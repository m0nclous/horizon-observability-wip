<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\DashboardTruth\Record;

use DF\HorizonObservability\Tests\Support\RedisKeys;

/**
 * At the end of a dataset, PERSISTs every key with a TTL under the world prefix and records the remaining TTL
 * (design section 6.2): the Horizon master and supervisor records (15/30 s, whose zset scores are V), the live ring
 * buckets (180 s), the duration extremes (300 s) and the Horizon job records. The Redis state then stays the state at
 * V while the cases run, however long the real time between recording and capture is.
 */
final class TtlFreeze
{
    /**
     * @return array<string, int> key without the prefix => the TTL in seconds it had
     */
    public static function freeze(string $prefix): array
    {
        $frozen = [];

        foreach (RedisKeys::databases() as $database) {
            $client = RedisKeys::client($database);

            foreach (RedisKeys::all($prefix, $database) as $key) {
                $ttl = (int) $client->ttl($key);

                if ($ttl > 0) {
                    $client->persist($key);
                    $frozen[($database === 0 ? '' : 'db'.$database.':').substr($key, strlen($prefix))] = $ttl;
                }
            }
        }

        ksort($frozen);

        return $frozen;
    }
}
