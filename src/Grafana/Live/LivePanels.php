<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Grafana\Live;

use DF\HorizonObservability\Grafana\Glyph;
use DF\HorizonObservability\Grafana\PanelRegistry;
use DF\HorizonObservability\Grafana\Panels\Common;
use DF\HorizonObservability\Grafana\Panels\Tiles;
use DF\HorizonObservability\Grafana\Promql;
use DF\HorizonObservability\Grafana\Queries\Selection;
use DF\HorizonObservability\Live\Captions;
use DF\HorizonObservability\Live\Stream\LineProtocol;
use LogicException;
use stdClass;

/**
 * The live variants of the Overview tiles, made from their `--live=off` panels: only the datasource, the queries,
 * the transformations and the few properties described below change, so gridPos, field defaults, colours, text mode
 * and caption size stay those of the off render.
 *
 * Poll mode:
 * - #1 Horizon is Mixed: the Prometheus band A with the snapshot's status L appended to it (`merge`, so the shown
 *   value and its colour are the current status, and Prometheus' when the live status is null or the call fails),
 *   and the Prometheus uptime and mappings B; the off render merges Prometheus' current status N instead.
 * - #3 Supervisor Processes reads the snapshot's `tiles[]` (Infinity) through {@see tileRows()}: one value per
 *   supervisor with the caption "name \u{00B7} max N" and the utilisation colour; no sparkline.
 * - #2 Pending is Mixed: the Prometheus sparkline A, the live value L appended to it (`merge`, so the last value is
 *   the live one, and Prometheus' when the live value is null: a Job or Batch filter, or an error) and the live
 *   caption and colour C (`configFromData`).
 * - #9 Delayed is Mixed: the Prometheus sparkline A and the live value L.
 * - #8 Completed keeps its Prometheus number and sparkline A and takes the caption "RPM: n" from the snapshot; with a
 *   Job or Batch filter, whose snapshot has no throughput, the off-mode Prometheus RPM B of the selected classes.
 *
 * The live captions have a fallback (`fieldConfig.defaults.displayName`, which "Config from query results"
 * overrides): when the snapshot call fails (no live endpoint, no Infinity datasource, the application unreachable, a
 * token without access) Grafana would otherwise name the value after its query and show the PromQL.
 *
 * Stream mode: #3 and #2 read the Grafana Live channel `stream/<id>/tiles` through {@see tileRows()}, #9 the windowed
 * channel `stream/<id>/delayed` (15 minutes at 5 s), and #1 and #8 are the poll panels (Mixed cannot carry a stream).
 * The other tiles read Prometheus in every mode.
 *
 * The queue tables (Current Workload) read their columns of now (Utilization, Processes, Pending, Delayed, Oldest
 * wait) from the snapshot's `workload` in both live modes, through the hidden queue source #24
 * ({@see queueSource()}); the rows and the totals of the period stay Prometheus queries.
 *
 * Every column and field a panel reads is a field of the snapshot JSON (`LiveSnapshot::toArray()`, `tiles()`) or of
 * the line protocol (`LineProtocol`); `tests/Grafana/LiveContractTest` holds them to it.
 *
 * @internal
 */
