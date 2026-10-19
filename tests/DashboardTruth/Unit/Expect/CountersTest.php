<?php

declare(strict_types=1);

use DF\HorizonObservability\Tests\DashboardTruth\Expect\Core\Counters;

/*
| The honest per-sample-pair counter rule of the expectation core (T17, design section 7.2) on T16-style timelines,
| and the tile per-step points adding up to C(from, to) on any grid and step. WP5 proves the rules against promtool.
*/

pest()->group('grafana');

/**
 * One counter series sampled every 10 s from t0 (seconds), values in order, a null a staleness marker.
 *
 * @param  list<float|null>  $values
 */
function dashboardTruthCounter(array $values, int $start = 1000, int $interval = 10): Counters
{
    $times = array_map(static fn (int $i): int => ($start + $i * $interval) * 1000, array_keys($values));

    return new Counters(['s' => ['labels' => ['__name__' => 'ho_queue_jobs_total', 'job' => 'app', 'instance' => 'a'], 't' => $times, 'v' => $values]], 600_000);
}

it('counts from the baseline at the period start', function (): void {
    // 5 at 1000 s, then 7, 9, 12
    $counters = dashboardTruthCounter([5.0, 7.0, 9.0, 12.0]);

    expect($counters->count(1000_000, 1030_000))->toBe(7.0)
        ->and($counters->count(1005_000, 1030_000))->toBe(7.0)
        ->and($counters->count(1010_000, 1030_000))->toBe(5.0)
        ->and($counters->count(1000_000, 1015_000))->toBe(2.0);
});

it('counts every attempt of a series born held at 0, never its first sample', function (): void {
    // a new series: 0 for the hold, then its attempts
    expect(dashboardTruthCounter([0.0, 0.0, 3.0, 4.0], start: 2000)->count(1000_000, 2100_000))->toBe(4.0)
        // a series first seen with history counts only its increase
        ->and(dashboardTruthCounter([100.0, 101.0], start: 2000)->count(1000_000, 2100_000))->toBe(1.0);
});

it('counts a reset as its post-reset value, for that pair only', function (): void {
    expect(dashboardTruthCounter([10.0, 12.0, 2.0, 5.0])->count(1000_000, 1030_000))->toBe(7.0);
});

it('counts pairs across a scrape gap, but not across a gap longer than LOOKBACK before the start (D1)', function (): void {
    expect(dashboardTruthCounter([1.0, null, 4.0])->count(1000_000, 1020_000))->toBe(3.0)
        // the last sample before the start is 20 min old: the first sample in the period is the baseline
        ->and(dashboardTruthCounter([1.0, 5.0, 6.0], start: 1000, interval: 1200)->count(2300_000, 3500_000))->toBe(1.0);
});

it('splits C(from, to) into tile steps that add up to it on any grid', function (int $step, int $offset): void {
    $values = [];

    for ($i = 0, $value = 0.0; $i < 400; $i++) {
        $value += ($i * 7) % 5 === 0 ? 2.0 : (($i % 3) === 0 ? 1.0 : 0.0);
        $values[] = $value;
    }

    $counters = dashboardTruthCounter($values, start: 1740960003, interval: 10);
    $from = 1740961200_000;
    $to = 1740963600_000;
    $first = intdiv($from - $offset, $step) * $step + $offset;
    $grid = [];

    for ($time = $first; $time <= $to; $time += $step) {
        $grid[] = $time;
    }

    $points = $counters->tileSteps($grid, $step, $from, $to);

    expect(array_sum(array_map(static fn (?float $value): float => $value ?? 0.0, $points)))->toBe($counters->count($from, $to))
        ->and($counters->count($from, $to))->toBeGreaterThan(0.0);
})->with([[15_000, 0], [20_000, 0], [10_000, 0], [120_000, 0], [120_000, 60_000], [300_000, 0]]);

it('fails loudly on replicas until WP5 models the dedupe', function (): void {
    new Counters([
        'a' => ['labels' => ['__name__' => 'x', 'job' => 'app', 'instance' => 'a'], 't' => [1000], 'v' => [1.0]],
        'b' => ['labels' => ['__name__' => 'x', 'job' => 'app', 'instance' => 'b'], 't' => [1000], 'v' => [1.0]],
    ], 600_000);
})->throws(LogicException::class, 'Replicas');
