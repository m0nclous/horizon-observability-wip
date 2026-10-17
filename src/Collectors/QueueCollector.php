<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Collectors;

use DF\HorizonObservability\Metrics\ScrapeContext;

/**
 * Collector `queues`: queue_pending_jobs, queue_delayed_*, queue_oldest_pending_job_age_seconds, queue_jobs_pending|reserved.
 *
 * Stub (T02): collects nothing. Owned by T05 (WP3).
 *
 * @internal
 */
final class QueueCollector implements Collector
{
    public function name(): string
    {
        return 'queues';
    }

    public function collect(ScrapeContext $context): iterable
    {
        return [];
    }
}
