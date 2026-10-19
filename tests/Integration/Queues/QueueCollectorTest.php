<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use DF\HorizonObservability\Collectors\HorizonCollector;
use DF\HorizonObservability\Collectors\QueueCollector;
use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Horizon\Topology;
use DF\HorizonObservability\Metrics\Labels;
use DF\HorizonObservability\Metrics\Metric;
use DF\HorizonObservability\Metrics\ScrapeContext;
use DF\HorizonObservability\Queues\QueueInspector;
use DF\HorizonObservability\Queues\UnsupportedTopology;
use DF\HorizonObservability\Redis\Lua;
use DF\HorizonObservability\Redis\RedisClients;
use DF\HorizonObservability\Redis\RedisScriptFailed;
use DF\HorizonObservability\Tests\Integration\Queues\Support\CollectedSamples;
use DF\HorizonObservability\Tests\Support\QueuePayloads;
use DF\HorizonObservability\Tests\Support\RedisKeys;
use DF\HorizonObservability\Tests\TestCase;

/*
| The queues collector: one series set per workbench queue (redis: default, slow, reports, mail, sms), the class
| breakdown with its sentinels, and isolation per queue and against the budget.
*/

pest()->group('redis');

const QUEUE_COLLECTOR_NOW = 1700002000.0;

