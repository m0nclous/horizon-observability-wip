<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Queues;

use Carbon\CarbonImmutable;
use DF\HorizonObservability\Attempts\JobClassLabel;
use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Horizon\Topology;
use DF\HorizonObservability\Redis\Lua;
use DF\HorizonObservability\Redis\RedisClients;
use DF\HorizonObservability\Redis\RedisScriptFailed;
use Illuminate\Redis\Connections\Connection;
use InvalidArgumentException;
use Predis\ClientInterface as PredisClient;
use Predis\Command\Processor\KeyPrefixProcessor;
use Redis;
use RedisCluster;
use UnexpectedValueException;

/**
 * One `queue_state.lua` call per queue on a reader client (Arch Q6.3, Q6.6, Q6.11, C8, C12; Addendum D').
 *
 * - **Connection.** The queue connection's Redis connection comes from {@see Topology::redisConnection()} and is read
 *   through {@see RedisClients::reader()} (`redis.scrape_timeout`, never persistent); the keys are Laravel's
 *   `queues:<q>`, `:reserved` and `:delayed`, which the client prefixes like the app's own queue client does.
 * - **Cluster.** On a Redis Cluster the three keys share a slot only through a hash tag in the queue name (or the
 *   prefix); without one the queue fails with {@see UnsupportedTopology} before any command is sent.
 * - **Full mode** ({@see self::inspect()}, the queues collector): counts, the class breakdown of the first
 *   `queue_scan_limit` head payloads (at most {@see self::MAX_BYTES}), the reserved breakdown of at most
 *   {@see self::RESERVED_SCAN_LIMIT} payloads, and the readiness of the first first-attempt payload (C8).
 * - **Live mode** ({@see self::live()}, the live snapshot): the same counts and readiness, but the head scan stops
 *   at the first fresh payload, at {@see self::LIVE_SCAN_LIMIT} payloads and at {@see self::LIVE_MAX_BYTES}; no pending
 *   breakdown; the reserved breakdown only when asked (a Job or Batch filter is active).
 *
 * Class names are the payload `displayName` decoded from the raw JSON and normalised with
 * {@see JobClassLabel::normalize()}, then summed, so they match the attempt metrics. Script and connection errors
 * propagate; callers isolate them per queue.
 *
 * @internal
 */
