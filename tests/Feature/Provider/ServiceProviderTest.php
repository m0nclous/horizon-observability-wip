<?php

declare(strict_types=1);

use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\HorizonObservabilityServiceProvider;
use DF\HorizonObservability\Redis\CircuitBreaker;
use DF\HorizonObservability\Redis\Keys;
use DF\HorizonObservability\Redis\Lua;
use DF\HorizonObservability\Redis\RedisClients;
use DF\HorizonObservability\Support\FailureReporter;
use DF\HorizonObservability\Support\Versions;
use DF\HorizonObservability\Tests\Support\Apps;
use DF\HorizonObservability\Tests\Support\LogRecorder;
use DF\HorizonObservability\Tests\Support\RegistersInBootProvider;
use Illuminate\Foundation\Application;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\ServiceProvider;
use Laravel\Horizon\HorizonServiceProvider;

it('registers the container singletons without resolving them at boot', function (): void {
    foreach ([Config::class, RedisClients::class, CircuitBreaker::class, FailureReporter::class, Lua::class, Keys::class] as $class) {
        expect($this->app->isShared($class))->toBeTrue()
            ->and($this->app->make($class))->toBe($this->app->make($class));
    }

    expect($this->app->isShared(Versions::class))->toBeTrue();
});

it('resolves no config snapshot and opens no Redis connection while booting', function (array $providers): void {
    $state = Apps::inspect($providers, [
        'database.redis.default' => ['host' => '127.0.0.1', 'port' => 1, 'database' => 0],
        'database.redis.horizon' => ['host' => '127.0.0.1', 'port' => 1, 'database' => 0],
    ], static fn (Application $app): array => [
        $app->resolved(Config::class),
        $app->resolved(RedisClients::class),
        $app['router']->getRoutes()->hasNamedRoute('horizon-observability.metrics'),
    ]);

    expect($state)->toBe([false, false, true]);
})->with([
    'Horizon first' => [[HorizonServiceProvider::class, HorizonObservabilityServiceProvider::class]],
    'package first' => [[HorizonObservabilityServiceProvider::class, HorizonServiceProvider::class]],
]);

it('logs the config problems once when the snapshot is first built', function (): void {
    $log = new LogRecorder;
    $this->app->instance('log', $log);
    config()->set('horizon-observability.namespace', '1bad');
    config()->set('horizon-observability.live.stream.id', 'bad id');
    $this->app->forgetInstance(Config::class);

    $this->app->make(Config::class);
    $this->app->make(Config::class);

    expect($log->messages('warning'))->toBe(['horizon-observability: 2 invalid configuration values were replaced by defaults; run php artisan horizon-observability:check.'])
        ->and($log->records[0]['context']['problems'])->toHaveCount(2);
});

it('registers the seven commands and demo, grafana once its class exists (T13)', function (): void {
    $grafana = class_exists('DF\\HorizonObservability\\Console\\GrafanaCommand');
    $commands = array_values(array_filter(array_keys(Artisan::all()), static fn (string $name): bool => str_starts_with($name, 'horizon-observability:')));
    sort($commands);

    expect($commands)->toBe(array_values(array_filter([
        'horizon-observability:cache',
        'horizon-observability:check',
        'horizon-observability:clear',
        'horizon-observability:demo',
        'horizon-observability:flush',
        $grafana ? 'horizon-observability:grafana' : null,
        'horizon-observability:install',
        'horizon-observability:stream',
    ])))->and(HorizonObservabilityServiceProvider::packageCommands())->toHaveCount($grafana ? 8 : 7);
});

it('exits 1 from the stream command without its settings', function (): void {
    $this->artisan('horizon-observability:stream')->assertExitCode(1);
});

it('offers the config and the Grafana assets for publishing', function (): void {
    $config = ServiceProvider::pathsToPublish(HorizonObservabilityServiceProvider::class, 'horizon-observability-config');
    $grafana = ServiceProvider::pathsToPublish(HorizonObservabilityServiceProvider::class, 'horizon-observability-grafana');

    expect(array_map(realpath(...), array_keys($config)))->toBe([realpath(dirname(__DIR__, 3).'/config/horizon-observability.php')])
        ->and(array_values($config))->toBe([config_path('horizon-observability.php')])
        ->and(array_map(static fn (string $path): string => basename(dirname($path)).'/'.basename($path), array_keys($grafana)))->toBe(['resources/grafana'])
        ->and(array_values($grafana))->toBe([base_path('grafana')]);
});

