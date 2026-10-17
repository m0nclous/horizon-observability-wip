<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Batches;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Cache\Factory as CacheFactory;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\Connection;
use Illuminate\Database\ConnectionResolverInterface;
use Illuminate\Database\Query\Builder;
use Illuminate\Database\SQLiteDatabaseDoesNotExistException;
use RuntimeException;
use Throwable;
use WeakMap;

/**
 * Batching driver and table detection plus the two bounded batch queries (Arch Q6.7, C1, C15).
 *
 * **Detection** ({@see self::available()}, the auto mode of `collectors.batches`): batches are read only with the
 * `database` batching driver (`queue.batching.driver`, absent = database), a configured `queue.batching.database`
 * connection (null = the default connection) and an existing `queue.batching.table` (default `job_batches`). The
 * table check is memoised per process and in the default cache store for {@see self::DETECTION_TTL_SECONDS}, so a
 * table created by a migration is noticed within 10 minutes. DynamoDB, an unconfigured connection, a missing SQLite
 * file and a missing table mean "no batching here", which is not a failure. A database that cannot be asked (down,
 * wrong credentials) throws: that is a real failure, which the exporter reports with `exporter_collector_success 0`,
 * and nothing is memoised.
 *
 * **Reading** ({@see self::read()}) runs two queries through the query builder, each `ORDER BY created_at DESC LIMIT
 * 5000`, so the cost no longer grows with the table (C15):
 * 1. open batches (`finished_at` and `cancelled_at` null) created during the last 7 days, so lost batches age out
 *    instead of growing the oldest age forever. allowFailures batches that are closed without `finished_at` (see
 *    {@see self::status()}) and older than the closed window are left out in SQL: they would only be discarded;
 * 2. batches finished or cancelled during the last hour.
 *
 * The README recommends indexes on `created_at`, `finished_at` and `cancelled_at` and scheduling
 * `queue:prune-batches`. Times are Unix seconds; no timezone is involved.
 *
 * @internal
 */
final class BatchReader
{
    /**
     * Open batches older than this are not read (7 days).
     */
    public const RUNNING_WINDOW_SECONDS = 604800;

    /**
     * Closed batches are read for this long after closing (1 hour); the dashboard keeps them with
     * `last_over_time(...[$__range])`.
     */
    public const CLOSED_WINDOW_SECONDS = 3600;

    /**
     * Row limit of each query.
     */
    public const LIMIT = 5000;

    public const DETECTION_TTL_SECONDS = 600;

    public const DEFAULT_TABLE = 'job_batches';

    public const COLUMNS = ['id', 'name', 'total_jobs', 'pending_jobs', 'failed_jobs', 'created_at', 'finished_at', 'cancelled_at'];

    private const CACHE_KEY = 'horizon-observability:batches:table:';

    /**
     * Per-process memo of the table check: database manager => detection key => [exists, expires at].
     * Keyed by the database manager, so every application instance (and every test) has its own.
     *
     * @var WeakMap<ConnectionResolverInterface, array<string, array{0: bool, 1: int}>>|null
     */
    private static ?WeakMap $detections = null;

    public function __construct(
        private readonly Repository $config,
        private readonly ConnectionResolverInterface $db,
        private readonly CacheFactory $cache,
        private readonly BatchKind $kind,
    ) {}

    /**
     * Whether the database batching driver and its table are there (auto mode of `collectors.batches`).
     *
     * @throws Throwable when the database cannot be asked whether the table exists
     */
    public function available(): bool
    {
        if ($this->driver() !== 'database') {
            return false;
        }

        $connection = $this->connectionName();
        $settings = $this->connections()[$connection] ?? null;

        if (! is_array($settings)) {
            return false;
        }

        $table = $this->table();
        $key = $this->detectionKey($connection, $table, $settings);
        $now = now()->getTimestamp();
        $memo = $this->memo();

        if (isset($memo[$key]) && $memo[$key][1] > $now) {
            return $memo[$key][0];
        }

        // The answer expires 10 minutes after the table was checked, also when another process checked it.
        [$exists, $checkedAt] = $this->cached($key, $now) ?? [$this->tableExists($connection, $table, $key, $now), $now];
        $memo[$key] = [$exists, $checkedAt + self::DETECTION_TTL_SECONDS];
        $this->detections()[$this->db] = $memo;

        return $exists;
    }

