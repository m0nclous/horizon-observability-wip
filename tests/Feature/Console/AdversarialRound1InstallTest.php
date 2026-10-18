<?php

declare(strict_types=1);

use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Install\DockerStack;
use DF\HorizonObservability\Tests\Feature\Console\Support\FakeGrafanaCommand;
use Dotenv\Dotenv;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/*
| T14 QA round 1 (adversarial): horizon-observability:install.
|
| - A --token that dotenv cannot read back: written unquoted, "abcd efgh ijkl mnop" makes .env unparseable (the next
|   boot of the app dies with Dotenv\Exception\InvalidFileException), "abcdefgh #ijklmnopqrst" is read back as
|   "abcdefgh" (8 characters: fail closed). install applies the full value to its own process, so its checks pass and
|   it exits 0 either way. Only --token values of the bearer token syntax (RFC 6750 b64token) are safe to write raw.
| - The token list for rotation (config: "comma-separated for rotation"): with HORIZON_OBSERVABILITY_TOKEN=a,b in .env
|   the Docker stack gives the whole list to Prometheus (credentials file) and to the Infinity datasource, so every
|   scrape and every job-list/live call sends "Bearer a,b" and gets 401, while check is green.
| - live.enabled = false: the /live route does not exist, yet install prints it among the endpoints and renders the
|   Docker dashboard with the default --live=poll, so its five live targets ask a missing route.
| - A package domain (HORIZON_OBSERVABILITY_DOMAIN): the endpoints only answer on that host, but the printed endpoints,
|   the scrape job target and the Infinity URL use APP_URL's host, where the routes do not match (404).
*/

pest()->group('redis');

beforeEach(function (): void {
    $this->base = sys_get_temp_dir().'/ho-adv-install-'.getmypid().'-'.bin2hex(random_bytes(4));

    foreach (['config', 'app', 'bootstrap/cache', 'storage/logs'] as $directory) {
        mkdir($this->base.'/'.$directory, 0777, true);
    }

    file_put_contents($this->base.'/.env', "APP_NAME=\"My Shop\"\nAPP_ENV=local\nAPP_URL=http://localhost\n");
    file_put_contents($this->base.'/.env.example', "APP_NAME=Laravel\nAPP_URL=http://localhost\n");
    $this->app->setBasePath($this->base);
    $this->app->useEnvironmentPath($this->base);
    config()->set('app.name', 'My Shop');
    config()->set('app.url', 'http://localhost');
    config()->set('horizon-observability.token');
    config()->set('horizon-observability.job_class_paths', [$this->base.'/app']);
    $this->app->forgetInstance(Config::class);

    if (! array_key_exists('horizon-observability:grafana', Artisan::all())) {
        $this->app->make(Kernel::class)->registerCommand(new FakeGrafanaCommand);
    }
});

afterEach(function (): void {
    (new Filesystem)->deleteDirectory($this->base);
});

/**
 * @param  array<string, mixed>  $options
 * @return array{0: int, 1: string}
 */
function adversarialInstall(array $options = []): array
{
    $code = Artisan::call('horizon-observability:install', ['--no-interaction' => true, ...$options]);

    return [$code, Artisan::output()];
}

it('never writes a --token that dotenv reads back differently', function (string $token): void {
    [$code] = adversarialInstall(['--token' => $token]);
    $contents = (string) file_get_contents($this->base.'/.env');

    if ($code !== 0 && ! str_contains($contents, 'HORIZON_OBSERVABILITY_TOKEN')) {
        expect(true)->toBeTrue();     // rejected, nothing written: fine

        return;
    }

    expect(static fn (): array => Dotenv::parse($contents))->not->toThrow(Throwable::class)
        ->and(Dotenv::parse($contents)['HORIZON_OBSERVABILITY_TOKEN'] ?? null)->toBe($token);
})->with([
    'with spaces' => 'abcd efgh ijkl mnop',
    'with a comment' => 'abcdefgh #ijklmnopqrst',
    'opening a quote' => '"abcdefghijklmnopq',
]);

