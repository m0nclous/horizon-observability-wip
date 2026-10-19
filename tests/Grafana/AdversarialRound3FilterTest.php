<?php

declare(strict_types=1);

use DF\HorizonObservability\Grafana\Live\LiveMode;
use DF\HorizonObservability\Grafana\PanelRegistry;
use DF\HorizonObservability\Tests\Grafana\Support\Promtool;
use DF\HorizonObservability\Tests\Grafana\Support\Rendered;

/*
 * A Job filter on namespaced classes must leave every query valid. The job_class label is the payload displayName
 * (Attempts\JobClassLabel), which Laravel sets to the class name: App\Jobs\SendEmail for every job without its own
 * displayName() (the workbench jobs all define one, so the dev stack and the e2e tests never see a backslash).
 *
 * "All jobs selected" (Queries\Selection) compares `"${job_class:text}"` with "All" inside a PromQL string literal. A
 * probe that substituted the class itself for the text found 8 queries of the poll render and 11 of the off render that
 * Prometheus rejects (`unknown escape sequence`), but Grafana does not send that: the Job variable's option text is the
 * class without its namespace (Variables::jobQuery() labels each class with `display`, the part after the last
 * backslash, and the variable's regex takes it as the text, the class as the value), so a real option never puts a
 * backslash into the string; Grafana 13.2.4 and 12.4.13 with namespaced classes gave no query errors. `$job_class` is
 * the value, regex-escaped by the Prometheus datasource (prometheusSpecialRegexEscape: a backslash becomes four in the
 * PromQL text).
 *
 * This regression test interpolates every expression of the poll and off renders that reads job_class as Grafana sends
 * it with a Job filter on namespaced classes (one, and two: the text is "SendEmail + Import") and every other filter
 * All, and parses them with promtool.
 *
 * A value that matches no option (a shared link with a class no longer exported, `?var-job_class=App%5CJobs%5CRemoved`)
 * has the value itself as its text, and a batch kind is its own text. In a double-quoted string a backslash in such a
 * text did not parse (`unknown escape sequence`) and every panel with the All test showed the query error, as the
 * pilot's own All test did (#33; Grafana 13.2.4 with the stale link: panels 2, 8 and 24 failed, with
 * Batch = `Legacy\Kind` the Job and Queue variables). The text now sits in a raw string (backticks), which processes
 * no escapes. A double quote parses there too, but Grafana's Prometheus datasource does not escape a double quote in
 * the value (`$job_class`, `$batch_kind`) unless the feature toggle prometheusSpecialCharsInLabelValues is on (off in
 * 12.4.13 and 13.2.4), so with it off every selector of that filter fails whatever the All test does; the quote case
 * below interpolates the value as the toggle does.
 */

pest()->group('grafana');

beforeEach(function (): void {
    if (Promtool::command() === null) {
        $this->markTestSkipped('promtool is not available; set PROMTOOL or put promtool on the PATH.');
    }
});

/**
 * An expression as Grafana's Prometheus datasource sends it with the selected job classes $classes: `${job_class:text}`
 * the options' texts (the classes without their namespace) joined with " + ", `$job_class` the values regex-escaped and
 * joined into a group, every other filter All.
 *
 * @param  list<string>  $classes
 */
