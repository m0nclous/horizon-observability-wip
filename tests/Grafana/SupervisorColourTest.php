<?php

declare(strict_types=1);

use DF\HorizonObservability\Grafana\Live\LiveMode;
use DF\HorizonObservability\Grafana\PanelRegistry;
use DF\HorizonObservability\Tests\Grafana\Support\Promtool;
use DF\HorizonObservability\Tests\Grafana\Support\Rendered;

/*
 * The Supervisor Processes tile (#3) colours its number, the last point of the range query A, by the value mapping
 * query B lists. B is an instant query now, and Grafana runs it at the end of the period, while the last point of A
 * sits on the step grid at or before the end. B reads its value through a subquery with the step $__interval, which
 * Prometheus aligns to the same grid, so the mapping always has the shown value, also when a scrape changed it after
 * the last step. The --live=off render: in the live modes the tile reads its value and colour from the live snapshot.
 */

pest()->group('grafana');

beforeEach(function (): void {
    if (Promtool::command() === null) {
        $this->markTestSkipped('promtool is not available; set PROMTOOL or put promtool on the PATH.');
    }
});

it('maps the value of the last step of the tile, not the value at the end of the period', function (): void {
    $dashboard = Rendered::dashboard(LiveMode::Off);
    $id = PanelRegistry::idOf($dashboard, 'Supervisor Processes');
    $target = $dashboard['panels'][array_search($id, array_column($dashboard['panels'], 'id'), true)]['targets'][1];
    $labels = 'job="app", instance="app:8080"';

    [$status, $output] = Promtool::testRules([], [[
        // Scrapes every 30 s; the tile's steps are 1 minute apart
        'interval' => '30s',
        'input_series' => [
            ['series' => 'ho_horizon_status{'.$labels.'}', 'values' => '1x40'],
            // One more process at every scrape: 38 at 19m, 39 at 19m30s
            ['series' => 'ho_horizon_supervisor_processes{'.$labels.', supervisor="supervisor-a", queue="default"}', 'values' => '0+1x40'],
            ['series' => 'ho_horizon_supervisor_max_processes{'.$labels.', supervisor="supervisor-a"}', 'values' => '100x40'],
        ],
        'promql_expr_test' => [[
            // The end of the period is 19m30s; the last step of A is 19m
            'expr' => Promtool::interpolate($target['expr'], '.+', 19 * 60 + 30, 15 * 60),
            'eval_time' => '19m30s',
            'exp_samples' => [['labels' => '{color="green", value="38"}', 'value' => 1]],
        ]],
    ]]);

    expect($target['instant'])->toBeTrue()
        ->and($target['format'])->toBe('table')
        ->and($status)->toBe(0, $output);
});
