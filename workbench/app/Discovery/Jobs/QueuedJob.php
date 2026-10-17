<?php

declare(strict_types=1);

namespace Workbench\App\Discovery\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Discovery fixture (Arch 8.2): an abstract base job. Skipped: abstract.
 */
abstract class QueuedJob implements ShouldQueue
{
    use Dispatchable;
    use Queueable;

    abstract public function handle(): void;
}
