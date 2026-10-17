<?php
// Probe: does a route registered from an app->booting() callback beat Horizon's catch-all,
// in both provider orders, uncached and compiled (route:cache)? Scratch only.
require '/tmp/claude-0/-home-user-horizon-observability/cc6491ff-4b68-5ae7-bac7-d93fef57187c/scratchpad/audit/vendorprobe/vendor/autoload.php';

use Illuminate\Http\Request;
use Illuminate\Routing\CompiledRouteCollection;
use Illuminate\Support\Facades\Route;
use Orchestra\Testbench\Foundation\Application as TB;

class BootingProvider extends Illuminate\Support\ServiceProvider {
    public function register(): void {
        $this->app->booting(function () {
            $base = trim((string) config('horizon.path'), '/').'/observability';
            Route::get($base.'/metrics', fn () => 'ours-metrics')->name('ho.metrics');
            Route::get($base.'/jobs/{list}', fn ($list) => 'ours-'.$list)->where('list', 'pending|completed|failed|delayed')->name('ho.jobs');
            // the owner's suggested path, to show it would shadow Horizon's SPA URL
            Route::get(trim((string) config('horizon.path'), '/').'/metrics', fn () => 'ours-collision')->name('ho.collision');
        });
    }
}
class BootProvider extends Illuminate\Support\ServiceProvider {
    public function boot(): void {
        Route::get('horizon/late/metrics', fn () => 'late')->name('late.metrics');
    }
}

function run(array $providers, string $label): void {
    $app = TB::create(basePath: null, options: ['extra' => ['providers' => $providers]]);
    $router = $app['router'];
    $paths = ['/horizon/observability/metrics', '/horizon/observability/jobs/failed', '/horizon/observability/jobs/nope', '/horizon/metrics', '/horizon/metrics/jobs', '/horizon/late/metrics', '/horizon/dashboard'];
    $routes = $router->getRoutes();
    $compiled = $routes->compile();
    $cached = (new CompiledRouteCollection($compiled['compiled'], $compiled['attributes']))->setRouter($router)->setContainer($app);
    foreach ($paths as $p) {
        $req = Request::create($p, 'GET');
        $a = $routes->match($req)->getName();
        $b = $cached->match($req)->getName();
        printf("%-22s %-38s uncached=%-14s cached=%s\n", $label, $p, $a, $b);
    }
}
run([Laravel\Horizon\HorizonServiceProvider::class, BootingProvider::class, BootProvider::class], 'horizon-first');
run([BootingProvider::class, BootProvider::class, Laravel\Horizon\HorizonServiceProvider::class], 'package-first');
