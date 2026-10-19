<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Grafana;

use DF\HorizonObservability\Grafana\Live\LiveMode;
use DF\HorizonObservability\Grafana\Queries\Attempts;
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
     * The uid suffixes of the core rules, which the Alerts tile and the alert sound watch.
     */
    public const CORE_RULES = ['no-metrics', 'down', 'long-wait', 'failures'];

    /**
     * The uid suffix of the stall rule of the stream mode, a core rule there.
     */
    public const LIVE_RULE = 'live';

    /**
     * The uid suffixes of the opt-in health rules.
     */
    public const HEALTH_RULES = ['collector', 'duplicates'];

    private const COLLECTOR_FOR = '15m';

    private const DUPLICATES_FOR = '10m';

    /**
     * @return array{apiVersion: int, groups: list<array<string, mixed>>, deleteRules?: list<array{orgId: int, uid: string}>}
     */
    public function build(): array
    {
        $dashboard = (new DashboardBuilder($this->options))->build();
        $promql = $this->options->rulePromql();
        // One alert per application (scrape job) and queue
        $by = static fn (string ...$labels): array => ['job', ...array_values($labels)];
        $status = $promql->selector(Metric::HorizonStatus);
        $window = Thresholds::FAILURE_WINDOW_MINUTES.'m';
        $attemptsIn = fn (string $statuses): string => Promql::sum(
            $promql->over('increase', Metric::JobsTotal, $window, [Promql::re('status', $statuses)]),
            ...$by('queue'),
        );
        $job = ' ({{ $labels.job }})';

        $rules = [
            // No Horizon status series: Prometheus does not scrape the application. Without this rule the others
            // would stay silent, having no data at all. With all jobs it fires only when no job reports.
            $this->rule(
                'no-metrics',
                'No queue metrics',
                Promql::count($status),
                $this->below(1),
                noData: 'Alerting',
                summary: 'Prometheus has no '.$promql->name(Metric::HorizonStatus).': the metrics endpoint of the application is not scraped',
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
                for: $this->duration($this->options->downFor),
            ),
            // As the red Pending tile: the oldest pending job of a queue waits longer than twice its horizon.waits
            // limit (strictly, like Horizon's LongWaitDetected). One alert per queue.
            $this->rule(
                'long-wait',
                sprintf('Queue wait over %dx limit', Thresholds::WAIT_CRITICAL_FACTOR),
                Promql::binary(
                    Promql::max($promql->selector(Metric::QueueOldestPendingJobAge), ...$by('queue')),
                    '/',
                    Promql::max($promql->selector(Metric::WaitThreshold), ...$by('queue')),
                    on: $by('queue'),
                ),
                ['type' => 'gt', 'params' => [Thresholds::WAIT_CRITICAL_FACTOR]],
                noData: 'OK',
                summary: $this->withValue('Oldest pending job in {{ $labels.queue }}'.$job.' waits {{ printf "%.1f" .Value }}x the queue wait limit', 'No wait data for {{ $labels.queue }}'.$job),
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
                summary: $this->withValue('{{ humanizePercentage .Value }} of attempts in {{ $labels.queue }}'.$job.' failed in '.Thresholds::FAILURE_WINDOW_MINUTES.' minutes', 'No attempt data for {{ $labels.queue }}'.$job),
                panelId: PanelRegistry::idOf($dashboard, 'Failed'),
                lookbackSeconds: Thresholds::FAILURE_WINDOW_MINUTES * 60,
            ),
        ];

        if ($this->options->live === LiveMode::Stream) {
            $rules[] = $this->liveRule($promql, PanelRegistry::idOf($dashboard, 'Supervisor Processes'));
        }

        if ($this->options->healthRules) {
            array_push($rules, ...$this->healthRules($promql, PanelRegistry::idOf($dashboard, 'Horizon')));
        }

        $provisioning = ['apiVersion' => 1, 'groups' => [[
            'orgId' => self::ORG_ID,
            'name' => self::GROUP,
            'folder' => $this->options->alertFolder,
            'interval' => Thresholds::ALERT_INTERVAL,
            'rules' => $rules,
        ]]];

        $unrendered = $this->unrenderedRules($rules);

        if ($unrendered !== []) {
            $provisioning['deleteRules'] = array_map(static fn (string $uid): array => ['orgId' => self::ORG_ID, 'uid' => $uid], $unrendered);
        }

        return $provisioning;
    }

    /**
     * The optional rules of this uid that the render leaves out (`<uid>-live` without `--live=stream`, the health
     * rules without `--health-rules`), for the provisioning file's `deleteRules`: Grafana keeps a provisioned rule
     * that disappears from the file, so after a switch from stream to poll the stall rule would fire once the stream
     * command stops (its last push timestamp stays exported). Deleting a rule that does not exist is a no-op (probed on
     * Grafana 12.4.13 and 13.2.4).
     *
     * @param  list<array<string, mixed>>  $rules  the rendered rules
     * @return list<string>
     */
    private function unrenderedRules(array $rules): array
    {
        $rendered = array_column($rules, 'uid');
        $optional = array_map(fn (string $suffix): string => $this->options->uid.'-'.$suffix, [self::LIVE_RULE, ...self::HEALTH_RULES]);

        return array_values(array_filter($optional, static fn (string $uid): bool => ! in_array($uid, $rendered, true)));
    }

    /**
     * `<uid>-live` "Live overview stalled", rendered with `--live=stream` only: no stream command has pushed the live
     * tiles for 30 seconds, so they show stale values. The age of the last push is taken at scrape time,
     * `timestamp(x) - x` on the raw replica series (after an aggregation `timestamp()` would return the evaluation
     * time), so neither the scrape interval nor the rule evaluation adds to it; the outer max is the replica dedupe.
     * No data is OK: the exporter has no push timestamp before the stream ever ran.
     *
     * @return array<string, mixed>
     */
    private function liveRule(Promql $promql, int $panelId): array
    {
        $pushed = $promql->replicas(Metric::LivePushTimestamp);

        return $this->rule(
            self::LIVE_RULE,
            'Live overview stalled',
            Promql::max(Promql::call('timestamp', $pushed).' - '.$pushed, 'job'),
            ['type' => 'gt', 'params' => [Thresholds::LIVE_STALL_SECONDS]],
            noData: 'OK',
            summary: $this->withValue(
                'The live overview of {{ $labels.job }} was last pushed {{ printf "%.0f" .Value }} s before the scrape; check the horizon-observability:stream process',
                'No push data for {{ $labels.job }}',
            ),
            panelId: $panelId,
            for: Thresholds::LIVE_STALL_FOR,
        );
    }

    /**
     * The opt-in health rules (`--health-rules`): a collector that keeps failing on any replica, and more than one
     * scraped target per job (per-pod scraping), which the dedupe hides from the panels. Both read the replicas one
     * by one, so they are the only selectors without the dedupe. Neither is in the Alerts tile: they are about the
     * exporter, not the queues.
     *
     * @return list<array<string, mixed>>
     */
    private function healthRules(Promql $promql, int $panelId): array
    {
        return [
            // Self-metrics are per replica: each replica runs its own collectors, so a collector that fails on one
            // replica only must fire, and the replica dedupe (a max) would hide it. Auto-disabled collectors emit
            // no success series, so they never fire.
            $this->rule(
                'collector',
                'Metrics collector failing',
                Promql::min($promql->replicas(Metric::CollectorSuccess), 'job', 'collector'),
                $this->below(1),
                noData: 'OK',
                summary: 'The {{ $labels.collector }} collector of {{ $labels.job }} fails; run php artisan horizon-observability:check',
                panelId: $panelId,
                for: self::COLLECTOR_FOR,
            ),
            // Counts the replicas on purpose, so the only selector without the dedupe
            $this->rule(
                'duplicates',
                'Duplicate metrics exporters',
                Promql::count($promql->replicas(Metric::HorizonStatus), 'job'),
                ['type' => 'gt', 'params' => [1]],
                noData: 'OK',
                summary: $this->withValue('{{ .Value }} targets of {{ $labels.job }} export the same metrics; scrape one target per application', 'No target data for {{ $labels.job }}'),
                panelId: $panelId,
                for: self::DUPLICATES_FOR,
            ),
        ];
    }

    /**
     * A summary that reads query A as `.` (its value is `.Value`), and $missing when the rule has no value: Grafana
     * expands the annotations in the no-data state too, where `$values.A.Value` is nil and a template function
     * given it fails ("Error in expanding template"; the pilot's failure rule logged it on every evaluation without
     * attempts). `with $values.A` tells no data from a zero value (probed on Grafana 12.4.13 and 13.2.4).
     */
    private function withValue(string $summary, string $missing): string
    {
        return '{{ with $values.A }}'.$summary.'{{ else }}'.$missing.'{{ end }}';
    }

    /**
     * The uid suffixes of the core rules of a live mode: {@see CORE_RULES}, and {@see LIVE_RULE} in stream mode.
     *
     * @return list<string>
     */
    public static function coreRules(LiveMode $live = LiveMode::Off): array
    {
        return $live === LiveMode::Stream ? [...self::CORE_RULES, self::LIVE_RULE] : self::CORE_RULES;
    }

    /**
     * The `grafana_rule_uid` regex of the core rules of a dashboard uid: the Alerts tile and the alert sound watch
     * exactly these, not the opt-in health rules or other dashboards' rules.
     */
    public static function coreRuleUids(string $uid, LiveMode $live = LiveMode::Off): string
    {
        return $uid.'-('.implode('|', self::coreRules($live)).')';
    }

    /**
     * A pending period as Grafana writes it: whole minutes as `5m`, anything else in seconds.
     */
    private function duration(int $seconds): string
    {
        return $seconds > 0 && $seconds % 60 === 0 ? intdiv($seconds, 60).'m' : $seconds.'s';
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
        string $for = Thresholds::ALERT_FOR,
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
            'for' => $for,
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
