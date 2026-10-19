<?php

declare(strict_types=1);

use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Tests\Feature\Console\Support\FakeGrafanaCommand;
use Dotenv\Dotenv;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;
use Illuminate\Support\Facades\Artisan;

/*
| Adversarial probe of horizon-observability:install and a token defined twice without a value (fixed for #29).
|
| EnvFile::with() filled every no-value definition of the key in place, and appended the key only when none of them
| could be filled (a comment after the value). With two definitions where only the last one has a comment
| (KEY=null ... KEY=null # set later), it filled the first one and stopped: the last definition still wins for
| phpdotenv, so the next start of the app read "null", i.e. no token, and install exited 1. It now decides by the last
| definition and appends the key after it, as for a single commented definition.
*/

pest()->group('redis');

beforeEach(function (): void {
    $this->base = sys_get_temp_dir().'/ho-adv4-install-'.getmypid().'-'.bin2hex(random_bytes(4));

    foreach (['config', 'app', 'bootstrap/cache', 'storage/logs'] as $directory) {
        mkdir($this->base.'/'.$directory, 0777, true);
    }

    file_put_contents($this->base.'/.env.example', "APP_NAME=Laravel\nAPP_URL=http://localhost\n");
    $this->app->setBasePath($this->base);
    $this->app->useEnvironmentPath($this->base);
    config()->set('app.name', 'My Shop');
    config()->set('app.url', 'http://localhost');
    // What env('HORIZON_OBSERVABILITY_TOKEN') returns for the last definition: null.
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

it('gives the app the token when a later definition without a value has a comment', function (string $first): void {
    file_put_contents($this->base.'/.env', "APP_NAME=\"My Shop\"\nAPP_ENV=local\nHORIZON_OBSERVABILITY_TOKEN={$first}\nAPP_URL=http://localhost\nHORIZON_OBSERVABILITY_TOKEN=null # set later\n");

    $code = Artisan::call('horizon-observability:install', ['--no-interaction' => true]);
    $output = Artisan::output();
    // As the next start of the app reads it: the last definition wins.
    $read = Dotenv::parse((string) file_get_contents($this->base.'/.env'))['HORIZON_OBSERVABILITY_TOKEN'] ?? null;

    expect(in_array(strtolower((string) $read), ['null', '(null)', 'empty', '(empty)', ''], true))->toBeFalse('the app reads HORIZON_OBSERVABILITY_TOKEN='.$read.'; install said: '.trim($output))
        ->and(strlen((string) $read))->toBeGreaterThanOrEqual(Config::MIN_TOKEN_LENGTH)
        ->and($code)->toBe(0);
})->with(['null', '', '(empty)']);
