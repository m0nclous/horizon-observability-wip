<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\Fixtures\Attempts\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Succeeds after an optional pause; counts its runs in the cache (`completing-job`).
 */
final class CompletingJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(public int $sleepMilliseconds = 0) {}

    public function handle(): void
    {
        if ($this->sleepMilliseconds > 0) {
            usleep($this->sleepMilliseconds * 1000);
        }

        cache()->increment('completing-job');
    }
}
