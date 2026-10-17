<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Batches;

use Carbon\CarbonImmutable;
use DF\HorizonObservability\Attempts\JobClassLabel;
use DF\HorizonObservability\Metrics\Labels;
use DF\HorizonObservability\Redis\CircuitBreaker;
use DF\HorizonObservability\Redis\Keys;
use DF\HorizonObservability\Redis\RedisClients;
use DF\HorizonObservability\Support\FailureReporter;
use Illuminate\Container\Container as GlobalContainer;
use Illuminate\Contracts\Bus\QueueingDispatcher;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Contracts\Container\Container;
use Illuminate\Queue\Events\JobQueued;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use JsonException;
use Predis\Response\ErrorInterface;
use Redis;
use RedisCluster;
use RuntimeException;
use Throwable;

/**
 * Links of batch kinds to queue connections, queues and job classes (Arch Q6.7): which queues and classes the batches
 * of a kind consist of. They feed `queue_batch_job_classes`, `queue_batch_info`, the `batch_kind` filter of the job
 * lists and the Batch scope of `/live`.
 *
 * **Writing** ({@see self::onQueued()}, a `JobQueued` listener registered unless `collectors.batches` is false): for a
 * batched job on a `redis` queue connection, `ZADD batch_links <now> kind\x1fconnection\x1fqueue\x1fjob_class` on
 * the storage connection.
 * - The kind comes from the batch name (`Bus::findBatch()`, one query per batch id, memoised for
 *   {@see self::MEMO_LIMIT} batch ids) through {@see BatchKind::resolve()}; the job class is the payload
 *   `displayName` through {@see JobClassLabel::normalize()}, like every other `job_class` label.
 * - A link is written at most once per {@see self::REFRESH_SECONDS} per process, so a batch of 10 000 jobs of one
 *   class costs one `ZADD`. The first write of each hour also prunes the links older than 7 days, so the set stays
 *   bounded when the batches collector does not run (auto mode without a batch table, DynamoDB batching).
 * - Writes go through the shared {@see CircuitBreaker}. While it is open the write is skipped; neither a skipped nor
 *   a failed link write counts as a dropped attempt (only {@see CircuitBreaker::ATTEMPTS} does): a link is refreshed
 *   by the next dispatch anyway.
 * - Nothing ever reaches the dispatching code: every failure is logged (throttled) and swallowed.
 *
 * **Reading**: links seen during the last {@see self::TTL_SECONDS} (7 days), at most the {@see self::MAX_LINKS} most
 * recently seen, with one `ZRANGE`. The batches collector also removes older ones once per scrape
 * ({@see self::prune()}); reads ignore them either way. Reads use no breaker; they fail visibly.
 *
 * @internal
 */
final class BatchLinks
{
    /**
     * Links older than this are ignored and pruned (7 days).
     */
    public const TTL_SECONDS = 604800;

    /**
     * A process rewrites a link at most this often (1 hour).
     */
    public const REFRESH_SECONDS = 3600;

    /**
     * Entries of each per-process memo (batch kinds by id, write times by link); a full memo starts over.
     */
    public const MEMO_LIMIT = 1000;

    /**
     * Links read at most, the most recently seen: a kind hook that yields a kind per batch cannot make a read (once
     * per scrape, job-list request and live snapshot with a Batch filter) grow with the batches of a week.
     */
    public const MAX_LINKS = 10000;

    /**
     * @var array<string, string> batch id => kind
     */
    private array $kinds = [];

    /**
     * @var array<string, int> member => Unix seconds of the last write
     */
    private array $written = [];

    /**
     * Unix seconds of this process's last prune on the write path.
     */
    private ?int $prunedAt = null;

    private readonly Container $container;

    /**
     * Dependencies are resolved from the container when they are first needed, so the listener costs nothing until
     * a batched job is dispatched, and `new BatchLinks` works anywhere an application runs.
     */
    public function __construct(?Container $container = null)
    {
        $this->container = $container ?? GlobalContainer::getInstance();
    }

