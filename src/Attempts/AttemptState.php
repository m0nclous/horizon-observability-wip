<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Attempts;

use DF\HorizonObservability\Queues\PayloadClock;

/**
 * In-flight state of one attempt between `JobProcessing` and `JobAttempted`/`JobTimedOut` (Arch Q6.2, C20).
 *
 * Owned by T04 (WP2).
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
     * @param  float|null  $readyAt  payload readiness of a first attempt ({@see PayloadClock})
     * @param  string|null  $uuid  the payload uuid
     * @param  string|null  $legacyKey  the `ready_at:<uuid>` key on a legacy stack
     */
    public function __construct(
        public readonly int $startedNs,
        public readonly float $startedAt,
        public readonly ?float $readyAt,
        public readonly ?string $uuid,
        public readonly ?string $legacyKey,
    ) {}
}
