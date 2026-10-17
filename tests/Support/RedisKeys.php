<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\Support;

use Predis\Client;

/**
 * Raw access to the test Redis without any prefix, for cleanup and assertions on final key names. Uses predis
 * (always installed for development), so it works without the phpredis extension.
 */
final class RedisKeys
{
    public static function client(): Client
    {
        return new Client([
            'host' => (string) (getenv('REDIS_HOST') ?: '127.0.0.1'),
            'port' => (int) (getenv('REDIS_PORT') ?: 6379),
            'database' => (int) (getenv('REDIS_DB') ?: 0),
        ]);
    }

    /**
     * Every key starting with the prefix, sorted.
     *
     * @return list<string>
     */
    public static function all(string $prefix): array
    {
        $client = self::client();
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
     * Delete every key starting with the prefix; the number deleted.
     */
    public static function delete(string $prefix): int
    {
        $keys = self::all($prefix);

        foreach (array_chunk($keys, 500) as $chunk) {
            self::client()->del($chunk);
        }

        return count($keys);
    }
}
