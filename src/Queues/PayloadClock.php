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
     * Whether the payload is a first attempt: `attempts` is absent, not a number, not finite or truncates to 0.
     * Horizon retries and `queue:retry` reset it to 0; a released job carries its attempts. `first_attempt()` in
     * `queue_state.lua` applies the same rule (tests/Contract/ReadinessParityTest).
     *
     * @param  array<array-key, mixed>  $payload
     */
    public static function isFirstAttempt(array $payload): bool
    {
        $attempts = $payload['attempts'] ?? null;

        if (! is_numeric($attempts)) {
            return true;
        }

        // Compared as a float, never cast: (int) of a float past the integer range wraps on PHP 8.2-8.4 (2^64 is 0)
        // and warns on PHP 8.5. A non-finite value (1e999 decodes to INF) is what (int) truncates to 0 on every
        // version, as in the Lua twin; a finite |attempts| >= 1 is a retry, however large.
        $value = (float) $attempts;

        return ! is_finite($value) || abs($value) < 1;
    }
}