function queueCollectorRun(?ScrapeContext $context = null): CollectedSamples
{
    return CollectedSamples::collect(app(QueueCollector::class), $context ?? new ScrapeContext(CarbonImmutable::createFromTimestamp(QUEUE_COLLECTOR_NOW), 8.0));
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function queueCollectorJob(string $class, float $age, array $overrides = []): array
{
    return QueuePayloads::job($class, ['pushedAt' => sprintf('%.4F', QUEUE_COLLECTOR_NOW - $age), 'createdAt' => (int) (QUEUE_COLLECTOR_NOW - $age), ...$overrides]);
}

/**
 * @return array<string, string>
 */
function queueCollectorLabels(string $queue, ?string $class = null): array
{
    return ['connection' => 'redis', 'queue' => $queue, ...($class === null ? [] : ['job_class' => $class])];
}

it('exports every topology queue, an empty one as zero', function (): void {
    $samples = queueCollectorRun();
    $zero = [];

    foreach (['default', 'slow', 'reports', 'mail', 'sms'] as $queue) {
        $zero['connection="redis",queue="'.$queue.'"'] = 0;
    }

    expect($samples->families)->toBe(['queue_pending_jobs', 'queue_delayed_jobs', 'queue_delayed_due_jobs', 'queue_oldest_pending_job_age_seconds'])
        ->and($samples->series('queue_pending_jobs'))->toEqualCanonicalizing($zero)
        ->and($samples->series('queue_delayed_jobs'))->toEqualCanonicalizing($zero)
        ->and($samples->series('queue_delayed_due_jobs'))->toEqualCanonicalizing($zero)
        ->and(array_values($samples->series('queue_oldest_pending_job_age_seconds')))->toBe([0.0, 0.0, 0.0, 0.0, 0.0])
        ->and($samples->series('queue_jobs_pending'))->toBe([])
        ->and($samples->failures)->toBe([]);
});

it('exports the counts, the classes with their sentinels and the oldest age', function (): void {
    config()->set('horizon-observability.queue_scan_limit', 3);
    app()->forgetInstance(Config::class);
    $redis = app('redis')->connection('default');

    QueuePayloads::push($redis, 'default', queueCollectorJob('App\\Jobs\\SendInvoice', 42.5));
    QueuePayloads::push($redis, 'default', queueCollectorJob('App\\Jobs\\SendInvoice', 40));
    QueuePayloads::push($redis, 'default', queueCollectorJob('', 30));
    QueuePayloads::push($redis, 'default', queueCollectorJob('App\\Jobs\\Export', 20));
    QueuePayloads::reserve($redis, 'default', queueCollectorJob('App\\Jobs\\Export', 60), QUEUE_COLLECTOR_NOW + 30);
    QueuePayloads::delay($redis, 'default', queueCollectorJob('App\\Jobs\\SendReminder', 600), QUEUE_COLLECTOR_NOW - 10);
    QueuePayloads::delay($redis, 'default', queueCollectorJob('App\\Jobs\\SendReminder', 600), QUEUE_COLLECTOR_NOW + 300);

    $samples = queueCollectorRun();

    expect($samples->value('queue_pending_jobs', queueCollectorLabels('default')))->toBe(5)
        ->and($samples->value('queue_delayed_jobs', queueCollectorLabels('default')))->toBe(2)
        ->and($samples->value('queue_delayed_due_jobs', queueCollectorLabels('default')))->toBe(1)
        ->and($samples->value('queue_oldest_pending_job_age_seconds', queueCollectorLabels('default')))->toBe(42.5)
        ->and($samples->value('queue_pending_jobs', queueCollectorLabels('slow')))->toBe(0)
        ->and($samples->series('queue_jobs_pending'))->toEqualCanonicalizing([
            'connection="redis",job_class="App\\\\Jobs\\\\SendInvoice",queue="default"' => 2,
            'connection="redis",job_class="(unknown)",queue="default"' => 1,
            'connection="redis",job_class="(unscanned)",queue="default"' => 1,
        ])
        ->and($samples->series('queue_jobs_reserved'))->toBe([
            'connection="redis",job_class="App\\\\Jobs\\\\Export",queue="default"' => 1,
        ])
        ->and($samples->families)->toBe(array_map(static fn (Metric $metric): string => $metric->value, Metric::ofCollector('queues')))
        ->and($samples->failures)->toBe([]);
});

it('keeps the top 100 classes per queue and folds the rest into (other)', function (): void {
    $redis = app('redis')->connection('default');
    $reserved = [];

    foreach (range(0, 104) as $i) {
        $class = sprintf('App\\Jobs\\Generated%03d', $i);
        QueuePayloads::push($redis, 'default', queueCollectorJob($class, 10));

        if ($i < 100) {
            QueuePayloads::push($redis, 'default', queueCollectorJob($class, 10));
        }

        $reserved[QueuePayloads::encode(queueCollectorJob($class, 10))] = QUEUE_COLLECTOR_NOW + $i;
    }

    RedisKeys::client()->zadd(TestCase::redisPrefix().'queues:default:reserved', $reserved);

    $samples = queueCollectorRun();
    $pending = $samples->series('queue_jobs_pending');
    $reservedSeries = $samples->series('queue_jobs_reserved');

    expect($pending)->toHaveCount(101)
        ->and($pending['connection="redis",job_class="(other)",queue="default"'])->toBe(5)
        ->and($pending['connection="redis",job_class="App\\\\Jobs\\\\Generated000",queue="default"'])->toBe(2)
        ->and(array_sum($pending))->toBe(205)
        ->and($reservedSeries)->toHaveCount(101)
        ->and($reservedSeries['connection="redis",job_class="(other)",queue="default"'])->toBe(5)
        ->and(array_sum($reservedSeries))->toBe(105);
});

it('omits the oldest age when no fresh job was scanned and nothing is due', function (): void {
    $redis = app('redis')->connection('default');
    QueuePayloads::push($redis, 'slow', queueCollectorJob('App\\Jobs\\Export', 900, ['attempts' => 2]));

    $samples = queueCollectorRun();

    expect($samples->value('queue_pending_jobs', queueCollectorLabels('slow')))->toBe(1)
        ->and($samples->value('queue_oldest_pending_job_age_seconds', queueCollectorLabels('slow')))->toBeNull()
        ->and($samples->value('queue_oldest_pending_job_age_seconds', queueCollectorLabels('default')))->toBe(0.0);
});

it('reports a failing queue and keeps the others', function (): void {
    app('redis')->connection('default')->set('queues:slow', 'not a list');

    $samples = queueCollectorRun();

    expect($samples->failedSources())->toBe(['queue:redis:slow'])
        ->and($samples->failures[0]['exception'])->toBeInstanceOf(RedisScriptFailed::class)
        ->and($samples->value('queue_pending_jobs', queueCollectorLabels('slow')))->toBeNull()
        ->and($samples->series('queue_pending_jobs'))->toHaveCount(4)
        ->and($samples->value('queue_pending_jobs', queueCollectorLabels('sms')))->toBe(0);
});

it('stops between queues when the scrape budget runs out', function (): void {
    // The clock reads 0 when the context starts (deadline 1 s), then 0.6 and 1.2 at the next two checks.
    $ticks = 0;
    $clock = static function () use (&$ticks): float {
        return 0.6 * $ticks++;
    };

    $samples = queueCollectorRun(new ScrapeContext(CarbonImmutable::createFromTimestamp(QUEUE_COLLECTOR_NOW), 1.0, $clock));

    expect($samples->series('queue_pending_jobs'))->toBe(['connection="redis",queue="default"' => 0])
        ->and($samples->failedSources())->toBe(['budget'])
        ->and($samples->failures[0]['exception']->getMessage())->toBe('the scrape budget ran out after 1 of 5 queues; the rest were not read.');
});

it('skips the queues of connections that do not use redis', function (): void {
    config()->set('queue.connections.database', ['driver' => 'database', 'table' => 'jobs', 'queue' => 'default']);
    config()->set('horizon.environments.testing.supervisor-db', ['connection' => 'database', 'queue' => ['imports'], 'maxProcesses' => 1]);
    config()->set('horizon.environments.testing.supervisor-missing', ['connection' => 'missing', 'queue' => ['ghost'], 'maxProcesses' => 1]);

    $samples = queueCollectorRun();

    expect($samples->series('queue_pending_jobs'))->toHaveCount(5)
        ->and($samples->value('queue_pending_jobs', ['connection' => 'database', 'queue' => 'imports']))->toBeNull()
        ->and($samples->failures)->toBe([]);
});

it('reports queues on a Redis Cluster without a hash tag as an unsupported topology on an older framework', function (): void {
    config()->set('database.redis.client', 'predis');
    config()->set('database.redis.clusters.queues', [['host' => '127.0.0.1', 'port' => 1]]);
    config()->set('queue.connections.redis.connection', 'queues');
    app()->forgetInstance(RedisClients::class);
    // A framework without first-class cluster queues keeps "default" under queues:default, which spans three slots.
    app()->bind(QueueInspector::class, static fn (): QueueInspector => new QueueInspector(app(RedisClients::class), app(Lua::class), app(Topology::class), app(Config::class), false));

    $samples = queueCollectorRun();

    expect($samples->families)->toBe([])
        ->and($samples->failedSources())->toBe(['queue:redis:default', 'queue:redis:slow', 'queue:redis:reports', 'queue:redis:mail', 'queue:redis:sms'])
        ->and(array_map(static fn (array $failure): string => $failure['exception']::class, $samples->failures))->each->toBe(UnsupportedTopology::class);
});

it('uses the topology the horizon collector read in the same scrape', function (): void {
    $context = new ScrapeContext(CarbonImmutable::createFromTimestamp(QUEUE_COLLECTOR_NOW), 8.0);
    $context->remember(HorizonCollector::TOPOLOGY, static fn (): array => [['connection' => 'redis', 'queue' => 'only']]);

    expect(queueCollectorRun($context)->series('queue_pending_jobs'))->toBe(['connection="redis",queue="only"' => 0]);
});

it('fails as a whole when the topology cannot be read', function (): void {
    config()->set('horizon.environments.testing.supervisor-default.minProcesses', 0);

    queueCollectorRun();
})->throws(Exception::class, 'minProcesses] must be greater than 0');

it('merges a class named like a sentinel into the sentinel and keeps numeric class names', function (): void {
    config()->set('horizon-observability.queue_scan_limit', 1);
    app()->forgetInstance(Config::class);
    $redis = app('redis')->connection('default');
    QueuePayloads::push($redis, 'default', queueCollectorJob(Labels::UNSCANNED, 5));
    QueuePayloads::push($redis, 'default', queueCollectorJob('App\\Jobs\\A', 5));
    QueuePayloads::push($redis, 'default', queueCollectorJob('123', 5));
    QueuePayloads::push($redis, 'slow', queueCollectorJob('123', 5));

    $samples = queueCollectorRun();

    expect($samples->series('queue_jobs_pending'))->toBe([
        'connection="redis",job_class="(unscanned)",queue="default"' => 3,
        // A numeric class name stays a string label.
        'connection="redis",job_class="123",queue="slow"' => 1,
    ]);
});
