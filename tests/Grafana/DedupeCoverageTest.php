<?php

declare(strict_types=1);

use DF\HorizonObservability\Grafana\AlertRulesBuilder;
use DF\HorizonObservability\Grafana\DashboardBuilder;
use DF\HorizonObservability\Grafana\DashboardOptions;
use DF\HorizonObservability\Grafana\Promql;
use DF\HorizonObservability\Metrics\Metric;
use DF\HorizonObservability\Tests\Grafana\Support\Rendered;

/*
 * C7 (Arch Appendix H; Arch Q1.5 and Q5.6 step 10): two replicas of the application export the same storage under
 * different `instance` labels, so every package selector, or the range function around it, sits in
 * `max by (job, <the family's catalog labels>[, le]) (...)`. This test tokenises every expression of the committed
 * dashboard and alert rules and checks each package selector's wrapper and its label list. The e2e half of C7
 * compares a two-replica scrape with a single one (bin/perf.mjs compare, CI e2e job).
 */

pest()->group('grafana');

/**
 * Byte ranges of the double-quoted PromQL strings of an expression.
 *
 * @return list<array{0: int, 1: int}>
 */
function promqlStrings(string $expr): array
{
    $ranges = [];

    for ($i = 0, $length = strlen($expr); $i < $length; $i++) {
        if ($expr[$i] !== '"') {
            continue;
        }

        $start = $i++;

        while ($i < $length && $expr[$i] !== '"') {
            $i += $expr[$i] === '\\' ? 2 : 1;
        }

        $ranges[] = [$start, $i];
    }

    return $ranges;
}

function inPromqlString(int $offset, array $strings): bool
{
    foreach ($strings as [$start, $end]) {
        if ($offset > $start && $offset < $end) {
            return true;
        }
    }

    return false;
}

/**
 * The offset of the unmatched "(" before $offset, outside strings, or -1.
 */
function openingParenthesis(string $expr, int $offset, array $strings): int
{
    $depth = 0;

    for ($i = $offset - 1; $i >= 0; $i--) {
        if (inPromqlString($i, $strings) || ($expr[$i] !== '(' && $expr[$i] !== ')')) {
            continue;
        }

        if ($expr[$i] === ')') {
            $depth++;
        } elseif ($depth-- === 0) {
            return $i;
        }
    }

    return -1;
}

/**
 * The offset of the ")" that closes the "(" at $open, outside strings.
 */
function closingParenthesis(string $expr, int $open, array $strings): int
{
    $depth = 0;

    for ($i = $open, $length = strlen($expr); $i < $length; $i++) {
        if (inPromqlString($i, $strings)) {
            continue;
        }

        if ($expr[$i] === '(') {
            $depth++;
        } elseif ($expr[$i] === ')' && --$depth === 0) {
            return $i;
        }
    }

    return -1;
}

/**
 * Package selectors of an expression without the dedupe wrapper of their catalog labels, as readable reasons.
 *
 * @return list<string>
 */
function undeduplicated(string $expr, string $prefix = 'ho_'): array
{
    $strings = promqlStrings($expr);
    $problems = [];
    $time = '(?: offset -?\$?[\w]+)?(?: @ \$\{__(?:from|to):date:seconds\})?';

    preg_match_all('/\b'.preg_quote($prefix, '/').'([a-z_]+)/', $expr, $matches, PREG_OFFSET_CAPTURE);

    foreach ($matches[1] as [$name, $offset]) {
        $start = $offset - strlen($prefix);

        if (inPromqlString($start, $strings)) {
            continue;
        }

        $series = preg_match('/_(bucket|sum|count)$/', $name, $suffix) === 1 && Metric::tryFrom($name) === null ? $suffix[1] : '';
        $metric = Metric::tryFrom($series === '' ? $name : substr($name, 0, -strlen($series) - 1));

        if ($metric === null) {
            $problems[] = $prefix.$name.': not a catalog family';

            continue;
        }

        $wrapper = 'max by ('.implode(', ', Promql::dedupeLabels($metric, $series)).') ';
        $open = openingParenthesis($expr, $start, $strings);
        $inner = substr($expr, $open + 1, closingParenthesis($expr, $open, $strings) - $open - 1);
        $selector = preg_quote($prefix.$name, '/').'(?:\{(?:[^}"]|"(?:[^"\\\\]|\\\\.)*")*\})?';

        // max by (...) (selector [offset] [@]) or max by (...) (function(selector[range] [offset] [@]))
        if (preg_match('/^'.$selector.$time.'$/', $inner) === 1) {
            $wrapped = substr($expr, 0, $open);
        } elseif (preg_match('/^'.$selector.'\[[^\]]+\]'.$time.'$/', $inner) === 1 && preg_match('/(\w+)$/', substr($expr, 0, $open), $function) === 1) {
            $outer = openingParenthesis($expr, $open - strlen($function[1]), $strings);
            $alone = $outer + 1 === $open - strlen($function[1]) && closingParenthesis($expr, $outer, $strings) === closingParenthesis($expr, $open, $strings) + 1;
            $wrapped = $alone ? substr($expr, 0, $outer) : '';
        } else {
            $wrapped = '';
        }

        if (! str_ends_with($wrapped, $wrapper)) {
            $problems[] = $prefix.$name.' at '.$start.': not directly in '.trim($wrapper);
        }
    }

    return $problems;
}

