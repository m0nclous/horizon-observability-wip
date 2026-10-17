<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Grafana;

/**
 * Everything a render of the dashboard and the alert rules may vary.
 *
 * The builders read these values instead of literals. {@see LegacyProfile} fills them with the values the
 * legacy generator hard-coded; WP8B derives them from the `horizon-observability:grafana` options and the config.
 *
 * @internal
 */
final readonly class DashboardOptions
{
    /**
     * @param  string  $metricPrefix  prefix of every package metric name, including the trailing underscore
     * @param  string  $uid  dashboard uid, also the prefix of the alert rule uids
     * @param  list<string>  $tags
     * @param  string  $prometheusDatasourceRegex  name regex of the hidden `datasource` variable; empty picks Grafana's default
     * @param  string  $infinityDatasourceRegex  name regex of the hidden `app_api` variable; empty picks the first Infinity datasource
     * @param  string  $jobListUrl  Infinity URL of a job list, `{list}` is replaced by pending, completed, failed or delayed
     * @param  string  $busyWindow  window of the worker utilisation (legacy `BUSY_WINDOW`), at least one scrape interval
     * @param  string  $refresh  dashboard auto-refresh interval
     * @param  string  $alertRuleUidRegex  `grafana_rule_uid` regex of the Alerts tile and of the alert sound
     * @param  string  $alertFolder  folder of the alert rule group
     * @param  string  $alertDatasourceUid  Prometheus datasource uid of the alert rules (Grafana interpolates `${ENV}` at provisioning)
     * @param  string  $alertSoundScript  `afterRender` JavaScript of the hidden alert sound panel
     * @param  string|null  $job  job regex every selector is scoped to; null renders no job matcher (WP8B)
     * @param  bool  $dedupe  wrap every selector in a replica dedupe aggregation (WP8B, Arch Q5.6 step 10)
     */
    public function __construct(
        public string $metricPrefix,
        public string $uid,
        public string $title,
        public array $tags,
        public string $prometheusDatasourceRegex,
        public string $infinityDatasourceRegex,
        public string $jobListUrl,
        public string $busyWindow,
        public string $refresh,
        public string $alertRuleUidRegex,
        public string $alertFolder,
        public string $alertDatasourceUid,
        public string $alertSoundScript,
        public LiveMode $live = LiveMode::Off,
        public GrafanaVersion $grafana = GrafanaVersion::Grafana13,
        public ?string $job = null,
        public bool $dedupe = false,
    ) {}

    /**
     * The Infinity URL of one job list (`pending`, `completed`, `failed` or `delayed`).
     */
    public function jobListUrl(string $list): string
    {
        return str_replace('{list}', $list, $this->jobListUrl);
    }
}
