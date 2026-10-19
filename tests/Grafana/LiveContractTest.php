<?php

declare(strict_types=1);

use DF\HorizonObservability\Grafana\Live\LiveMode;
use DF\HorizonObservability\Grafana\Live\LivePanels;
use DF\HorizonObservability\Grafana\PanelRegistry;
use DF\HorizonObservability\Grafana\Promql;
use DF\HorizonObservability\Grafana\Queries\Selection;
use DF\HorizonObservability\Live\Captions;
use DF\HorizonObservability\Live\LiveScope;
use DF\HorizonObservability\Live\LiveSnapshot;
use DF\HorizonObservability\Live\Stream\LineProtocol;
use DF\HorizonObservability\Tests\Grafana\Support\Rendered;

/*
 * What the live tiles read exists where they read it.
 * - Every column of an Infinity target of `${api_path}/live` is a field of the snapshot JSON (schema v1,
 *   LiveSnapshot::toArray(); the `tiles` root: LiveSnapshot::tiles()) with the column's type, in every snapshot shape
 *   the endpoint answers (a seeded one, a Job filter, nothing running, an unavailable build).
 * - Every tile row field and tile name of TILE_ROWS is one that LiveSnapshot::tiles() and the `tiles` measurement of
 *   LineProtocol carry, `value` in every row (else the stat shows `level`); the null sentinel is the one the line
 *   protocol pushes.
 * - The stream channels are the measurements the stream command pushes, the delayed sparkline holds one push and the
 *   500 ms buffer the rows of one push.
 * - The Prometheus targets of the live renders are targets of the off render, unchanged, so every truth test of the off
 *   render holds for them.
 */

pest()->group('grafana');

/**
 * The snapshot shapes the endpoint answers.
 *
 * @return array<string, LiveSnapshot>
 */
function liveSnapshots(): array
{
    $fixture = json_decode((string) file_get_contents(__DIR__.'/../Fixtures/live/snapshot.json'), true, flags: JSON_THROW_ON_ERROR);
    $queues = [['connection' => 'redis', 'queue' => 'default']];
    $supervisor = ['name' => 'supervisor-default', 'connection' => 'redis', 'balance' => 'auto', 'processes' => 3, 'min_processes' => 1, 'max_processes' => 3, 'busy' => 1, 'queues' => ['default']];
    $queue = ['connection' => 'redis', 'queue' => 'default', 'pending' => 7, 'reserved' => 1, 'delayed' => 2, 'delayed_due' => 1, 'oldest_seconds' => 17.4,
        'wait_threshold_seconds' => 60, 'completed_per_minute' => 40, 'failed_per_minute' => 1.0, 'supervisors' => ['supervisor-default']];

    return [
        'seeded (tests/Fixtures/live/snapshot.json)' => LiveSnapshot::fromArray($fixture),
        'a Job filter' => LiveSnapshot::compose(new LiveScope(null, $queues, true, ['App\\Jobs\\Mail']), 1792201716123, 1000, 1, [$supervisor], [$queue], ['completed' => 3, 'failed' => 0, 'retried' => 0]),
        'Horizon stopped, no ring data' => LiveSnapshot::compose(new LiveScope(null, $queues, false, null), 1792201716123, 1000, -1, [$supervisor], [$queue], null),
        'nothing in scope' => LiveSnapshot::empty(new LiveScope(null, [], false, null), 1792201716123, 1000),
        'unavailable' => LiveSnapshot::unavailable(new LiveScope(null, $queues, false, null), 1792201716123, 1000, [['source' => 'redis', 'message' => 'RedisException: down']]),
    ];
}

/**
 * The live snapshot targets of a committed render: [panel id, target].
 *
 * @return list<array{0: int, 1: array<string, mixed>}>
 */
function snapshotTargets(LiveMode $mode): array
{
    $targets = [];

    foreach (PanelRegistry::panels(Rendered::dashboard($mode)) as $panel) {
        foreach ($panel['targets'] ?? [] as $target) {
            if (str_starts_with($target['url'] ?? '', LivePanels::URL.'?')) {
                $targets[] = [$panel['id'], $target];
            }
        }
    }

    return $targets;
}

/**
 * Whether $value fits an Infinity column type.
 */
function fitsColumn(mixed $value, string $type): bool
{
    return match ($type) {
        'timestamp_epoch' => is_int($value) && $value > 1_000_000_000_000,
        'number' => $value === null || is_int($value) || is_float($value),
        'string' => is_string($value),
        default => false,
    };
}

/**
 * The fields of the `tiles` and `delayed` rows of a line protocol payload.
 *
 * @return array{tiles: list<list<string>>, delayed: list<list<string>>}
 */
