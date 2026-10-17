<?php

declare(strict_types=1);

use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Demo\DemoJob;
use DF\HorizonObservability\Install\DockerStack;
use DF\HorizonObservability\Tests\Feature\Console\Support\FakeGrafanaCommand;
use DF\HorizonObservability\Tests\Support\RedisKeys;
use DF\HorizonObservability\Tests\TestCase;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Finder\Finder;
use Symfony\Component\Process\Process;
use Symfony\Component\Yaml\Yaml;

/*
| PUBLIC horizon-observability:install (Arch Q7 and the WP10 row, Addendum Q9' WP10, owner decision 12): interactive and
| idempotent with --no-interaction defaults; publishes the config, writes a working token to .env (never over an
| existing value), prints the scrape job at 10 s, the Grafana steps, the render command of each live mode and the
| stream steps, ends with the URL to open and a passing check. --docker writes a stack that `docker compose config`
| accepts; --demo dispatches the sample jobs. Each test runs in its own application directory.
*/

pest()->group('redis');

beforeEach(function (): void {
    $this->base = sys_get_temp_dir().'/ho-install-'.getmypid().'-'.bin2hex(random_bytes(4));

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

    FakeGrafanaCommand::$calls = [];
});

afterEach(function (): void {
    (new Filesystem)->deleteDirectory($this->base);
});

/**
 * @param  array<string, mixed>  $options
 * @return array{0: int, 1: string}
 */
function install(array $options = []): array
{
    $code = Artisan::call('horizon-observability:install', ['--no-interaction' => true, ...$options]);

    return [$code, Artisan::output()];
}

/**
 * Every file of the application directory => sha1 of its contents.
 *
 * @return array<string, string>
 */
function installFiles(string $base): array
{
    $files = [];

    foreach (Finder::create()->files()->ignoreDotFiles(false)->in($base)->exclude(['storage']) as $file) {
        $files[$file->getRelativePathname()] = sha1($file->getContents());
    }

    ksort($files);

    return $files;
}

function installToken(string $base): ?string
{
    preg_match('/^HORIZON_OBSERVABILITY_TOKEN=(.*)$/m', (string) file_get_contents($base.'/.env'), $match);

    return $match[1] ?? null;
}

it('publishes the config, writes a working token and prints the setup', function (): void {
    [$code, $output] = install();
    $token = installToken($this->base);

    expect($code)->toBe(0)
        ->and(file_get_contents($this->base.'/config/horizon-observability.php'))->toBe(file_get_contents(dirname(__DIR__, 3).'/config/horizon-observability.php'))
        ->and($token)->toMatch('/^[A-Za-z0-9]{40}$/')
        ->and(file_get_contents($this->base.'/.env'))->toStartWith("APP_NAME=\"My Shop\"\nAPP_ENV=local\nAPP_URL=http://localhost\n")
        ->and(file_get_contents($this->base.'/.env.example'))->toEndWith("\nHORIZON_OBSERVABILITY_TOKEN=\n")
        // The Prometheus job at 10 s with the bearer token
        ->and($output)->toContain('- job_name: my-shop-horizon')
        ->and($output)->toContain('metrics_path: /horizon/observability/metrics')
        ->and($output)->toContain('scrape_interval: 10s')
        ->and($output)->toContain('type: Bearer')
        ->and($output)->toContain("targets: ['localhost:80']")
        // Grafana: plugins, the Infinity datasource, the render command of each live mode
        ->and($output)->toContain('yesoreyeram-infinity-datasource')
        ->and($output)->toContain('marcusolsson-dynamictext-panel')
        ->and($output)->toContain('customHealthCheckUrl: http://localhost/horizon/observability/jobs/pending?limit=1')
        ->and($output)->toContain('php artisan horizon-observability:grafana grafana --job=my-shop-horizon')
        ->and($output)->toContain('php artisan horizon-observability:grafana grafana --job=my-shop-horizon --live=stream')
        ->and($output)->toContain('php artisan horizon-observability:grafana grafana --job=my-shop-horizon --live=off')
        // Stream mode: a service account with role Editor
        ->and($output)->toContain('"role":"Editor"')
        ->and($output)->toContain('/api/serviceaccounts')
        ->and($output)->toContain('HORIZON_OBSERVABILITY_LIVE_GRAFANA_TOKEN')
        // A passing check and the URL to open last
        ->and($output)->toMatch('/Check: 0 errors, \d+ warnings?/')
        ->and(trim($output))->toEndWith('Open http://localhost:3000/d/horizon-observability.')
        ->and($output)->not->toContain((string) $token);

    // The token works right away (the running process uses it too).
    $this->get('/horizon/observability/metrics')->assertUnauthorized();
    $this->get('/horizon/observability/metrics', ['Authorization' => 'Bearer '.$token])->assertOk();
});

