<?php

declare(strict_types=1);

use DF\HorizonObservability\Live\Stream\LeaderLock;
use DF\HorizonObservability\Redis\Keys;
use DF\HorizonObservability\Redis\RedisClients;
use DF\HorizonObservability\Tests\Support\RedisCommands;
use DF\HorizonObservability\Tests\Support\RedisKeys;
use DF\HorizonObservability\Tests\TestCase;

/*
| The stream leader lease (Addendum R4.6): SET live:leader <host:pid:rand> NX PX 5000 on the storage client, renewed
| and released with lock.lua only by its holder.
*/

pest()->group('redis');

beforeEach(function (): void {
    $this->raw = TestCase::redisPrefix().'horizon:'.(new Keys)->liveLeader();
});

it('lets one of two processes hold the lease', function (): void {
    $a = app(LeaderLock::class);
    $b = app(LeaderLock::class);

    expect($a->token())->not->toBe($b->token())
        ->and($a->token())->toMatch('/^.+:\d+:[0-9a-f]{8}$/')
        ->and($a->acquire())->toBeTrue()
        ->and($b->acquire())->toBeFalse()
        ->and(RedisKeys::client()->get($this->raw))->toBe($a->token())
        ->and(RedisKeys::client()->pttl($this->raw))->toBeGreaterThan(4500)->toBeLessThanOrEqual(LeaderLock::TTL_MS)
        ->and($b->renew())->toBeFalse()
        ->and($a->renew())->toBeTrue();
});

it('re-acquires its own lease and renews it to 5 s', function (): void {
    $lock = app(LeaderLock::class);
    $lock->acquire();
    RedisKeys::client()->pexpire($this->raw, 100);

    expect($lock->acquire())->toBeTrue()
        ->and(RedisKeys::client()->pttl($this->raw))->toBeGreaterThan(4500);
});

it('releases only its own lease', function (): void {
    $a = app(LeaderLock::class);
    $b = app(LeaderLock::class);
    $a->acquire();

    $b->release();

    expect(RedisKeys::client()->get($this->raw))->toBe($a->token());

    $a->release();

    expect(RedisKeys::client()->exists($this->raw))->toBe(0)
        ->and($b->acquire())->toBeTrue();
});

it('runs on the worker-timeout storage client', function (): void {
    $commands = RedisCommands::start($this->app);

    app(LeaderLock::class)->acquire();

    expect($commands->names(RedisClients::STORAGE))->toBe(['SET'])
        ->and($commands->count())->toBe(1);
});

it('lets Redis errors through', function (): void {
    RedisKeys::client()->rpush($this->raw, ['not a lease']);

    expect(fn (): bool => app(LeaderLock::class)->acquire())->toThrow(RuntimeException::class);
});
