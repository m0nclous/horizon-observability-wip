<?php

declare(strict_types=1);

use DF\HorizonObservability\Grafana\Live\LiveMode;
use DF\HorizonObservability\Tests\Grafana\Support\Promtool;
use DF\HorizonObservability\Tests\Grafana\Support\Rendered;

/*
 * T13 QA round 3 (adversarial), owner decision 13 (filters) and round 2 finding 3. The job_class label is the payload
 * displayName (Attempts\JobClassLabel), which Laravel sets to the class name: App\Jobs\SendEmail for every job without
 * its own displayName() (the workbench jobs all define one, so the dev stack and the e2e tests never see a backslash).
 *
 * "All jobs selected" (Queries\Selection) compares `"${job_class:text}"` with "All" inside a PromQL string literal.
 * Grafana's `text` format is the raw text, so a selected App\Jobs\SendEmail gives `"App\Jobs\SendEmail"` and Prometheus
 * rejects the query: `parse error: unknown escape sequence U+004A 'J'`. `$job_class` itself is fine (Grafana
 * regex-escapes it). In the dev stack (Grafana 13.2.4 and 12.4.13), var-job_class=App\Jobs\TestPingJob made 8 queries of
 * the committed poll render fail: Pending A, Delayed A, the queue source B, D, F, G, K (the Pending, Processes, Oldest
 * wait, Delayed and Wait p95 columns of every queue table) and the Completed caption B that round 2 added for a Job
 * filter, so the poll and stream Completed caption still reads "RPM: —" under a Job filter of a real class while
 * --live=off shows its RPM. The --live=off render fails 11 (also Pending B and C, Supervisor Processes A and B); that
 * part is as old as the pilot's filter_is_all().
 *
 * The probe interpolates every expression of the poll and off renders as Grafana sends it with Job = App\Jobs\SendEmail
 * and every other filter All, and parses them with promtool.
 */

pest()->group('grafana');

beforeEach(function (): void {
    if (Promtool::command() === null) {
        $this->markTestSkipped('promtool is not available; set PROMTOOL or put promtool on the PATH.');
    }
});

/**
 * An expression as Grafana's Prometheus datasource sends it with one selected job class $class: `${job_class:text}` is
 * the raw text, `$job_class` regex-escaped (prometheusSpecialRegexEscape: a backslash becomes four in the PromQL text),
 * every other filter All.
 */
function round3WithClass(string $expr, string $class): string
{
    $regex = str_replace('\\', '\\\\\\\\', $class);
    $expr = strtr($expr, [
        '${job_class:text}' => $class,
        '${job_class}' => $regex,
        '$job_class' => $regex,
        '${__from:date:seconds}' => '0',
        '${__to:date:seconds}' => '3600',
        '$__interval_ms' => '60000',
        '${__interval_ms}' => '60000',
        '$__interval' => '1m',
        '$__range' => '1h',
        '$busy_window' => '20s',
        '$scrape_interval' => '10s',
    ]);
    $expr = (string) preg_replace('/\$\{[A-Za-z_]\w*:text\}/', 'All', $expr);

    return (string) preg_replace('/\$\{[A-Za-z_]\w*(?::\w+)?\}|\$[A-Za-z_]\w*/', '.*', $expr);
}

it('parses every query of the poll and off renders with a Job filter on a namespaced class', function (LiveMode $mode): void {
    $rules = [];

    foreach (Rendered::expressions(mode: $mode) as $where => $expr) {
        if (str_starts_with($where, 'panel ') && str_contains($expr, 'job_class')) {
            $rules[] = ['record' => 'adversarial:'.preg_replace('/[^A-Za-z0-9_]+/', '_', $where), 'expr' => round3WithClass($expr, 'App\\Jobs\\SendEmail')];
        }
    }

    [$status, $output] = Promtool::testRules([['name' => 'job-filter', 'rules' => $rules]], [[
        'interval' => '1m',
        'input_series' => [],
        'promql_expr_test' => [['expr' => 'vector(1)', 'eval_time' => '0m', 'exp_samples' => [['labels' => '{}', 'value' => 1]]]],
    ]]);

    expect(count($rules))->toBeGreaterThan(10)
        ->and($status)->toBe(0, substr($output, 0, 3000));
})->with([
    'poll' => [LiveMode::Poll],
    'off' => [LiveMode::Off],
]);
