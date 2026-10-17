<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Attempts;

use DF\HorizonObservability\Queues\PayloadClock;

/**
 * In-flight state of one attempt between `JobProcessing` and `JobAttempted`/`JobTimedOut` (Arch Q6.2, C20).
 *
 * {@see AttemptTracker} keeps it in a `WeakMap` keyed by the job object, which the worker passes to every event of
 * one attempt; the entry disappears with the job.
 *
 * @internal
 */
final class AttemptState
{
    /**
     * The attempt was recorded; later events of the same attempt are ignored.
     */
    public bool $finished = false;

    /**
     * Max attempts exceeded: recorded as failed without a duration sample.
     */
    public bool $noDuration = false;

    /**
     * @param  int  $startedNs  `hrtime(true)` at `JobProcessing`
     * @param  float  $startedAt  Unix seconds at `JobProcessing`
     * @param  float|null  $readyAt  payload readiness of a first attempt ({@see PayloadClock}); null otherwise
     * @param  string|null  $uuid  the payload uuid
     * @param  string|null  $legacyKey  the `ready_at:<id>` key of a first attempt on a legacy stack (keyed by the
     *                                  payload `id`, which Horizon sets to the uuid); null otherwise
     */
    public function __construct(
        public readonly int $startedNs,
        public readonly float $startedAt,
        public readonly ?float $readyAt,
        public readonly ?string $uuid,
        public readonly ?string $legacyKey,
    ) {}

    /**
     * Whether the wait of this attempt can be recorded: a first attempt with a known readiness (from the payload or
     * from the legacy `ready_at` key).
     */
    public function recordsWait(): bool
    {
        return $this->readyAt !== null || $this->legacyKey !== null;
    }

    /**
     * Seconds since `JobProcessing` on the monotonic clock.
     */
    public function elapsedSeconds(): float
    {
        return max(0.0, (hrtime(true) - $this->startedNs) / 1e9);
    }
}
