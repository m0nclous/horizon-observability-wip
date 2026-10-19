<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\DashboardTruth\Contracts;

/**
 * The expected frame of one target on one grid: series by label set, each with its points (time => value). An
 * instant target has one point per series, at `to`. The comparator matches series by their exact label sets and
 * compares every point exactly (floats of the same arithmetic to 1e-9 relative, design section 8).
 */
final readonly class Expectation
{
    /**
     * @param  list<array{labels: array<string, string>, points: array<int, float>}>  $series
     * @param  string  $summary  a short human statement of the expected value for the report
     * @param  float|null  $total  for a tile whose number is the sum of its points: the period count C(from, to),
     *                             which the points must add up to on every grid (design section 7.2, a gate of its own)
     */
    public function __construct(
        public array $series,
        public string $summary = '',
        public ?float $total = null,
    ) {}

    /**
     * Non-trivial (coverage gate, design section 11): at least one point that is not 0.
     */
    public function nonTrivial(): bool
    {
        foreach ($this->series as $one) {
            foreach ($one['points'] as $value) {
                if ($value !== 0.0) {
                    return true;
                }
            }
        }

        return false;
    }
}
