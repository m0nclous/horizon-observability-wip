<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Grafana\Panels;

use DF\HorizonObservability\Grafana\Glyph;
use DF\HorizonObservability\Grafana\PanelRegistry;
use DF\HorizonObservability\Grafana\Promql;
use DF\HorizonObservability\Grafana\Queries\Attempts;
use DF\HorizonObservability\Grafana\Queries\Selection;
use DF\HorizonObservability\Grafana\Queries\Workers;
use DF\HorizonObservability\Metrics\Metric;
use stdClass;

/**
 * Per-job panels of the "Details \u{00B7} Jobs" row: pending and completed jobs by class per queue, and the Jobs table.
 *
 * @internal
 */
final readonly class Jobs
{
    public const CHART_HEIGHT = 9;

    public const TABLE_HEIGHT = 14;

    private const BY_JOB = ['queue', 'job_class'];

    /**
     * The class name without its namespace (PromQL regex).
     */
    private const SHORT_CLASS = '(?:.*\\\\)?([^\\\\]+)';

    public function __construct(
        private Common $common,
        private Promql $promql,
        private Selection $selection,
        private Attempts $attempts,
        private Workers $workers,
    ) {}

    /**
     * Pending jobs by class, one panel per queue of the Queue filter in one row, so the jobs of different
     * queues do not mix. Each point is the maximum of its step, so peaks between points survive long periods;
     * without a scrape in the step the current value avoids a false zero.
     *
     * The worker limit (all workers busy, some jobs wait) and twice the limit (overload) are thresholds on the
     * Total line rather than series: a series would stretch the axis up to it with a coloured band above, a
     * threshold is outside the axis scale and invisible while fewer jobs wait. Dashed, with yellow from the
     * limit and red from twice the limit.
     *
     * @return array<string, mixed>
     */
    public function pendingChart(int $y): array
    {
        $pendingMax = Promql::either(
            Promql::sum($this->promql->over('max_over_time', Metric::QueueJobsPending, Promql::INTERVAL), ...self::BY_JOB),
            Promql::sum($this->promql->selector(Metric::QueueJobsPending), ...self::BY_JOB),
        );
        // A class series exists only while its jobs wait: zeros for the classes that waited in the period make
        // the lines drop to 0 instead of ending. The `job` label is the short class name for the legend.
        $byJob = Promql::labelReplace(
            Promql::either(Promql::paren($pendingMax), '0 * '.Promql::group($this->promql->over('max_over_time', Metric::QueueJobsPending, Promql::RANGE), ...self::BY_JOB)),
            'job',
            '$1',
            'job_class',
            self::SHORT_CLASS,
        );

        return $this->chart(
            PanelRegistry::PENDING_BY_JOB,
            'Pending by job: $queue',
            $y,
            [
                $this->common->target('A', $byJob, instant: false, legend: '{{job}}'),
                // The sum of the per-class maxima per step matches the top of the stack. An empty queue is
                // zero only while its metrics are scraped: without metrics "No data", not zero.
                $this->common->target(
                    'B',
                    Promql::sum($pendingMax).' or 0 * '.Promql::group($this->promql->selector(Metric::QueuePendingJobs)),
                    instant: false,
                    legend: 'Total',
                ),
                // Config from query results takes this query out of the graph
                $this->common->target('E', '2 * '.$this->workers->workerLimit(), legend: 'overload'),
            ],
            unit: 'locale',
            decimals: 0,
            limitThresholds: true,
            // Thresholds from query E: transparent from 0, yellow from the limit, red from the overload. Without
            // its own lowest step Grafana adds green and the whole background turned green. The steps follow
            // the field order: base, limit, overload.
            transformations: [
                Common::calculate('E', 'base', 'overload', '*', '0'),
                Common::calculate('E', 'limit', 'overload', '/', '2'),
                ['id' => 'organize', 'filter' => Common::byRefId('E'), 'options' => [
                    'indexByName' => ['Time' => 0, 'base' => 1, 'limit' => 2, 'overload' => 3],
                ]],
                Common::configFromData('E', [
                    Common::thresholdHandler('base', 'transparent'),
                    Common::thresholdHandler('limit', 'yellow'),
                    Common::thresholdHandler('overload', 'red'),
                ], applyTo: 'B'),
            ],
        );
    }

    /**
     * Completed jobs by class per minute, below the pending chart with the same layout: when pending jobs grow,
     * it shows whether the queue stopped or the workers are busy with other jobs. Exact counter differences
     * per step.
     *
     * @return array<string, mixed>
     */
    public function completedChart(int $y): array
    {
        $perMinute = Promql::sum($this->attempts->perStep(Attempts::COMPLETED), ...self::BY_JOB).' / ($__interval_ms / 60000)';

        return $this->chart(
            PanelRegistry::COMPLETED_BY_JOB,
            'Completed by job: $queue',
            $y,
            [
                $this->common->target('A', Promql::labelReplace($perMinute, 'job', '$1', 'job_class', self::SHORT_CLASS), instant: false, legend: '{{job}}'),
                $this->common->target('B', Promql::sum($perMinute).' or '.$this->selection->zeroIfMetrics(), instant: false, legend: 'Total'),
            ],
            unit: 'suffix:/min',
            decimals: 1,
            limitThresholds: false,
        );
    }

    /**
     * The Jobs table: classes that wait or run now or ran in the period, and every class of the code that ever
     * ran in a queue, with zeros. Roughly ten rows fit; Grafana does not size a panel to its content, the rest
     * scrolls inside the table.
     *
     * @return array<string, mixed>
     */
    public function table(int $y): array
    {
        $by = self::BY_JOB;
        $rows = $this->rows();
        // Increase of a duration histogram series in the period, per class
        $durationIncrease = fn (string $series, string ...$labels): string => Promql::sum(
            $this->promql->over('increase', Metric::JobDuration, Promql::RANGE, series: $series),
            ...$labels,
        );
        $durationCount = $durationIncrease('count', ...$by);
        $max = Promql::max($this->promql->over('max_over_time', Metric::JobDurationMax, Promql::RANGE), ...$by);
        // The buckets are the largest per-class vector (16 series per class and application), so their batch scope
        // comes after the sum by class, which keeps job_class: the same rows, fewer series in the set operation
        // when several applications share the Prometheus. The selector carries the Job filter itself, so
        // filtered() adds neither the filter nor the scope again.
        $buckets = $this->promql->batchScoped(Promql::sum(
            $this->promql->over('increase', Metric::JobDuration, Promql::RANGE, [Promql::re('job_class', '$job_class')], series: 'bucket'),
            'le',
            ...$by,
        ));
        // Bucket estimates interpolate inside a bucket and can exceed the exact maximum, so the table caps p95
        // at the maximum. Without a maximum (attempts before the metric existed) the estimate stays as is.
        $p95 = Promql::paren(Promql::andOn(
            Promql::call('histogram_quantile', '0.95', $buckets),
            Promql::paren($durationCount.' > 0'),
            ...$by,
        ));
        $pending = Promql::sum($this->promql->selector(Metric::QueueJobsPending), ...$by).' or 0 * '.$rows;

        $targets = [
            'A' => $pending,
            'E' => Promql::sum($this->promql->selector(Metric::QueueJobsReserved), ...$by).' or 0 * '.$rows,
            'F' => $durationIncrease('sum', ...$by).' / '.Promql::paren($durationCount.' > 0'),
            'G' => Promql::either(
                Promql::orOn(
                    Promql::paren(Promql::binary($p95, '<=', $max, on: $by)),
                    Promql::paren(Promql::binary($max, '<', $p95, on: $by)),
                    ...$by,
                ),
                $p95,
            ),
            'I' => Promql::min($this->promql->over('min_over_time', Metric::JobDurationMin, Promql::RANGE), ...$by),
            'J' => $max,
            // A stub row of dashes without jobs: otherwise an empty period shows only headers, without the Job
            // and Queue columns
            'Z' => Promql::unlessOn(
                Promql::withLabel(Promql::withLabel('vector(1)', 'job_class', Glyph::DASH), 'queue', Glyph::DASH),
                Promql::paren($pending),
            ),
        ];

        return [
            'datasource' => Common::prometheus(),
            'fieldConfig' => [
                'defaults' => Common::tableDefaults(),
                'overrides' => [
                    Common::column('Job', extra: [
                        Common::property('custom.minWidth', 180),
                        Common::property('mappings', [Common::regexMapping('/^(?:.*\\\\)?([^\\\\]+)$/', ['text' => '$1', 'index' => 0])]),
                    ]),
                    Common::column('Queue', 110),
                    Common::column('Pending', 100),
                    Common::column('Failed', 80, Common::colourFromOne()),
                    Common::column('Retries', 95),
                    Common::successRateColumn(),
                    Common::column('Workers', 95),
                    Common::column('Min', 95, Common::seconds(2)),
                    Common::column('Avg', 95, Common::seconds(2)),
                    Common::column('p95', 90, Common::seconds(2)),
                    Common::column('Max', 95, Common::seconds(2)),
                    Common::column('Timeouts', 95, Common::colourFromOne()),
                ],
            ],
            'gridPos' => Common::gridPos(self::TABLE_HEIGHT, 24, 0, $y),
            'id' => PanelRegistry::JOBS,
            // Classes that ran in the period above the idle ones: those have no Avg
            'options' => Common::tableOptions(['Pending' => true, 'Avg' => true]),
            'targets' => [
                ...array_map(
                    fn (string $refId, string $expr): array => $this->common->target($refId, $expr, format: 'table'),
                    array_keys($targets),
                    $targets,
                ),
                // Failed, Timeouts, Retries and Success rate from one attempts query by status (the legacy table
                // evaluated attempts_delta five times): a series per (queue, class, status), 0 for the listed
                // classes without such attempts. Pivoted into columns by "Labels to fields".
                $this->common->target('B', $this->attemptsByStatus($rows)),
            ],
            // The row carries the title
            'title' => '',
            'transformations' => [
                // The attempts by status (query B) become a column per status
                ['id' => 'labelsToFields', 'filter' => Common::byRefId('B'), 'options' => ['valueLabel' => 'status']],
                Common::merge(),
                // Failed counts timeouts too; without attempts in the period the success rate is 0 / 0, a dash
                Common::calculateFields('failed_total', 'failed', '+', 'timed_out'),
                Common::calculateFields('finished', 'completed', '+', 'failed_total'),
                Common::calculateFields('success', 'completed', '/', 'finished'),
                ['id' => 'organize', 'options' => [
                    'excludeByName' => ['Time' => true, 'Value #Z' => true, 'completed' => true, 'failed' => true, 'finished' => true],
                    'includeByName' => new stdClass,
                    'indexByName' => [
                        'Time' => 0, 'job_class' => 1, 'queue' => 2, 'Value #A' => 3, 'failed_total' => 4, 'retried' => 5,
                        'success' => 6, 'Value #E' => 7, 'Value #I' => 8, 'Value #F' => 9, 'Value #G' => 10,
                        'Value #J' => 11, 'timed_out' => 12,
                    ],
                    'renameByName' => [
                        'job_class' => 'Job', 'queue' => 'Queue', 'Value #A' => 'Pending', 'failed_total' => 'Failed',
                        'retried' => 'Retries', 'success' => 'Success rate', 'Value #E' => 'Workers',
                        'Value #F' => 'Avg', 'Value #G' => 'p95', 'timed_out' => 'Timeouts',
                        'Value #I' => 'Min', 'Value #J' => 'Max',
                    ],
                ]],
            ],
            'type' => 'table',
        ];
    }

    /**
     * The rows of the Jobs table (legacy `ROWS`): active in the period (`JOBS`: waiting, running, or with
     * attempts; counter series exist for every class that ever ran, hence `> 0`), idle classes of the code that
     * ran before (`IDLE`: a row in every queue they ran in) and classes of the code that never ran (`NEVER`:
     * their queue is unknown, so they show only while Queue and Supervisor are "All").
     */
    private function rows(): string
    {
        $by = self::BY_JOB;
        $attempts = $this->promql->selector(Metric::JobsTotal);
        $classes = $this->promql->selector(Metric::JobClasses);

        $active = Promql::either(
            Promql::group($this->promql->selector(Metric::QueueJobsPending), ...$by),
            Promql::group($this->promql->selector(Metric::QueueJobsReserved), ...$by),
            Promql::group($this->promql->over('increase', Metric::JobsTotal, Promql::RANGE).' > 0', ...$by),
        );
        $idle = Promql::andOn(Promql::group($attempts, ...$by), Promql::group($classes, 'job_class'), 'job_class');
        $never = Promql::paren(Promql::andOn(
            Promql::withLabel(
                Promql::unlessOn(
                    Promql::group($classes, 'job_class'),
                    Promql::paren(Promql::either(Promql::group($attempts, 'job_class'), Promql::group($active, 'job_class'))),
                    'job_class',
                ),
                'queue',
                Glyph::DASH,
            ),
            Selection::unfiltered(),
        ));

        return Promql::paren(Promql::either(Promql::paren($active), $idle, $never));
    }

    /**
     * The attempts of the period per (queue, class, status) for the statuses the table counts, as whole numbers, and
     * 0 for the listed classes ($rows) without them (legacy `job_count` per status).
     */
    private function attemptsByStatus(string $rows): string
    {
        $statuses = explode('|', Attempts::COUNTED);
        $byStatus = Promql::call('round', Promql::sum($this->attempts->delta(Attempts::COUNTED, Promql::RANGE), 'queue', 'job_class', 'status'));
        $rowsByStatus = Promql::either(...array_map(static fn (string $status): string => Promql::withLabel($rows, 'status', $status), $statuses));

        return Promql::either(Promql::andOn($byStatus, $rows, ...self::BY_JOB), '0 * '.Promql::paren($rowsByStatus));
    }

    /**
     * The per-queue time series of the job charts, repeated per queue of the Queue filter, without a legend
     * (it ate the graph in narrow panels; the tooltip lists the jobs). Total is a line over the stack.
     *
     * @param  list<array<string, mixed>>  $targets
     * @param  list<array<string, mixed>>|null  $transformations
     * @return array<string, mixed>
     */
    private function chart(
        int $id,
        string $title,
        int $y,
        array $targets,
        string $unit,
        int $decimals,
        bool $limitThresholds,
        ?array $transformations = null,
    ): array {
        $total = [
            Common::property('custom.stacking', ['group' => 'A', 'mode' => 'none']),
            Common::property('custom.fillOpacity', 0),
            Common::property('custom.lineWidth', 2),
            Common::property('color', ['mode' => 'fixed', 'fixedColor' => 'text']),
        ];

        if ($limitThresholds) {
            $total[] = Common::property('custom.thresholdsStyle', ['mode' => 'dashed+area']);
        }

        $panel = [
            'datasource' => Common::prometheus(),
            'fieldConfig' => [
                'defaults' => [
                    'color' => ['mode' => 'palette-classic'],
                    'custom' => [
                        'axisPlacement' => 'auto',
                        'drawStyle' => 'line',
                        'fillOpacity' => 30,
                        'gradientMode' => 'none',
                        'lineInterpolation' => 'stepAfter',
                        'lineWidth' => 1,
                        'pointSize' => 4,
                        'showPoints' => 'never',
                        'spanNulls' => false,
                        'stacking' => ['group' => 'A', 'mode' => 'normal'],
                    ],
                    'decimals' => $decimals,
                    'min' => 0,
                    'unit' => $unit,
                    // No zones by default: without supervisor data (query E empty) Grafana would draw its own,
                    // a green background and red from 80
                    'thresholds' => Common::thresholds('transparent'),
                ],
                'overrides' => [[
                    'matcher' => ['id' => 'byFrameRefID', 'options' => 'B'],
                    'properties' => $total,
                ]],
            ],
            'gridPos' => Common::gridPos(self::CHART_HEIGHT, 24, 0, $y),
            'id' => $id,
            'options' => [
                'legend' => ['showLegend' => false],
                'tooltip' => ['hideZeros' => true, 'mode' => 'multi', 'sort' => 'desc'],
            ],
            'targets' => $targets,
        ];

        if ($transformations !== null) {
            $panel['transformations'] = $transformations;
        }

        return [
            ...$panel,
            'repeat' => 'queue',
            'repeatDirection' => 'h',
            'maxPerRow' => 4,
            'title' => $title,
            'type' => 'timeseries',
        ];
    }
}
