<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Grafana\Queries;

use DF\HorizonObservability\Grafana\Promql;
use DF\HorizonObservability\Grafana\Thresholds;

/**
 * The dashboard's alert rules as Grafana's state history in Prometheus (`GRAFANA_ALERTS`, one point per rule
 * evaluation). A resolved alert is no longer written and Prometheus would look back 5 minutes for a point, so
 * an alert counts only with a point in {@see Thresholds::ALERT_SEEN_WINDOW}.
 *
 * @internal
 */
final readonly class AlertState
{
    /**
     * Grafana's default `prometheus_metric_name` of the state history.
     */
    private const METRIC = 'GRAFANA_ALERTS';

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
     * Rule series with a point in the window (legacy `alerts_seen`).
     */
    public function seen(string $state = '', string $window = Thresholds::ALERT_SEEN_WINDOW): string
    {
        return Promql::range('last_over_time', $this->selector($state), $window);
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
