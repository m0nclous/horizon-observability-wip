<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Grafana;

use DF\HorizonObservability\Grafana\Queries\Attempts;
use DF\HorizonObservability\Grafana\Queries\Selection;
use DF\HorizonObservability\Grafana\Queries\Waits;
use DF\HorizonObservability\Metrics\Metric;
use LogicException;

/**
 * Renders the Grafana alert rules of the dashboard (Grafana file provisioning format, port of the legacy
 * `horizon.yml`). The rules fire by the same thresholds that turn the tiles red, and each one links to the tile
 * it explains: `__panelId__` is looked up by title in the rendered dashboard.
 *
 * There is no contact point: Grafana notifies by its default policy (email), which without SMTP only logs an
 * error. Grafana keeps rules in a folder, hence their own folder. The Prometheus datasource is given by uid
 * (an env placeholder Grafana interpolates at provisioning): unlike the dashboard, alert rules have no default
 * datasource.
 *
 * @internal
 */
final readonly class AlertRulesBuilder
{
    private const ORG_ID = 1;

    private const GROUP = 'Queues';

    /**
     * Rule titles and labels are interpolated by Grafana's provisioning; a `$` there would be read as a variable.
     */
    private const NOT_INTERPOLATED = '$';

    public function __construct(private DashboardOptions $options) {}

    /**
     * The uid suffixes of the core rules, which the Alerts tile and the alert sound watch (Arch Q5.2, Q5.3).
     */
    public const CORE_RULES = ['no-metrics', 'down', 'long-wait', 'failures'];

    /**
     * @return array{apiVersion: int, groups: list<array<string, mixed>>}
     */
    public function build(): array
    {
        $dashboard = (new DashboardBuilder($this->options))->build();
        $promql = $this->options->rulePromql();
        $legacy = $this->options->legacy;
        // One alert per application (scrape job) and queue; the pilot had no job scope
        $by = static fn (string ...$labels): array => $legacy ? array_values($labels) : ['job', ...array_values($labels)];
        $status = $promql->selector(Metric::HorizonStatus);
        $window = Thresholds::FAILURE_WINDOW_MINUTES.'m';
        $attemptsIn = fn (string $statuses): string => Promql::sum(
            $promql->over('increase', Metric::JobsTotal, $window, [Promql::re('status', $statuses)]),
            ...$by('queue'),
        );
        $job = $legacy ? '' : ' ({{ $labels.job }})';

        $rules = [
            // No Horizon status series: Prometheus does not scrape the application. Without this rule the others
            // would stay silent, having no data at all. With all jobs it fires only when no job reports.
            $this->rule(
                'no-metrics',
                'No queue metrics',
                Promql::count($status),
                $this->below(1),
                noData: 'Alerting',
                summary: $legacy
                    ? 'Prometheus has no '.$promql->name(Metric::HorizonStatus).', /metrics of the app is not scraped'
                    : 'Prometheus has no '.$promql->name(Metric::HorizonStatus).': the metrics endpoint of the application is not scraped',
                panelId: PanelRegistry::idOf($dashboard, 'Horizon'),
            ),
            // As the red Horizon tile: paused or not running. Without metrics No queue metrics fires instead.
            $this->rule(
                'down',
                'Horizon is down',
                Promql::min($status, ...$by()),
                $this->below(1),
                noData: 'OK',
                summary: 'Horizon is paused or not running'.$job,
                panelId: PanelRegistry::idOf($dashboard, 'Horizon'),
            ),
            // As the red Pending tile: the oldest pending job of a queue waits longer than twice its horizon.waits
            // limit (strictly, like Horizon's LongWaitDetected). One alert per queue.
            $this->rule(
                'long-wait',
                sprintf('Queue wait over %dx limit', Thresholds::WAIT_CRITICAL_FACTOR),
                $legacy ? (new Waits($promql, new Selection($promql)))->ratio() : Promql::binary(
                    Promql::max($promql->oldestAge(), ...$by('queue')),
                    '/',
                    Promql::max($promql->selector(Metric::WaitThreshold), ...$by('queue')),
                    on: $by('queue'),
                ),
                ['type' => 'gt', 'params' => [Thresholds::WAIT_CRITICAL_FACTOR]],
                noData: 'OK',
                summary: 'Oldest pending job in {{ $labels.queue }}'.$job.' waits {{ printf "%.1f" $values.A.Value }}x the queue wait limit',
                panelId: PanelRegistry::idOf($dashboard, 'Pending'),
            ),
            // As the red Failed tile, but per queue over the failure window: failures and timeouts from the
            // critical share of the attempts, measured against at least the minimum number of attempts.
            $this->rule(
                'failures',
                'High failure rate',
                Promql::binary(
                    $attemptsIn(Attempts::FAILED),
                    '/',
                    Promql::call('clamp_min', $attemptsIn(Attempts::FINISHED), (string) Thresholds::MIN_ATTEMPTS),
                ),
                ['type' => 'gte', 'params' => [Thresholds::FAILURE_SHARE_CRITICAL]],
                noData: 'OK',
                summary: '{{ humanizePercentage $values.A.Value }} of attempts in {{ $labels.queue }}'.$job.' failed in '
                    .Thresholds::FAILURE_WINDOW_MINUTES.' minutes',
                panelId: PanelRegistry::idOf($dashboard, 'Failed'),
                lookbackSeconds: Thresholds::FAILURE_WINDOW_MINUTES * 60,
            ),
        ];

        return ['apiVersion' => 1, 'groups' => [[
            'orgId' => self::ORG_ID,
            'name' => self::GROUP,
            'folder' => $this->options->alertFolder,
            'interval' => Thresholds::ALERT_INTERVAL,
            'rules' => $rules,
        ]]];
    }

    /**
     * The `grafana_rule_uid` regex of the core rules of a dashboard uid: the Alerts tile and the alert sound watch
     * exactly these, not the opt-in health rules or other dashboards' rules.
     */
    public static function coreRuleUids(string $uid): string
    {
        return $uid.'-('.implode('|', self::CORE_RULES).')';
    }

    /**
     * One rule: query A evaluated against a threshold expression C.
     *
     * @param  array{type: string, params: list<int|float>}  $evaluator
     * @return array<string, mixed>
     */
    private function rule(
        string $suffix,
        string $title,
        string $expr,
        array $evaluator,
        string $noData,
        string $summary,
        int $panelId,
        int $lookbackSeconds = Thresholds::ALERT_LOOKBACK_SECONDS,
    ): array {
        $uid = $this->options->uid.'-'.$suffix;

        foreach ([$uid, $title] as $interpolated) {
            if (str_contains($interpolated, self::NOT_INTERPOLATED)) {
                throw new LogicException('Alert rule uids and titles must not contain "$": '.$interpolated);
            }
        }

        return [
            'uid' => $uid,
            'title' => $title,
            'condition' => 'C',
            'data' => [
                [
                    'refId' => 'A',
                    'relativeTimeRange' => ['from' => $lookbackSeconds, 'to' => 0],
                    'datasourceUid' => $this->options->alertDatasourceUid,
                    'model' => [
                        'editorMode' => 'code',
                        'expr' => $expr,
                        'instant' => true,
                        'range' => false,
                        'refId' => 'A',
                    ],
                ],
                [
                    'refId' => 'C',
                    'datasourceUid' => '__expr__',
                    'model' => [
                        'type' => 'threshold',
                        'expression' => 'A',
                        'conditions' => [['evaluator' => $evaluator]],
                        'refId' => 'C',
                    ],
                ],
            ],
            'noDataState' => $noData,
            'execErrState' => 'Error',
            'for' => Thresholds::ALERT_FOR,
            'annotations' => [
                'summary' => $summary,
                '__dashboardUid__' => $this->options->uid,
                '__panelId__' => (string) $panelId,
            ],
            'isPaused' => false,
        ];
    }

    /**
     * @return array{type: string, params: list<int|float>}
     */
    private function below(int $value): array
    {
        return ['type' => 'lt', 'params' => [$value]];
    }
}
