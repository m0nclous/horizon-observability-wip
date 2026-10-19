<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Install;

/**
 * The setup snippets `horizon-observability:install` prints for an existing Prometheus and Grafana: the scrape job at
 * 10 s, the Infinity datasource, the render command of each live mode and the stream mode steps (a service account
 * with role Editor). Secrets appear as placeholders only. With `live.enabled` false there is no `/live` endpoint and
 * no stream command, so the only render is `--live=off`.
 *
 * @internal
 */
final readonly class SetupGuide
{
    public const SERVICE_ACCOUNT = 'horizon-observability-live';

    /**
     * @param  string  $job  Prometheus job name (the dashboard's prometheus_job)
     * @param  AppUrl  $app  the app as Prometheus and Grafana reach it
     * @param  string  $apiPath  the endpoints' base path, e.g. /horizon/observability
     * @param  string  $grafanaUrl  Grafana's base URL, for the API calls of the stream steps
     * @param  string  $renderPath  output directory of the render commands, relative to the app root
     * @param  bool  $live  `live.enabled`: the poll and stream modes need the live endpoint and the stream command
     */
    public function __construct(
        public string $job,
        public AppUrl $app,
        public string $apiPath,
        public string $grafanaUrl,
        public string $renderPath = 'grafana',
        public bool $live = true,
    ) {}

    /**
     * The scrape job (prometheus.yml, under scrape_configs).
     */
    public function prometheusJob(): string
    {
        return <<<YAML
            - job_name: {$this->job}                 # the dashboard's prometheus_job
              metrics_path: {$this->app->path}{$this->apiPath}/metrics
              scheme: {$this->app->scheme}
              scrape_interval: 10s                     # recommended; 5s to 60s are supported
              scrape_timeout: 8s
              authorization:
                type: Bearer
                credentials_file: /etc/prometheus/secrets/horizon-observability-token   # HORIZON_OBSERVABILITY_TOKEN
              static_configs:
                - targets: ['{$this->app->target()}']
            YAML;
    }

    /**
     * The Infinity datasource of the job lists and the live tiles (Grafana provisioning).
     */
    public function infinityDatasource(): string
    {
        $url = $this->app->toString();

        return <<<YAML
            apiVersion: 1
            datasources:
              - name: Horizon Observability API
                uid: horizon-observability-api
                type: yesoreyeram-infinity-datasource
                access: proxy
                url: {$url}
                jsonData:
                  auth_method: bearerToken
                  allowedHosts: ['{$url}']
                  customHealthCheckEnabled: true
                  customHealthCheckUrl: {$url}{$this->apiPath}/jobs/pending?limit=1
                secureJsonData:
                  bearerToken: \${HORIZON_OBSERVABILITY_API_TOKEN}   # HORIZON_OBSERVABILITY_API_TOKEN, or the TOKEN
            YAML;
    }

    /**
     * The render command per live mode => what it gives; only `--live=off` when live is disabled.
     *
     * @return array<string, string>
     */
    public function renderCommands(): array
    {
        $command = sprintf('php artisan horizon-observability:grafana %s --job=%s', $this->renderPath, $this->job);

        if (! $this->live) {
            return [$command.' --live=off' => 'off: every tile reads Prometheus (live.enabled is false, so there is no live endpoint; HORIZON_OBSERVABILITY_LIVE_ENABLED=true enables the poll and stream modes)'];
        }

        return [
            $command => 'poll (default): the live tiles read the app at every dashboard refresh',
            $command.' --live=stream' => 'stream: 1 s live tiles pushed by horizon-observability:stream',
            $command.' --live=off' => 'off: every tile reads Prometheus',
        ];
    }

    /**
     * The stream mode steps: a Grafana service account with role Editor (live:push on Grafana 13) and its token; none
     * when live is disabled.
     *
     * @return list<string>
     */
    public function streamSteps(): array
    {
        if (! $this->live) {
            return [];
        }

        $grafana = rtrim($this->grafanaUrl, '/');
        $account = self::SERVICE_ACCOUNT;

        return [
            sprintf("curl -s -u admin:admin -H 'Content-Type: application/json' -d '{\"name\":\"%s\",\"role\":\"Editor\"}' %s/api/serviceaccounts", $account, $grafana),
            sprintf("curl -s -u admin:admin -H 'Content-Type: application/json' -d '{\"name\":\"%s\"}' %s/api/serviceaccounts/<id from the first answer>/tokens", $account, $grafana),
            sprintf('Set HORIZON_OBSERVABILITY_LIVE_GRAFANA_URL=%s and HORIZON_OBSERVABILITY_LIVE_GRAFANA_TOKEN=<key from the second answer> in .env', $grafana),
            'Run php artisan horizon-observability:stream under supervisord or systemd, and check it with php artisan horizon-observability:check --live-push-test',
        ];
    }
}
