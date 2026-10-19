<?php

declare(strict_types=1);

use DF\HorizonObservability\Grafana\PanelRegistry;
use DF\HorizonObservability\Tests\Grafana\Support\Promtool;
use DF\HorizonObservability\Tests\Grafana\Support\Rendered;

/*
 * T13 QA round 4 (spec tester), owner decision 13 and ADDENDUM R2.4 O4/O7.
 *
 * 1. A series that stops inside the range. Attempts::delta() ends every series at its instant value at the period end
 *    (5-minute lookback, none after a staleness marker), so the tables leave out every attempt of a series that has no
 *    value then, while the tiles count it. The same application moved to another scrape job inside the range (the
 *    dedicated scrape job of owner decision 8 and ADDENDUM R3.2): the round 3 render counted 150 here, the round 4
 *    render 100. (Also found by the adversarial tester, AdversarialRound4StopTest.)
 *
 * 2. The period edges as Grafana really sends them. Grafana 12.4.13 and 13.2.4 format `${__from:date:seconds}` and
 *    `${__to:date:seconds}` as Math.round(ms / 1000) (the `seconds` case of the date formatter in both bundles), evaluate
 *    an instant query (the tables) at the exact `to` in milliseconds, and align a range query (the tiles, #14) to
 *    floor((t + utcOffsetSec) / step) * step - utcOffsetSec (both checked through /api/ds/query with a `to` of x.700 s:
 *    the instant `time()` was x.7, the range points on the offset grid). The tiles' points must add up to the table
 *    count of the same request (O4: "so the points add up to the period count"):
 *    - a scrape between the exact period start and its rounded second is counted by the table, not by the tiles
 *      (any time zone; with samples on arbitrary milliseconds about 2.5 % of the refreshes per edge at 10 s scrapes);
 *    - where the UTC offset is not a multiple of the step and `to` lies less than 0.5 s before a Grafana point, no point
 *      passes the last-point test and the last step ends at the next subquery point instead of `to` (also found by the
 *      adversarial tester, AdversarialRound4TileTest).
 */

pest()->group('grafana');

beforeEach(function (): void {
    if (Promtool::command() === null) {
        $this->markTestSkipped('promtool is not available; set PROMTOOL or put promtool on the PATH.');
    }
});

it('counts the attempts of a series that stops inside the range in the tables, as the tiles do', function (string $stop): void {
    // Job a (App\Jobs\Import on default) is moved to job b at 10m: a stops (a staleness marker, or no marker after a
    // configuration reload: then it leaves the instant lookback after 5 minutes), b continues the same counter. Samples
    // every minute, the range (5m, 20m]: a 150 -> 200 (50), b 200 -> 300 (100, its first value not counted): 150.
    $series = static fn (string $job): string => sprintf(
        'ho_queue_jobs_total{job="%s", instance="%s:8080", connection="redis", queue="default", job_class="App\\\\Jobs\\\\Import", status="completed"}',
        $job,
        $job,
    );
    $end = $stop === 'staleness marker' ? ' stale' : '';
    $input = [
        ['series' => 'ho_horizon_status{job="a", instance="a:8080"}', 'values' => '1x10'.$end],
        ['series' => 'ho_horizon_status{job="b", instance="b:8080"}', 'values' => '_x10 1x10'],
        ['series' => 'ho_queue_pending_jobs{job="a", instance="a:8080", connection="redis", queue="default"}', 'values' => '0x10'.$end],
        ['series' => 'ho_queue_pending_jobs{job="b", instance="b:8080", connection="redis", queue="default"}', 'values' => '_x10 0x10'],
        ['series' => $series('a'), 'values' => '100+10x10'.$end],
        ['series' => $series('b'), 'values' => '_x10 200+10x10'],
    ];
    $expr = static fn (int $id, string $refId): string => Promtool::interpolate(Rendered::expressions()['panel '.$id.' '.$refId], '.+', 20 * 60, 15 * 60);
    $completed = 'label_replace(vector(1), "status", "completed", "", "")';
    $one = static fn (int $value): array => [['labels' => '{}', 'value' => $value]];

    [$status, $output] = Promtool::testRules([], [[
        'interval' => '1m',
        'input_series' => $input,
        'promql_expr_test' => [
            // The tile (its points summed at the 1-minute step): 150, already right
            ['expr' => 'sum(sum_over_time(('.$expr(PanelRegistry::COMPLETED, 'A').')[16m:1m]))', 'eval_time' => '20m', 'exp_samples' => $one(150)],
            // The queue tables (#5 read #24 C), the Jobs table #6 B and the Failed tile's threshold base #4 C must count 150
            // like the tile; the render gives 100
            ['expr' => 'sum(('.$expr(PanelRegistry::QUEUE_SOURCE, 'C').') and on (status) '.$completed.')', 'eval_time' => '20m', 'exp_samples' => $one(150)],
            ['expr' => 'sum(('.$expr(PanelRegistry::JOBS, 'B').') and on (status) '.$completed.')', 'eval_time' => '20m', 'exp_samples' => $one(150)],
            ['expr' => $expr(PanelRegistry::FAILED, 'C'), 'eval_time' => '20m', 'exp_samples' => $one(150)],
        ],
    ]]);

    expect($status)->toBe(0, round4SpecOutput($output));
})->with(['staleness marker', 'no staleness marker']);

