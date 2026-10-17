<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\Support;

use DF\HorizonObservability\HorizonObservabilityServiceProvider;
use Illuminate\Support\ServiceProvider;

/**
 * Registers the package from its own `boot()`, after the application's booting callbacks have fired (a late
 * registration that `isBooted()` does not see). Binds a {@see LogRecorder} as `log` first, so the warning can be read.
 */
final class RegistersInBootProvider extends ServiceProvider
{
    public function boot(): void
    {
        $this->app->instance('log', new LogRecorder);
        $this->app->register(HorizonObservabilityServiceProvider::class);
    }
}
