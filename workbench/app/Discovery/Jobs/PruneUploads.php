<?php

declare(strict_types=1);

namespace Workbench\App\Discovery\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Discovery fixture: an invokable job (Laravel's bus calls __invoke() when there is no handle()). Found.
 */
final class PruneUploads implements ShouldQueue
{
    use Queueable;

    public function __invoke(): void {}
}
