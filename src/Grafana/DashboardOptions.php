<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Grafana;

use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Grafana\Live\LiveMode;
use InvalidArgumentException;

/**
 * Everything a render of the dashboard and the alert rules may vary (the `horizon-observability:grafana` options,
 * Arch Q5.1 and Q5.2), and the values derived from them. The defaults are the package render: the `--defaults`
 * render committed in `resources/grafana`.
 *
 * @internal
 */
final readonly class DashboardOptions
{
    /**
     * Dashboard uid of the package render; the alert rule uids start with it (Arch Q5.2).
     */
    public const DEFAULT_UID = 'horizon-observability';

    /**
     * The Prometheus datasource of the alert rules: alert rules have no default datasource, so Grafana interpolates
     * this environment variable at provisioning (Arch Q5.3).
     */
    public const DATASOURCE_PLACEHOLDER = '${HORIZON_OBSERVABILITY_PROMETHEUS_UID}';

    /**
     * The recommended scrape interval of the package endpoint and the supported range, seconds (ADDENDUM R3.2).
     */
    public const DEFAULT_SCRAPE_INTERVAL = 10;

    public const MIN_SCRAPE_INTERVAL = 5;

    public const MAX_SCRAPE_INTERVAL = 60;

    /**
     * The dashboard refresh in poll mode and its range, seconds (ADDENDUM R4.1, R2.5): Grafana's default
     * `min_refresh_interval` is 5 s, and provisioning raises a faster refresh to it.
     */
    public const DEFAULT_LIVE_POLL_INTERVAL = 5;

    public const MIN_LIVE_POLL_INTERVAL = 1;

    public const MAX_LIVE_POLL_INTERVAL = 300;

    /**
     * A Grafana Live stream id, as `live.stream.id` accepts it (ADDENDUM R4.10): the channels are
     * `stream/<id>/tiles` and `stream/<id>/delayed`.
     */
    public const STREAM_ID_PATTERN = '/^[A-Za-z0-9_-]{1,64}$/';

    /**
     * The smallest busy window and counter lookback, seconds (ADDENDUM R3.3).
     */
    private const MIN_BUSY_WINDOW = 15;

    private const MIN_COUNTER_LOOKBACK = 600;

    /**
     * @param  string  $metricPrefix  prefix of every package metric name, including the trailing underscore
     * @param  string  $uid  dashboard uid, also the prefix of the alert rule uids
     * @param  list<string>  $tags
     * @param  string  $prometheusDatasourceRegex  name regex of the hidden `datasource` variable; empty picks Grafana's default
     * @param  string  $infinityDatasourceRegex  name regex of the hidden `app_api` variable; empty picks the first Infinity datasource
     * @param  int  $scrapeInterval  S, the scrape interval of the package endpoint in seconds (`--scrape-interval`,
     *                               5 to 60): the default of the hidden `scrape_interval` variable, which every
     *                               Prometheus panel takes as its min interval, and of the windows derived from it
     * @param  string  $alertFolder  folder of the alert rule group
     * @param  string  $alertDatasourceUid  Prometheus datasource uid of the alert rules (Grafana interpolates `${ENV}` at provisioning)
     * @param  LiveMode  $live  how the Overview tiles get their current values (`--live`, ADDENDUM R4.1); the package
     *                          render polls
     * @param  string|null  $job  the Prometheus job the dashboard selects by default and the alert rules are scoped to
     *                            (`--job`); null selects all jobs
     * @param  bool  $dedupe  wrap every package selector in the replica dedupe `max by (job, <labels>)` (Arch Q5.6
     *                        step 10); every render of the command has it, false only shows what it prevents
     * @param  bool  $healthRules  add the opt-in health rules `<uid>-collector` and `<uid>-duplicates` (Arch Q1.5)
     * @param  int  $livePollInterval  the dashboard refresh in poll mode, seconds (`--live-poll-interval`, 1 to 300)
     * @param  string  $liveStreamId  the Grafana Live stream id the stream tiles subscribe to (`--live-stream-id`,
     *                                {@see STREAM_ID_PATTERN}); it must match `live.stream.id` of the stream command
     * @param  bool  $recordingRules  also render the opt-in Prometheus recording rules (`--recording-rules`, ADDENDUM
     *                                R2.4 O9), which the dashboard does not read
     *
     * @throws InvalidArgumentException for a poll interval or a stream id out of range
     */
    public function __construct(
        public string $metricPrefix = 'ho_',
        public string $uid = self::DEFAULT_UID,
        public string $title = 'Queues',
        public array $tags = ['horizon', 'queue'],
        public string $prometheusDatasourceRegex = '',
        public string $infinityDatasourceRegex = '',
        public int $scrapeInterval = self::DEFAULT_SCRAPE_INTERVAL,
        public string $alertFolder = 'Horizon',
        public string $alertDatasourceUid = self::DATASOURCE_PLACEHOLDER,
        public LiveMode $live = LiveMode::Poll,
        public ?string $job = null,
        public bool $dedupe = true,
        public bool $healthRules = false,
        public int $livePollInterval = self::DEFAULT_LIVE_POLL_INTERVAL,
        public string $liveStreamId = Config::DEFAULT_LIVE_STREAM_ID,
        public bool $recordingRules = false,
    ) {
        if ($livePollInterval < self::MIN_LIVE_POLL_INTERVAL || $livePollInterval > self::MAX_LIVE_POLL_INTERVAL) {
            throw new InvalidArgumentException(sprintf(
                'The live poll interval must be %d to %d seconds, not %d.',
                self::MIN_LIVE_POLL_INTERVAL,
                self::MAX_LIVE_POLL_INTERVAL,
                $livePollInterval,
            ));
        }

        if (preg_match(self::STREAM_ID_PATTERN, $liveStreamId) !== 1) {
            throw new InvalidArgumentException('The live stream id must be 1 to 64 characters of A-Z, a-z, 0-9, "_" and "-".');
        }
    }

    /**
     * The scrape interval as a PromQL duration, the default of the `scrape_interval` variable.
     */
    public function scrapeIntervalText(): string
    {
        return Promql::duration($this->scrapeInterval);
    }

    /**
     * The default of the `busy_window` variable: max(15s, 2 x S), so every window holds at least one sample with
     * jitter; the utilisation is a per-sample average, so any window of at least S is correct (ADDENDUM R3.3).
     */
    public function busyWindow(): string
    {
        return Promql::duration(max(self::MIN_BUSY_WINDOW, 2 * $this->scrapeInterval));
    }

    /**
     * How far back the start of a period looks for the last counter value: max(10m, 10 x S), so a few missed
     * scrapes at the boundary do not break the count (ADDENDUM R3.3; the pilot looked back a day).
     */
    public function counterLookback(): string
    {
        return Promql::duration(max(self::MIN_COUNTER_LOOKBACK, 10 * $this->scrapeInterval));
    }

    /**
     * The minutes of the RPM window (Completed caption, batch RPM): max(5m, 5 x S) (ADDENDUM R3.3).
     */
    public function rpmMinutes(): int
    {
        return max(Thresholds::RPM_WINDOW_MINUTES, intdiv(5 * $this->scrapeInterval + 59, 60));
    }

    /**
     * The dashboard refresh (ADDENDUM R2.5, R3.3): the poll interval in poll mode, where the live tiles refresh with
     * the dashboard; S in off and stream modes, where a faster refresh would only re-read the same samples (stream
     * tiles update by themselves).
     */
    public function refresh(): string
    {
        return $this->live === LiveMode::Poll ? Promql::duration($this->livePollInterval) : $this->scrapeIntervalText();
    }

    /**
     * The `grafana_rule_uid` regex of the Alerts tile and the alert sound: exactly the core rules of this uid, with
     * `<uid>-live` in stream mode (ADDENDUM R4.11).
     */
    public function alertRules(): string
    {
        return AlertRulesBuilder::coreRuleUids($this->uid, $this->live);
    }

    /**
     * The PromQL builder of the dashboard: selectors scoped to the `prometheus_job` variable.
     */
    public function promql(): Promql
    {
        return new Promql($this->metricPrefix, Promql::JOB_VARIABLE, $this->dedupe);
    }

    /**
     * The PromQL builder of the alert rules, which Grafana does not interpolate: selectors scoped to
     * {@see jobRegex()}.
     */
    public function rulePromql(): Promql
    {
        return new Promql($this->metricPrefix, $this->jobRegex(), $this->dedupe);
    }

    /**
     * The job regex of the alert rules (Arch Q5.3 `J`): `.+`, or the exact `--job`.
     */
    public function jobRegex(): string
    {
        return $this->job === null ? '.+' : Promql::literalRegex($this->job);
    }
}
