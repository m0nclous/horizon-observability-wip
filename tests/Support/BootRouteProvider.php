<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\Support;

use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

/**
 * Negative control of the route precedence tests: a route registered in `boot()` (the position the package avoids).
 */
final class BootRouteProvider extends ServiceProvider
{
    public function boot(): void
    {
        $horizon = config('horizon.path');

        Route::get(trim(is_string($horizon) ? $horizon : 'horizon', '/').'/observability/late', static fn (): string => 'late')->name('late.route');
    }
}
