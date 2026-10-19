<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Grafana\Panels;

use DF\HorizonObservability\Grafana\Glyph;
use DF\HorizonObservability\Grafana\PanelRegistry;
use DF\HorizonObservability\Grafana\Promql;
use DF\HorizonObservability\Grafana\Queries\Attempts;
use DF\HorizonObservability\Grafana\Queries\Selection;
use DF\HorizonObservability\Grafana\Queries\Waits;
use DF\HorizonObservability\Grafana\Queries\Workers;
use DF\HorizonObservability\Grafana\Variables;
use DF\HorizonObservability\Metrics\Metric;
use stdClass;

/**
 * The Queues table, repeated per supervisor (hidden variable `supervisor_title`) so the queues that share a
 * pool of processes stand together. A queue belongs to the supervisors it was seen with within a day: while
 * Horizon is down its supervisors are missing from the metrics, yet the tables must stay.
 *
 * Left to right: pool utilisation, workers now (processes), the queue now (pending, delayed, oldest wait over
 * the limit), totals of the period (wait p95, completed, failed, of which timeouts, retries, success rate).
 *
 * The queries run once for all supervisors in the hidden queue source #24: query M lists the (queue, supervisor) rows
 * and the other queries the values per queue; each copy of the table reads #24 through the `-- Dashboard --`
 * datasource, merges the values into the rows and keeps the rows of its own supervisor. Without it each copy ran every
 * query for all queues and then dropped the other supervisors' rows: N supervisors cost N times.
 *
 * @internal
 */
