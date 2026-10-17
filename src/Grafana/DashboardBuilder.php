<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Grafana;

use DF\HorizonObservability\Grafana\Panels\AlertSound;
use DF\HorizonObservability\Grafana\Panels\Batches;
use DF\HorizonObservability\Grafana\Panels\Common;
use DF\HorizonObservability\Grafana\Panels\JobLists;
use DF\HorizonObservability\Grafana\Panels\Jobs;
use DF\HorizonObservability\Grafana\Panels\Queues;
use DF\HorizonObservability\Grafana\Panels\Tiles;
use DF\HorizonObservability\Grafana\Queries\AlertState;
use DF\HorizonObservability\Grafana\Queries\Attempts;
use DF\HorizonObservability\Grafana\Queries\BatchState;
use DF\HorizonObservability\Grafana\Queries\Selection;
use DF\HorizonObservability\Grafana\Queries\Waits;
use DF\HorizonObservability\Grafana\Queries\Workers;
use LogicException;

/**
 * Renders the dashboard model: panels, layout, variables and links (port of
 * `legacy/grafana/generate_queues_dashboard.py`).
 *
 * Layout: the hidden alert sound panel takes the first grid row; then the Overview row with the tiles, the
 * Current Workload row with the queue tables (one per supervisor) and the batch workload, and two collapsed
 * detail rows. A row owns every panel below it up to the next row, so the tiles and the tables each need their
 * own row; the tables carry no title of their own, their row names them. Collapsed rows do not run their
 * panels' queries. Grafana has no nested rows in this dashboard format, hence two detail rows with a common
 * prefix.
 *
 * @internal
 */
