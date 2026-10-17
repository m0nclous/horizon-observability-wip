<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Live;

/**
 * Caption, level and colour rules of the Overview tiles (Addendum R4.2 and R4.4), shared by the live snapshot and the
 * Grafana renderer so both modes read the same. Levels are 0 (green), 1 (yellow) and 2 (red); tiles without a
 * colour rule use `text`. Fixtures: `tests/Fixtures/live/captions.php`.
 *
 * @internal
 */
final class Captions
{
    /**
     * Pending: the oldest wait above this multiple of the queue's `horizon.waits` limit is level 1 (Horizon's
     * `LongWaitDetected`), above {@see self::WAIT_CRIT} level 2 (the long-wait alert).
     */
    public const WAIT_WARN = 1.0;

    public const WAIT_CRIT = 2.0;

    /**
     * Supervisor utilisation in percent (rounded) from which a supervisor is level 1, and level 2.
     */
    public const UTIL_WARN = 70;

    public const UTIL_CRIT = 90;

    /**
     * Ages below this are shown in seconds, from it in rounded minutes.
     */
    public const SECONDS_TEXT_LIMIT = 120;

    public const GREEN = 'green';

    public const YELLOW = 'yellow';

    public const RED = 'red';

    /**
     * Colour of tiles without a colour rule (Grafana's text colour).
     */
    public const TEXT = 'text';

    /**
     * Caption of every live tile while the snapshot cannot be built (Addendum R4.10).
     */
    public const UNAVAILABLE = 'Live unavailable';

    /**
     * U+00B7 MIDDLE DOT, the caption separator.
     */
    public const DOT = "\u{00B7}";

    /**
     * U+2014 EM DASH, "no value".
     */
    public const DASH = "\u{2014}";

    /**
     * Pending caption: `Oldest: 17 s \u{00B7} default`, `Oldest: 3 min \u{00B7} slow` (from 120 s, rounded
     * minutes), or `Oldest: \u{2014}`
     * when nothing waits (age 0 or unknown) or no queue is named.
     */
    public static function oldest(?int $ageSeconds, ?string $queue): string
    {
        if ($ageSeconds === null || $ageSeconds <= 0 || $queue === null || $queue === '') {
            return 'Oldest: '.self::DASH;
        }

        if ($ageSeconds < self::SECONDS_TEXT_LIMIT) {
            return sprintf('Oldest: %d s %s %s', $ageSeconds, self::DOT, $queue);
        }

        return sprintf('Oldest: %d min %s %s', (int) round($ageSeconds / 60), self::DOT, $queue);
    }

    /**
     * Supervisor caption from its display name: `default \u{00B7} max 3`.
     */
    public static function supervisor(string $name, int $maxProcesses): string
    {
        return sprintf('%s %s max %d', $name, self::DOT, $maxProcesses);
    }

    /**
     * Display name of a supervisor: without the `supervisor-` prefix when something follows it, else unchanged
     * (the dashboard's `label_replace(..., "supervisor-(.+)")`).
     */
    public static function display(string $supervisor): string
    {
        return preg_match('/^supervisor-(.+)$/s', $supervisor, $match) === 1 ? $match[1] : $supervisor;
    }

    /**
     * Completed caption: `RPM: 42`, or `RPM: \u{2014}` without data.
     */
    public static function rpm(?int $perMinute): string
    {
        return 'RPM: '.($perMinute === null ? self::DASH : (string) $perMinute);
    }

    /**
     * Horizon caption: `Running` (1), `Paused` (0) or `Stopped` (-1 and anything else).
     */
    public static function horizon(int $status): string
    {
        return match ($status) {
            1 => 'Running',
            0 => 'Paused',
            default => 'Stopped',
        };
    }

    /**
     * Horizon level: 0 while running, 2 otherwise.
     */
    public static function horizonLevel(int $status): int
    {
        return $status === 1 ? 0 : 2;
    }

    /**
     * Wait level from age / threshold: `(ratio > 1) + (ratio > 2)`; 0 without a ratio.
     */
    public static function waitLevel(?float $ratio): int
    {
        if ($ratio === null || is_nan($ratio)) {
            return 0;
        }

        return (int) ($ratio > self::WAIT_WARN) + (int) ($ratio > self::WAIT_CRIT);
    }

    /**
     * Utilisation zone of a supervisor (`busy / max`, 0-1): `(round(100u) >= 70) + (round(100u) >= 90)`; always 2
     * while Horizon is not running.
     */
    public static function utilizationLevel(float $utilization, bool $horizonRunning): int
    {
        if (! $horizonRunning) {
            return 2;
        }

        $percent = is_nan($utilization) ? 0 : (int) round(100 * $utilization);

        return (int) ($percent >= self::UTIL_WARN) + (int) ($percent >= self::UTIL_CRIT);
    }

    /**
     * Named Grafana colour of a level: green (0 and below), yellow (1), red (2 and above).
     */
    public static function color(int $level): string
    {
        return match (true) {
            $level <= 0 => self::GREEN,
            $level === 1 => self::YELLOW,
            default => self::RED,
        };
    }
}