it('changes nothing when it runs again', function (): void {
    install();
    $before = installFiles($this->base);

    [$code, $output] = install();

    expect($code)->toBe(0)
        ->and(installFiles($this->base))->toBe($before)
        ->and($output)->toContain('config/horizon-observability.php (unchanged)')
        ->and($output)->toContain('HORIZON_OBSERVABILITY_TOKEN (already in .env, kept)');
});

it('never overwrites an existing token', function (): void {
    file_put_contents($this->base.'/.env', "APP_NAME=Shop\nHORIZON_OBSERVABILITY_TOKEN=existing-token-0123456789\n");

    [$code, $output] = install(['--token' => 'another-token-0123456789']);

    expect($code)->toBe(0)
        ->and(file_get_contents($this->base.'/.env'))->toBe("APP_NAME=Shop\nHORIZON_OBSERVABILITY_TOKEN=existing-token-0123456789\n")
        ->and($output)->toContain('--token was ignored');
});

it('fills in an empty token and uses --token', function (): void {
    file_put_contents($this->base.'/.env', "APP_NAME=Shop\nHORIZON_OBSERVABILITY_TOKEN=\nAPP_DEBUG=true");

    [$code] = install(['--token' => 'given-token-0123456789']);

    expect($code)->toBe(0)
        ->and(file_get_contents($this->base.'/.env'))->toBe("APP_NAME=Shop\nHORIZON_OBSERVABILITY_TOKEN=given-token-0123456789\nAPP_DEBUG=true");
});

it('keeps a token that is set outside .env', function (): void {
    config()->set('horizon-observability.token', 'from-the-environment-0123456789');
    $this->app->forgetInstance(Config::class);

    [$code, $output] = install();

    expect($code)->toBe(0)
        ->and(installToken($this->base))->toBeNull()
        ->and($output)->toContain('set outside .env, kept');
});

it('rejects a short --token', function (): void {
    [$code, $output] = install(['--token' => 'short']);

    expect($code)->toBe(1)
        ->and($output)->toContain('--token must be at least 16 characters long.')
        ->and(installToken($this->base))->toBeNull();
});

it('prints the token instead of writing it with --no-env', function (): void {
    [$code, $output] = install(['--no-env' => true, '--token' => 'printed-token-0123456789']);

    expect($code)->toBe(0)
        ->and($output)->toContain('Add to the environment of the app: HORIZON_OBSERVABILITY_TOKEN=printed-token-0123456789')
        ->and(installToken($this->base))->toBeNull()
        ->and(file_get_contents($this->base.'/.env.example'))->not->toContain('HORIZON_OBSERVABILITY_TOKEN');
});

it('keeps a changed config unless --force, and asks when interactive', function (): void {
    file_put_contents($this->base.'/config/horizon-observability.php', "<?php\n\nreturn ['namespace' => 'office'];\n");

    [, $output] = install();

    expect(file_get_contents($this->base.'/config/horizon-observability.php'))->toContain("'office'")
        ->and($output)->toContain('config/horizon-observability.php (kept)');

    $this->artisan('horizon-observability:install')
        ->expectsConfirmation('config/horizon-observability.php exists and differs from the package defaults. Overwrite it?', 'yes')
        ->assertExitCode(0);

    expect(file_get_contents($this->base.'/config/horizon-observability.php'))->not->toContain("'office'");

    file_put_contents($this->base.'/config/horizon-observability.php', "<?php\n\nreturn ['namespace' => 'office'];\n");
    install(['--force' => true]);

    expect(file_get_contents($this->base.'/config/horizon-observability.php'))->not->toContain("'office'");
});

it('asks before publishing the config when interactive', function (): void {
    $this->artisan('horizon-observability:install')
        ->expectsConfirmation('Publish config/horizon-observability.php?', 'no')
        ->assertExitCode(0);

    expect(is_file($this->base.'/config/horizon-observability.php'))->toBeFalse()
        ->and(installToken($this->base))->not->toBeNull();
});

