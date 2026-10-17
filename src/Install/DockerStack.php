<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Install;

use InvalidArgumentException;

/**
 * The files of `horizon-observability:install --docker` (owner decision 12): a small local stack with Prometheus
 * (scraping the app every 10 s with the bearer token, Addendum R3.2) and Grafana (Infinity and Business Text plugins,
 * both datasources, the rendered dashboard as the home dashboard, and the alert rules), pointed at the app.
 *
 * Nothing secret is written: Docker Compose reads `HORIZON_OBSERVABILITY_TOKEN` (and an optional
 * `HORIZON_OBSERVABILITY_API_TOKEN`) from the `.env` file next to the compose file, which is the application's own.
 * Ports listen on 127.0.0.1 only; Grafana allows anonymous viewers and `admin`/`admin`, so the stack is for local use.
 *
 * The dashboard and the alert rules are rendered by `horizon-observability:grafana` into {@see self::RENDER_DIRECTORY}.
 *
 * @internal
 */
final readonly class DockerStack
{
    public const COMPOSE_FILE = 'docker-compose.observability.yml';

    /**
     * Where `horizon-observability:grafana` writes `dashboards/` and `alerting/`.
     */
    public const RENDER_DIRECTORY = 'docker/observability/grafana';

    public const PROMETHEUS_IMAGE = 'quay.io/prometheus/prometheus:v3.15.0';

    /**
     * Supported Grafana majors => image (owner decision 4).
     *
     * @var array<int, string>
     */
    public const GRAFANA_IMAGES = ['12' => 'grafana/grafana:12.4.13', '13' => 'grafana/grafana:13.2.4'];

    public const PLUGINS = 'yesoreyeram-infinity-datasource@4.1.1,marcusolsson-dynamictext-panel@6.3.0';

    public const PROMETHEUS_UID = 'prometheus';

    public const INFINITY_UID = 'horizon-observability-api';

    public const DASHBOARD_UID = 'horizon-observability';

    /**
     * @param  string  $project  Docker Compose project name
     * @param  string  $job  Prometheus job name (the dashboard's prometheus_job)
     * @param  AppUrl  $app  the app as the containers reach it, e.g. http://host.docker.internal:8000
     * @param  string  $apiPath  the endpoints' base path, e.g. /horizon/observability
     * @param  string  $grafana  Grafana major, a key of {@see self::GRAFANA_IMAGES}
     *
     * @throws InvalidArgumentException for an unsupported Grafana major
     */
    public function __construct(
        public string $project,
        public string $job,
        public AppUrl $app,
        public string $apiPath,
        public string $grafana = '13',
    ) {
        if (! isset(self::GRAFANA_IMAGES[$grafana])) {
            throw new InvalidArgumentException('--grafana must be 12 or 13.');
        }
    }

    /**
     * Relative path => contents, the compose file first. The rendered dashboard and alert rules are not part of it.
     *
     * @return array<string, string>
     */
    public function files(): array
    {
        return [
            self::COMPOSE_FILE => $this->compose(),
            'docker/observability/prometheus/prometheus.yml' => $this->prometheus(),
            'docker/observability/grafana/provisioning/datasources/horizon-observability.yaml' => $this->datasources(),
            'docker/observability/grafana/provisioning/dashboards/horizon-observability.yaml' => $this->dashboards(),
        ];
    }

    public function grafanaImage(): string
    {
        return self::GRAFANA_IMAGES[$this->grafana];
    }

    /**
     * The metrics path including a subdirectory the app is served from.
     */
    public function metricsPath(): string
    {
        return $this->app->path.$this->apiPath.'/metrics';
    }

    private function compose(): string
    {
        $grafana = $this->grafanaImage();
        $prometheus = self::PROMETHEUS_IMAGE;
        $plugins = self::PLUGINS;
        $uid = self::PROMETHEUS_UID;
        $app = $this->app->toString();
        $dashboard = '/etc/grafana/dashboards/horizon-observability/'.self::DASHBOARD_UID.'.json';
        $missing = 'set HORIZON_OBSERVABILITY_TOKEN in .env (php artisan horizon-observability:install)';

        return <<<YAML
            # Local Prometheus and Grafana for Horizon Observability, written by
            # php artisan horizon-observability:install --docker (run it again with --force to rewrite this file).
            #
            #   docker compose -f docker-compose.observability.yml up -d
            #   open http://localhost:3000 (the dashboard is Grafana's home page; admin/admin to edit)
            #
            # Prometheus scrapes {$app} every 10 s, so serve the app there, e.g.
            # php artisan serve --host=0.0.0.0 --port=8000, and run php artisan horizon.
            # The bearer token is HORIZON_OBSERVABILITY_TOKEN from the .env file next to this file.
            # Local use only: the ports listen on 127.0.0.1 and Grafana lets anonymous visitors view.
            name: {$this->project}

            services:
              prometheus:
                image: {$prometheus}
                entrypoint: [/bin/sh, -c]
                command:
                  - >-
                    printf '%s' "\$\$HORIZON_OBSERVABILITY_TOKEN" > /tmp/horizon-observability-token &&
                    exec /bin/prometheus --config.file=/etc/prometheus/prometheus.yml --storage.tsdb.path=/prometheus
                    --storage.tsdb.retention.time=7d --web.enable-remote-write-receiver
                environment:
                  HORIZON_OBSERVABILITY_TOKEN: \${HORIZON_OBSERVABILITY_TOKEN:?{$missing}}
                extra_hosts: ['host.docker.internal:host-gateway']
                volumes:
                  - ./docker/observability/prometheus/prometheus.yml:/etc/prometheus/prometheus.yml:ro
                  - prometheus-data:/prometheus
                ports: ['127.0.0.1:\${PROMETHEUS_PORT:-9090}:9090']
                restart: unless-stopped

              grafana:
                image: {$grafana}
                depends_on: [prometheus]
                extra_hosts: ['host.docker.internal:host-gateway']
                environment:
                  GF_SECURITY_ADMIN_PASSWORD: admin
                  GF_AUTH_ANONYMOUS_ENABLED: 'true'
                  GF_AUTH_ANONYMOUS_ORG_ROLE: Viewer
                  GF_PLUGINS_PREINSTALL_SYNC: {$plugins}
                  GF_DASHBOARDS_DEFAULT_HOME_DASHBOARD_PATH: {$dashboard}
                  # The Alerts tile and the alert sound read Grafana's alert state history from Prometheus.
                  GF_UNIFIED_ALERTING_STATE_HISTORY_ENABLED: 'true'
                  GF_UNIFIED_ALERTING_STATE_HISTORY_BACKEND: multiple
                  GF_UNIFIED_ALERTING_STATE_HISTORY_PRIMARY: annotations
                  GF_UNIFIED_ALERTING_STATE_HISTORY_SECONDARIES: prometheus
                  GF_UNIFIED_ALERTING_STATE_HISTORY_PROMETHEUS_TARGET_DATASOURCE_UID: {$uid}
                  HORIZON_OBSERVABILITY_PROMETHEUS_UID: {$uid}
                  HORIZON_OBSERVABILITY_APP_URL: {$app}
                  HORIZON_OBSERVABILITY_API_TOKEN: \${HORIZON_OBSERVABILITY_API_TOKEN:-\${HORIZON_OBSERVABILITY_TOKEN:?{$missing}}}
                volumes:
                  - ./docker/observability/grafana/provisioning/datasources:/etc/grafana/provisioning/datasources:ro
                  - ./docker/observability/grafana/provisioning/dashboards:/etc/grafana/provisioning/dashboards:ro
                  - ./docker/observability/grafana/alerting:/etc/grafana/provisioning/alerting:ro
                  - ./docker/observability/grafana/dashboards:/etc/grafana/dashboards/horizon-observability:ro
                  - grafana-data:/var/lib/grafana
                ports: ['127.0.0.1:\${GRAFANA_PORT:-3000}:3000']
                restart: unless-stopped

            volumes:
              prometheus-data: {}
              grafana-data: {}

            YAML;
    }

    private function prometheus(): string
    {
        return <<<YAML
            # Written by php artisan horizon-observability:install --docker: the package endpoint as its own scrape job
            # at the recommended 10 s (5 s to 60 s are supported). The job name is the dashboard's prometheus_job.
            global:
              scrape_interval: 10s
              scrape_timeout: 8s
              evaluation_interval: 10s

            scrape_configs:
              - job_name: {$this->job}
                metrics_path: {$this->metricsPath()}
                scheme: {$this->app->scheme}
                scrape_interval: 10s
                scrape_timeout: 8s
                authorization:
                  type: Bearer
                  credentials_file: /tmp/horizon-observability-token
                static_configs:
                  - targets: ['{$this->app->target()}']

            YAML;
    }

    private function datasources(): string
    {
        $prometheus = self::PROMETHEUS_UID;
        $infinity = self::INFINITY_UID;
        // The datasource URL already holds a subdirectory the app is served from.
        $health = $this->apiPath.'/jobs/pending?limit=1';

        return <<<YAML
            # Written by php artisan horizon-observability:install --docker. Grafana calls the app server-side, so the
            # token never reaches a browser.
            apiVersion: 1

            datasources:
              - name: Prometheus
                uid: {$prometheus}
                type: prometheus
                access: proxy
                url: http://prometheus:9090
                isDefault: true
                editable: false
                jsonData:
                  httpMethod: POST
                  timeInterval: 10s
                  prometheusType: Prometheus
                  prometheusVersion: 3.15.0

              - name: Horizon Observability API
                uid: {$infinity}
                type: yesoreyeram-infinity-datasource
                access: proxy
                url: \${HORIZON_OBSERVABILITY_APP_URL}
                editable: false
                jsonData:
                  auth_method: bearerToken
                  allowedHosts:
                    - \${HORIZON_OBSERVABILITY_APP_URL}
                  customHealthCheckEnabled: true
                  customHealthCheckUrl: \${HORIZON_OBSERVABILITY_APP_URL}{$health}
                secureJsonData:
                  bearerToken: \${HORIZON_OBSERVABILITY_API_TOKEN}

            YAML;
    }

    private function dashboards(): string
    {
        return <<<'YAML'
            # Written by php artisan horizon-observability:install --docker: the rendered dashboard
            # (docker/observability/grafana/dashboards, php artisan horizon-observability:grafana).
            apiVersion: 1

            providers:
              - name: horizon-observability
                type: file
                folder: Horizon
                allowUiUpdates: false
                updateIntervalSeconds: 10
                options:
                  path: /etc/grafana/dashboards/horizon-observability
                  foldersFromFilesStructure: false

            YAML;
    }
}
