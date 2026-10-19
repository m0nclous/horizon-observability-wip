<?php

declare(strict_types=1);

use DF\HorizonObservability\Grafana\Glyph;
use DF\HorizonObservability\Grafana\Live\LiveMode;
use DF\HorizonObservability\Grafana\PanelRegistry;
use DF\HorizonObservability\Grafana\Promql;
use DF\HorizonObservability\Live\Captions;
use DF\HorizonObservability\Tests\Grafana\Support\Promtool;
use DF\HorizonObservability\Tests\Grafana\Support\Rendered;

/*
 * The live tiles take their captions and levels from Live\Captions (via the snapshot), the --live=off tiles from
 * PromQL. Switching the mode must not change what a tile says, so the off-mode expressions, evaluated by promtool on
 * the exported series, give what Captions gives for the shared cases of tests/Fixtures/live/captions.php:
 * - Pending (#2): the caption "Oldest: N s \u{00B7} Q" / "Oldest: N min \u{00B7} Q" / "Oldest: \u{2014}" (query B and its
 *   renameByRegex) equals Captions::oldestExact() for the exact age, and the level (query C) Captions::waitLevel();
 * - Supervisor Processes (#3): the caption "name \u{00B7} max N" (query A, legend) equals Captions::supervisor() of
 *   Captions::display();
 * - Completed (#8): the caption "RPM: n" / "RPM: \u{2014}" (query B's renames) equals Captions::rpm().
 */

pest()->group('grafana');

beforeEach(function (): void {
    if (Promtool::command() === null) {
        $this->markTestSkipped('promtool is not available; set PROMTOOL or put promtool on the PATH.');
    }
});

/**
 * The cases of tests/Fixtures/live/captions.php.
 *
 * @return array<string, array<string, array{0: list<mixed>, 1: mixed}>>
 */
function captionCases(): array
{
    return require __DIR__.'/../Fixtures/live/captions.php';
}

/**
 * The target $refId of panel $id of the --live=off render.
 *
 * @return array<string, mixed>
 */
function offTarget(int $id, string $refId): array
{
    foreach (PanelRegistry::panels(Rendered::dashboard(LiveMode::Off)) as $panel) {
        foreach ($panel['id'] === $id ? $panel['targets'] : [] as $target) {
            if ($target['refId'] === $refId) {
                return $target;
            }
        }
    }

    throw new RuntimeException('No target '.$refId.' in panel '.$id.'.');
}

/**
 * The renameByRegex transformations of panel $id of the --live=off render applied to a field name, as Grafana does.
 */
function offRenamed(int $id, string $name): string
{
    foreach (PanelRegistry::panels(Rendered::dashboard(LiveMode::Off)) as $panel) {
        foreach ($panel['id'] === $id ? $panel['transformations'] : [] as $transformation) {
            if ($transformation['id'] === 'renameByRegex') {
                $pattern = '/'.str_replace('/', '\/', $transformation['options']['regex']).'/u';
                // JavaScript's String.replace() and preg_replace() read "$1" alike
                $name = (string) preg_replace($pattern, $transformation['options']['renamePattern'], $name);
            }
        }
    }

    return $name;
}

/**
 * Series of one application with one queue $queue waiting $age seconds against a $threshold limit.
 *
 * @return list<array{series: string, values: string}>
 */
function waitingQueue(string $queue, float $age, int $threshold): array
{
    $labels = 'job="app", instance="app:8080", connection="redis", queue='.Promql::quote($queue);

    return [
        ['series' => 'ho_horizon_status{job="app", instance="app:8080"}', 'values' => '1x10'],
        ['series' => 'ho_queue_oldest_pending_job_age_seconds{'.$labels.'}', 'values' => $age.'x10'],
        ['series' => 'ho_horizon_wait_threshold_seconds{'.$labels.'}', 'values' => $threshold.'x10'],
    ];
}

