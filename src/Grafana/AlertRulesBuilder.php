<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Grafana;

use DF\HorizonObservability\Grafana\Queries\Attempts;
use DF\HorizonObservability\Grafana\Queries\Selection;
use DF\HorizonObservability\Grafana\Queries\Waits;
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
     * @return array{apiVersion: int, groups: list<array<string, mixed>>}
     */
    public function build(): array
    {
        $dashboard = (new DashboardBuilder($this->options))->build();
        $promql = $this->options->rulePromql();
        $status = $promql->selector('horizon_status');
        $window = Thresholds::FAILURE_WINDOW_MINUTES.'m';
        $attemptsIn = fn (string $statuses): string => Promql::sum(
            Promql::range('increase', $promql->selector('queue_jobs_total', Promql::re('status', $statuses)), $window),
            'queue',
        );

        $rules = [
            // No Horizon status series: Prometheus does not scrape the application. Without this rule the others
            // would stay silent, having no data at all.
            $this->rule(
                'no-metrics',
                'No queue metrics',
                Promql::count($status),
                $this->below(1),
                noData: 'Alerting',
                summary: 'Prometheus has no '.$promql->metric('horizon_status').', /metrics of the app is not scraped',
                panelId: PanelRegistry::idOf($dashboard, 'Horizon'),
            ),
            // As the red Horizon tile: paused or not running. Without metrics No queue metrics fires instead.
            $this->rule(
                'down',
                'Horizon is down',
                Promql::min($status),
                $this->below(1),
                noData: 'OK',
                summary: 'Horizon is paused or not running',
                panelId: PanelRegistry::idOf($dashboard, 'Horizon'),
            ),
            // As the red Pending tile: the oldest pending job of a queue waits longer than twice its horizon.waits
            // limit (strictly, like Horizon's LongWaitDetected). One alert per queue.
            $this->rule(
                'long-wait',
                sprintf('Queue wait over %dx limit', Thresholds::WAIT_CRITICAL_FACTOR),
                (new Waits($promql, new Selection($promql)))->ratio(),
                ['type' => 'gt', 'params' => [Thresholds::WAIT_CRITICAL_FACTOR]],
                noData: 'OK',
                summary: 'Oldest pending job in {{ $labels.queue }} waits {{ printf "%.1f" $values.A.Value }}x the queue wait limit',
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
                summary: '{{ humanizePercentage $values.A.Value }} of attempts in {{ $labels.queue }} failed in '
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
