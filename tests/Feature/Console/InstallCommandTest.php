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

/**
 * The literals Laravel's env() reads as no value (Illuminate\Support\Env::get()), as a project writes them: the
 * skeleton's .env.example uses KEY=null for a value that is not set yet (T14 QA round 3).
 *
 * @return array<string, array{0: string, 1: string}> name => [line, newline]
 */
function installNoValueLines(): array
{
    $lines = [];

    foreach (['null', 'NULL', 'Null', '(null)', '(NULL)', 'empty', 'EMPTY', '(empty)', '(Empty)', '"null"', "'NULL'", '"(empty)"', "'empty'"] as $literal) {
        $lines[$literal] = ['HORIZON_OBSERVABILITY_TOKEN='.$literal, "\n"];
    }

    $lines['null, CRLF'] = ['HORIZON_OBSERVABILITY_TOKEN=null', "\r\n"];
    $lines['"(null)", CRLF'] = ['HORIZON_OBSERVABILITY_TOKEN="(null)"', "\r\n"];
    $lines['export EMPTY, CRLF'] = ['export HORIZON_OBSERVABILITY_TOKEN=EMPTY', "\r\n"];

    return $lines;
}

it('writes the token in place of a value Laravel reads as no value, and changes nothing when it runs again', function (string $line, string $newline): void {
    $before = implode($newline, ['APP_NAME="My Shop"', 'APP_ENV=local', $line, 'APP_URL=http://localhost', '']);
    file_put_contents($this->base.'/.env', $before);

    [$code, $output] = install();
    $contents = (string) file_get_contents($this->base.'/.env');
    // As the next start of the app reads it.
    $token = Dotenv\Dotenv::parse($contents)['HORIZON_OBSERVABILITY_TOKEN'] ?? null;

    expect($code)->toBe(0)
        ->and($token)->toMatch('/^[A-Za-z0-9]{40}$/')
        // Only that line changed, in place, with the file's line endings.
        ->and($contents)->toBe(str_replace($line, (str_starts_with($line, 'export ') ? 'export ' : '').'HORIZON_OBSERVABILITY_TOKEN='.$token, $before))
        ->and($output)->toContain('HORIZON_OBSERVABILITY_TOKEN (written to .env: Laravel reads')
        ->and($output)->not->toContain('already in .env')
        ->and($output)->not->toContain('shorter than')
        ->and($output)->not->toContain('none valid')
        ->and($output)->toMatch('/Check: 0 errors, \d+ warnings?/')
        ->and($output)->not->toContain((string) $token);

    // The token works right away.
    $this->get('/horizon/observability/metrics')->assertUnauthorized();
    $this->get('/horizon/observability/metrics', ['Authorization' => 'Bearer '.$token])->assertOk();

    $files = installFiles($this->base);
    [$again, $output] = install();

    expect($again)->toBe(0)
        ->and(installFiles($this->base))->toBe($files)
        ->and($output)->toContain('HORIZON_OBSERVABILITY_TOKEN (already in .env, kept)');
})->with(installNoValueLines());

it('gives the Docker stack the token it writes in place of null', function (): void {
    file_put_contents($this->base.'/.env', "APP_NAME=\"My Shop\"\nAPP_ENV=local\nHORIZON_OBSERVABILITY_TOKEN=null\n");

    [$code] = install(['--docker' => true]);
    $token = installToken($this->base);
    $config = new Process(['docker', 'compose', '-f', DockerStack::COMPOSE_FILE, 'config', '--format', 'json'], $this->base);
    $config->run();
    $resolved = json_decode($config->getOutput(), true);

    // docker compose reads .env itself, without Laravel's literals: "null" would have become Bearer null.
    expect($code)->toBe(0)
        ->and($token)->toMatch('/^[A-Za-z0-9]{40}$/')
        ->and($config->getExitCode())->toBe(0, $config->getErrorOutput())
        ->and($resolved['services']['prometheus']['environment']['HORIZON_OBSERVABILITY_TOKEN'])->toBe($token)
        ->and($resolved['services']['grafana']['environment']['HORIZON_OBSERVABILITY_API_TOKEN'])->toBe($token);

    $files = installFiles($this->base);
    [$again] = install(['--docker' => true]);

    expect($again)->toBe(0)
        ->and(installFiles($this->base))->toBe($files);
})->skip(function (): bool {
    $probe = new Process(['docker', 'compose', 'version']);
    $probe->run();

    return ! $probe->isSuccessful();
}, 'docker compose is not available');

