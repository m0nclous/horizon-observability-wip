<?php

declare(strict_types=1);

namespace Workbench\App\Providers;

use Illuminate\Support\ServiceProvider;
use Laravel\Horizon\Horizon;

/**
 * Workbench wiring: Horizon's dashboard is open in the workbench.
 */
final class WorkbenchServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Horizon::auth(static fn (): bool => true);
    }
}
