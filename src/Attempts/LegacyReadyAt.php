<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Attempts;

use Illuminate\Queue\Events\JobQueued;

/**
 * `JobQueued` listener of legacy stacks (no payload `delay`): stores the readiness of delayed Redis jobs for the
 * record `EVAL` (Arch Q6.8, C18, A01). Registered only when `Versions::payloadCarriesDelay()` is false.
 *
 * Stub (T02): no-op. Owned by T04 (WP2).
 *
 * @internal
 */
final class LegacyReadyAt
{
    public function onQueued(JobQueued $event): void {}
}
