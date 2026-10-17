<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Batches;

use Illuminate\Queue\Events\JobQueued;

/**
 * Links of batch kinds to queues and job classes (Arch Q6.7): written by a `JobQueued` listener for batched jobs on
 * `redis` connections (memoised, through the circuit breaker), read by the batches collector, the job lists and the
 * live scope.
 *
 * Stub (T02): writes nothing, reads nothing. Owned by T07 (WP5).
 *
 * @internal
 */
final class BatchLinks
{
    public function onQueued(JobQueued $event): void {}

    /**
     * Links seen during the last 7 days.
     *
     * @return list<BatchLink>
     */
    public function all(): array
    {
        return [];
    }

    /**
     * Queues and job classes of the given batch kinds.
     *
     * @param  list<string>  $kinds
     * @return array{queues: list<string>, jobs: list<string>}
     */
    public function forKinds(array $kinds): array
    {
        return ['queues' => [], 'jobs' => []];
    }
}
