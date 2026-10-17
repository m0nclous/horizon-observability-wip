<?php

declare(strict_types=1);

use DF\HorizonObservability\Metrics\Metric;
use DF\HorizonObservability\Tests\Integration\Batches\Support\BatchTable;
use DF\HorizonObservability\Tests\Support\HorizonState;
use DF\HorizonObservability\Tests\Truth\Support\Invariants;
use DF\HorizonObservability\Tests\Truth\Support\RedisState;
use DF\HorizonObservability\Tests\Truth\Support\Samples;
use DF\HorizonObservability\Tests\Truth\Support\Scenario;
use DF\HorizonObservability\Tests\Truth\Support\Scenarios;
use DF\HorizonObservability\Tests\Truth\Support\TruthJob;
use Illuminate\Support\Facades\Bus;

/*
| Exporter truth (T16): the invariants of Support\Invariants (histogram shape, the five statuses, observations per
| attempt, classes adding up to the pending jobs incl. "(unscanned)", due within delayed and pending, pools with
| processes, batch progress, self-metrics) hold at every scrape of the other scenarios; the timeline scrapes are
| checked in ScrapeIntervalTruthTest and ResetTruthTest. Reserved jobs per class add up to the reserved set in Redis.
*/

pest()->group('redis', 'truth');

afterEach(function (): void {
    Scenario::stop();
});

it('holds the invariants before, while and after a job is reserved and every attempt outcome', function (): void {
    $scrapes = [];
    $reserved = null;

    $scenario = Scenarios::attempts($this, static function (Scenario $scenario) use (&$scrapes, &$reserved): void {
        $samples = new Samples($scenario->scrape());
        $scrapes['reserved'] = $samples;
        $reserved = [
            'classes' => (int) array_sum($samples->series(Metric::QueueJobsReserved->value)),
            'redis' => (new RedisState($scenario->queueRedis(), 'mail'))->reserved(),
        ];
    });
    $scrapes['settled'] = new Samples($scenario->scrape());

    foreach ($scrapes as $moment => $samples) {
        expect(Invariants::violations($samples))->toBe([], $moment);
    }

    expect($reserved)->toBe(['classes' => 1, 'redis' => 1]);
});

it('holds the invariants of a backlog, a long queue beyond the scan limit, batches and two Horizon hosts', function (): void {
    config()->set('horizon-observability.queue_scan_limit', 3);
    BatchTable::create();
    HorizonState::using(app())
        ->master('host-a', 'running', ['host-a:supervisor-default', 'host-a:supervisor-pool'])
        ->supervisor('host-a:supervisor-default', HorizonState::options('host-a:supervisor-default', queue: 'default'), ['redis:default' => 2])
        ->supervisor('host-a:supervisor-pool', HorizonState::options('host-a:supervisor-pool', queue: 'mail,sms', balance: false), ['redis:mail,sms' => 1]);
    $scenario = Scenario::start($this);

    foreach (range(1, 8) as $index) {
        $scenario->dispatch(new TruthJob('Truth\\Bulk'.($index % 3)), 'reports');
    }

    $scenario->dispatch(new TruthJob('Truth\\Soon'), 'reports', delay: 5)
        ->dispatch(new TruthJob('Truth\\Later'), 'reports', delay: 500)
        ->dispatch(new TruthJob('Truth\\Parked', ['release:900'], tries: 2), 'slow')
        ->work('slow');
    Bus::batch([new TruthJob('Truth\\Row'), new TruthJob('Truth\\Row', ['throw'])])->name('import-7')->allowFailures()->onConnection('redis')->onQueue('sms')->dispatch();
    $scenario->advance(10)->work('sms')->advance(60);
    $samples = new Samples($scenario->scrape());

    expect(Invariants::violations($samples))->toBe([])
        ->and($samples->value(Metric::QueueJobsPending->value, ['connection' => 'redis', 'queue' => 'reports', 'job_class' => '(unscanned)']))->toBe(5.0)
        ->and($samples->series(Metric::BatchProgress->value))->not->toBe([]);
});
