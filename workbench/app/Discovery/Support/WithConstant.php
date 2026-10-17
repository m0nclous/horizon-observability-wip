<?php

declare(strict_types=1);

namespace Workbench\App\Discovery\Support;

use Illuminate\Contracts\Queue\ShouldQueue;

const WITH_CONSTANT = 1;

/**
 * Discovery fixture: a class next to a file-level constant. Skipped by the prefilter, never included.
 */
final class WithConstant implements ShouldQueue
{
    public function handle(): void {}
}
