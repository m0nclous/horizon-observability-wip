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
 * Delayed jobs that are not due yet (Arch Q2.1, C13), like the Delayed tile: the sorted set `queues:<q>:delayed` of
 * each queue, whose score is the time the job becomes available. Horizon does not list them (its pending list
 * holds a delayed job only for `horizon.trim.pending` minutes); due ones are ready and count as pending.
 *
 * - The queues are resolved to their Redis connection by {@see Topology::redisConnection()} and read on the
 *   private reader of that connection; a queue whose connection does not use the `redis` driver is skipped, and
 *   one Redis key is read once even when two queue connections share it.
 * - `delayed_jobs.lua` extracts light rows in Redis, {@see self::CHUNK} entries at a time and at most
 *   {@see self::SCAN_LIMIT} per queue, all against the same `now`; the payloads never leave Redis.
 * - The class filter compares the normalised job name ({@see JobClassLabel}).
 * - Each queue contributes its `$limit` soonest matching jobs; the merged rows are sorted by availability (ties keep
 *   the queue order) and cut to `$limit`.
 * - A failing script skips its queue and is logged; a connection error propagates. An empty queue list or class
 *   filter returns without any Redis command (C13).
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

    public function __construct(
        private RedisClients $clients,
        private Lua $lua,
        private Topology $topology,
        private FailureReporter $reporter,
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

            if ($redisConnection === null || isset($scanned[$key = Keys::field($redisConnection, $queue)])) {
                continue;
            }

            $scanned[$key] = true;

            try {
                array_push($rows, ...$this->scan($this->clients->reader($redisConnection), $queue, $classSet, $limit, $seconds));
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
    private function scan(Connection $redis, string $queue, ?array $classSet, int $limit, float $now): array
    {
        $rows = [];
        $offset = 0;

        while ($offset < self::SCAN_LIMIT && count($rows) < $limit) {
            // Without a class filter every entry with a JSON payload is a row, so read no more than still needed.
            $count = min($classSet === null ? $limit - count($rows) : self::CHUNK, self::CHUNK, self::SCAN_LIMIT - $offset);
            $reply = $this->lua->eval($redis, 'delayed_jobs', ['queues:'.$queue.':delayed'], [$now, $offset, $count]);
            $offset += $count;
            $reply = is_array($reply) ? array_values($reply) : [];
            $read = $reply[0] ?? 0;

            foreach (array_slice($reply, 1) as $entry) {
                $row = is_array($entry) ? $this->row(array_values($entry), $queue) : null;

                if ($row === null || ($classSet !== null && ! isset($classSet[JobClassLabel::normalize($row['name'])]))) {
                    continue;
                }

                $rows[] = $row;

                if (count($rows) >= $limit) {
                    break;
                }
            }

            if (! is_numeric($read) || (int) $read < $count) {
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
            'pushed_at' => is_numeric($pushedAt) ? (float) $pushedAt : null,
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
