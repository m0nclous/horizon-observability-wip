<?php

declare(strict_types=1);

namespace Workbench\App\Discovery\Support;

use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Discovery fixture: the class is not named like its file (not PSR-4). Skipped by the prefilter, never included.
 */
final class Renamed implements ShouldQueue
{
    public function handle(): void {}
}
