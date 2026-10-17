<?php

declare(strict_types=1);

use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\HorizonObservabilityServiceProvider;
use DF\HorizonObservability\Metrics\Exporter;
use DF\HorizonObservability\Tests\Support\Apps;
use DF\HorizonObservability\Tests\Unit\Metrics\Support\FakeCollector;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;
use Laravel\Horizon\HorizonServiceProvider;

/*
| Arch Q2.4: with available_during_maintenance (the default) the endpoints stay reachable during "php artisan down",
| so queue visibility survives a deploy; everything else answers 503. The opt-out restores the 503.
| The maintenance state lives in the array cache, so nothing is written to the filesystem; the scrapes use quiet fake
| collectors.
*/

const MAINTENANCE_MODE_TEST_TOKEN = 'maintenance-token-0123456789abcdef';

/**
 * Status codes of GET requests to the given paths in a fresh application that is down for maintenance.
 *
 * @param  array<string, mixed>  $config
 * @param  list<string>  $paths
 * @return array<string, int>
 */
function maintenanceModeTestStatuses(array $config, array $paths): array
{
    return Apps::inspect(
        [HorizonServiceProvider::class, HorizonObservabilityServiceProvider::class],
        [
            'cache.default' => 'array',
            'app.maintenance.driver' => 'cache',
            'app.maintenance.store' => 'array',
            'horizon-observability.token' => MAINTENANCE_MODE_TEST_TOKEN,
            ...$config,
        ],
        static function (Application $app) use ($paths): array {
            $app->instance(Exporter::class, new Exporter($app, null, FakeCollector::quiet()));
            $app->maintenanceMode()->activate(['time' => time(), 'retry' => null, 'status' => 503, 'secret' => null, 'template' => null, 'redirect' => null, 'refresh' => null, 'except' => []]);
            $kernel = $app->make(Kernel::class);
            $statuses = [];

            foreach ($paths as $path) {
                $request = Request::create($path, 'GET', [], [], [], ['HTTP_AUTHORIZATION' => 'Bearer '.MAINTENANCE_MODE_TEST_TOKEN]);
                $statuses[$path] = $kernel->handle($request)->getStatusCode();
            }

            return $statuses;
        },
    );
}

it('keeps the endpoints reachable during maintenance', function (): void {
    $this->app['config']->set('app.maintenance', ['driver' => 'cache', 'store' => 'array']);
    $this->app['config']->set('horizon-observability.token', MAINTENANCE_MODE_TEST_TOKEN);
    $this->app->forgetInstance(Config::class);
    $this->app->instance(Exporter::class, new Exporter($this->app, null, FakeCollector::quiet()));
    $this->app->maintenanceMode()->activate(['time' => time(), 'status' => 503]);

    expect($this->app->isDownForMaintenance())->toBeTrue();

    $this->get('/horizon/observability/metrics', ['Authorization' => 'Bearer '.MAINTENANCE_MODE_TEST_TOKEN])->assertOk();
    // The job lists and /live are reachable too: the request gets as far as Authorize (401, not 503).
    foreach (['pending', 'completed', 'failed', 'delayed'] as $list) {
        $this->get('/horizon/observability/jobs/'.$list)->assertUnauthorized();
    }

    $this->get('/horizon/observability/live')->assertUnauthorized();
    // Everything else is down, including Horizon itself and an unknown list name (Horizon's catch-all).
    $this->get('/horizon/observability/jobs/nope')->assertStatus(503);
    $this->get('/horizon/dashboard')->assertStatus(503);
    $this->get('/horizon/metrics')->assertStatus(503);
});

it('keeps them reachable in a fresh application, also under a custom base path', function (): void {
    expect(maintenanceModeTestStatuses([], ['/horizon/observability/metrics', '/horizon/metrics']))
        ->toBe(['/horizon/observability/metrics' => 200, '/horizon/metrics' => 503])
        ->and(maintenanceModeTestStatuses(['horizon-observability.path' => 'metrics/horizon'], ['/metrics/horizon/metrics', '/horizon/observability/metrics']))
        ->toBe(['/metrics/horizon/metrics' => 200, '/horizon/observability/metrics' => 503]);
});

it('answers 503 during maintenance when opted out', function (): void {
    expect(maintenanceModeTestStatuses(['horizon-observability.available_during_maintenance' => false], ['/horizon/observability/metrics', '/horizon/observability/jobs/pending']))
        ->toBe(['/horizon/observability/metrics' => 503, '/horizon/observability/jobs/pending' => 503]);
});