final readonly class Queues
{
    public const HEIGHT = 5;

    /**
     * Any explicit value makes Grafana run the hidden source: without one, a panel hidden before its first render
     * never runs its queries and every copy of the table stays empty (verified on Grafana 12 and 13). Its queries
     * are instant, so the value changes nothing else.
     */
    private const SOURCE_MAX_DATA_POINTS = 100;

    /**
     * Utilisation zones of the percent bar: a pattern of the three-digit percent without leading zeros in
     * `$1$2`, the fill and the text colour (green up to 69%, yellow up to 89%, red above).
     */
    private const LOAD_ZONES = [
        ['0(?:0(\d)|([1-6]\d))', '#73BF69', '#FFFFFF'],
        ['0([78]\d)()', '#FADE2A', '#111217'],
        ['(?:0(9\d)|(100))', '#F2495C', '#FFFFFF'],
    ];

    public function __construct(
        private Promql $promql,
        private Selection $selection,
        private Attempts $attempts,
        private Workers $workers,
        private Waits $waits,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function table(int $y): array
    {
        return [
            'datasource' => Common::dashboard(),
            'fieldConfig' => [
                'defaults' => Common::tableDefaults(),
                'overrides' => [
                    Common::column('Queue', extra: [Common::property('custom.minWidth', 140)]),
                    Common::column('Utilization', 130, [
                        Common::property('custom.cellOptions', ['type' => 'markdown']),
                        Common::property('mappings', [...$this->loadMappings(), Common::undefinedMapping()]),
                    ]),
                    // "age / limit", coloured by the limit of its own queue, see oldestCell()
                    Common::column('Oldest wait', 130, $this->levelTextCell()),
                    // p95 of the wait for a free worker in the period; colours as Oldest wait
                    Common::column('Wait p95', 100, $this->levelTextCell()),
                    Common::column('Pending', 90),
                    Common::column('Completed', 100),
                    Common::column('Delayed', 90),
                    Common::column('Processes', 100),
                    Common::column('Failed', 80, Common::colourFromOne()),
                    Common::column('Retries', 80),
                    Common::column('Timeouts', 90, Common::colourFromOne()),
                    Common::successRateColumn(),
                ],
            ],
            'gridPos' => Common::gridPos(self::HEIGHT, 24, 0, $y),
            'id' => PanelRegistry::QUEUES,
            'options' => Common::tableOptions(['Utilization' => true]),
            'targets' => [['datasource' => Common::dashboard(), 'panelId' => PanelRegistry::QUEUE_SOURCE, 'refId' => 'A', 'withTransforms' => false]],
            'title' => '${supervisor_title:text}',
            'transformations' => [
                // The labels of the series of M and C become fields, the attempts by status of C a column per
                // status; the table frames have no labels. Without a frame filter: Grafana puts the output of a
                // filtered transformation in front of the other frames, and the order matters to the merge.
                ['id' => 'labelsToFields', 'options' => ['valueLabel' => 'status']],
                // The values of each queue join every (queue, supervisor) row of M, whose frames are the first:
                // Grafana's merge adds a frame's values to each earlier row of the same key (Time and queue), but
                // a row that a frame without supervisor started takes only the first supervisor of its queue, and a
                // queue of two supervisors would have its full row in one copy only
                Common::merge(),
                // The rows of this copy's supervisor; the dash of the table without supervisors keeps the stub row
                ['id' => 'filterByValue', 'options' => ['filters' => [
                    ['config' => ['id' => 'equal', 'options' => ['value' => '${supervisor_title}']], 'fieldName' => 'supervisor'],
                ], 'match' => 'any', 'type' => 'include']],
                // Failed counts timeouts too; without attempts in the period the success rate is 0 / 0, a dash
                Common::calculateFields('failed_total', 'failed', '+', 'timed_out'),
                Common::calculateFields('finished', 'completed', '+', 'failed_total'),
                Common::calculateFields('success', 'completed', '/', 'finished'),
                ['id' => 'organize', 'options' => [
                    // age, limit, level, p95 and p95_level are parts of the Oldest wait and Wait p95 texts;
                    // the values of F, K and M (Value), the stub row, the supervisor of the source rows and the
                    // parts of Failed and Success rate are not shown
                    'excludeByName' => [
                        'Time' => true, 'age' => true, 'limit' => true, 'level' => true, 'Value #F' => true,
                        'p95' => true, 'p95_level' => true, 'Value #K' => true, 'Value' => true, 'Value #Z' => true, 'supervisor' => true,
                        'failed' => true, 'finished' => true,
                    ],
                    'includeByName' => new stdClass,
                    'indexByName' => [
                        'Time' => 0, 'queue' => 1, 'Value #A' => 2, 'Value #D' => 3, 'Value #B' => 4,
                        'Value #G' => 5, 'cell' => 6, 'p95_cell' => 7, 'completed' => 8, 'failed_total' => 9,
                        'timed_out' => 10, 'retried' => 11, 'success' => 12,
                    ],
                    'renameByName' => [
                        'queue' => 'Queue', 'Value #A' => 'Utilization', 'Value #B' => 'Pending',
                        'completed' => 'Completed', 'Value #G' => 'Delayed', 'Value #D' => 'Processes',
                        'failed_total' => 'Failed', 'retried' => 'Retries', 'timed_out' => 'Timeouts',
                        'success' => 'Success rate', 'p95_cell' => 'Wait p95', 'cell' => 'Oldest wait',
                    ],
                ]],
                Common::toString('Utilization'),
            ],
            'type' => 'table',
            'repeat' => 'supervisor_title',
            'repeatDirection' => 'v',
        ];
    }

    /**
     * The hidden queue source #24: the table queries for every supervisor at once, in the hidden top grid row next
     * to the alert sound panel, whose script hides both.
     *
     * @return array<string, mixed>
     */
    public function source(): array
    {
        return [
            'datasource' => Common::prometheus(),
            'gridPos' => Common::gridPos(AlertSound::HEIGHT, 24 - AlertSound::WIDTH, AlertSound::WIDTH, 0),
            'id' => PanelRegistry::QUEUE_SOURCE,
            'maxDataPoints' => self::SOURCE_MAX_DATA_POINTS,
            'targets' => $this->targets(),
            'title' => 'Queues source',
            'transparent' => true,
            'type' => 'table',
        ];
    }

    /**
     * The table rows come from query M, every (queue, supervisor) pair, and the values from per-queue queries; the
     * table's merge joins them. Joining each query with the supervisors in PromQL read a day of the supervisors'
     * processes series once per query, four times for C: about 100 ms of the 1-hour refresh on the reference profile.
     *
     * @return list<array<string, mixed>>
     */
    private function targets(): array
    {
        $queues = $this->selection->queues();
        $range = Promql::RANGE;
        // The queues of the period: an application whose scrape failed at the end of the period, or that stopped
        // inside it, keeps its rows and their attempts (the columns of now show dashes)
        $periodQueues = $this->selection->queues($range);
        // Each selected queue with every supervisor it was seen with within a day: a row per (queue, supervisor), so a
        // queue that several supervisors work is in each of their tables, and each queue of a balance=false pool too
        $rows = Promql::andOn(
            Promql::group($this->promql->over('last_over_time', Metric::SupervisorQueues, Variables::KNOWN_FOR), 'queue', 'supervisor'),
            $periodQueues,
            'queue',
        );

        // Pool utilisation for the percent bar; the job filter does not apply. Every queue has a row here.
        $utilisation = Common::PERCENT_OFFSET.' + '.Promql::paren(Promql::orOn(
            Promql::andOn(Promql::call('round', '100 * '.$this->workers->queueLoad()), $queues, 'queue'),
            Common::UNKNOWN_PERCENT.' + 0 * '.$queues,
            'queue',
        ));

        $queries = [
            // Pending, Processes, Delayed and Oldest wait are per whole queue, or with a batch or jobs selected
            // per their classes: waiting and busy workers; delayed jobs and ages have no classes (a dash).
            'B' => $this->selection->byJobSelection(
                Promql::sum($this->promql->selector(Metric::QueuePendingJobs), 'queue'),
                $this->orZero(Promql::sum($this->promql->selector(Metric::QueueJobsPending), 'queue')),
            ),
            // Running processes: 0 while Horizon is paused or stopped, as on the Supervisor Processes tile
            'D' => $this->selection->byJobSelection(
                $this->orZero(Promql::binary(
                    // The processes of the pools that work each queue, which replaced the pilot's
                    // horizon_current_processes: a balance=false pool counts for each of its queues
                    $this->workers->queueProcesses(),
                    '*',
                    $this->workers->horizonRunning(),
                    on: [],
                    groupLeft: [],
                )),
                $this->orZero(Promql::sum($this->promql->selector(Metric::QueueJobsReserved), 'queue')),
            ),
            'F' => $this->selection->byJobSelection($this->oldestCell()),
            // As the Delayed tile: not due yet
            'G' => $this->selection->byJobSelection($this->orZero(Promql::paren(
                Promql::sum($this->promql->selector(Metric::QueueDelayedJobs), 'queue')
                .' - '.Promql::sum($this->promql->selector(Metric::QueueDelayedDueJobs), 'queue'),
            ))),
            // The histogram has no classes: a dash with jobs or a batch selected
            'K' => $this->selection->byJobSelection($this->waitP95Cell()),
        ];

        // M first, and a series per row, not a table: the Prometheus datasource returns the frames of series
        // before the tables, in query order, so the frames of M come first and the merge adds the values of the
        // later frames to its rows
        $targets = [
            Common::rawTarget('M', $this->promql->filtered($rows, false)),
            Common::rawTarget('A', $this->promql->filtered($utilisation, false), format: 'table'),
        ];

        foreach ($queries as $refId => $expr) {
            $targets[] = Common::rawTarget($refId, $this->promql->filtered($expr, true), format: 'table');
        }

        // Completed, Failed, Timeouts, Retries and Success rate from one attempts query by status (the legacy table
        // evaluated attempts_delta six times): one series per (queue, status), 0 for the queues of the period without
        // attempts. A series per status, not a table: the table pivots the statuses into columns with
        // "Labels to fields" and computes Failed and Success rate.
        $statuses = explode('|', Attempts::COUNTED);
        $targets[] = Common::rawTarget('C', $this->promql->filtered(Promql::either(
            Promql::andOn(Promql::sum($this->attempts->delta(Attempts::COUNTED, $range), 'queue', 'status'), $periodQueues, 'queue'),
            '0 * '.Promql::paren($this->perValue($periodQueues, 'status', $statuses)),
        ), true));

        // A stub row of dashes when there are no rows: otherwise only headers remain, without the Queue column,
        // and the table does not stretch. Its supervisor is the title of the only table without supervisors.
        $stub = Promql::unlessOn(
            Promql::withLabel(Promql::withLabel('vector(1)', 'queue', Glyph::DASH), 'supervisor', Glyph::DASH),
            Promql::paren($rows),
        );
        $targets[] = Common::rawTarget('Z', $this->promql->filtered($stub, false), format: 'table');

        return $targets;
    }

    /**
     * Copies of $expr, one per value, each with the label set to it (a cross join with a list of values).
     *
     * @param  list<string>  $values
     */
    private function perValue(string $expr, string $label, array $values): string
    {
        return Promql::either(...array_map(static fn (string $value): string => Promql::withLabel($expr, $label, $value), $values));
    }

    /**
     * $expr for the configured queues, and 0 for configured queues without a value.
     */
    private function orZero(string $expr): string
    {
        $queues = $this->selection->queues();

        return Promql::either(Promql::andOn($expr, $queues, 'queue'), '0 * '.$queues);
    }

    /**
     * Oldest wait: "age of the oldest / wait limit of the queue", e.g. "5 s / 30 s", coloured by the queue's
     * own limit like the Pending tile. Grafana neither joins two numbers in a cell nor colours one column with
     * per-row thresholds, so the query builds the text with the level in front ("2|5 s / 30 s"); the column's
     * value mappings cut the level off and colour by it. Without a limit only the age (legacy `OLDEST_CELL`).
     */
    private function oldestCell(): string
    {
        $age = $this->waits->oldestAge();
        $limit = $this->waits->queueWaitLimit();
        $parts = Promql::binary(
            Promql::binary(Waits::durationLabel($age, 'age'), '*', Waits::durationLabel($limit, 'limit'), on: ['queue'], groupLeft: ['limit']),
            '*',
            Promql::countValues('level', $this->waits->levelAgainstLimit($age), 'queue'),
            on: ['queue'],
            groupLeft: ['level'],
        );

        return Promql::orOn(
            Promql::labelJoin(Promql::labelJoin($parts, 'cell', ' / ', 'age', 'limit'), 'cell', '|', 'level', 'cell'),
            Promql::labelReplace(Waits::durationLabel($age, 'age'), 'cell', '0|$1', 'age', '(.*)'),
            'queue',
        );
    }

    /**
     * Wait p95 like {@see oldestCell()}, without the limit in the text (Oldest wait shows it). No waits in the
     * period (NaN) is a dash (legacy `WAIT_P95_CELL`).
     */
    private function waitP95Cell(): string
    {
        $p95 = $this->waits->waitP95();
        // Level 0 without a wait limit: the p95 once more, not the text again (two quantiles)
        $level = Promql::orOn($this->waits->levelAgainstLimit($p95), '0 * '.$p95, 'queue');
        $parts = Promql::binary(
            Waits::durationLabel($p95, 'p95', '0.1'),
            '*',
            Promql::countValues('p95_level', $level, 'queue'),
            on: ['queue'],
            groupLeft: ['p95_level'],
        );

        return Promql::labelJoin($parts, 'p95_cell', '|', 'p95_level', 'p95');
    }

    /**
     * Mappings of the Utilization percent bar.
     *
     * @return list<array<string, mixed>>
     */
    private function loadMappings(): array
    {
        $mappings = [Common::unknownPercentMapping(0)];

        foreach (self::LOAD_ZONES as $index => [$pattern, $fill, $text]) {
            $mappings[] = Common::regexMapping('/^1'.$pattern.'$/', ['text' => Common::percentBar($fill, $text), 'index' => $index + 1]);
        }

        return $mappings;
    }

    /**
     * Cell options of the level-prefixed texts: red for level 2, yellow for 1, plain for 0.
     *
     * @return list<array<string, mixed>>
     */
    private function levelTextCell(): array
    {
        return [
            Common::property('custom.cellOptions', ['type' => 'color-text']),
            Common::property('custom.align', 'right'),
            Common::property('mappings', [
                Common::regexMapping('/^2\|(.*)$/', ['text' => '$1', 'color' => 'red', 'index' => 0]),
                Common::regexMapping('/^1\|(.*)$/', ['text' => '$1', 'color' => 'yellow', 'index' => 1]),
                Common::regexMapping('/^0\|(.*)$/', ['text' => '$1', 'index' => 2]),
            ]),
        ];
    }
}
