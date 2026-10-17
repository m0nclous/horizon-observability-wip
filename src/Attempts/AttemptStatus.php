<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Attempts;

/**
 * Outcome of a finished job attempt: the `status` label of `queue_jobs_total`.
 *
 * The values are public (Arch Q4, SemVer-covered). `released` stays: it is a real outcome and keeps the totals
 * additive.
 */
enum AttemptStatus: string
{
    case Completed = 'completed';
    case Released = 'released';
    case Retried = 'retried';
    case Failed = 'failed';
    case TimedOut = 'timed_out';
}