final readonly class QueueInspector
{
    public const SCRIPT = 'queue_state';

    /**
     * Reserved payloads counted by class per queue (Arch Q3 internal constants).
     */
    public const RESERVED_SCAN_LIMIT = 1000;

    /**
     * Byte cap of the head scan in full mode (Arch Q3 internal constants).
     */
    public const MAX_BYTES = 16777216;

    /**
     * Head payloads the live mode looks at, at most (applied by the script: `min(queue_scan_limit, 20)`).
     */
    public const LIVE_SCAN_LIMIT = 20;

    /**
     * Byte cap of the head scan in live mode (Addendum D').
     */
    public const LIVE_MAX_BYTES = 1048576;

    public function __construct(
        private RedisClients $clients,
        private Lua $lua,
        private Topology $topology,
        private Config $config,
    ) {}

    /**
     * Whether the queues of a queue connection can be inspected: it is configured and uses the `redis` driver.
     * Queues of other connections are skipped by the callers.
     */
    public function supports(string $connection): bool
    {
        return $this->topology->redisConnection($connection) !== null;
    }

    /**
     * Full mode for the queues collector: counts, readiness and per-class maps of the scanned head.
     *
     * @throws InvalidArgumentException when the queue connection is not a configured `redis` connection
     * @throws UnsupportedTopology when the queue keys span several cluster slots
     * @throws RedisScriptFailed
     */
    public function inspect(string $connection, string $queue, CarbonImmutable $now): QueueSnapshot
    {
        return $this->read($connection, $queue, $now, 'full', self::RESERVED_SCAN_LIMIT, self::MAX_BYTES);
    }

    /**
     * Live mode for the live snapshot: at most 20 head items and 1 MB, no pending classes, reserved classes only
     * when asked (a Job or Batch filter is active).
     *
     * @throws InvalidArgumentException when the queue connection is not a configured `redis` connection
     * @throws UnsupportedTopology when the queue keys span several cluster slots
     * @throws RedisScriptFailed
     */
    public function live(string $connection, string $queue, CarbonImmutable $now, bool $reservedByClass): QueueSnapshot
    {
        return $this->read($connection, $queue, $now, 'live', $reservedByClass ? self::RESERVED_SCAN_LIMIT : 0, self::LIVE_MAX_BYTES);
    }

    /**
     * @param  'full'|'live'  $mode
     */
    private function read(string $connection, string $queue, CarbonImmutable $now, string $mode, int $reservedScanLimit, int $maxBytes): QueueSnapshot
    {
        $redisConnection = $this->topology->redisConnection($connection) ?? throw new InvalidArgumentException(sprintf(
            'The queue connection "%s" is not configured or does not use the redis driver.',
            $connection,
        ));

        $reader = $this->clients->reader($redisConnection);
        $key = 'queues:'.$queue;

        if ($this->clients->isCluster($redisConnection) && ! $this->hasHashTag($this->prefix($reader).$key)) {
            throw UnsupportedTopology::clusterQueue($connection, $queue, $redisConnection);
        }

        $reply = $this->lua->eval($reader, self::SCRIPT, [$key, $key.':reserved', $key.':delayed'], [
            (float) $now->format('U.u'),
            $this->config->queueScanLimit,
            $reservedScanLimit,
            $maxBytes,
            $mode,
        ]);

        return $this->snapshot($connection, $queue, $reply);
    }

    private function snapshot(string $connection, string $queue, mixed $reply): QueueSnapshot
    {
        if (! is_array($reply) || count($reply) !== 9) {
            throw new UnexpectedValueException(sprintf('%s returned %s instead of 9 values.', self::SCRIPT, get_debug_type($reply)));
        }

        $reply = array_values($reply);

        return new QueueSnapshot(
            connection: $connection,
            queue: $queue,
            length: $this->count($reply[0]),
            reserved: $this->count($reply[1]),
            delayed: $this->count($reply[2]),
            delayedDue: $this->count($reply[3]),
            earliestDueAt: $this->time($reply[4]),
            firstFreshReadyAt: $this->time($reply[5]),
            scanned: $this->count($reply[6]),
            pendingByClass: $this->classes($reply[7]),
            reservedByClass: $this->classes($reply[8]),
        );
    }

    private function count(mixed $value): int
    {
        if (is_int($value) && $value >= 0) {
            return $value;
        }

        if (is_string($value) && ctype_digit($value)) {
            return (int) $value;
        }

        throw new UnexpectedValueException(sprintf('%s returned %s instead of a count.', self::SCRIPT, get_debug_type($value)));
    }

    /**
     * A Unix time from the script: a decimal string, or nil (`false` on phpredis, `null` on predis) when unknown.
     */
    private function time(mixed $value): ?float
    {
        if ($value === null || $value === false) {
            return null;
        }

        if ((is_string($value) || is_int($value) || is_float($value)) && is_numeric($value) && is_finite((float) $value)) {
            return (float) $value;
        }

        throw new UnexpectedValueException(sprintf('%s returned %s instead of a time.', self::SCRIPT, get_debug_type($value)));
    }

    /**
     * Flat `{displayName, count, ...}` pairs to normalised class => count.
     *
     * @return array<string, int>
     */
    private function classes(mixed $pairs): array
    {
        if (! is_array($pairs) || count($pairs) % 2 !== 0) {
            throw new UnexpectedValueException(sprintf('%s returned %s instead of name and count pairs.', self::SCRIPT, get_debug_type($pairs)));
        }

        $classes = [];

        foreach (array_chunk(array_values($pairs), 2) as [$name, $count]) {
            if (! is_string($name) && ! is_int($name)) {
                throw new UnexpectedValueException(sprintf('%s returned %s instead of a class name.', self::SCRIPT, get_debug_type($name)));
            }

            $class = JobClassLabel::normalize($this->decode((string) $name));
            $classes[$class] = ($classes[$class] ?? 0) + $this->count($count);
        }

        return $classes;
    }

    /**
     * The raw JSON string content of a `displayName` with its escapes decoded (`\\`, `\"`, `\/`, `\uXXXX`); the raw
     * text when the escapes are invalid.
     */
    private function decode(string $raw): string
    {
        if (! str_contains($raw, '\\')) {
            return $raw;
        }

        $decoded = json_decode('"'.$raw.'"');

        return is_string($decoded) ? $decoded : $raw;
    }

    /**
     * The connection prefix the client puts before every key (phpredis `OPT_PREFIX`, predis `prefix`).
     */
    private function prefix(Connection $connection): string
    {
        $client = $connection->client();

        if ($client instanceof Redis || $client instanceof RedisCluster) {
            // RedisCluster shares the option numbers of Redis.
            $prefix = $client->getOption(Redis::OPT_PREFIX);
        } elseif ($client instanceof PredisClient) {
            $processor = $client->getOptions()->prefix;
            $prefix = $processor instanceof KeyPrefixProcessor ? $processor->getPrefix() : '';
        } else {
            $prefix = '';
        }

        return is_string($prefix) ? $prefix : '';
    }

    /**
     * Whether a key that starts with $start and continues without braces is hashed by a tag inside $start: the text
     * between the first `{` and the first `}` after it, when not empty (the Redis Cluster rule). The three queue keys
     * differ only after `queues:<q>`, so they share a slot exactly when this holds.
     */
    private function hasHashTag(string $start): bool
    {
        $open = strpos($start, '{');

        if ($open === false) {
            return false;
        }

        $close = strpos($start, '}', $open + 1);

        return $close !== false && $close > $open + 1;
    }
}
