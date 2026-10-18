<?php

declare(strict_types=1);

use DF\HorizonObservability\Grafana\Queries\Waits;
use DF\HorizonObservability\Grafana\Thresholds;
use DF\HorizonObservability\Tests\Grafana\Support\Promtool;

/*
 * The wait level of the Pending tile, Oldest wait and Wait p95 (T13 QA round 2): Waits::levels() evaluates its ratio
 * once as clamp(ceil(ratio) - 1, 0, 2), the level of the comparisons with the warning (1) and critical (2) factors,
 * (ratio > bool 1) + (ratio > bool 2), which evaluated it twice. Held to the comparisons at and around the factors.
 */

pest()->group('grafana');

it('is the level of the warning and critical wait factors', function (): void {
    if (Promtool::command() === null) {
        $this->markTestSkipped('promtool is not available; set PROMTOOL or put promtool on the PATH.');
    }

    $tests = [];

    foreach (['0', '0.25', '0.999', '1', '1.0001', '1.5', '2', '2.0001', '2.5', '3', '3.5', '100', '+Inf'] as $ratio) {
        $comparisons = '(vector('.$ratio.') > bool '.Thresholds::WAIT_WARNING_FACTOR.') + (vector('.$ratio.') > bool '.Thresholds::WAIT_CRITICAL_FACTOR.')';
        $tests[] = ['expr' => Waits::levels('vector('.$ratio.')').' != '.$comparisons, 'eval_time' => '1m', 'exp_samples' => []];
    }

    [$status, $output] = Promtool::testRules([], [['interval' => '1m', 'input_series' => [], 'promql_expr_test' => $tests]]);

    expect([Thresholds::WAIT_WARNING_FACTOR, Thresholds::WAIT_CRITICAL_FACTOR])->toBe([1, 2], 'Waits::levels() assumes the factors 1 and 2')
        ->and($status)->toBe(0, $output);
});
