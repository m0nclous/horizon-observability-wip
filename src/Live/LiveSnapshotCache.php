<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Live;

use Closure;
use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Redis\Keys;
use DF\HorizonObservability\Redis\Lua;
use DF\HorizonObservability\Redis\RedisClients;
use DF\HorizonObservability\Support\FailureReporter;
use Illuminate\Redis\Connections\Connection;
use JsonException;
use Throwable;
use UnexpectedValueException;

/**
 * Stale-while-revalidate snapshot cache on the package storage, safe against stampedes: at most one
 * build per `cache_ttl` per scope across every PHP worker and replica.
 *
 * Freshness is decided by Redis key expiry, never by comparing `generated_at` with the local clock, so replicas whose
 * clocks differ share the same copy:
 *
 * ```
 * fresh = live:snapshot:<hash>          PX ttl_ms                    (ttl_ms = live.cache_ttl x 1000)
 * stale = live:snapshot-stale:<hash>    PX max(2000, 10 x ttl_ms)
 * lock  = live:snapshot-lock:<hash>     SET NX PX 2000, released with lock.lua (compare-and-delete)
 * ```
 *
 * 1. `MGET fresh stale` (one round trip on a hit); a fresh copy is returned.
 * 2. Otherwise the request that takes the lock builds, stores both copies and releases the lock.
 * 3. The others return the stale copy, or, without one, poll the fresh key every 50 ms for up to 500 ms.
 * 4. When the builder died or is slow, the snapshot is built without caching (counted in {@see self::stats()}).
 *
 * {@see self::refresh()} (the stream leader, once per tick) skips step 1 when it gets the lock, so every push carries a
 * new build and keeps the full-scope copy fresh for poll requests: 1 build per stream interval for that scope.
 *
 * A build that has no live data ({@see LiveSnapshot::isAvailable()}) stores nothing, so the next request after the
 * lock is released tries again; a snapshot with partial errors is stored like any other. `age_ms` is local now minus
 * `generated_at` (informational, clamped at 0). `cache_ttl = 0` disables the cache.
 *
 * All commands run on the short-timeout reader of the storage connection (`redis.scrape_timeout`), like every
 * live-path read of package keys. When the cache's Redis fails, the answer is {@see LiveSnapshot::unavailable()}
 * with the error: the stale copy lives in the same Redis, and building per request without the lock would multiply
 * the failing Redis' timeouts by viewers and panels.
 *
 * @internal
 */
final class LiveSnapshotCache
{
    /**
     * Lifetime of the build lock.
     */
    public const LOCK_TTL_MS = 2000;

    /**
     * The stale copy lives at least this long, and at least 10 x the TTL.
     */
    public const MIN_STALE_TTL_MS = 2000;

    public const STALE_FACTOR = 10;

    /**
     * Followers without a stale copy wait for the builder this long, polling the fresh key.
     */
    public const FOLLOWER_WAIT_MS = 500;

    public const FOLLOWER_POLL_MS = 50;

    /**
     * @var array{hits: int, stale: int, waited: int, builds: int, uncached: int, failures: int}
     */
    private array $stats = ['hits' => 0, 'stale' => 0, 'waited' => 0, 'builds' => 0, 'uncached' => 0, 'failures' => 0];

    /**
     * @var Closure(int): void
     */
    private readonly Closure $sleep;

    /**
     * @param  (Closure(int): void)|null  $sleep  sleeps the given milliseconds, for tests; default `usleep()`
     */
    public function __construct(
        private readonly LiveSnapshotBuilder $builder,
        private readonly Config $config,
        private readonly RedisClients $clients,
        private readonly Lua $lua,
        private readonly Keys $keys,
        private readonly FailureReporter $reporter,
        ?Closure $sleep = null,
    ) {
        $this->sleep = $sleep ?? static function (int $milliseconds): void {
            usleep($milliseconds * 1000);
        };
    }

    public function get(LiveScope $scope): LiveSnapshot
    {
        return $this->fetch($scope, false);
    }

    /**
     * Build a new snapshot and store it, whatever the age of the fresh copy (the stream leader, every tick: each
     * push carries a new build and poll requests for the full scope find a fresh copy). When another request holds
     * the build lock it answers like {@see self::get()}.
     */
    public function refresh(LiveScope $scope): LiveSnapshot
    {
        return $this->fetch($scope, true);
    }

