<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\DashboardTruth\Expect\Core;

/**
 * Which stored samples a reader sees (design section 7.2), restated from Prometheus' documented semantics on the
 * samples of one series (times in milliseconds, a null value is a staleness marker):
 *
 * - instant at t: the newest sample in (t - 5 m, t], unless a staleness marker follows it;
 * - range [w] at t: the samples in (t - w, t] (Prometheus 3's left-open window), without staleness markers;
 * - the last sample at or before t within a lookback w (`last_over_time`'s window).
 *
 * WP5 adds subqueries, `@`/`offset` and the replica dedupe, each proven against promtool.
 */
final class Visibility
{
    public const LOOKBACK_DELTA_MS = 300_000;

    /**
     * The value an instant read at `$time` sees, or null.
     *
     * @param  array{t: list<int>, v: list<float|null>}  $series
     */
    public static function instant(array $series, int $time): ?float
    {
        $index = self::lastAtOrBefore($series['t'], $time);

        if ($index === null || $series['t'][$index] <= $time - self::LOOKBACK_DELTA_MS) {
            return null;
        }

        return $series['v'][$index];
    }

    /**
     * The samples in (t - w, t], staleness markers excluded: list of [time, value].
     *
     * @param  array{t: list<int>, v: list<float|null>}  $series
     * @return list<array{0: int, 1: float}>
     */
    public static function range(array $series, int $time, int $windowMs): array
    {
        $samples = [];

        foreach ($series['t'] as $index => $sampleTime) {
            if ($sampleTime > $time - $windowMs && $sampleTime <= $time && $series['v'][$index] !== null) {
                $samples[] = [$sampleTime, $series['v'][$index]];
            }
        }

        return $samples;
    }

    /**
     * The last sample (not a staleness marker) in (t - w, t], or null: [time, value].
     *
     * @param  array{t: list<int>, v: list<float|null>}  $series
     * @return array{0: int, 1: float}|null
     */
    public static function lastWithin(array $series, int $time, int $windowMs): ?array
    {
        $samples = self::range($series, $time, $windowMs);

        return $samples === [] ? null : $samples[count($samples) - 1];
    }

    /**
     * The index of the last time at or before `$time` in an ascending list, or null.
     *
     * @param  list<int>  $times
     */
    private static function lastAtOrBefore(array $times, int $time): ?int
    {
        $low = 0;
        $high = count($times) - 1;
        $found = null;

        while ($low <= $high) {
            $middle = intdiv($low + $high, 2);

            if ($times[$middle] <= $time) {
                $found = $middle;
                $low = $middle + 1;
            } else {
                $high = $middle - 1;
            }
        }

        return $found;
    }
}
