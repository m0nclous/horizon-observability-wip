<?php

declare(strict_types=1);

use DF\HorizonObservability\Tests\Support\RedisKeys;
use DF\HorizonObservability\Tests\TestCase;
use Illuminate\Support\Facades\Cache;

pest()->group('redis');

it('cleans the keys of the redis cache store, which live in the cache database (T18 QA)', function (): void {
    // The cache connection writes to REDIS_CACHE_DB (1), not REDIS_DB (0): a scan marker (TTL 1 h) left there made a
    // second run with the same pid (always 1 in a container) fail within the hour.
    // The databases follow REDIS_DB and REDIS_CACHE_DB as the app config does (0 and 1 by default).
    $default = (int) config('database.redis.default.database');
    $cache = (int) config('database.redis.cache.database');

    if ($default === $cache) {
        $this->markTestSkipped('REDIS_DB and REDIS_CACHE_DB name the same database.');
    }

    config()->set('cache.default', 'redis');
    Cache::put('redis-keys-test', 'value', 3600);
    app('redis')->connection('default')->set('redis-keys-test', 'value');

    expect(RedisKeys::databases())->toBe([$default, $cache])
        ->and(RedisKeys::all(TestCase::redisPrefix(), $cache))->toHaveCount(1)
        ->and(RedisKeys::all(TestCase::redisPrefix()))->toBe([TestCase::redisPrefix().'redis-keys-test'])
        ->and(RedisKeys::delete(TestCase::redisPrefix()))->toBe(2)
        ->and(RedisKeys::all(TestCase::redisPrefix(), $cache))->toBe([])
        ->and(RedisKeys::all(TestCase::redisPrefix()))->toBe([]);
});

it('reads REDIS_DB and REDIS_CACHE_DB set to 0 as database 0 (T18 QA round 2)', function (): void {
    // `getenv() ?: 1` read REDIS_CACHE_DB=0 as 1: with REDIS_DB=2 the cache keys in database 0 were never cleaned.
    $saved = ['REDIS_DB' => getenv('REDIS_DB'), 'REDIS_CACHE_DB' => getenv('REDIS_CACHE_DB')];

    try {
        putenv('REDIS_DB=2');
        putenv('REDIS_CACHE_DB=0');
        $zeroCache = RedisKeys::databases();
        putenv('REDIS_DB=0');
        putenv('REDIS_CACHE_DB=0');
        $same = RedisKeys::databases();
        putenv('REDIS_DB');
        putenv('REDIS_CACHE_DB');
        $defaults = RedisKeys::databases();
    } finally {
        foreach ($saved as $name => $value) {
            putenv($value === false ? $name : $name.'='.$value);
        }
    }

    expect($zeroCache)->toBe([2, 0])
        ->and($same)->toBe([0])
        ->and($defaults)->toBe([0, 1]);
});
