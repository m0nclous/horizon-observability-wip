<?php

declare(strict_types=1);

use DF\HorizonObservability\Grafana\PanelRegistry;
use DF\HorizonObservability\Tests\Grafana\Support\Promtool;
use DF\HorizonObservability\Tests\Grafana\Support\Rendered;

/*
 * T13 QA round 1 (adversarial), owner decision 13: the Completed (#8) and Failed (#4) tiles sum the attempts per
 * step over the dashboard range. A (connection, queue, job_class) triple is exported only after its first attempt
 * (AttemptsCollector zero-fills the statuses of known triples only), so its series is born with the attempts that
 * happened before the first scrape: a new job class after a deploy, or the burst of sample jobs of an onboarding demo
 * (owner decision 12) that finishes between two scrapes. The tiles must count those attempts; today the per-step
 * difference starts from the first exported value and shows 0.
 *
 * Grafana's range query (step = $__interval, reduced with sum) is emulated by a subquery at the same step.
 * Pre-existing in the pilot's queries. The tiles count them since WP8C O4 (the per-step attempts on the sum of the
 * series, clipped only at the first and last step); the tables and captions (Attempts::delta()) still start a born
 * series at its first value, which T16/T17 decide (exporter or queries).
 */

pest()->group('grafana');

beforeEach(function (): void {
    if (Promtool::command() === null) {
        $this->markTestSkipped('promtool is not available; set PROMTOOL or put promtool on the PATH.');
    }
});

it('counts the attempts a counter series is born with', function (): void {
    $class = static fn (string $class, string $status): string => sprintf(
        'ho_queue_jobs_total{job="app", instance="app:8080", connection="redis", queue="default", job_class="App\\\\Jobs\\\\%s", status="%s"}',
        $class,
        $status,
    );
    $tile = static function (int $panel): string {
        $expressions = Rendered::expressions();

        return 'sum_over_time(('.Promtool::interpolate($expressions['panel '.$panel.' A'], '.+', 19 * 60, 15 * 60).')[16m:1m])';
    };

    [$status, $output] = Promtool::testRules([], [[
        'interval' => '1m',
        'input_series' => [
            ['series' => 'ho_horizon_status{job="app", instance="app:8080"}', 'values' => '1x20'],
            // A known class without attempts in the range
            ['series' => $class('Old', 'completed'), 'values' => '1000x20'],
            ['series' => $class('Old', 'failed'), 'values' => '10x20'],
            // A new class: its first scrape at 10m holds the 5 completed and 2 failed attempts since 9m
            ['series' => $class('New', 'completed'), 'values' => '_x9 5x10'],
            ['series' => $class('New', 'failed'), 'values' => '_x9 2x10'],
        ],
        'promql_expr_test' => [
            ['expr' => $tile(PanelRegistry::idOf(Rendered::dashboard(), 'Completed')), 'eval_time' => '19m', 'exp_samples' => [['labels' => '{}', 'value' => 5]]],
            ['expr' => $tile(PanelRegistry::idOf(Rendered::dashboard(), 'Failed')), 'eval_time' => '19m', 'exp_samples' => [['labels' => '{}', 'value' => 2]]],
        ],
    ]]);

    expect($status)->toBe(0, $output);
});
