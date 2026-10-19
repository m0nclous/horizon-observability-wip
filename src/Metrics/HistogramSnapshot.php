<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Metrics;

/**
 * Histogram buckets between the storage and the exposition.
 *
 * Storage is non-cumulative: an observation increments only the smallest bucket whose bound it does not exceed
 * ({@see self::bucket()}), keyed by the `le` spelling of {@see self::label()}. The exposition is cumulative over the
 * *configured* bounds: {@see self::cumulative()} folds every stored bucket into the smallest configured bound that is
 * not below it, or into `+Inf`, so changing `buckets` never corrupts the data already stored (a removed bound joins
 * the next one; a new bound starts empty).
 *
 * @internal
 */
final class HistogramSnapshot
{
    public const INF = '+Inf';

    /**
     * Cumulative buckets of the configured bounds.
     *
     * Stored keys that are neither a number nor `+Inf` are ignored, and so are negative counts.
     *
     * @param  array<string, int>  $stored  le => non-cumulative count
     * @param  list<float>  $bounds  the configured buckets
     * @return array<string, int> le => cumulative count, ascending, `+Inf` last (`+Inf` is the observation count)
     */
    public static function cumulative(array $stored, array $bounds): array
    {
        $bounds = self::normalize($bounds);
        $counts = array_fill(0, count($bounds) + 1, 0);

        foreach ($stored as $le => $count) {
            $value = self::parse((string) $le);

            if ($value === null || $count <= 0) {
                continue;
            }

            $counts[self::index($value, $bounds)] += $count;
        }

        $cumulative = [];
        $running = 0;

        foreach ($bounds as $index => $bound) {
            $running += $counts[$index];
            $cumulative[self::label($bound)] = $running;
        }

        $cumulative[self::INF] = $running + $counts[count($bounds)];

        return $cumulative;
    }

    /**
     * The stored bucket of an observation: the `le` of the smallest bound it does not exceed, or `+Inf`.
     *
     * @param  list<float>  $bounds
     */
    public static function bucket(float $value, array $bounds): string
    {
        $bounds = self::normalize($bounds);
        $index = self::index($value, $bounds);

        return $index < count($bounds) ? self::label($bounds[$index]) : self::INF;
    }

    /**
     * The `le` spelling of a bound: an integral bound without a decimal (`1`, `60`), any other in its shortest
     * round-trip form (`0.05`, `2.5`, `1.0E-5`), infinity as `+Inf`.
     *
     * The spelling is part of the exposed series (`le="0.05"`), so it must not depend on the `serialize_precision`
     * ini (with the old php.ini value 17, `var_export(0.05)` gives `0.050000000000000003`) nor on the locale: `%H` is
     * the locale-independent `%G`.
     */
    public static function label(float $bound): string
    {
        if (is_infinite($bound) && $bound > 0) {
            return self::INF;
        }

        if (is_finite($bound) && floor($bound) === $bound && abs($bound) < 2 ** 53) {
            return (string) (int) $bound;
        }

        for ($digits = 1; $digits < 17; $digits++) {
            $text = sprintf('%.'.$digits.'H', $bound);

            if ((float) $text === $bound) {
                return $text;
            }
        }

        return sprintf('%.17H', $bound);
    }

    /**
     * The bounds as the comma-separated `le` list `record_attempt.lua` buckets the wait with.
     *
     * @param  list<float>  $bounds
     */
    public static function labels(array $bounds): string
    {
        return implode(',', array_map(self::label(...), self::normalize($bounds)));
    }

    /**
     * Finite bounds, ascending, without duplicates.
     *
     * @param  list<float>  $bounds
     * @return list<float>
     */
    private static function normalize(array $bounds): array
    {
        $finite = array_values(array_unique(array_filter($bounds, is_finite(...)), SORT_REGULAR));
        sort($finite);

        return $finite;
    }

    /**
     * Index of the smallest bound not below the value; `count($bounds)` for `+Inf`.
     *
     * @param  list<float>  $bounds
     */
    private static function index(float $value, array $bounds): int
    {
        foreach ($bounds as $index => $bound) {
            if ($value <= $bound) {
                return $index;
            }
        }

        return count($bounds);
    }

    private static function parse(string $le): ?float
    {
        if ($le === self::INF) {
            return INF;
        }

        return is_numeric($le) && is_finite((float) $le) ? (float) $le : null;
    }
}
