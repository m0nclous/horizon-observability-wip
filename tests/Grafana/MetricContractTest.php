<?php

declare(strict_types=1);

use DF\HorizonObservability\Grafana\Live\LiveMode;
use DF\HorizonObservability\Grafana\Promql;
use DF\HorizonObservability\Metrics\Metric;
use DF\HorizonObservability\Tests\Grafana\Support\Rendered;

/*
 * The metric contract: every <ns>_<name> in the committed dashboards and alert rules (off, poll, stream) is a family of
 * the catalog (Metrics\Metric), after stripping the histogram suffixes _bucket, _sum and _count. The only label outside
 * the catalog is the batch-scope sentinel __batch_scope__.
 */

pest()->group('grafana');

it('reads only families of the metric catalog', function (LiveMode $mode): void {
    $families = array_map(static fn (Metric $metric): string => $metric->value, Metric::cases());
    $unknown = [];
    $text = implode("\n", Rendered::expressions(mode: $mode))."\n".json_encode(Rendered::alerting($mode), JSON_THROW_ON_ERROR);

    preg_match_all('/\bho_([a-z_]+)/', $text, $matches);

    foreach (array_unique($matches[1]) as $name) {
        $family = (string) preg_replace('/_(bucket|sum|count)$/', '', $name);

        if (! in_array($family, $families, true) && ! in_array($name, $families, true)) {
            $unknown[] = 'ho_'.$name;
        }
    }

    expect($matches[1])->not->toBe([])
        ->and($unknown)->toBe([]);
})->with(Rendered::modes());

it('no longer reads the pilot-only families', function (LiveMode $mode): void {
    $text = json_encode([Rendered::dashboard($mode), Rendered::alerting($mode)], JSON_THROW_ON_ERROR);

    expect($text)->not->toContain('horizon_current_processes')
        ->and(preg_match('/queue_oldest_pending_job_age(?!_seconds)/', $text))->toBe(0);
})->with(Rendered::modes());

/**
 * The expression with every PromQL string literal emptied, so labels and names inside strings do not count.
 */
function withoutStrings(string $expr): string
{
    return (string) preg_replace('/"(?:[^"\\\\]|\\\\.)*"/', '""', $expr);
}

it('matches each package selector only on labels of its family', function (LiveMode $mode): void {
    $wrong = [];

    foreach (Rendered::expressions(mode: $mode) as $where => $expr) {
        preg_match_all('/\bho_([a-z_]+)\{((?:[^}"]|"(?:[^"\\\\]|\\\\.)*")*)\}/', $expr, $selectors, PREG_SET_ORDER);

        foreach ($selectors as [, $name, $matchers]) {
            $series = preg_match('/_(bucket|sum|count)$/', $name, $suffix) === 1 && Metric::tryFrom($name) === null ? $suffix[1] : '';
            $metric = Metric::from($series === '' ? $name : substr($name, 0, -strlen($series) - 1));
            $allowed = ['job', ...$metric->labels(), ...($series === 'bucket' ? ['le'] : [])];

            preg_match_all('/(\w+)\s*(?:=~|!~|!=|=)\s*"/', withoutStrings($matchers) === '' ? '' : $matchers, $labels);

            foreach (array_diff($labels[1], $allowed) as $label) {
                $wrong[] = $where.': ho_'.$name.' has no label '.$label;
            }
        }
    }

    expect($wrong)->toBe([]);
})->with(Rendered::modes());

it('matches the selectors by name only on labels of their families, job and the batch-scope sentinel', function (LiveMode $mode): void {
    $wrong = [];
    $sentinels = 0;

    foreach (Rendered::expressions(mode: $mode) as $where => $expr) {
        preg_match_all('/\{__name__=~"([^"]+)"((?:, (?:[^}"]|"(?:[^"\\\\]|\\\\.)*")*)?)\}/', $expr, $selectors, PREG_SET_ORDER);

        foreach ($selectors as [, $names, $matchers]) {
            $allowed = ['job', Promql::BATCH_SCOPE];

            foreach (explode('|', $names) as $name) {
                array_push($allowed, ...Metric::from(substr($name, strlen('ho_')))->labels());
            }

            preg_match_all('/(\w+)\s*(=~|!~|!=|=)\s*"((?:[^"\\\\]|\\\\.)*)"/', $matchers, $labels, PREG_SET_ORDER);

            foreach ($labels as [, $label, $operator, $value]) {
                if (! in_array($label, $allowed, true)) {
                    $wrong[] = $where.': {__name__=~"'.$names.'"} has no label '.$label;
                }

                // The sentinel no stored series carries: only as the Batch switch
                if ($label === Promql::BATCH_SCOPE) {
                    $sentinels++;

                    if ($operator !== '!~' || $value !== '$batch_kind') {
                        $wrong[] = $where.': '.$label.$operator.'"'.$value.'"';
                    }
                }
            }
        }
    }

    expect($wrong)->toBe([])
        ->and($sentinels)->toBeGreaterThan(200);
})->with(Rendered::modes());

