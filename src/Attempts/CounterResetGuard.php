<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Attempts;

use Carbon\CarbonImmutable;

/**
 * `reset_guard.lua` before the counters are read: a changed Redis `run_id` deletes the counters (Arch Q6.10).
 *
 * Stub (T02): never resets. Owned by T04 (WP2).
 *
 * @internal
 */
final class CounterResetGuard
{
    /**
     * Whether the counters were reset by this call.
     */
    public function check(CarbonImmutable $now): bool
    {
        return false;
    }
}
