<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Live\Stream;

use DF\HorizonObservability\Live\LiveSnapshot;

/**
 * Influx line protocol of a push (Addendum R4.7): the tagless `tiles` rows stamped 1 ms apart and the `delayed`
 * window; `null` is encoded as `-1.0`.
 *
 * Stub (T02): encodes nothing. Owned by T12 (WP-L2).
 *
 * @internal
 */
final class LineProtocol
{
    /**
     * @param  list<array{0: int, 1: float}>  $delayedWindow  [unix_ms, value], ascending
     */
    public static function encode(LiveSnapshot $snapshot, int $timeNs, array $delayedWindow): string
    {
        return '';
    }

    /**
     * @param  list<array{tile: string, key: string}>  $rows  the rows of the last successful push, or [] for pending only
     */
    public static function unavailable(int $timeNs, array $rows): string
    {
        return '';
    }
}
