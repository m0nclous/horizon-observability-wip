<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use DF\HorizonObservability\Attempts\AttemptStatus;
use DF\HorizonObservability\Collectors\AttemptsCollector;
use DF\HorizonObservability\Metrics\Metric;
use DF\HorizonObservability\Redis\Keys;
use DF\HorizonObservability\Tests\Fixtures\Attempts\Storage;
use DF\HorizonObservability\Tests\Truth\Support\Model;
use DF\HorizonObservability\Tests\Truth\Support\Samples;
use DF\HorizonObservability\Tests\Truth\Support\Scenario;
use DF\HorizonObservability\Tests\Truth\Support\TruthJob;

/*
| The first value of a new counter series (T16; the pilot's Completed/Failed tiles dropped it, T13 QA round 1).
|
| Prometheus cannot count the first sample of a series: increase() and the dashboard's exact differences start
| from it. The exporter therefore exports a series created less than AttemptsCollector::NEW_SERIES_HOLD_SECONDS
| (75 s) ago as 0, so every scraper (5-60 s intervals, every replica) records a 0 before the real value, which then
| counts in full. A series without a creation time (the counters a new scrape job or a late replica finds, data from
| before the creation times) is exported as it is: its old total must not count as new.
| Evidence on a real Prometheus v3.15.0: tests/Truth/probes/first-value (README there).
*/

pest()->group('redis', 'truth');

afterEach(function (): void {
    Scenario::stop();
});

/**
 * @return array{completed: int, durations: int, waits: int}
 */
function firstValueCounts(Scenario $scenario, string $class = 'Truth\\Invoice', string $queue = 'default'): array
{
    $samples = new Samples($scenario->scrape());
    $series = ['connection' => 'redis', 'queue' => $queue, 'job_class' => $class];

    return [
        'completed' => (int) $samples->value(Metric::JobsTotal->value, [...$series, 'status' => 'completed']),
        'durations' => (int) $samples->value(Metric::JobDuration->value.'_count', $series),
        'waits' => (int) $samples->value(Metric::JobWait->value.'_count', ['connection' => 'redis', 'queue' => $queue]),
    ];
}

it('restates the collector hold in the model', function (): void {
    expect(Model::HOLD_SECONDS)->toBe(AttemptsCollector::NEW_SERIES_HOLD_SECONDS);
});

it('exports a new series as 0 for 75 s, then with every attempt it was created with', function (): void {
    $scenario = Scenario::start($this);

    foreach (range(1, 3) as $ignored) {
        $scenario->dispatch(new TruthJob('Truth\\Invoice'));
    }

    $scenario->advance(2)->work();
    $zero = ['completed' => 0, 'durations' => 0, 'waits' => 0];

    expect(firstValueCounts($scenario))->toBe($zero)
        ->and(firstValueCounts($scenario->advance(30)))->toBe($zero)
        ->and(firstValueCounts($scenario->advance(44.999)))->toBe($zero)
        ->and(firstValueCounts($scenario->advance(0.001)))->toBe(['completed' => 3, 'durations' => 3, 'waits' => 3]);
});

it('does not hold later attempts, a new status of a known series or the waits of a known queue', function (): void {
    $scenario = Scenario::start($this);
    $scenario->dispatch(new TruthJob('Truth\\Invoice'))->work()->advance(AttemptsCollector::NEW_SERIES_HOLD_SECONDS);

    expect(firstValueCounts($scenario))->toBe(['completed' => 1, 'durations' => 1, 'waits' => 1]);

    $scenario->dispatch(new TruthJob('Truth\\Invoice'))
        ->dispatch(new TruthJob('Truth\\Invoice', ['fail']))
        ->dispatch(new TruthJob('Truth\\Fresh'))
        ->work();
    $samples = new Samples($scenario->scrape());
    $invoice = ['connection' => 'redis', 'queue' => 'default', 'job_class' => 'Truth\\Invoice'];
    $fresh = ['connection' => 'redis', 'queue' => 'default', 'job_class' => 'Truth\\Fresh'];

    expect($samples->value(Metric::JobsTotal->value, [...$invoice, 'status' => 'completed']))->toBe(2.0)
        // The first failure of a known series: zero-filled before, so increase() counts it.
        ->and($samples->value(Metric::JobsTotal->value, [...$invoice, 'status' => 'failed']))->toBe(1.0)
        ->and($samples->value(Metric::JobDuration->value.'_count', $invoice))->toBe(3.0)
        // A new class on a known queue: its series wait, the queue's waits do not.
        ->and($samples->value(Metric::JobsTotal->value, [...$fresh, 'status' => 'completed']))->toBe(0.0)
        ->and($samples->value(Metric::JobDuration->value.'_count', $fresh))->toBe(0.0)
        ->and($samples->value(Metric::JobWait->value.'_count', ['connection' => 'redis', 'queue' => 'default']))->toBe(4.0);
});

