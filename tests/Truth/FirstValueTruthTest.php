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
| (90 s) ago as 0, so every scraper records a 0 before the real value, which then counts in full. The budget: scrape
| intervals up to 60 s, scrapes up to 7.5 s off their slots, worker clocks up to 15 s off the exporter's, either way.
| A series without a creation time (the counters a new scrape job or a late replica finds, data from before the
| creation times) is exported as it is: its old total must not count as new.
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

it('restates the collector hold in the model and in the HELP text of queue_jobs_total', function (): void {
    expect(Model::HOLD_SECONDS)->toBe(AttemptsCollector::NEW_SERIES_HOLD_SECONDS)
        ->and(Metric::JobsTotal->help())->toContain('a new series reads 0 for its first '.AttemptsCollector::NEW_SERIES_HOLD_SECONDS.' seconds');
});

it('exports a new series as 0 for the hold, then with every attempt it was created with', function (): void {
    $scenario = Scenario::start($this);

    foreach (range(1, 3) as $ignored) {
        $scenario->dispatch(new TruthJob('Truth\\Invoice'));
    }

    $scenario->advance(2)->work();
    $zero = ['completed' => 0, 'durations' => 0, 'waits' => 0];

    expect(AttemptsCollector::NEW_SERIES_HOLD_SECONDS)->toBe(90)
        ->and(firstValueCounts($scenario))->toBe($zero)
        ->and(firstValueCounts($scenario->advance(30)))->toBe($zero)
        ->and(firstValueCounts($scenario->advance(AttemptsCollector::NEW_SERIES_HOLD_SECONDS - 30.001)))->toBe($zero)
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

/**
 * How far a scrape at `$slot` (seconds after the creation) lands off its slot, within the ±7.5 s budget.
 * `worst`: the slots whose early scrape comes before the creation are early, every later one is late, so the first
 * scrape that sees the series comes as late as the budget allows (S + 15 s after the creation, less 1 ms).
 */
function firstValueDeviation(string $pattern, int $k, float $slot): float
{
    $j = 7.5;

    return match ($pattern) {
        'none' => 0.0,
        'worst' => $slot < $j ? -$j : $j,
        'early-late' => $k % 2 === 0 ? -$j : $j,
        'late-early' => $k % 2 === 0 ? $j : -$j,
        default => throw new LogicException($pattern),
    };
}

it('exports a new series as 0 on its first scrape within the budget: S <= 60 s, ±7.5 s off the slots, clocks ±15 s apart', function (int $interval, float $phase, string $pattern, int $behind): void {
    $hold = AttemptsCollector::NEW_SERIES_HOLD_SECONDS;
    $created = 1_800_000_000;
    $at = static fn (float $seconds): CarbonImmutable => CarbonImmutable::createFromTimestamp($seconds);
    // The worker that records the first attempt has a clock $behind seconds behind the exporter's (negative: ahead).
    Storage::record(status: AttemptStatus::Completed, duration: 0.2, now: $at($created - $behind));
    $series = 'connection=redis,queue=default,job_class=App\\Jobs\\SendInvoice,status=completed';
    $values = [];

    // Scrapes on a fixed grid of slots, each one off its slot by the pattern; times are the exporter's clock.
    for ($k = -2; ($slot = $phase + $k * $interval) < $hold + 15 + 3 * $interval; $k++) {
        $scrape = $created + $slot + firstValueDeviation($pattern, $k, $slot);

        if ($scrape < $created) {
            continue; // before the attempt: the series does not exist yet
        }

        $value = Storage::scrape($at($scrape))['samples']['queue_jobs_total'][$series];
        $age = $scrape - ($created - $behind);

        // The rule, restated: held while the age by the exporter's clock is below the hold, in either direction.
        expect($value)->toBe(abs($age) < $hold ? 0 : 1, sprintf('scrape at +%.3f s, age %.3f s', $scrape - $created, $age));
        $values[sprintf('%014.3f', $scrape - $created)] = $value;
    }

    // The guarantee, in the order the samples are taken (alternating deviations at short intervals reorder the grid):
    // the first sample of the series is 0, then the full count, never decreasing.
    ksort($values, SORT_STRING);
    $values = array_values($values);
    $sorted = $values;
    sort($sorted);

    expect($values[0])->toBe(0)
        ->and(end($values))->toBe(1)
        ->and($values)->toBe($sorted);
})->with(function (): array {
    $cases = [];

    foreach ([5, 10, 15, 30, 45, 60] as $interval) {
        // A slot right at the creation, mid-interval, right before the next slot, and 7.5 s less 1 ms after the
        // creation (with `worst`, that scrape comes 1 ms before the creation and the next one S + 15 s after it).
        foreach ([0.0, $interval / 2, $interval - 0.001, 7.499] as $phase) {
            foreach (['none', 'worst', 'early-late', 'late-early'] as $pattern) {
                foreach ([-15, 0, 15] as $behind) {
                    $cases[sprintf('S=%d phase=%.3f %s worker %+d s', $interval, $phase, $pattern, -$behind)] = [$interval, $phase, $pattern, $behind];
                }
            }
        }
    }

    return $cases;
});

it('holds the worst case of the budget by 1 ms: the first scrape 89.999 s old by the exporter\'s clock', function (): void {
    // S = 60 s: the scrape before the creation 7.5 s early (1 ms before it), the next one 7.5 s late, the worker
    // clock 15 s behind: 60 + 15 + 15 - 0.001.
    $created = 1_800_000_000;
    Storage::record(now: CarbonImmutable::createFromTimestamp($created - 15));
    $first = Storage::scrape(CarbonImmutable::createFromTimestamp($created + 74.999))['samples']['queue_jobs_total'];
    $after = Storage::scrape(CarbonImmutable::createFromTimestamp($created + 75.0))['samples']['queue_jobs_total'];
    $series = 'connection=redis,queue=default,job_class=App\\Jobs\\SendInvoice,status=completed';

    expect($first[$series])->toBe(0)
        ->and($after[$series])->toBe(1);
});

it('holds a series created during the scrape, after its request time', function (): void {
    // The scrape's request time is its clock for every collector; the counters are read up to the scrape budget
    // (8 s at most) later, so a series created in between has a negative age and must be held too.
    $request = 1_800_000_000;
    Storage::record(now: CarbonImmutable::createFromTimestamp($request + 7.9));
    $series = 'connection=redis,queue=default,job_class=App\\Jobs\\SendInvoice,status=completed';

    expect(Storage::scrape(CarbonImmutable::createFromTimestamp($request))['samples']['queue_jobs_total'][$series])->toBe(0)
        ->and(Storage::scrape(CarbonImmutable::createFromTimestamp($request + 7.9 + AttemptsCollector::NEW_SERIES_HOLD_SECONDS))['samples']['queue_jobs_total'][$series])->toBe(1);
});

it('never holds a series for longer than twice the hold, whatever the worker clock says', function (): void {
    // A creation time an hour ahead (a broken worker clock) is outside the budget: the series is exported as it is.
    $now = 1_800_000_000;
    Storage::record(now: CarbonImmutable::createFromTimestamp($now + 3600));
    $series = 'connection=redis,queue=default,job_class=App\\Jobs\\SendInvoice,status=completed';

    expect(Storage::scrape(CarbonImmutable::createFromTimestamp($now))['samples']['queue_jobs_total'][$series])->toBe(1);
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
