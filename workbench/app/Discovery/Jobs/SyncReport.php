<?php

declare(strict_types=1);

namespace Workbench\App\Discovery\Jobs;

use Illuminate\Foundation\Bus\Dispatchable;

/**
 * Discovery fixture: a job that always runs synchronously (no queue interface). Skipped.
 */
final class SyncReport
{
    use Dispatchable;

    public function handle(): void {}
}