it('never overwrites true or false, and checks what env() gives the app for them', function (string $literal): void {
    $before = "APP_NAME=Shop\nAPP_ENV=local\nHORIZON_OBSERVABILITY_TOKEN={$literal}\n";
    file_put_contents($this->base.'/.env', $before);

    [$code, $output] = install();

    // env() returns a boolean, not a token: the app reports the same problem and locks the endpoints.
    expect($code)->toBe(1)
        ->and(file_get_contents($this->base.'/.env'))->toBe($before)
        ->and($output)->toContain('HORIZON_OBSERVABILITY_TOKEN (already in .env, kept)')
        ->and($output)->toContain('token must be a comma-separated string or a list of strings')
        ->and($output)->not->toContain('shorter than');
})->with(['true', '(FALSE)']);

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

it('accepts only a --token of the bearer token syntax, which .env reads back unchanged', function (string $token, bool $valid): void {
    [$code, $output] = install(['--token' => $token]);

    if ($valid) {
        expect($code)->toBe(0)
            ->and(installToken($this->base))->toBe($token)
            ->and(Dotenv\Dotenv::parse((string) file_get_contents($this->base.'/.env'))['HORIZON_OBSERVABILITY_TOKEN'])->toBe($token);

        return;
    }

    expect($code)->toBe(1)
        ->and($output)->toContain('--token may only contain letters, digits, - . _ ~ + / and = at the end')
        ->and(installToken($this->base))->toBeNull();
})->with([
    'letters, digits and - . _ ~ + /' => ['Ab-0.1_2~3+4/5678901', true],
    'base64 padding' => ['YWJjZGVmZ2hpamtsbW5vcA==', true],
    'a space' => ['abcd efgh ijkl mnop', false],
    'a comment' => ['abcdefgh#ijklmnopqrst', false],
    'a quote' => ['"abcdefghijklmnopq', false],
    'a comma (a rotation list)' => ['first-token-0123456,second-token-01234', false],
    'a dollar sign' => ['abc$defghijklmnopq', false],
    'padding inside' => ['abc=defghijklmnopq', false],
]);

it('fails when .env does not read the written token back', function (): void {
    // A line phpdotenv cannot parse: the next start of the app would fail as well.
    file_put_contents($this->base.'/.env', "APP_NAME=\"My Shop\nAPP_ENV=local\n");

    [$code, $output] = install();

    expect($code)->toBe(1)
        ->and($output)->toContain('The next start of the app reads HORIZON_OBSERVABILITY_TOKEN from .env as missing, not as written')
        ->and($output)->not->toContain('(written to .env)');

    // An .env phpdotenv rejects outright (the first run applied its token to this process).
    file_put_contents($this->base.'/.env', "APP_NAME=My Shop\n");
    config()->set('horizon-observability.token');
    $this->app->forgetInstance(Config::class);
    [$code, $output] = install();

    expect($code)->toBe(1)
        ->and($output)->toContain('InvalidFileException')
        ->and($output)->not->toContain('(written to .env)');
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
        // The provisioned dashboard itself, which the alert annotations are linked to, not Grafana's home page.
        ->and(trim($output))->toEndWith('Open http://localhost:3000/d/horizon-observability.');

    if (FakeGrafanaCommand::$calls !== []) {
        expect(FakeGrafanaCommand::$calls[array_key_last(FakeGrafanaCommand::$calls)])->toMatchArray([
            'path' => $this->base.'/docker/observability/grafana',
            'job' => 'my-shop-horizon',
            'datasource-uid' => 'prometheus',
            'live' => 'poll',
            'check' => false,
        ]);
    }
});

