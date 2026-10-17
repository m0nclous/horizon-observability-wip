<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Horizon\Topology;
use DF\HorizonObservability\Queues\QueueInspector;
use DF\HorizonObservability\Queues\QueueSnapshot;
use DF\HorizonObservability\Queues\UnsupportedTopology;
use DF\HorizonObservability\Redis\Lua;
use DF\HorizonObservability\Redis\RedisClients;
use DF\HorizonObservability\Tests\Support\QueuePayloads;
use DF\HorizonObservability\Tests\Support\RedisCommands;
use DF\HorizonObservability\Tests\TestCase;
use Illuminate\Queue\RedisQueue;
use Predis\Client;

/*
| Queue state on a Redis Cluster (Arch Q6.5, 8.5 `cluster` leg: a single-node cluster on 127.0.0.1:7000, override
| with REDIS_CLUSTER_HOST and REDIS_CLUSTER_PORT). A hash-tagged queue is read with one script. An untagged queue is
| read where the framework keeps it (queues:{<q>} with first-class cluster queues, Laravel 13); on an older framework
| it is reported as an unsupported topology instead of failing with CROSSSLOT. Skipped when no cluster answers.
*/

pest()->group('cluster');

function clusterQueueStateNode(): Client
{
    return new Client([
        'host' => (string) (getenv('REDIS_CLUSTER_HOST') ?: '127.0.0.1'),
        'port' => (int) (getenv('REDIS_CLUSTER_PORT') ?: 7000),
        'timeout' => 0.5,
    ]);
}

function clusterQueueStateClean(Client $node): void
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

beforeEach(function (): void {
    $node = clusterQueueStateNode();

    try {
        $info = (string) $node->executeRaw(['CLUSTER', 'INFO']);
    } catch (Throwable $e) {
        $this->markTestSkipped('No Redis Cluster at the cluster leg address: '.$e->getMessage());
    }

    if (! str_contains($info, 'cluster_state:ok')) {
        $this->markTestSkipped('The Redis Cluster is not ready (cluster_state is not ok).');
    }

    clusterQueueStateClean($node);
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
        clusterQueueStateClean($this->node);
    }
});

it('reads a hash-tagged queue on a cluster in full and live mode', function (): void {
    $redis = app('redis')->connection('queues');
    $now = CarbonImmutable::now();
    QueuePayloads::push($redis, '{default}', QueuePayloads::job('App\\Jobs\\SendInvoice', ['pushedAt' => sprintf('%.4F', (float) $now->format('U.u') - 30)]));
    QueuePayloads::reserve($redis, '{default}', QueuePayloads::job('App\\Jobs\\Export'), (float) $now->getTimestamp() + 60);
    QueuePayloads::delay($redis, '{default}', QueuePayloads::job(), (float) $now->getTimestamp() - 5);
    $inspector = app(QueueInspector::class);

    $full = $inspector->inspect('clustered', '{default}', $now);
    $live = $inspector->live('clustered', '{default}', $now, true);

    expect($full->length)->toBe(1)
        ->and($full->reserved)->toBe(1)
        ->and($full->delayedDue)->toBe(1)
        ->and($full->pendingByClass)->toBe(['App\\Jobs\\SendInvoice' => 1])
        ->and($full->reservedByClass)->toBe(['App\\Jobs\\Export' => 1])
        ->and($full->oldestAgeSeconds((float) $now->format('U.u')))->toBeGreaterThan(29.0)
        ->and($live->pending())->toBe(2)
        ->and($live->reservedByClass)->toBe(['App\\Jobs\\Export' => 1])
        ->and($this->node->exists(TestCase::redisPrefix().'queues:{default}'))->toBe(1);
});

it('reads an untagged queue where the framework keeps it on a cluster', function (): void {
    if (! method_exists(RedisQueue::class, 'getQueueRedisKey')) {
        $this->markTestSkipped('The installed framework has no first-class Redis Cluster queues.');
    }

    // Laravel and Horizon write the queue "default" of a cluster connection under queues:{default}.
    $queue = app('queue')->connection('clustered');
    $queue->push('App\\Jobs\\SendInvoice@handle', ['invoice' => 42], 'default');
    $queue->later(60, 'App\\Jobs\\SendReminder@handle', [], 'default');
    $now = CarbonImmutable::now()->addSecond();
    $inspector = app(QueueInspector::class);

    $full = $inspector->inspect('clustered', 'default', $now);
    $live = $inspector->live('clustered', 'default', $now, false);

    expect($inspector->queueKey('clustered', 'default'))->toBe('queues:{default}')
        ->and($this->node->exists(TestCase::redisPrefix().'queues:{default}'))->toBe(1)
        ->and($full->length)->toBe(1)
        ->and($full->delayed)->toBe(1)
        ->and($full->pendingByClass)->toBe(['App\\Jobs\\SendInvoice' => 1])
        ->and($full->firstFreshReadyAt)->not->toBeNull()
        ->and($live->length)->toBe(1)
        ->and($live->firstFreshReadyAt)->toBe($full->firstFreshReadyAt);
});

it('reports an untagged queue on a cluster without sending the script on an older framework', function (): void {
    QueuePayloads::push(app('redis')->connection('queues'), 'default', QueuePayloads::job());
    $keys = ['queues:default', 'queues:default:reserved', 'queues:default:delayed'];

    // What the check prevents: the three keys hash to different slots, so the script fails on every scrape.
    try {
        app(Lua::class)->eval(app(RedisClients::class)->reader('queues'), 'queue_state', $keys, [time(), 10, 10, 1024, 'full']);
        $error = '';
    } catch (Throwable $e) {
        $error = $e->getMessage();
    }

    // phpredis and predis refuse on the client side (the server would answer CROSSSLOT).
    expect($error)->toMatch("/CROSSSLOT|same slot|Cannot use 'EVAL' with redis-cluster/");

    $commands = RedisCommands::start($this->app);

    // A framework without first-class cluster queues keeps the queue under queues:default.
    $inspector = new QueueInspector(app(RedisClients::class), app(Lua::class), app(Topology::class), app(Config::class), false);

    expect(static fn (): QueueSnapshot => $inspector->inspect('clustered', 'default', CarbonImmutable::now()))
        ->toThrow(UnsupportedTopology::class, 'unsupported topology')
        ->and($commands->names(RedisClients::READER.'queues'))->toBe([]);
});
