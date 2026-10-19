<?php

declare(strict_types=1);

use DF\HorizonObservability\Grafana\DashboardBuilder;
use DF\HorizonObservability\Grafana\DashboardOptions;
use DF\HorizonObservability\Grafana\Live\LiveMode;
use DF\HorizonObservability\Grafana\PanelRegistry;
use DF\HorizonObservability\Tests\Grafana\Support\LayoutProjection;
use DF\HorizonObservability\Tests\Grafana\Support\Rendered;

/*
 * The layout projection (Arch Q5.6, Q5.7) must not change while WP8B rewrites the queries: panel ids, types,
 * titles, grid positions, repeat options and row membership, and the look of every panel. The reference is the
 * projection of the pilot's dashboard, frozen in tests/Fixtures/grafana/default/layout-projection.json. The
 * --live=off render keeps it exactly; the poll and stream renders deviate only as ADDENDUM R4.1 lists (owner
 * decision 9): the row title "Live Overview" and the look of the live tiles R4.8 gives.
 */

pest()->group('grafana');

function renderedDashboard(?DashboardOptions $options = null): array
{
    return LayoutProjection::decoded((new DashboardBuilder($options ?? new DashboardOptions))->build());
}

it('pins the layout', function (LiveMode $mode): void {
    $rows = array_map(static fn (array $panel): array => [
        $panel['id'],
        $panel['type'],
        $panel['title'],
        array_values($panel['gridPos']),
        $panel['row'],
        $panel['repeat'] ?? null,
        $panel['repeatDirection'] ?? null,
        $panel['maxPerRow'] ?? null,
        $panel['collapsed'] ?? null,
    ], LayoutProjection::layout(renderedDashboard(new DashboardOptions(live: $mode))));

    // id, type, title, [h, w, x, y], row, repeat, repeatDirection, maxPerRow, collapsed
    expect($rows)->toBe([
        [23, 'marcusolsson-dynamictext-panel', '', [1, 12, 0, 0], null, null, null, null, null],
        [24, 'table', 'Queues source', [1, 12, 12, 0], null, null, null, null, null],
        [10, 'row', $mode === LiveMode::Off ? 'Overview' : 'Live Overview', [1, 24, 0, 1], null, null, null, null, false],
        [1, 'stat', 'Horizon', [5, 2, 0, 2], 10, null, null, null, null],
        [3, 'stat', 'Supervisor Processes', [5, 6, 2, 2], 10, null, null, null, null],
        [2, 'stat', 'Pending', [5, 4, 8, 2], 10, null, null, null, null],
        [8, 'stat', 'Completed', [5, 4, 12, 2], 10, null, null, null, null],
        [9, 'stat', 'Delayed', [5, 3, 16, 2], 10, null, null, null, null],
        [4, 'stat', 'Failed', [5, 3, 19, 2], 10, null, null, null, null],
        [21, 'stat', 'Alerts', [5, 2, 22, 2], 10, null, null, null, null],
        [11, 'row', 'Current Workload', [1, 24, 0, 7], null, null, null, null, false],
        [5, 'table', '${supervisor_title:text}', [5, 24, 0, 8], 11, 'supervisor_title', 'v', null, null],
        [17, 'table', 'Batches', [7, 24, 0, 13], 11, null, null, null, null],
        [12, 'row', "Details \u{00B7} Jobs", [1, 24, 0, 20], null, null, null, null, true],
        [7, 'timeseries', 'Pending by job: $queue', [9, 24, 0, 21], 12, 'queue', 'h', 4, null],
        [14, 'timeseries', 'Completed by job: $queue', [9, 24, 0, 30], 12, 'queue', 'h', 4, null],
        [6, 'table', '', [14, 24, 0, 39], 12, null, null, null, null],
        [19, 'table', "\u{26A0}\u{FE0E} Failed Jobs", [15, 24, 0, 53], 12, null, null, null, null],
        [18, 'table', "\u{2713} Completed Jobs", [30, 14, 0, 68], 12, null, null, null, null],
        [20, 'table', "\u{25F7} Pending Jobs", [15, 10, 14, 68], 12, null, null, null, null],
        [22, 'table', "\u{29D6} Delayed Jobs", [15, 10, 14, 83], 12, null, null, null, null],
        [13, 'row', "Details \u{00B7} Batches", [1, 24, 0, 21], null, null, null, null, true],
        [16, 'table', '${batch_title:text}', [7, 24, 0, 22], 13, 'batch_title', 'v', null, null],
    ]);
})->with(Rendered::modes());

it('keeps the layout and the look of the legacy dashboard', function (DashboardOptions $options): void {
    // The frozen projection of the pilot's dashboard (Arch Q5.6) with the documented WP8C changes: no option may
    // change the look
    $dashboard = renderedDashboard($options);

    expect(LayoutProjection::full($dashboard))->toBe(LayoutProjection::optimised(LayoutProjection::frozen(), $dashboard));
})->with([
    'package defaults, live off' => [new DashboardOptions(live: LiveMode::Off)],
    'another identity, job and prefix' => [new DashboardOptions(metricPrefix: 'acme_', uid: 'queues', title: 'Queues', live: LiveMode::Off, job: 'app')],
    'slow scrapes and health rules' => [new DashboardOptions(scrapeInterval: 60, live: LiveMode::Off, healthRules: true)],
]);