it('renders --live=off and offers no live mode when live is disabled', function (): void {
    config()->set('horizon-observability.live.enabled', false);
    $this->app->forgetInstance(Config::class);

    [$code, $output] = install(['--docker' => true]);

    expect($code)->toBe(0)
        ->and($output)->toContain('http://localhost/horizon/observability/{metrics,jobs/*}')
        ->and($output)->not->toContain('{metrics,jobs/*,live}')
        ->and($output)->toContain('php artisan horizon-observability:grafana grafana --job=my-shop-horizon --live=off')
        ->and($output)->toContain('HORIZON_OBSERVABILITY_LIVE_ENABLED=true enables the poll and stream modes')
        ->and($output)->not->toContain('--live=stream')
        ->and($output)->not->toContain('/api/serviceaccounts')
        ->and($output)->toContain('rendered (--live=off)');

    if (FakeGrafanaCommand::$calls !== []) {
        expect(array_unique(array_column(FakeGrafanaCommand::$calls, 'live')))->toBe(['off']);
    }

    // Live enabled again: the default render (poll) differs from the --live=off one only with the real command.
    config()->set('horizon-observability.live.enabled', true);
    $this->app->forgetInstance(Config::class);
    FakeGrafanaCommand::$calls = [];
    [, $output] = install(['--docker' => true]);

    expect($output)->toContain('{metrics,jobs/*,live}')
        ->and($output)->toContain('--job=my-shop-horizon --live=stream');

    if (FakeGrafanaCommand::$calls !== []) {
        expect(array_unique(array_column(FakeGrafanaCommand::$calls, 'live')))->toBe(['poll']);
    }
});

it('keeps a dashboard that differs from the render unless --force, and asks when interactive', function (): void {
    install(['--docker' => true]);
    $dashboard = $this->base.'/docker/observability/grafana/dashboards/horizon-observability.json';
    file_put_contents($dashboard, "{\"rendered\": \"--live=stream by hand\"}\n");

    [$code, $output] = install(['--docker' => true]);

    expect($code)->toBe(0)
        ->and(file_get_contents($dashboard))->toBe("{\"rendered\": \"--live=stream by hand\"}\n")
        ->and($output)->toContain('kept (differs from the --live=poll render; --force renders it again)');

    [, $output] = install(['--docker' => true, '--force' => true]);

    expect(file_get_contents($dashboard))->not->toContain('by hand')
        ->and($output)->toContain('rendered (--live=poll)');

    // Interactive last: before Laravel 11.44 the mocked console output of $this->artisan() stays bound after the
    // command, so a later Artisan::call() would ask its questions through that mock.
    file_put_contents($dashboard, "{\"rendered\": \"--live=stream by hand\"}\n");

    $this->artisan('horizon-observability:install', ['--docker' => true, '--app-url' => 'http://host.docker.internal:8000'])
        ->expectsConfirmation('The dashboard in docker/observability/grafana differs from the --live=poll render. Render it again?', 'no')
        ->assertExitCode(0);

    expect(file_get_contents($dashboard))->toBe("{\"rendered\": \"--live=stream by hand\"}\n");
});

it('points the endpoints, the scrape jobs and the Infinity URL at the package domain', function (): void {
    config()->set('horizon-observability.domain', 'metrics.shop.test');
    config()->set('app.url', 'http://localhost:8080');
    $this->app->forgetInstance(Config::class);

    [, $output] = install();

    // The routes answer only on the domain: the scheme and port of APP_URL, the domain as the host.
    expect($output)->toContain('http://metrics.shop.test:8080/horizon/observability/{metrics,jobs/*,live}')
        ->and($output)->toContain("targets: ['metrics.shop.test:8080']")
        ->and($output)->toContain('customHealthCheckUrl: http://metrics.shop.test:8080/horizon/observability/jobs/pending?limit=1');

    // Docker with a local APP_URL: the containers reach the domain on the Docker host.
    [$code, $output] = install(['--docker' => true]);
    $compose = Yaml::parseFile($this->base.'/'.DockerStack::COMPOSE_FILE);
    $prometheus = Yaml::parseFile($this->base.'/docker/observability/prometheus/prometheus.yml');

    expect($code)->toBe(0)
        ->and($prometheus['scrape_configs'][0]['static_configs'][0]['targets'])->toBe(['metrics.shop.test:8080'])
        ->and($compose['services']['grafana']['environment']['HORIZON_OBSERVABILITY_APP_URL'])->toBe('http://metrics.shop.test:8080')
        ->and($compose['services']['prometheus']['extra_hosts'])->toBe(['host.docker.internal:host-gateway', 'metrics.shop.test:host-gateway'])
        ->and($compose['services']['grafana']['extra_hosts'])->toBe(['host.docker.internal:host-gateway', 'metrics.shop.test:host-gateway'])
        ->and($output)->toContain('php artisan serve --host=0.0.0.0 --port=8080');

    // A domain without a port in APP_URL gets the port of php artisan serve; a public APP_URL keeps DNS.
    config()->set('app.url', 'http://localhost');
    install(['--docker' => true, '--force' => true]);

    expect(Yaml::parseFile($this->base.'/docker/observability/prometheus/prometheus.yml')['scrape_configs'][0]['static_configs'][0]['targets'])->toBe(['metrics.shop.test:8000']);

    config()->set('app.url', 'https://shop.example.com');
    config()->set('horizon-observability.domain', 'metrics.example.com');
    install(['--docker' => true, '--force' => true]);
    $compose = Yaml::parseFile($this->base.'/'.DockerStack::COMPOSE_FILE);

    expect($compose['services']['grafana']['environment']['HORIZON_OBSERVABILITY_APP_URL'])->toBe('https://metrics.example.com')
        ->and($compose['services']['prometheus']['extra_hosts'])->toBe(['host.docker.internal:host-gateway']);
});

