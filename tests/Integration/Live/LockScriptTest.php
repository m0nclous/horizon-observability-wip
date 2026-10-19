<?php

declare(strict_types=1);

use DF\HorizonObservability\Live\LiveRedis;
use DF\HorizonObservability\Redis\Lua;
use DF\HorizonObservability\Redis\RedisClients;
use DF\HorizonObservability\Redis\RedisScriptFailed;
use DF\HorizonObservability\Tests\Support\RedisKeys;
use DF\HorizonObservability\Tests\TestCase;
use Illuminate\Redis\Connections\Connection;

/*
| lock.lua and the lease commands of LiveRedis: SET NX PX takes a lease, lock.lua renews (compare-and-PEXPIRE) and
| releases (compare-and-DEL) it only while it holds the caller's token. Both clients.
*/

pest()->group('redis');

beforeEach(function (): void {
    $clients = app(RedisClients::class);
    $this->redis = $clients->reader($clients->storageConnection());
    $this->lua = app(Lua::class);
    $this->key = '{horizon-observability}:v1:live:test-lock';
    $this->raw = TestCase::redisPrefix().'horizon:'.$this->key;
});

it('takes a lease only when nobody holds it, with the given lifetime', function (): void {
    /** @var Connection $redis */
    $redis = $this->redis;

    expect(LiveRedis::setIfAbsent($redis, $this->key, 'token-a', 3000))->toBeTrue()
        ->and(LiveRedis::setIfAbsent($redis, $this->key, 'token-b', 3000))->toBeFalse()
        ->and(RedisKeys::client()->get($this->raw))->toBe('token-a')
        ->and(RedisKeys::client()->pttl($this->raw))->toBeGreaterThan(2500)->toBeLessThanOrEqual(3000);
});

it('renews only the holder token', function (): void {
    LiveRedis::setIfAbsent($this->redis, $this->key, 'token-a', 1000);

    expect(LiveRedis::renew($this->lua, $this->redis, $this->key, 'token-b', 9000))->toBeFalse()
        ->and(RedisKeys::client()->pttl($this->raw))->toBeLessThanOrEqual(1000)
        ->and(LiveRedis::renew($this->lua, $this->redis, $this->key, 'token-a', 9000))->toBeTrue()
        ->and(RedisKeys::client()->pttl($this->raw))->toBeGreaterThan(8000)
        ->and(LiveRedis::renew($this->lua, $this->redis, $this->key.':missing', 'token-a', 9000))->toBeFalse();
});

it('releases only the holder token', function (): void {
    LiveRedis::setIfAbsent($this->redis, $this->key, 'token-a', 5000);

    expect(LiveRedis::release($this->lua, $this->redis, $this->key, 'token-b'))->toBeFalse()
        ->and(RedisKeys::client()->get($this->raw))->toBe('token-a')
        ->and(LiveRedis::release($this->lua, $this->redis, $this->key, 'token-a'))->toBeTrue()
        ->and(RedisKeys::client()->exists($this->raw))->toBe(0)
        ->and(LiveRedis::release($this->lua, $this->redis, $this->key, 'token-a'))->toBeFalse();
});

it('lets a new holder keep a lease the old holder lost', function (): void {
    LiveRedis::setIfAbsent($this->redis, $this->key, 'old', 5000);
    RedisKeys::client()->del($this->raw); // expired
    LiveRedis::setIfAbsent($this->redis, $this->key, 'new', 5000);

    expect(LiveRedis::renew($this->lua, $this->redis, $this->key, 'old', 5000))->toBeFalse()
        ->and(LiveRedis::release($this->lua, $this->redis, $this->key, 'old'))->toBeFalse()
        ->and(RedisKeys::client()->get($this->raw))->toBe('new');
});

it('rejects invalid arguments with a script error', function (array $args): void {
    $this->lua->eval($this->redis, 'lock', [$this->key], $args);
})->with([
    'unknown op' => [['steal', 'token', 1000]],
    'empty token' => [['renew', '', 1000]],
])->throws(RedisScriptFailed::class);

it('rejects a renew without a positive integer lifetime', function (string $ttl): void {
    LiveRedis::setIfAbsent($this->redis, $this->key, 'token', 5000);

    $this->lua->eval($this->redis, 'lock', [$this->key], ['renew', 'token', $ttl]);
})->with(['0', '-5', '1.5', 'x'])->throws(RedisScriptFailed::class);

it('throws on an error reply instead of reading it as not acquired', function (): void {
    RedisKeys::client()->rpush($this->raw, ['not a string']);

    expect(fn (): mixed => LiveRedis::command($this->redis, 'get', [$this->key]))->toThrow(RuntimeException::class, 'WRONGTYPE')
        ->and(fn () => $this->lua->eval($this->redis, 'lock', [$this->key], ['renew', 'token', 1000]))->toThrow(RedisScriptFailed::class);
});
