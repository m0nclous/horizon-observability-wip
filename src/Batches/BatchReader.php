<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Batches;

use Carbon\CarbonImmutable;

/**
 * Batching driver and table detection plus the two bounded batch queries (Arch Q6.7, C1, C15).
 *
 * Stub (T02): batching is never available. Owned by T07 (WP5).
 *
 * @internal
 */
final class BatchReader
{
    /**
     * Whether the database batching driver and its table are there (auto mode of `collectors.batches`).
     */
    public function available(): bool
    {
        return false;
    }

    /**
     * Running batches of the last 7 days and batches closed during the last hour, newest first.
     *
     * @return list<BatchSnapshot>
     */
    public function read(CarbonImmutable $now): array
    {
        return [];
    }
}
