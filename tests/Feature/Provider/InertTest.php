<?php

declare(strict_types=1);

use DF\HorizonObservability\Attempts\AttemptTracker;
use DF\HorizonObservability\Batches\BatchLinks;
use DF\HorizonObservability\HorizonObservabilityServiceProvider;
use DF\HorizonObservability\Tests\Support\Apps;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Http\Middleware\PreventRequestsDuringMaintenance;
use Illuminate\Support\Facades\Artisan;
use Laravel\Horizon\HorizonServiceProvider;

/*
| enabled = false is inert (Arch Q9 WP0b): no routes, no queue event listeners, no menu link, no maintenance
| exception. The commands stay, so check and flush still work.
*/

dataset('provider orders', [
    'Horizon first' => [[HorizonServiceProvider::class, HorizonObservabilityServiceProvider::class]],
    'package first' => [[HorizonObservabilityServiceProvider::class, HorizonServiceProvider::class]],
]);

/**
 * Event => listener descriptions registered by the package.
 *
 * @return list<string>
 */
function packageListeners(Application $app): array
{
    $found = [];

    foreach ($app['events']->getRawListeners() as $event => $listeners) {
        foreach ($listeners as $listener) {
            $description = is_array($listener) ? implode('@', array_map(static fn ($part): string => is_object($part) ? $part::class : (string) $part, $listener)) : (is_string($listener) ? $listener : '');

            if (str_contains($description, 'DF\\HorizonObservability\\')) {
                $found[] = $event.' => '.$description;
            }
        }
    }

    sort($found);

    return $found;
}

/**
 * @return list<string>
 */
function packageRoutes(Application $app): array
{
    return array_values(array_filter(
        array_map(static fn ($route): string => (string) $route->getName(), $app['router']->getRoutes()->getRoutes()),
        static fn (string $name): bool => str_starts_with($name, 'horizon-observability.'),
    ));
}

it('is inert when disabled', function (array $providers, mixed $enabled): void {
    $state = Apps::inspect($providers, [
        'horizon-observability.enabled' => $enabled,
        'horizon-observability.grafana.url' => 'http://localhost:3000/d/horizon-observability',
    ], static fn (Application $app): array => [
        'routes' => packageRoutes($app),
        'metrics' => Apps::match($app['router']->getRoutes(), '/horizon/observability/metrics'),
        'middleware' => $app['config']->get('horizon.middleware'),
        'listeners' => packageListeners($app),
        'maintenance' => (new PreventRequestsDuringMaintenance($app))->getExcludedPaths(),
        'commands' => array_values(array_filter(array_keys(Artisan::all()), static fn (string $name): bool => str_starts_with($name, 'horizon-observability:'))),
    ]);

    expect($state['routes'])->toBe([])
        ->and($state['metrics'])->toBe('horizon.index')
        ->and($state['middleware'])->toBe(['web'])
        ->and($state['listeners'])->toBe([])
        ->and($state['maintenance'])->toBe([])
        ->and($state['commands'])->toHaveCount(6);
})->with('provider orders')->with(['false' => [false], 'string false' => ['false'], 'zero' => ['0']]);

it('wires everything when enabled', function (array $providers): void {
    $state = Apps::inspect($providers, [], static fn (Application $app): array => [
        'routes' => packageRoutes($app),
        'listeners' => packageListeners($app),
        'maintenance' => (new PreventRequestsDuringMaintenance($app))->getExcludedPaths(),
    ]);

    expect($state['routes'])->toBe(['horizon-observability.metrics', 'horizon-observability.jobs', 'horizon-observability.live'])
        ->and($state['listeners'])->toBe([
            'Illuminate\\Queue\\Events\\JobAttempted => '.AttemptTracker::class.'@onAttempted',
            'Illuminate\\Queue\\Events\\JobExceptionOccurred => '.AttemptTracker::class.'@onExceptionOccurred',
            'Illuminate\\Queue\\Events\\JobProcessing => '.AttemptTracker::class.'@onProcessing',
            'Illuminate\\Queue\\Events\\JobQueued => '.BatchLinks::class.'@onQueued',
            'Illuminate\\Queue\\Events\\JobTimedOut => '.AttemptTracker::class.'@onTimedOut',
        ])
        ->and($state['maintenance'])->toBe(['horizon/observability/metrics', 'horizon/observability/jobs/*', 'horizon/observability/live']);
})->with('provider orders');

it('skips the listeners of disabled collectors and the maintenance exception on opt-out', function (): void {
    $state = Apps::inspect([HorizonServiceProvider::class, HorizonObservabilityServiceProvider::class], [
        'horizon-observability.collectors.attempts' => false,
        'horizon-observability.collectors.batches' => false,
        'horizon-observability.available_during_maintenance' => false,
    ], static fn (Application $app): array => [packageListeners($app), (new PreventRequestsDuringMaintenance($app))->getExcludedPaths()]);

    expect($state)->toBe([[], []]);
});

it('leaves /live out of the maintenance exception when live is disabled', function (): void {
    $paths = Apps::inspect([HorizonServiceProvider::class, HorizonObservabilityServiceProvider::class], [
        'horizon-observability.live.enabled' => false,
        'horizon-observability.path' => 'metrics/horizon',
    ], static fn (Application $app): array => (new PreventRequestsDuringMaintenance($app))->getExcludedPaths());

    expect($paths)->toBe(['metrics/horizon/metrics', 'metrics/horizon/jobs/*']);
});
