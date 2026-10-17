<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Live;

use DF\HorizonObservability\Redis\Lua;
use DF\HorizonObservability\Redis\RedisScriptFailed;
use Illuminate\Redis\Connections\Connection;
use Illuminate\Redis\Connections\PhpRedisConnection;
use Predis\Response\ErrorInterface;
use Predis\Response\ServerException;
use Predis\Response\Status;
use Redis;
use RedisCluster;
use RuntimeException;

/**
 * The Redis commands of the live snapshot cache and the stream leader, with the same reply rules on phpredis and
 * predis: an error reply (`WRONGTYPE`, `OOM`, `READONLY`, ...) throws on both clients instead of reading as a miss or
 * as "not acquired" (phpredis answers `false` and keeps the message in `getLastError()`), and connection errors
 * propagate unchanged.
 *
 * Leases are `SET <key> <token> NX PX <ttl>` and `lock.lua` (compare-and-renew, compare-and-delete), so a holder
 * whose lease expired can never extend or delete the next holder's lease.
 *
 * @internal
 */
final class LiveRedis
{
    public const LOCK_SCRIPT = 'lock';

    /**
     * Run one command; error replies throw.
     *
     * @param  list<mixed>  $parameters
     *
     * @throws RuntimeException on an error reply (predis' ServerException is wrapped, so both clients throw alike)
     */
    public static function command(Connection $connection, string $method, array $parameters): mixed
    {
        $client = $connection->client();
        $phpredis = $client instanceof Redis || $client instanceof RedisCluster;

        if ($phpredis) {
            $client->clearLastError();
        }

        try {
            $reply = $connection->command($method, $parameters);
        } catch (ServerException $e) {
            throw new RuntimeException(sprintf('%s failed: %s', strtoupper($method), $e->getMessage()), 0, $e);
        }

        if ($reply instanceof ErrorInterface) {
            throw new RuntimeException(sprintf('%s failed: %s', strtoupper($method), $reply->getMessage()));
        }

        if ($phpredis && $reply === false) {
            $error = $client->getLastError();

            if (is_string($error) && $error !== '') {
                $client->clearLastError();

                throw new RuntimeException(sprintf('%s failed: %s', strtoupper($method), $error));
            }
        }

        return $reply;
    }

    /**
     * `SET key value NX PX ttl`: true when this call created the key.
     *
     * @throws RuntimeException on an error reply
     */
    public static function setIfAbsent(Connection $connection, string $key, string $value, int $ttlMs): bool
    {
        // phpredis takes the options as an array, predis in the Redis order.
        $reply = $connection instanceof PhpRedisConnection
            ? self::command($connection, 'set', [$key, $value, ['nx', 'px' => max(1, $ttlMs)]])
            : self::command($connection, 'set', [$key, $value, 'PX', max(1, $ttlMs), 'NX']);

        // phpredis: true or false; predis: a Status "OK" or null.
        return $reply === true || ($reply instanceof Status && $reply->getPayload() === 'OK') || $reply === 'OK';
    }

    /**
     * `lock.lua renew`: extend the lease to `$ttlMs` if it still holds `$token`.
     *
     * @throws RedisScriptFailed
     */
    public static function renew(Lua $lua, Connection $connection, string $key, string $token, int $ttlMs): bool
    {
        return (int) self::scalar($lua->eval($connection, self::LOCK_SCRIPT, [$key], ['renew', $token, max(1, $ttlMs)])) === 1;
    }

    /**
     * `lock.lua release`: delete the lease if it still holds `$token`.
     *
     * @throws RedisScriptFailed
     */
    public static function release(Lua $lua, Connection $connection, string $key, string $token): bool
    {
        return (int) self::scalar($lua->eval($connection, self::LOCK_SCRIPT, [$key], ['release', $token, 0])) === 1;
    }

    private static function scalar(mixed $reply): int|string
    {
        return is_int($reply) || is_string($reply) ? $reply : 0;
    }
}
