<?php

declare(strict_types=1);

use DF\HorizonObservability\Http\RoutePath;
use Symfony\Component\Process\ExecutableFinder;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;
use Workbench\App\Console\Commands\PerfDataCommand;

/*
| The Docker dev stack (Arch 8.3, ADDENDUM R2.2, R3.2, R4.9; T11) agrees with the package and the workbench: the
| scrape path and token, the 10-second scrape job, the pinned Grafana images and plugins, the perf labels, and every
| mounted file exists.
*/

function devStackRoot(): string
{
    return dirname(__DIR__, 3);
}

/**
 * @return array<string, mixed>
 */
function devStackYaml(string $path): array
{
    $parsed = Yaml::parseFile(devStackRoot().'/'.$path);

    return is_array($parsed) ? $parsed : [];
}

/**
 * @return array<string, mixed>
 */
function devStackService(string $name): array
{
    $services = devStackYaml('docker-compose.yml')['services'] ?? [];

    return is_array($services) && is_array($services[$name] ?? null) ? $services[$name] : [];
}

function devStackToken(): string
{
    $env = devStackYaml('testbench.yaml')['env'] ?? [];
    $token = '';

    foreach (is_array($env) ? $env : [] as $line) {
        if (is_string($line) && str_starts_with($line, 'HORIZON_OBSERVABILITY_TOKEN=')) {
            $token = substr($line, strlen('HORIZON_OBSERVABILITY_TOKEN='));
        }
    }

    return $token;
}

it('pins both supported Grafana images and the plugin versions', function (): void {
    $grafana = devStackService('grafana');
    $environment = $grafana['environment'];

    expect($grafana['image'])->toBe('${GRAFANA_IMAGE:-grafana/grafana:13.2.4}')
        ->and($environment['GF_PLUGINS_PREINSTALL_SYNC'])->toBe('yesoreyeram-infinity-datasource@4.1.1,marcusolsson-dynamictext-panel@6.3.0')
        ->and($environment['GF_DASHBOARDS_MIN_REFRESH_INTERVAL'])->toBe('1s')
        ->and($environment['GF_UNIFIED_ALERTING_STATE_HISTORY_PROMETHEUS_TARGET_DATASOURCE_UID'])->toBe('prometheus')
        ->and($environment['HORIZON_OBSERVABILITY_API_TOKEN'])->toBe(devStackToken());
});

it('runs Prometheus with the remote-write receiver and an explicit data path', function (string $service, array $flags): void {
    expect(devStackService($service)['command'])->toContain(...$flags);
})->with([
    'dev' => ['prometheus', ['--web.enable-remote-write-receiver', '--storage.tsdb.path=/prometheus']],
    'perf (promtool writes the blocks into the same path)' => ['perf-prometheus', ['--storage.tsdb.path=/prometheus']],
]);

it('scrapes the package endpoint as its own 10-second job with the bearer token', function (string $file): void {
    $config = devStackYaml('docker/prometheus/'.$file);
    $jobs = $config['scrape_configs'];

    expect($jobs)->toHaveCount(1)
        ->and($jobs[0]['metrics_path'])->toBe('/horizon/'.RoutePath::SEGMENT.'/metrics')
        ->and($jobs[0]['scrape_interval'])->toBe('10s')
        ->and($jobs[0]['scrape_timeout'])->toBe('8s')
        ->and($jobs[0]['authorization'])->toBe(['type' => 'Bearer', 'credentials_file' => '/etc/prometheus/token']);
})->with(['prometheus.yml', 'prometheus.app.yml', 'prometheus.replicas.yml']);

it('gives Prometheus the workbench token', function (): void {
    expect(trim((string) file_get_contents(devStackRoot().'/docker/prometheus/token')))->toBe(devStackToken())
        ->and(devStackToken())->not->toBe('');
});

it('scrapes two replicas in one job for the dedupe gate', function (): void {
    expect(devStackYaml('docker/prometheus/prometheus.replicas.yml')['scrape_configs'][0]['static_configs'][0]['targets'])
        ->toBe(['host.docker.internal:8000', 'host.docker.internal:8001']);
});

