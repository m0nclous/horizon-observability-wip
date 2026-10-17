<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Live\Stream;

/**
 * Redis leader lease of the stream command: `SET live:leader <token> NX PX 5000`, renewed and released with
 * `lock.lua` (Addendum R4.6).
 *
 * Stub (T02): never acquires. Owned by T12 (WP-L2).
 *
 * @internal
 */
final class LeaderLock
{
    private ?string $token = null;

    public function acquire(): bool
    {
        return false;
    }

    /**
     * Extend the lease; false means the lease was lost: stop pushing.
     */
    public function renew(): bool
    {
        return false;
    }

    public function release(): void {}

    /**
     * This process's lease token (`host:pid:rand`).
     */
    public function token(): string
    {
        return $this->token ??= gethostname().':'.getmypid().':'.bin2hex(random_bytes(4));
    }
}
