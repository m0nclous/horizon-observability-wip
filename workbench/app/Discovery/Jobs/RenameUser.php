<?php

declare(strict_types=1);

namespace Workbench\App\Discovery\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Discovery fixture: its own displayName(), so its job_class label is not the class name. Skipped.
 */
final class RenameUser implements ShouldQueue
{
    use Queueable;

    public function handle(): void {}

    public function displayName(): string
    {
        return 'rename-user';
    }
}
