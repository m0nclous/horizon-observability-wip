<?php

declare(strict_types=1);

use DF\HorizonObservability\Grafana\Live\LiveMode;
use DF\HorizonObservability\Grafana\PanelRegistry;
use DF\HorizonObservability\Tests\Grafana\Support\Promtool;
use DF\HorizonObservability\Tests\Grafana\Support\Rendered;

/*
 * The Horizon tile (#1) after a restart (#44). The band A keeps the worst status of each graph step, so a restart stays
 * visible on long ranges, but the tile shows the current status: the off render merges its instant query N (the last
 * status within Prometheus' lookback) into A as the last point, the poll and stream renders the snapshot's status
 * (LiveShapes). The mappings of B give that value its text and colour; the uptime counts only the steps with a
 * status, so the time before the first scrape is not down.
 */

pest()->group('grafana');

beforeEach(function (): void {
    if (Promtool::command() === null) {
        $this->markTestSkipped('promtool is not available; set PROMTOOL or put promtool on the PATH.');
    }
});

/**
 * The off render's Horizon tile.
 *
 * @return array<string, mixed>
 */
function horizonPanel(): array
{
    foreach (PanelRegistry::panels(Rendered::dashboard(LiveMode::Off)) as $panel) {
        if ($panel['id'] === PanelRegistry::HORIZON) {
            return $panel;
        }
    }

    throw new RuntimeException('No Horizon tile.');
}

/**
 * The targets of the off render's Horizon tile by refId.
 *
 * @return array<string, array<string, mixed>>
 */
function horizonTargets(): array
{
    return array_column(horizonPanel()['targets'], null, 'refId');
}

/**
 * An expression as Grafana sends it at $to with a range of $range seconds and the step $interval.
 */
function horizonExpr(string $expr, int $to, int $range, string $interval = '1m'): string
{
    return Promtool::interpolate(strtr($expr, ['$__interval' => $interval]), '.+', $to, $range);
}

/**
 * The status samples at 30-second scrapes: $values in promtool's notation.
 *
 * @return list<array{series: string, values: string}>
 */
function horizonStatus(string $values): array
{
    return [['series' => 'ho_horizon_status{job="app", instance="app:8080"}', 'values' => $values]];
}

it('shows the current status after a restart, not the worst status of the last step', function (): void {
    $targets = horizonTargets();

    // Running for 4 minutes, stopped for 4, running again for the last 2: the last step of a 5-minute step holds
    // the stop, the current status is running
    [$status, $output] = Promtool::testRules([], [[
        'interval' => '30s',
        'input_series' => horizonStatus('1x7 -1x7 1x4'),
        'promql_expr_test' => [
            ['expr' => horizonExpr($targets['A']['expr'], 600, 3600, '5m'), 'eval_time' => '9m', 'exp_samples' => [['labels' => '{}', 'value' => -1]]],
            ['expr' => horizonExpr($targets['N']['expr'], 600, 3600, '5m'), 'eval_time' => '9m', 'exp_samples' => [['labels' => '{}', 'value' => 1]]],
            // Stopped now: the current status at once
            ['expr' => horizonExpr($targets['N']['expr'], 600, 3600, '5m'), 'eval_time' => '5m', 'exp_samples' => [['labels' => '{}', 'value' => -1]]],
        ],
    ]]);

    $transformations = horizonPanel()['transformations'];

    expect($status)->toBe(0, $output)
        ->and(array_keys($targets))->toBe(['A', 'N', 'B'])
        ->and($targets['A']['instant'])->toBeFalse()
        ->and($targets['N']['instant'])->toBeTrue()
        ->and($targets['N']['format'] ?? 'time_series')->toBe('time_series')
        // N becomes the last point of the band, then B maps the merged frame (no refId of its own)
        ->and($transformations[0])->toBe(['id' => 'merge', 'filter' => ['id' => 'byRefId', 'options' => 'A|N'], 'options' => []])
        ->and($transformations[1]['id'])->toBe('configFromData')
        ->and($transformations[1]['options']['configRefId'])->toBe('B')
        ->and($transformations[1]['options'])->not->toHaveKey('applyTo');
});

it('counts the uptime over the time with a status only', function (string $values, int $to, array $rows): void {
    $samples = [];

    foreach ($rows as [$status, $color, $text]) {
        $samples[] = ['labels' => sprintf('{status="%s", color="%s", text="%s"}', $status, $color, $text), 'value' => 1];
    }

    [$exit, $output] = Promtool::testRules([], [[
        'interval' => '1m',
        'input_series' => horizonStatus($values),
        'promql_expr_test' => [['expr' => horizonExpr(horizonTargets()['B']['expr'], $to, 3600), 'eval_time' => intdiv($to, 60).'m', 'exp_samples' => $samples]],
    ]]);

    expect($exit)->toBe(0, $output);
})->with([
    // Installed 30 minutes ago, running since: 100 %, not 50 %
    'a new install' => ['_x29 1x30', 3600, [['1', 'green', '100.0%'], ['0', 'red', '100.0%'], ['-1', 'red', '100.0%'], ['-2', 'red', 'No metrics']]],
    // Stopped for a quarter of the time with metrics: yellow
    'a stop within the time with metrics' => ['_x21 1x19 -1x9 1x9', 3600, [['1', 'yellow', '75.0%'], ['0', 'red', '75.0%'], ['-1', 'red', '75.0%'], ['-2', 'red', 'No metrics']]],
    // No status in the range: a dash, and a running status (the live snapshot) stays green
    'no status in the range' => ['1x10', 7200, [['1', 'green', "\u{2014}"], ['0', 'red', "\u{2014}"], ['-1', 'red', "\u{2014}"], ['-2', 'red', 'No metrics']]],
]);
