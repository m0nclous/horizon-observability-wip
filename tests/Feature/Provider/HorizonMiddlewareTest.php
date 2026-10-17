<?php

declare(strict_types=1);

use DF\HorizonObservability\HorizonObservabilityServiceProvider;
use DF\HorizonObservability\Http\Middleware\InjectGrafanaLink;
use DF\HorizonObservability\Tests\Support\Apps;
use Illuminate\Contracts\Http\Kernel as HttpKernel;
use Illuminate\Foundation\Application;
use Illuminate\Routing\Router;
use Illuminate\Session\Middleware\StartSession;
use Laravel\Horizon\HorizonServiceProvider;

/*
| C16: the menu-link middleware is appended to horizon.middleware from the booting callback, after every register()
| and before Horizon's boot(), so "web" is never lost, in both provider orders, idempotently and always as an array.
*/

dataset('provider orders', [
    'Horizon first' => [[HorizonServiceProvider::class, HorizonObservabilityServiceProvider::class]],
    'package first' => [[HorizonObservabilityServiceProvider::class, HorizonServiceProvider::class]],
]);

/**
 * @param  list<class-string>  $providers
 * @param  array<string, mixed>  $config
 * @return array{config: mixed, group: list<string>|null, route: list<string>}
 */
function horizonMiddleware(array $providers, array $config): array
{
    return Apps::inspect($providers, $config, static function (Application $app): array {
        $app->make(HttpKernel::class); // syncs the kernel's middleware groups (web) to the router
        /** @var Router $router */
        $router = $app['router'];
        $route = $router->getRoutes()->getByName('horizon.index');

        return [
            'config' => $app['config']->get('horizon.middleware'),
            'group' => $router->getMiddlewareGroups()['horizon'] ?? null,
            'route' => $route === null ? [] : array_values(array_map(strval(...), $router->gatherRouteMiddleware($route))),
        ];
    });
}

it('appends the link middleware and keeps web (C16)', function (array $providers): void {
    $middleware = horizonMiddleware($providers, ['horizon-observability.grafana.url' => 'http://localhost:3000/d/horizon-observability']);

    expect($middleware['config'])->toBe(['web', InjectGrafanaLink::class])
        ->and($middleware['route'])->toContain(InjectGrafanaLink::class)
        ->and($middleware['route'])->toContain(StartSession::class);

    // Horizon >= 5.45 builds a "horizon" group in boot() from its own entries (Sentinel) plus horizon.middleware;
    // earlier versions pass horizon.middleware to the routes. Only the configured tail is ours.
    if ($middleware['group'] !== null) {
        expect(array_slice($middleware['group'], -2))->toBe(['web', InjectGrafanaLink::class]);
    }
})->with('provider orders');

it('tolerates the string form and keeps custom middleware in order', function (array $providers, mixed $configured, array $expected): void {
    $middleware = horizonMiddleware($providers, [
        'horizon.middleware' => $configured,
        'horizon-observability.grafana.url' => 'http://localhost:3000/d/horizon-observability',
    ]);

    expect($middleware['config'])->toBe($expected);
})->with('provider orders')->with([
    'string web' => ['web', ['web', InjectGrafanaLink::class]],
    'custom list' => [['web', 'auth.basic'], ['web', 'auth.basic', InjectGrafanaLink::class]],
    'already appended' => [['web', InjectGrafanaLink::class], ['web', InjectGrafanaLink::class]],
]);

it('leaves Horizon alone without a Grafana URL', function (array $providers, ?string $url): void {
    $middleware = horizonMiddleware($providers, ['horizon-observability.grafana.url' => $url]);

    expect($middleware['config'])->toBe(['web'])
        ->and($middleware['route'])->not->toContain(InjectGrafanaLink::class);
})->with('provider orders')->with(['null' => [null], 'empty' => [''], 'blank' => ['  ']]);

it('appends once when the booting callback runs twice', function (): void {
    $middleware = Apps::inspect(
        [HorizonServiceProvider::class, HorizonObservabilityServiceProvider::class],
        ['horizon-observability.grafana.url' => 'http://localhost:3000/d/horizon-observability'],
        static function (Application $app): mixed {
            $app->register(new HorizonObservabilityServiceProvider($app), force: true);

            return $app['config']->get('horizon.middleware');
        },
    );

    expect($middleware)->toBe(['web', InjectGrafanaLink::class]);
});

it('keeps the link middleware in the horizon group of Horizon 5.45+', function (): void {
    $group = Apps::inspect(
        [HorizonObservabilityServiceProvider::class, HorizonServiceProvider::class],
        ['horizon-observability.grafana.url' => 'http://localhost:3000/d/horizon-observability'],
        static fn (Application $app): ?array => $app['router']->getMiddlewareGroups()['horizon'] ?? null,
    );

    if ($group === null) {
        $this->markTestSkipped('Horizon < 5.45 has no horizon middleware group.');
    }

    expect($group)->toContain(InjectGrafanaLink::class);
});
