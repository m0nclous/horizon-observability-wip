<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Metrics;

/**
 * Prometheus text format 0.0.4 renderer (Arch Q1.1).
 *
 * Stub (T02): renders nothing. Owned by T03 (WP1).
 *
 * @internal
 */
final class TextFormat
{
    /**
     * @param  iterable<MetricFamily>  $families
     * @param  string  $prefix  `''` or `"<namespace>_"`
     */
    public function render(iterable $families, string $prefix): string
    {
        return '';
    }
}
