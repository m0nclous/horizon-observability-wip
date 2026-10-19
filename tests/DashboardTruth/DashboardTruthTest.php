<?php

declare(strict_types=1);

use DF\HorizonObservability\Tests\DashboardTruth\Compare\Assertion;
use DF\HorizonObservability\Tests\DashboardTruth\Compare\AssertStage;
use DF\HorizonObservability\Tests\DashboardTruth\Compare\Report;

/*
| The assert stage of the dashboard truth suite (T17, design sections 3, 8 and 11.2): one test per case and Grafana
| version of the run's plan, then the runtime gate. Run by tests/DashboardTruth/bin/run with DASHBOARD_TRUTH_STACK=1
| and DASHBOARD_TRUTH_RUN_DIR, after the capture stage, while the stack still runs (the replays post to the
| Grafanas). Without DASHBOARD_TRUTH_STACK the tests are skipped.
|
| Each test compares every target spec of the case's render on Grafana's grid and on both replays, the DOM, and the
| checks no spec owns; the failure message is the report of its failures (case, version, refId, expected and actual
| values, executedQueryString, replay command). A negative control (CaseDef::$expect) must fail, and only with its
| category. The full report is <run dir>/report.{md,json}.
*/

pest()->group('dashboard-truth');

it('shows the truth', function (string $case, string $grafana): void {
    $stage = AssertStage::get();
    $failures = $stage->check($case, $grafana);
    $negative = $stage->negative($case);

    if ($negative !== null) {
        // A negative control: it must fail, and only with its category
        $other = array_filter($failures, static fn (Assertion $failure): bool => $failure->category?->value !== $negative);

        expect($failures)->not->toBeEmpty(sprintf('the negative control %s did not fail as %s', $case, $negative))
            ->and(count($other))->toBe(0, "\n".Report::describe(array_values($other))."\n");

        return;
    }

    expect(count($failures))->toBe(0, "\n".Report::describe($failures)."\n");
})->with(AssertStage::tests())->skip(! AssertStage::enabled(), 'the assert stage runs from tests/DashboardTruth/bin/run (DASHBOARD_TRUTH_STACK=1)');

it('made every assertion the specs promise (runtime gate)', function (): void {
    $missing = AssertStage::get()->gate();

    expect(count($missing))->toBe(0, "\nThe runtime gate:\n- ".implode("\n- ", $missing)."\n");
})->skip(! AssertStage::enabled(), 'the assert stage runs from tests/DashboardTruth/bin/run (DASHBOARD_TRUTH_STACK=1)');
