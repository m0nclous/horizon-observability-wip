<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\Fixtures\Attempts\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;

/**
 * Releases itself back onto the queue on its first attempt (no exception), then succeeds; with `$tries = 1` the
 * second attempt exceeds the max attempts.
 */
final class ReleasingJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(public int $tries = 3) {}

    public function handle(): void
    {
        if ($this->attempts() === 1) {
            $this->release();
        }
    }
}
