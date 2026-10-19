<?php

declare(strict_types=1);

use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Metrics\Metric;
use DF\HorizonObservability\Tests\Support\QueuePayloads;
use DF\HorizonObservability\Tests\Truth\Support\Model;
use DF\HorizonObservability\Tests\Truth\Support\RedisState;
use DF\HorizonObservability\Tests\Truth\Support\Samples;
use DF\HorizonObservability\Tests\Truth\Support\Scenario;
use DF\HorizonObservability\Tests\Truth\Support\Scenarios;
use DF\HorizonObservability\Tests\Truth\Support\TruthJob;

/*
| Exporter truth (T16): the queue gauges equal the queue the scenario left behind, and an independent read of it in
| Redis (plain LLEN, ZCARD, ZCOUNT and json_decode, see RedisState): pending (ready plus due delayed), delayed, due,
| the oldest wait of a first attempt (C8), ready jobs per class with "(unscanned)" beyond the scan limit, and
| reserved jobs per class while a real worker holds one.
*/

pest()->group('redis', 'truth');

afterEach(function (): void {
    Scenario::stop();
});

const QUEUE_STATE_QUEUES = ['default', 'slow', 'reports', 'mail', 'sms'];

/**
 * The backlog scenario, scraped at T+30:
 * - slow: a job released for 600 s at T (attempts 1, delayed) and a job dispatched with a delay of 3 s (due);
 * - reports: Truth\A, Truth\A, Truth\B ready, Truth\C due (delay 5), Truth\D not due (delay 120);
 * - default: two Truth\F ready; mail and sms empty.
 */
function queueStateBacklog(Scenario $scenario): void
{
    $scenario->dispatch(new TruthJob('Truth\\Parked', ['release:600'], tries: 2), 'slow')->work('slow');

    $scenario->dispatch(new TruthJob('Truth\\A'), 'reports')
        ->dispatch(new TruthJob('Truth\\A'), 'reports')
        ->dispatch(new TruthJob('Truth\\B'), 'reports')
        ->dispatch(new TruthJob('Truth\\C'), 'reports', delay: 5)
        ->dispatch(new TruthJob('Truth\\D'), 'reports', delay: 120)
        ->dispatch(new TruthJob('Truth\\E'), 'slow', delay: 3)
        ->dispatch(new TruthJob('Truth\\F'))
        ->dispatch(new TruthJob('Truth\\F'))
        ->advance(30);
}

it('exports pending, delayed, due and the oldest wait per queue as the queues hold them', function (): void {
    $scenario = Scenario::start($this);
    queueStateBacklog($scenario);
    $samples = new Samples($scenario->scrape());
    $now = $scenario->unix();

    foreach (QUEUE_STATE_QUEUES as $queue) {
        $labels = ['connection' => 'redis', 'queue' => $queue];
        $redis = new RedisState($scenario->queueRedis(), $queue);
        $model = $scenario->model;

        expect($samples->value(Metric::QueuePendingJobs->value, $labels))->toBe((float) $model->pending('redis', $queue, $now), $queue)
            ->and($samples->value(Metric::QueuePendingJobs->value, $labels))->toBe((float) ($redis->ready() + $redis->due($now)), $queue)
            ->and($samples->value(Metric::QueueDelayedJobs->value, $labels))->toBe((float) $model->delayed('redis', $queue), $queue)
            ->and($samples->value(Metric::QueueDelayedJobs->value, $labels))->toBe((float) $redis->delayed(), $queue)
            ->and($samples->value(Metric::QueueDelayedDueJobs->value, $labels))->toBe((float) $model->due('redis', $queue, $now), $queue)
            ->and($samples->value(Metric::QueueDelayedDueJobs->value, $labels))->toBe((float) $redis->due($now), $queue)
            ->and($samples->value(Metric::QueueOldestPendingJobAge->value, $labels))->toEqualWithDelta($model->oldest('redis', $queue, $now), 0.0005, $queue)
            ->and($samples->value(Metric::QueueOldestPendingJobAge->value, $labels))->toEqualWithDelta($redis->oldest($now), 0.0005, $queue);
    }

    // The scenario's own numbers: reports has 3 ready + 1 due, slow 1 due behind a parked release; the due job of
    // slow became available at T+3, 27 s before the scrape.
    expect($samples->sumBy(Metric::QueuePendingJobs->value, 'queue'))->toBe(['default' => 2.0, 'mail' => 0.0, 'reports' => 4.0, 'slow' => 1.0, 'sms' => 0.0])
        ->and($samples->sumBy(Metric::QueueDelayedJobs->value, 'queue'))->toBe(['default' => 0.0, 'mail' => 0.0, 'reports' => 2.0, 'slow' => 2.0, 'sms' => 0.0])
        ->and($samples->value(Metric::QueueOldestPendingJobAge->value, ['connection' => 'redis', 'queue' => 'slow']))->toBe(27.0);
});

