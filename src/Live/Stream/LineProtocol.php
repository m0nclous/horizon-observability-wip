<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Live\Stream;

use DF\HorizonObservability\Live\Captions;
use DF\HorizonObservability\Live\LiveSnapshot;

/**
 * Influx line protocol of a push (Addendum R4.7; public and SemVer-covered as a wire format, the class is not).
 *
 * Two tagless measurements:
 * - `tiles` (channel `stream/<id>/tiles`): the {@see LiveSnapshot::tiles()} rows (pending, delayed, supervisors by
 *   display name, throughput), fields `tile`, `key`, `caption`, `value`, `color`, `level`; row k is stamped
 *   `T + k ms`, because Grafana 12 keeps only the last of several tagless rows sharing a timestamp.
 * - `delayed` (channel `stream/<id>/delayed`): the delayed window, one row per point older than T, then the
 *   current value at T.
 *
 * Numbers are always floats without the `i` suffix, so the field types never change between pushes; `value` is never
 * omitted (a push without any `value` makes the stat show `level`), `null` is pushed as {@see self::NULL_VALUE}.
 * String field values escape `\` and `"`; line breaks become spaces. Timestamps are Unix nanoseconds.
 *
 * @internal
 */
final class LineProtocol
{
    public const TILES = 'tiles';

    public const DELAYED = 'delayed';

    /**
     * The value pushed for `null` (counts are never negative); the stream tiles map it to an em dash.
     */
    public const NULL_VALUE = -1.0;

    /**
     * Spacing of the rows of one push.
     */
    public const ROW_SPACING_NS = 1000000;

    /**
     * Window points pushed with the current one (Addendum R4.6: 179 x 5 s plus the current value, 15 minutes).
     */
    public const WINDOW_POINTS = 179;

    /**
     * The `tiles` rows stamped `$timeNs + k ms` in R4.7 order, then the `delayed` window points older than the push
     * and the current delayed value (left out when it is null).
     *
     * @param  list<array{0: int, 1: float}>  $delayedWindow  [unix_ms, value], ascending
     */
    public static function encode(LiveSnapshot $snapshot, int $timeNs, array $delayedWindow): string
    {
        $lines = [];

        foreach ($snapshot->tiles() as $index => $row) {
            $lines[] = self::tile($row['tile'], $row['key'], $row['caption'], $row['value'], $row['color'], $row['level'], $timeNs + $index * self::ROW_SPACING_NS);
        }

        $nowMs = intdiv($timeNs, self::ROW_SPACING_NS);
        $points = [];

        foreach ($delayedWindow as [$unixMs, $value]) {
            if ($unixMs < $nowMs) {
                $points[$unixMs] = $value;
            }
        }

        ksort($points);

        foreach (array_slice($points, -self::WINDOW_POINTS, null, true) as $unixMs => $value) {
            $lines[] = self::DELAYED.' value='.self::number($value).' '.($unixMs * self::ROW_SPACING_NS);
        }

        if ($snapshot->delayed['value'] !== null) {
            $lines[] = self::DELAYED.' value='.self::number($snapshot->delayed['value']).' '.$timeNs;
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * The idempotent "unavailable" frame (Addendum R4.7, R4.10): the given tile rows, or only the pending row,
     * with caption `Live unavailable`, value -1, colour `text` and level 0, stamped `$timeNs + k ms`. The tiles then
     * read "Live unavailable \u{2014}".
     *
     * @param  list<array{tile: string, key: string}>  $rows  the rows of the last successful push, or [] for pending only
     */
    public static function unavailable(int $timeNs, array $rows): string
    {
        $rows = $rows === [] ? [['tile' => 'pending', 'key' => '']] : $rows;
        $lines = [];

        foreach (array_values($rows) as $index => $row) {
            $lines[] = self::tile($row['tile'], $row['key'], Captions::UNAVAILABLE, null, Captions::TEXT, 0, $timeNs + $index * self::ROW_SPACING_NS);
        }

        return implode("\n", $lines)."\n";
    }

    /**
     * A field value number: always a float (`7.0`, `0.667`), `null` and non-finite values as {@see self::NULL_VALUE}.
     */
    public static function number(int|float|null $value): string
    {
        if ($value === null || ! is_finite((float) $value)) {
            $value = self::NULL_VALUE;
        }

        $value = (float) $value;

        if (floor($value) === $value && abs($value) < 1e15) {
            return sprintf('%.1F', $value);
        }

        return var_export($value, true);
    }

    /**
     * A string field value: quoted, `\` and `"` escaped, line breaks as spaces.
     */
    public static function string(string $value): string
    {
        return '"'.addcslashes(strtr($value, ["\r\n" => ' ', "\r" => ' ', "\n" => ' ']), '\\"').'"';
    }

    private static function tile(string $tile, string $key, string $caption, ?float $value, string $color, int $level, int $timeNs): string
    {
        return sprintf(
            '%s tile=%s,key=%s,caption=%s,value=%s,color=%s,level=%s %d',
            self::TILES,
            self::string($tile),
            self::string($key),
            self::string($caption),
            self::number($value),
            self::string($color),
            self::number($level),
            $timeNs,
        );
    }
}
