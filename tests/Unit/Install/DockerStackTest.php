<?php

declare(strict_types=1);

use DF\HorizonObservability\Install\AppUrl;
use DF\HorizonObservability\Install\DockerStack;
use Symfony\Component\Yaml\Yaml;

/*
| The files of horizon-observability:install --docker: valid YAML, Prometheus at 10 s with the bearer token from the
| environment (no secret in any file), Grafana 12 or 13 with the plugins and both datasources.
*/

function dockerStack(string $grafana = '13', string $url = 'http://host.docker.internal:8000'): DockerStack
{
    return new DockerStack('shop-observability', 'shop-horizon', AppUrl::parse($url), '/horizon/observability', $grafana);
}

it('writes valid YAML for every file', function (): void {
    $files = dockerStack()->files();

    expect(array_keys($files))->toBe([
        'docker-compose.observability.yml',
        'docker/observability/prometheus/prometheus.yml',
        'docker/observability/grafana/provisioning/datasources/horizon-observability.yaml',
        'docker/observability/grafana/provisioning/dashboards/horizon-observability.yaml',
    ]);

    foreach ($files as $contents) {
        expect(Yaml::parse($contents))->toBeArray();
    }
});

it('runs Prometheus and Grafana of the chosen major, local only', function (string $grafana, string $image): void {
    $compose = Yaml::parse(dockerStack($grafana)->files()[DockerStack::COMPOSE_FILE]);

    expect($compose['services']['grafana']['image'])->toBe($image)
        ->and($compose['services']['prometheus']['ports'])->toBe(['127.0.0.1:${PROMETHEUS_PORT:-9090}:9090'])
        ->and($compose['services']['grafana']['environment'])->toMatchArray([
            'GF_AUTH_ANONYMOUS_ORG_ROLE' => 'Viewer',
            'GF_DASHBOARDS_DEFAULT_HOME_DASHBOARD_PATH' => '/etc/grafana/dashboards/horizon-observability/horizon-observability.json',
            'GF_UNIFIED_ALERTING_STATE_HISTORY_PROMETHEUS_TARGET_DATASOURCE_UID' => 'prometheus',
            'HORIZON_OBSERVABILITY_PROMETHEUS_UID' => 'prometheus',
        ])
        ->and($compose['services']['prometheus']['command'][0])->toContain('--web.enable-remote-write-receiver');
})->with([['12', 'grafana/grafana:12.4.13'], ['13', 'grafana/grafana:13.2.4']]);

it('starts Grafana once Prometheus has scraped the app, so the dashboard variables load with data', function (): void {
    $grafana = Yaml::parse(dockerStack()->files()[DockerStack::COMPOSE_FILE])['services']['grafana'];
    $office = Yaml::parse((new DockerStack('p', 'j', AppUrl::parse('http://app'), '/h/o', '13', 'office_'))->files()[DockerStack::COMPOSE_FILE]);

    expect($grafana['entrypoint'])->toBe(['/bin/sh', '-c'])
        ->and($grafana['command'][0])->toContain('for i in $$(seq 1 60)')
        ->toContain('http://prometheus:9090/api/v1/query?query=count(ho_horizon_info)')
        ->toEndWith('exec /run.sh')
        ->and($office['services']['grafana']['command'][0])->toContain('count(office_horizon_info)');
});

it('rejects an unsupported Grafana', function (): void {
    expect(static fn (): DockerStack => dockerStack('11'))->toThrow(InvalidArgumentException::class, '--grafana must be 12 or 13.');
});

it('scrapes the app every 10 s with the bearer token', function (): void {
    $job = Yaml::parse(dockerStack('13', 'https://shop.internal:8443/sub')->files()['docker/observability/prometheus/prometheus.yml'])['scrape_configs'][0];

    expect($job)->toBe([
        'job_name' => 'shop-horizon',
        'metrics_path' => '/sub/horizon/observability/metrics',
        'scheme' => 'https',
        'scrape_interval' => '10s',
        'scrape_timeout' => '8s',
        'authorization' => ['type' => 'Bearer', 'credentials_file' => '/tmp/horizon-observability-token'],
        'static_configs' => [['targets' => ['shop.internal:8443']]],
    ]);
});

it('keeps the token out of every file', function (): void {
    foreach (dockerStack()->files() as $contents) {
        expect($contents)->not->toMatch('/HORIZON_OBSERVABILITY_(API_)?TOKEN[=:] *[A-Za-z0-9]{16,}/');
    }

    $compose = dockerStack()->files()[DockerStack::COMPOSE_FILE];

    expect($compose)->toContain('HORIZON_OBSERVABILITY_TOKEN: ${HORIZON_OBSERVABILITY_TOKEN:?')
        ->and($compose)->toContain('HORIZON_OBSERVABILITY_API_TOKEN: ${HORIZON_OBSERVABILITY_API_TOKEN:-${HORIZON_OBSERVABILITY_TOKEN:?');
});

it('provisions both datasources and the dashboard folder', function (): void {
    $files = dockerStack('13', 'http://host.docker.internal:8000/sub')->files();
    $datasources = Yaml::parse($files['docker/observability/grafana/provisioning/datasources/horizon-observability.yaml'])['datasources'];
    $dashboards = Yaml::parse($files['docker/observability/grafana/provisioning/dashboards/horizon-observability.yaml'])['providers'][0];

    expect($datasources[0])->toMatchArray(['uid' => 'prometheus', 'type' => 'prometheus', 'url' => 'http://prometheus:9090', 'isDefault' => true])
        ->and($datasources[0]['jsonData']['timeInterval'])->toBe('10s')
        ->and($datasources[1])->toMatchArray(['uid' => 'horizon-observability-api', 'type' => 'yesoreyeram-infinity-datasource', 'url' => '${HORIZON_OBSERVABILITY_APP_URL}'])
        ->and($datasources[1]['jsonData']['customHealthCheckUrl'])->toBe('${HORIZON_OBSERVABILITY_APP_URL}/horizon/observability/jobs/pending?limit=1')
        ->and($datasources[1]['secureJsonData'])->toBe(['bearerToken' => '${HORIZON_OBSERVABILITY_API_TOKEN}'])
        ->and($dashboards['options']['path'])->toBe('/etc/grafana/dashboards/horizon-observability');
});
