<?php

declare(strict_types=1);

namespace Workbench\App\Discovery\Jobs;

/**
 * Discovery fixture: inherits displayName(), so its job_class label is not the class name. Skipped.
 */
final class ArchiveReport extends NamedJob
{
    public function handle(): void {}
}
