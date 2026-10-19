<?php

declare(strict_types=1);

use DF\HorizonObservability\Tests\DashboardTruth\Compare\Comparator;
use DF\HorizonObservability\Tests\DashboardTruth\Compare\Observation;
use DF\HorizonObservability\Tests\DashboardTruth\Contracts\Expectation;

/*
| The grid of a range target (T17, design section 5.3) on synthetic frames: Grafana's aligned progression of the
| step from the point in (from - step, from] to the point in (to - step, to], at the phase of the returned points,
| whatever points the dashboard returned. A dropped zero, a cut start or end and a gap are missing points of the
| comparison, a point off the grid is an unexpected one, and the tile sum alone never hides them. A series without
| points is no series on either side.
*/

pest()->group('grafana');

const DASHBOARD_TRUTH_FROM = 1740962400000;

const DASHBOARD_TRUTH_TO = 1740966000000;

/**
 * A Prometheus range frame of one series at the given step.
 *
 * @param  array<int, float>  $points  time => value
 * @return array<string, mixed>
 */
function dashboardTruthRangeFrame(array $points, string $step = '15s'): array
{
    return [
        'schema' => ['meta' => ['executedQueryString' => "Expr: x\nStep: {$step}"], 'fields' => [['name' => 'Time', 'type' => 'time'], ['name' => 'Value', 'type' => 'number', 'labels' => []]]],
        'data' => ['values' => [array_keys($points), array_values($points)]],
    ];
}

/**
 * The truth on the full 15 s grid of the hour: 1 every fourth point, else 0.
 *
 * @return array<int, float>
 */
function dashboardTruthHour(): array
{
    $points = [];

    foreach (range(DASHBOARD_TRUTH_FROM, DASHBOARD_TRUTH_TO, 15000) as $index => $time) {
        $points[$time] = $index % 4 === 1 ? 1.0 : 0.0;
    }

    return $points;
}

it('is the full aligned progression of the step, whatever points the dashboard returned', function (): void {
    $nonZero = array_filter(dashboardTruthHour(), static fn (float $value): bool => $value !== 0.0);
    $grid = Observation::grid([dashboardTruthRangeFrame($nonZero)], 15000, DASHBOARD_TRUTH_FROM, DASHBOARD_TRUTH_TO);

    expect($grid)->toHaveCount(241)
        ->and($grid[0])->toBe(DASHBOARD_TRUTH_FROM)
        ->and($grid[240])->toBe(DASHBOARD_TRUTH_TO)
        ->and(Observation::grid([dashboardTruthRangeFrame([DASHBOARD_TRUTH_FROM + 600000 => 1.0])], 15000, DASHBOARD_TRUTH_FROM, DASHBOARD_TRUTH_TO))->toBe($grid);
});

it('aligns both ends down to the step and reads the phase from the returned points', function (): void {
    // An unaligned range: the first point is the step multiple at or before from, the last one at or before to
    $grid = Observation::grid([dashboardTruthRangeFrame([DASHBOARD_TRUTH_FROM => 1.0])], 15000, DASHBOARD_TRUTH_FROM + 7000, DASHBOARD_TRUTH_TO + 14000);

    expect($grid[0])->toBe(DASHBOARD_TRUTH_FROM)
        ->and($grid[count($grid) - 1])->toBe(DASHBOARD_TRUTH_TO)
        ->and($grid)->toHaveCount(241);

    // A relative range in Asia/Kathmandu shifts the 2 m grid by 60 s (design section 5.5): the last point is 60 s
    // before to
    $shifted = Observation::grid([dashboardTruthRangeFrame([DASHBOARD_TRUTH_FROM + 1260000 => 3.0], '2m0s')], 120000, DASHBOARD_TRUTH_FROM, DASHBOARD_TRUTH_TO);

    expect($shifted[0])->toBe(DASHBOARD_TRUTH_FROM - 60000)
        ->and($shifted[count($shifted) - 1])->toBe(DASHBOARD_TRUTH_TO - 60000)
        ->and($shifted)->toHaveCount(31);
});

it('has the epoch-aligned grid without any returned point', function (): void {
    expect(Observation::grid([], 15000, DASHBOARD_TRUTH_FROM, DASHBOARD_TRUTH_TO))->toBe(range(DASHBOARD_TRUTH_FROM, DASHBOARD_TRUTH_TO, 15000));
});

it('reports a dropped zero, a cut start, a cut end and a point off the grid as point differences', function (array $points, string $difference): void {
    $truth = dashboardTruthHour();
    $expected = new Expectation([['labels' => [], 'points' => $truth]], total: array_sum($truth));
    $actual = Observation::series([dashboardTruthRangeFrame($points)]);

    expect(Comparator::differences($expected, $actual))->toContain($difference)
        ->and(Comparator::differences($expected, Observation::series([dashboardTruthRangeFrame($truth)])))->toBe([]);
})->with([
    'the zeros dropped (the tile sum unchanged)' => [array_filter(dashboardTruthHour(), static fn (float $value): bool => $value !== 0.0), '{} at 2025-03-03T00:40:00Z: expected 0, actual no point'],
    'a cut start' => [array_slice(dashboardTruthHour(), 4, null, true), '{} at 2025-03-03T00:40:15Z: expected 1, actual no point'],
    'a cut end' => [array_slice(dashboardTruthHour(), 0, 240, true), '{} at 2025-03-03T01:40:00Z: expected 0, actual no point'],
    'a point off the grid' => [dashboardTruthHour() + [DASHBOARD_TRUTH_FROM + 7000 => 0.0], '{} at 2025-03-03T00:40:07Z: expected no point, actual 0'],
]);

it('takes a series without points for no series on either side', function (): void {
    $empty = new Expectation([['labels' => [], 'points' => []]]);

    expect(Comparator::differences($empty, []))->toBe([])
        ->and(Comparator::differences($empty, Observation::series([dashboardTruthRangeFrame([])])))->toBe([])
        ->and(Comparator::differences(new Expectation([['labels' => [], 'points' => [DASHBOARD_TRUTH_TO => 0.0]]]), []))->toBe(['the series {} is missing (expected 1 points)']);
});
