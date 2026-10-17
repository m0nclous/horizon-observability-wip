<?php

declare(strict_types=1);

namespace Workbench\App\Discovery\Support;

use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Discovery fixture: a class next to a file-level function. Skipped by the prefilter, never included.
 */
final class WithFunction implements ShouldQueue
{
    public function handle(): void {}
}

function with_function(): bool
{
    return true;
}
