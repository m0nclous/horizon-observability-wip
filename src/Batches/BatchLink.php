<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Batches;

/**
 * A (batch kind, queue connection, queue, job class) tuple seen for batched jobs during the last 7 days (Arch Q6.7).
 *
 * @internal
 */
final readonly class BatchLink
{
    public function __construct(public string $kind, public string $connection, public string $queue, public string $jobClass) {}
}