    /**
     * Running batches of the last 7 days and batches closed during the last hour, newest first.
     *
     * @return list<BatchSnapshot>
     *
     * @throws Throwable when the driver is not `database`, or the connection or the table is unusable
     */
    public function read(CarbonImmutable $now): array
    {
        $driver = $this->driver();

        if ($driver !== 'database') {
            throw new RuntimeException(sprintf(
                'batches are read from the database batching driver only, queue.batching.driver is [%s]; set collectors.batches to null (auto) or false.',
                $driver,
            ));
        }

        $runningSince = $now->getTimestamp() - self::RUNNING_WINDOW_SECONDS;
        $closedSince = $now->getTimestamp() - self::CLOSED_WINDOW_SECONDS;

        $open = $this->query()
            ->whereNull('finished_at')
            ->whereNull('cancelled_at')
            ->where('created_at', '>=', $runningSince)
            ->whereNot(static fn (Builder $query): Builder => $query
                ->where('total_jobs', '>', 0)
                ->whereColumn('pending_jobs', 'failed_jobs')
                ->where('created_at', '<', $closedSince))
            ->orderByDesc('created_at')
            ->limit(self::LIMIT)
            ->get();

        $closed = $this->query()
            ->where(static fn (Builder $query): Builder => $query
                ->where('finished_at', '>=', $closedSince)
                ->orWhere('cancelled_at', '>=', $closedSince))
            ->orderByDesc('created_at')
            ->limit(self::LIMIT)
            ->get();

        $snapshots = [];

        foreach ([...$open->all(), ...$closed->all()] as $row) {
            $snapshot = self::snapshot(is_object($row) || is_array($row) ? $row : [], $this->kind);

            if ($snapshot->closedAt === null || $snapshot->closedAt >= $closedSince) {
                $snapshots[$snapshot->id] = $snapshot;
            }
        }

        $snapshots = array_values($snapshots);
        usort($snapshots, static fn (BatchSnapshot $a, BatchSnapshot $b): int => [$b->createdAt, $a->id] <=> [$a->createdAt, $b->id]);

        return $snapshots;
    }

    /**
     * One row of the batch table as a snapshot, classified as the pilot did:
     * - `cancelled_at` set: Cancelled, closed at `cancelled_at`;
     * - `finished_at` set, or `total > 0` and `pending == failed` (allowFailures batches with failed jobs never get
     *   `finished_at`, because a failure does not decrement `pending_jobs`): Failures with failed jobs, else Finished;
     *   closed at `finished_at`, or at `created_at` without it;
     * - otherwise Running (also a new batch whose row still has `total_jobs = 0`).
     *
     * Progress is `round((total - pending) / total * 100)`, 0 without jobs.
     *
     * @param  object|array<array-key, mixed>  $row
     */
    public static function snapshot(object|array $row, BatchKind $kind): BatchSnapshot
    {
        $row = is_array($row) ? $row : get_object_vars($row);
        $id = is_scalar($row['id'] ?? null) ? (string) $row['id'] : '';
        $name = is_scalar($row['name'] ?? null) ? (string) $row['name'] : '';
        $total = self::integer($row['total_jobs'] ?? null) ?? 0;
        $pending = self::integer($row['pending_jobs'] ?? null) ?? 0;
        $failed = self::integer($row['failed_jobs'] ?? null) ?? 0;
        $createdAt = self::integer($row['created_at'] ?? null) ?? 0;
        $finishedAt = self::integer($row['finished_at'] ?? null);
        $cancelledAt = self::integer($row['cancelled_at'] ?? null);
        $status = self::status($total, $pending, $failed, $finishedAt, $cancelledAt);

        return new BatchSnapshot(
            id: $id,
            name: $name,
            kind: $kind->resolve($name, $id),
            status: $status,
            total: $total,
            pending: $pending,
            failed: $failed,
            progress: $total > 0 ? self::integer(round(($total - $pending) / $total * 100)) ?? 0 : 0,
            createdAt: $createdAt,
            closedAt: match ($status) {
                BatchStatus::Running => null,
                BatchStatus::Cancelled => $cancelledAt,
                default => $finishedAt ?? $createdAt,
            },
        );
    }

    /**
     * The status rules of {@see self::snapshot()}.
     */
    public static function status(int $total, int $pending, int $failed, ?int $finishedAt, ?int $cancelledAt): BatchStatus
    {
        return match (true) {
            $cancelledAt !== null => BatchStatus::Cancelled,
            $finishedAt !== null || ($total > 0 && $pending === $failed) => $failed > 0 ? BatchStatus::Failures : BatchStatus::Finished,
            default => BatchStatus::Running,
        };
    }

    /**
     * `queue.batching.driver`; absent means `database`, as Laravel's `BusServiceProvider` reads it.
     */
    public function driver(): string
    {
        $driver = $this->config->get('queue.batching.driver');

        return is_string($driver) && trim($driver) !== '' ? strtolower(trim($driver)) : 'database';
    }

