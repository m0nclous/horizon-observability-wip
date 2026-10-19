<?php

declare(strict_types=1);

use DF\HorizonObservability\Grafana\Promql;
use DF\HorizonObservability\Grafana\Queries\Attempts;
use DF\HorizonObservability\Metrics\Metric;
use DF\HorizonObservability\Tests\Grafana\Support\ProfileSeries;
use DF\HorizonObservability\Tests\Grafana\Support\Promtool;

/*
 * The attempts of a window (Attempts::delta(), the tables, captions and alerts' sibling): the counter difference from
 * the last value within LOOKBACK before the window, with the full-range fallback min_over_time(sel[$__range]) for a
 * series without one (kept over the whole range: a fallback bounded to the first LOOKBACK would count a series' whole
 * value after a Prometheus outage longer than LOOKBACK over the start), to the last value inside the window, and the
 * value after a reset where the difference is negative. The start is evaluated once (`(end - start) >= 0 or end`);
 * where every series has a value at the end of the window (the medium profile), it must equal the legacy
 * `(now - start) >= 0 or (now < start)`, which evaluated it twice. SeriesEndTest holds the series without one.
 */

pest()->group('grafana');

/**
 * The legacy form of Attempts::delta() per (queue, job_class, status).
 */
function legacyDelta(string $statuses, string $window): string
{
    $promql = new Promql('ho_');
    $status = [Promql::re('status', $statuses)];
    $sum = static fn (string $expr): string => Promql::sum($expr, 'queue', 'job_class', 'status');
    $now = $sum($promql->selector(Metric::JobsTotal, ...$status));
    $start = Promql::paren(Promql::either(
        $sum($promql->over('last_over_time', Metric::JobsTotal, '10m', $status, offset: $window)),
        $sum($promql->over('min_over_time', Metric::JobsTotal, $window, $status)),
    ));

    return '(('.$now.' - '.$start.') >= 0 or ('.$now.' < '.$start.'))';
}

function deltaOf(string $statuses, string $window): string
{
    return (new Attempts(new Promql('ho_'), '10m'))->delta($statuses, $window);
}

beforeEach(function (): void {
    if (Promtool::command() === null) {
        $this->markTestSkipped('promtool is not available; set PROMTOOL or put promtool on the PATH.');
    }
});

it('equals the legacy form on the medium profile', function (string $window): void {
    $now = ProfileSeries::interpolate(deltaOf(Attempts::COUNTED, $window), null, '.+');
    $legacy = ProfileSeries::interpolate(legacyDelta(Attempts::COUNTED, $window), null, '.+');

    [$status, $output] = Promtool::testRules([], [[
        'interval' => '1m',
        'input_series' => ProfileSeries::data()['series'],
        'promql_expr_test' => [
            ['expr' => ProfileSeries::difference($now, $legacy), 'eval_time' => '19m', 'exp_samples' => []],
            // 180 (queue, class) tuples with four statuses each
            ['expr' => 'count('.$now.')', 'eval_time' => '19m', 'exp_samples' => [['labels' => '{}', 'value' => 720]]],
        ],
    ]]);

    expect($status)->toBe(0, substr($output, 0, 3000));
})->with(['the dashboard range' => ['$__range'], 'the RPM window' => ['5m']]);

it('counts the value after a reset and starts after an outage over the start of the window', function (): void {
    $series = static fn (string $class): string => 'ho_queue_jobs_total{job="app", instance="app:8080", connection="redis", queue="default", job_class="App\\\\Jobs\\\\'.$class.'", status="completed"}';
    $window = ProfileSeries::interpolate(deltaOf(Attempts::COMPLETED, Promql::RANGE), null, '.+');
    $labels = static fn (string $class): string => '{job_class="App\\\\Jobs\\\\'.$class.'", queue="default", status="completed"}';

    [$status, $output] = Promtool::testRules([], [[
        'interval' => '1m',
        'input_series' => [
            // +2 a minute, reset to 3 at 20m (the store was flushed), +1 a minute after it
            ['series' => $series('Reset'), 'values' => '100+2x19 3+1x10'],
            // +1 a minute; Prometheus scraped nothing from 4m to 16m (an outage longer than LOOKBACK before the start
            // of the window at 14m), back at 17m with 517
            ['series' => $series('Outage'), 'values' => '500+1x3 _x13 517+1x13'],
        ],
        'promql_expr_test' => [[
            // The window (14m, 29m]
            'expr' => $window,
            'eval_time' => '29m',
            'exp_samples' => [
                // After the reset the value counts: 12 at 29m
                ['labels' => $labels('Reset'), 'value' => 12],
                // From the first value after the outage, 517, to 529: the attempts of the outage are lost, the
                // counter's whole value is not counted
                ['labels' => $labels('Outage'), 'value' => 12],
            ],
        ]],
    ]]);

    expect($status)->toBe(0, $output);
});