function round3WithClasses(string $expr, array $classes): string
{
    $texts = array_map(static fn (string $class): string => substr((string) strrchr('\\'.$class, '\\'), 1), $classes);
    $values = array_map(static fn (string $class): string => str_replace('\\', '\\\\\\\\', $class), $classes);
    $value = count($values) === 1 ? $values[0] : '('.implode('|', $values).')';
    $expr = strtr($expr, [
        '${job_class:text}' => implode(' + ', $texts),
        '${job_class}' => $value,
        '$job_class' => $value,
        '${__from:date:seconds}' => '0',
        '${__to:date:seconds}' => '3600',
        '${__from}' => '0',
        '${__to}' => '3600000',
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

it('parses every query of the poll and off renders with a Job filter on namespaced classes', function (LiveMode $mode, array $classes): void {
    $rules = [];

    foreach (Rendered::expressions(mode: $mode) as $where => $expr) {
        if (str_starts_with($where, 'panel ') && str_contains($expr, 'job_class')) {
            $rules[] = ['record' => 'adversarial:'.preg_replace('/[^A-Za-z0-9_]+/', '_', $where), 'expr' => round3WithClasses($expr, $classes)];
        }
    }

    [$status, $output] = Promtool::testRules([['name' => 'job-filter', 'rules' => $rules]], [[
        'interval' => '1m',
        'input_series' => [],
        'promql_expr_test' => [['expr' => 'vector(1)', 'eval_time' => '0m', 'exp_samples' => [['labels' => '{}', 'value' => 1]]]],
    ]]);

    // The text holds no backslash, the value does (escaped)
    expect(round3WithClasses('${job_class:text}', $classes))->not->toContain('\\')
        ->and(round3WithClasses('$job_class', $classes))->toContain('App\\\\\\\\Jobs\\\\\\\\SendEmail')
        ->and(count($rules))->toBeGreaterThan(10)
        ->and($status)->toBe(0, substr($output, 0, 3000));
})->with([
    'poll, one class' => [LiveMode::Poll, ['App\\Jobs\\SendEmail']],
    'off, one class' => [LiveMode::Off, ['App\\Jobs\\SendEmail']],
    'poll, two classes' => [LiveMode::Poll, ['App\\Jobs\\SendEmail', 'App\\Jobs\\Import']],
    'off, two classes' => [LiveMode::Off, ['App\\Jobs\\SendEmail', 'App\\Jobs\\Import']],
]);

/**
 * Grafana's Prometheus escaping of a variable value (prometheusSpecialRegexEscape): a backslash becomes four in the
 * PromQL text, a regex metacharacter gets two, and a double quote three with the feature toggle
 * prometheusSpecialCharsInLabelValues (classic: a single quote gets two, a double quote none).
 */
function round3Escaped(string $value, bool $toggle): string
{
    $value = str_replace('\\', '\\\\\\\\', $value);
    $value = $toggle ? str_replace('"', '\\\\\\"', $value) : str_replace("'", "\\\\'", $value);

    return (string) preg_replace('/[$^*{}\[\]\'+?.()|]/', '\\\\\\\\$0', $value);
}

/**
 * An expression as Grafana sends it with the custom value $value (one that matches no option) in the filter $variable:
 * the text is the value itself, the value escaped; every other filter All.
 */
function round3WithCustom(string $expr, string $variable, string $value, bool $toggle): string
{
    $escaped = round3Escaped($value, $toggle);

    return Promtool::interpolate(strtr($expr, [
        '${'.$variable.':text}' => $value,
        '${'.$variable.'}' => $escaped,
        '$'.$variable => $escaped,
    ]), '.+', 3600, 3600);
}

it('parses every query of the poll and off renders with a custom Job or Batch value that matches no option', function (LiveMode $mode, string $variable, string $value, bool $toggle): void {
    $rules = [];

    foreach (Rendered::expressions(mode: $mode) as $where => $expr) {
        // The variable queries too (Grafana's query_result() around them is not PromQL)
        $expr = (string) preg_replace('/^query_result\((.*)\)$/s', '$1', $expr);

        if (str_contains($expr, '$'.$variable) || str_contains($expr, '${'.$variable)) {
            $rules[] = ['record' => 'custom:'.preg_replace('/[^A-Za-z0-9_]+/', '_', $where), 'expr' => round3WithCustom($expr, $variable, $value, $toggle)];
        }
    }

    [$status, $output] = Promtool::testRules([['name' => 'custom-filter', 'rules' => $rules]], [[
        'interval' => '1m',
        'input_series' => [],
        'promql_expr_test' => [['expr' => 'vector(1)', 'eval_time' => '0m', 'exp_samples' => [['labels' => '{}', 'value' => 1]]]],
    ]]);

    expect(count($rules))->toBeGreaterThan(10)
        ->and($status)->toBe(0, substr($output, 0, 3000));
})->with([
    'poll, Job = a class no longer exported' => [LiveMode::Poll, 'job_class', 'App\\Jobs\\RemovedJob', false],
    'off, Job = a class no longer exported' => [LiveMode::Off, 'job_class', 'App\\Jobs\\RemovedJob', false],
    'poll, Batch = a kind with a backslash' => [LiveMode::Poll, 'batch_kind', 'Legacy\\Import', false],
    'off, Batch = a kind with a backslash' => [LiveMode::Off, 'batch_kind', 'Legacy\\Import', false],
    'poll, Batch = a kind with double quotes (the value escaped as with the toggle)' => [LiveMode::Poll, 'batch_kind', 'Import "daily"', true],
    'off, Job = a value with a double quote (the value escaped as with the toggle)' => [LiveMode::Off, 'job_class', 'App\\Jobs\\Say"Hi"', true],
]);

it('shows the numbers of a stale Job link instead of a query error', function (): void {
    $expr = static fn (int $id, string $refId): string => round3WithCustom(Rendered::expressions()['panel '.$id.' '.$refId], 'job_class', 'App\\Jobs\\RemovedJob', false);
    $series = static fn (string $name, string $labels = ''): string => 'ho_'.$name.'{job="app", instance="app:8080", connection="redis", queue="default"'.$labels.'}';

    [$status, $output] = Promtool::testRules([], [[
        'interval' => '1m',
        'input_series' => [
            ['series' => 'ho_horizon_status{job="app", instance="app:8080"}', 'values' => '1x60'],
            ['series' => $series('queue_pending_jobs'), 'values' => '5x60'],
            ['series' => $series('queue_jobs_pending', ', job_class="App\\\\Jobs\\\\Import"'), 'values' => '5x60'],
            ['series' => $series('queue_delayed_jobs'), 'values' => '3x60'],
            ['series' => $series('queue_delayed_due_jobs'), 'values' => '1x60'],
            ['series' => $series('queue_jobs_total', ', job_class="App\\\\Jobs\\\\Import", status="completed"'), 'values' => '0+10x60'],
        ],
        'promql_expr_test' => [
            // The Pending tile with the All test: none of the class's jobs pending, 0
            ['expr' => $expr(PanelRegistry::PENDING, 'A'), 'eval_time' => '60m', 'exp_samples' => [['labels' => '{}', 'value' => 0]]],
            // The Delayed tile with the All test: no per-class value, no data (a dash)
            ['expr' => $expr(PanelRegistry::DELAYED, 'A'), 'eval_time' => '60m', 'exp_samples' => []],
            // The Completed tile's points: 0 while the application exports metrics
            ['expr' => 'sum(sum_over_time(('.$expr(PanelRegistry::COMPLETED, 'A').')[59m:1m]))', 'eval_time' => '60m', 'exp_samples' => [['labels' => '{}', 'value' => 0]]],
        ],
    ]]);

    expect($status)->toBe(0, substr($output, 0, 3000));
});
