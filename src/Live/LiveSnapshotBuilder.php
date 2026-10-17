<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Live;

use Carbon\CarbonImmutable;
use Closure;
use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Horizon\Supervisors;
use DF\HorizonObservability\Horizon\SupervisorSnapshot;
use DF\HorizonObservability\Horizon\Topology;
use DF\HorizonObservability\Queues\QueueInspector;
use DF\HorizonObservability\Queues\QueueSnapshot;
use DF\HorizonObservability\Queues\UnsupportedTopology;
use DF\HorizonObservability\Redis\Keys;
use DF\HorizonObservability\Redis\RedisScriptFailed;
use DF\HorizonObservability\Support\FailureReporter;
use InvalidArgumentException;
use Throwable;
use UnexpectedValueException;

/**
 * Builds the live snapshot of a scope (Addendum R4.2): Q + 5 round trips, Q = the queues in scope.
 *
 * 1. **Horizon**: the status and the running supervisors from Horizon's repositories ({@see Supervisors}, 2 round
 *    trips each, as in the exporter). The supervisors are not read when the status read failed (same connection).
 * 2. **Queues**: one `queue_state.lua` call per queue in live mode ({@see QueueInspector::live()}: at most 20 head
 *    items and 1 MB), on the reader of the queue connection's Redis connection, with the reserved classes only when a
 *    Job or Batch filter is active. Queues of connections that do not use the `redis` driver are skipped, as in the
 *    exporter.
 * 3. **Wait thresholds** from {@see Topology::waitThreshold()} (Horizon's effective value, Addendum C2.3 item 5).
 * 4. **Throughput** from the attempt ring ({@see AttemptRing::window()}, 1 round trip): the last complete minute.
 *    The ring has no job classes, so with a Job or Batch filter it is not read and the throughput is null
 *    (`RPM: \u{2014}`) rather than the unfiltered count.
 * 5. **Derivations** in {@see LiveSnapshot::compose()} with {@see Captions}.
 *
 * Scope rules: a running supervisor is listed when it passes the Supervisor filter and, when the scope is narrower
 * than the topology (a Supervisor, Queue or Batch filter), when it serves a queue in scope; its processes are then
 * those of its pools that serve a queue in scope (the off-mode tile filters `horizon_supervisor_processes` by queue
 * too). Busy workers are the reserved jobs of its queues in scope (of the selected classes with a Job or Batch filter).
 * The queue rows are the raw queue state (unfiltered by class). An unknown oldest age (jobs are ready but no first
 * attempt is among the 20 head items and nothing is due) reads as 0. The throughput counts the queues in scope, or
 * every queue of the ring for the unfiltered scope.
 *
 * Failure isolation: every source is read in isolation and a failure is listed in `errors` (`{source, message}`,
 * short, no payloads) and logged with an in-process throttle (no cache round trip: the default cache store may share
 * the failing Redis). A failed queue is left out of the sums; after a connection failure the remaining queues of the
 * same Redis connection are skipped instead of paying the timeout again, and after {@see self::BUDGET_SECONDS} the
 * remaining reads are skipped. When no queue in scope could be read, Pending, Delayed and Reserved are null with
 * caption `Live unavailable` ({@see LiveSnapshot::withQueuesUnavailable()}); when nothing at all could be read the
 * result is {@see LiveSnapshot::unavailable()}. The builder never throws.
 *
 * @internal
 */
