<?php

declare(strict_types=1);

use DF\HorizonObservability\HorizonObservabilityServiceProvider;
use DF\HorizonObservability\Tests\Support\Apps;
use DF\HorizonObservability\Tests\Support\BootRouteProvider;
use Illuminate\Foundation\Application;
use Illuminate\Routing\RouteCollectionInterface;
use Laravel\Horizon\HorizonServiceProvider;

/*
| The package routes, registered from an application booting callback, beat Horizon's catch-all route in both
| provider orders, uncached and as route:cache compiles them, for every horizon.path; /live is included, and
| live.enabled=false leaves no live route.
*/

dataset('provider orders', [
    'Horizon first' => [[HorizonServiceProvider::class, HorizonObservabilityServiceProvider::class]],
    'package first' => [[HorizonObservabilityServiceProvider::class, HorizonServiceProvider::class]],
]);

dataset('route modes', [
    'uncached' => [static fn (Application $app): RouteCollectionInterface => $app['router']->getRoutes()],
    'compiled' => [Apps::compiled(...)],
]);

it('routes the endpoints before Horizon\'s catch-all', function (array $providers, Closure $routes): void {
    $matches = Apps::inspect($providers, [], static fn (Application $app): array => array_map(
        static fn (string $url): ?string => Apps::match($routes($app), $url),
        [
            'metrics' => '/horizon/observability/metrics',
            'pending' => '/horizon/observability/jobs/pending',
            'completed' => '/horizon/observability/jobs/completed',
            'failed' => '/horizon/observability/jobs/failed',
            'delayed' => '/horizon/observability/jobs/delayed',
            'live' => '/horizon/observability/live',
            'unknown list' => '/horizon/observability/jobs/nope',
            'Horizon metrics page' => '/horizon/metrics',
            'Horizon metrics jobs' => '/horizon/metrics/jobs',
            'Horizon dashboard' => '/horizon/dashboard',
        ],
    ));

    expect($matches)->toBe([
        'metrics' => 'horizon-observability.metrics',
        'pending' => 'horizon-observability.jobs',
        'completed' => 'horizon-observability.jobs',
        'failed' => 'horizon-observability.jobs',
        'delayed' => 'horizon-observability.jobs',
        'live' => 'horizon-observability.live',
        'unknown list' => 'horizon.index',
        'Horizon metrics page' => 'horizon.index',
        'Horizon metrics jobs' => 'horizon.index',
        'Horizon dashboard' => 'horizon.index',
    ]);
})->with('provider orders')->with('route modes');

it('answers HEAD on the endpoints', function (array $providers): void {
    expect(Apps::inspect($providers, [], static fn (Application $app): ?string => Apps::match($app['router']->getRoutes(), '/horizon/observability/metrics', 'HEAD')))
        ->toBe('horizon-observability.metrics');
})->with('provider orders');

it('follows horizon.path', function (array $providers, Closure $routes, string $horizonPath, array $expected): void {
    $matches = Apps::inspect($providers, ['horizon.path' => $horizonPath], static fn (Application $app): array => array_map(
        static fn (string $url): ?string => Apps::match($routes($app), $url),
        array_combine(array_keys($expected), array_keys($expected)),
    ));

    expect($matches)->toBe($expected);
})->with('provider orders')->with('route modes')->with([
    'nested' => ['admin/horizon', [
        '/admin/horizon/observability/metrics' => 'horizon-observability.metrics',
        '/admin/horizon/observability/jobs/failed' => 'horizon-observability.jobs',
        '/admin/horizon/observability/live' => 'horizon-observability.live',
        '/admin/horizon/metrics' => 'horizon.index',
        '/horizon/observability/metrics' => null,
    ]],
    'Horizon at the root of its domain' => ['', [
        '/horizon/observability/metrics' => 'horizon-observability.metrics',
        '/horizon/observability/jobs/pending' => 'horizon-observability.jobs',
        '/horizon/observability/live' => 'horizon-observability.live',
        '/metrics' => 'horizon.index',
        '/observability/metrics' => 'horizon.index',
    ]],
]);

it('uses the path key as the whole base', function (array $providers, Closure $routes): void {
    $matches = Apps::inspect($providers, ['horizon-observability.path' => 'metrics/horizon'], static fn (Application $app): array => [
        Apps::match($routes($app), '/metrics/horizon/metrics'),
        Apps::match($routes($app), '/metrics/horizon/jobs/delayed'),
        Apps::match($routes($app), '/metrics/horizon/live'),
        Apps::match($routes($app), '/horizon/observability/metrics'),
    ]);

    expect($matches)->toBe(['horizon-observability.metrics', 'horizon-observability.jobs', 'horizon-observability.live', 'horizon.index']);
})->with('provider orders')->with('route modes');

it('binds the endpoints to the configured domain only', function (array $providers): void {
    $matches = Apps::inspect($providers, ['horizon-observability.domain' => 'metrics.internal'], static fn (Application $app): array => [
        Apps::match($app['router']->getRoutes(), 'http://metrics.internal/horizon/observability/metrics'),
        Apps::match($app['router']->getRoutes(), 'http://app.example.com/horizon/observability/metrics'),
    ]);

    expect($matches)->toBe(['horizon-observability.metrics', 'horizon.index']);
})->with('provider orders');

it('registers no live route when live.enabled is false', function (array $providers, Closure $routes): void {
    [$live, $names] = Apps::inspect($providers, ['horizon-observability.live.enabled' => false], static fn (Application $app): array => [
        Apps::match($routes($app), '/horizon/observability/live'),
        array_values(array_filter(
            array_map(static fn ($route): ?string => $route->getName(), $app['router']->getRoutes()->getRoutes()),
            static fn (?string $name): bool => str_starts_with((string) $name, 'horizon-observability.'),
        )),
    ]);

    expect($live)->toBe('horizon.index')
        ->and($names)->toBe(['horizon-observability.metrics', 'horizon-observability.jobs']);
})->with('provider orders')->with('route modes');

it('loses to the catch-all when registered in boot() (negative control)', function (Closure $routes): void {
    $providers = [HorizonServiceProvider::class, HorizonObservabilityServiceProvider::class, BootRouteProvider::class];

    [$registered, $match] = Apps::inspect($providers, [], static fn (Application $app): array => [
        $app['router']->getRoutes()->getByName('late.route') !== null,
        Apps::match($routes($app), '/horizon/observability/late'),
    ]);

    expect($registered)->toBeTrue()
        ->and($match)->toBe('horizon.index');
})->with('route modes');

it('keeps the precedence with a real route:cache', function (): void {
    $this->defineCacheRoutes(<<<'PHP'
<?php
PHP);

    expect($this->app->routesAreCached())->toBeTrue()
        ->and(Apps::match($this->app['router']->getRoutes(), '/horizon/observability/metrics'))->toBe('horizon-observability.metrics')
        ->and(Apps::match($this->app['router']->getRoutes(), '/horizon/observability/jobs/failed'))->toBe('horizon-observability.jobs')
        ->and(Apps::match($this->app['router']->getRoutes(), '/horizon/observability/live'))->toBe('horizon-observability.live')
        ->and(Apps::match($this->app['router']->getRoutes(), '/horizon/metrics'))->toBe('horizon.index');

    $this->get('/horizon/observability/metrics')->assertForbidden();
    $this->getJson('/horizon/observability/jobs/failed')->assertForbidden()->assertExactJson(['message' => 'Forbidden.']);
    $this->get('/horizon/observability/live')->assertForbidden()->assertExactJson(['message' => 'Forbidden.']);
});
