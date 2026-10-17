<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use DF\HorizonObservability\Queues\DelayedJobStore;
use DF\HorizonObservability\Redis\RedisClients;
use DF\HorizonObservability\Tests\Support\QueuePayloads;
use DF\HorizonObservability\Tests\TestCase;
use Illuminate\Queue\RedisQueue;
use Predis\Client;

/*
| The delayed list on a Redis Cluster (Arch Q2.1, Q6.5, 8.5 `cluster` leg: a single-node cluster on 127.0.0.1:7000,
| override with REDIS_CLUSTER_HOST and REDIS_CLUSTER_PORT). The delayed set is read under the key Laravel's
| RedisQueue writes: `queues:{<q>}:delayed` for an untagged queue with first-class cluster queues (Laravel 13), the
| name as given for a hash-tagged one. Skipped when no cluster answers.
*/

pest()->group('cluster');

function clusterDelayedJobStoreNode(): Client
{
    return new Client([
        'host' => (string) (getenv('REDIS_CLUSTER_HOST') ?: '127.0.0.1'),
        'port' => (int) (getenv('REDIS_CLUSTER_PORT') ?: 7000),
        'timeout' => 0.5,
    ]);
}

function clusterDelayedJobStoreClean(Client $node): void
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
 * @return list<array<string, mixed>>
 */
function clusterDelayedJobStoreRows(string $queue): array
{
    return app(DelayedJobStore::class)->rows([['connection' => 'clustered', 'queue' => $queue]], null, 10, CarbonImmutable::now());
}

beforeEach(function (): void {
    $node = clusterDelayedJobStoreNode();

    try {
        $info = (string) $node->executeRaw(['CLUSTER', 'INFO']);
    } catch (Throwable $e) {
        $this->markTestSkipped('No Redis Cluster at the cluster leg address: '.$e->getMessage());
    }

    if (! str_contains($info, 'cluster_state:ok')) {
        $this->markTestSkipped('The Redis Cluster is not ready (cluster_state is not ok).');
    }

    clusterDelayedJobStoreClean($node);
    $this->node = $node;

    config()->set('database.redis.clusters.queues', [[
        'host' => (string) (getenv('REDIS_CLUSTER_HOST') ?: '127.0.0.1'),
        'port' => (int) (getenv('REDIS_CLUSTER_PORT') ?: 7000),
        'database' => 0,
    ]]);
    config()->set('database.redis.clusters.options', ['cluster' => 'redis', 'prefix' => TestCase::redisPrefix()]);
    config()->set('queue.connections.clustered', ['driver' => 'redis', 'connection' => 'queues', 'queue' => '{default}', 'retry_after' => 90]);
    app()->forgetInstance(RedisClients::class);
});

afterEach(function (): void {
    if (property_exists($this, 'node') && $this->node !== null) {
        clusterDelayedJobStoreClean($this->node);
    }
});

it('lists the delayed jobs of a hash-tagged queue on a cluster', function (): void {
    QueuePayloads::delay(app('redis')->connection('queues'), '{default}', QueuePayloads::job('App\\Jobs\\SendReminder', ['uuid' => 'tagged']), (float) time() + 60);

    expect(array_column(clusterDelayedJobStoreRows('{default}'), 'id'))->toBe(['tagged']);
});

it('lists the delayed jobs of an untagged queue where the framework keeps them on a cluster', function (): void {
    if (! method_exists(RedisQueue::class, 'getQueueRedisKey')) {
        $this->markTestSkipped('The installed framework has no first-class Redis Cluster queues.');
    }

    // Laravel writes the delayed job of the queue "default" of a cluster connection to queues:{default}:delayed.
    app('queue')->connection('clustered')->later(60, 'App\\Jobs\\SendReminder@handle', [], 'default');
    $rows = clusterDelayedJobStoreRows('default');

    expect($this->node->exists(TestCase::redisPrefix().'queues:{default}:delayed'))->toBe(1)
        ->and(array_column($rows, 'name'))->toBe(['App\\Jobs\\SendReminder'])
        ->and(array_column($rows, 'queue'))->toBe(['default']);
});
