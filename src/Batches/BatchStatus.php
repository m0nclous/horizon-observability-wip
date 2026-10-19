<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Batches;

/**
 * State of a job batch: the `status` label of `queue_batch_progress`. Capitalised because the dashboard shows the
 * values as they are. The values are public and SemVer-covered.
 */
enum BatchStatus: string
{
    case Running = 'Running';
    case Finished = 'Finished';
    case Failures = 'Failures';
    case Cancelled = 'Cancelled';
}
