<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Attempts;

use Carbon\CarbonImmutable;
use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Live\AttemptRing;
use DF\HorizonObservability\Metrics\HistogramSnapshot;
use DF\HorizonObservability\Metrics\Labels;
use DF\HorizonObservability\Redis\CircuitBreaker;
use DF\HorizonObservability\Redis\Keys;
use DF\HorizonObservability\Redis\Lua;
use DF\HorizonObservability\Redis\RedisClients;
use DF\HorizonObservability\Support\FailureReporter;
use Illuminate\Contracts\Container\Container;
use Illuminate\Redis\Connections\Connection;

/**
 * Storage of the attempts (Arch Q6.2, Q6.6, Appendix D and E; Addendum R3.4, R4.5).
 *
 * Writes: {@see self::record()} runs exactly one `record_attempt.lua` `EVAL` per attempt on the private storage
 * client (`redis.worker_timeout`, never the app's or the job's connection object, C20b) through the per-process
 * circuit breaker (C11), so an unreachable storage costs at most one timeout per cooldown and nothing reaches the
 * worker. The `EVAL` goes through Laravel's normalised `Connection::eval()` on phpredis and predis alike (C2).
 *
 * Reads (for the attempts collector): the counter hashes, the job-class set, the merged extremes of the last 150
 * seconds (`extremes_read.lua`) and `meta`, all on a reader client of the storage connection with
 * `redis.scrape_timeout`, without the breaker: read failures fail the collector visibly.
 *
 * A container singleton whose constructor touches neither Redis nor {@see Config}: the config snapshot is resolved
 * per call.
 *
 * @internal
 */
