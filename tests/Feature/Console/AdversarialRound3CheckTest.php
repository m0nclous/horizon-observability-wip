<?php

declare(strict_types=1);

use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Redis\Keys;
use DF\HorizonObservability\Redis\RedisClients;
use DF\HorizonObservability\Support\CheckResult;
use DF\HorizonObservability\Support\Diagnostics;
use DF\HorizonObservability\Tests\TestCase;
use Illuminate\Redis\Connectors\PhpRedisConnector;
use Predis\Client;

/*
| Adversarial probes of horizon-observability:check on valid but unusual Redis setups.
|
| 1. The Timeouts block (check warns when a connection the scrape waits on has no connect/read timeout) reads the
|    timeout keys from the merge of the connection, the global `database.redis.options` and the connection's own
|    `options`, and for a cluster from its first node too (Diagnostics::redisSettings(), "as
|    Laravel's connectors merge them"). That is how Laravel's phpredis connector builds a single client, but not how
|    the others do (Illuminate\Redis\Connectors in the Laravel the suite resolves):
|    - PredisConnector::connect() passes the connection array as the predis connection parameters and every
|      `options` array as client options, which predis does not read as parameters: a `read_write_timeout` in
|      `database.redis.options` or in the connection's `options` is never applied;
|    - PhpRedisConnector::createRedisClusterInstance() takes `timeout` and `read_timeout` from the merged options only
|      (global, cluster and own options), never from the nodes: a timeout written on the node is never applied.
|    In both cases check prints "has timeout ... read_(write_)timeout ... s" with status ok while the client waits for
|    the socket default (60 s): a false all-clear, the kind of result the block exists to prevent. The probes build the
|    client Laravel builds and compare.
| 2. A read-only storage Redis (a replica, e.g. REDIS_HOST pointed at a replica or a primary demoted by a failover):
|    every worker write and the scrape's reset guard fail with READONLY. check reports the Storage connection as
|    "reachable" (ok) and the failed attempts collector with the fix "Check that Redis is reachable with the settings
|    in config/database.php (see Storage and Queues)", which the Storage line just contradicted and which does not say
|    what to change (every problem must have one actionable fix line). It needs its own throwaway server,
|    never the shared one (REPLICAOF makes it read-only): set REDIS_READONLY_PROBE_PORT, e.g.
|    docker run -d --rm -p 127.0.0.1:16404:6379 redis:7.4-alpine and REDIS_READONLY_PROBE_PORT=16404.
*/

pest()->group('redis');

/**
 * The check result of a section and name.
 */
function adversarialRound3Result(string $section, string $name): CheckResult
{
    app()->forgetInstance(Config::class);
    app()->forgetInstance(RedisClients::class);

    foreach (app(Diagnostics::class)->check() as $result) {
        if ($result->section === $section && $result->name === $name) {
            return $result;
        }
    }

    throw new RuntimeException(sprintf('check has no %s / %s result.', $section, $name));
}

beforeEach(function (): void {
    config()->set('horizon-observability.token', 'adversarial-round3-token-0123456789');
});

it('reports a predis read_write_timeout as set only when the predis client of the connection has it', function (string $where): void {
    config()->set('database.redis.client', 'predis');

    match ($where) {
        'the connection (control: applied)' => config()->set('database.redis.horizon.read_write_timeout', 2.0),
        'global options' => config()->set('database.redis.options.read_write_timeout', 2.0),
        default => config()->set('database.redis.horizon.options.read_write_timeout', 2.0),
    };

    app()->forgetInstance('redis');
    $result = adversarialRound3Result('Timeouts', 'Horizon connection');

    // The client Laravel builds for Horizon's connection.
    $parameters = app('redis')->connection('horizon')->client()->getConnection()->getParameters();
    $applied = (float) ($parameters->read_write_timeout ?? 0) > 0;

    expect($result->status->value)->toBe($applied ? 'ok' : 'warning', sprintf(
        'check says "%s" (%s), the predis client has read_write_timeout %s',
        $result->message,
        $result->status->value,
        var_export($parameters->read_write_timeout ?? null, true),
    ));
})->with(['the connection (control: applied)', 'global options', "the connection's own options"]);

