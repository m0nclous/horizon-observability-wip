<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Collectors;

use DF\HorizonObservability\Metrics\ScrapeContext;

/**
 * Collector `batches`: queue_batch*.
 *
 * Stub (T02): collects nothing. Owned by T07 (WP5).
 *
 * @internal
 */
final class BatchesCollector implements Collector
{
    public function name(): string
    {
        return 'batches';
    }

    public function collect(ScrapeContext $context): iterable
    {
        return [];
    }
}