it('writes the Docker stack pointed at the app, with the dashboard rendered for the job', function (): void {
    [$code, $output] = install(['--docker' => true]);
    $compose = Yaml::parseFile($this->base.'/'.DockerStack::COMPOSE_FILE);
    $prometheus = Yaml::parseFile($this->base.'/docker/observability/prometheus/prometheus.yml');
    $datasources = Yaml::parseFile($this->base.'/docker/observability/grafana/provisioning/datasources/horizon-observability.yaml');
    $dashboard = json_decode((string) file_get_contents($this->base.'/docker/observability/grafana/dashboards/horizon-observability.json'), true);

    expect($code)->toBe(0)
        ->and($compose['name'])->toBe('my-shop-observability')
        ->and($compose['services']['grafana']['image'])->toBe('grafana/grafana:13.2.4')
        ->and($compose['services']['prometheus']['image'])->toBe('quay.io/prometheus/prometheus:v3.15.0')
        ->and($compose['services']['grafana']['environment']['GF_PLUGINS_PREINSTALL_SYNC'])->toBe('yesoreyeram-infinity-datasource@4.1.1,marcusolsson-dynamictext-panel@6.3.0')
        ->and($compose['services']['grafana']['environment']['HORIZON_OBSERVABILITY_APP_URL'])->toBe('http://host.docker.internal:8000')
        ->and($compose['services']['grafana']['ports'])->toBe(['127.0.0.1:${GRAFANA_PORT:-3000}:3000'])
        ->and($prometheus['scrape_configs'][0])->toMatchArray([
            'job_name' => 'my-shop-horizon',
            'metrics_path' => '/horizon/observability/metrics',
            'scheme' => 'http',
            'scrape_interval' => '10s',
            'scrape_timeout' => '8s',
            'authorization' => ['type' => 'Bearer', 'credentials_file' => '/tmp/horizon-observability-token'],
            'static_configs' => [['targets' => ['host.docker.internal:8000']]],
        ])
        ->and(array_column($datasources['datasources'], 'type'))->toBe(['prometheus', 'yesoreyeram-infinity-datasource'])
        ->and($datasources['datasources'][0]['uid'])->toBe('prometheus')
        ->and($dashboard['uid'])->toBe('horizon-observability')
        ->and(is_file($this->base.'/docker/observability/grafana/alerting/horizon-observability.json'))->toBeTrue()
        ->and($output)->toContain('php artisan serve --host=0.0.0.0 --port=8000')
        ->and($output)->toContain('docker compose -f docker-compose.observability.yml up -d')
        ->and(trim($output))->toEndWith('Open http://localhost:3000.');

    if (FakeGrafanaCommand::$calls !== []) {
        expect(FakeGrafanaCommand::$calls[array_key_last(FakeGrafanaCommand::$calls)])->toMatchArray([
            'path' => $this->base.'/docker/observability/grafana',
            'job' => 'my-shop-horizon',
            'datasource-uid' => 'prometheus',
            'check' => false,
        ]);
    }
});

it('selects Grafana 12 and the app URL, and rejects an unknown Grafana', function (): void {
    [$code] = install(['--docker' => true, '--grafana' => '12', '--app-url' => 'https://shop.internal/sub/']);
    $compose = Yaml::parseFile($this->base.'/'.DockerStack::COMPOSE_FILE);
    $prometheus = Yaml::parseFile($this->base.'/docker/observability/prometheus/prometheus.yml');

    expect($code)->toBe(0)
        ->and($compose['services']['grafana']['image'])->toBe('grafana/grafana:12.4.13')
        ->and($compose['services']['grafana']['environment']['HORIZON_OBSERVABILITY_APP_URL'])->toBe('https://shop.internal/sub')
        ->and($prometheus['scrape_configs'][0]['scheme'])->toBe('https')
        ->and($prometheus['scrape_configs'][0]['metrics_path'])->toBe('/sub/horizon/observability/metrics')
        ->and($prometheus['scrape_configs'][0]['static_configs'][0]['targets'])->toBe(['shop.internal:443']);

    expect(install(['--docker' => true, '--grafana' => '11'])[0])->toBe(1)
        ->and(install(['--docker' => true, '--app-url' => 'ftp://nope'])[0])->toBe(1);
});

it('keeps the port of APP_URL for Docker', function (): void {
    config()->set('app.url', 'http://127.0.0.1:8080');

    install(['--docker' => true]);
    $prometheus = Yaml::parseFile($this->base.'/docker/observability/prometheus/prometheus.yml');

    expect($prometheus['scrape_configs'][0]['static_configs'][0]['targets'])->toBe(['host.docker.internal:8080']);
});

