<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Queues;

use Carbon\CarbonImmutable;

/**
 * Not-yet-due delayed rows via `delayed_jobs.lua` (Arch Q2, C13).
 *
 * Stub (T02): no rows. Owned by T06 (WP4).
 *
 * @internal
 */
final class DelayedJobStore
{
    /**
     * Light rows of the delayed list, ascending by availability.
     *
     * @param  list<array{connection: string, queue: string}>  $queues
     * @param  list<string>|null  $jobClasses  null = all
     * @return list<array<string, mixed>>
     */
    public function rows(array $queues, ?array $jobClasses, int $limit, CarbonImmutable $now): array
    {
        return [];
    }
}
