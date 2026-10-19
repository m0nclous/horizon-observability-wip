<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\DashboardTruth\Record;

use Carbon\CarbonImmutable;

/**
 * The clocks behind horizon-clock-shim.php (design section 4): Horizon and `Illuminate\Bus` read the real time
 * through unqualified `microtime()` and `time()` calls, which the recorder redirects into the virtual clock of the
 * scenario (Carbon's test now) with namespaced functions. Without a test now every clock passes through to the real
 * one.
 *
 * - {@see self::frozen()}: the virtual time itself (`Laravel\Horizon\microtime`: `JobPayload::prepare()`'s
 *   `pushedAt` is the virtual dispatch time; Horizon's own `Stopwatch` reads 0, which the package never uses);
 * - {@see self::drifting()}: the virtual time plus the real time since Carbon's test now last changed
 *   (`Laravel\Horizon\Repositories\microtime`: the reserved, completed and failed stamps and index scores of
 *   `RedisJobRepository` are virtual, while job runtimes stay real, so the job-list runtime column is not 0);
 * - {@see self::seconds()}: `floor` of the virtual time (`Illuminate\Bus\time`: the `created_at`, `finished_at` and
 *   `cancelled_at` of `DatabaseBatchRepository`).
 */
final class HorizonClock
{
    /**
     * The virtual time last seen and the real time at which it was first seen.
     */
    private static ?float $seen = null;

    private static float $seenAt = 0.0;

    /**
     * How many times each clock was read since the last {@see self::reset()}: the recorder asserts that the shims
     * are in effect.
     *
     * @var array{frozen: int, drifting: int, seconds: int}
     */
    private static array $reads = ['frozen' => 0, 'drifting' => 0, 'seconds' => 0];

    /**
     * The virtual time in epoch seconds, or null without a test now.
     */
    public static function virtual(): ?float
    {
        $now = CarbonImmutable::getTestNow();

        return $now === null ? null : (float) $now->format('U.u');
    }

    public static function frozen(bool $asFloat): string|float
    {
        $virtual = self::virtual();

        if ($virtual === null) {
            return \microtime($asFloat);
        }

        self::$reads['frozen']++;

        return self::format($virtual, $asFloat);
    }

    public static function drifting(bool $asFloat): string|float
    {
        $virtual = self::virtual();

        if ($virtual === null) {
            return \microtime($asFloat);
        }

        $real = \microtime(true);

        if (self::$seen !== $virtual) {
            self::$seen = $virtual;
            self::$seenAt = $real;
        }

        self::$reads['drifting']++;

        return self::format($virtual + ($real - self::$seenAt), $asFloat);
    }

    public static function seconds(): int
    {
        $virtual = self::virtual();

        if ($virtual === null) {
            return \time();
        }

        self::$reads['seconds']++;

        return (int) floor($virtual);
    }

    /**
     * Tell the drifting clock that the virtual time moved now (the recorder calls it after every advance), so the
     * real drift counts from the move and not from the first read after it.
     */
    public static function moved(): void
    {
        self::$seen = self::virtual();
        self::$seenAt = \microtime(true);
    }

    /**
     * @return array{frozen: int, drifting: int, seconds: int}
     */
    public static function reads(): array
    {
        return self::$reads;
    }

    public static function reset(): void
    {
        self::$seen = null;
        self::$seenAt = 0.0;
        self::$reads = ['frozen' => 0, 'drifting' => 0, 'seconds' => 0];
    }

    /**
     * Whether the namespaced shims are defined in this process (RecordTest loads them at file load, only with
     * DASHBOARD_TRUTH_RECORD=1).
     */
    public static function shimmed(): bool
    {
        return function_exists('Laravel\Horizon\microtime')
            && function_exists('Laravel\Horizon\Repositories\microtime')
            && function_exists('Illuminate\Bus\time');
    }

    private static function format(float $seconds, bool $asFloat): string|float
    {
        if ($asFloat) {
            return $seconds;
        }

        $whole = floor($seconds);

        // microtime(false): "msec sec"
        return sprintf('%.8F %d', $seconds - $whole, (int) $whole);
    }
}