it('labels the perf scrape like the backfill', function (string $file, string $job, string $instance, string $interval): void {
    $config = devStackYaml('docker/perf/'.$file)['scrape_configs'][0];

    expect($config['job_name'])->toBe($job)
        ->and($config['scrape_interval'] ?? devStackYaml('docker/perf/'.$file)['global']['scrape_interval'])->toBe($interval)
        ->and($config['relabel_configs'])->toBe([['target_label' => 'instance', 'replacement' => $instance]])
        ->and($config['static_configs'][0]['targets'])->toBe(['host.docker.internal:8391']);
})->with([
    'perf' => ['prometheus.yml', 'perf-horizon', 'perf-exporter', '10s'],
    'R2.1 reference' => ['prometheus.reference.yml', 'office-horizon', 'app:8080', '15s'],
]);

it('defaults the backfill labels to the perf scrape job', function (): void {
    $definition = (new PerfDataCommand)->getDefinition();

    expect($definition->getOption('job')->getDefault())->toBe('perf-horizon')
        ->and($definition->getOption('instance')->getDefault())->toBe('perf-exporter')
        ->and($definition->getOption('step')->getDefault())->toBe('10');
});

it('provisions the Prometheus datasource for large POST queries at the scrape interval', function (): void {
    $datasource = devStackYaml('docker/grafana/provisioning/datasources/prometheus.yaml')['datasources'][0];

    expect($datasource['uid'])->toBe('prometheus')
        ->and($datasource['url'])->toBe('${HORIZON_OBSERVABILITY_PROMETHEUS_URL}')
        ->and($datasource['jsonData'])->toMatchArray([
            'httpMethod' => 'POST',
            'timeInterval' => '${HORIZON_OBSERVABILITY_SCRAPE_INTERVAL}',
            'incrementalQuerying' => true,
            'incrementalQueryOverlapWindow' => '10m',
        ])
        ->and(devStackService('grafana')['environment'])->toMatchArray([
            'HORIZON_OBSERVABILITY_PROMETHEUS_URL' => '${PROMETHEUS_URL:-http://prometheus:9090}',
            'HORIZON_OBSERVABILITY_SCRAPE_INTERVAL' => '${SCRAPE_INTERVAL:-10s}',
        ]);
});

it('provisions the shipped Infinity datasource with the API bearer token', function (): void {
    // The package example (Arch Q5.5), mounted by the dev stack so it is tested
    $datasource = devStackYaml('resources/grafana/provisioning/datasources/horizon-observability.yaml')['datasources'][0];

    expect($datasource['type'])->toBe('yesoreyeram-infinity-datasource')
        ->and($datasource['jsonData']['auth_method'])->toBe('bearerToken')
        ->and($datasource['secureJsonData'])->toBe(['bearerToken' => '${HORIZON_OBSERVABILITY_API_TOKEN}'])
        ->and($datasource['jsonData']['customHealthCheckUrl'])->toEndWith('/horizon/'.RoutePath::SEGMENT.'/jobs/pending?limit=1')
        ->and(devStackService('grafana')['volumes'])->toContain(
            './resources/grafana/provisioning/datasources/horizon-observability.yaml:/etc/grafana/provisioning/datasources/horizon-observability.yaml:ro',
        );
});

it('provisions the package render with the shipped dashboard provider', function (): void {
    $provider = devStackYaml('resources/grafana/provisioning/dashboards/horizon-observability.yaml')['providers'][0];

    expect(devStackService('grafana')['volumes'])->toContain(
        './resources/grafana/provisioning/dashboards:/etc/grafana/provisioning/dashboards:ro',
        './resources/grafana/alerting:/etc/grafana/provisioning/alerting:ro',
        '${GRAFANA_DASHBOARDS:-./resources/grafana/dashboards}:'.$provider['options']['path'].':ro',
    )->and($provider['type'])->toBe('file');
});

