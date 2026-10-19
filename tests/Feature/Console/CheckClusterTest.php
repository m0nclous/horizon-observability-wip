<?php

declare(strict_types=1);

use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Horizon\Topology;
use DF\HorizonObservability\Queues\QueueInspector;
use DF\HorizonObservability\Redis\Lua;
use DF\HorizonObservability\Redis\RedisClients;
use DF\HorizonObservability\Support\CheckResult;
use DF\HorizonObservability\Support\Diagnostics;
use DF\HorizonObservability\Tests\TestCase;
use Predis\Client;

/*
| horizon-observability:check on a Redis Cluster (the seeded case "cluster topology"; the `cluster` CI leg: a
| single-node cluster on 127.0.0.1:7000, override with REDIS_CLUSTER_HOST and REDIS_CLUSTER_PORT): the storage on a
| cluster is reported as such, and an untagged queue on a framework without first-class cluster queues fails the
| queues collector with the hash tag fix instead of a CROSSSLOT error. Skipped when no cluster answers.
*/

pest()->group('cluster');

function checkClusterNode(): Client
{
    return new Client([
        'host' => (string) (getenv('REDIS_CLUSTER_HOST') ?: '127.0.0.1'),
        'port' => (int) (getenv('REDIS_CLUSTER_PORT') ?: 7000),
        'timeout' => 0.5,
    ]);
}

function checkClusterClean(Client $node): void
{
    $cursor = '0';

    do {
        /** @var array{0: string, 1: list<string>} $page */
        $page = $node->scan($cursor, ['MATCH' => TestCase::redisPrefix().'*', 'COUNT' => 1000]);
        [$cursor, $keys] = $page;

        foreach ($keys as $key) {
            $node->del([$key]);
        }
    } while ($cursor !== '0');
}

/**
 * @return array<string, CheckResult> "section / name" => result
 */
function checkClusterResults(): array
{
    app()->forgetInstance(Config::class);
    app()->forgetInstance(RedisClients::class);
    $results = [];

    foreach (app(Diagnostics::class)->check() as $result) {
        $results[$result->section.' / '.$result->name] = $result;
    }

    return $results;
}

beforeEach(function (): void {
    $node = checkClusterNode();

    try {
        $info = (string) $node->executeRaw(['CLUSTER', 'INFO']);
    } catch (Throwable $e) {
        $this->markTestSkipped('No Redis Cluster at the cluster leg address: '.$e->getMessage());
    }

    if (! str_contains($info, 'cluster_state:ok')) {
        $this->markTestSkipped('The Redis Cluster is not ready (cluster_state is not ok).');
    }

    checkClusterClean($node);
    $this->node = $node;

    config()->set('database.redis.clusters.queues', [[
        'host' => (string) (getenv('REDIS_CLUSTER_HOST') ?: '127.0.0.1'),
        'port' => (int) (getenv('REDIS_CLUSTER_PORT') ?: 7000),
        'database' => 0,
    ]]);
    config()->set('database.redis.clusters.options', ['cluster' => 'redis', 'prefix' => TestCase::redisPrefix()]);
    config()->set('queue.connections.clustered', ['driver' => 'redis', 'connection' => 'queues', 'queue' => 'default', 'retry_after' => 90]);
    config()->set('horizon.environments.testing', ['supervisor-1' => ['connection' => 'clustered', 'queue' => ['default'], 'maxProcesses' => 1]]);
    config()->set('horizon-observability.token', 'check-token-0123456789abcdef');
});

afterEach(function (): void {
    if (property_exists($this, 'node') && $this->node instanceof Client) {
        checkClusterClean($this->node);
    }
});

it('reports an untagged queue on a cluster as an unsupported topology with the hash tag fix', function (): void {
    // A framework without first-class cluster queues (before Laravel 13): the reader cannot read "default" there.
    app()->instance(QueueInspector::class, new QueueInspector(app(RedisClients::class), app(Lua::class), app(Topology::class), app(Config::class), false));

    $results = checkClusterResults();

    expect($results['Collectors / queues']->status->value)->toBe('error')
        ->and($results['Collectors / queues']->message)->toContain('unsupported topology')
        ->and($results['Collectors / queues']->fix)->toContain('hash tag')
        ->and($results['Queues / Redis queues']->message)->toContain('(cluster)');
});

it('reports the package storage on a cluster', function (): void {
    config()->set('horizon-observability.redis.connection', 'queues');

    $results = checkClusterResults();

    expect($results['Storage / Connection']->status->value)->toBe('ok')
        ->and($results['Storage / Connection']->message)->toContain(', cluster')
        ->and($results['Collectors / attempts']->status->value)->toBe('ok');
});
