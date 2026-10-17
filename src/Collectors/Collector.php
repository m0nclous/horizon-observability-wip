<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Collectors;

use DF\HorizonObservability\Metrics\MetricFamily;
use DF\HorizonObservability\Metrics\ScrapeContext;

/**
 * One isolated source of metric families (Arch Q1.3). The exporter materialises `collect()` fully; a throw fails the
 * whole collector, and {@see ScrapeContext::reportFailure()} keeps the data read so far but marks it failed.
 *
 * @internal
 */
interface Collector
{
    /**
     * Collector id: horizon, queues, attempts, job_classes or batches (the `collector` label of the self-metrics).
     */
    public function name(): string;

    /**
     * @return iterable<MetricFamily>
     */
    public function collect(ScrapeContext $context): iterable;
}
