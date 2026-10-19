<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\DashboardTruth\Expect\Core;

/**
 * Grafana's display formatting of the units and decimals the panels declare (design section 7.2), restated: WP1 has
 * the `locale` unit (en-US thousands separators) and PromQL's documented `round()` (ties up). WP5 adds durations,
 * percentages and the captions.
 */
final class Format
{
    /**
     * The `locale` unit in en-US with `$decimals` decimals: `1,234`.
     */
    public static function locale(float $value, int $decimals = 0): string
    {
        return number_format($value, $decimals, '.', ',');
    }

    /**
     * PromQL `round()` to an integer: ties are rounded up (to +Inf).
     */
    public static function round(float $value): float
    {
        return floor($value + 0.5);
    }
}