it('publishes every port on 127.0.0.1 unless BIND_ADDRESS says otherwise', function (): void {
    $published = [];

    foreach (devStackYaml('docker-compose.yml')['services'] as $name => $service) {
        foreach ($service['ports'] ?? [] as $port) {
            $published[] = $name.' '.$port;
        }
    }

    expect($published)->toHaveCount(5)
        ->each->toMatch('/^[\w-]+ \$\{BIND_ADDRESS:-127\.0\.0\.1\}:\$\{\w+_PORT:-\d+\}:\d+$/');
});

it('mounts only files that exist', function (): void {
    $missing = [];

    foreach (devStackYaml('docker-compose.yml')['services'] as $name => $service) {
        foreach ($service['volumes'] ?? [] as $volume) {
            // ${NAME:-default} takes its default, as without the variable.
            $source = explode(':', (string) preg_replace('/\$\{\w+:-([^}]*)\}/', '$1', (string) $volume))[0];

            if (! str_starts_with($source, './') || $source === './build/perf') {
                continue;
            }

            if (! file_exists(devStackRoot().'/'.substr($source, 2))) {
                $missing[] = $name.': '.$source;
            }
        }
    }

    expect($missing)->toBe([]);
});

it('streams with the bootstrapped token, which git ignores', function (): void {
    $stream = devStackService('stream');
    $check = new Process(['git', 'check-ignore', '-q', 'docker/grafana/live-token'], devStackRoot());

    expect($stream['profiles'])->toBe(['app'])
        ->and($stream['command'])->toContain('$$(cat docker/grafana/live-token)')
        ->toContain('horizon-observability:stream')
        ->and($stream['environment']['HORIZON_OBSERVABILITY_LIVE_GRAFANA_URL'])->toBe('http://grafana:3000');

    // 0: ignored, 1: not ignored; anything else (127 without git, 128 outside a repository) cannot tell.
    if (! in_array($check->run(), [0, 1], true)) {
        $this->markTestSkipped('git is not available');
    }

    expect($check->getExitCode())->toBe(0);
});

it('ships executable shell scripts that parse', function (string $script): void {
    $path = devStackRoot().'/'.$script;

    expect(is_executable($path))->toBeTrue()
        ->and((new Process(['sh', '-n', $path]))->run())->toBe(0);
})->with(['docker/grafana/bootstrap-live.sh', 'docker/perf/backfill.sh']);

it('ships Node tools that parse', function (string $script): void {
    $node = (new ExecutableFinder)->find('node');

    if ($node === null) {
        $this->markTestSkipped('node is not installed');
    }

    $check = new Process([$node, '--check', devStackRoot().'/'.$script]);

    expect($check->run())->toBe(0, $check->getErrorOutput());
})->with(['bin/perf.mjs', 'bin/screenshot.mjs']);

it('keeps the perf budget of the addendum', function (): void {
    $budget = json_decode((string) file_get_contents(devStackRoot().'/docker/perf/budget.json'), true);
    $legacy = json_decode((string) file_get_contents(devStackRoot().'/docker/perf/reference-legacy.json'), true);

    expect($budget['ranges']['1h'])->toBe([
        'refreshP50Ms' => 1000,
        'refreshP95Ms' => 1500,
        'overviewP50Ms' => 1000,
        'firstLoadMs' => 3000,
        'requestsPerRefresh' => 12,
        'variableRequestsPerRefresh' => 2,
        'promqlMs' => 1500,
        'interpolatedKb' => 100,
        'maxPanelKb' => 25,
        'infinityRequestsPerRefresh' => 5,
    ])
        ->and($budget['ranges']['6h'])->toBe(['refreshP50Ms' => 2000])
        ->and($budget['overviewPanels'])->toBe([1, 3, 2, 8, 9, 4, 21])
        ->and($legacy['values'])->toMatchArray(['requestsPerRefresh' => 19, 'variableRequestsPerRefresh' => 6, 'promqlMs' => 14800]);
});
