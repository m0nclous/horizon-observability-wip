<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\DashboardTruth\Contracts;

/**
 * The grid a range target is evaluated on (design section 5.3): the step Grafana's backend chose (`Step:` of
 * `meta.executedQueryString`) and every point time of the aligned range query, from the step multiple in
 * (from - step, from] to the one in (to - step, to], at the phase of the returned points
 * (`Compare\Observation::grid()`). It is never the set of points the dashboard happened to return, so a point the
 * dashboard drops is a difference, and a gauge's "last grid point" is the one at the end of the range. Also where it
 * came from: the captured page load or one of the browserless replays at S and at the next coarser Grafana step
 * (design section 5.6).
 */
final readonly class Grid
{
    public const CAPTURED = 'grid';

    /**
     * @param  list<int>  $times  point times, epoch milliseconds
     * @param  string  $source  {@see self::CAPTURED} or `replay <step>`
     */
    public function __construct(
        public array $times,
        public int $stepMs,
        public string $source = self::CAPTURED,
    ) {}

    public function step(): string
    {
        return Durations::format($this->stepMs);
    }
}
