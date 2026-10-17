<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Live\Stream;

use Closure;

/**
 * The stream loop (Addendum R4.6): leader election, snapshot, delayed window, encode, push, backoff and `meta`
 * counters.
 *
 * Stub (T02): does not run. Owned by T12 (WP-L2).
 *
 * @internal
 */
final class LiveStreamer
{
    /**
     * @param  Closure(): bool  $shouldStop  checked between ticks (signals)
     * @return int the command's exit code
     */
    public function run(Closure $shouldStop, int $maxSeconds, int $memoryMb, bool $once): int
    {
        return 1;
    }
}
