<?php

declare(strict_types=1);

use DF\HorizonObservability\Tests\Support\RedisKeys;
use DF\HorizonObservability\Tests\TestCase;
use Illuminate\Support\Facades\Cache;

pest()->group('redis');

it('cleans the keys of the redis cache store, which live in the cache database (T18 QA)', function (): void {
    // The cache connection writes to REDIS_CACHE_DB (1), not REDIS_DB (0): a scan marker (TTL 1 h) left there made a
    // second run with the same pid (always 1 in a container) fail within the hour.
    config()->set('cache.default', 'redis');
    Cache::put('redis-keys-test', 'value', 3600);
    app('redis')->connection('default')->set('redis-keys-test', 'value');

    expect(RedisKeys::databases())->toBe([0, 1])
        ->and(RedisKeys::all(TestCase::redisPrefix(), 1))->toHaveCount(1)
        ->and(RedisKeys::all(TestCase::redisPrefix()))->toBe([TestCase::redisPrefix().'redis-keys-test'])
        ->and(RedisKeys::delete(TestCase::redisPrefix()))->toBe(2)
        ->and(RedisKeys::all(TestCase::redisPrefix(), 1))->toBe([])
        ->and(RedisKeys::all(TestCase::redisPrefix()))->toBe([]);
});
