<?php

declare(strict_types=1);

use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Redis\Keys;
use DF\HorizonObservability\Redis\RedisClients;
use DF\HorizonObservability\Tests\TestCase;
use Illuminate\Support\Facades\Artisan;
use Predis\Client;

/*
| horizon-observability:flush with the package storage on a Redis Cluster (the `cluster` CI leg: a single-node
| cluster on 127.0.0.1:7000, override with REDIS_CLUSTER_HOST and REDIS_CLUSTER_PORT): SCAN runs on every master
| and only the package keys go. Skipped when no cluster answers.
*/

pest()->group('cluster');

function flushClusterNode(): Client
{
    return new Client([
        'host' => (string) (getenv('REDIS_CLUSTER_HOST') ?: '127.0.0.1'),
        'port' => (int) (getenv('REDIS_CLUSTER_PORT') ?: 7000),
        'timeout' => 0.5,
    ]);
}

/**
 * @return list<string>
 */
function flushClusterKeys(Client $node, string $pattern): array
{
    $cursor = '0';
    $keys = [];

    do {
        /** @var array{0: string, 1: list<string>} $page */
        $page = $node->scan($cursor, ['MATCH' => $pattern, 'COUNT' => 1000]);
        [$cursor, $batch] = $page;
        array_push($keys, ...$batch);
    } while ($cursor !== '0');

    sort($keys);

    return array_values(array_unique($keys));
}

beforeEach(function (): void {
    $node = flushClusterNode();

    try {
        $info = (string) $node->executeRaw(['CLUSTER', 'INFO']);
    } catch (Throwable $e) {
        $this->markTestSkipped('No Redis Cluster at the cluster leg address: '.$e->getMessage());
    }

    if (! str_contains($info, 'cluster_state:ok')) {
        $this->markTestSkipped('The Redis Cluster is not ready (cluster_state is not ok).');
    }

    foreach (flushClusterKeys($node, TestCase::redisPrefix().'*') as $key) {
        $node->del([$key]);
    }

    $this->node = $node;
    config()->set('database.redis.clusters.metrics', [[
        'host' => (string) (getenv('REDIS_CLUSTER_HOST') ?: '127.0.0.1'),
        'port' => (int) (getenv('REDIS_CLUSTER_PORT') ?: 7000),
        'database' => 0,
    ]]);
    config()->set('database.redis.clusters.options', ['cluster' => 'redis', 'prefix' => TestCase::redisPrefix()]);
    config()->set('horizon-observability.redis.connection', 'metrics');
    $this->app->forgetInstance(Config::class);
    $this->app->forgetInstance(RedisClients::class);
});

afterEach(function (): void {
    if (property_exists($this, 'node') && $this->node instanceof Client) {
        foreach (flushClusterKeys($this->node, TestCase::redisPrefix().'*') as $key) {
            $this->node->del([$key]);
        }
    }
});

it('deletes the package keys on a cluster and leaves the others', function (): void {
    $keys = new Keys;
    $prefix = TestCase::redisPrefix();

    foreach ([$keys->attempts(), $keys->meta(), $keys->liveLeader(), $keys->ring(1), $keys->readyAt('a-b-c')] as $key) {
        $this->node->set($prefix.$key, '1');
    }

    $this->node->set($prefix.'{default}:queue', '1');

    expect(Artisan::call('horizon-observability:flush', ['--force' => true]))->toBe(0)
        ->and(Artisan::output())->toContain('Deleted 5 package keys (attempts 1, live 2, meta 1, ready_at 1)')
        ->and(flushClusterKeys($this->node, $prefix.'*'))->toBe([$prefix.'{default}:queue']);
});