it('exports series without a creation time as they are, so old totals never count as new', function (): void {
    $scenario = Scenario::start($this);

    foreach (range(1, 4) as $ignored) {
        $scenario->dispatch(new TruthJob('Truth\\Invoice'));
    }

    $scenario->work();
    // Counters without creation times: a scrape job added later or a late replica finds them like this.
    Storage::store()->reader()->del((new Keys)->created());

    expect(firstValueCounts($scenario))->toBe(['completed' => 4, 'durations' => 4, 'waits' => 4]);
});

it('exports a new series as 0 on at least one scrape of every supported schedule (5-60 s, jitter up to 7.5 s)', function (int $interval, float $phase, float $jitter): void {
    $created = 1_800_000_000.0;
    $at = static fn (float $seconds): CarbonImmutable => CarbonImmutable::createFromTimestamp($seconds);
    Storage::record(status: AttemptStatus::Completed, duration: 0.2, now: $at($created));
    $series = 'connection=redis,queue=default,job_class=App\\Jobs\\SendInvoice,status=completed';
    $zeros = 0;

    // Scrapes on a fixed grid around the creation, each one jittered: alternately early and late by $jitter.
    for ($k = -3; $k * $interval < AttemptsCollector::NEW_SERIES_HOLD_SECONDS + 3 * $interval; $k++) {
        $scrape = $created + $phase + $k * $interval + ($k % 2 === 0 ? $jitter : -$jitter);

        if ($scrape < $created) {
            continue;
        }

        $value = Storage::scrape($at($scrape))['samples']['queue_jobs_total'][$series];
        $expected = $scrape - $created < AttemptsCollector::NEW_SERIES_HOLD_SECONDS ? 0 : 1;

        expect($value)->toBe($expected, sprintf('scrape at +%.3f s', $scrape - $created));
        $zeros += $value === 0 ? 1 : 0;
    }

    expect($zeros)->toBeGreaterThanOrEqual(1);
})->with(function (): array {
    $cases = [];

    foreach ([5, 10, 15, 30, 45, 60] as $interval) {
        foreach ([0.0, 0.001, $interval / 2, $interval - 0.001] as $phase) {
            foreach ([0.0, 7.5] as $jitter) {
                $cases[sprintf('S=%d phase=%.3f jitter=%.1f', $interval, $phase, $jitter)] = [$interval, $phase, $jitter];
            }
        }
    }

    return $cases;
});

it('holds the series of a counter reset again, so the counts after it start at 0', function (): void {
    $scenario = Scenario::start($this);
    $scenario->dispatch(new TruthJob('Truth\\Invoice'))->work()->advance(AttemptsCollector::NEW_SERIES_HOLD_SECONDS);

    expect(firstValueCounts($scenario)['completed'])->toBe(1);

    // A restored dataset: another run id in meta makes the reset guard delete the counters and creation times.
    Storage::store()->reader()->hset((new Keys)->meta(), 'run_id', 'a-restored-dataset');
    $scenario->scrape();
    $scenario->dispatch(new TruthJob('Truth\\Invoice'))->dispatch(new TruthJob('Truth\\Invoice'))->work();

    expect(firstValueCounts($scenario)['completed'])->toBe(0)
        ->and(firstValueCounts($scenario->advance(AttemptsCollector::NEW_SERIES_HOLD_SECONDS))['completed'])->toBe(2);
});
