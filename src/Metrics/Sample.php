<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Metrics;

/**
 * One exposition line of a family: suffix (`''`, or `_bucket`, `_sum`, `_count` for histograms), labels, value.
 *
 * @internal
 */
final readonly class Sample
{
    /**
     * @param  array<string, string>  $labels  sanitised values in the family's label order; `le` last on buckets
     */
    public function __construct(public string $suffix, public array $labels, public int|float $value) {}
}