    /**
     * Record the link of a batched job on a `redis` queue connection. Never throws.
     */
    public function onQueued(JobQueued $event): void
    {
        try {
            $batchId = $this->batchId($event->job);

            if ($batchId === null) {
                return;
            }

            $connection = is_string($event->connectionName) ? $event->connectionName : '';
            $settings = $this->queueConnection($connection);

            if (($settings['driver'] ?? null) !== 'redis') {
                return;
            }

            $queue = $event->queue;
            $queue = is_string($queue) && $queue !== '' ? $queue : (is_string($settings['queue'] ?? null) && $settings['queue'] !== '' ? $settings['queue'] : 'default');
            $member = Keys::field(
                $this->kindOf($batchId),
                Labels::sanitize($connection),
                Labels::sanitize($queue),
                JobClassLabel::normalize($this->displayName($event)),
            );
            $now = now()->getTimestamp();

            if (isset($this->written[$member]) && $now - $this->written[$member] < self::REFRESH_SECONDS) {
                return;
            }

            /** @var CircuitBreaker $breaker */
            $breaker = $this->container->make(CircuitBreaker::class);

            if ($breaker->isOpen()) {
                return;
            }

            $key = $this->keys()->batchLinks();

            $prune = $this->prunedAt === null || $now - $this->prunedAt >= self::REFRESH_SECONDS;
            $write = function () use ($key, $now, $member, $prune): void {
                $storage = $this->storage();
                $this->command($storage, 'zadd', [$key, $now, $member]);

                if ($prune) {
                    $this->command($storage, 'zremrangebyscore', [$key, '-inf', '('.($now - self::TTL_SECONDS)]);
                }
            };

            if ($breaker->attempt('batch_links', $write)) {
                if (count($this->written) >= self::MEMO_LIMIT) {
                    $this->written = [];
                }

                $this->written[$member] = $now;
                $this->prunedAt = $prune ? $now : $this->prunedAt;
            }
        } catch (Throwable $e) {
            try {
                /** @var FailureReporter $reporter */
                $reporter = $this->container->make(FailureReporter::class);
                $reporter->report('batch_links', $e);
            } catch (Throwable) {
                // Dispatching must never fail because of the metrics.
            }
        }
    }

    /**
     * Links seen during the last 7 days.
     *
     * @return list<BatchLink>
     */
    public function all(): array
    {
        return array_map(static fn (array $entry): BatchLink => $entry['link'], $this->recent(now()->toImmutable()));
    }

    /**
     * Queues and job classes of the given batch kinds, sorted and without duplicates; empty for kinds without links.
     *
     * @param  list<string>  $kinds
     * @return array{queues: list<string>, jobs: list<string>}
     */
    public function forKinds(array $kinds): array
    {
        if ($kinds === []) {
            return ['queues' => [], 'jobs' => []];
        }

        $wanted = array_fill_keys($kinds, true);
        $queues = [];
        $jobs = [];

        foreach ($this->all() as $link) {
            if (isset($wanted[$link->kind])) {
                $queues[$link->queue] = true;
                $jobs[$link->jobClass] = true;
            }
        }

        $queues = array_map(strval(...), array_keys($queues));
        $jobs = array_map(strval(...), array_keys($jobs));
        sort($queues, SORT_STRING);
        sort($jobs, SORT_STRING);

        return ['queues' => $queues, 'jobs' => $jobs];
    }

    /**
     * Links seen since `$now - 7 days` with the time they were last seen, sorted by link. One `ZRANGE ... WITHSCORES`
     * of the {@see self::MAX_LINKS} highest scores (ranks `-MAX_LINKS` to `-1`), from a set the pruning keeps to about
     * 7 days of links.
     *
     * @return list<array{link: BatchLink, seenAt: int}>
     */
    public function recent(CarbonImmutable $now): array
    {
        $storage = $this->storage();
        // phpredis takes the WITHSCORES flag as a bool, predis as the modifier; both answer member => score.
        $reply = $this->command($storage, 'zrange', [
            $this->keys()->batchLinks(),
            -self::MAX_LINKS,
            -1,
            $storage instanceof PhpRedisConnection ? true : 'WITHSCORES',
        ]);
        $since = $now->getTimestamp() - self::TTL_SECONDS;
        $entries = [];

        foreach (is_array($reply) ? $reply : [] as $member => $score) {
            // Label values as the writer stores them; sanitised again so a foreign member cannot break a family.
            $parts = array_map(Labels::sanitize(...), Keys::splitField((string) $member));

            if (count($parts) !== 4 || in_array('', $parts, true) || ! is_numeric($score) || (int) $score < $since) {
                continue;
            }

            $field = Keys::field(...$parts);
            $entries[$field] = ['link' => new BatchLink(...$parts), 'seenAt' => max((int) $score, $entries[$field]['seenAt'] ?? PHP_INT_MIN)];
        }

        ksort($entries, SORT_STRING);

        return array_values($entries);
    }