it('adds the tile points up to the table count of the same request at the exact period edges', function (int $offset, int $step, int $range, float $to): void {
    // One class failing once a scrape: sample i at 10 i s has the value i; the period ($to - $range, $to] ends now (the
    // last sample is the last one at or before $to). Expected per point: the attempts of its step clipped to the exact
    // period, as Grafana's points tile it (the step that holds the start from the start, the last point to the end).
    $from = $to - $range;
    $align = static fn (float $t): int => (int) (floor(($t + $offset) / $step) * $step - $offset);
    $value = static fn (float $t): int => (int) floor($t / 10);
    $last = (int) floor($to / 10);
    $expr = str_replace('end()', (string) $align($to), Promtool::interpolate(strtr(Rendered::expressions()['panel '.PanelRegistry::FAILED.' A'], [
        '${__interval_ms}' => (string) ($step * 1000),
        '$__interval_ms' => (string) ($step * 1000),
        '$__interval' => $step.'s',
    ]), '.+', (int) round($to), $range));
    $table = Promtool::interpolate(Rendered::expressions()['panel '.PanelRegistry::QUEUE_SOURCE.' C'], '.+', (int) round($to), $range);
    $one = static fn (int $value): array => [['labels' => '{}', 'value' => $value]];
    $truth = $value($to) - $value($from);
    $tests = [
        // promtool loads the samples only up to the last whole minute of the latest evaluation time
        ['expr' => 'vector(1)', 'eval_time' => ((int) ceil(($last * 10 + 1) / 60) * 60).'s', 'exp_samples' => $one(1)],
        // The table, an instant query at the exact end: the attempts of the exact period
        ['expr' => 'sum(('.$table.') and on (status) label_replace(vector(1), "status", "failed", "", ""))', 'eval_time' => (int) ($to * 1000).'ms', 'exp_samples' => $one($truth)],
    ];
    $sum = 0;

    for ($point = $align($from); $point <= $align($to); $point += $step) {
        $previous = (int) floor($point / $step) * $step;
        $attempts = $previous + $step <= $from ? 0 : $value($point + $step > $to ? $to : $previous + $step) - $value(max($from, $previous));
        $sum += $attempts;
        $tests[] = ['expr' => $expr, 'eval_time' => $point.'s', 'exp_samples' => $one($attempts)];
    }

    [$status, $output] = Promtool::testRules([], [[
        'interval' => '10s',
        'input_series' => [
            ['series' => 'ho_horizon_status{job="app", instance="app:8080"}', 'values' => '1x'.$last],
            ['series' => 'ho_queue_pending_jobs{job="app", instance="app:8080", connection="redis", queue="default"}', 'values' => '0x'.$last],
            ['series' => 'ho_queue_jobs_total{job="app", instance="app:8080", connection="redis", queue="default", job_class="App\\\\Jobs\\\\Import", status="failed"}', 'values' => '0+1x'.$last],
        ],
        'promql_expr_test' => $tests,
    ]]);

    expect($sum)->toBe($truth)
        ->and($status)->toBe(0, round4SpecOutput($output));
})->with([
    // UTC, the Failed tile's 15 s step at 1 hour: the scrape at 1210 s lies in (1209.7, 1210], the rounded start; the
    // first point (1200, its step holds the start) must count it: 1, the render gives 0 (the table counts it)
    'UTC, 15 s steps, a scrape between the start and its rounded second' => [0, 15, 3600, 4809.7],
    // Asia/Kathmandu (utcOffsetSec 20700), the Failed tile's 2-minute step at 6 hours: Grafana's points on odd minutes,
    // `to` 0.3 s before the point 22260, the last point 22140 must count (22080, 22259.7]: 17, the render gives 12; and
    // its first point loses the scrape at 660 s as above
    'Asia/Kathmandu, 2-minute steps, the end 0.3 s before a point' => [20700, 120, 21600, 22259.7],
])->skip('T19 patch backlog item 5: the tiles take the period edges rounded to whole seconds, the tables the exact edges');

/**
 * promtool's output with every line cut to 100 characters (the expressions are long).
 */
function round4SpecOutput(string $output): string
{
    return implode("\n", array_map(static fn (string $line): string => substr($line, 0, 100), explode("\n", $output)));
}
