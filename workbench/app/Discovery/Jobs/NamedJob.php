<?php

declare(strict_types=1);

namespace Workbench\App\Discovery\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Discovery fixture: an abstract base job with a displayName() for its children. Skipped: abstract.
 */
abstract class NamedJob implements ShouldQueue
{
    use Queueable;

    public function displayName(): string
    {
        return 'reports:'.static::class;
    }
}
