<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Collectors;

use DF\HorizonObservability\Metrics\ScrapeContext;

/**
 * Collector `horizon`: horizon_status, horizon_supervisor_*, horizon_wait_threshold_seconds, horizon_info.
 *
 * Stub (T02): collects nothing. Owned by T05 (WP3).
 *
 * @internal
 */
final class HorizonCollector implements Collector
{
    public function name(): string
    {
        return 'horizon';
    }

    public function collect(ScrapeContext $context): iterable
    {
        return [];
    }
}