it('gives the Docker Prometheus one token of a rotation list', function (): void {
    $tokens = 'rotate-old-0123456789abcdef,rotate-new-0123456789abcdef';
    file_put_contents($this->base.'/.env', "APP_NAME=Shop\nHORIZON_OBSERVABILITY_TOKEN={$tokens}\n");
    config()->set('horizon-observability.token', $tokens);
    $this->app->forgetInstance(Config::class);

    [$code] = adversarialInstall(['--docker' => true]);
    $compose = Yaml::parseFile($this->base.'/'.DockerStack::COMPOSE_FILE);
    $credentials = $this->base.'/credentials';

    // The container's start command, as Compose hands it to /bin/sh ($$ is Compose's escape for $), up to the exec.
    $command = str_replace(['$$', '/tmp/horizon-observability-token'], ['$', $credentials], (string) $compose['services']['prometheus']['command'][0]);
    $command = substr($command, 0, (int) strpos($command, ' && exec '));
    $shell = Process::fromShellCommandline($command, $this->base, ['HORIZON_OBSERVABILITY_TOKEN' => $tokens]);
    $shell->mustRun();

    // The app accepts each token alone; "Bearer <old>,<new>" matches none of them.
    expect($code)->toBe(0)
        ->and(app(Config::class)->tokens)->toBe(['rotate-old-0123456789abcdef', 'rotate-new-0123456789abcdef'])
        ->and(app(Config::class)->tokens)->toContain(file_get_contents($credentials));
});

it('gives the Docker Infinity datasource one token of a rotation list', function (): void {
    $tokens = 'rotate-old-0123456789abcdef,rotate-new-0123456789abcdef';
    file_put_contents($this->base.'/.env', "APP_NAME=Shop\nHORIZON_OBSERVABILITY_TOKEN={$tokens}\n");
    config()->set('horizon-observability.token', $tokens);
    $this->app->forgetInstance(Config::class);

    adversarialInstall(['--docker' => true]);
    $config = new Process(['docker', 'compose', '-f', DockerStack::COMPOSE_FILE, 'config', '--format', 'json'], $this->base);
    $config->mustRun();
    $resolved = json_decode($config->getOutput(), true);
    $grafana = $resolved['services']['grafana'];
    $api = $grafana['environment']['HORIZON_OBSERVABILITY_API_TOKEN'];

    // Either Compose hands Grafana one token, or the Grafana start command cuts the list before /run.sh.
    $cut = str_contains(implode(' ', (array) ($grafana['command'] ?? [])), 'HORIZON_OBSERVABILITY_API_TOKEN');

    expect($cut || in_array($api, app(Config::class)->tokens, true))->toBeTrue('Grafana gets HORIZON_OBSERVABILITY_API_TOKEN='.$api);
})->skip(function (): bool {
    $probe = new Process(['docker', 'compose', 'version']);
    $probe->run();

    return ! $probe->isSuccessful();
}, 'docker compose is not available');

it('renders the Docker dashboard with --live=off when live is disabled', function (): void {
    // live.enabled = false removes the /live route; check says "the dashboard must be rendered with --live=off".
    config()->set('horizon-observability.live.enabled', false);
    $this->app->forgetInstance(Config::class);
    FakeGrafanaCommand::$calls = [];

    [, $output] = adversarialInstall(['--docker' => true]);
    $dashboard = (string) file_get_contents($this->base.'/docker/observability/grafana/dashboards/horizon-observability.json');

    if (FakeGrafanaCommand::$calls !== []) {
        expect(FakeGrafanaCommand::$calls[array_key_last(FakeGrafanaCommand::$calls)]['live'])->toBe('off');
    } else {
        expect($dashboard)->not->toContain('/live?');
    }

    expect($output)->not->toContain('{metrics,jobs/*,live}');
});

it('points the printed endpoints and the scrape job at the package domain', function (): void {
    config()->set('horizon-observability.domain', 'metrics.shop.test');
    $this->app->forgetInstance(Config::class);

    [, $output] = adversarialInstall(['--docker' => true, '--app-url' => 'http://metrics.shop.test']);
    $prometheus = Yaml::parseFile($this->base.'/docker/observability/prometheus/prometheus.yml');

    expect($prometheus['scrape_configs'][0]['static_configs'][0]['targets'])->toBe(['metrics.shop.test:80']);

    // Without --app-url: the endpoints and the scrape job of your own Prometheus must name the domain, not APP_URL.
    [, $output] = adversarialInstall();

    expect($output)->toContain('http://metrics.shop.test/horizon/observability/{metrics,jobs/*,live}')
        ->and($output)->toContain("targets: ['metrics.shop.test:80']");
});
