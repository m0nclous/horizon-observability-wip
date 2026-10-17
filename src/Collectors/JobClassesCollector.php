<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Collectors;

use DF\HorizonObservability\Metrics\ScrapeContext;

/**
 * Collector `job_classes`: queue_job_classes.
 *
 * Stub (T02): collects nothing. Owned by T08 (WP6).
 *
 * @internal
 */
final class JobClassesCollector implements Collector
{
    public function name(): string
    {
        return 'job_classes';
    }

    public function collect(ScrapeContext $context): iterable
    {
        return [];
    }
}
