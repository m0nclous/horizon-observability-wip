<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Live;

use Carbon\CarbonImmutable;
use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Redis\Keys;
use DF\HorizonObservability\Redis\Lua;
use DF\HorizonObservability\Redis\RedisClients;
use DF\HorizonObservability\Redis\RedisScriptFailed;
use Illuminate\Container\Container;
use UnexpectedValueException;

/**
 * The attempt ring buffer (Addendum R4.5): `live:ring:<unix div 5>` hashes of `conn\x1fqueue\x1fstatus` => attempts,
 * written inside `record_attempt.lua` and read over the 12 complete buckets before the current one (60 s).
 *
 * - `key()` and `ttl()` are the writer's contract (T04, `AttemptStore::record()`), frozen.
 * - {@see self::window()} reads the window with one `ring_read.lua` `EVAL` (12 `HGETALL` inside) on the
 *   short-timeout reader of the storage connection (`redis.scrape_timeout`), like every live-path read of package
 *   keys. The window `[5(c - 12), 5c)` with `c = now div 5` is exactly 60 s and lags by at most 5 s. Workers stamp
 *   the bucket with their own clock and the reader with its own, so clock skew moves attempts between neighbouring
 *   buckets but never counts one twice.
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

    /**
     * @param  RedisClients|null  $clients  needed by {@see self::window()} only; the container's when null
     * @param  Lua|null  $lua  needed by {@see self::window()} only; the container's when null
     */
    public function __construct(
        private Keys $keys,
        private Config $config,
        private ?RedisClients $clients = null,
        private ?Lua $lua = null,
    ) {}

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
     * The ring keys of the window, oldest first: the {@see self::WINDOW_BUCKETS} complete buckets before the bucket of
     * `$now`.
     *
     * @return list<string>
     */
    public function windowKeys(CarbonImmutable $now): array
    {
        $current = intdiv($now->getTimestamp(), Keys::RING_BUCKET_SECONDS);

        return array_map($this->keys->ring(...), range($current - self::WINDOW_BUCKETS, $current - 1));
    }

    /**
     * Attempts per queue and status over the 60 s window ending at the start of the current bucket, sorted by
     * queue and status. Fields that are not `conn\x1fqueue\x1fstatus` are skipped.
     *
     * @return array<string, array<string, int>> "connection\x1fqueue" => status => count
     *
     * @throws RedisScriptFailed
     * @throws UnexpectedValueException when the script reply is malformed
     */
    public function window(CarbonImmutable $now): array
    {
        $clients = $this->clients ?? Container::getInstance()->make(RedisClients::class);
        $lua = $this->lua ?? Container::getInstance()->make(Lua::class);

        $reply = $lua->eval($clients->reader($clients->storageConnection()), 'ring_read', $this->windowKeys($now), []);

        if (! is_array($reply) || count($reply) % 2 !== 0) {
            throw new UnexpectedValueException(sprintf('ring_read returned %s instead of field and count pairs.', get_debug_type($reply)));
        }

        $window = [];

        foreach (array_chunk(array_values($reply), 2) as [$field, $count]) {
            $parts = is_string($field) ? Keys::splitField($field) : [];

            if (count($parts) !== 3 || ! is_numeric($count)) {
                continue;
            }

            [$connection, $queue, $status] = $parts;
            $queueField = Keys::field($connection, $queue);
            $window[$queueField][$status] = ($window[$queueField][$status] ?? 0) + (int) $count;
        }

        ksort($window, SORT_STRING);

        return array_map(static function (array $statuses): array {
            ksort($statuses, SORT_STRING);

            return $statuses;
        }, $window);
    }
}
