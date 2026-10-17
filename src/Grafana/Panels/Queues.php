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
 * pool of processes stand together. A queue belongs to the supervisor it was last seen with within a day:
 * while Horizon is down its supervisors are missing from the metrics, yet the tables must stay.
 *
 * Left to right: pool utilisation, workers now (processes), the queue now (pending, delayed, oldest wait over
 * the limit), totals of the period (wait p95, completed, failed, of which timeouts, retries, success rate).
 *
 * @internal
 */
final readonly class Queues
{
    public const HEIGHT = 5;

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
            'datasource' => Common::prometheus(),
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
            'targets' => $this->targets(),
            'title' => '${supervisor_title:text}',
            'transformations' => [
                Common::merge(),
                ['id' => 'organize', 'options' => [
                    // age, limit, level, p95 and p95_level are parts of the Oldest wait and Wait p95 texts;
                    // the values of F and K and the stub row are not shown
                    'excludeByName' => [
                        'Time' => true, 'age' => true, 'limit' => true, 'level' => true, 'Value #F' => true,
                        'p95' => true, 'p95_level' => true, 'Value #K' => true, 'Value #Z' => true,
                    ],
                    'includeByName' => new stdClass,
                    'indexByName' => [
                        'Time' => 0, 'queue' => 1, 'Value #A' => 2, 'Value #D' => 3, 'Value #B' => 4,
                        'Value #G' => 5, 'cell' => 6, 'p95_cell' => 7, 'Value #C' => 8, 'Value #E' => 9,
                        'Value #I' => 10, 'Value #H' => 11, 'Value #J' => 12,
                    ],
                    'renameByName' => [
                        'queue' => 'Queue', 'Value #A' => 'Utilization', 'Value #B' => 'Pending',
                        'Value #C' => 'Completed', 'Value #G' => 'Delayed', 'Value #D' => 'Processes',
                        'Value #E' => 'Failed', 'Value #H' => 'Retries', 'Value #I' => 'Timeouts',
                        'Value #J' => 'Success rate', 'p95_cell' => 'Wait p95', 'cell' => 'Oldest wait',
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
     * @return list<array<string, mixed>>
     */
    private function targets(): array
    {
        $queues = $this->selection->queues();
        $range = Promql::RANGE;
        $knownQueues = Promql::group(
            $this->promql->over('last_over_time', Metric::SupervisorProcesses, Variables::KNOWN_FOR, [Promql::re('supervisor', '$supervisor_title')]),
            'queue',
        );
        // Only the queues of the supervisor this copy of the table is repeated for
        $scoped = fn (string $expr, bool $jobFilter = true): string => Promql::andOn(
            Promql::paren($this->promql->filtered($expr, $jobFilter)),
            $knownQueues,
            'queue',
        );

        // Pool utilisation for the percent bar; the job filter does not apply. Every queue has a row here.
        $utilisation = $scoped(Common::PERCENT_OFFSET.' + '.Promql::paren(Promql::orOn(
            Promql::andOn(Promql::call('round', '100 * '.$this->workers->queueLoad()), $queues, 'queue'),
            Common::UNKNOWN_PERCENT.' + 0 * '.$queues,
            'queue',
        )), jobFilter: false);

        $queries = [
            // Pending, Processes, Delayed and Oldest wait are per whole queue, or with a batch or jobs selected
            // per their classes: waiting and busy workers; delayed jobs and ages have no classes (a dash).
            'B' => $this->selection->byJobSelection(
                Promql::sum($this->promql->selector(Metric::QueuePendingJobs), 'queue'),
                $this->orZero(Promql::sum($this->promql->selector(Metric::QueueJobsPending), 'queue')),
            ),
            'C' => $this->orZero(Promql::sum($this->attempts->delta(Attempts::COMPLETED, $range), 'queue')),
            // Running processes: 0 while Horizon is paused or stopped, as on the Supervisor Processes tile
            'D' => $this->selection->byJobSelection(
                $this->orZero(Promql::binary(
                    // The supervisors' processes per queue, which replaced the pilot's horizon_current_processes
                    // (Arch Q5.2). The Queue filter is explicit: the family is not one of the queue-filtered ones.
                    Promql::sum($this->promql->selector(Metric::SupervisorProcesses, Promql::re('queue', '$queue')), 'queue'),
                    '*',
                    $this->workers->horizonRunning(),
                    on: [],
                    groupLeft: [],
                )),
                $this->orZero(Promql::sum($this->promql->selector(Metric::QueueJobsReserved), 'queue')),
            ),
            'E' => $this->orZero(Promql::sum($this->attempts->delta(Attempts::FAILED, $range), 'queue')),
            'F' => $this->selection->byJobSelection($this->oldestCell()),
            // As the Delayed tile: not due yet
            'G' => $this->selection->byJobSelection($this->orZero(Promql::paren(
                Promql::sum($this->promql->selector(Metric::QueueDelayedJobs), 'queue')
                .' - '.Promql::sum($this->promql->selector(Metric::QueueDelayedDueJobs), 'queue'),
            ))),
            'H' => $this->orZero(Promql::sum($this->attempts->delta(Attempts::RETRIED, $range), 'queue')),
            'I' => $this->orZero(Promql::sum($this->attempts->delta(Attempts::TIMED_OUT, $range), 'queue')),
            // Without attempts in the period: empty (a dash)
            'J' => Promql::sum($this->attempts->delta(Attempts::COMPLETED, $range), 'queue')
                .' / '.Promql::paren(Promql::sum($this->attempts->delta(Attempts::FINISHED, $range), 'queue').' > 0'),
            // The histogram has no classes: a dash with jobs or a batch selected
            'K' => $this->selection->byJobSelection($this->waitP95Cell()),
        ];

        $targets = [Common::rawTarget('A', $utilisation, format: 'table')];

        foreach ($queries as $refId => $expr) {
            $targets[] = Common::rawTarget($refId, $scoped($expr), format: 'table');
        }

        // A stub row of dashes when there are no queues: otherwise only headers remain, without the Queue column,
        // and the table does not stretch.
        $stub = Promql::unlessOn(Promql::withLabel('vector(1)', 'queue', Glyph::DASH), Promql::paren($utilisation));
        $targets[] = Common::rawTarget('Z', $stub, format: 'table');

        return $targets;
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
        $text = Waits::durationLabel($p95, 'p95', '0.1');
        $parts = Promql::binary(
            $text,
            '*',
            Promql::countValues('p95_level', $this->waits->levelAgainstLimit($p95), 'queue'),
            on: ['queue'],
            groupLeft: ['p95_level'],
        );

        return Promql::orOn(
            Promql::labelJoin($parts, 'p95_cell', '|', 'p95_level', 'p95'),
            Promql::labelReplace($text, 'p95_cell', '0|$1', 'p95', '(.*)'),
            'queue',
        );
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