it('keeps the look of the committed off render', function (): void {
    $off = Rendered::dashboard(LiveMode::Off);

    expect(LayoutProjection::full($off))->toBe(LayoutProjection::optimised(LayoutProjection::frozen(), $off));
});

it('changes the look of the live renders only as ADDENDUM R4.1 lists', function (LiveMode $mode, DashboardOptions $options, DashboardOptions $off): void {
    $live = renderedDashboard($options);
    $offDashboard = renderedDashboard($off);
    $expected = LayoutProjection::live($mode, LayoutProjection::optimised(LayoutProjection::frozen(), $offDashboard), $offDashboard);

    expect(LayoutProjection::full($live))->toBe($expected)
        ->and(LayoutProjection::full($live))->not->toBe(LayoutProjection::full($offDashboard));
})->with([
    'poll, the package render' => [LiveMode::Poll, new DashboardOptions, new DashboardOptions(live: LiveMode::Off)],
    'stream' => [LiveMode::Stream, new DashboardOptions(live: LiveMode::Stream), new DashboardOptions(live: LiveMode::Off)],
    'poll, another identity, job and prefix' => [
        LiveMode::Poll,
        new DashboardOptions(metricPrefix: 'acme_', uid: 'queues', job: 'app', livePollInterval: 2),
        new DashboardOptions(metricPrefix: 'acme_', uid: 'queues', live: LiveMode::Off, job: 'app'),
    ],
    'stream at slow scrapes with health rules' => [
        LiveMode::Stream,
        new DashboardOptions(scrapeInterval: 60, live: LiveMode::Stream, healthRules: true),
        new DashboardOptions(scrapeInterval: 60, live: LiveMode::Off, healthRules: true),
    ],
]);

it('keeps the look of the committed live renders but for ADDENDUM R4.1', function (LiveMode $mode): void {
    $off = Rendered::dashboard(LiveMode::Off);

    expect(LayoutProjection::full(Rendered::dashboard($mode)))
        ->toBe(LayoutProjection::live($mode, LayoutProjection::optimised(LayoutProjection::frozen(), $off), $off));
})->with([LiveMode::Poll, LiveMode::Stream]);

it('shows the legacy columns of the queue and job tables in the legacy order', function (): void {
    $frozen = array_column(LayoutProjection::frozen(), null, 'id');

    expect(LayoutProjection::columns($frozen[PanelRegistry::QUEUES]['transformations']))->toBe([
        'Queue', 'Utilization', 'Processes', 'Pending', 'Delayed', 'Oldest wait', 'Wait p95', 'Completed', 'Failed',
        'Timeouts', 'Retries', 'Success rate',
    ])->and(LayoutProjection::columns($frozen[PanelRegistry::JOBS]['transformations']))->toBe([
        'Job', 'Queue', 'Pending', 'Failed', 'Retries', 'Success rate', 'Workers', 'Min', 'Avg', 'p95', 'Max', 'Timeouts',
    ]);
});

it('fills the Overview row with tiles from edge to edge', function (): void {
    $tiles = array_values(array_filter(LayoutProjection::layout(renderedDashboard()), static fn (array $panel): bool => $panel['row'] === PanelRegistry::OVERVIEW_ROW));
    $x = 0;

    foreach ($tiles as $tile) {
        expect($tile['type'])->toBe('stat')
            ->and($tile['gridPos']['x'])->toBe($x)
            ->and($tile['gridPos']['y'])->toBe($tiles[0]['gridPos']['y']);
        $x += $tile['gridPos']['w'];
    }

    expect($x)->toBe(24);
});

it('places no panel over another', function (): void {
    $overlaps = static fn (array $a, array $b): bool => $a['x'] < $b['x'] + $b['w'] && $b['x'] < $a['x'] + $a['w']
        && $a['y'] < $b['y'] + $b['h'] && $b['y'] < $a['y'] + $a['h'];
    $dashboard = renderedDashboard();
    // The dashboard grid, and the grid of each collapsed row (its panels open below it and push the rest down)
    $grids = [$dashboard['panels']];

    foreach ($dashboard['panels'] as $panel) {
        if (($panel['collapsed'] ?? false) === true) {
            $grids[] = $panel['panels'];
        }
    }

    foreach ($grids as $panels) {
        foreach ($panels as $i => $a) {
            foreach (array_slice($panels, $i + 1) as $b) {
                expect($overlaps($a['gridPos'], $b['gridPos']))->toBeFalse("panels {$a['id']} and {$b['id']} overlap");
            }
        }
    }
});

it('gives every panel the id of the registry', function (): void {
    $ids = array_column(LayoutProjection::layout(renderedDashboard()), 'id');
    $registry = (new ReflectionClass(PanelRegistry::class))->getConstants();
    sort($ids);
    sort($registry);

    expect($ids)->toBe($registry)
        ->and(PanelRegistry::idOf(renderedDashboard(), 'Failed'))->toBe(PanelRegistry::FAILED);
});
