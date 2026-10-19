<?php

declare(strict_types=1);

use DF\HorizonObservability\Grafana\AlertRulesBuilder;
use DF\HorizonObservability\Grafana\DashboardBuilder;
use DF\HorizonObservability\Grafana\DashboardOptions;
use DF\HorizonObservability\Tests\Grafana\Support\Promtool;
use DF\HorizonObservability\Tests\Grafana\Support\Rendered;
use Workbench\App\Perf\SyntheticModel;

/*
 * The default render scopes every package selector to the hidden prometheus_job variable with All (`.+`) selected, so
 * one render serves every application a Prometheus scrapes. Two applications (two scrape jobs, e.g. staging and
 * production of one code base) share supervisor, queue and batch names. Every expression of the dashboard must still
 * evaluate with All selected: an evaluation error empties the variable or the panel (here:
 * `found duplicate series for the match group` on the right-hand side of a one-to-one or group_left match that keeps
 * `job`).
 *
 * The input is the perf profile (Workbench\App\Perf\SyntheticModel, medium) for two jobs, 20 minutes at 1 minute.
 * Each expression is wrapped as `count(<expr>) * 0 or vector(0)`, which is `{} 0` unless the evaluation fails.
 */

pest()->group('grafana');

/**
 * @return list<array{series: string, values: string}>
 */
function twoJobSeries(int $points): array
{
    $model = new SyntheticModel('medium');
    $start = 1_790_000_000;
    $values = [];

    foreach (['app-a', 'app-b'] as $job) {
        $extra = 'job="'.$job.'",instance="app:8080"';

        for ($i = 0; $i < $points; $i++) {
            $t = $start + 60 * $i;

            foreach (explode("\n", $model->samples($t, $extra).$model->horizonInfo($t, $extra)) as $line) {
                if ($line === '' || $line[0] === '#') {
                    continue;
                }

                // name{labels} value timestamp
                $value = explode(' ', substr($line, strrpos($line, '} ') + 2))[0];
                $values[substr($line, 0, strrpos($line, '} ') + 1)][] = $value;
            }
        }
    }

    $series = [];

    foreach ($values as $selector => $list) {
        $series[] = ['series' => $selector, 'values' => implode(' ', $list)];
    }

    return $series;
}

beforeEach(function (): void {
    if (Promtool::command() === null) {
        $this->markTestSkipped('promtool is not available; set PROMTOOL or put promtool on the PATH.');
    }
});

it('evaluates every expression with All jobs when two applications share supervisor, queue and batch names', function (): void {
    $options = new DashboardOptions(healthRules: true);
    $expressions = Rendered::expressions((new DashboardBuilder($options))->build(), (new AlertRulesBuilder($options))->build());
    $tests = [];

    foreach ($expressions as $where => $expr) {
        if (str_starts_with($expr, 'label_values(')) {
            continue;
        }

        $query = str_starts_with($expr, 'query_result(') ? substr($expr, strlen('query_result('), -1) : $expr;
        $tests[] = [
            'expr' => '# '.$where."\ncount(".Promtool::interpolate($query, '.+', 19 * 60, 15 * 60).') * 0 or vector(0)',
            'eval_time' => '19m',
            'exp_samples' => [['labels' => '{}', 'value' => 0]],
        ];
    }

    [$status, $output] = Promtool::testRules([], [[
        'interval' => '1m',
        'input_series' => twoJobSeries(20),
        'promql_expr_test' => $tests,
    ]]);

    // The panels and variables that fail, by the comment line each test expression starts with
    preg_match_all('/# ((?:panel|variable|rule) [^\\\\"]+?)\\\\n/', $output, $failing);

    // 69 since the attempts columns of the queue and job tables come from one query each
    expect(count($tests))->toBeGreaterThan(60)
        ->and($failing[1])->toBe([])
        ->and($status)->toBe(0, substr($output, 0, 4000));
});
