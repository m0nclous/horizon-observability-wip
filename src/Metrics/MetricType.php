<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Metrics;

/**
 * Prometheus metric type of a family; the value is the `# TYPE` keyword.
 *
 * @internal
 */
enum MetricType: string
{
    case Counter = 'counter';
    case Gauge = 'gauge';
    case Histogram = 'histogram';
}
