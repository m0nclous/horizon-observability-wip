<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Horizon;

use DF\HorizonObservability\Http\JobListQuery;

/**
 * Pending, completed and failed rows from Horizon's Redis: chunked index reads, light `HMGET` fields, details through
 * `job_details.lua` for the final page only (Arch Q2, C13, C14).
 *
 * Stub (T02): no rows. Owned by T06 (WP4).
 *
 * @internal
 */
final class JobStore
{
    /**
     * Raw rows of a pending, completed or failed list, newest first.
     *
     * @param  list<string>|null  $queues  resolved queue filter; null = all
     * @param  list<string>|null  $jobClasses  resolved class filter; null = all
     * @return list<array<string, mixed>>
     */
    public function rows(JobListQuery $query, ?array $queues, ?array $jobClasses): array
    {
        return [];
    }
}
