<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\Fixtures\Attempts\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Runs a {@see CompletingJob} with `dispatchSync()` inside its own attempt.
 */
final class NestingJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function handle(): void
    {
        CompletingJob::dispatchSync();
    }
}
