<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Horizon;

use DF\HorizonObservability\Attempts\JobClassLabel;
use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Config\ExceptionMode;
use DF\HorizonObservability\Http\ExceptionSummary;
use DF\HorizonObservability\Http\JobList;
use DF\HorizonObservability\Http\JobListQuery;
use DF\HorizonObservability\Queues\DelayedJobStore;
use DF\HorizonObservability\Redis\Lua;
use DF\HorizonObservability\Redis\RedisClients;
use DF\HorizonObservability\Redis\RedisScriptFailed;
use DF\HorizonObservability\Support\FailureReporter;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PhpRedisClusterConnection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Predis\Pipeline\Pipeline;
use Redis;
use RedisCluster;

/**
 * Pending, completed and failed rows from Horizon's Redis: the jobs of Horizon's Pending Jobs, Completed Jobs and
 * Failed Jobs pages, read on the private reader of Horizon's `horizon` connection.
 *
 * - The ids come from Horizon's sorted sets `pending_jobs`, `completed_jobs` and `failed_jobs`, whose score is minus
 *   the time the job was pushed, completed or failed, so the newest come first and the period `[from, to]` is a
 *   score range. They are read in chunks of {@see self::CHUNK}, at most {@see self::SCAN_LIMIT} ids in at most 20
 *   calls per request: with a filter, a matching job older than that is not listed.
 * - Each id is read with one `HMGET` of light fields, never the payload, pipelined per chunk (a transaction on a
 *   phpredis cluster, as Horizon does). A hash that has expired while its id is still in the set is skipped, like
 *   a hash without its list's time field.
 * - The queue filter compares Horizon's `queue` field, the class filter the normalised job name
 *   ({@see JobClassLabel}, as in the metric labels and the batch links). Pending jobs include reserved ones, as in
 *   Horizon.
 * - For failed jobs, the attempts (from the payload) and the first exception line come from `job_details.lua`, one
 *   call per {@see self::DETAILS_CHUNK} rows of the final page only; with `exceptions = none` the exception is not
 *   read at all. A failing script leaves its rows without details (0 attempts, no exception) and is logged; a
 *   connection error propagates.
 * - An empty queue or class filter returns without any Redis command.
 *
 * @phpstan-type JobStoreRow array{id: string, name: string, queue: string, created_at: float|null, reserved_at: float|null, completed_at: float|null, failed_at: float|null, attempts: int, exception: string|null}
 *
 * @internal
 */
