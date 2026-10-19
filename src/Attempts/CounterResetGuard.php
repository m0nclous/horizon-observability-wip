<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Attempts;

use Carbon\CarbonImmutable;
use DF\HorizonObservability\Redis\Keys;
use DF\HorizonObservability\Redis\Lua;
use DF\HorizonObservability\Support\FailureReporter;

/**
 * `reset_guard.lua` before the counters are read: a changed Redis `run_id` deletes the counters.
 *
 * A restart without persistence, a restore from an older RDB or a failover to a lagging replica rolls the counters
 * back; `increase()` would then count the old totals again. Deleting them is a plain counter reset, which `rate()` and
 * `increase()` handle; only absolute values are lost. A missing `meta` (first scrape) only stores the `run_id`, and an
 * `INFO` that fails is a no-op. One info line per reset.
 *
 * @internal
 */
final readonly class CounterResetGuard
{
    public function __construct(
        private AttemptStore $store,
        private Lua $lua,
        private Keys $keys,
        private FailureReporter $reporter,
    ) {}

    /**
     * Whether the counters were reset by this call. Redis errors are thrown.
     */
    public function check(CarbonImmutable $now): bool
    {
        $result = $this->lua->eval(
            $this->store->reader(),
            'reset_guard',
            [$this->keys->meta(), ...$this->keys->counterKeys()],
            [$now->getPreciseTimestamp(3) / 1000],
        );

        if (! is_numeric($result) || (int) $result !== 1) {
            return false;
        }

        $this->reporter->info('the Redis run_id changed (restart or restore); the attempt counters were reset.');

        return true;
    }
}
