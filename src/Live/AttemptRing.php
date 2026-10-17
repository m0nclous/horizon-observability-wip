<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Live;

use Carbon\CarbonImmutable;
use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Redis\Keys;

/**
 * The attempt ring buffer (Addendum R4.5): `live:ring:<unix div 5>` hashes of `conn\x1fqueue\x1fstatus` => attempts,
 * written inside `record_attempt.lua` and read over the 12 complete buckets before the current one (60 s).
 *
 * `key()` and `ttl()` are the writer's contract (T04) and are final; `window()` is a stub (T02) owned by T12 (WP-L1).
 *
 * @internal
 */
final readonly class AttemptRing
{
    /**
     * TTL of a ring bucket while live is enabled: about 36 buckets (3 minutes) stay readable.
     */
    public const TTL_SECONDS = 180;

    /**
     * Complete buckets of the read window: 12 x 5 s = 60 s.
     */
    public const WINDOW_BUCKETS = 12;

    public function __construct(private Keys $keys, private Config $config) {}

    /**
     * The ring key of the bucket a Unix time falls into.
     */
    public function key(int $unixSeconds): string
    {
        return $this->keys->ring(intdiv($unixSeconds, Keys::RING_BUCKET_SECONDS));
    }

    /**
     * The `ring_ttl` argument of `record_attempt.lua`: 180, or 0 (no ring write) when live is disabled.
     */
    public function ttl(): int
    {
        return $this->config->liveEnabled ? self::TTL_SECONDS : 0;
    }

    /**
     * Attempts per queue and status over the 60 s window ending at the start of the current bucket.
     *
     * @return array<string, array<string, int>> "connection\x1fqueue" => status => count
     */
    public function window(CarbonImmutable $now): array
    {
        return [];
    }
}