final readonly class LiveSnapshotBuilder
{
    /**
     * Reads start only within this many seconds of the build start: the build lock of the snapshot cache lives 2 s,
     * so a slow build outlives it by at most one Redis timeout.
     */
    public const BUDGET_SECONDS = 2.0;

    /**
     * Error messages are cut to this many bytes.
     */
    public const MAX_ERROR_BYTES = 200;

    /**
     * @var Closure(): float
     */
    private Closure $clock;

    /**
     * @param  (Closure(): float)|null  $clock  monotonic seconds, for tests; default `hrtime()`
     */
    public function __construct(
        private Config $config,
        private Supervisors $supervisors,
        private Topology $topology,
        private QueueInspector $inspector,
        private AttemptRing $ring,
        private FailureReporter $reporter,
        ?Closure $clock = null,
    ) {
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1e9;
    }

    public function build(LiveScope $scope, CarbonImmutable $now): LiveSnapshot
    {
        $started = ($this->clock)();
        $generatedAt = (int) floor((float) $now->format('U.u') * 1000);
        $ttlMs = (int) round($this->config->liveCacheTtl * 1000);
        $errors = [];

        // 1. Horizon.
        $status = null;
        $running = [];

        try {
            $status = $this->supervisors->status();
        } catch (Throwable $e) {
            $errors[] = $this->failure('horizon', $e);
        }

        if ($status !== null) {
            try {
                $running = $this->supervisors->all();
            } catch (Throwable $e) {
                $errors[] = $this->failure('supervisors', $e);
            }
        }

        // 2. Queues.
        $inScope = [];

        foreach ($scope->queues as ['connection' => $connection, 'queue' => $queue]) {
            $inScope[Keys::field($connection, $queue)] = true;
        }

        $snapshots = [];
        $seen = [];
        $attempted = 0;
        $failedConnections = [];

        foreach ($scope->queues as ['connection' => $connection, 'queue' => $queue]) {
            $field = Keys::field($connection, $queue);

            if (isset($seen[$field]) || ! $this->inspector->supports($connection)) {
                continue;
            }

            $seen[$field] = true;

            $attempted++;
            $source = 'queue:'.$connection.':'.$queue;
            $redisConnection = (string) $this->topology->redisConnection($connection);

            if (isset($failedConnections[$redisConnection])) {
                $errors[] = ['source' => $source, 'message' => sprintf('skipped: the Redis connection "%s" failed for an earlier queue.', $redisConnection)];

                continue;
            }

            if ($this->overBudget($started)) {
                $errors[] = ['source' => $source, 'message' => sprintf('skipped: the build ran out of its %.0f s budget.', self::BUDGET_SECONDS)];

                continue;
            }

            try {
                $snapshots[$field] = $this->inspector->live($connection, $queue, $now, $scope->jobFilter);
            } catch (Throwable $e) {
                $errors[] = $this->failure($source, $e);

                if ($this->isConnectionFailure($e)) {
                    $failedConnections[$redisConnection] = true;
                }
            }
        }

        // 4. Throughput (not with a Job or Batch filter: the ring has no classes).
        $window = null;

        if (! $scope->jobFilter) {
            if ($this->overBudget($started)) {
                $errors[] = ['source' => 'ring', 'message' => sprintf('skipped: the build ran out of its %.0f s budget.', self::BUDGET_SECONDS)];
            } else {
                try {
                    $window = $this->ring->window($now);
                } catch (Throwable $e) {
                    $errors[] = $this->failure('ring', $e);
                }
            }
        }

        $queuesUnknown = $attempted > 0 && $snapshots === [];

        if ($status === null && ($queuesUnknown || $attempted === 0) && $window === null) {
            return LiveSnapshot::unavailable($scope, $generatedAt, $ttlMs, $errors);
        }

        $narrowed = $this->narrowed($scope);
        $supervisorRows = $this->supervisorInputs($scope, $running, $inScope, $snapshots, $narrowed);
        $queueRows = $this->queueInputs($snapshots, $supervisorRows, $window, (float) $now->format('U.u'));

        $snapshot = LiveSnapshot::compose(
            $scope,
            $generatedAt,
            $ttlMs,
            $status,
            $supervisorRows,
            $queueRows,
            $window === null ? null : $this->throughput($window, $inScope, $narrowed),
            $errors,
        );

        return $queuesUnknown ? $snapshot->withQueuesUnavailable() : $snapshot;
    }

    /**
     * Running supervisors merged by name (several masters run the same supervisor during a deploy or on several
     * hosts): processes summed per pool, the largest min and max, the settings of the first master by name.
     *
     * @param  list<SupervisorSnapshot>  $running
     * @param  array<string, true>  $inScope  `conn\x1fqueue` of the queues in scope
     * @param  array<string, QueueSnapshot>  $snapshots  the queues read
     * @return list<array{name: string, connection: string, balance: string, processes: int, min_processes: int, max_processes: int, busy: int, queues: list<string>}>
     */
    private function supervisorInputs(LiveScope $scope, array $running, array $inScope, array $snapshots, bool $narrowed): array
    {
        /** @var array<string, array{connection: string, balance: string, min: int, max: int, pools: array<string, int>, queues: list<string>}> $merged */
        $merged = [];

        foreach ($running as $supervisor) {
            if ($scope->supervisors !== null && ! in_array($supervisor->name, $scope->supervisors, true)) {
                continue;
            }

            $entry = $merged[$supervisor->name] ?? [
                'connection' => $supervisor->connection,
                'balance' => $supervisor->balance,
                'min' => $supervisor->minProcesses,
                'max' => $supervisor->maxProcesses,
                'pools' => [],
                'queues' => [],
            ];
            $entry['min'] = max($entry['min'], $supervisor->minProcesses);
            $entry['max'] = max($entry['max'], $supervisor->maxProcesses);

            foreach ($supervisor->processes as $pool => $count) {
                $entry['pools'][(string) $pool] = ($entry['pools'][(string) $pool] ?? 0) + $count;
            }

            foreach ($supervisor->queues as $queue) {
                if (! in_array($queue, $entry['queues'], true)) {
                    $entry['queues'][] = $queue;
                }
            }

            $merged[$supervisor->name] = $entry;
        }

        $rows = [];

        foreach ($merged as $name => $entry) {
            $served = fn (string $queue): bool => isset($inScope[Keys::field($entry['connection'], $queue)]);
            $processes = 0;
            $matched = false;

            foreach ($entry['pools'] as $pool => $count) {
                $poolQueues = array_map(trim(...), explode(',', (string) $pool));

                if (! $narrowed || array_filter($poolQueues, $served) !== []) {
                    $processes += $count;
                    $matched = true;
                }
            }

            $queues = $narrowed ? array_values(array_filter($entry['queues'], $served)) : $entry['queues'];

            if ($narrowed && ! $matched && $queues === []) {
                continue;
            }

            $busy = 0;

            foreach ($queues as $queue) {
                $snapshot = $snapshots[Keys::field($entry['connection'], $queue)] ?? null;

                if ($snapshot instanceof QueueSnapshot) {
                    $busy += $scope->jobFilter ? $this->reservedOf($snapshot, $scope->jobClasses) : $snapshot->reserved;
                }
            }

            $rows[] = [
                'name' => (string) $name,
                'connection' => $entry['connection'],
                'balance' => $entry['balance'],
                'processes' => $processes,
                'min_processes' => $entry['min'],
                'max_processes' => $entry['max'],
                'busy' => $busy,
                'queues' => $queues,
            ];
        }

        return $rows;
    }

    /**
     * @param  array<string, QueueSnapshot>  $snapshots
     * @param  list<array{name: string, connection: string, balance: string, processes: int, min_processes: int, max_processes: int, busy: int, queues: list<string>}>  $supervisors
     * @param  array<string, array<string, int>>|null  $window
     * @return list<array{connection: string, queue: string, pending: int, reserved: int, delayed: int, delayed_due: int, oldest_seconds: float, wait_threshold_seconds: int|null, completed_per_minute: int|null, failed_per_minute: float|null, supervisors: list<string>}>
     */
    private function queueInputs(array $snapshots, array $supervisors, ?array $window, float $now): array
    {
        $rows = [];

        foreach ($snapshots as $field => $snapshot) {
            $age = $snapshot->oldestAgeSeconds($now);
            $counts = $window[$field] ?? [];
            $served = array_values(array_map(
                static fn (array $supervisor): string => $supervisor['name'],
                array_filter($supervisors, static fn (array $supervisor): bool => $supervisor['connection'] === $snapshot->connection && in_array($snapshot->queue, $supervisor['queues'], true)),
            ));
            sort($served, SORT_STRING);

            $rows[] = [
                'connection' => $snapshot->connection,
                'queue' => $snapshot->queue,
                'pending' => $snapshot->pending(),
                'reserved' => $snapshot->reserved,
                'delayed' => $snapshot->delayedNotDue(),
                'delayed_due' => $snapshot->delayedDue,
                // The exact age at the exporter's precision (round(age, 3)): compose() takes the wait ratio and level
                // from it and shows it rounded to seconds.
                'oldest_seconds' => $age === null ? 0.0 : round($age, 3),
                'wait_threshold_seconds' => $this->topology->waitThreshold($snapshot->connection, $snapshot->queue),
                'completed_per_minute' => $window === null ? null : (int) round(($counts['completed'] ?? 0) * 60 / LiveSnapshot::WINDOW_SECONDS),
                'failed_per_minute' => $window === null ? null : round((($counts['failed'] ?? 0) + ($counts['timed_out'] ?? 0)) * 60 / LiveSnapshot::WINDOW_SECONDS, 2),
                'supervisors' => $served,
            ];
        }

        return $rows;
    }

    /**
     * Ring counts of the queues in scope (every queue for the unfiltered scope): completed, failed (failed and
     * timed out) and retried; `released` is not counted.
     *
     * @param  array<string, array<string, int>>  $window
     * @param  array<string, true>  $inScope
     * @return array{completed: int, failed: int, retried: int}
     */
    private function throughput(array $window, array $inScope, bool $narrowed): array
    {
        $totals = ['completed' => 0, 'failed' => 0, 'retried' => 0];

        foreach ($window as $field => $counts) {
            if ($narrowed && ! isset($inScope[$field])) {
                continue;
            }

            $totals['completed'] += $counts['completed'] ?? 0;
            $totals['failed'] += ($counts['failed'] ?? 0) + ($counts['timed_out'] ?? 0);
            $totals['retried'] += $counts['retried'] ?? 0;
        }

        return $totals;
    }

    /**
     * Reserved jobs of the selected classes (all reserved jobs when no class is selected).
     *
     * @param  list<string>|null  $classes
     */
    private function reservedOf(QueueSnapshot $snapshot, ?array $classes): int
    {
        if ($classes === null) {
            return $snapshot->reserved;
        }

        $busy = 0;

        foreach (array_unique($classes) as $class) {
            $busy += $snapshot->reservedByClass[$class] ?? 0;
        }

        return $busy;
    }

    /**
     * Whether the scope is narrower than the topology: a Supervisor filter, or fewer queues (a Queue or Batch filter).
     */
    private function narrowed(LiveScope $scope): bool
    {
        if ($scope->supervisors !== null) {
            return true;
        }

        try {
            return $scope->queues !== $this->topology->queues();
        } catch (Throwable) {
            return true;
        }
    }

    private function overBudget(float $started): bool
    {
        return ($this->clock)() - $started >= self::BUDGET_SECONDS;
    }

    /**
     * A failure of one queue (a script error, a malformed reply, an unsupported topology) leaves the connection
     * usable; anything else (a refused or timed-out connection) is taken as a failed connection.
     */
    private function isConnectionFailure(Throwable $e): bool
    {
        return ! ($e instanceof RedisScriptFailed
            || $e instanceof UnsupportedTopology
            || $e instanceof UnexpectedValueException
            || $e instanceof InvalidArgumentException);
    }

    /**
     * The `errors[]` message of a failure: `ShortClass: first line`, at most {@see self::MAX_ERROR_BYTES} bytes.
     */
    public static function errorMessage(Throwable $e): string
    {
        $message = FailureReporter::describe($e);

        return strlen($message) > self::MAX_ERROR_BYTES ? mb_strcut($message, 0, self::MAX_ERROR_BYTES - 3, 'UTF-8').'...' : $message;
    }

    /**
     * @return array{source: string, message: string}
     */
    private function failure(string $source, Throwable $e): array
    {
        $this->reporter->reportLocally('live.'.$source, $e);

        return ['source' => $source, 'message' => self::errorMessage($e)];
    }
}
