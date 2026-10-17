<?php
require '/tmp/claude-0/-home-user-horizon-observability/cc6491ff-4b68-5ae7-bac7-d93fef57187c/scratchpad/audit/vendorprobe/vendor/autoload.php';

use Illuminate\Support\Arr;
use Orchestra\Testbench\Foundation\Application as TB;

class LinkMiddleware { public function handle($r, $n) { return $n($r); } }
class LinkProvider extends Illuminate\Support\ServiceProvider {
    public function register(): void {
        $this->app->booting(function () {
            $current = Arr::wrap(config('horizon.middleware', ['web']));
            if (! in_array(LinkMiddleware::class, $current, true)) {
                config(['horizon.middleware' => [...$current, LinkMiddleware::class]]);
            }
        });
    }
}
foreach (['horizon-first' => [Laravel\Horizon\HorizonServiceProvider::class, LinkProvider::class],
          'package-first' => [LinkProvider::class, Laravel\Horizon\HorizonServiceProvider::class]] as $label => $providers) {
    $app = TB::create(basePath: null, options: ['extra' => ['providers' => $providers]]);
    $group = $app['router']->getMiddlewareGroups()['horizon'] ?? null;
    $route = $app['router']->getRoutes()->getByName('horizon.index');
    printf("%s config=%s group=%s route=%s\n", $label, json_encode(config('horizon.middleware')), json_encode($group), json_encode($route?->middleware()));
}
