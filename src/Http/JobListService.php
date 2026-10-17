<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Http;

/**
 * Resolves the supervisor and batch-kind filters to queue and class sets and reads the rows; an empty resolved
 * filter returns without any Redis command (Arch Q2, C13).
 *
 * Stub (T02): no rows. Owned by T06 (WP4).
 *
 * @internal
 */
final class JobListService
{
    /**
     * The formatted rows of a list ({@see JobRows::fields()}).
     *
     * @return list<array<string, mixed>>
     */
    public function rows(JobListQuery $query): array
    {
        return [];
    }
}
