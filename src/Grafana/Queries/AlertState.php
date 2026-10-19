<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Grafana\Queries;

use DF\HorizonObservability\Grafana\Promql;
use DF\HorizonObservability\Grafana\Thresholds;

/**
 * The dashboard's alert rules as Grafana's state history in Prometheus (`GRAFANA_ALERTS`). On every evaluation
 * Grafana writes a point for each rule instance that is pending or firing, stamped with the evaluation time, and a
 * stale marker when an instance leaves that state (seen on Grafana 12.4 and 13.2). So the plain selector is the state
 * at its evaluation time: an instance counts from its first point to its stale marker, and a late or missed point does
 * not drop it, since Prometheus looks back 5 minutes for the last point. A window such as the former
 * `last_over_time(...[90s])` dropped an instance whenever its next point was more than 30 s late at the query time (a
 * late evaluation or write, or a browser clock ahead of Grafana's: the dashboard queries at the browser's now), and
 * kept it for 30 s after its stale marker.
 *
 * Without a stale marker (Grafana stopped while an alert was active and did not restore its state) an instance counts
 * for up to 5 minutes after its last point.
 *
 * @internal
 */
final readonly class AlertState
{
    /**
     * Grafana's default `prometheus_metric_name` of the state history.
     */
    private const METRIC = 'GRAFANA_ALERTS';

    /**
     * The `alertstate` values of an active instance: `pending`, and `firing` for Alerting (also for Recovering, Error
     * and NoData, which alert as well).
     */
    private const ACTIVE = 'pending|firing';

    public function __construct(private string $ruleUidRegex) {}

    /**
     * The rules of this dashboard, optionally in one state such as `firing` (legacy `alerts_selector`).
     */
    public function selector(string $state = ''): string
    {
        $matchers = [Promql::re('grafana_rule_uid', $this->ruleUidRegex)];

        if ($state !== '') {
            $matchers[] = Promql::eq('alertstate', $state);
        }

        return Promql::vector(self::METRIC, ...$matchers);
    }

    /**
     * The rule instances active at the evaluation time: pending or firing, or only those in $state.
     */
    public function active(string $state = ''): string
    {
        return $state !== ''
            ? $this->selector($state)
            : Promql::vector(self::METRIC, Promql::re('grafana_rule_uid', $this->ruleUidRegex), Promql::re('alertstate', self::ACTIVE));
    }

    /**
     * The number of active rules, at the evaluation time or at $at: a rule counts once whatever its instances (one per
     * queue for the per-queue rules) and their states. Nothing when no rule is active.
     */
    public function activeRules(?string $at = null): string
    {
        $active = $at === null ? $this->active() : Promql::at($this->active(), $at);

        return Promql::count(Promql::count($active, 'grafana_rule_uid'));
    }

    /**
     * Some rule fired within the stabilization period: a firing point in it.
     */
    public function firedRecently(): string
    {
        return Promql::group(Promql::range('last_over_time', $this->selector('firing'), self::stabilization()));
    }

    /**
     * Time of the last firing point within the stabilization period (legacy `ALERTS_LAST_FIRING`).
     */
    public function lastFiring(): string
    {
        return Promql::max(Promql::range('max_over_time', Promql::call('timestamp', $this->selector('firing')), self::stabilization().':'));
    }

    /**
     * The stabilization period as a PromQL duration.
     */
    public static function stabilization(): string
    {
        return Thresholds::ALERT_STABILIZATION_MINUTES.'m';
    }
}