    private function fetch(LiveScope $scope, bool $refresh): LiveSnapshot
    {
        $ttlMs = $this->ttlMs();

        if ($ttlMs <= 0) {
            $this->stats['builds']++;

            return $this->build($scope);
        }

        $hash = $scope->cacheKey();
        $fresh = $this->keys->liveSnapshot($hash);
        $stale = $this->keys->liveSnapshotStale($hash);
        $lock = $this->keys->liveSnapshotLock($hash);

        try {
            $redis = $this->clients->reader($this->clients->storageConnection());

            if ($refresh && LiveRedis::setIfAbsent($redis, $lock, $token = bin2hex(random_bytes(16)), self::LOCK_TTL_MS)) {
                return $this->buildAndStore($redis, $scope, $fresh, $stale, $lock, $token, $ttlMs);
            }

            $reply = LiveRedis::command($redis, 'mget', [[$fresh, $stale]]);
            [$freshJson, $staleJson] = is_array($reply) ? [...array_values($reply), null, null] : [null, null];

            if (($snapshot = $this->decode($freshJson)) instanceof LiveSnapshot) {
                $this->stats['hits']++;

                return $this->aged($snapshot);
            }

            $token = bin2hex(random_bytes(16));

            if (LiveRedis::setIfAbsent($redis, $lock, $token, self::LOCK_TTL_MS)) {
                return $this->buildAndStore($redis, $scope, $fresh, $stale, $lock, $token, $ttlMs);
            }

            if (($snapshot = $this->decode($staleJson)) instanceof LiveSnapshot) {
                $this->stats['stale']++;

                return $this->aged($snapshot);
            }

            for ($waited = 0; $waited < self::FOLLOWER_WAIT_MS; $waited += self::FOLLOWER_POLL_MS) {
                ($this->sleep)(self::FOLLOWER_POLL_MS);

                if (($snapshot = $this->decode(LiveRedis::command($redis, 'get', [$fresh]))) instanceof LiveSnapshot) {
                    $this->stats['waited']++;

                    return $this->aged($snapshot);
                }
            }
        } catch (Throwable $e) {
            $this->stats['failures']++;
            $this->reporter->reportLocally('live.cache', $e);

            return LiveSnapshot::unavailable($scope, $this->nowMs(), $ttlMs, [['source' => 'cache', 'message' => $this->message($e)]]);
        }

        $this->stats['uncached']++;

        return $this->build($scope);
    }

    /**
     * Counters of this instance: fresh hits, stale answers, answers after waiting for the builder, builds (cached or
     * not), uncached builds (the holder of the lock died or was slow) and cache failures.
     *
     * @return array{hits: int, stale: int, waited: int, builds: int, uncached: int, failures: int}
     */
    public function stats(): array
    {
        return $this->stats;
    }

    /**
     * The TTL of the fresh copy in milliseconds (0 = no cache).
     */
    public function ttlMs(): int
    {
        return (int) round($this->config->liveCacheTtl * 1000);
    }

    /**
     * The TTL of the stale copy in milliseconds.
     */
    public function staleTtlMs(): int
    {
        return max(self::MIN_STALE_TTL_MS, self::STALE_FACTOR * $this->ttlMs());
    }

    private function buildAndStore(Connection $redis, LiveScope $scope, string $fresh, string $stale, string $lock, string $token, int $ttlMs): LiveSnapshot
    {
        try {
            $this->stats['builds']++;
            $snapshot = $this->build($scope);

            if ($snapshot->isAvailable()) {
                try {
                    $json = $snapshot->toJson();
                    LiveRedis::setWithTtl($redis, $fresh, $json, $ttlMs);
                    LiveRedis::setWithTtl($redis, $stale, $json, $this->staleTtlMs());
                } catch (Throwable $e) {
                    $this->stats['failures']++;
                    $this->reporter->reportLocally('live.cache', $e);
                }
            }

            return $this->aged($snapshot);
        } finally {
            try {
                LiveRedis::release($this->lua, $redis, $lock, $token);
            } catch (Throwable $e) {
                // The lock expires by itself within 2 s.
                $this->reporter->reportLocally('live.cache', $e);
            }
        }
    }

    private function build(LiveScope $scope): LiveSnapshot
    {
        // Laravel's clock (Date), like the exporter's ScrapeContext, so Date::use(), travelTo() and Carbon::setTestNow()
        // move both; on Carbon 2, CarbonImmutable::now() has a test clock of its own.
        $now = now()->toImmutable();

        try {
            return $this->builder->build($scope, $now);
        } catch (Throwable $e) {
            // The builder isolates its sources; this is a bug, still answered as "Live unavailable".
            $this->reporter->reportLocally('live.build', $e);

            return LiveSnapshot::unavailable($scope, $this->nowMs(), $this->ttlMs(), [['source' => 'build', 'message' => $this->message($e)]]);
        }
    }

    private function decode(mixed $json): ?LiveSnapshot
    {
        if (! is_string($json) || $json === '') {
            return null;
        }

        try {
            $data = json_decode($json, true, 64, JSON_THROW_ON_ERROR);

            return is_array($data) ? LiveSnapshot::fromArray($data) : null;
        } catch (JsonException|UnexpectedValueException) {
            return null; // a foreign or corrupt value counts as a miss
        }
    }

    private function aged(LiveSnapshot $snapshot): LiveSnapshot
    {
        return $snapshot->withAge($this->nowMs() - $snapshot->generatedAtMs());
    }

    private function nowMs(): int
    {
        return (int) floor((float) now()->format('U.u') * 1000);
    }

    private function message(Throwable $e): string
    {
        return LiveSnapshotBuilder::errorMessage($e);
    }
}