function lineFields(string $payload): array
{
    $rows = ['tiles' => [], 'delayed' => []];

    foreach (array_filter(explode("\n", $payload)) as $line) {
        // measurement fields timestamp; string field values are quoted and never contain a newline
        preg_match('/^(\w+) (.*) (\d+)$/', $line, $match);
        preg_match_all('/(?:^|,)(\w+)=(?:"(?:[^"\\\\]|\\\\.)*"|[^,]*)/', $match[2], $fields);
        $rows[$match[1]][] = $fields[1];
    }

    return $rows;
}

it('reads only fields of the snapshot JSON with their types (schema v1)', function (LiveMode $mode): void {
    $read = [];

    foreach (liveSnapshots() as $shape => $snapshot) {
        $document = $snapshot->toArray();

        foreach (snapshotTargets($mode) as [$id, $target]) {
            $rows = $target['root_selector'] === '' ? [$document] : $document[$target['root_selector']];

            expect($target['root_selector'])->toBeIn(['', 'tiles', 'workload']);

            foreach ($rows as $row) {
                foreach ($target['columns'] as $column) {
                    $value = $row;

                    foreach (explode('.', $column['selector']) as $key) {
                        expect(is_array($value) && array_key_exists($key, $value))->toBeTrue($shape.': panel '.$id.' reads '.$column['selector']);
                        $value = $value[$key];
                    }

                    expect(fitsColumn($value, $column['type']))->toBeTrue($shape.': panel '.$id.' '.$column['selector'].' is not '.$column['type']);
                    $read[$column['selector']] = true;
                }
            }
        }
    }

    ksort($read);

    expect(array_keys($read))->toBe(match ($mode) {
        // The tiles, and the queue source's workload rows (delayed, oldest_cell, pending, processes, queue, utilization)
        LiveMode::Poll => ['caption', 'color', 'delayed', 'delayed.value', 'generated_at', 'horizon.status', 'key', 'oldest_cell', 'pending', 'pending.caption', 'pending.color', 'pending.value', 'processes', 'queue', 'throughput.caption', 'tile', 'utilization', 'value'],
        LiveMode::Stream => ['delayed', 'generated_at', 'horizon.status', 'oldest_cell', 'pending', 'processes', 'queue', 'throughput.caption', 'utilization'],
        LiveMode::Off => [],
    });
})->with(Rendered::modes());

it('names the merged live columns as the Prometheus frame: Time and Value', function (): void {
    // merge appends the live row to the Prometheus series only when the field names agree
    foreach (snapshotTargets(LiveMode::Poll) as [$id, $target]) {
        if ($target['refId'] === 'L') {
            expect(array_column($target['columns'], 'text'))->toBe(['Time', 'Value'], 'panel '.$id)
                ->and($target['columns'][0])->toBe(['selector' => 'generated_at', 'text' => 'Time', 'type' => 'timestamp_epoch']);
        }
    }
});

it('groups the tile rows the snapshot and the line protocol carry, with a value in every row', function (LiveMode $mode): void {
    $snapshot = liveSnapshots()['seeded (tests/Fixtures/live/snapshot.json)'];
    $tiles = array_values(array_unique(array_column($snapshot->tiles(), 'tile')));
    $pushed = lineFields(LineProtocol::encode($snapshot, 1792201716123000000, []))['tiles'];
    $unavailable = lineFields(LineProtocol::unavailable(1792201716123000000, [['tile' => 'pending', 'key' => ''], ['tile' => 'supervisor', 'key' => 'supervisor-default']]))['tiles'];
    $grouped = 0;

    foreach (PanelRegistry::panels(Rendered::dashboard($mode)) as $panel) {
        if (($panel['transformations'][0]['id'] ?? null) !== 'filterByValue') {
            continue;
        }

        $grouped++;
        $tile = $panel['transformations'][0]['options']['filters'][0]['config']['options']['value'];
        $fields = ['tile', ...array_keys($panel['transformations'][1]['options']['fields'])];

        expect($tiles)->toContain($tile)
            ->and(array_values(array_unique($fields)))->toBe(LivePanels::TILE_FIELDS);

        foreach ([...$pushed, ...$unavailable] as $row) {
            expect(array_diff(LivePanels::TILE_FIELDS, $row))->toBe([], implode(',', $row));
        }

        foreach ($snapshot->tiles() as $row) {
            expect(array_diff(LivePanels::TILE_FIELDS, array_keys($row)))->toBe([]);
        }
    }

    expect($grouped)->toBe(match ($mode) {
        LiveMode::Off => 0,
        LiveMode::Poll => 1,
        LiveMode::Stream => 2,
    });
})->with(Rendered::modes());

it('shows the null the line protocol pushes as a dash', function (): void {
    $sentinel = LivePanels::sentinel();

    // A pushed null value is -1.0; Grafana shows the number -1, which the value mapping matches
    expect(LineProtocol::number(null))->toBe('-1.0')
        ->and(array_keys($sentinel['options']))->toBe([(int) LineProtocol::NULL_VALUE])
        ->and($sentinel['options'][-1]['text'])->toBe(Captions::DASH)
        ->and($sentinel['options'][-1]['color'])->toBe(Captions::TEXT);

    foreach ([LiveMode::Poll, LiveMode::Stream] as $mode) {
        foreach (PanelRegistry::panels(Rendered::dashboard($mode)) as $panel) {
            if (($panel['transformations'][0]['id'] ?? null) === 'filterByValue') {
                expect($panel['fieldConfig']['defaults']['mappings'])->toContain(json_decode(json_encode($sentinel, JSON_THROW_ON_ERROR), true));
            }
        }
    }
});