it('changes no Docker file when it runs again, and keeps a changed one unless --force', function (): void {
    install(['--docker' => true]);
    $before = installFiles($this->base);

    [, $output] = install(['--docker' => true]);

    expect(installFiles($this->base))->toBe($before)
        ->and($output)->toContain('unchanged');

    file_put_contents($this->base.'/'.DockerStack::COMPOSE_FILE, "# mine\n");
    [, $output] = install(['--docker' => true]);

    expect(file_get_contents($this->base.'/'.DockerStack::COMPOSE_FILE))->toBe("# mine\n")
        ->and($output)->toContain('kept (changed; --force overwrites)');

    install(['--docker' => true, '--force' => true]);

    expect(installFiles($this->base))->toBe($before);
});

it('writes a stack that docker compose config accepts, and that names the token when it is missing', function (): void {
    install(['--docker' => true]);
    $config = new Process(['docker', 'compose', '-f', DockerStack::COMPOSE_FILE, 'config', '--format', 'json'], $this->base);
    $config->run();

    expect($config->getExitCode())->toBe(0, $config->getErrorOutput());

    $resolved = json_decode($config->getOutput(), true);
    $token = installToken($this->base);

    expect($resolved['services']['prometheus']['environment']['HORIZON_OBSERVABILITY_TOKEN'])->toBe($token)
        ->and($resolved['services']['grafana']['environment']['HORIZON_OBSERVABILITY_API_TOKEN'])->toBe($token)
        // Escaped for compose: the shell in the container reads the variable, so the token is in no file of the stack.
        ->and($resolved['services']['prometheus']['command'][0])->toContain('"$$HORIZON_OBSERVABILITY_TOKEN"');

    file_put_contents($this->base.'/.env', "APP_NAME=Shop\n");
    $missing = new Process(['docker', 'compose', '-f', DockerStack::COMPOSE_FILE, 'config', '-q'], $this->base);
    $missing->run();

    expect($missing->getExitCode())->not->toBe(0)
        ->and($missing->getErrorOutput())->toContain('set HORIZON_OBSERVABILITY_TOKEN in .env (php artisan horizon-observability:install)');
})->skip(function (): bool {
    $probe = new Process(['docker', 'compose', 'version']);
    $probe->run();

    return ! $probe->isSuccessful();
}, 'docker compose is not available');

it('dispatches the demo burst with --demo', function (): void {
    Schema::connection('testing')->create('job_batches', static function (Blueprint $table): void {
        $table->string('id')->primary();
        $table->string('name');
        $table->integer('total_jobs');
        $table->integer('pending_jobs');
        $table->integer('failed_jobs');
        $table->longText('failed_job_ids');
        $table->mediumText('options')->nullable();
        $table->integer('cancelled_at')->nullable();
        $table->integer('created_at');
        $table->integer('finished_at')->nullable();
    });

    [$code, $output] = install(['--demo' => true]);
    $queued = RedisKeys::client()->llen(TestCase::redisPrefix().'queues:default');
    $delayed = RedisKeys::client()->zcard(TestCase::redisPrefix().'queues:default:delayed');

    expect($code)->toBe(0)
        ->and($output)->toContain('Dispatched 200 sample jobs to redis/default')
        ->and($output)->toContain('plus a batch of 10')
        ->and($queued + $delayed)->toBe(210)
        ->and($delayed)->toBe(20);
});

it('fails in production with --demo', function (): void {
    $this->app['env'] = 'production';
    config()->set('horizon-observability.token', 'production-token-0123456789');
    $this->app->forgetInstance(Config::class);

    [$code, $output] = install(['--demo' => true]);

    expect($code)->toBe(1)
        ->and($output)->toContain('refuses to run in production')
        ->and(RedisKeys::client()->exists(TestCase::redisPrefix().'queues:default'))->toBe(0);
});

it('exits 1 when the check finds an error', function (): void {
    config()->set('database.redis.horizon', ['host' => '127.0.0.1', 'port' => 1, 'database' => 0]);

    [$code, $output] = install();

    expect($code)->toBe(1)
        ->and($output)->toContain('ERROR Storage, Connection')
        ->and($output)->toContain('Fix: Start Redis or fix database.redis.horizon');
});

it('loads the demo job class from the package', function (): void {
    expect(class_exists(DemoJob::class))->toBeTrue();
});
