<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\DashboardTruth\Expect;

use DF\HorizonObservability\Tests\DashboardTruth\Contracts\Mode;
use DF\HorizonObservability\Tests\DashboardTruth\Contracts\PanelSpec;
use DF\HorizonObservability\Tests\DashboardTruth\Contracts\TargetSpec;
use DF\HorizonObservability\Tests\DashboardTruth\Expect\Panels\Completed;

/**
 * The registry of panel specs (design section 13): every (panel, refId) of every render must have a spec that a PR
 * case exercises, or be listed in {@see self::PENDING} while the fan-out (WP2-WP8) is under way. The static gate
 * (CoverageTest) fails on a target that is neither, on a pending entry that no render has or that is covered
 * everywhere, and on a spec that names a target no render has. PENDING must shrink to empty by WP8, before T17 moves
 * to testing.
 */
final class Specs
{
    /**
     * Targets not specified yet, as "<panel> <refId>": pending in every render that has them. #8 B stays pending for
     * its poll and stream forms (their JSON differs from the off form that S01 covers).
     */
    public const PENDING = [
        '1 A', '1 B', '2 A', '2 B', '2 C', '2 L', '3 A', '3 B', '3 S', '4 A', '4 B', '4 C', '5 A', '6 A', '6 B',
        '6 E', '6 F', '6 G', '6 I', '6 J', '6 Z', '7 A', '7 B', '7 E', '8 B', '8 C', '9 A', '9 L', '14 A', '14 B',
        '16 A', '16 B', '16 C', '16 D', '16 E', '16 F', '16 Z', '17 A', '17 B', '17 C', '17 D', '17 E', '17 F',
        '17 G', '17 H', '17 Z', '18 A', '19 A', '20 A', '21 A', '21 B', '21 C', '22 A', '23 A', '24 A', '24 B',
        '24 C', '24 D', '24 F', '24 G', '24 K', '24 M', '24 Z',
    ];

    /**
     * @return list<PanelSpec>
     */
    public static function panels(): array
    {
        return [new Completed];
    }

    /**
     * Every target spec.
     *
     * @return list<TargetSpec>
     */
    public static function targets(): array
    {
        $targets = [];

        foreach (self::panels() as $panel) {
            array_push($targets, ...$panel->targets());
        }

        return $targets;
    }

    /**
     * The target specs of a render mode.
     *
     * @return list<TargetSpec>
     */
    public static function forMode(Mode $mode): array
    {
        return array_values(array_filter(self::targets(), static fn (TargetSpec $target): bool => $target->covers($mode)));
    }

    public static function target(int $panel, string $refId, Mode $mode): ?TargetSpec
    {
        foreach (self::forMode($mode) as $target) {
            if ($target->panel === $panel && $target->refId === $refId) {
                return $target;
            }
        }

        return null;
    }
}
