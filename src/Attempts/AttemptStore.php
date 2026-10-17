<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Attempts;

use Carbon\CarbonImmutable;

/**
 * Storage of the attempts: the `record_attempt.lua` write through the circuit breaker, and the reads the attempts
 * collector decodes (Arch Q6.2, Q6.6, Addendum R3.4, R4.5).
 *
 * Stub (T02): records nothing. Owned by T04 (WP2).
 *
 * @internal
 */
final class AttemptStore
{
    /**
     * Record one finished attempt; false when it was skipped (breaker open) or failed.
     *
     * @param  float|null  $durationSeconds  null for attempts without a duration sample
     * @param  float|null  $startedAt  Unix seconds; set for first attempts, whose wait is recorded
     * @param  float|null  $readyAt  payload readiness of a first attempt
     * @param  string|null  $uuid  payload uuid, for the legacy `ready_at` key
     */
    public function record(
        string $connection,
        string $queue,
        string $jobClass,
        AttemptStatus $status,
        ?float $durationSeconds,
        ?float $startedAt,
        ?float $readyAt,
        ?string $uuid,
        CarbonImmutable $now,
    ): bool {
        return false;
    }
}
