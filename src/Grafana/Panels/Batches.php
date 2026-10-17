<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Grafana\Panels;

use DF\HorizonObservability\Grafana\Glyph;
use DF\HorizonObservability\Grafana\PanelRegistry;
use DF\HorizonObservability\Grafana\Promql;
use DF\HorizonObservability\Grafana\Queries\Attempts;
use DF\HorizonObservability\Grafana\Queries\BatchState;
use DF\HorizonObservability\Grafana\Thresholds;
use DF\HorizonObservability\Metrics\Metric;

/**
 * The batch panels: the current load per batch kind (Current Workload row) and the latest batches of each
 * kind (Details \u{00B7} Batches row, repeated per kind).
 *
 * The legacy generator also built a per-kind summary table (panel 15, `batches_table`) that it never placed
 * on the dashboard; it is not ported.
 *
 * @internal
 */
final readonly class Batches
{
    public const WORKLOAD_HEIGHT = 7;

    public const LIST_HEIGHT = 7;

    public function __construct(
        private Common $common,
        private Promql $promql,
        private Attempts $attempts,
        private BatchState $batches,
    ) {}

    /**
     * Current load per batch kind: kinds with batches in the period, as selected by the Batch filter. Workers,
     * completed and failed count the job classes of the kind (`queue_batch_job_classes`) in their queues.
     *
     * @return array<string, mixed>
     */
    public function workload(int $y): array
    {
        $kinds = $this->batches->selectedKinds();
        $total = $this->batches->jobs('total');
        $pending = $this->batches->jobs('pending');
        $failed = $this->batches->jobs('failed');
        $minutes = Thresholds::RPM_WINDOW_MINUTES;

        // Progress of the running batches of the kind: 1000 + percent for the bar, 1999 (a dash) without any
        $progress = Common::PERCENT_OFFSET.' + '.Promql::paren(Promql::either(
            Promql::andOn(
                Promql::call('round', '100 * '.Promql::paren($total.' - '.$pending).' / '.Promql::paren($total.' > 0')),
                $kinds,
                'batch',
            ),
            Common::UNKNOWN_PERCENT.' + 0 * '.$kinds,
        ));

        $targets = [
            $this->common->target('A', $this->batches->selectedKindsOrZero(Promql::sum($this->promql->selector(Metric::BatchesRunning), 'batch')), format: 'table', jobFilter: false),
            $this->common->target('B', $progress, format: 'table', jobFilter: false),
            // Waiting: Laravel counts failed jobs as pending
            $this->common->target('C', $this->batches->selectedKindsOrZero(Promql::call('clamp_min', $pending.' - '.$failed, '0')), format: 'table', jobFilter: false),
            // Failed in the period, as Failed in the queue tables, not only in the batches running now
            $this->common->target('D', $this->batches->selectedKindsOrZero($this->batches->perKind($this->attempts->delta(Attempts::FAILED, Promql::RANGE))), format: 'table'),
            $this->common->target('E', $this->batches->selectedKindsOrZero($this->batches->perKind($this->promql->selector(Metric::QueueJobsReserved))), format: 'table'),
            $this->common->target(
                'F',
                Promql::either(
                    Promql::andOn(Promql::max($this->promql->selector(Metric::BatchOldestAge), 'batch'), $kinds, 'batch'),
                    '-1 + 0 * '.$kinds,
                ),
                format: 'table',
                jobFilter: false,
            ),
            $this->common->target('G', $this->batches->selectedKindsOrZero($this->batches->perKind($this->attempts->delta(Attempts::COMPLETED, Promql::RANGE))), format: 'table'),
            $this->common->target(
                'H',
                $this->batches->selectedKindsOrZero(Promql::call('round', $this->batches->perKind($this->attempts->delta(Attempts::COMPLETED, $minutes.'m')).' / '.$minutes)),
                format: 'table',
            ),
            // A stub row of dashes without batches in the period: otherwise only headers remain, without the Batch
            // column, and the table does not stretch
            $this->common->target('Z', Promql::unlessOn(Promql::withLabel('vector(1)', 'batch', Glyph::DASH), Promql::paren($kinds)), format: 'table', jobFilter: false),
        ];

        return [
            'datasource' => Common::prometheus(),
            'fieldConfig' => [
                // The dash shows in the stub row: kinds of the period always have values
                'defaults' => Common::tableDefaults(),
                'overrides' => [
                    Common::column('Batch', extra: [Common::property('custom.minWidth', 160)]),
                    Common::column('Running', 100),
                    Common::column('Progress', 160, [
                        Common::property('custom.cellOptions', ['type' => 'markdown']),
                        Common::property('mappings', [...$this->progressMappings(), Common::undefinedMapping()]),
                    ]),
                    Common::column('Pending', 90),
                    Common::column('Failed', 80, Common::colourFromOne()),
                    Common::column('Workers', 90),
                    Common::column('Oldest', 100, $this->oldestCell()),
                    Common::column('Completed', 110),
                    Common::column('RPM', 80),
                ],
            ],
            'gridPos' => Common::gridPos(self::WORKLOAD_HEIGHT, 24, 0, $y),
            'id' => PanelRegistry::BATCH_WORKLOAD,
            'options' => Common::tableOptions(['Running' => true]),
            'targets' => $targets,
            'title' => 'Batches',
            'transformations' => [
                Common::merge(),
                ['id' => 'organize', 'options' => [
                    'excludeByName' => ['Time' => true, 'Value #Z' => true],
                    'indexByName' => [
                        'Time' => 0, 'batch' => 1, 'Value #A' => 2, 'Value #B' => 3, 'Value #C' => 4, 'Value #D' => 5,
                        'Value #E' => 6, 'Value #F' => 7, 'Value #G' => 8, 'Value #H' => 9,
                    ],
                    'renameByName' => [
                        'batch' => 'Batch', 'Value #A' => 'Running', 'Value #B' => 'Progress', 'Value #C' => 'Pending',
                        'Value #D' => 'Failed', 'Value #E' => 'Workers', 'Value #F' => 'Oldest',
                        'Value #G' => 'Completed', 'Value #H' => 'RPM',
                    ],
                ]],
                Common::toString('Progress'),
            ],
            'type' => 'table',
        ];
    }

    /**
     * The latest batches of one kind, one table per kind of the period (hidden variable `batch_title`). The
     * name links to the batch in Horizon; the query builds the HTML, because a Grafana field link would be on
     * every cell, the dash of the stub row included.
     *
     * @return array<string, mixed>
     */
    public function list(int $y): array
    {
        $statusProgress = $this->batches->statusProgress(Common::PERCENT_OFFSET);
        $linked = Promql::labelReplace(
            Promql::labelJoin($statusProgress, 'link', "\t", 'id', 'name'),
            'link',
            '<a href="${horizon_url}/batches/$1" target="_blank">$2</a>',
            'link',
            "([^\t]*)\t(.*)",
        );
        $jobs = fn (string $state): string => $this->batches->valueOfLatest(Metric::BatchJobCounts, Promql::eq('state', $state));

        $targets = [
            $this->common->target('A', $linked, format: 'table'),
            $this->common->target('B', $jobs('total'), format: 'table'),
            // Waiting: Laravel counts failed jobs as pending. A cancelled batch may no longer count a failed job
            // as pending, which would give -1 without the clamp
            $this->common->target(
                'C',
                Promql::call(
                    'clamp_min',
                    Promql::binary($jobs('pending'), '-', $jobs('failed'), on: ['batch', 'name']),
                    '0',
                ),
                format: 'table',
            ),
            $this->common->target('D', $jobs('failed'), format: 'table'),
            $this->common->target('E', '1000 * '.$this->batches->valueOfLatest(Metric::BatchCreated), format: 'table'),
            $this->common->target('F', $this->batches->durationOfLatest(), format: 'table'),
            // A stub row of dashes without batches: otherwise only headers remain, without the Name column, and
            // the table does not stretch. Query A sets the rows.
            $this->common->target(
                'Z',
                Promql::unlessOn(
                    Promql::withLabel(Promql::withLabel(Promql::withLabel('vector(1)', 'name', Glyph::DASH), 'status', Glyph::DASH), 'link', Glyph::DASH),
                    Promql::paren($statusProgress),
                ),
                format: 'table',
                jobFilter: false,
            ),
        ];

        return [
            'datasource' => Common::prometheus(),
            'fieldConfig' => [
                'defaults' => Common::tableDefaults(),
                'overrides' => [
                    Common::column('Name', extra: [
                        Common::property('custom.minWidth', 220),
                        Common::property('custom.cellOptions', ['type' => 'markdown']),
                    ]),
                    Common::column('Status', 110, [
                        Common::property('custom.cellOptions', ['type' => 'color-background', 'mode' => 'basic']),
                        Common::property('custom.align', 'center'),
                        Common::property('mappings', $this->statusMappings()),
                    ]),
                    Common::column('Progress', 160, [
                        Common::property('custom.cellOptions', ['type' => 'markdown']),
                        Common::property('mappings', [...$this->progressMappings(), Common::undefinedMapping()]),
                    ]),
                    Common::column('Jobs', 90),
                    Common::column('Pending', 90),
                    Common::column('Failed', 80, Common::colourFromOne()),
                    // Browser time, as in Horizon
                    Common::column('Created', 200, [Common::property('unit', 'dateTimeAsLocal')]),
                    Common::column('Duration', 110, Common::seconds(0)),
                ],
            ],
            'gridPos' => Common::gridPos(self::LIST_HEIGHT, 24, 0, $y),
            'id' => PanelRegistry::BATCH_LIST,
            'options' => Common::tableOptions(['Created' => true]),
            'repeat' => 'batch_title',
            'repeatDirection' => 'v',
            'targets' => $targets,
            'title' => '${batch_title:text}',
            'transformations' => [
                Common::merge(),
                ['id' => 'organize', 'options' => [
                    // name only joins the rows of the queries; the visible name is link
                    'excludeByName' => ['Time' => true, 'batch' => true, 'name' => true, 'id' => true, 'Value #Z' => true],
                    'indexByName' => [
                        'Time' => 0, 'batch' => 1, 'name' => 2, 'link' => 3, 'status' => 4, 'Value #A' => 5, 'Value #B' => 6,
                        'Value #C' => 7, 'Value #D' => 8, 'Value #E' => 9, 'Value #F' => 10, 'id' => 11,
                    ],
                    'renameByName' => [
                        'link' => 'Name', 'status' => 'Status', 'Value #A' => 'Progress', 'Value #B' => 'Jobs',
                        'Value #C' => 'Pending', 'Value #D' => 'Failed', 'Value #E' => 'Created',
                        'Value #F' => 'Duration',
                    ],
                ]],
                Common::toString('Progress'),
            ],
            'type' => 'table',
        ];
    }

    /**
     * Progress bar of a batch: 1000 + percent processed (total minus pending, as Horizon and Laravel count;
     * failed jobs stay pending).
     *
     * @return list<array<string, mixed>>
     */
    private function progressMappings(): array
    {
        return [
            Common::unknownPercentMapping(1),
            Common::regexMapping('/^1(?:0{1,2}(\d{1,2})|(100))$/', ['text' => Common::percentBar('#5794F2', '#FFFFFF'), 'index' => 0]),
        ];
    }

    /**
     * Status colours as in Horizon: failed jobs are an error (red), cancelled a warning (orange), running grey.
     *
     * @return list<array<string, mixed>>
     */
    private function statusMappings(): array
    {
        return [['type' => 'value', 'options' => [
            'Running' => ['text' => 'Running', 'color' => '#8E8E8E', 'index' => 0],
            'Finished' => ['text' => 'Finished', 'color' => 'green', 'index' => 1],
            'Failures' => ['text' => 'Failures', 'color' => 'red', 'index' => 2],
            'Cancelled' => ['text' => 'Cancelled', 'color' => 'orange', 'index' => 3],
            // The stub row
            Glyph::DASH => ['text' => Glyph::DASH, 'color' => 'transparent', 'index' => 4],
        ]]];
    }

    /**
     * Age of the oldest pending job in seconds; -1 (no running batch) is a dash: an empty query would drop the
     * whole column from the merged table.
     *
     * @return list<array<string, mixed>>
     */
    private function oldestCell(): array
    {
        return [
            ...Common::seconds(0),
            Common::property('mappings', [['type' => 'value', 'options' => ['-1' => ['text' => Glyph::DASH, 'index' => 0]]]]),
        ];
    }
}
