<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Queues;

use Carbon\CarbonImmutable;
use DF\HorizonObservability\Attempts\JobClassLabel;
use DF\HorizonObservability\Horizon\Topology;
use DF\HorizonObservability\Redis\Keys;
use DF\HorizonObservability\Redis\Lua;
use DF\HorizonObservability\Redis\RedisClients;
use DF\HorizonObservability\Redis\RedisScriptFailed;
use DF\HorizonObservability\Support\FailureReporter;
use Illuminate\Redis\Connections\Connection;

/**
 * Delayed jobs that are not due yet, like the Delayed tile: the sorted set `queues:<q>:delayed` of
 * each queue, whose score is the time the job becomes available. Horizon does not list them (its pending list
 * holds a delayed job only for `horizon.trim.pending` minutes); due ones are ready and count as pending.
 *
 * - The queues are resolved to their Redis connection by {@see Topology::redisConnection()} and read on the
 *   private reader of that connection; a queue whose connection does not use the `redis` driver is skipped, and
 *   one Redis key is read once even when two queue connections share it.
 * - The key is the queue key Laravel's RedisQueue uses ({@see QueueInspector::queueKey()}) plus `:delayed`, so a
 *   cluster connection with first-class cluster queues (Laravel 13) reads `queues:{<q>}:delayed`.
 * - `delayed_jobs.lua` extracts light rows in Redis, at most {@see self::CHUNK} entries and {@see self::MAX_BYTES}
 *   payload bytes per call, and at most {@see self::SCAN_LIMIT} entries in 20 calls per queue, all against the same
 *   `now`; the payloads never leave Redis.
 * - The class filter compares the normalised job name ({@see JobClassLabel}).
 * - Each queue contributes its `$limit` soonest matching jobs; the merged rows are sorted by availability (ties keep
 *   the queue order) and cut to `$limit`.
 * - A failing script skips its queue and is logged; a connection error propagates. An empty queue list or class
 *   filter returns without any Redis command.
 *
 * @phpstan-type DelayedRow array{id: string, name: string, queue: string, attempts: int, pushed_at: float|null, available_at: float}
 *
 * @internal
 */
final readonly class DelayedJobStore
{
    /**
     * Entries read per `delayed_jobs.lua` call.
     */
    public const CHUNK = 500;

    /**
     * Entries read per queue at most (20 calls).
     */
    public const SCAN_LIMIT = 10000;

    /**
     * `delayed_jobs.lua` calls per queue at most (10 000 / 500).
     */
    private const MAX_CALLS = self::SCAN_LIMIT / self::CHUNK;

    /**
     * Payload bytes one `delayed_jobs.lua` call reads at most (it stops after the entry that reaches them): Redis is
     * blocked while the script copies payloads into Lua, about 5 ms per MB, so a call stays near 40 ms however large
     * the payloads are. A queue of very large payloads therefore lists fewer than `$limit` rows (20 calls).
     */
    public const MAX_BYTES = 8 * 1024 * 1024;

    public function __construct(
        private RedisClients $clients,
        private Lua $lua,
        private Topology $topology,
        private FailureReporter $reporter,
        private QueueInspector $inspector,
    ) {}

    /**
     * Light rows of the delayed list, ascending by availability.
     *
     * @param  list<array{connection: string, queue: string}>  $queues
     * @param  list<string>|null  $jobClasses  null = all
     * @return list<DelayedRow>
     */
    public function rows(array $queues, ?array $jobClasses, int $limit, CarbonImmutable $now): array
    {
        if ($queues === [] || $jobClasses === [] || $limit < 1) {
            return [];
        }

        $classSet = $jobClasses === null ? null : array_fill_keys($jobClasses, true);
        $seconds = $now->getPreciseTimestamp(6) / 1_000_000;
        $scanned = [];
        $rows = [];

        foreach ($queues as ['connection' => $connection, 'queue' => $queue]) {
            $redisConnection = $this->topology->redisConnection($connection);

            if ($redisConnection === null) {
                continue;
            }

            $key = $this->inspector->queueKey($connection, $queue).':delayed';

            if (isset($scanned[$field = Keys::field($redisConnection, $key)])) {
                continue;
            }

            $scanned[$field] = true;

            try {
                array_push($rows, ...$this->scan($this->clients->reader($redisConnection), $key, $queue, $classSet, $limit, $seconds));
            } catch (RedisScriptFailed $e) {
                $this->reporter->report('jobs.delayed', $e, ['queue' => $queue]);
            }
        }

        usort($rows, static fn (array $a, array $b): int => $a['available_at'] <=> $b['available_at']);

        return array_slice($rows, 0, $limit);
    }

    /**
     * The `$limit` soonest matching jobs of one queue.
     *
     * @param  array<string, true>|null  $classSet
     * @return list<DelayedRow>
     *
     * @throws RedisScriptFailed
     */
    private function scan(Connection $redis, string $key, string $queue, ?array $classSet, int $limit, float $now): array
    {
        $rows = [];
        $offset = 0;

        for ($calls = 0; $calls < self::MAX_CALLS && $offset < self::SCAN_LIMIT && count($rows) < $limit; $calls++) {
            // Without a class filter every entry with a JSON payload is a row, so the first call reads no more than
            // the page needs; later calls (after entries that are not JSON) read whole chunks, so a small page
            // still costs at most MAX_CALLS calls per queue.
            $count = min($calls === 0 && $classSet === null ? $limit : self::CHUNK, self::CHUNK, self::SCAN_LIMIT - $offset);
            $reply = $this->lua->eval($redis, 'delayed_jobs', [$key], [$now, $offset, $count, self::MAX_BYTES]);
            $reply = is_array($reply) ? array_values($reply) : [];
            $read = is_numeric($reply[0] ?? null) ? (int) $reply[0] : 0;
            $more = is_numeric($reply[1] ?? null) && (int) $reply[1] === 1;
            $offset += $read;

            foreach (array_slice($reply, 2) as $entry) {
                $row = is_array($entry) ? $this->row(array_values($entry), $queue) : null;

                if ($row === null || ($classSet !== null && ! isset($classSet[JobClassLabel::normalize($row['name'])]))) {
                    continue;
                }

                $rows[] = $row;

                if (count($rows) >= $limit) {
                    break;
                }
            }

            if (! $more || $read < 1) {
                break;
            }
        }

        return $rows;
    }

    /**
     * A row from a `delayed_jobs.lua` entry `{uuid, displayName, attempts, pushedAt|false, score}`.
     *
     * @param  list<mixed>  $entry
     * @return DelayedRow|null
     */
    private function row(array $entry, string $queue): ?array
    {
        [$uuid, $name, $attempts, $pushedAt, $score] = $entry + [null, null, null, null, null];

        if (! is_numeric($score)) {
            return null;
        }

        $pushedAt = is_string($pushedAt) ? $this->decode($pushedAt) : null;

        return [
            'id' => is_string($uuid) ? $this->decode($uuid) : '',
            'name' => is_string($name) ? $this->decode($name) : '',
            'queue' => $queue,
            'attempts' => is_numeric($attempts) ? (int) $attempts : 0,
            'pushed_at' => is_numeric($pushedAt) && is_finite((float) $pushedAt) ? (float) $pushedAt : null,
            'available_at' => (float) $score,
        ];
    }

    /**
     * Raw JSON string content with its escapes decoded (the content itself when it is not valid JSON).
     */
    private function decode(string $raw): string
    {
        if (! str_contains($raw, '\\')) {
            return $raw;
        }

        $decoded = json_decode('"'.$raw.'"');

        return is_string($decoded) ? $decoded : $raw;
    }
}
