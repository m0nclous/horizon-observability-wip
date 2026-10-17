<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Grafana;

/**
 * Everything a render of the dashboard and the alert rules may vary.
 *
 * The builders read these values instead of literals. The defaults are the package render (the `--defaults` render
 * of `horizon-observability:grafana`, Arch Q5.1 and Q5.2); {@see LegacyProfile} fills them with the values the
 * legacy generator hard-coded.
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
     * @param  string  $metricPrefix  prefix of every package metric name, including the trailing underscore
     * @param  string  $uid  dashboard uid, also the prefix of the alert rule uids
     * @param  list<string>  $tags
     * @param  string  $prometheusDatasourceRegex  name regex of the hidden `datasource` variable; empty picks Grafana's default
     * @param  string  $infinityDatasourceRegex  name regex of the hidden `app_api` variable; empty picks the first Infinity datasource
     * @param  string  $jobListUrl  Infinity URL of a job list, `{list}` is replaced by pending, completed, failed or delayed
     * @param  string  $busyWindow  default of the hidden `busy_window` variable, the window of the worker utilisation
     *                              (legacy `BUSY_WINDOW`, a literal in the pilot), at least one scrape interval
     * @param  string  $refresh  dashboard auto-refresh interval
     * @param  string  $alertRuleUidRegex  `grafana_rule_uid` regex of the Alerts tile and of the alert sound
     * @param  string  $alertFolder  folder of the alert rule group
     * @param  string  $alertDatasourceUid  Prometheus datasource uid of the alert rules (Grafana interpolates `${ENV}` at provisioning)
     * @param  string|null  $alertSoundScript  `afterRender` JavaScript of the hidden alert sound panel; null reads
     *                                         `resources/grafana/js/alert-sound.js`
     * @param  string|null  $job  the Prometheus job the dashboard selects by default and the alert rules are scoped to
     *                            (`--job`); null selects all jobs
     * @param  bool  $dedupe  wrap every selector in a replica dedupe aggregation (WP8B, Arch Q5.6 step 10)
     * @param  bool  $legacy  render what the pilot rendered where WP8B changed the output (metric names, ...);
     *                        TEMPORARY, set by {@see LegacyProfile} only and deleted with it at the end of WP8B
     */
    public function __construct(
        public string $metricPrefix = 'ho_',
        public string $uid = self::DEFAULT_UID,
        public string $title = 'Queues',
        public array $tags = ['horizon', 'queue'],
        public string $prometheusDatasourceRegex = '',
        public string $infinityDatasourceRegex = '',
        public string $jobListUrl = '${api_path}/jobs/{list}',
        public string $busyWindow = '15s',
        public string $refresh = '15s',
        public string $alertRuleUidRegex = 'horizon-.*',
        public string $alertFolder = 'Horizon',
        public string $alertDatasourceUid = '${HPG_PROMETHEUS_DATASOURCE_UID}',
        public ?string $alertSoundScript = null,
        public LiveMode $live = LiveMode::Off,
        public GrafanaVersion $grafana = GrafanaVersion::Grafana13,
        public ?string $job = null,
        public bool $dedupe = false,
        public bool $legacy = false,
    ) {}

    /**
     * The PromQL builder of the dashboard: selectors scoped to the `prometheus_job` variable.
     */
    public function promql(): Promql
    {
        return new Promql($this->metricPrefix, $this->legacy, $this->legacy ? null : Promql::JOB_VARIABLE);
    }

    /**
     * The PromQL builder of the alert rules, which Grafana does not interpolate: selectors scoped to
     * {@see jobRegex()}.
     */
    public function rulePromql(): Promql
    {
        return new Promql($this->metricPrefix, $this->legacy, $this->legacy ? null : $this->jobRegex());
    }

    /**
     * The job regex of the alert rules (Arch Q5.3 `J`): `.+`, or the exact `--job`.
     */
    public function jobRegex(): string
    {
        return $this->job === null ? '.+' : Promql::literalRegex($this->job);
    }

    /**
     * The `afterRender` script of the alert sound panel.
     */
    public function alertSound(): string
    {
        return $this->alertSoundScript ?? Resources::alertSoundScript();
    }

    /**
     * The Infinity URL of one job list (`pending`, `completed`, `failed` or `delayed`).
     */
    public function jobListUrl(string $list): string
    {
        return str_replace('{list}', $list, $this->jobListUrl);
    }
}
