<?php

declare(strict_types=1);

use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Tests\Feature\Console\Support\FakeGrafanaCommand;
use Dotenv\Dotenv;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;

/*
| T14 QA round 3 (adversarial): horizon-observability:install and the values Laravel reads as "no value".
|
| Laravel's env() (Illuminate\Support\Env::get()) reads null and (null) as null, empty and (empty) as an empty
| string: the skeleton's .env.example writes REDIS_PASSWORD=null, MAIL_USERNAME=null the same way, so
| HORIZON_OBSERVABILITY_TOKEN=null is how such a project writes "no token yet". The app then has no token (open in
| local, locked everywhere else). install reads the line as an existing token: it keeps it ("already in .env,
| kept"), writes nothing, applies the literal string to its own process and reports "token: 1 of 1 tokens are
| shorter than 16 characters" and "none valid" (exit 1), a state the app never sees, with a fix that does not name
| the cause; the Docker stack would send "Bearer null". An unset value must get the generated token, as KEY= does.
*/

pest()->group('redis');

beforeEach(function (): void {
    $this->base = sys_get_temp_dir().'/ho-adv3-install-'.getmypid().'-'.bin2hex(random_bytes(4));

    foreach (['config', 'app', 'bootstrap/cache', 'storage/logs'] as $directory) {
        mkdir($this->base.'/'.$directory, 0777, true);
    }

    file_put_contents($this->base.'/.env.example', "APP_NAME=Laravel\nAPP_URL=http://localhost\n");
    $this->app->setBasePath($this->base);
    $this->app->useEnvironmentPath($this->base);
    config()->set('app.name', 'My Shop');
    config()->set('app.url', 'http://localhost');
    // What env('HORIZON_OBSERVABILITY_TOKEN') returns for each of these lines: null or ''.
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

it('writes a token over a value Laravel reads as no value', function (string $value): void {
    file_put_contents($this->base.'/.env', "APP_NAME=\"My Shop\"\nAPP_ENV=local\nAPP_URL=http://localhost\nHORIZON_OBSERVABILITY_TOKEN={$value}\n");

    $code = Artisan::call('horizon-observability:install', ['--no-interaction' => true]);
    $output = Artisan::output();
    $read = Dotenv::parse((string) file_get_contents($this->base.'/.env'))['HORIZON_OBSERVABILITY_TOKEN'] ?? null;

    // As the next start of the app reads it (Env::get() turns these literals into null or '').
    expect(in_array(strtolower((string) $read), ['null', '(null)', 'empty', '(empty)', ''], true))->toBeFalse('.env still has HORIZON_OBSERVABILITY_TOKEN='.$read)
        ->and(strlen((string) $read))->toBeGreaterThanOrEqual(Config::MIN_TOKEN_LENGTH)
        ->and($output)->not->toContain('shorter than 16 characters')
        ->and($code)->toBe(0);
})->with(['null', '(null)', 'empty', '(empty)']);