it('subscribes to the measurements the stream command pushes', function (): void {
    $channels = [];
    $snapshot = liveSnapshots()['seeded (tests/Fixtures/live/snapshot.json)'];
    $now = 1792201716123;
    $window = array_map(static fn (int $i): array => [$now - 5000 * $i, (float) $i], range(1, 200));
    $payload = lineFields(LineProtocol::encode($snapshot, $now * 1000000, $window));

    foreach (PanelRegistry::panels(Rendered::dashboard(LiveMode::Stream)) as $panel) {
        foreach ($panel['targets'] ?? [] as $target) {
            if (isset($target['channel'])) {
                $channels[$panel['id']] = $target['channel'];

                expect($target['datasource'])->toBe(['type' => 'datasource', 'uid' => 'grafana'])
                    ->and($target['queryType'])->toBe('measurements');
            }
        }

        if ($panel['id'] === PanelRegistry::DELAYED) {
            // The whole window of one push: its sparkline seeds the 15 minutes on page open
            expect($panel['maxDataPoints'])->toBe(count($payload['delayed']))
                ->and(count($payload['delayed']))->toBe(LineProtocol::WINDOW_POINTS + 1)
                ->and(LineProtocol::WINDOW_POINTS * 5)->toBe(15 * 60 - 5)
                ->and($panel['timeFrom'])->toBe('15m');
        }

        if (($panel['targets'][0]['buffer'] ?? null) !== null) {
            // The rows of one push are 1 ms apart: the buffer holds them all, and fewer than 500 rows fit any push
            expect($panel['targets'][0]['buffer'])->toBe(LivePanels::BUFFER_MS)
                ->and((count($payload['tiles']) - 1) * LineProtocol::ROW_SPACING_NS / 1e6)->toBeLessThan(LivePanels::BUFFER_MS);
        }
    }

    expect($channels)->toBe([
        PanelRegistry::SUPERVISOR_PROCESSES => 'stream/horizon_observability/'.LineProtocol::TILES,
        PanelRegistry::PENDING => 'stream/horizon_observability/'.LineProtocol::TILES,
        PanelRegistry::DELAYED => 'stream/horizon_observability/'.LineProtocol::DELAYED,
    ])
        ->and(array_unique(array_merge(...$payload['delayed'])))->toBe(['value']);
});

it('reads the Prometheus targets of the off render unchanged', function (LiveMode $mode): void {
    $off = [];

    foreach (PanelRegistry::panels(Rendered::dashboard(LiveMode::Off)) as $panel) {
        foreach ($panel['targets'] ?? [] as $target) {
            $off[$panel['id'].' '.$target['refId']] = $target;
        }
    }

    $prometheus = 0;

    foreach (PanelRegistry::panels(Rendered::dashboard($mode)) as $panel) {
        foreach ($panel['targets'] ?? [] as $target) {
            if (($target['datasource']['type'] ?? null) === 'prometheus') {
                $prometheus++;
                $expected = $off[$panel['id'].' '.$target['refId']] ?? null;

                // The Alerts tile and the alert sound also watch the stall rule in stream mode
                if ($mode === LiveMode::Stream && is_array($expected)) {
                    $expected['expr'] = str_replace('-(no-metrics|down|long-wait|failures)"', '-(no-metrics|down|long-wait|failures|live)"', $expected['expr']);
                }

                // The Completed RPM of the off render serves only a Job or Batch filter, whose snapshot has no
                // throughput
                if ($mode !== LiveMode::Off && $panel['id'] === PanelRegistry::COMPLETED && $target['refId'] === 'B' && is_array($expected)) {
                    $expected['expr'] = Promql::unlessOn(Promql::paren($expected['expr']), Selection::allJobsSelected());
                }

                expect($target)->toBe($expected, 'panel '.$panel['id'].' '.$target['refId']);
            }
        }
    }

    $offPrometheus = count(array_filter($off, static fn (array $target): bool => ($target['datasource']['type'] ?? null) === 'prometheus'));

    expect($prometheus)->toBe(match ($mode) {
        LiveMode::Off => $offPrometheus,
        // Without #1 N, #3 A and B, #2 B and C (#8 B stays, for a Job or Batch filter), and the queue source's
        // columns of now (#24 A, B, D, F, G), which read the snapshot
        LiveMode::Poll => $offPrometheus - 5 - 5,
        // and without #2 A, #9 A
        LiveMode::Stream => $offPrometheus - 7 - 5,
    });
})->with(Rendered::modes());
