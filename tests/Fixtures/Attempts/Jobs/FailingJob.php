<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\Fixtures\Attempts\Jobs;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use RuntimeException;

/**
 * Always throws; with `$tries > 1` the worker retries it, then fails it.
 */
final class FailingJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;

    public function __construct(public int $tries = 1) {}

    public function handle(): void
    {
        throw new RuntimeException('Failing on purpose.');
    }
}
