<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\Support;

use Illuminate\Redis\Connections\Connection;
use Illuminate\Support\Str;

/**
 * Raw queue payloads like Laravel's `Queue::createPayload()` plus Horizon's `JobPayload::prepare()`, and helpers
 * that place them in Laravel's Redis queue keys (`queues:<q>`, `queues:<q>:delayed`, `queues:<q>:reserved`) on a
 * connection that applies the queue prefix.
 */
final class QueuePayloads
{
    /**
     * A payload of a queued job class. `$overrides` replace or add top-level keys, `$without` removes keys (e.g. a
     * payload of Laravel < 12.11 has no `createdAt` and `delay`).
     *
     * @param  array<string, mixed>  $overrides
     * @param  list<string>  $without
     * @return array<string, mixed>
     */
    public static function job(string $displayName = 'App\\Jobs\\SendInvoice', array $overrides = [], array $without = []): array
    {
        $uuid = (string) Str::uuid();
        $payload = [
            'uuid' => $uuid,
            'displayName' => $displayName,
            'job' => 'Illuminate\\Queue\\CallQueuedHandler@call',
            'maxTries' => null,
            'maxExceptions' => null,
            'failOnTimeout' => false,
            'backoff' => null,
            'timeout' => null,
            'retryUntil' => null,
            'data' => [
                'commandName' => $displayName,
                'command' => 'O:'.strlen($displayName).':"'.$displayName.'":0:{}',
            ],
            'createdAt' => 1700000000,
            'delay' => null,
            'id' => $uuid,
            'attempts' => 0,
            'type' => 'job',
            'tags' => [],
            'silenced' => false,
            'pushedAt' => '1700000000.1234',
        ];

        return array_diff_key([...$payload, ...$overrides], array_flip($without));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function encode(array $payload): string
    {
        return (string) json_encode($payload, JSON_UNESCAPED_UNICODE);
    }

    /**
     * `RPUSH queues:<queue>` (a ready job).
     *
     * @param  array<string, mixed>|string  $payload
     */
    public static function push(Connection $redis, string $queue, array|string $payload): void
    {
        $redis->rpush('queues:'.$queue, is_string($payload) ? $payload : self::encode($payload));
    }

    /**
     * `ZADD queues:<queue>:delayed` (a delayed job available at the given Unix time).
     *
     * @param  array<string, mixed>|string  $payload
     */
    public static function delay(Connection $redis, string $queue, array|string $payload, float $availableAt): void
    {
        $redis->zadd('queues:'.$queue.':delayed', $availableAt, is_string($payload) ? $payload : self::encode($payload));
    }

    /**
     * `ZADD queues:<queue>:reserved` (a job a worker holds until the given Unix time).
     *
     * @param  array<string, mixed>|string  $payload
     */
    public static function reserve(Connection $redis, string $queue, array|string $payload, float $expiresAt): void
    {
        $redis->zadd('queues:'.$queue.':reserved', $expiresAt, is_string($payload) ? $payload : self::encode($payload));
    }
}