final readonly class LivePanels
{
    /**
     * The snapshot endpoint relative to the Infinity datasource URL, like the job lists (`${api_path}/jobs/<list>`).
     */
    public const URL = '${api_path}/live';

    /**
     * The dashboard filters the endpoint scopes the snapshot by (`JobListQuery::VARIABLES`), as `${var:queryparam}`.
     */
    public const FILTERS = ['supervisor', 'batch_kind', 'queue', 'job_class'];

    /**
     * The fields of a tile row, the snapshot's `tiles[]` and the `tiles` measurement alike; `level` is not read.
     */
    public const TILE_FIELDS = ['tile', 'key', 'caption', 'value', 'color'];

    /**
     * The tile rows of #3 and #2 (`LiveSnapshot::tiles()`).
     */
    public const SUPERVISOR_TILE = 'supervisor';

    public const PENDING_TILE = 'pending';

    /**
     * Stream tiles keep 500 ms of rows (`maxDelta`): with pushes 1 s apart and the rows of one push stamped 1 ms
     * apart, exactly the latest push.
     */
    public const BUFFER_MS = 500;

    /**
     * The stream Delayed sparkline: the whole window of one push (`LineProtocol::WINDOW_POINTS` plus the current
     * value) over the 15 minutes it spans, whatever the dashboard range.
     */
    public const DELAYED_POINTS = LineProtocol::WINDOW_POINTS + 1;

    public const DELAYED_TIME_FROM = '15m';

    private const MIXED = '-- Mixed --';

    /**
     * The Prometheus queries of the queue source that the live renders replace with the snapshot: utilisation (A),
     * pending (B), processes (D), the Oldest wait cell (F) and delayed (G).
     */
    public const QUEUE_SOURCE_LIVE_REFS = ['A', 'B', 'D', 'F', 'G'];

    /**
     * The Horizon tile's snapshot query, the live status merged into its band in place of the off render's query N.
     */
    public const HORIZON_REF = 'L';

    /**
     * The queue source's snapshot query (W for workload; L is a live value merged into a tile's series).
     */
    public const WORKLOAD_REF = 'W';

    /**
     * The queue source's Prometheus queries in the live renders, the frames the queue tables merge before they join W.
     */
    public const QUEUE_SOURCE_PROMETHEUS_REFS = 'M|C|K|Z';

    /**
     * The snapshot's `workload` rows as the queue source's columns, named like the Prometheus fields they replace,
     * so the tables' merge and organize steps stay those of the off render.
     */
    public const WORKLOAD_COLUMNS = [
        ['queue', 'queue', 'string'],
        ['utilization', 'Value #A', 'number'],
        ['processes', 'Value #D', 'number'],
        ['pending', 'Value #B', 'number'],
        ['delayed', 'Value #G', 'number'],
        ['oldest_cell', 'cell', 'string'],
    ];

    public function __construct(private LiveMode $mode, private string $streamId) {}

    /**
     * The Overview tiles of this mode, given their `--live=off` panels in order.
     *
     * @param  list<array<string, mixed>>  $tiles
     * @return list<array<string, mixed>>
     */
    public function tiles(array $tiles): array
    {
        if (! $this->mode->isLive()) {
            return $tiles;
        }

        return array_map(fn (array $tile): array => match ($tile['id'] ?? null) {
            PanelRegistry::HORIZON => $this->horizon($tile),
            PanelRegistry::SUPERVISOR_PROCESSES => $this->supervisorProcesses($tile),
            PanelRegistry::PENDING => $this->pending($tile),
            PanelRegistry::COMPLETED => $this->completed($tile),
            PanelRegistry::DELAYED => $this->delayed($tile),
            default => $tile,
        }, $tiles);
    }

    /**
     * The hidden queue source #24 of this mode, given its `--live=off` panel: in poll and stream modes a Mixed panel
     * whose Prometheus queries keep the rows (M), the totals of the period (C, K) and the stub row (Z), and whose
     * snapshot query W brings the columns of now; the tables join W by queue ({@see queueTable()}). Stream mode polls
     * too (a table cannot carry a stream), at the dashboard refresh.
     *
     * @param  array<string, mixed>  $panel
     * @return array<string, mixed>
     */
    public function queueSource(array $panel): array
    {
        if (! $this->mode->isLive()) {
            return $panel;
        }

        $targets = array_values(array_filter(
            is_array($panel['targets'] ?? null) ? $panel['targets'] : [],
            static fn (mixed $target): bool => is_array($target) && ! in_array($target['refId'] ?? null, self::QUEUE_SOURCE_LIVE_REFS, true),
        ));
        $targets[] = self::snapshotTarget(self::WORKLOAD_REF, 'workload', self::WORKLOAD_COLUMNS);

        return $this->changed($panel, ['datasource' => self::mixed(), 'targets' => $targets]);
    }

    /**
     * The queue table #5 of this mode, given its `--live=off` panel: in poll and stream modes the merge takes only the
     * Prometheus frames of the source and the snapshot rows W join its result by queue. One merge of all frames would
     * not join: Grafana's merge keys the rows on the fields every frame has, which is `Time` for the Prometheus frames
     * (the stub Z has no queue when it is empty) and nothing once W, which has no time, is among them. The outer
     * tabular join keeps every (queue, supervisor) row, with empty columns of now when the snapshot is unavailable.
     *
     * @param  array<string, mixed>  $panel
     * @return array<string, mixed>
     */
    public function queueTable(array $panel): array
    {
        if (! $this->mode->isLive()) {
            return $panel;
        }

        $transformations = [];

        foreach (is_array($panel['transformations'] ?? null) ? $panel['transformations'] : [] as $transformation) {
            if (is_array($transformation) && ($transformation['id'] ?? null) === 'merge') {
                $transformations[] = [...$transformation, 'filter' => Common::byRefId(self::QUEUE_SOURCE_PROMETHEUS_REFS)];
                // The snapshot columns keep their names through the join: without a display name of their own, a joined
                // field is named after its query too ("Value #A W"), which the table's organize would not find
                $names = array_column(self::WORKLOAD_COLUMNS, 1);
                $transformations[] = [
                    'id' => 'organize',
                    'filter' => Common::byRefId(self::WORKLOAD_REF),
                    'options' => ['excludeByName' => new stdClass, 'includeByName' => new stdClass, 'indexByName' => new stdClass, 'renameByName' => array_combine($names, $names)],
                ];
                $transformations[] = ['id' => 'joinByField', 'options' => ['byField' => 'queue', 'mode' => 'outerTabular']];

                continue;
            }

            $transformations[] = $transformation;
        }

        return $this->changed($panel, ['transformations' => $transformations]);
    }

    /**
     * The Grafana Live channel of a measurement of the stream command: `stream/<id>/tiles`, `stream/<id>/delayed`.
     */
    public function channel(string $measurement): string
    {
        return 'stream/'.$this->streamId.'/'.$measurement;
    }

    /**
     * `TILE_ROWS(t)`, shared by the poll and stream tiles: the rows of tile $tile, the last one per (`tile`, `key`),
     * one field each, named by its caption and coloured by its colour. `groupBy` removes the duplicate fields of a
     * live panel that re-subscribes after a refresh and drops `time`, `labels` and `level`, which `rowsToFields` would
     * turn into labels.
     *
     * @return list<array<string, mixed>>
     */
    public static function tileRows(string $tile): array
    {
        $last = ['operation' => 'aggregate', 'aggregations' => ['last']];

        return [
            ['id' => 'filterByValue', 'options' => ['type' => 'include', 'match' => 'any', 'filters' => [
                ['fieldName' => 'tile', 'config' => ['id' => 'equal', 'options' => ['value' => $tile]]],
            ]]],
            ['id' => 'groupBy', 'options' => ['fields' => [
                'tile' => ['operation' => 'groupby'],
                'key' => ['operation' => 'groupby'],
                'caption' => $last,
                'value' => $last,
                'color' => $last,
            ]]],
            ['id' => 'rowsToFields', 'options' => ['mappings' => [
                Common::handler('caption (last)', 'field.name'),
                Common::handler('value (last)', 'field.value'),
                Common::handler('color (last)', 'color'),
                Common::handler('tile', '__ignore'),
                Common::handler('key', '__ignore'),
            ]]],
        ];
    }

    /**
     * `SENTINEL`: the line protocol pushes a null value as -1, which a stream tile shows as a dash.
     *
     * @return array<string, mixed>
     */
    public static function sentinel(): array
    {
        return ['type' => 'value', 'options' => [
            (string) LineProtocol::NULL_VALUE => ['text' => Glyph::DASH, 'color' => Captions::TEXT, 'index' => 0],
        ]];
    }

    /**
     * Grafana's built-in `-- Grafana --` datasource, which reads Grafana Live channels.
     *
     * @return array<string, string>
     */
    public static function grafana(): array
    {
        return ['type' => 'datasource', 'uid' => 'grafana'];
    }

    /**
     * @return array<string, string>
     */
    public static function mixed(): array
    {
        return ['type' => 'datasource', 'uid' => self::MIXED];
    }

    /**
     * An Infinity query of the snapshot endpoint with the dashboard filters: the fields at the $root of the JSON
     * (`""` for the document, `tiles` for the tile rows) as columns, `[selector, name, type]` each.
     *
     * @param  list<array{0: string, 1: string, 2: string}>  $columns
     * @return array<string, mixed>
     */
    public static function snapshotTarget(string $refId, string $root, array $columns): array
    {
        $filters = implode('&', array_map(static fn (string $name): string => '${'.$name.':queryparam}', self::FILTERS));

        return [
            'refId' => $refId,
            'datasource' => Common::infinity(),
            'type' => 'json',
            'source' => 'url',
            'parser' => 'backend',
            'format' => 'table',
            'url' => self::URL.'?'.$filters,
            'url_options' => ['method' => 'GET', 'data' => ''],
            'root_selector' => $root,
            'columns' => array_map(static fn (array $column): array => ['selector' => $column[0], 'text' => $column[1], 'type' => $column[2]], $columns),
        ];
    }

    /**
     * #1: the Prometheus band and uptime with the live status as the last point, in poll and stream modes.
     *
     * @param  array<string, mixed>  $panel
     * @return array<string, mixed>
     */
    private function horizon(array $panel): array
    {
        return $this->changed($panel, [
            'datasource' => self::mixed(),
            'targets' => [
                $this->prometheusTarget($panel, 'A'),
                $this->prometheusTarget($panel, 'B'),
                self::snapshotTarget(self::HORIZON_REF, '', [['generated_at', 'Time', 'timestamp_epoch'], ['horizon.status', 'Value', 'number']]),
            ],
            'transformations' => Tiles::horizonTransformations(self::HORIZON_REF),
        ]);
    }

    /**
     * #3: the processes of each supervisor (the busy workers of the selected jobs with a Job or Batch filter), live.
     *
     * @param  array<string, mixed>  $panel
     * @return array<string, mixed>
     */
    private function supervisorProcesses(array $panel): array
    {
        return $this->tileRowsPanel($panel, self::SUPERVISOR_TILE);
    }

    /**
     * #2: poll keeps the Prometheus sparkline and takes the live value, caption and colour; stream reads the live
     * tile row only (Mixed cannot carry a stream).
     *
     * @param  array<string, mixed>  $panel
     * @return array<string, mixed>
     */
    private function pending(array $panel): array
    {
        if ($this->mode === LiveMode::Stream) {
            return $this->tileRowsPanel($panel, self::PENDING_TILE);
        }

        return $this->changed($panel, [
            'datasource' => self::mixed(),
            'fieldConfig' => $this->withDisplayName($panel, Captions::oldest(null, null)),
            'targets' => [
                $this->prometheusTarget($panel, 'A'),
                self::snapshotTarget('L', '', [['generated_at', 'Time', 'timestamp_epoch'], ['pending.value', 'Value', 'number']]),
                self::snapshotTarget('C', '', [['pending.caption', 'caption', 'string'], ['pending.color', 'color', 'string']]),
            ],
            'transformations' => [
                $this->mergeLive(),
                $this->configFrom('C', [Common::handler('caption', 'displayName'), Common::handler('color', 'color')]),
            ],
        ]);
    }

    /**
     * #8: the Prometheus number and sparkline with the live caption "RPM: n" (the last complete minute of the ring
     * buffer), in poll and stream modes. A Job or Batch filter leaves the snapshot without throughput, so the caption
     * then comes from the off-mode query B, the Prometheus RPM of the selected classes, which is empty without a
     * filter. Both captions apply to A only: a caption on the other config frame would hide its field from the next
     * "Config from query results".
     *
     * @param  array<string, mixed>  $panel
     * @return array<string, mixed>
     */
    private function completed(array $panel): array
    {
        $rpm = $this->prometheusTarget($panel, 'B');
        $rpm['expr'] = Promql::unlessOn(Promql::paren(is_string($rpm['expr'] ?? null) ? $rpm['expr'] : ''), Selection::allJobsSelected());
        $transformations = is_array($panel['transformations'] ?? null) ? array_values($panel['transformations']) : [];

        return $this->changed($panel, [
            'datasource' => self::mixed(),
            'fieldConfig' => $this->withDisplayName($panel, Captions::rpm(null)),
            'targets' => [
                $this->prometheusTarget($panel, 'A'),
                $rpm,
                self::snapshotTarget('C', '', [['throughput.caption', 'caption', 'string']]),
            ],
            'transformations' => [Common::configFromData('C', [Common::handler('caption', 'displayName')]), ...$transformations],
        ]);
    }

    /**
     * The panel's field config with the fallback caption $name.
     *
     * @param  array<string, mixed>  $panel
     * @return array<string, mixed>
     */
    private function withDisplayName(array $panel, string $name): array
    {
        $fieldConfig = is_array($panel['fieldConfig'] ?? null) ? $panel['fieldConfig'] : [];
        $defaults = is_array($fieldConfig['defaults'] ?? null) ? $fieldConfig['defaults'] : [];
        $defaults['displayName'] = $name;
        $fieldConfig['defaults'] = $defaults;

        return $fieldConfig;
    }

    /**
     * #9: poll appends the live value to the Prometheus sparkline; stream shows the pushed 15-minute window.
     *
     * @param  array<string, mixed>  $panel
     * @return array<string, mixed>
     */
    private function delayed(array $panel): array
    {
        if ($this->mode === LiveMode::Stream) {
            return $this->changed($panel, [
                'datasource' => self::grafana(),
                'targets' => [$this->streamTarget(LineProtocol::DELAYED)],
                'maxDataPoints' => self::DELAYED_POINTS,
                'timeFrom' => self::DELAYED_TIME_FROM,
                'hideTimeOverride' => true,
            ]);
        }

        return $this->changed($panel, [
            'datasource' => self::mixed(),
            'targets' => [
                $this->prometheusTarget($panel, 'A'),
                self::snapshotTarget('L', '', [['generated_at', 'Time', 'timestamp_epoch'], ['delayed.value', 'Value', 'number']]),
            ],
            'transformations' => [$this->mergeLive()],
        ]);
    }

    /**
     * A tile of tile rows (#3 in both modes, #2 in stream mode): no sparkline, the sentinel mapping.
     *
     * @param  array<string, mixed>  $panel
     * @return array<string, mixed>
     */
    private function tileRowsPanel(array $panel, string $tile): array
    {
        $stream = $this->mode === LiveMode::Stream;
        $fieldConfig = is_array($panel['fieldConfig'] ?? null) ? $panel['fieldConfig'] : [];
        $defaults = is_array($fieldConfig['defaults'] ?? null) ? $fieldConfig['defaults'] : [];
        $mappings = is_array($defaults['mappings'] ?? null) ? array_values($defaults['mappings']) : [];
        $defaults['mappings'] = [...$mappings, self::sentinel()];
        $fieldConfig['defaults'] = $defaults;
        $options = is_array($panel['options'] ?? null) ? $panel['options'] : [];
        $options['graphMode'] = 'none';

        return $this->changed($panel, [
            'datasource' => $stream ? self::grafana() : Common::infinity(),
            'fieldConfig' => $fieldConfig,
            'options' => $options,
            'targets' => [$stream
                ? $this->streamTarget(LineProtocol::TILES, self::BUFFER_MS)
                : self::snapshotTarget('S', 'tiles', array_map(static fn (string $field): array => [$field, $field, $field === 'value' ? 'number' : 'string'], self::TILE_FIELDS)),
            ],
            'transformations' => self::tileRows($tile),
        ]);
    }

    /**
     * A Grafana Live measurements query of a channel of the stream command.
     *
     * @return array<string, mixed>
     */
    private function streamTarget(string $measurement, ?int $buffer = null): array
    {
        $target = [
            'refId' => 'A',
            'datasource' => self::grafana(),
            'queryType' => 'measurements',
            'channel' => $this->channel($measurement),
        ];

        if ($buffer !== null) {
            $target['buffer'] = $buffer;
        }

        return $target;
    }

    /**
     * `merge` of the Prometheus series A and the live row L: one frame, whose last value is the live one.
     *
     * @return array<string, mixed>
     */
    private function mergeLive(): array
    {
        return ['id' => 'merge', 'filter' => Common::byRefId('A|L'), 'options' => new stdClass];
    }

    /**
     * "Config from query results" of query $refId for every other frame (no `applyTo`: the merged frame of A and L
     * has no refId of its own).
     *
     * @param  list<array<string, mixed>>  $mappings
     * @return array<string, mixed>
     */
    private function configFrom(string $refId, array $mappings): array
    {
        return ['id' => 'configFromData', 'options' => ['configRefId' => $refId, 'mappings' => $mappings]];
    }

    /**
     * Prometheus target $refId of the off panel, unchanged.
     *
     * @param  array<string, mixed>  $panel
     * @return array<string, mixed>
     */
    private function prometheusTarget(array $panel, string $refId): array
    {
        foreach (is_array($panel['targets'] ?? null) ? $panel['targets'] : [] as $target) {
            if (is_array($target) && ($target['refId'] ?? null) === $refId) {
                /** @var array<string, mixed> $target */
                return $target;
            }
        }

        throw new LogicException(sprintf('Panel %s has no target %s.', json_encode($panel['id'] ?? null), $refId));
    }

    /**
     * The panel with $changes: existing keys keep their place, new ones follow.
     *
     * @param  array<string, mixed>  $panel
     * @param  array<string, mixed>  $changes
     * @return array<string, mixed>
     */
    private function changed(array $panel, array $changes): array
    {
        return array_replace($panel, $changes);
    }
}