it('reports a phpredis cluster timeout as set only when the cluster client gets it', function (array $cluster): void {
    config()->set('database.redis.client', 'phpredis');
    config()->set('database.redis.clusters.cache_cluster', $cluster);
    config()->set('cache.stores.redis_cluster', ['driver' => 'redis', 'connection' => 'cache_cluster']);
    config()->set('cache.default', 'redis_cluster');
    app()->forgetInstance('redis');

    $result = adversarialRound3Result('Timeouts', 'Default cache store');

    // The options Laravel's phpredis connector hands to new RedisCluster(null, $seeds, $timeout, $readTimeout, ...).
    $captured = null;
    app()->forgetInstance('redis');
    // Not static: RedisManager::extend() binds the closure (Laravel 11).
    app('redis')->extend('phpredis', function () use (&$captured): PhpRedisConnector {
        return new class($captured) extends PhpRedisConnector
        {
            public function __construct(private mixed &$captured) {}

            protected function createRedisClusterInstance(array $servers, array $options): never
            {
                $this->captured = $options;

                throw new RuntimeException('captured');
            }
        };
    });

    try {
        app('redis')->connection('cache_cluster');
    } catch (RuntimeException) {
        // captured, no cluster needed
    }

    $applied = is_array($captured) && (float) ($captured['timeout'] ?? 0) > 0 && (float) ($captured['read_timeout'] ?? 0) > 0;

    expect($captured)->toBeArray()
        ->and($result->status->value)->toBe($applied ? 'ok' : 'warning', sprintf(
            'check says "%s" (%s), new RedisCluster() gets timeout %s and read_timeout %s',
            $result->message,
            $result->status->value,
            var_export($captured['timeout'] ?? 0, true),
            var_export($captured['read_timeout'] ?? 0, true),
        ));
})->with([
    'in the cluster options (control: applied)' => [['options' => ['timeout' => 2.0, 'read_timeout' => 2.0], ['host' => '127.0.0.1', 'port' => 7000]]],
    // As on a single connection; the cluster options have none.
    'on the node' => [[['host' => '127.0.0.1', 'port' => 7000, 'timeout' => 2.0, 'read_timeout' => 2.0]]],
]);

it('gives an actionable fix when the storage Redis is a read-only replica', function (string $client): void {
    $port = (int) getenv('REDIS_READONLY_PROBE_PORT');
    $host = (string) (getenv('REDIS_HOST') ?: '127.0.0.1');
    $raw = new Client(['host' => $host, 'port' => $port]);
    $raw->executeRaw(['REPLICAOF', 'NO', 'ONE']);
    $raw->del([TestCase::redisPrefix().'horizon:'.(new Keys)->meta()]);

    config()->set('database.redis.client', $client);

    foreach (['default', 'horizon'] as $connection) {
        config()->set('database.redis.'.$connection.'.host', $host);
        config()->set('database.redis.'.$connection.'.port', $port);
    }

    app()->forgetInstance('redis');
    $raw->executeRaw(['REPLICAOF', '127.0.0.1', '1']);

    try {
        $storage = adversarialRound3Result('Storage', 'Connection');
        $attempts = adversarialRound3Result('Collectors', 'attempts');
    } finally {
        $raw->executeRaw(['REPLICAOF', 'NO', 'ONE']);
    }

    // What check reports: the storage reachable, the attempts collector failed with READONLY.
    expect($storage->status->value)->toBe('ok')
        ->and($attempts->status->value)->toBe('error')
        ->and($attempts->message)->toContain('READONLY')
        // The fix must say what to change: the storage connection points at a read-only replica.
        ->and((string) $attempts->fix)->not->toContain('Check that Redis is reachable')
        ->and((string) $attempts->fix)->toMatch('/replica|primary|read-only|writable/i');
})->with(['phpredis', 'predis'])
    ->skip(fn (): bool => getenv('REDIS_READONLY_PROBE_PORT') === false, 'needs a throwaway Redis in REDIS_READONLY_PROBE_PORT');
