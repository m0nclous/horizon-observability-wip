<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Grafana\Panels;

use DF\HorizonObservability\Grafana\Glyph;
use DF\HorizonObservability\Grafana\Json;
use DF\HorizonObservability\Grafana\PanelRegistry;
use DF\HorizonObservability\Grafana\Promql;
use DF\HorizonObservability\Grafana\Queries\AlertState;
use DF\HorizonObservability\Grafana\Queries\Attempts;
use DF\HorizonObservability\Grafana\Queries\Selection;
use DF\HorizonObservability\Grafana\Queries\Waits;
use DF\HorizonObservability\Grafana\Queries\Workers;
use DF\HorizonObservability\Grafana\Thresholds;
use DF\HorizonObservability\Metrics\Metric;

/**
 * The stat tiles of the Overview row.
 *
 * Several tiles colour by a level instead of by their number: thresholds compare with the shown number, so the
 * level query returns X and the thresholds are X (red) and X - K (yellow), K larger than any number on a tile:
 * level 0 gives X = 3K (both unreachable), 1 gives X = K (yellow from 0), 2 gives X = 0 (red from 0).
 *
 * @internal
 */
final readonly class Tiles
{
    public const HEIGHT = 5;

    /**
     * Tile widths, left to right; they fill the 24 grid columns (pinned by LayoutProjectionTest). Supervisor
     * Processes is wider: one value per supervisor with a "name \u{00B7} max N" caption.
     */
    private const WIDTHS = [
        PanelRegistry::HORIZON => 2,
        PanelRegistry::SUPERVISOR_PROCESSES => 6,
        PanelRegistry::PENDING => 4,
        PanelRegistry::COMPLETED => 4,
        PanelRegistry::DELAYED => 3,
        PanelRegistry::FAILED => 3,
        PanelRegistry::ALERTS => 2,
    ];

    private const LEVEL_COLOR_K = 1000000000;

    /**
     * Status values of the Horizon tile; Horizon reports 1, 0 and -1, the dashboard adds "no metrics".
     */
    private const STATUS_RUNNING = '1';

    private const STATUS_PAUSED = '0';

    private const STATUS_INACTIVE = '-1';

    private const STATUS_NO_METRICS = '-2';

    /**
     * Supervisor Processes: colours per zone, keyed by the text of n + zone / 64.
     */
    private const ZONE_COLORS = [
        'green' => '[0-9]+',
        'yellow' => '[0-9]+[.]015625',
        'red' => '[0-9]+[.]03125',
    ];

    public function __construct(
        private Common $common,
        private Promql $promql,
        private Selection $selection,
        private Attempts $attempts,
        private Workers $workers,
        private Waits $waits,
        private AlertState $alerts,
        private int $rpmMinutes,
    ) {}

    /**
     * The tiles left to right at row $y.
     *
     * @return list<array<string, mixed>>
     */
    public function all(int $y): array
    {
        $tiles = [];
        $x = 0;

        foreach (self::WIDTHS as $id => $width) {
            $gridPos = Common::gridPos(self::HEIGHT, $width, $x, $y);
            $tiles[] = match ($id) {
                PanelRegistry::HORIZON => $this->horizon($gridPos),
                PanelRegistry::SUPERVISOR_PROCESSES => $this->supervisorProcesses($gridPos),
                PanelRegistry::PENDING => $this->pending($gridPos),
                PanelRegistry::COMPLETED => $this->completed($gridPos),
                PanelRegistry::DELAYED => $this->delayed($gridPos),
                PanelRegistry::FAILED => $this->failed($gridPos),
                PanelRegistry::ALERTS => $this->alerts($gridPos),
            };
            $x += $width;
        }

        return $tiles;
    }

    /**
     * Horizon: caption "Uptime" above the uptime over the period, background by the current status (green
     * running, yellow running but down longer than allowed in the period, red not running). Below, a thin
     * status band over time: max is far above 1, so UP draws a narrow band at the edge and values <= 0 fall
     * off the scale. The worst value per step keeps a short restart visible on long periods.
     *
     * Stat shows the text of a value mapping, so the mappings come from query B (Config from query results,
     * one row per status): the percent and green or yellow for running, the percent and red for paused and
     * inactive, "No metrics" without metrics. count_values turns the percent into text, truncated to tenths.
     *
     * @param  array{h: int, w: int, x: int, y: int}  $gridPos
     * @return array<string, mixed>
     */
    private function horizon(array $gridPos): array
    {
        $band = Promql::either(
            Promql::min($this->promql->over('min_over_time', Metric::HorizonStatus, Promql::INTERVAL)),
            Promql::min($this->promql->selector(Metric::HorizonStatus)),
            'vector('.self::STATUS_NO_METRICS.')',
        );

        $uptime = Promql::call(
            'floor',
            '1000 * '.Promql::range('avg_over_time', Promql::paren(Promql::either($this->workers->horizonRunning(), 'vector(0)')), Promql::RANGE.':'.Promql::INTERVAL),
        ).' / 10';
        $uptimeText = Promql::labelReplace(
            Promql::labelReplace(Promql::countValues('text', $uptime), 'text', '$1%', 'text', '([0-9]+[.][0-9])'),
            'text',
            '$1.0%',
            'text',
            '([0-9]+)',
        );
        $warning = (string) Thresholds::UPTIME_WARNING_PERCENT;
        $rows = [];

        foreach ([
            [self::STATUS_RUNNING, 'green', ' >= '.$warning],
            [self::STATUS_RUNNING, 'yellow', ' < '.$warning],
            [self::STATUS_PAUSED, 'red', ''],
            [self::STATUS_INACTIVE, 'red', ''],
        ] as [$value, $color, $condition]) {
            $rows[] = Promql::paren(Promql::andOn(
                Promql::withLabel(Promql::withLabel($uptimeText, 'status', $value), 'color', $color),
                Promql::paren($uptime.$condition),
            ));
        }

        $rows[] = Promql::withLabel(
            Promql::withLabel(Promql::withLabel('vector(1)', 'status', self::STATUS_NO_METRICS), 'color', 'red'),
            'text',
            'No metrics',
        );

        return Common::stat(
            PanelRegistry::HORIZON,
            'Horizon',
            $gridPos,
            [
                $this->common->target('A', $band, instant: false),
                $this->common->target('B', Promql::either(...$rows), format: 'table'),
            ],
            thresholds: Common::thresholds('red'),
            colorMode: 'background',
            graphMode: 'area',
            valueRange: [0, 10],
            displayName: 'Uptime',
            withName: true,
            transformations: [Common::configFromData('B', [
                Common::handler('status', 'mappings.value'),
                Common::handler('text', 'mappings.text'),
                Common::handler('color', 'mappings.color'),
                Common::handler('Time', '__ignore'),
                Common::handler('Value', '__ignore'),
            ])],
        );
    }

    /**
     * Supervisor Processes, per supervisor: the number is the running processes, the caption the name without
     * the "supervisor-" prefix and maxProcesses ("slow \u{00B7} max 10"), the background the processes over time.
     * With a batch or jobs selected, the workers busy with their jobs. The colour is the pool's utilisation
     * zone at the end of the period ({@see Workers::processesZone()}): thresholds are per panel, so query B
     * lists the values of the period with a colour by their fraction as value mappings.
     *
     * @param  array{h: int, w: int, x: int, y: int}  $gridPos
     * @return array<string, mixed>
     */
    private function supervisorProcesses(array $gridPos): array
    {
        $maxProcesses = Promql::countValues(
            'max',
            Promql::max($this->promql->selector(Metric::SupervisorMaxProcesses), 'supervisor'),
            'supervisor',
        );
        $value = Promql::binary(Promql::paren($this->workers->processesValue()), '*', $maxProcesses, on: ['supervisor'], groupLeft: ['max']);
        $named = Promql::labelReplace(
            Promql::labelReplace($value, 'name', '$1', 'supervisor', '(.*)'),
            'name',
            '$1',
            'supervisor',
            'supervisor-(.+)',
        );

        $colored = Promql::countValues('value', $this->workers->processesValue());

        foreach (self::ZONE_COLORS as $color => $pattern) {
            $colored = Promql::labelReplace($colored, 'color', $color, 'value', $pattern);
        }

        return Common::stat(
            PanelRegistry::SUPERVISOR_PROCESSES,
            'Supervisor Processes',
            $gridPos,
            [
                $this->common->target('A', $named, instant: false, jobFilter: false, legend: '{{name}} '.Glyph::DOT.' max {{max}}'),
                $this->common->target('B', $colored, instant: false, format: 'table', jobFilter: false),
            ],
            thresholds: Common::thresholds('green'),
            colorMode: 'background',
            graphMode: 'area',
            withName: true,
            transformations: [Common::configFromData('B', [
                Common::handler('value', 'mappings.value'),
                Common::handler('color', 'mappings.color'),
                Common::handler('Time', '__ignore'),
                Common::handler('Value', '__ignore'),
            ])],
        );
    }

    /**
     * Pending: the number is the pending jobs (with a batch or jobs selected, only theirs, from the head of the
     * queue up to pending_scan_limit). The colour is how long the oldest job of the worst selected queue waits
     * against its `horizon.waits` limit; the caption is that queue and the age ("Oldest: 17 min \u{00B7} slow"). Ages
     * exist per queue only, so with jobs or a batch selected there is no colour and "Oldest: \u{2014}".
     *
     * @param  array{h: int, w: int, x: int, y: int}  $gridPos
     * @return array<string, mixed>
     */
    private function pending(array $gridPos): array
    {
        $pending = $this->selection->byJobSelection(
            Promql::sum($this->promql->selector(Metric::QueuePendingJobs)),
            Promql::sum($this->promql->selector(Metric::QueueJobsPending)).' or '.$this->selection->zeroIfMetrics(),
        );

        $limit = Waits::SECONDS_TEXT_LIMIT;
        $worstAge = $this->waits->worstAge();
        $caption = Promql::orOn(
            Promql::either(
                Promql::labelJoin(Promql::countValues('v', Promql::call('round', $worstAge.' < '.$limit), 'queue'), 'display', ' s '.Glyph::DOT.' ', 'v', 'queue'),
                Promql::labelJoin(Promql::countValues('v', Promql::call('round', Promql::paren($worstAge.' >= '.$limit).' / 60'), 'queue'), 'display', ' min '.Glyph::DOT.' ', 'v', 'queue'),
            ),
            Promql::withLabel('vector(1)', 'display', Glyph::DASH),
        );
        $level = Promql::either(
            Promql::paren(Promql::andOn($this->waits->worstLevel(), $this->selection->allJobsSelected())),
            'vector(0)',
        );

        return Common::stat(
            PanelRegistry::PENDING,
            'Pending',
            $gridPos,
            [
                $this->common->target('A', $pending, instant: false),
                $this->common->target('B', $caption, jobFilter: false, legend: 'oldest'),
                $this->levelColorTarget('C', $level, jobFilter: false),
            ],
            thresholds: Common::thresholds('green'),
            colorMode: 'background',
            graphMode: 'area',
            withName: true,
            transformations: [
                // The caption comes from the display label
                Common::labelsToFields('B'),
                Common::configFromData('B', [Common::handler('display', 'displayName')]),
                Common::renameByRegex('^(.+ '.Glyph::DOT.' .+|'.Glyph::DASH.')$', 'Oldest: $1'),
                ...$this->levelColorTransformations('C'),
            ],
        );
    }

    /**
     * Completed: the background is the completed jobs per step, the number their sum, i.e. the jobs completed
     * in the period. The caption is "RPM", completed per minute over the last minutes like Horizon's Jobs Per
     * Minute, but over a fixed window instead of since the last snapshot.
     *
     * @param  array{h: int, w: int, x: int, y: int}  $gridPos
     * @return array<string, mixed>
     */
    private function completed(array $gridPos): array
    {
        $minutes = $this->rpmMinutes;
        $perMinute = Promql::either(
            Promql::call('round', Promql::sum($this->attempts->delta(Attempts::COMPLETED, $minutes.'m')).' / '.$minutes),
            $this->selection->zeroIfMetrics(),
            'vector(-1)',
        );

        return Common::stat(
            PanelRegistry::COMPLETED,
            'Completed',
            $gridPos,
            [
                $this->common->target('A', $this->attemptsPerStep(Attempts::COMPLETED), instant: false),
                $this->common->target('B', $perMinute, legend: 'per_minute'),
            ],
            graphMode: 'area',
            calcs: ['sum'],
            withName: true,
            transformations: [
                Common::configFromData('B', [Common::handler('per_minute', 'displayName')]),
                Common::renameByRegex('^(\d+)$', 'RPM: $1'),
                // No metrics at all: without a caption Grafana would name the series after the query
                Common::renameByRegex('^-1$', 'RPM: '.Glyph::DASH),
            ],
        );
    }

    /**
     * Delayed: jobs whose time has not come yet (delay() and retries in their backoff). Due ones are already
     * pending and are subtracted. There are no delayed counts per class: a dash with jobs selected.
     *
     * @param  array{h: int, w: int, x: int, y: int}  $gridPos
     * @return array<string, mixed>
     */
    private function delayed(array $gridPos): array
    {
        $delayed = $this->selection->byJobSelection(
            Promql::sum($this->promql->selector(Metric::QueueDelayedJobs)).' - '.Promql::sum($this->promql->selector(Metric::QueueDelayedDueJobs)),
        );

        return Common::stat(
            PanelRegistry::DELAYED,
            'Delayed',
            $gridPos,
            [$this->common->target('A', $delayed, instant: false)],
            graphMode: 'area',
        );
    }

    /**
     * Failed: the number is the failures in the period, the background the failures per step. The colour is
     * their share of the attempts (query C), otherwise a production tile with constant failures would never
     * leave red: yellow from 1%, red from 5% of the attempts, but measured against at least 100 attempts so a
     * couple of failures among few attempts do not colour it. Without attempt data the static thresholds count
     * failures. The caption is the success rate like the tables (query B), truncated: 99.96% is 99.9%.
     *
     * @param  array{h: int, w: int, x: int, y: int}  $gridPos
     * @return array<string, mixed>
     */
    private function failed(array $gridPos): array
    {
        $attempts = Promql::sum($this->attempts->delta(Attempts::FINISHED, Promql::RANGE));
        $success = Promql::call(
            'floor',
            '1000 * '.Promql::sum($this->attempts->delta(Attempts::COMPLETED, Promql::RANGE)).' / '.Promql::paren($attempts.' > 0'),
        ).' / 10 or vector(-1)';
        $minimum = (string) Thresholds::MIN_ATTEMPTS;

        return Common::stat(
            PanelRegistry::FAILED,
            'Failed',
            $gridPos,
            [
                $this->common->target('A', $this->attemptsPerStep(Attempts::FAILED), instant: false),
                $this->common->target('B', $success, legend: 'success'),
                $this->common->target('C', Promql::call('clamp_min', $attempts, $minimum).' or vector('.$minimum.')', legend: 'attempts'),
            ],
            thresholds: Common::thresholds(
                'green',
                [Thresholds::FAILED_WARNING_COUNT, 'yellow'],
                [Thresholds::FAILED_CRITICAL_COUNT, 'red'],
            ),
            colorMode: 'background',
            graphMode: 'area',
            calcs: ['sum'],
            withName: true,
            transformations: [
                Common::configFromData('B', [Common::handler('success', 'displayName')]),
                Common::renameByRegex('^(\d+)$', 'Success: $1.0%'),
                Common::renameByRegex('^(\d+\.\d)$', 'Success: $1%'),
                // No attempts in the period
                Common::renameByRegex('^-1$', 'Success: '.Glyph::DASH),
                Common::calculate('C', 'warning', 'attempts', '*', Json::number(Thresholds::FAILURE_SHARE_WARNING)),
                Common::calculate('C', 'critical', 'attempts', '*', Json::number(Thresholds::FAILURE_SHARE_CRITICAL)),
                Common::configFromData('C', [
                    Common::handler('attempts', '__ignore'),
                    Common::thresholdHandler('warning', 'yellow'),
                    Common::thresholdHandler('critical', 'red'),
                ]),
            ],
        );
    }

    /**
     * Alerts: the number of the dashboard's rules that are not normal (firing or pending). Red with firing
     * rules, yellow with only pending ones or while a resolved alert fired less than the stabilization period
     * ago: zero does not yet mean things settled. The caption is the state ("Firing", "Pending", "Stable in
     * 12m" rounded up from the last firing point, else "Stable"); it is always present, otherwise the number
     * would jump. Which rules fire is behind the dashboard's Alert rules button.
     *
     * @param  array{h: int, w: int, x: int, y: int}  $gridPos
     * @return array<string, mixed>
     */
    private function alerts(array $gridPos): array
    {
        $count = Promql::either(Promql::count(Promql::count($this->alerts->seen(), 'grafana_rule_uid')), 'vector(0)');
        $level = Promql::either(
            '2 * '.Promql::group($this->alerts->seen('firing')),
            Promql::group($this->alerts->seen('pending')),
            Promql::group($this->alerts->seen('firing', AlertState::stabilization())),
            'vector(0)',
        );
        $remaining = Promql::call(
            'ceil',
            Promql::paren((Thresholds::ALERT_STABILIZATION_MINUTES * 60).' - '.Promql::paren('time() - '.$this->alerts->lastFiring())).' / 60',
        );
        $caption = Promql::withLabel(Promql::group($this->alerts->seen('firing')), 'display', 'Firing');
        $caption = Promql::orOn($caption, Promql::withLabel(Promql::group($this->alerts->seen('pending')), 'display', 'Pending'));
        $caption = Promql::orOn($caption, Promql::labelReplace(Promql::countValues('v', $remaining.' > 0'), 'display', 'Stable in ${1}m', 'v', '(.*)'));
        $caption = Promql::orOn($caption, Promql::withLabel('vector(1)', 'display', 'Stable'));

        return Common::stat(
            PanelRegistry::ALERTS,
            'Alerts',
            $gridPos,
            [
                $this->common->target('A', $count, instant: false),
                $this->levelColorTarget('C', $level),
                $this->common->target('B', $caption, legend: 'caption'),
            ],
            thresholds: Common::thresholds('green'),
            colorMode: 'background',
            graphMode: 'area',
            withName: true,
            transformations: [
                Common::labelsToFields('B'),
                Common::configFromData('B', [Common::handler('display', 'displayName')]),
                ...$this->levelColorTransformations('C'),
            ],
        );
    }

    private function attemptsPerStep(string $statuses): string
    {
        return Promql::sum($this->attempts->perStep($statuses)).' or '.$this->selection->zeroIfMetrics();
    }

    /**
     * The level query of a level-coloured tile (legacy `level_color_target`).
     *
     * @return array<string, mixed>
     */
    private function levelColorTarget(string $refId, string $level, bool $jobFilter = true): array
    {
        $k = self::LEVEL_COLOR_K;
        $expr = $k.' * '.Promql::paren(Promql::paren($level).' < bool 1').' * 2 + '.$k.' * '.Promql::paren(Promql::paren($level).' < bool 2');

        return $this->common->target($refId, $expr, jobFilter: $jobFilter, legend: 'level');
    }

    /**
     * Yellow and red thresholds from the level query (legacy `level_color_transformations`). The steps are added
     * in field order and Grafana takes the last one that matches, so yellow must come before red.
     *
     * @return list<array<string, mixed>>
     */
    private function levelColorTransformations(string $refId): array
    {
        return [
            Common::calculate($refId, 'warning', 'level', '-', (string) self::LEVEL_COLOR_K),
            Common::calculate($refId, 'critical', 'level', '-', '0'),
            Common::configFromData($refId, [
                Common::handler('level', '__ignore'),
                Common::thresholdHandler('warning', 'yellow'),
                Common::thresholdHandler('critical', 'red'),
            ]),
        ];
    }
}
