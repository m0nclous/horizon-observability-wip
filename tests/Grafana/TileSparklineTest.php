<?php

declare(strict_types=1);

use DF\HorizonObservability\Grafana\PanelRegistry;
use DF\HorizonObservability\Grafana\Promql;
use DF\HorizonObservability\Grafana\Queries\Attempts;
use DF\HorizonObservability\Grafana\Queries\Selection;
use DF\HorizonObservability\Metrics\Metric;
use DF\HorizonObservability\Tests\Grafana\Support\ProfileSeries;
use DF\HorizonObservability\Tests\Grafana\Support\Promtool;
use DF\HorizonObservability\Tests\Grafana\Support\Rendered;

/*
 * ADDENDUM R2.4 O4 (as amended in T13 QA rounds 2 and 3): the Completed (#8) and Failed (#4) sparklines compute the
 * attempts per step per series, the value at the end of the step minus the one at its start (or the series' first
 * value inside the step), and sum them; the first and the last step are clipped to the period. Their points add up to
 * the tile number, so on continuous data (the medium perf profile here) they must equal the legacy form, the pilot's
 * attempts_per_step summed, at every step of a period, and so must the per-step graph #14 (Attempts::perStep(), per
 * series since QA round 3). A counter reset costs the attempts of its series in the one step it falls in, not the
 * period or the other series.
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
 * The legacy tile expression: the pilot's attempts_per_step per (queue, job_class, status), summed, as the WP8B render
 * wrote it (Attempts::perStep() before T13 QA round 3).
 */
function legacySparkline(string $statuses): string
{
    $promql = new Promql('ho_');
    $status = [Promql::re('status', $statuses)];
    $labels = ['queue', 'job_class', 'status'];
    $sum = static fn (string $expr): string => Promql::sum($expr, ...$labels);
    $stepEnd = Promql::paren(Promql::either(
        $sum($promql->over('last_over_time', Metric::JobsTotal, Promql::INTERVAL, $status, offset: '-'.Promql::INTERVAL)),
        $sum($promql->shifted(Metric::JobsTotal, $status, offset: '-'.Promql::INTERVAL)),
    ));
    $stepStart = Promql::paren(Promql::either(
        $sum($promql->over('last_over_time', Metric::JobsTotal, Promql::INTERVAL, $status)),
        $sum($promql->selector(Metric::JobsTotal, ...$status)),
    ));
    $stepFirst = $sum($promql->over('min_over_time', Metric::JobsTotal, Promql::INTERVAL, $status, offset: '-'.Promql::INTERVAL));
    $periodEnd = $sum($promql->shifted(Metric::JobsTotal, $status, at: Promql::TO_SECONDS));
    $periodStart = $sum($promql->over('last_over_time', Metric::JobsTotal, '10m', $status, at: Promql::FROM_SECONDS));
    $later = '(('.$stepEnd.' <= '.$periodEnd.') or ('.Promql::andOn($periodEnd, $stepEnd, ...$labels).') or ('.Promql::unlessOn($stepEnd, $periodEnd, ...$labels).'))';
    $since = '(('.$stepStart.' >= '.$periodStart.') or ('.Promql::andOn($periodStart, $stepStart, ...$labels).') or ('
        .Promql::unlessOn($stepStart, $periodStart, ...$labels).') or ('.Promql::unlessOn($stepFirst, $stepStart, ...$labels).'))';
    $perStep = '(('.$later.' - '.$since.') >= 0 or ('.$later.' < '.$since.'))';

    return $promql->filtered(Promql::sum($perStep).' or '.(new Selection($promql))->zeroIfMetrics(), true);
}

/**
 * The graph #14's Total line as a sum of attempts per step: Attempts::perStep() summed.
 */
function perStepSparkline(string $statuses): string
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
    $graph = sparklineInterpolate(perStepSparkline($statuses));
    $tests = [];

    foreach (range(4, 19) as $minute) {
        $tests[] = ['expr' => '# step '.$minute."\n".ProfileSeries::difference($now, $legacy), 'eval_time' => $minute.'m', 'exp_samples' => []];
        $tests[] = ['expr' => '# graph step '.$minute."\n".ProfileSeries::difference($graph, $legacy), 'eval_time' => $minute.'m', 'exp_samples' => []];
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

it('loses only the reset series in the step of a counter reset, not the period', function (): void {
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
            // The tile number: its points summed. In (4m, 19m] A had 5 + 1 + 9 attempts and B 30; A's attempt in the
            // step from 9m to 10m is lost (T16 recreates a reset series at 0 first, so it is not lost in practice)
            ['expr' => 'sum_over_time(('.$tile.')[16m:1m])', 'eval_time' => '19m', 'exp_samples' => [['labels' => '{}', 'value' => 45 - 1]]],
            ['expr' => '('.$tile.')', 'eval_time' => '9m', 'exp_samples' => [['labels' => '{}', 'value' => 2]]],
            ['expr' => '('.$tile.')', 'eval_time' => '10m', 'exp_samples' => [['labels' => '{}', 'value' => 3]]],
        ],
    ]]);

    expect($status)->toBe(0, $output);
});

it('adds up to the period when the browser UTC offset shifts the graph steps off the subquery points', function (): void {
    // Grafana aligns its steps to the dashboard time zone: at a 1-minute step and a UTC offset of 30 seconds the points
    // are at hh:mm:30, while the subquery points of the tile are whole minutes. Each point is evaluated as the range
    // query evaluates it: an instant query at its time, end() being the last point.
    $series = 'ho_queue_jobs_total{job="app", instance="app:8080", connection="redis", queue="default", job_class="App\\\\Jobs\\\\A", status="completed"}';
    $from = 4 * 60 + 15;
    $to = 19 * 60;
    $expr = Rendered::expressions()['panel '.PanelRegistry::COMPLETED.' A'];

    // promtool loads the input series up to the last evaluation time only: the last point reads the end of the period
    $tests = [['expr' => 'vector(1)', 'eval_time' => '20m', 'exp_samples' => [['labels' => '{}', 'value' => 1]]]];

    // The first point (3m30s) only covers time before the period; the others each count the attempt of one minute
    foreach (range(3 * 60 + 30, 18 * 60 + 30, 60) as $point) {
        $tests[] = [
            'expr' => Promtool::interpolate(str_replace('end()', (string) $point, $expr), '.+', $to, $to - $from),
            'eval_time' => $point.'s',
            'exp_samples' => [['labels' => '{}', 'value' => $point < $from ? 0 : 1]],
        ];
    }

    [$status, $output] = Promtool::testRules([], [[
        'interval' => '1m',
        'input_series' => [
            ['series' => 'ho_horizon_status{job="app", instance="app:8080"}', 'values' => '1x20'],
            // +1 a minute: 15 attempts after 4m15s up to 19m, one in each of the 15 points from 4m30s
            ['series' => $series, 'values' => '0+1x20'],
        ],
        'promql_expr_test' => $tests,
    ]]);

    expect(count($tests))->toBe(17)
        ->and($status)->toBe(0, $output);
});