it('exports the ready jobs per class, the rest of a long queue as (unscanned)', function (int $scanLimit): void {
    config()->set('horizon-observability.queue_scan_limit', $scanLimit);
    app()->forgetInstance(Config::class);
    $scenario = Scenario::start($this);
    queueStateBacklog($scenario);
    $samples = new Samples($scenario->scrape());

    foreach (QUEUE_STATE_QUEUES as $queue) {
        $expected = [];

        foreach ($scenario->model->pendingByClass('redis', $queue, $scanLimit) as $class => $count) {
            $expected[Model::key(['connection' => 'redis', 'queue' => $queue, 'job_class' => (string) $class])] = $count;
        }

        $actual = array_filter(
            $samples->integers(Metric::QueueJobsPending->value),
            static fn (string $key): bool => Samples::labels($key)['queue'] === $queue,
            ARRAY_FILTER_USE_KEY,
        );

        expect($actual)->toBe($expected, $queue)
            // Every ready job is counted once, by class or as unscanned (due delayed jobs are not in the list yet).
            ->and(array_sum($actual))->toBe((new RedisState($scenario->queueRedis(), $queue))->ready(), $queue);
    }

    if ($scanLimit >= 3) {
        expect((new RedisState($scenario->queueRedis(), 'reports'))->readyByClass())->toBe(['Truth\\A' => 2, 'Truth\\B' => 1]);
    }
})->with(['the default scan limit' => 1000, 'a scan limit of 2' => 2]);

it('exports a job reserved by a real worker under its class and not as pending', function (): void {
    $seen = null;

    Scenarios::attempts($this, static function (Scenario $scenario) use (&$seen): void {
        $seen = [
            'samples' => new Samples($scenario->scrape()),
            'model' => $scenario->model->reservedByClass('redis', 'mail'),
            'redis' => (new RedisState($scenario->queueRedis(), 'mail'))->reservedByClass(),
            'pending' => $scenario->model->pending('redis', 'mail', $scenario->unix()),
        ];
    });

    $reserved = $seen['samples']->series(Metric::QueueJobsReserved->value);

    expect($seen['model'])->toBe(['Truth\\Long' => 1])
        ->and($seen['redis'])->toBe($seen['model'])
        ->and($reserved)->toBe([Model::key(['connection' => 'redis', 'queue' => 'mail', 'job_class' => 'Truth\\Long']) => 1.0])
        ->and($seen['samples']->value(Metric::QueuePendingJobs->value, ['connection' => 'redis', 'queue' => 'mail']))->toBe((float) $seen['pending'])
        ->and($seen['pending'])->toBe(0);
});

it('omits the oldest wait when jobs are ready but none is a first attempt and nothing is due (C8 unknown)', function (): void {
    $scenario = Scenario::start($this);
    // A retried payload at the head of the list (attempts 2), as a worker's migration leaves it.
    QueuePayloads::push($scenario->queueRedis(), 'sms', QueuePayloads::job('Truth\\Retried', ['attempts' => 2, 'pushedAt' => (string) ($scenario->unix() - 50)]));
    $samples = new Samples($scenario->scrape());

    expect($samples->value(Metric::QueuePendingJobs->value, ['connection' => 'redis', 'queue' => 'sms']))->toBe(1.0)
        ->and($samples->value(Metric::QueueOldestPendingJobAge->value, ['connection' => 'redis', 'queue' => 'sms']))->toBeNull()
        ->and((new RedisState($scenario->queueRedis(), 'sms'))->oldest($scenario->unix()))->toBeNull();
});
