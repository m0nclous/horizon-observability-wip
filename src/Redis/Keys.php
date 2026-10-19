<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Redis;

use DF\HorizonObservability\Metrics\Labels;
use InvalidArgumentException;

/**
 * Names of the package's storage keys (Arch Q6.5 and Appendix E, Addendum E').
 *
 * Every key starts with the hash tag `{horizon-observability}`, so all of them share one Redis Cluster slot, and with
 * the schema version `v1`. The storage connection adds its own prefix (e.g. `laravel_horizon:`) to `KEYS[]`; Lua
 * scripts never build key names. Hash fields join label values with {@see self::SEPARATOR}, which
 * {@see Labels::sanitize()} strips from values.
 *
 * @internal
 */
final readonly class Keys
{
    public const ROOT = '{horizon-observability}:v1:';

    /**
     * Separator of the label values in a hash field (U+001F UNIT SEPARATOR).
     */
    public const SEPARATOR = "\x1f";

    /**
     * Width of a duration extremes bucket (Addendum R3.4): `extremes:<unix div 15>:min|max`.
     */
    public const EXTREMES_BUCKET_SECONDS = 15;

    /**
     * Width of a live ring buffer bucket (Addendum R4.5): `live:ring:<unix div 5>`.
     */
    public const RING_BUCKET_SECONDS = 5;

    public function __construct(private string $root = self::ROOT) {}

    public function root(): string
    {
        return $this->root;
    }

    /**
     * Hash `conn\x1fqueue\x1fclass\x1fstatus` => attempts.
     */
    public function attempts(): string
    {
        return $this->root.'attempts';
    }

    /**
     * Hash `conn\x1fqueue\x1fclass\x1fle` => non-cumulative count.
     */
    public function durationBuckets(): string
    {
        return $this->root.'duration:buckets';
    }

    /**
     * Hash `conn\x1fqueue\x1fclass` => seconds.
     */
    public function durationSum(): string
    {
        return $this->root.'duration:sum';
    }

    /**
     * Hash `conn\x1fqueue\x1fle` => non-cumulative count.
     */
    public function waitBuckets(): string
    {
        return $this->root.'wait:buckets';
    }

    /**
     * Hash `conn\x1fqueue` => seconds.
     */
    public function waitSum(): string
    {
        return $this->root.'wait:sum';
    }

    /**
     * Hash of the creation times of the counter series (unix seconds of the storage Redis' `TIME`, {@see ServerClock}):
     * `conn\x1fqueue\x1fclass` for the attempt series of a (connection, queue, job class), `conn\x1fqueue` for the
     * wait histogram of a queue. Written by `record_attempt.lua` with the first count of a series; the attempts
     * collector exports a series as 0 for its first seconds, so Prometheus sees it start at 0 and `increase()` counts
     * its first attempts (T16). `new_series.lua` deletes a field when it releases its series.
     */
    public function created(): string
    {
        return $this->root.'created';
    }

    /**
     * Hash `conn\x1fqueue\x1fclass` => seconds, TTL 300 s.
     *
     * @param  int  $bucket  unix seconds div {@see self::EXTREMES_BUCKET_SECONDS}
     * @param  'min'|'max'  $kind
     */
    public function extremes(int $bucket, string $kind): string
    {
        if ($kind !== 'min' && $kind !== 'max') {
            throw new InvalidArgumentException(sprintf('Extremes kind must be "min" or "max", "%s" given.', $kind));
        }

        return $this->root.'extremes:'.$bucket.':'.$kind;
    }

    /**
     * Zset job class => last seen (unix seconds).
     */
    public function jobClasses(): string
    {
        return $this->root.'job_classes';
    }

    /**
     * Hash `run_id`, `reset_at`, `dropped`, `live_push_at`, `live_push_failures`.
     */
    public function meta(): string
    {
        return $this->root.'meta';
    }

    /**
     * Zset `kind\x1fconn\x1fqueue\x1fclass` => last seen (unix seconds).
     */
    public function batchLinks(): string
    {
        return $this->root.'batch_links';
    }

    /**
     * String: readiness of a delayed job on a legacy stack (unix seconds).
     */
    public function readyAt(string $uuid): string
    {
        return $this->root.'ready_at:'.$uuid;
    }

    /**
     * `SCAN MATCH` pattern of every `ready_at` key (without the connection prefix).
     */
    public function readyAtPattern(): string
    {
        return $this->root.'ready_at:*';
    }

    /**
     * Hash `conn\x1fqueue\x1fstatus` => attempts, TTL 180 s.
     *
     * @param  int  $bucket  unix seconds div {@see self::RING_BUCKET_SECONDS}
     */
    public function ring(int $bucket): string
    {
        return $this->root.'live:ring:'.$bucket;
    }

    /**
     * String: fresh snapshot JSON of a scope; presence means fresh.
     */
    public function liveSnapshot(string $hash): string
    {
        return $this->root.'live:snapshot:'.$hash;
    }

    /**
     * String: the same JSON, served while another request rebuilds.
     */
    public function liveSnapshotStale(string $hash): string
    {
        return $this->root.'live:snapshot-stale:'.$hash;
    }

    /**
     * String: build lock token of a scope.
     */
    public function liveSnapshotLock(string $hash): string
    {
        return $this->root.'live:snapshot-lock:'.$hash;
    }

    /**
     * String: `host:pid:rand` of the stream leader.
     */
    public function liveLeader(): string
    {
        return $this->root.'live:leader';
    }

    /**
     * List `"<unix_ms>:<value>"`, newest first.
     */
    public function liveDelayedWindow(): string
    {
        return $this->root.'live:window:delayed';
    }

    /**
     * `SCAN MATCH` pattern of every `live:*` key (without the connection prefix).
     */
    public function livePattern(): string
    {
        return $this->root.'live:*';
    }

    /**
     * `SCAN MATCH` pattern of every package key (without the connection prefix).
     */
    public function pattern(): string
    {
        return $this->root.'*';
    }

    /**
     * The counter and histogram keys the reset guard deletes, in `reset_guard.lua` KEYS order after `meta`.
     *
     * @return list<string>
     */
    public function counterKeys(): array
    {
        return [$this->attempts(), $this->durationBuckets(), $this->durationSum(), $this->waitBuckets(), $this->waitSum(), $this->created()];
    }

    /**
     * The fixed keys (no bucket, uuid or scope in the name). Bucketed keys expire by themselves; `flush` finds them
     * with {@see self::pattern()}.
     *
     * @return list<string>
     */
    public function all(): array
    {
        return [...$this->counterKeys(), $this->jobClasses(), $this->meta(), $this->batchLinks(), $this->liveLeader(), $this->liveDelayedWindow()];
    }

    /**
     * A hash field from label values, e.g. `field('redis', 'default', 'completed')`.
     */
    public static function field(string ...$values): string
    {
        return implode(self::SEPARATOR, $values);
    }

    /**
     * The label values of a hash field.
     *
     * @return list<string>
     */
    public static function splitField(string $field): array
    {
        return explode(self::SEPARATOR, $field);
    }
}