it('asks where Docker reaches the app when interactive', function (): void {
    $this->artisan('horizon-observability:install', ['--docker' => true])
        ->expectsQuestion('At which URL do Prometheus and Grafana in Docker reach the app?', 'http://host.docker.internal:9000')
        ->expectsConfirmation('Publish config/horizon-observability.php?', 'yes')
        ->assertExitCode(0);

    $prometheus = Yaml::parseFile($this->base.'/docker/observability/prometheus/prometheus.yml');

    expect($prometheus['scrape_configs'][0]['static_configs'][0]['targets'])->toBe(['host.docker.internal:9000']);
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

it('follows a custom Horizon path in the snippets and the scrape job', function (): void {
    config()->set('horizon.path', 'admin/horizon');

    // The routes were registered at boot under the old path, so the check reports them; only the files count here.
    [, $output] = install(['--docker' => true]);
    $prometheus = Yaml::parseFile($this->base.'/docker/observability/prometheus/prometheus.yml');
    $datasources = (string) file_get_contents($this->base.'/docker/observability/grafana/provisioning/datasources/horizon-observability.yaml');

    expect($prometheus['scrape_configs'][0]['metrics_path'])->toBe('/admin/horizon/observability/metrics')
        ->and($datasources)->toContain('${HORIZON_OBSERVABILITY_APP_URL}/admin/horizon/observability/jobs/pending?limit=1')
        ->and($output)->toContain('metrics_path: /admin/horizon/observability/metrics')
        ->and($output)->toContain('http://localhost/admin/horizon');
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
        // Escaped for compose: the shell in the container reads the variable (its first entry), so the token is in no
        // file of the stack.
        ->and($resolved['services']['prometheus']['command'][0])->toContain('"$${HORIZON_OBSERVABILITY_TOKEN%%,*}"')
        ->and($resolved['services']['grafana']['command'][0])->toContain('export HORIZON_OBSERVABILITY_API_TOKEN="$${HORIZON_OBSERVABILITY_API_TOKEN%%,*}";');

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

it('prints the totals of its checks as check counts them, and each problem', function (): void {
    // Errors (an unreachable storage Redis) and warnings (no namespace, Horizon not running, ...).
    config()->set('database.redis.horizon', ['host' => '127.0.0.1', 'port' => 1, 'database' => 0]);
    config()->set('horizon-observability.namespace', '');

    [$code, $output] = install();

    app()->forgetInstance(Config::class);
    Artisan::call('horizon-observability:check', ['--json' => true]);
    /** @var array{errors: int, warnings: int} $report */
    $report = json_decode(Artisan::output(), true, flags: JSON_THROW_ON_ERROR);

    expect($code)->toBe(1)
        ->and($report['errors'])->toBeGreaterThan(1)
        ->and($report['warnings'])->toBeGreaterThan(1)
        ->and($output)->toContain(sprintf('Check: %d errors, %d warnings (details: php artisan horizon-observability:check)', $report['errors'], $report['warnings']))
        ->and(preg_match_all('/^    ERROR /m', $output))->toBe($report['errors'])
        ->and(preg_match_all('/^    WARN /m', $output))->toBe($report['warnings'])
        ->and($output)->toContain('WARN Configuration, Namespace: none: metric names such as queue_jobs_total may collide with other exporters');
});

it('loads the demo job class from the package', function (): void {
    expect(class_exists(DemoJob::class))->toBeTrue();
});
