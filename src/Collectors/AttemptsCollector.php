<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Collectors;

use DF\HorizonObservability\Metrics\ScrapeContext;

/**
 * Collector `attempts`: queue_jobs_total, the duration and wait histograms, the duration extremes, exporter_attempts_dropped_total and exporter_live_push_*.
 *
 * Stub (T02): collects nothing. Owned by T04 (WP2).
 *
 * @internal
 */
final class AttemptsCollector implements Collector
{
    public function name(): string
    {
        return 'attempts';
    }

    public function collect(ScrapeContext $context): iterable
    {
        return [];
    }
}