final readonly class AttemptStore
{
    /**
     * TTL of an extremes bucket (Addendum R3.4): longer than the 150 s read window.
     */
    public const EXTREMES_TTL_SECONDS = 300;

    /**
     * Extremes buckets merged on read: the current one and the 9 previous ones (150 s).
     */
    public const EXTREMES_READ_BUCKETS = 10;

    /**
     * Job classes idle for longer than this leave the class set when it is full and a new class needs the room
     * (Arch Q4: 30 days); their series stop.
     */
    public const CLASS_IDLE_SECONDS = 2592000;

    public function __construct(
        private Container $container,
        private RedisClients $clients,
        private Lua $lua,
        private Keys $keys,
        private CircuitBreaker $breaker,
        private FailureReporter $reporter,
    ) {}

    /**
     * Record one finished attempt; false when it was skipped (breaker open) or failed. Never throws.
     *
     * @param  string  $jobClass  the normalised `job_class` label ({@see JobClassLabel})
     * @param  float|null  $durationSeconds  null for attempts without a duration sample
     * @param  float|null  $startedAt  Unix seconds; set for first attempts, whose wait is recorded
     * @param  float|null  $readyAt  payload readiness of a first attempt
     * @param  string|null  $uuid  payload id of a first attempt on a legacy stack (Horizon: the uuid): the EVAL reads
     *                             and deletes its `ready_at` key; null on a modern stack
     * @param  CarbonImmutable  $now  the attempt end; it picks the extremes and ring buckets and the class scores
     */
    public function record(
        string $connection,
        string $queue,
        string $jobClass,
        AttemptStatus $status,
        ?float $durationSeconds,
        ?float $startedAt,
        ?float $readyAt,
        ?string $uuid,
        CarbonImmutable $now,
    ): bool {
        return $this->breaker->attempt('attempts', function () use ($connection, $queue, $jobClass, $status, $durationSeconds, $startedAt, $readyAt, $uuid, $now): void {
            $config = $this->container->make(Config::class);
            $ring = new AttemptRing($this->keys, $config);
            $unix = $now->getTimestamp();
            $bucket = intdiv($unix, Keys::EXTREMES_BUCKET_SECONDS);
            $class = $this->label($jobClass);
            $duration = $durationSeconds !== null && is_finite($durationSeconds) ? max(0.0, $durationSeconds) : null;
            $waits = $startedAt !== null && is_finite($startedAt);

            $keys = [
                $this->keys->attempts(),
                $this->keys->durationBuckets(),
                $this->keys->durationSum(),
                $this->keys->waitBuckets(),
                $this->keys->waitSum(),
                $this->keys->extremes($bucket, 'min'),
                $this->keys->extremes($bucket, 'max'),
                $this->keys->jobClasses(),
                $this->keys->meta(),
                $ring->key($unix),
            ];

            if ($uuid !== null && $uuid !== '' && $waits) {
                $keys[] = $this->keys->readyAt($uuid);
            }

            $dropped = $this->breaker->takeDropped();

            $effective = $this->lua->eval($this->clients->storage(), 'record_attempt', $keys, [
                $this->label($connection),
                $this->label($queue),
                $class,
                $status->value,
                $duration ?? '',
                $duration === null ? '' : HistogramSnapshot::bucket($duration, $config->buckets),
                $waits ? $startedAt : '',
                $waits && $readyAt !== null && is_finite($readyAt) ? $readyAt : '',
                HistogramSnapshot::labels($config->buckets),
                self::EXTREMES_TTL_SECONDS,
                $config->maxJobClasses,
                self::CLASS_IDLE_SECONDS,
                $ring->ttl(),
                $now->getPreciseTimestamp(3) / 1000,
                $dropped,
                Labels::OTHER,
            ]);

            if ($effective === Labels::OTHER && $class !== Labels::OTHER) {
                $this->reporter->warnOnce('attempts.job_classes', sprintf(
                    'more than %d job classes (max_job_classes); new classes are recorded as %s.',
                    $config->maxJobClasses,
                    Labels::OTHER,
                ), ['job_class' => $class]);
            }
        });
    }

    /**
     * The storage connection as a short-timeout reader (`redis.scrape_timeout`), for the scrape path.
     */
    public function reader(): Connection
    {
        return $this->clients->reader($this->clients->storageConnection());
    }

    /**
     * The counter and histogram hashes, field => raw value.
     *
     * @return array{attempts: array<string, string>, durationBuckets: array<string, string>, durationSum: array<string, string>, waitBuckets: array<string, string>, waitSum: array<string, string>}
     */
    public function counters(): array
    {
        $redis = $this->reader();

        return [
            'attempts' => $this->hash($redis->hgetall($this->keys->attempts())),
            'durationBuckets' => $this->hash($redis->hgetall($this->keys->durationBuckets())),
            'durationSum' => $this->hash($redis->hgetall($this->keys->durationSum())),
            'waitBuckets' => $this->hash($redis->hgetall($this->keys->waitBuckets())),
            'waitSum' => $this->hash($redis->hgetall($this->keys->waitSum())),
        ];
    }

    /**
     * The admitted job classes.
     *
     * Read only: classes idle for {@see self::CLASS_IDLE_SECONDS} leave the set inside `record_attempt.lua`, and only
     * when the set is full and a new class needs the room (Arch Q4 guard 1). Pruning idle classes on every scrape
     * instead would delete the counters of a job that runs less often than every 30 days (a monthly job), so each
     * run would start a new series at 1 that `increase()` and the dashboard never count.
     *
     * @return list<string>
     */
    public function jobClasses(): array
    {
        $members = $this->reader()->zrange($this->keys->jobClasses(), 0, -1);

        return array_values(array_map(strval(...), array_filter(is_array($members) ? $members : [], is_scalar(...))));
    }

    /**
     * Duration extremes of the 15-second bucket of `$now` and the 9 before it, merged per field (min of the minima,
     * max of the maxima): every attempt stays visible for more than 135 s (Addendum R3.4).
     *
     * @return array{min: array<string, string>, max: array<string, string>} field => seconds
     */
    public function extremes(CarbonImmutable $now): array
    {
        $current = intdiv($now->getTimestamp(), Keys::EXTREMES_BUCKET_SECONDS);
        $buckets = range($current, $current - self::EXTREMES_READ_BUCKETS + 1);
        $keys = [
            ...array_map(fn (int $bucket): string => $this->keys->extremes($bucket, 'min'), $buckets),
            ...array_map(fn (int $bucket): string => $this->keys->extremes($bucket, 'max'), $buckets),
        ];

        $reply = $this->lua->eval($this->reader(), 'extremes_read', $keys, []);
        $result = ['min' => [], 'max' => []];
        $part = 'min';
        $items = is_array($reply) ? array_values($reply) : [];

        for ($index = 0, $count = count($items); $index < $count; $index++) {
            $item = $items[$index];

            if ($item === "\x1e") {
                $part = 'max';

                continue;
            }

            $value = $items[++$index] ?? null;

            if (is_scalar($item) && is_scalar($value)) {
                $result[$part][(string) $item] = (string) $value;
            }
        }

        return $result;
    }

    /**
     * The `meta` hash: `run_id`, `reset_at`, `dropped`, `live_push_at`, `live_push_failures`.
     *
     * @return array<string, string>
     */
    public function meta(): array
    {
        return $this->hash($this->reader()->hgetall($this->keys->meta()));
    }

    /**
     * Delete stale hash fields; the number of fields deleted.
     *
     * @param  array<string, list<string>>  $fields  key => fields
     */
    public function forget(array $fields): int
    {
        $redis = $this->reader();
        $deleted = 0;

        foreach ($fields as $key => $names) {
            foreach (array_chunk($names, 500) as $chunk) {
                $deleted += (int) $redis->hdel($key, ...$chunk);
            }
        }

        return $deleted;
    }

    /**
     * A label value as stored: sanitised, never empty.
     */
    private function label(string $value): string
    {
        $value = Labels::sanitize($value);

        return $value === '' ? Labels::UNKNOWN : $value;
    }

    /**
     * @return array<string, string>
     */
    private function hash(mixed $reply): array
    {
        $hash = [];

        foreach (is_array($reply) ? $reply : [] as $field => $value) {
            if (is_scalar($value)) {
                $hash[(string) $field] = (string) $value;
            }
        }

        return $hash;
    }
}