it('captions Pending in the off mode as Captions does for the exact age', function (float $age, string $queue): void {
    $expected = Captions::oldestExact($age, $queue);
    // The display label of query B: "17 s · default", or the dash when nothing waits
    $display = substr($expected, strlen('Oldest: '));
    $labels = $display === Glyph::DASH
        ? '{display="'.Glyph::DASH.'"}'
        : sprintf('{display=%s, queue=%s, v="%s"}', Promql::quote($display), Promql::quote($queue), explode(' ', $display)[0]);

    [$status, $output] = Promtool::testRules([], [[
        'interval' => '1m',
        'input_series' => waitingQueue($queue, $age, 60),
        'promql_expr_test' => [[
            'expr' => Promtool::interpolate(offTarget(PanelRegistry::PENDING, 'B')['expr'], '.+', 5 * 60, 5 * 60),
            'eval_time' => '5m',
            'exp_samples' => [['labels' => $labels, 'value' => 1]],
        ]],
    ]]);

    // The field name is the display label, renamed to "Oldest: ..."
    expect(offRenamed(PanelRegistry::PENDING, $display))->toBe($expected)
        ->and($status)->toBe(0, $output);
})->with(function (): array {
    $cases = [];

    foreach (captionCases()['oldest_exact'] as $name => [[$age, $queue]]) {
        // Exported ages are finite and not negative; a queue always has a name
        if ($age !== null && is_finite($age) && $age >= 0 && is_string($queue) && $queue !== '') {
            $cases[$name] = [(float) $age, $queue];
        }
    }

    return $cases;
});

it('colours Pending in the off mode by the level Captions gives for the wait ratio', function (float $ratio, int $level): void {
    // The level query C: 2K for level 0, K for level 1, 0 for level 2 (K = 1e9), the thresholds being K and 0
    $k = 1000000000;

    [$status, $output] = Promtool::testRules([], [[
        'interval' => '1m',
        'input_series' => waitingQueue('default', $ratio * 100, 100),
        'promql_expr_test' => [[
            'expr' => Promtool::interpolate(offTarget(PanelRegistry::PENDING, 'C')['expr'], '.+', 5 * 60, 5 * 60),
            'eval_time' => '5m',
            'exp_samples' => [['labels' => '{}', 'value' => [3 * $k, $k, 0][$level]]],
        ]],
    ]]);

    expect(Captions::waitLevel($ratio))->toBe($level)
        ->and($status)->toBe(0, $output);
})->with(function (): array {
    $cases = [];

    foreach (captionCases()['wait_level'] as $name => [[$ratio], $level]) {
        if ($ratio !== null) {
            $cases[$name] = [(float) $ratio, $level];
        }
    }

    return $cases;
});

it('captions Supervisor Processes in the off mode as Captions does', function (string $supervisor, string $display): void {
    $labels = 'job="app", instance="app:8080", supervisor='.Promql::quote($supervisor);
    $target = offTarget(PanelRegistry::SUPERVISOR_PROCESSES, 'A');

    [$status, $output] = Promtool::testRules([], [[
        'interval' => '1m',
        'input_series' => [
            ['series' => 'ho_horizon_status{job="app", instance="app:8080"}', 'values' => '1x10'],
            ['series' => 'ho_horizon_supervisor_processes{'.$labels.', queue="default"}', 'values' => '2x10'],
            ['series' => 'ho_horizon_supervisor_queues{'.$labels.', queue="default", pool="default"}', 'values' => '1x10'],
            ['series' => 'ho_horizon_supervisor_max_processes{'.$labels.'}', 'values' => '3x10'],
        ],
        'promql_expr_test' => [[
            'expr' => Promtool::interpolate($target['expr'], '.+', 5 * 60, 5 * 60),
            'eval_time' => '5m',
            'exp_samples' => [['labels' => sprintf('{max="3", name=%s, supervisor=%s}', Promql::quote($display), Promql::quote($supervisor)), 'value' => 2]],
        ]],
    ]]);

    // The legend {{name}} · max {{max}} is the caption
    expect(strtr($target['legendFormat'], ['{{name}}' => $display, '{{max}}' => '3']))->toBe(Captions::supervisor(Captions::display($supervisor), 3))
        ->and($status)->toBe(0, $output);
})->with(function (): array {
    $cases = [];

    foreach (captionCases()['display'] as $name => [[$supervisor], $display]) {
        $cases[$name] = [$supervisor, $display];
    }

    return $cases;
});

it('captions Completed in the off mode as Captions does', function (?int $perMinute, string $expected): void {
    // Query B gives the rounded RPM, or -1 without metrics; the renames make the caption
    expect(offRenamed(PanelRegistry::COMPLETED, $perMinute === null ? '-1' : (string) $perMinute))->toBe($expected)
        ->and(Captions::rpm($perMinute))->toBe($expected);
})->with(function (): array {
    $cases = [];

    foreach (captionCases()['rpm'] as $name => [[$perMinute], $caption]) {
        $cases[$name] = [$perMinute, $caption];
    }

    return $cases;
});
