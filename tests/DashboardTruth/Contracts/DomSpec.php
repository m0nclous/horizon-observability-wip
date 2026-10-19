<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\DashboardTruth\Contracts;

use Closure;

/**
 * The DOM a user sees of one panel (design section 10): for a stat tile its title and, per value, the caption and
 * the value text exactly as formatted. The expectation is the spec's value through the panel's declared unit and
 * decimals, computed from the case (and, for the tile number, checked against the frame it is formatted from).
 */
final readonly class DomSpec
{
    /**
     * @param  list<Mode>  $modes
     * @param  Closure(CaseContext): list<string>  $expect  the expected text lines of the panel: title, then caption
     *                                                      and value per value
     * @param  string|null  $sumOf  for a tile whose number is the sum of a range target's points: that refId; the
     *                              number shown must then be the formatted sum of the captured points as well
     * @param  (Closure(float): string)|null  $format  the panel's unit and decimals, for that check
     */
    public function __construct(
        public int $panel,
        public array $modes,
        public Closure $expect,
        public ?string $sumOf = null,
        public ?Closure $format = null,
    ) {}
}
