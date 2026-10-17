<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Queues;

use Carbon\CarbonImmutable;

/**
 * One `queue_state.lua` call per queue on a reader client (Arch Q6.3, Addendum D').
 *
 * Stub (T02): every queue is empty. Owned by T05 (WP3).
 *
 * @internal
 */
final class QueueInspector
{
    /**
     * Full mode for the queues collector: counts, readiness and per-class maps of the scanned head.
     */
    public function inspect(string $connection, string $queue, CarbonImmutable $now): QueueSnapshot
    {
        return QueueSnapshot::empty($connection, $queue);
    }

    /**
     * Live mode for the live snapshot: at most 20 head items and 1 MB, no pending classes, reserved classes only
     * when asked (a Job or Batch filter is active).
     */
    public function live(string $connection, string $queue, CarbonImmutable $now, bool $reservedByClass): QueueSnapshot
    {
        return QueueSnapshot::empty($connection, $queue);
    }
}