/**
 * The destination labels of every label_replace() and label_join() of an expression: their second argument.
 *
 * @return list<string>
 */
function createdLabels(string $expr): array
{
    $labels = [];
    $offset = 0;

    while (preg_match('/label_(?:replace|join)\(/', $expr, $match, PREG_OFFSET_CAPTURE, $offset) === 1) {
        $offset = $match[0][1] + strlen($match[0][0]);
        $depth = 0;
        $arguments = 0;

        // Walk to the end of the first argument at depth 0, strings skipped
        for ($i = $offset, $length = strlen($expr); $i < $length; $i++) {
            $char = $expr[$i];

            if ($char === '"') {
                for ($i++; $i < $length && $expr[$i] !== '"'; $i++) {
                    $i += $expr[$i] === '\\' ? 1 : 0;
                }
            } elseif ($char === '(') {
                $depth++;
            } elseif ($char === ')') {
                $depth--;
            } elseif ($char === ',' && $depth === 0 && ++$arguments === 1) {
                preg_match('/^,\s*"(\w+)"/', substr($expr, $i), $destination);
                $labels[] = $destination[1] ?? '';

                break;
            }
        }
    }

    return array_values(array_filter($labels));
}

/**
 * Labels an expression groups, matches or joins on that are neither catalog labels, job, le, labels the expression
 * creates itself, nor (with GRAFANA_ALERTS) labels of Grafana's alert state history.
 *
 * @return list<string>
 */
function unknownLabels(string $expr): array
{
    $catalog = array_merge(['job', 'le'], ...array_map(static fn (Metric $metric): array => $metric->labels(), Metric::cases()));
    $alerts = ['grafana_rule_uid', 'alertstate', 'alertname'];

    preg_match_all('/count_values(?: by \([^)]*\))? \("(\w+)"/', $expr, $counted);
    $allowed = [...$catalog, ...createdLabels($expr), ...$counted[1], ...(str_contains($expr, 'GRAFANA_ALERTS') ? $alerts : [])];
    $code = withoutStrings($expr);

    preg_match_all('/\b(?:by|on|group_left|group_right|ignoring|without)\s*\(([^)]*)\)/', $code, $lists);
    preg_match_all('/^label_values\([^,]+,\s*(\w+)\)$/', $code, $values);
    $used = [...$values[1]];

    foreach ($lists[1] as $list) {
        array_push($used, ...array_filter(array_map(trim(...), explode(',', $list))));
    }

    return array_values(array_diff(array_unique($used), $allowed));
}

it('groups and matches only on catalog labels, job, le or labels the expression creates', function (LiveMode $mode): void {
    $wrong = [];

    foreach (Rendered::expressions(mode: $mode) as $where => $expr) {
        foreach (unknownLabels($expr) as $label) {
            $wrong[] = $where.': '.$label;
        }
    }

    expect($wrong)->toBe([]);
})->with(Rendered::modes());

it('flags labels from nowhere', function (string $expr, array $unknown): void {
    expect(unknownLabels($expr))->toBe($unknown);
})->with([
    'catalog labels' => ['sum by (queue, job_class) (x) / on (queue) group_left (connection) y', []],
    'a typo' => ['sum by (queeu) (x)', ['queeu']],
    'created by label_replace' => ['sum by (display) (label_replace(label_replace(x, "inner", "", "", ""), "display", "$1", "queue", "(.*)"))', []],
    'created by count_values' => ['count_values by (queue) ("level", x) * on (level) y', []],
    'alert state labels only with GRAFANA_ALERTS' => ['count by (grafana_rule_uid) (x)', ['grafana_rule_uid']],
    'a label inside a string' => ['label_replace(x, "a", "$1", "b", "(by (nothing))")', []],
]);
