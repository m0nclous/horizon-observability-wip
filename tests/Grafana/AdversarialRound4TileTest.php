<?php

declare(strict_types=1);

use DF\HorizonObservability\Grafana\PanelRegistry;
use DF\HorizonObservability\Tests\Grafana\Support\Promtool;
use DF\HorizonObservability\Tests\Grafana\Support\Rendered;

/*
 * The last point of the Completed and Failed tiles (`end()`, the one whose step reaches the period end) ends at the
 * series' values at the period end, so the points add up to the period count. The last point is chosen with
 * `vector(time()) + STEP > <period end>`, while the range query ends at the step-aligned point at or before the exact
 * `to` in milliseconds. With the rounded end `${__to:date:seconds}` (Grafana's frontend formats it as
 * Math.round(to / 1000), 12.4.13 and 13.2.4 alike), when `to` lay less than half a second before one of Grafana's
 * points, the rounded end was that point: no point satisfied the test, the last point counted its inner step
 * (PREV, PREV + step], and where the browser's UTC offset shifts Grafana's points off the subquery points (multiples of
 * the step since 1970) the attempts between PREV + step and the period end were lost (#31). The render
 * now takes the exact edges, `${__to}e-3` (milliseconds).
 *
 * Asia/Kathmandu (UTC+5:45, utcOffsetSec 20700) with the Failed tile's 2-minute step over 6 hours (Grafana's step at
 * 1920 px): Grafana's points are on odd minutes, the subquery points on even minutes, so the last step lost one minute.
 * Through Grafana's /api/ds/query (12.4.13 and 13.2.4) on a 10-second-scrape data set: Failed tile 13,196, the queue
 * tables (#24 C) and the raw samples 13,283; with `to` 0.6 s before the point (rounded down) all three were 13,283.
 * The TileStepTest periods end on whole seconds, where this does not happen.
 *
 * Here: one class failing once a scrape (every 10 s), samples up to 22250 s, the last scrape before now; the period
 * ends now at 22259.7 s, 0.3 s before Grafana's point 22260 (an odd minute), and is 6 hours long. Grafana's points are
 * 660 - 120 = 540 ... 22140 (aligned with the offset), `end()` is 22140, `${__from}` 659700 and `${__to}` 22259700
 * (`${__from:date:seconds}` would be 660 and `${__to:date:seconds}` 22260).
 */

pest()->group('grafana');

beforeEach(function (): void {
    if (Promtool::command() === null) {
        $this->markTestSkipped('promtool is not available; set PROMTOOL or put promtool on the PATH.');
    }
});

/**
 * Panel $id $refId as Grafana sends it for the period ending at 22259.7 s, 6 hours, at 2-minute steps, every filter All.
 */
function round4TileExpr(int $id, string $refId): string
{
    $expr = strtr(Rendered::expressions()['panel '.$id.' '.$refId], [
        '${__interval_ms}' => '120000',
        '$__interval_ms' => '120000',
        '$__interval' => '2m',
    ]);

    // The period edges as Grafana sends them (milliseconds); the range query's last point is end()
    return str_replace('end()', '22140', Promtool::interpolate($expr, '.+', 22259.7, 21600));
}

it('counts the last step up to the period end when the end rounds up onto a point the UTC offset shifts', function (): void {
    $failed = 'label_replace(vector(1), "status", "failed", "", "")';

    [$status, $output] = Promtool::testRules([], [[
        'interval' => '10s',
        'input_series' => [
            ['series' => 'ho_horizon_status{job="app", instance="app:8080"}', 'values' => '1x2225'],
            ['series' => 'ho_queue_pending_jobs{job="app", instance="app:8080", connection="redis", queue="default"}', 'values' => '0x2225'],
            ['series' => 'ho_queue_jobs_total{job="app", instance="app:8080", connection="redis", queue="default", job_class="App\\\\Jobs\\\\Import", status="failed"}', 'values' => '0+1x2225'],
        ],
        'promql_expr_test' => [
            // promtool loads the samples up to the last evaluation time
            ['expr' => 'vector(1)', 'eval_time' => '22260s', 'exp_samples' => [['labels' => '{}', 'value' => 1]]],
            // The table at the exact end (Grafana's instant query time): 2225 - 65 = 2160 failed in (659.7 s, 22259.7 s]
            ['expr' => 'sum(('.round4TileExpr(PanelRegistry::QUEUE_SOURCE, 'C').') and on (status) '.$failed.')', 'eval_time' => '6h10m59s700ms', 'exp_samples' => [['labels' => '{}', 'value' => 2160]]],
            // An inner point: 22020 counts its step (21960, 22080], 12 failures
            ['expr' => round4TileExpr(PanelRegistry::FAILED, 'A'), 'eval_time' => '22020s', 'exp_samples' => [['labels' => '{}', 'value' => 12]]],
            // The last point: 22140 counts (22080, 22259.7], 2225 - 2208 = 17 failures (with the rounded end it counted
            // (22080, 22200], 12)
            ['expr' => round4TileExpr(PanelRegistry::FAILED, 'A'), 'eval_time' => '22140s', 'exp_samples' => [['labels' => '{}', 'value' => 17]]],
        ],
    ]]);

    expect($status)->toBe(0, implode("\n", array_map(
        static fn (string $line): string => preg_match('/^\s*expr: /', $line) === 1 ? substr($line, 0, 70).'...' : $line,
        explode("\n", $output),
    )));
});
