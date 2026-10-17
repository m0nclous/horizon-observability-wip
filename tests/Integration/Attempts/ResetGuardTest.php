<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use DF\HorizonObservability\Attempts\AttemptStore;
use DF\HorizonObservability\Attempts\CounterResetGuard;
use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Redis\Keys;
use DF\HorizonObservability\Redis\RedisClients;
use DF\HorizonObservability\Tests\Fixtures\Attempts\Storage;
use DF\HorizonObservability\Tests\Support\LogRecorder;
use DF\HorizonObservability\Tests\Support\RedisKeys;
use DF\HorizonObservability\Tests\TestCase;

/*
| reset_guard.lua (Arch Q6.10, Appendix D). The pilot wiped every counter whenever meta.run_id differed, including
| a missing meta on the first scrape (C21); a restored or restarted Redis must reset the counters so increase()
| does not count old totals again.
*/

pest()->group('redis');

beforeEach(function (): void {
    $this->keys = new Keys;
    $this->now = CarbonImmutable::createFromTimestamp(1_700_000_000.25);
    $this->app->instance('log', $this->log = new LogRecorder);
    $this->guard = static fn (): CounterResetGuard => app(CounterResetGuard::class);
    $info = (string) RedisKeys::client()->executeRaw(['INFO', 'server']);
    $this->runId = preg_match('/run_id:(\w+)/', $info, $match) === 1 ? $match[1] : '';
    Storage::record(duration: 0.5, startedAt: 1_700_000_000.0, readyAt: 1_699_999_999.0, now: $this->now);
});

it('stores the run_id on the first scrape and deletes nothing (C21)', function (): void {
    expect(($this->guard)()->check($this->now))->toBeFalse()
        ->and(Storage::hash($this->keys->meta()))->toBe(['run_id' => $this->runId])
        ->and(Storage::hash($this->keys->attempts()))->toBe(['redis|default|App\\Jobs\\SendInvoice|completed' => '1']);
});

it('keeps counter fields of meta written before the first scrape', function (): void {
    Storage::store()->reader()->hset($this->keys->meta(), 'dropped', '4');

    ($this->guard)()->check($this->now);

    expect(Storage::hash($this->keys->meta()))->toBe(['dropped' => '4', 'run_id' => $this->runId]);
});

it('is a no-op for the same run_id', function (): void {
    ($this->guard)()->check($this->now);

    expect(($this->guard)()->check($this->now))->toBeFalse()
        ->and(Storage::hash($this->keys->attempts()))->toBe(['redis|default|App\\Jobs\\SendInvoice|completed' => '1'])
        ->and($this->log->records)->toBe([]);
});

it('deletes the counters after a changed run_id and logs it once', function (): void {
    Storage::store()->reader()->hmset($this->keys->meta(), [
        'run_id' => 'a-restored-dataset',
        'dropped' => '5',
        'live_push_at' => '1699999999.5',
        'live_push_failures' => '2',
    ]);

    expect(($this->guard)()->check($this->now))->toBeTrue()
        ->and(array_map(Storage::exists(...), $this->keys->counterKeys()))->toBe([false, false, false, false, false])
        ->and(Storage::hash($this->keys->meta()))->toBe([
            'live_push_at' => '1699999999.5',
            'reset_at' => '1700000000.25',
            'run_id' => $this->runId,
        ])
        ->and(Storage::exists($this->keys->jobClasses()))->toBeTrue()
        ->and(Storage::exists($this->keys->extremes(113_333_333, 'max')))->toBeTrue()
        ->and(Storage::exists($this->keys->ring(340_000_000)))->toBeTrue()
        ->and($this->log->messages('info'))->toBe(['horizon-observability: the Redis run_id changed (restart or restore); the attempt counters were reset.'])
        ->and(($this->guard)()->check($this->now))->toBeFalse();
});

it('is a no-op when INFO is denied', function (): void {
    $user = 'hot_'.getmypid().'_noinfo';
    $raw = RedisKeys::client();

    try {
        $raw->executeRaw(['ACL', 'SETUSER', $user, 'reset', 'on', '>noinfo-password', '~*', '+@all', '-info']);
    } catch (Throwable $e) {
        $this->markTestSkipped('No ACL support: '.$e->getMessage());
    }

    try {
        config()->set('database.redis.noinfo', [
            'host' => getenv('REDIS_HOST') ?: '127.0.0.1',
            'port' => (int) (getenv('REDIS_PORT') ?: 6379),
            'database' => 0,
            'username' => $user,
            'password' => 'noinfo-password',
        ]);
        config()->set('horizon-observability.redis.connection', 'noinfo');

        foreach ([Config::class, RedisClients::class, AttemptStore::class] as $abstract) {
            $this->app->forgetInstance($abstract);
        }

        $meta = $this->keys->meta();
        Storage::store()->reader()->hset($meta, 'run_id', 'a-restored-dataset');
        Storage::record(now: $this->now);

        expect(($this->guard)()->check($this->now))->toBeFalse()
            ->and(Storage::hash($meta))->toBe(['run_id' => 'a-restored-dataset'])
            ->and(Storage::hash($this->keys->attempts()))->toBe(['redis|default|App\\Jobs\\SendInvoice|completed' => '1']);
    } finally {
        $raw->executeRaw(['ACL', 'DELUSER', $user]);
        RedisKeys::delete(TestCase::redisPrefix());
    }
});
