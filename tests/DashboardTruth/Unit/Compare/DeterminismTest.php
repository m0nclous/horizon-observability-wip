<?php

declare(strict_types=1);

use DF\HorizonObservability\Tests\DashboardTruth\Compare\Determinism;
use DF\HorizonObservability\Tests\DashboardTruth\Contracts\Observation;

/*
| The determinism check of two runs (T17, design sections 4 and 9, `bin/run compare`) on synthetic observations: the
| order of series and rows, the measured durations (#6 F/G/I/J, the job lists' Runtime and their stamps to the
| second, the duration cells of those panels' DOM), the order of those panels' table rows (#6 sorts by its measured
| Avg) and random job ids never count; any other value, a cell moved between rows, the sent query, the DOM of another
| panel and the variables do.
*/

pest()->group('grafana');

/**
 * A Prometheus frame of one series.
 *
 * @param  array<string, string>  $labels
 * @param  list<array{0: int, 1: float}>  $points
 * @return array<string, mixed>
 */
function dashboardTruthFrame(array $labels, array $points, string $expr = 'x'): array
{
    return [
        'schema' => ['meta' => ['executedQueryString' => "Expr: {$expr}\nStep: 15s"], 'fields' => [['name' => 'Time', 'type' => 'time'], ['name' => 'Value', 'type' => 'number', 'labels' => $labels]]],
        'data' => ['values' => [array_column($points, 0), array_column($points, 1)]],
    ];
}

/**
 * An observation with #8 A (two series), #6 F, the #18 job list and the DOM of #8 and #6, changed by `$change`.
 *
 * @param  array<string, mixed>  $change
 */
function dashboardTruthDeterminismObservation(array $change = []): Observation
{
    $change += [
        'order' => false, 'value' => 3.0, 'duration' => 0.0012, 'runtime' => 0.0009, 'completedMs' => 1740962430120,
        'uuid' => '51e07fa0-b268-4f99-8f8b-507448cf9263', 'expr' => 'x', 'dom8' => '304', 'dom6' => '1.24 ms', 'queue' => 'default',
        'rows6' => [['Born', '0', '0', '823.38 µs'], ['Invoice', '0', '30', '$dom6']], 'withRows' => true,
    ];
    $rows6 = array_map(static fn (array $row): array => array_map(static fn (string $cell): string => $cell === '$dom6' ? $change['dom6'] : $cell, $row), $change['rows6']);
    $rows6 = [['Job', 'Pending', 'Failed', 'Avg'], ...$rows6];
    $series = [
        dashboardTruthFrame(['job_class' => 'Truth\\Invoice'], [[1740962400000, $change['value']], [1740962415000, 1.0]], $change['expr']),
        dashboardTruthFrame(['job_class' => 'Truth\\Born'], [[1740962400000, 0.0], [1740962415000, 2.0]], $change['expr']),
    ];
    $request = static fn (int $seq, string $panel, string $title, string $refId, array $frames): array => [
        'seq' => $seq, 'panelId' => $panel, 'panelTitle' => rawurlencode($title), 'pluginId' => 'stat', 'dashboardUid' => 't17-off', 'dsType' => 'prometheus', 'url' => '/api/ds/query',
        'body' => ['from' => '1740962400000', 'to' => '1740966000000', 'queries' => [['refId' => $refId, 'expr' => $change['expr'], 'intervalMs' => 15000, 'maxDataPoints' => 258]]],
        'status' => 200, 'response' => ['results' => [$refId => ['status' => 200, 'frames' => $frames]]], 'error' => null,
    ];
    $jobs = [
        'schema' => ['fields' => [['name' => 'Completed', 'type' => 'time'], ['name' => 'Job', 'type' => 'string'], ['name' => 'Runtime', 'type' => 'number']]],
        'data' => ['values' => [[$change['completedMs'], 1740962460000], ['<a href="/horizon/jobs/completed/'.$change['uuid'].'">Invoice</a>', 'Invoice'], [$change['runtime'], 0.001]]],
    ];
    $dom = static fn (string $key, array $lines, ?array $rows = null): array => ['key' => $key, 'panelId' => $key, 'title' => $lines[0], 'lines' => $lines, ...($rows === null ? [] : ['rows' => $rows]), 'sparkline' => false, 'error' => false, 'noData' => false];
    $path = tempnam(sys_get_temp_dir(), 'ho-t17-determinism');
    file_put_contents($path, json_encode([
        'schema' => 1, 'case' => 'S01', 'grafana' => '13', 'grafanaVersion' => '13.2.4', 'url' => 'http://x/d/t17-off/', 'fakeNow' => 1740966000000,
        'complete' => true, 'incomplete' => [], 'rows' => [], 'released' => ['atMs' => 1, 'requests' => 3],
        'requests' => [
            $request(1, '8', 'Completed', 'A', $change['order'] ? array_reverse($series) : $series),
            $request(2, '6', 'Jobs', 'F', [dashboardTruthFrame(['job_class' => 'Truth\\Invoice'], [[1740966000000, $change['duration']]])]),
            $request(3, '18', '✓ Completed Jobs', 'A', [$jobs]),
        ],
        'variables' => [
            ['name' => 'queue', 'type' => 'query', 'value' => [$change['queue']], 'text' => [$change['queue']], 'options' => [['text' => 'default', 'value' => 'default'], ['text' => 'slow', 'value' => 'slow']]],
        ],
        'panels' => [],
        'dom' => [
            'panel-8' => $dom('panel-8', ['Completed', 'RPM: 5', $change['dom8']], []),
            'panel-6' => $dom('panel-6', ['Jobs', ...array_merge(...$rows6)], $change['withRows'] ? $rows6 : null),
        ],
        'router' => [], 'errors' => [], 'timing' => (object) [],
    ], JSON_UNESCAPED_UNICODE));

    try {
        return Observation::read($path);
    } finally {
        unlink($path);
    }
}

