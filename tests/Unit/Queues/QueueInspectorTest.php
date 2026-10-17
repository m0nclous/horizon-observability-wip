<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use DF\HorizonObservability\Queues\QueueInspector;
use DF\HorizonObservability\Queues\UnsupportedTopology;
use DF\HorizonObservability\Redis\RedisClients;
use Predis\PredisException;

/*
| QueueInspector without a Redis server (Arch Q6.5, C2.3 item 15): which queue connections it reads, and the cluster
| topology check that runs before any command is sent. The cluster connection points at a closed port; predis
| connects lazily, so nothing is ever contacted.
*/

/**
 * A queue connection "clustered" on a Redis Cluster connection "queues" (predis, closed port).
 */
function queueInspectorCluster(string $prefix = ''): void
{
    config()->set('database.redis.client', 'predis');
    config()->set('database.redis.clusters.queues', [['host' => '127.0.0.1', 'port' => 1, 'database' => 0]]);
    config()->set('database.redis.clusters.options', ['cluster' => 'redis', 'prefix' => $prefix]);
    config()->set('queue.connections.clustered', ['driver' => 'redis', 'connection' => 'queues', 'queue' => '{default}']);
    app()->forgetInstance(RedisClients::class);
}

it('reads only configured redis queue connections', function (): void {
    config()->set('queue.connections.database', ['driver' => 'database', 'table' => 'jobs']);
    $inspector = app(QueueInspector::class);

    expect($inspector->supports('redis'))->toBeTrue()
        ->and($inspector->supports('database'))->toBeFalse()
        ->and($inspector->supports('sync'))->toBeFalse()
        ->and($inspector->supports('missing'))->toBeFalse();
});

it('refuses a queue connection that is not redis', function (string $method): void {
    $inspector = app(QueueInspector::class);
    $now = CarbonImmutable::now();

    $method === 'inspect' ? $inspector->inspect('sync', 'default', $now) : $inspector->live('sync', 'default', $now, false);
})->with(['inspect', 'live'])->throws(InvalidArgumentException::class, 'The queue connection "sync" is not configured or does not use the redis driver.');

it('reports a cluster queue without a hash tag as an unsupported topology before any command', function (string $method): void {
    queueInspectorCluster();
    $inspector = app(QueueInspector::class);
    $now = CarbonImmutable::now();

    $method === 'inspect' ? $inspector->inspect('clustered', 'default', $now) : $inspector->live('clustered', 'default', $now, true);
})->with(['inspect', 'live'])->throws(UnsupportedTopology::class, 'unsupported topology: the keys of queue "default" (queue connection "clustered") on the Redis Cluster connection "queues" span several hash slots; give the queue a hash tag, e.g. "{default}"');

it('accepts a hash-tagged cluster queue, or a hash tag in the prefix, and then connects', function (string $queue, string $prefix): void {
    queueInspectorCluster($prefix);

    // The topology check passes; the failure is the connection to the closed port.
    app(QueueInspector::class)->inspect('clustered', $queue, CarbonImmutable::now());
})->throws(PredisException::class)->with([
    'tagged queue' => ['{default}', ''],
    'tagged queue with a plain prefix' => ['{emails}', 'app:'],
    'tag in the prefix' => ['default', '{app}:'],
]);

it('treats an empty or unclosed hash tag as no tag', function (string $queue, string $prefix): void {
    queueInspectorCluster($prefix);

    app(QueueInspector::class)->inspect('clustered', $queue, CarbonImmutable::now());
})->with([
    'empty tag' => ['{}default', ''],
    'unclosed tag' => ['{default', ''],
    'unclosed tag in the prefix' => ['default', '{app:'],
])->throws(UnsupportedTopology::class);
