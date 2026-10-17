<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Metrics;

/**
 * Non-cumulative stored buckets to the cumulative buckets of the configured bounds (Arch Q1.2).
 *
 * Stub (T02): everything goes into `+Inf`. Owned by T04 (WP2).
 *
 * @internal
 */
final class HistogramSnapshot
{
    /**
     * @param  array<string, int>  $stored  le => non-cumulative count
     * @param  list<float>  $bounds  the configured buckets
     * @return array<string, int> le => cumulative count, ascending, `+Inf` last
     */
    public static function cumulative(array $stored, array $bounds): array
    {
        return ['+Inf' => array_sum($stored)];
    }
}