it('wraps every package selector of the committed render in the dedupe of its catalog labels', function (): void {
    $problems = [];
    $selectors = 0;

    foreach (Rendered::expressions() as $where => $expr) {
        // The variable lists jobs by series names, not by a PromQL expression
        if (str_starts_with($expr, 'label_values(')) {
            continue;
        }

        $selectors += preg_match_all('/\bho_[a-z_]+\{/', $expr);

        foreach (undeduplicated($expr) as $problem) {
            $problems[] = $where.': '.$problem;
        }
    }

    expect($selectors)->toBeGreaterThan(300)
        ->and($problems)->toBe([]);
});

it('finds selectors outside the wrapper', function (string $expr, int $problems): void {
    expect(undeduplicated($expr))->toHaveCount($problems);
})->with([
    'bare selector' => ['sum(ho_queue_pending_jobs{job=~".+"})', 1],
    'wrapped selector' => ['sum(max by (job, connection, queue) (ho_queue_pending_jobs{job=~".+"}))', 0],
    'wrong labels' => ['sum(max by (job, queue) (ho_queue_pending_jobs{job=~".+"}))', 1],
    'wrapped range function' => ['max by (job, connection, queue, job_class) (max_over_time(ho_queue_jobs_pending{job=~".+"}[5m] offset 1h))', 0],
    'range function inside the wrapper' => ['max by (job, connection, queue, job_class) (1 + max_over_time(ho_queue_jobs_pending[5m]))', 1],
    'bucket with le' => ['max by (job, connection, queue, job_class, le) (increase(ho_queue_job_duration_seconds_bucket[1h]))', 0],
    'bucket without le' => ['max by (job, connection, queue, job_class) (increase(ho_queue_job_duration_seconds_bucket[1h]))', 1],
    'shifted to the end' => ['max by (job) (ho_horizon_status @ ${__to:date:seconds})', 0],
    'name in a string' => ['label_replace(vector(1), "x", "ho_queue_pending_jobs", "", "")', 0],
    'unknown family' => ['max by (job) (ho_queue_unknown)', 1],
]);

it('wraps the selectors of every alert rule and of a pinned job', function (): void {
    $options = new DashboardOptions(uid: 'queues', job: 'app', healthRules: true);
    $problems = [];

    foreach (Rendered::expressions((new DashboardBuilder($options))->build(), (new AlertRulesBuilder($options))->build()) as $where => $expr) {
        // The duplicates rule counts the replicas on purpose (Arch Q1.5)
        if (! str_starts_with($expr, 'label_values(') && $where !== 'rule queues-duplicates A') {
            array_push($problems, ...array_map(static fn (string $problem): string => $where.': '.$problem, undeduplicated($expr)));
        }
    }

    expect($problems)->toBe([]);
});

it('fails a render without the dedupe', function (): void {
    $dashboard = (new DashboardBuilder(new DashboardOptions(dedupe: false)))->build();
    $problems = 0;

    foreach (Rendered::expressions($dashboard, []) as $expr) {
        $problems += str_starts_with($expr, 'label_values(') ? 0 : count(undeduplicated($expr));
    }

    expect($problems)->toBeGreaterThan(300);
});
