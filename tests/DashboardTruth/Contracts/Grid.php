<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\DashboardTruth\Contracts;

/**
 * The grid a range target was evaluated on: its point times and step as Grafana's backend chose them (read from the
 * frame and `meta.executedQueryString`, never computed, design section 5.3), and where it came from: the captured
 * page load or one of the browserless replays at S and at the next coarser Grafana step (design section 5.6).
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
