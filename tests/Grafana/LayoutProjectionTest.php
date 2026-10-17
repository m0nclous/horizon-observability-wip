<?php

declare(strict_types=1);

use DF\HorizonObservability\Grafana\DashboardBuilder;
use DF\HorizonObservability\Grafana\DashboardOptions;
use DF\HorizonObservability\Grafana\PanelRegistry;
use DF\HorizonObservability\Tests\Grafana\Support\LayoutProjection;
use DF\HorizonObservability\Tests\Grafana\Support\LegacyFixtures;

/*
 * The layout projection (Arch Q5.6, Q5.7) must not change while WP8B rewrites the queries: panel ids, types,
 * titles, grid positions, repeat options and row membership, and the look of every panel. The reference is the
 * projection of the legacy golden, frozen in tests/Fixtures/grafana/default/layout-projection.json.
 */

pest()->group('grafana');

function legacyDashboard(): array
{
    return LayoutProjection::decoded((new DashboardBuilder(LegacyFixtures::options()))->build());
}

it('pins the layout', function (): void {
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
    ], LayoutProjection::layout(legacyDashboard()));

    // id, type, title, [h, w, x, y], row, repeat, repeatDirection, maxPerRow, collapsed
    expect($rows)->toBe([
        [23, 'marcusolsson-dynamictext-panel', '', [1, 24, 0, 0], null, null, null, null, null],
        [10, 'row', 'Overview', [1, 24, 0, 1], null, null, null, null, false],
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
});

it('freezes the projection of the legacy golden', function (): void {
    // The frozen file outlives tests/Fixtures/grafana/legacy (Arch Q5.6: deleted at the end of WP8B)
    $golden = json_decode(LegacyFixtures::read('queues.json'), true, flags: JSON_THROW_ON_ERROR);

    expect(LayoutProjection::frozen())->toBe(LayoutProjection::full($golden));
});

it('keeps the layout and the look of the legacy dashboard', function (string $profile): void {
    $options = $profile === 'legacy' ? LegacyFixtures::options() : new DashboardOptions;
    $dashboard = LayoutProjection::decoded((new DashboardBuilder($options))->build());

    expect(LayoutProjection::full($dashboard))->toBe(LayoutProjection::frozen());
})->with(['legacy', 'package defaults']);

it('fills the Overview row with tiles from edge to edge', function (): void {
    $tiles = array_values(array_filter(LayoutProjection::layout(legacyDashboard()), static fn (array $panel): bool => $panel['row'] === PanelRegistry::OVERVIEW_ROW));
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
    $dashboard = legacyDashboard();
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
    $ids = array_column(LayoutProjection::layout(legacyDashboard()), 'id');
    $registry = (new ReflectionClass(PanelRegistry::class))->getConstants();
    sort($ids);
    sort($registry);

    expect($ids)->toBe($registry)
        ->and(PanelRegistry::idOf(legacyDashboard(), 'Failed'))->toBe(PanelRegistry::FAILED);
});
