<?php

declare(strict_types=1);

namespace Workbench\App\Discovery\Support;

use Illuminate\Contracts\Queue\ShouldQueue;

/*
| Discovery fixture: a functions file, as an application loads it through Composer's "files". It
| names ShouldQueue and maps to a PSR-4 class name, which made the pilot autoload it a second time: the unguarded
| function below then fatals with "Cannot redeclare". The prefilter never hands it to the autoloader.
*/

function is_queued(object $job): bool
{
    return $job instanceof ShouldQueue;
}
