<?php

declare(strict_types=1);

use DF\HorizonObservability\Grafana\PanelRegistry;
use DF\HorizonObservability\Grafana\Promql;
use DF\HorizonObservability\Grafana\Queries\Attempts;
use DF\HorizonObservability\Tests\Grafana\Support\Promtool;
use DF\HorizonObservability\Tests\Grafana\Support\Rendered;

/*
 * The counter-birth rule: Prometheus cannot count the first sample of a series, and the dashboard must not count it
 * either, because the first value of a series that appears inside the range may be a whole history (the scrape job or
 * replica of an application added later, AdversarialRound2TileTest). The exporter makes new series count instead: it
 * exports a new (connection, queue, class) as 0 for its first 90 seconds (the new-series hold, README
 * "Dashboard numbers"), so its first attempts are an increase from 0.
 *
 * The Completed (#8) and Failed (#4) tiles and the tables (Attempts::delta(), #5 and #6) follow the same rule: a series
 * born at 0 counts every attempt after its birth, a series born with a value counts from that value, and the tile
 * equals the sum of the table values. Grafana's range query (step = $__interval, reduced with sum) is emulated by a
 * subquery at the same step.
 */

pest()->group('grafana');

beforeEach(function (): void {
    if (Promtool::command() === null) {
        $this->markTestSkipped('promtool is not available; set PROMTOOL or put promtool on the PATH.');
    }
});

/**
 * A tile number over the 15-minute range ending at 19m.
 */
function birthTile(string $title): string
{
    $expr = Rendered::expressions()['panel '.PanelRegistry::idOf(Rendered::dashboard(), $title).' A'];

    return 'sum_over_time(('.Promtool::interpolate($expr, '.+', 19 * 60, 15 * 60).')[16m:1m])';
}

/**
 * The attempts of the range as the tables count them: Attempts::delta() over 15 minutes at 19m, summed.
 */
function birthTable(string $statuses): string
{
    $promql = new Promql('ho_');

    return Promtool::interpolate($promql->filtered(Promql::sum((new Attempts($promql, '10m'))->delta($statuses, Promql::RANGE)), true), '.+', 19 * 60, 15 * 60);
}

it('counts the attempts of a series exported as 0 first, like the tables', function (): void {
    $class = static fn (string $class, string $status): string => sprintf(
        'ho_queue_jobs_total{job="app", instance="app:8080", connection="redis", queue="default", job_class="App\\\\Jobs\\\\%s", status="%s"}',
        $class,
        $status,
    );

    [$status, $output] = Promtool::testRules([], [[
        'interval' => '1m',
        'input_series' => [
            ['series' => 'ho_horizon_status{job="app", instance="app:8080"}', 'values' => '1x20'],
            // A known class without attempts in the range
            ['series' => $class('Old', 'completed'), 'values' => '1000x20'],
            ['series' => $class('Old', 'failed'), 'values' => '10x20'],
            // A new class at 9m, held at 0 by the exporter, then 5 completed and 2 failed attempts
            ['series' => $class('New', 'completed'), 'values' => '_x8 0 0 5x10'],
            ['series' => $class('New', 'failed'), 'values' => '_x8 0 0 2x10'],
            // A series born with its value (no hold): its first value is not counted, its later attempts are
            ['series' => $class('Late', 'completed'), 'values' => '_x11 70 71 72 73x6'],
        ],
        'promql_expr_test' => [
            ['expr' => birthTile('Completed'), 'eval_time' => '19m', 'exp_samples' => [['labels' => '{}', 'value' => 5 + 3]]],
            ['expr' => birthTile('Failed'), 'eval_time' => '19m', 'exp_samples' => [['labels' => '{}', 'value' => 2]]],
            ['expr' => birthTable(Attempts::COMPLETED), 'eval_time' => '19m', 'exp_samples' => [['labels' => '{}', 'value' => 5 + 3]]],
            ['expr' => birthTable(Attempts::FAILED), 'eval_time' => '19m', 'exp_samples' => [['labels' => '{}', 'value' => 2]]],
        ],
    ]]);

    expect($status)->toBe(0, $output);
});
