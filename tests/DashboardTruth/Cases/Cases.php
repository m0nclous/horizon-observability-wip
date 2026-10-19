<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\DashboardTruth\Cases;

use DF\HorizonObservability\Tests\DashboardTruth\Contracts\CaseDef;
use DF\HorizonObservability\Tests\DashboardTruth\Contracts\Category;
use DF\HorizonObservability\Tests\DashboardTruth\Contracts\Mode;
use InvalidArgumentException;

/**
 * The case catalogue (design section 9): one case = one page load. WP1 has the slice case S01 and the negative
 * control S02 (a `var-queue` value that is not an option must fail as `case setup`, never as a finding); WP8 adds
 * the PR set C01-C30, the weekly additions and the coverage check of the PR set (`coverage()`).
 */
final class Cases
{
    /**
     * @return array<string, CaseDef>
     */
    public static function all(): array
    {
        $cases = [
            new CaseDef('S01', 'slice', Mode::Off, 'now-1h', 'now', purpose: 'WP1 thin slice: #8 Completed on the off render, 1 h, All, UTC, 1920'),
            new CaseDef('S02', 'slice', Mode::Off, 'now-1h', 'now', vars: ['queue' => ['no-such-queue']], purpose: 'negative control: a URL value that is not an option fails as case setup', expect: Category::CaseSetup),
        ];

        return array_combine(array_map(static fn (CaseDef $case): string => $case->id, $cases), $cases);
    }

    /**
     * The cases of a set (`pr` or `full`), optionally only the listed ids.
     *
     * @param  list<string>  $only
     * @return array<string, CaseDef>
     */
    public static function select(string $set, array $only = []): array
    {
        $cases = array_filter(self::all(), static fn (CaseDef $case): bool => $set === 'full' || $case->set === 'pr');

        if ($only !== []) {
            $unknown = array_diff($only, array_keys(self::all()));

            if ($unknown !== []) {
                throw new InvalidArgumentException('Unknown cases: '.implode(', ', $unknown));
            }

            $cases = array_intersect_key(self::all(), array_flip($only));
        }

        return $cases;
    }

    public static function get(string $id): CaseDef
    {
        return self::all()[$id] ?? throw new InvalidArgumentException(sprintf('Unknown case "%s".', $id));
    }
}
