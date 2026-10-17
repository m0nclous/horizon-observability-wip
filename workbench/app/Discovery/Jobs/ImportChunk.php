<?php

declare(strict_types=1);

namespace Workbench\App\Discovery\Jobs;

/**
 * Discovery fixture (Arch 8.2): queued through its parent only; this file never names the queue interface. Found.
 */
final class ImportChunk extends QueuedJob
{
    public function handle(): void {}
}
