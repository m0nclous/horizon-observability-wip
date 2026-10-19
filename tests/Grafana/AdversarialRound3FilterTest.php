<?php

declare(strict_types=1);

use DF\HorizonObservability\Grafana\Live\LiveMode;
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
 * All, and parses them with promtool. Known limit (Selection::isAll()): a value that matches no option (a shared link
 * with a class no longer exported) has the value as its text, and a batch kind is its own text; with a backslash or a
 * double quote in them the All test does not parse and the panels that use it show the query error, as the pilot's
 * own All test did.
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
