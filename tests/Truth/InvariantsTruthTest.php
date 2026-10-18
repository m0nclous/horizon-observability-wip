<?php

declare(strict_types=1);

use DF\HorizonObservability\Collectors\AttemptsCollector;
use DF\HorizonObservability\Metrics\Metric;
use DF\HorizonObservability\Redis\Keys;
use DF\HorizonObservability\Tests\Fixtures\Attempts\Storage;
use DF\HorizonObservability\Tests\Integration\Batches\Support\BatchTable;
use DF\HorizonObservability\Tests\Support\Exposition;
use DF\HorizonObservability\Tests\Support\HorizonState;
use DF\HorizonObservability\Tests\Truth\Support\Invariants;
use DF\HorizonObservability\Tests\Truth\Support\RedisState;
use DF\HorizonObservability\Tests\Truth\Support\Samples;
use DF\HorizonObservability\Tests\Truth\Support\Scenario;
use DF\HorizonObservability\Tests\Truth\Support\Scenarios;
use DF\HorizonObservability\Tests\Truth\Support\TruthJob;
use Illuminate\Support\Facades\Bus;
use PHPUnit\Framework\ExpectationFailedException;

/*
| Exporter truth (T16): the invariants of Support\Invariants (histogram shape, the five statuses, observations per
| attempt, waits per attempt outside a hold, classes adding up to the pending jobs incl. "(unscanned)", due within
| delayed and pending, pools with processes, batch progress, self-metrics, counters that never decrease except at a
| documented reset) are asserted by Scenario::scrape() at every scrape of every scenario. The cases here put the
| invariants under pressure; reserved jobs per class add up to the reserved set in Redis.
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

it('fails a scenario scrape whose counter decreases without a documented reset', function (): void {
    $scenario = Scenario::start($this);
    $scenario->dispatch(new TruthJob('Truth\\Invoice'))->dispatch(new TruthJob('Truth\\Invoice'))->work()->advance(AttemptsCollector::NEW_SERIES_HOLD_SECONDS);
    $scenario->scrape();
    // One attempt less in the storage, as a lost write or a bad merge would leave it; meta.reset_at stays.
    Storage::store()->reader()->hincrby((new Keys)->attempts(), Keys::field('redis', 'default', 'Truth\\Invoice', 'completed'), -1);

    expect(fn (): mixed => $scenario->scrape())->toThrow(ExpectationFailedException::class, 'decreased from 2 to 1 without a documented reset');
});

it('holds the invariants while a new class on a known queue is held: its waits lead its attempts for the hold only', function (): void {
    $scenario = Scenario::start($this);
    $scenario->dispatch(new TruthJob('Truth\\Invoice'))->work()->advance(AttemptsCollector::NEW_SERIES_HOLD_SECONDS);
    $scenario->scrape();

    // A deploy brings a new class to the known queue: its attempts are held, the queue's waits are not.
    foreach (range(1, 3) as $ignored) {
        $scenario->dispatch(new TruthJob('Truth\\Fresh'));
    }

    $waits = static fn (Samples $samples): ?float => $samples->value(Metric::JobWait->value.'_count', ['connection' => 'redis', 'queue' => 'default']);
    $attempts = static fn (Samples $samples): float => array_sum($samples->series(Metric::JobsTotal->value));
    $held = new Samples($scenario->work()->advance(1)->scrape());
    $settled = new Samples($scenario->advance(AttemptsCollector::NEW_SERIES_HOLD_SECONDS)->scrape());

    // Scenario::scrape() asserted the invariants at both scrapes.
    expect([$waits($held), $attempts($held)])->toBe([4.0, 1.0])
        ->and([$waits($settled), $attempts($settled)])->toBe([4.0, 4.0]);
});

it('allows more waits than attempts only while a series of the queue is held', function (): void {
    $exposition = static function (bool $withHeld): Samples {
        $lines = ['# TYPE ho_queue_jobs_total counter'];

        foreach (['completed' => 1, 'released' => 0, 'retried' => 0, 'failed' => 0, 'timed_out' => 0] as $status => $count) {
            $lines[] = sprintf('ho_queue_jobs_total{connection="redis",queue="default",job_class="Truth\\\\Old",status="%s"} %d', $status, $count);

            if ($withHeld) {
                $lines[] = sprintf('ho_queue_jobs_total{connection="redis",queue="default",job_class="Truth\\\\New",status="%s"} 0', $status);
            }
        }
        $lines[] = '# TYPE ho_queue_job_wait_seconds histogram';
        $lines[] = 'ho_queue_job_wait_seconds_bucket{connection="redis",queue="default",le="+Inf"} 3';
        $lines[] = 'ho_queue_job_wait_seconds_sum{connection="redis",queue="default"} 1.5';
        $lines[] = 'ho_queue_job_wait_seconds_count{connection="redis",queue="default"} 3';

        return new Samples(Exposition::parse(implode("\n", $lines)."\n"));
    };

    expect(Invariants::violations($exposition(false)))->toBe(['queue_job_wait_seconds{connection="redis",queue="default"}: 3 waits for 1 attempts, and no series of the queue is held'])
        ->and(Invariants::violations($exposition(true)))->toBe([]);
});
