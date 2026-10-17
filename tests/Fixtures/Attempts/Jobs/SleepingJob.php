<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\Fixtures\Attempts\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Sleeps longer than its timeout, for the worker's SIGALRM handler.
 */
final class SleepingJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public int $timeout = 1;

    public int $tries = 1;

    public function handle(): void
    {
        sleep(3);
    }
}
