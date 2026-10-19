<?php

declare(strict_types=1);

use DF\HorizonObservability\Grafana\PanelRegistry;
use DF\HorizonObservability\Tests\Grafana\Support\Promtool;
use DF\HorizonObservability\Tests\Grafana\Support\Rendered;

/*
 * T13 QA round 2 (adversarial), owner decision 13. WP8C O4 computes the Completed (#8) and Failed (#4) tiles as the
 * increase of the sum of all selected series per step, clipped only at the first and the last step (a deviation from
 * the ADDENDUM R2.4 O4 expression). A series that appears inside the range then adds its whole value to the step it
 * appears in. One Grafana serves several applications (prometheus_job "All" by default): when the scrape job of an
 * application with a history is added, every attempt since its install lands in one step of the tiles, while the
 * legacy per-series form (Attempts::perStep(), the WP8B render) and Attempts::delta() (the tables and the Success
 * caption) count none of them. In the dev stack the same mechanism already makes the tiles disagree with the tables:
 * a class whose series is born with 258 failures adds 258 to the Failed tile and nothing to its table row.
 *
 * Grafana's range query (step = $__interval, reduced with sum) is emulated by a subquery at the same step, as in
 * CounterBirthTest. T16 exports a new counter series as 0 first (a 75 s hold), so new classes count without
 * counting first values; the late scrape job is the case the hold cannot cover.
 */

pest()->group('grafana');

beforeEach(function (): void {
    if (Promtool::command() === null) {
        $this->markTestSkipped('promtool is not available; set PROMTOOL or put promtool on the PATH.');
    }
});

/**
 * The tile's number: the sum of its per-step series over a 15-minute range ending at 19m, every filter at All.
 */
function lateJobTile(string $title): string
{
    $expr = Rendered::expressions()['panel '.PanelRegistry::idOf(Rendered::dashboard(), $title).' A'];

    return 'sum_over_time(('.Promtool::interpolate($expr, '.+', 19 * 60, 15 * 60).')[16m:1m])';
}

it('does not count the history of an application whose scrape job is added during the range', function (): void {
    $series = static fn (string $job, string $class, string $status): string => sprintf(
        'ho_queue_jobs_total{job="%s", instance="%s:8080", connection="redis", queue="default", job_class="App\\\\Jobs\\\\%s", status="%s"}',
        $job,
        $job,
        $class,
        $status,
    );

    // Application b ran for months before its scrape job was added at 10m. In the range both complete 1 job per
    // minute: a from 4m to 19m (15 attempts), b from 10m to 19m (9). Nothing fails in the range.
    [$status, $output] = Promtool::testRules([], [[
        'interval' => '1m',
        'input_series' => [
            ['series' => 'ho_horizon_status{job="a", instance="a:8080"}', 'values' => '1x20'],
            ['series' => 'ho_horizon_status{job="b", instance="b:8080"}', 'values' => '_x10 1x10'],
            ['series' => $series('a', 'Mail', 'completed'), 'values' => '500+1x20'],
            ['series' => $series('a', 'Mail', 'failed'), 'values' => '7x20'],
            ['series' => $series('b', 'Import', 'completed'), 'values' => '_x10 100000+1x9'],
            ['series' => $series('b', 'Import', 'failed'), 'values' => '_x10 2000x9'],
        ],
        'promql_expr_test' => [
            // WP8C O4 counts 100,024 and 2,000
            ['expr' => lateJobTile('Completed'), 'eval_time' => '19m', 'exp_samples' => [['labels' => '{}', 'value' => 24]]],
            ['expr' => lateJobTile('Failed'), 'eval_time' => '19m', 'exp_samples' => [['labels' => '{}', 'value' => 0]]],
        ],
    ]]);

    expect($status)->toBe(0, $output);
});
