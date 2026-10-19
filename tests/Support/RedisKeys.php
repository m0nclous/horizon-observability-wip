<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\Support;

use Predis\Client;

/**
 * Raw access to the test Redis without any prefix, for cleanup and assertions on final key names. Uses predis
 * (always installed for development), so it works without the phpredis extension.
 *
 * {@see self::delete()} cleans the default database (`REDIS_DB`, 0) and the one of the `cache` connection
 * (`REDIS_CACHE_DB`, 1), which the tests on the redis cache store write to (scan markers, throttle keys).
 */
final class RedisKeys
{
    public static function client(?int $database = null): Client
    {
        return new Client([
            'host' => (string) (getenv('REDIS_HOST') ?: '127.0.0.1'),
            'port' => (int) (getenv('REDIS_PORT') ?: 6379),
            'database' => $database ?? self::env('REDIS_DB', 0),
        ]);
    }

    /**
     * The databases the tests write to: the default one and the one of the `cache` connection.
     *
     * @return list<int>
     */
    public static function databases(): array
    {
        return array_values(array_unique([self::env('REDIS_DB', 0), self::env('REDIS_CACHE_DB', 1)]));
    }

    /**
     * An integer environment variable, or the default when it is not set. `?:` would also replace "0", so
     * REDIS_CACHE_DB=0 read as database 1 and the cache keys in database 0 outlived the test (T18 QA round 2).
     */
    private static function env(string $name, int $default): int
    {
        $value = getenv($name);

        return $value === false || $value === '' ? $default : (int) $value;
    }

    /**
     * Every key starting with the prefix in one database (the default one without an argument), sorted.
     *
     * @return list<string>
     */
    public static function all(string $prefix, ?int $database = null): array
    {
        $client = self::client($database);
        $pattern = addcslashes($prefix, '*?[]\\').'*';
        $keys = [];
        $cursor = '0';

        do {
            /** @var array{0: string, 1: list<string>} $page */
            $page = $client->scan($cursor, ['MATCH' => $pattern, 'COUNT' => 1000]);
            [$cursor, $batch] = $page;
            array_push($keys, ...$batch);
        } while ($cursor !== '0');

        $keys = array_values(array_unique($keys));
        sort($keys);

        return $keys;
    }

    /**
     * Delete every key starting with the prefix in every database the tests write to; the number deleted.
     */
    public static function delete(string $prefix): int
    {
        $deleted = 0;

        foreach (self::databases() as $database) {
            $keys = self::all($prefix, $database);

            foreach (array_chunk($keys, 500) as $chunk) {
                self::client($database)->del($chunk);
            }

            $deleted += count($keys);
        }

        return $deleted;
    }
}