final readonly class JobStore
{
    /**
     * Horizon's Redis connection (`Horizon::use()` defines it, with Horizon's prefix).
     */
    public const CONNECTION = 'horizon';

    /**
     * Ids read per `ZRANGEBYSCORE`.
     */
    public const CHUNK = 500;

    /**
     * Ids read per request at most (20 chunks).
     */
    public const SCAN_LIMIT = 10000;

    /**
     * `ZRANGEBYSCORE` calls per request at most (10 000 / 500).
     */
    private const MAX_CALLS = self::SCAN_LIMIT / self::CHUNK;

    /**
     * Job hashes per `job_details.lua` call.
     */
    public const DETAILS_CHUNK = 100;

    /**
     * list => [sorted set, time field that must be present, light fields].
     */
    private const LISTS = [
        'pending' => ['pending_jobs', 'created_at', ['id', 'name', 'queue', 'created_at']],
        'completed' => ['completed_jobs', 'completed_at', ['id', 'name', 'queue', 'created_at', 'reserved_at', 'completed_at']],
        'failed' => ['failed_jobs', 'failed_at', ['id', 'name', 'queue', 'reserved_at', 'failed_at']],
    ];

    public function __construct(
        private RedisClients $clients,
        private Lua $lua,
        private Config $config,
        private FailureReporter $reporter,
    ) {}

    /**
     * Raw rows of a pending, completed or failed list, newest first, at most `$query->limit`; delayed jobs are
     * not Horizon's ({@see DelayedJobStore}), so that list has no rows here.
     *
     * @param  list<string>|null  $queues  resolved queue filter; null = all
     * @param  list<string>|null  $jobClasses  resolved class filter; null = all
     * @return list<JobStoreRow>
     */
    public function rows(JobListQuery $query, ?array $queues, ?array $jobClasses): array
    {
        $list = self::LISTS[$query->list->value] ?? null;

        if ($list === null || $queues === [] || $jobClasses === [] || $query->limit < 1) {
            return [];
        }

        [$set, $timeField, $fields] = $list;
        $queueSet = $queues === null ? null : array_fill_keys($queues, true);
        $classSet = $jobClasses === null ? null : array_fill_keys($jobClasses, true);
        $min = $query->toMs === null ? '-inf' : $this->score($query->toMs);
        $max = $query->fromMs === null ? '+inf' : $this->score($query->fromMs);
        $redis = $this->clients->reader(self::CONNECTION);
        $rows = [];
        $offset = 0;

        for ($calls = 0; $calls < self::MAX_CALLS && $offset < self::SCAN_LIMIT && count($rows) < $query->limit; $calls++) {
            // Without a filter every live hash is a row, so the first read takes no more ids than the page needs.
            // Later reads (after expired hashes) take whole chunks: a small page over a set of expired ids (Horizon
            // stopped, so nothing trims its sets) still costs at most MAX_CALLS round trips, not one per id.
            $count = min($calls === 0 && $queueSet === null && $classSet === null ? $query->limit : self::CHUNK, self::CHUNK, self::SCAN_LIMIT - $offset);
            $ids = $this->ids($redis, $set, $min, $max, $offset, $count);
            $offset += $count;

            foreach ($this->hashes($redis, $ids, $fields) as $values) {
                $row = $this->row($values, $timeField);

                if ($row === null
                    || ($queueSet !== null && ! isset($queueSet[$row['queue']]))
                    || ($classSet !== null && ! isset($classSet[JobClassLabel::normalize($row['name'])]))) {
                    continue;
                }

                $rows[] = $row;

                if (count($rows) >= $query->limit) {
                    break;
                }
            }

            if (count($ids) < $count) {
                break;
            }
        }

        return $query->list === JobList::Failed ? $this->withDetails($redis, $rows) : $rows;
    }

    /**
     * The score bound of a time in Unix milliseconds: minus the time in seconds, exact to the millisecond.
     */
    private function score(int $milliseconds): string
    {
        return sprintf('-%d.%03d', intdiv($milliseconds, 1000), $milliseconds % 1000);
    }

    /**
     * @return list<string>
     */
    private function ids(Connection $redis, string $set, string $min, string $max, int $offset, int $count): array
    {
        $ids = $redis->zrangebyscore($set, $min, $max, ['limit' => ['offset' => $offset, 'count' => $count]]);

        return is_array($ids) ? array_values(array_filter($ids, is_string(...))) : [];
    }

    /**
     * The light fields of each id, in order: field => string value, or null for a missing field (phpredis answers a
     * pipelined `HMGET` with field => value or false, predis with a list of values or null).
     *
     * @param  list<string>  $ids
     * @param  list<string>  $fields
     * @return list<array<string, string|null>>
     */
    private function hashes(Connection $redis, array $ids, array $fields): array
    {
        if ($ids === []) {
            return [];
        }

        $callback = static function (Redis|RedisCluster|Pipeline $pipe) use ($ids, $fields): void {
            foreach ($ids as $id) {
                $pipe->hmget($id, $fields);
            }
        };

        $replies = match (true) {
            $redis instanceof PhpRedisClusterConnection => $redis->transaction($callback),
            $redis instanceof PhpRedisConnection => $redis->pipeline($callback),
            default => $redis->command('pipeline', [$callback]),
        };
        $hashes = [];

        foreach (is_array($replies) ? array_values($replies) : [] as $reply) {
            $values = is_array($reply) ? array_values($reply) : [];
            $hash = [];

            foreach ($fields as $position => $field) {
                $value = $values[$position] ?? null;
                $hash[$field] = is_string($value) || is_int($value) || is_float($value) ? (string) $value : null;
            }

            $hashes[] = $hash;
        }

        return $hashes;
    }

    /**
     * A row from the light fields, or null for an expired hash (no id) or a hash without the list's time field.
     *
     * @param  array<string, string|null>  $values
     * @return JobStoreRow|null
     */
    private function row(array $values, string $timeField): ?array
    {
        $id = $values['id'] ?? null;

        if ($id === null || $id === '' || $this->time($values[$timeField] ?? null) === null) {
            return null;
        }

        return [
            'id' => $id,
            'name' => $values['name'] ?? '',
            'queue' => $values['queue'] ?? '',
            'created_at' => $this->time($values['created_at'] ?? null),
            'reserved_at' => $this->time($values['reserved_at'] ?? null),
            'completed_at' => $this->time($values['completed_at'] ?? null),
            'failed_at' => $this->time($values['failed_at'] ?? null),
            'attempts' => 0,
            'exception' => null,
        ];
    }

    /**
     * Horizon's `microtime(true)` string as Unix seconds; null when it is missing, empty, not positive or not finite
     * (a corrupt `1e999` would make the runtime INF, which JSON cannot encode).
     */
    private function time(?string $value): ?float
    {
        return is_numeric($value) && is_finite((float) $value) && (float) $value > 0 ? (float) $value : null;
    }

    /**
     * The attempts and the first exception line of failed rows (`job_details.lua`).
     *
     * @param  list<JobStoreRow>  $rows
     * @return list<JobStoreRow>
     */
    private function withDetails(Connection $redis, array $rows): array
    {
        $bytes = $this->config->exceptions === ExceptionMode::None ? 0 : ExceptionSummary::MAX_BYTES;
        $result = [];

        foreach (array_chunk($rows, self::DETAILS_CHUNK) as $chunk) {
            $details = $this->details($redis, array_column($chunk, 'id'), $bytes);

            foreach ($chunk as $position => $row) {
                [$row['attempts'], $row['exception']] = $details[$position] ?? [0, null];
                $result[] = $row;
            }
        }

        return $result;
    }

    /**
     * `job_details.lua` for one chunk: [attempts, first exception line] per id; [] when the script fails.
     *
     * @param  list<string>  $ids
     * @return list<array{0: int, 1: string|null}>
     */
    private function details(Connection $redis, array $ids, int $bytes): array
    {
        try {
            $reply = $this->lua->eval($redis, 'job_details', $ids, [$bytes]);
        } catch (RedisScriptFailed $e) {
            $this->reporter->report('jobs.details', $e);

            return [];
        }

        $details = [];

        foreach (is_array($reply) ? array_values($reply) : [] as $detail) {
            [$attempts, $exception] = (is_array($detail) ? array_values($detail) : []) + [null, null];
            $details[] = [
                is_numeric($attempts) ? (int) $attempts : 0,
                is_string($exception) && $exception !== '' ? $exception : null,
            ];
        }

        return $details;
    }
}
