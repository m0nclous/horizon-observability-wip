<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Grafana;

/**
 * Thresholds and windows shared by the tiles, the tables and the alert rules, so a tile turns red by the same
 * rule that makes an alert fire.
 *
 * @internal
 */
final class Thresholds
{
    /**
     * Horizon tile: uptime over the period below this share (percent) turns a running Horizon yellow.
     */
    public const UPTIME_WARNING_PERCENT = 99;

    /**
     * Supervisor utilisation zones (percent): yellow from the first, red from the second.
     */
    public const LOAD_WARNING_PERCENT = 70;

    public const LOAD_CRITICAL_PERCENT = 90;

    /**
     * Oldest wait relative to the queue's `horizon.waits` limit: yellow above 1x (Horizon's LongWaitDetected),
     * red and the long-wait alert above 2x.
     */
    public const WAIT_WARNING_FACTOR = 1;

    public const WAIT_CRITICAL_FACTOR = 2;

    /**
     * Failed tile and failure alert: share of failed attempts, measured against at least this many attempts.
     */
    public const FAILURE_SHARE_WARNING = 0.01;

    public const FAILURE_SHARE_CRITICAL = 0.05;

    public const MIN_ATTEMPTS = 100;

    /**
     * Failed tile without attempt data: absolute counts.
     */
    public const FAILED_WARNING_COUNT = 1;

    public const FAILED_CRITICAL_COUNT = 5;

    /**
     * Success rate columns: red below the first share, yellow below the second.
     */
    public const SUCCESS_RATE_CRITICAL = 0.9;

    public const SUCCESS_RATE_WARNING = 0.99;

    /**
     * Completed tile and batch table: jobs per minute over this many minutes.
     */
    public const RPM_WINDOW_MINUTES = 5;

    /**
     * Alerts tile and alert sound: Grafana writes `GRAFANA_ALERTS` once per evaluation (1m), so a rule counts while
     * it has a point in this window (1.5 evaluation intervals).
     */
    public const ALERT_SEEN_WINDOW = '90s';

    /**
     * Alerts tile: yellow for this many minutes after the last firing point.
     */
    public const ALERT_STABILIZATION_MINUTES = 15;

    /**
     * Alert rules: evaluation interval, pending period, default lookback and the failure-rate window.
     */
    public const ALERT_INTERVAL = '1m';

    public const ALERT_FOR = '5m';

    public const ALERT_LOOKBACK_SECONDS = 600;

    public const FAILURE_WINDOW_MINUTES = 15;

    /**
     * Stall rule of the stream mode: the last live push is older than this at scrape time, for this long
     * (ADDENDUM R4.11).
     */
    public const LIVE_STALL_SECONDS = 30;

    public const LIVE_STALL_FOR = '1m';
}
