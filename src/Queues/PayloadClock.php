<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Queues;

/**
 * Readiness of a queued job from its payload (Arch Q6.3, C8, C19): the PHP twin of the formula in `queue_state.lua`.
 * `tests/Fixtures/payloads.php` holds the shared parity cases.
 *
 * @internal
 */
final class PayloadClock
{
    /**
     * When the job became available, Unix seconds: `max(pushedAt, createdAt + delay)`.
     *
     * - `pushedAt`: Horizon's JSON string with microseconds (numbers are accepted too);
     * - `createdAt` and `delay`: integers, present from Laravel 12.11 (`delay` also needs Horizon 5.49); other types
     *   and `delay: null` count as absent;
     * - without `pushedAt`, `createdAt` is the base; without both, the readiness is unknown (null).
     *
     * A Horizon retry or `queue:retry` keeps the old `createdAt` and `delay` but gets a fresh `pushedAt`, so the
     * stale delay is ignored (C19).
     *
     * @param  array<array-key, mixed>  $payload
     */
    public static function readyAt(array $payload): ?float
    {
        $pushed = is_numeric($payload['pushedAt'] ?? null) ? (float) $payload['pushedAt'] : null;
        $created = is_int($payload['createdAt'] ?? null) ? $payload['createdAt'] : null;
        $delay = is_int($payload['delay'] ?? null) ? $payload['delay'] : null;
        $base = $pushed ?? ($created !== null ? (float) $created : null);

        if ($base === null) {
            return null;
        }

        return $created !== null && $delay !== null ? max($base, (float) ($created + $delay)) : $base;
    }

    /**
     * Whether the payload is a first attempt: `attempts` is 0, absent or not a number. Horizon retries and
     * `queue:retry` reset it to 0; a released job carries its attempts.
     *
     * @param  array<array-key, mixed>  $payload
     */
    public static function isFirstAttempt(array $payload): bool
    {
        $attempts = $payload['attempts'] ?? null;

        // |attempts| < 1 is what (int) truncates to 0, without casting a float past the integer range (a wrapped
        // value on PHP 8.2-8.4, a warning on PHP 8.5).
        return ! is_numeric($attempts) || abs((float) $attempts) < 1;
    }
}