it('hooks the job class cache into optimize', function (): void {
    if (! property_exists(ServiceProvider::class, 'optimizeCommands')) {
        $this->markTestSkipped('optimizes() needs Laravel 11.27.1.');
    }

    expect(ServiceProvider::$optimizeCommands['horizon-observability'] ?? null)->toBe('horizon-observability:cache')
        ->and(ServiceProvider::$optimizeClearCommands['horizon-observability'] ?? null)->toBe('horizon-observability:clear');
});

it('adds an about section', function (): void {
    Artisan::call('about', ['--only' => 'horizon_observability', '--json' => true]);
    $about = json_decode(Artisan::output(), true);

    expect($about['horizon_observability'] ?? null)->toMatchArray([
        'enabled' => 'yes',
        'endpoints' => url('horizon/observability'),
        'namespace' => 'ho',
        'live' => 'enabled',
        'config_problems' => '0',
    ])
        // No token outside "local": locked. T03 may add a hint (e.g. the env var to set) after the word.
        ->and($about['horizon_observability']['access'] ?? null)->toStartWith('locked');
});

it('warns once when registered after boot', function (): void {
    $log = new LogRecorder;
    $this->app->instance('log', $log);

    $this->app->register(new HorizonObservabilityServiceProvider($this->app), force: true);

    expect($log->messages('warning'))->toBe(["horizon-observability: provider registered after boot; Horizon's catch-all route may shadow the endpoints."])
        ->and($this->app['router']->getRoutes()->hasNamedRoute('horizon-observability.metrics'))->toBeTrue();
});

it('still registers the routes, with the warning, when registered from another provider\'s boot()', function (): void {
    [$routes, $warnings, $metrics] = Apps::inspect(
        [HorizonServiceProvider::class, RegistersInBootProvider::class],
        [],
        static function (Application $app): array {
            /** @var LogRecorder $log */
            $log = $app->make('log');

            return [
                array_values(array_filter(
                    array_map(static fn ($route): string => (string) $route->getName(), $app['router']->getRoutes()->getRoutes()),
                    static fn (string $name): bool => str_starts_with($name, 'horizon-observability.'),
                )),
                $log->messages('warning'),
                Apps::match($app['router']->getRoutes(), '/horizon/observability/metrics'),
            ];
        },
    );

    // Horizon booted first, so its catch-all wins: the warning says so instead of silently registering nothing.
    expect($routes)->toBe(['horizon-observability.metrics', 'horizon-observability.jobs', 'horizon-observability.live'])
        ->and($warnings)->toBe(["horizon-observability: provider registered after boot; Horizon's catch-all route may shadow the endpoints."])
        ->and($metrics)->toBe('horizon.index');
});

it('wires up once and quietly in both provider orders (the late fallback stays idle)', function (array $providers): void {
    $log = new LogRecorder;

    $metrics = Apps::inspect(
        $providers,
        [],
        static fn (Application $app): ?string => Apps::match($app['router']->getRoutes(), '/horizon/observability/metrics'),
        static function (Application $app) use ($log): void {
            $app->instance('log', $log);
        },
    );

    expect($metrics)->toBe('horizon-observability.metrics')
        ->and($log->messages('warning'))->toBe([]);
})->with([
    'Horizon first' => [[HorizonServiceProvider::class, HorizonObservabilityServiceProvider::class]],
    'package first' => [[HorizonObservabilityServiceProvider::class, HorizonServiceProvider::class]],
]);

it('answers 403 while locked, before anything else', function (): void {
    $this->get('/horizon/observability/metrics')->assertForbidden()->assertSeeText('Forbidden.');
    $this->get('/horizon/observability/jobs/pending')->assertForbidden()->assertExactJson(['message' => 'Forbidden.']);
    $this->get('/horizon/observability/live?var-queue=default')->assertForbidden()->assertExactJson(['message' => 'Forbidden.']);
    $this->get('/horizon/observability/jobs/nope')->assertStatus(200);
});
