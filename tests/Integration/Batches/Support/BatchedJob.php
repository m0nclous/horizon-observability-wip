<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\Integration\Batches\Support;

use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;

/**
 * A batchable job for the batch-link tests; it is only dispatched, never run.
 */
final class BatchedJob implements ShouldQueue
{
    use Batchable;
    use Dispatchable;
    use Queueable;

    public function handle(): void {}
}