    /**
     * Remove the links older than 7 days (`ZREMRANGEBYSCORE`); the number removed.
     */
    public function prune(CarbonImmutable $now): int
    {
        $removed = $this->command($this->storage(), 'zremrangebyscore', [
            $this->keys()->batchLinks(),
            '-inf',
            '('.($now->getTimestamp() - self::TTL_SECONDS),
        ]);

        return is_numeric($removed) ? (int) $removed : 0;
    }

    /**
     * One command on the storage connection. An error reply (`WRONGTYPE`, `OOM`, ...) throws on both clients: predis
     * throws or returns an error response, phpredis answers `false` and keeps the message in `getLastError()`, which
     * would otherwise read as "no links" or as a successful write.
     *
     * @param  list<mixed>  $parameters
     */
    private function command(Connection $storage, string $method, array $parameters): mixed
    {
        $client = $storage->client();
        $phpredis = $client instanceof Redis || $client instanceof RedisCluster;

        if ($phpredis) {
            $client->clearLastError();
        }

        $reply = $storage->command($method, $parameters);

        if ($reply instanceof ErrorInterface) {
            throw new RuntimeException(sprintf('%s of the batch links failed: %s', strtoupper($method), $reply->getMessage()));
        }

        if ($phpredis && $reply === false) {
            $error = $client->getLastError();
            $client->clearLastError();

            throw new RuntimeException(sprintf(
                '%s of the batch links failed: %s',
                strtoupper($method),
                is_string($error) && $error !== '' ? $error : 'no reply',
            ));
        }

        return $reply;
    }

    private function kindOf(string $batchId): string
    {
        if (isset($this->kinds[$batchId])) {
            return $this->kinds[$batchId];
        }

        /** @var QueueingDispatcher $bus */
        $bus = $this->container->make(QueueingDispatcher::class);
        $name = $bus->findBatch($batchId)?->name;

        if (count($this->kinds) >= self::MEMO_LIMIT) {
            $this->kinds = [];
        }

        return $this->kinds[$batchId] = (new BatchKind)->resolve(is_string($name) ? $name : null, $batchId);
    }

    /**
     * @return array<array-key, mixed>
     */
    private function queueConnection(string $name): array
    {
        /** @var Repository $config */
        $config = $this->container->make('config');
        $connections = $config->get('queue.connections');
        $connection = is_array($connections) ? ($connections[$name] ?? null) : null;

        return is_array($connection) ? $connection : [];
    }

    private function storage(): Connection
    {
        /** @var RedisClients $clients */
        $clients = $this->container->make(RedisClients::class);

        return $clients->storage();
    }

    private function keys(): Keys
    {
        /** @var Keys */
        return $this->container->make(Keys::class);
    }

    private function batchId(mixed $job): ?string
    {
        if (! is_object($job) || ! isset($job->batchId)) {
            return null;
        }

        $id = $job->batchId;

        return is_string($id) && $id !== '' ? $id : null;
    }

    /**
     * The payload `displayName` (Horizon's job name); the job's own name when the payload has none.
     */
    private function displayName(JobQueued $event): string
    {
        $payload = $event->payload;

        if (is_string($payload)) {
            try {
                $payload = json_decode($payload, true, 512, JSON_THROW_ON_ERROR);
            } catch (JsonException) {
                $payload = null;
            }
        }

        if (is_array($payload) && is_string($payload['displayName'] ?? null)) {
            return $payload['displayName'];
        }

        $job = $event->job;

        if (is_object($job)) {
            return method_exists($job, 'displayName') && is_string($name = $job->displayName()) ? $name : $job::class;
        }

        return is_string($job) ? $job : '';
    }
}