it('finds no difference in the order of the rows of a panel sorted by a measured column', function (bool $withRows): void {
    $swapped = ['rows6' => [['Invoice', '0', '30', '$dom6'], ['Born', '0', '0', '829.57 µs']], 'withRows' => $withRows];

    expect(Determinism::differences(dashboardTruthDeterminismObservation(['withRows' => $withRows]), dashboardTruthDeterminismObservation($swapped)))->toBe([]);
})->with(['the table rows' => true, 'the lines of an observation without rows' => false]);

it('finds a cell that moved to another row of a panel sorted by a measured column', function (): void {
    $moved = ['rows6' => [['Born', '0', '30', '823.38 µs'], ['Invoice', '0', '0', '$dom6']]];

    expect(Determinism::differences(dashboardTruthDeterminismObservation(), dashboardTruthDeterminismObservation($moved)))->toBe(['dom panel-6 differs']);
});

it('finds no difference in the order of series, the measured durations and random job ids', function (): void {
    $base = dashboardTruthDeterminismObservation();

    expect(Determinism::differences($base, dashboardTruthDeterminismObservation()))->toBe([])
        ->and(Determinism::differences($base, dashboardTruthDeterminismObservation([
            'order' => true,
            'duration' => 0.0019,
            'runtime' => 0.0011,
            'completedMs' => 1740962430480,
            'uuid' => '0644238a-6909-47a2-bb78-2cf70c3c36a6',
            'dom6' => '871.30 µs',
        ])))->toBe([]);
});

it('finds every other difference', function (array $change, string $difference): void {
    expect(Determinism::differences(dashboardTruthDeterminismObservation(), dashboardTruthDeterminismObservation($change)))->toContain($difference);
})->with([
    'a point' => [['value' => 4.0], 'frame #8 A (Completed) differs'],
    'the sent query' => [['expr' => 'y'], 'query #8 A (Completed) differs'],
    'a job-list stamp beyond the second' => [['completedMs' => 1740962431120], 'frame #18 A (✓ Completed Jobs) differs'],
    'the DOM of a panel without measured values' => [['dom8' => '305'], 'dom panel-8 differs'],
    'a non-duration cell of a measured panel' => [['dom6' => 'Sync'], 'dom panel-6 differs'],
    'a variable' => [['queue' => 'slow'], 'variable queue differs'],
]);
