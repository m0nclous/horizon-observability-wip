<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Grafana;

/**
 * The values `legacy/grafana/generate_queues_dashboard.py --prefix office` hard-coded.
 *
 * Rendering with these options reproduces `tests/Fixtures/grafana/legacy/queues.json` byte for byte and the
 * rules of `tests/Fixtures/grafana/legacy/horizon.yml` (Arch Q5.6 M1 and M2). TEMPORARY: deleted with the
 * legacy goldens at the end of WP8B.
 *
 * @internal
 */
final class LegacyProfile
{
    /**
     * @param  string  $alertSoundScript  the verbatim `afterRender` script, `tests/Fixtures/grafana/legacy/alert-sound.js`
     */
    public static function options(string $alertSoundScript): DashboardOptions
    {
        return new DashboardOptions(
            metricPrefix: 'office_',
            uid: 'horizon',
            title: 'Queues',
            tags: ['horizon', 'queue', 'office'],
            prometheusDatasourceRegex: '',
            infinityDatasourceRegex: '',
            jobListUrl: '/metrics/horizon/{list}-jobs',
            scrapeInterval: 15,
            busyWindow: '15s',
            refresh: '15s',
            alertRuleUidRegex: 'horizon-.*',
            alertFolder: 'Horizon',
            alertDatasourceUid: '${HPG_PROMETHEUS_DATASOURCE_UID}',
            alertSoundScript: $alertSoundScript,
            live: LiveMode::Off,
            dedupe: false,
            legacy: true,
        );
    }
}
