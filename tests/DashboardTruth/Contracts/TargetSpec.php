<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\DashboardTruth\Contracts;

use Closure;

/**
 * The specification of one target (panel, refId) of the rendered dashboard (design sections 7 and 11): what it
 * means, restated from the docblocks, ARCHITECTURE and the ADDENDUM (never from the dashboard's PromQL), in which
 * renders and at which levels it is asserted, and the expectation as a function of the case and the grid.
 *
 * A range target is compared on Grafana's captured grid and on both replays (S and the next coarser step); an instant
 * target on its one point at `to`. The consumed fields are the frame fields the panel JSON reads (legendFormat labels,
 * configFromData field names, ...), which the static gate requires the spec to assert.
 */
final readonly class TargetSpec
{
    /**
     * @param  list<Mode>  $modes
     * @param  list<Level>  $levels
     * @param  Closure(CaseContext, Grid): Expectation  $expect
     * @param  list<string>  $consumed
     */
    public function __construct(
        public int $panel,
        public string $refId,
        public string $meaning,
        public array $modes,
        public array $levels,
        public bool $range,
        public Closure $expect,
        public string $tolerance = 'exact',
        public array $consumed = [],
    ) {}

    public function key(): string
    {
        return $this->panel.' '.$this->refId;
    }

    public function covers(Mode $mode): bool
    {
        return in_array($mode, $this->modes, true);
    }
}
