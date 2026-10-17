<?php

declare(strict_types=1);

namespace Workbench\App\Discovery\Support;

use Illuminate\Contracts\Queue\ShouldQueue;

/**
 * Discovery fixture: two classes in one file. Skipped by the prefilter, never included.
 */
final class TwoClasses implements ShouldQueue
{
    public function handle(): void {}
}

/**
 * The second class of the file.
 */
final class SecondClass {}
