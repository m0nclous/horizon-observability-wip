<?php

declare(strict_types=1);

use DF\HorizonObservability\Grafana\PanelRegistry;
use DF\HorizonObservability\Grafana\Promql;
use DF\HorizonObservability\Grafana\Queries\Attempts;
use DF\HorizonObservability\Grafana\Queries\Selection;
use DF\HorizonObservability\Tests\Grafana\Support\ProfileSeries;
use DF\HorizonObservability\Tests\Grafana\Support\Promtool;
use DF\HorizonObservability\Tests\Grafana\Support\Rendered;

/*
 * ADDENDUM R2.4 O4: the Completed (#8) and Failed (#4) sparklines compute the attempts per step on the sum of all
 * selected series instead of per (queue, class, status). Their points add up to the tile number, so they must equal
 * the legacy series-level form (Attempts::perStep() summed) at every step of a period on continuous data, the
 * medium perf profile here. A counter reset costs the one step it falls in, not the whole period.
 *
 * Grafana's range query is emulated by one promtool evaluation per step: 15-minute range ending at 19m, 1m steps.
 */

pest()->group('grafana');

/**
 * An expression at step $at of a range from 4m to 19m with 1-minute steps, every filter at "All".
 */
function sparklineInterpolate(string $expr): string
{
    return Promtool::interpolate($expr, '.+', 19 * 60, 15 * 60);
}

/**
 * The legacy tile expression: Attempts::perStep() summed, as the WP8B render wrote it.
 */
function legacySparkline(string $statuses): string
{
    $promql = new Promql('ho_');

    return $promql->filtered(Promql::sum((new Attempts($promql, '10m'))->perStep($statuses)).' or '.(new Selection($promql))->zeroIfMetrics(), true);
}

beforeEach(function (): void {
    if (Promtool::command() === null) {
        $this->markTestSkipped('promtool is not available; set PROMTOOL or put promtool on the PATH.');
    }
});

it('gives the legacy value at every step of the period (O4)', function (string $title, string $statuses): void {
    $now = sparklineInterpolate(Rendered::expressions()['panel '.PanelRegistry::idOf(Rendered::dashboard(), $title).' A']);
    $legacy = sparklineInterpolate(legacySparkline($statuses));
    $tests = [];

    foreach (range(4, 19) as $minute) {
        $tests[] = ['expr' => '# step '.$minute."\n".ProfileSeries::difference($now, $legacy), 'eval_time' => $minute.'m', 'exp_samples' => []];
        // Not trivially equal: the steps have attempts
        $tests[] = ['expr' => 'count('.$now.' > 0)', 'eval_time' => $minute.'m', 'exp_samples' => [['labels' => '{}', 'value' => 1]]];
    }

    [$status, $output] = Promtool::testRules([], [[
        'interval' => '1m',
        'input_series' => ProfileSeries::data()['series'],
        'promql_expr_test' => $tests,
    ]]);

    expect(PanelRegistry::idOf(Rendered::dashboard(), $title))->toBeIn([PanelRegistry::COMPLETED, PanelRegistry::FAILED])
        ->and($status)->toBe(0, substr($output, 0, 3000));
})->with([
    'Completed' => ['Completed', Attempts::COMPLETED],
    'Failed' => ['Failed', Attempts::FAILED],
]);

it('loses only the step of a counter reset, not the period', function (): void {
    $series = static fn (string $class): string => 'ho_queue_jobs_total{job="app", instance="app:8080", connection="redis", queue="default", job_class="App\\\\Jobs\\\\'.$class.'", status="completed"}';
    $tile = sparklineInterpolate(Rendered::expressions()['panel '.PanelRegistry::COMPLETED.' A']);

    [$status, $output] = Promtool::testRules([], [[
        'interval' => '1m',
        'input_series' => [
            ['series' => 'ho_horizon_status{job="app", instance="app:8080"}', 'values' => '1x20'],
            // +1 a minute, reset at 10m (the store was flushed) and +1 a minute again
            ['series' => $series('A'), 'values' => '100+1x9 1+1x10'],
            // +2 a minute throughout
            ['series' => $series('B'), 'values' => '0+2x20'],
        ],
        'promql_expr_test' => [
            // The tile number: its points summed. In (4m, 19m] A had 5 + 1 + 9 attempts and B 30; the step from 9m
            // to 10m (A's 1 and B's 2) is lost
            ['expr' => 'sum_over_time(('.$tile.')[16m:1m])', 'eval_time' => '19m', 'exp_samples' => [['labels' => '{}', 'value' => 45 - 3]]],
            ['expr' => '('.$tile.')', 'eval_time' => '9m', 'exp_samples' => [['labels' => '{}', 'value' => 0]]],
            ['expr' => '('.$tile.')', 'eval_time' => '10m', 'exp_samples' => [['labels' => '{}', 'value' => 3]]],
        ],
    ]]);

    expect($status)->toBe(0, $output);
});