    /**
     * `queue.batching.database`, or the default connection when it is unset.
     */
    public function connectionName(): string
    {
        $connection = $this->config->get('queue.batching.database');

        return is_string($connection) && $connection !== '' ? $connection : $this->db->getDefaultConnection();
    }

    /**
     * `queue.batching.table`, default `job_batches`.
     */
    public function table(): string
    {
        $table = $this->config->get('queue.batching.table');

        return is_string($table) && $table !== '' ? $table : self::DEFAULT_TABLE;
    }

    private function query(): Builder
    {
        return $this->db->connection($this->connectionName())->table($this->table())->select(self::COLUMNS);
    }

    private function tableExists(string $connection, string $table, string $key, int $now): bool
    {
        try {
            $database = $this->db->connection($connection);

            $exists = $database instanceof Connection
                ? $database->getSchemaBuilder()->hasTable($table)
                : throw new RuntimeException(sprintf('the batching connection [%s] is not a database connection.', $connection));
        } catch (Throwable $e) {
            if (! $this->missingSqliteFile($e)) {
                throw $e;
            }

            $exists = false;
        }

        try {
            $this->cache->store()->put(self::CACHE_KEY.$key, ($exists ? 'yes' : 'no').':'.$now, self::DETECTION_TTL_SECONDS);
        } catch (Throwable) {
            // The memo still applies; a failing cache only costs a table check per process.
        }

        return $exists;
    }

    /**
     * The cached answer of another process and when it checked the table; null when there is none or it is older than
     * {@see self::DETECTION_TTL_SECONDS} (a store that keeps entries longer than asked).
     *
     * @return array{0: bool, 1: int}|null
     */
    private function cached(string $key, int $now): ?array
    {
        try {
            $value = $this->cache->store()->get(self::CACHE_KEY.$key);
        } catch (Throwable) {
            return null;
        }

        if (! is_string($value) || preg_match('/^(yes|no):(\d{1,19})$/', $value, $match) !== 1) {
            return null;
        }

        // A check time ahead of this clock (skew between servers) counts as now.
        $checkedAt = min((int) $match[2], $now);

        return $checkedAt + self::DETECTION_TTL_SECONDS > $now ? [$match[1] === 'yes', $checkedAt] : null;
    }

    /**
     * The memo and cache key of a table check: the connection, the table and where the connection points (driver,
     * host, port, database, prefix), so apps sharing a cache store and a connection name do not share the answer.
     *
     * @param  array<array-key, mixed>  $settings
     */
    private function detectionKey(string $connection, string $table, array $settings): string
    {
        $target = [$connection, $table];

        foreach (['driver', 'url', 'host', 'port', 'database', 'prefix'] as $option) {
            $value = $settings[$option] ?? null;
            $target[] = is_scalar($value) ? (string) $value : (is_array($value) ? implode(',', array_filter($value, is_scalar(...))) : '');
        }

        return sha1(implode("\x1f", $target));
    }

    /**
     * A SQLite database file that does not exist: there is no table, which is not a failure.
     */
    private function missingSqliteFile(Throwable $e): bool
    {
        for ($current = $e; $current instanceof Throwable; $current = $current->getPrevious()) {
            if ($current instanceof SQLiteDatabaseDoesNotExistException) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<array-key, mixed>
     */
    private function connections(): array
    {
        $connections = $this->config->get('database.connections');

        return is_array($connections) ? $connections : [];
    }

    /**
     * @return array<string, array{0: bool, 1: int}>
     */
    private function memo(): array
    {
        return $this->detections()[$this->db] ?? [];
    }

    /**
     * @return WeakMap<ConnectionResolverInterface, array<string, array{0: bool, 1: int}>>
     */
    private function detections(): WeakMap
    {
        return self::$detections ??= new WeakMap;
    }

    /**
     * An integer column read as an int, or null when it is not a number. A float outside the integer range (a
     * corrupt row) is null as well: casting it wraps on PHP 8.2-8.4 and warns on PHP 8.5, which Laravel's error
     * handler turns into an exception that would fail the whole collector while the row exists.
     */
    private static function integer(mixed $value): ?int
    {
        return match (true) {
            is_int($value) => $value,
            is_float($value) => $value >= (float) PHP_INT_MIN && $value < (float) PHP_INT_MAX ? (int) $value : null,
            is_string($value) && is_numeric(trim($value)) => self::integer((float) trim($value)),
            default => null,
        };
    }
}