final readonly class DashboardBuilder
{
    /**
     * The dashboard JSON schema version the panels are written for.
     */
    private const SCHEMA_VERSION = 42;

    /**
     * The oldest supported Grafana (owner decision 4).
     */
    public const MIN_GRAFANA = '12.3.0';

    public function __construct(private DashboardOptions $options) {}

    /**
     * @return array<string, mixed>
     */
    public function build(): array
    {
        $this->assertSupported();

        $dashboard = [
            'annotations' => ['list' => [[
                'builtIn' => 1,
                'datasource' => ['type' => 'grafana', 'uid' => '-- Grafana --'],
                'enable' => true,
                'hide' => true,
                'iconColor' => 'rgba(0, 211, 255, 1)',
                'name' => 'Annotations & Alerts',
                'type' => 'dashboard',
            ]]],
            'editable' => true,
            'fiscalYearStartMonth' => 0,
            'graphTooltip' => 0,
            'id' => null,
            'links' => $this->links(),
            'panels' => $this->panels(),
            'preload' => false,
            // Grafana accepts only an interval of timepicker.refresh_intervals
            'refresh' => $this->options->refresh,
            'schemaVersion' => self::SCHEMA_VERSION,
            'tags' => $this->options->tags,
            'templating' => ['list' => $this->variables()],
            'time' => ['from' => 'now-1h', 'to' => 'now'],
            // Refreshing less often than every 5 minutes makes no sense: the dashboard shows the current state
            'timepicker' => ['refresh_intervals' => ['5s', '10s', '15s', '30s', '1m', '5m']],
            'timezone' => 'browser',
            'title' => $this->options->title,
            'uid' => $this->options->uid,
            'version' => 1,
        ];

        // What the dashboard needs, for Grafana's import (Arch Q5.2); file provisioning ignores it
        return $this->options->legacy ? $dashboard : ['__requires' => self::requires(), ...$dashboard];
    }

    /**
     * Grafana, the datasources and the panels the dashboard uses, with the minimum versions that render it: Grafana
     * 12.3 (Business Text 6.3.0 needs it), Infinity 4.1.1 and Business Text 6.3.0 (owner decision 4).
     *
     * @return list<array{type: string, id: string, name: string, version: string}>
     */
    public static function requires(): array
    {
        return [
            ['type' => 'grafana', 'id' => 'grafana', 'name' => 'Grafana', 'version' => self::MIN_GRAFANA],
            ['type' => 'datasource', 'id' => 'prometheus', 'name' => 'Prometheus', 'version' => '1.0.0'],
            ['type' => 'datasource', 'id' => 'yesoreyeram-infinity-datasource', 'name' => 'Infinity', 'version' => '4.1.1'],
            ['type' => 'panel', 'id' => 'marcusolsson-dynamictext-panel', 'name' => 'Business Text', 'version' => '6.3.0'],
            ['type' => 'panel', 'id' => 'stat', 'name' => 'Stat', 'version' => ''],
            ['type' => 'panel', 'id' => 'table', 'name' => 'Table', 'version' => ''],
            ['type' => 'panel', 'id' => 'timeseries', 'name' => 'Time series', 'version' => ''],
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function panels(): array
    {
        $promql = $this->options->promql();
        $selection = new Selection($promql);
        $attempts = new Attempts($promql);
        $workers = new Workers($promql, $selection, $this->options->legacy ? $this->options->busyWindow : Variables::BUSY_WINDOW);
        $waits = new Waits($promql, $selection);
        $batchState = new BatchState($promql);
        $alertState = new AlertState($this->options->alertRules());
        $common = new Common($promql);

        $tiles = new Tiles($common, $promql, $selection, $attempts, $workers, $waits, $alertState);
        $queues = new Queues($promql, $selection, $attempts, $workers, $waits);
        $jobs = new Jobs($common, $promql, $selection, $attempts, $workers);
        $jobLists = new JobLists($this->options);
        $batches = new Batches($common, $promql, $attempts, $batchState);

        $panels = [(new AlertSound($common, $alertState, $this->options->alertSound()))->panel()];
        $y = AlertSound::HEIGHT;

        $panels[] = Common::row(PanelRegistry::OVERVIEW_ROW, 'Overview', $y);
        $y += Common::ROW_HEIGHT;
        array_push($panels, ...$tiles->all($y));
        $y += Tiles::HEIGHT;

        $panels[] = Common::row(PanelRegistry::WORKLOAD_ROW, 'Current Workload', $y);
        $y += Common::ROW_HEIGHT;
        $panels[] = $queues->table($y);
        $y += Queues::HEIGHT;
        $panels[] = $batches->workload($y);
        $y += Batches::WORKLOAD_HEIGHT;

        // Pending and completed by job first, then the Jobs table and the job lists
        $child = $y + Common::ROW_HEIGHT;
        $panels[] = Common::row(PanelRegistry::JOBS_ROW, 'Details '.Glyph::DOT.' Jobs', $y, [
            $jobs->pendingChart($child),
            $jobs->completedChart($child + Jobs::CHART_HEIGHT),
            $jobs->table($child + 2 * Jobs::CHART_HEIGHT),
            ...$jobLists->all($child + 2 * Jobs::CHART_HEIGHT + Jobs::TABLE_HEIGHT),
        ]);
        $y += Common::ROW_HEIGHT;

        $panels[] = Common::row(PanelRegistry::BATCHES_ROW, 'Details '.Glyph::DOT.' Batches', $y, [
            $batches->list($y + Common::ROW_HEIGHT),
        ]);

        return $panels;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function variables(): array
    {
        $promql = $this->options->promql();

        return (new Variables($promql, new Selection($promql), new BatchState($promql), $this->options))->all();
    }

    /**
     * Reset sets every filter to All on this same dashboard and keeps the time range and the job scope: without explicit values
     * in the URL Grafana would keep the selection. The URL uses the current dashboard's uid, because another
     * Grafana may store it elsewhere; Grafana adds its sub-path itself. Horizon opens this environment's Horizon.
     *
     * @return list<array<string, mixed>>
     */
    private function links(): array
    {
        $reset = array_map(static fn (string $name): string => 'var-'.$name.'=%24__all', Variables::FILTERS);

        if (! $this->options->legacy) {
            // The job scope is not a filter: Reset stays on the selected application
            $reset[] = '${prometheus_job:queryparam}';
        }

        return [
            [
                'asDropdown' => false, 'icon' => '', 'includeVars' => false, 'keepTime' => true, 'tags' => [],
                'targetBlank' => false, 'title' => 'Reset', 'tooltip' => '', 'type' => 'link',
                'url' => '/d/${__dashboard.uid}?'.implode('&', $reset),
            ],
            [
                'asDropdown' => false, 'icon' => 'external link', 'includeVars' => false, 'keepTime' => false, 'tags' => [],
                'targetBlank' => true, 'title' => 'Horizon', 'tooltip' => '', 'type' => 'link', 'url' => '${horizon_url}',
            ],
        ];
    }

    /**
     * The options WP8B adds behaviour for are rejected until then, rather than silently ignored.
     */
    private function assertSupported(): void
    {
        $unsupported = array_keys(array_filter([
            'live' => $this->options->live !== LiveMode::Off,
            'dedupe' => $this->options->dedupe,
        ]));

        if ($unsupported !== []) {
            throw new LogicException('Not supported by the dashboard renderer yet: '.implode(', ', $unsupported).'.');
        }
    }
}
