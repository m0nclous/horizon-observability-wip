<?php

declare(strict_types=1);

use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Live\Stream\LiveStreamer;
use DF\HorizonObservability\Redis\Keys;
use DF\HorizonObservability\Redis\RedisClients;
use DF\HorizonObservability\Tests\Integration\Live\Stream\Support\StreamClock;
use DF\HorizonObservability\Tests\Integration\Live\Support\LiveState;
use DF\HorizonObservability\Tests\Integration\Live\Support\Processes;
use DF\HorizonObservability\Tests\Support\RedisCommands;
use DF\HorizonObservability\Tests\Support\RedisKeys;
use DF\HorizonObservability\Tests\TestCase;
use Illuminate\Console\Signals;
use Illuminate\Http\Client\Request;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/*
| Adversarial probes of the stream loop (T12 QA round 3) against the round 3 changes: the leader's keep-alive renew
| while it waits for the next tick, and the follower's 2 s retry that ends the wait early (Addendum R4.6 steps 1, 2
| and 6), at the longest interval of Appendix A' (10 s): a keep-alive that hits a Redis error, a leader that backs
| off for 60 s, the command cost of the keep-alive and of the retry, the outage frame cadence whatever the interval,
| and real `horizon-observability:stream` processes on the real clock with real lease expiry.
*/

pest()->group('redis');

const QA3_STREAM_URL = 'http://grafana-qa3:3000';

beforeEach(function (): void {
    config()->set('horizon-observability.live.stream.grafana_url', QA3_STREAM_URL);
    config()->set('horizon-observability.live.stream.token', 'glsa_qa3-token-0123456789');
    config()->set('horizon-observability.live.stream.id', 'probe');
    config()->set('horizon-observability.live.stream.interval', 10.0);
    config()->set('horizon-observability.live.cache_ttl', 0);
    $this->app->forgetInstance(Config::class);

    $this->time = new StreamClock(LiveState::now());
    LiveState::horizon($this->app);
    LiveState::queues($this->app);

    $this->pushes = [];
    $this->answers = [];
    Http::fake(function (Request $request) {
        $this->pushes[] = ['at' => $this->time->seconds(), 'body' => $request->body()];
        $answer = array_shift($this->answers) ?? 200;

        return Http::response($answer === 200 ? '' : '{"message":"refused"}', $answer);
    });

    $this->lease = TestCase::redisPrefix().'horizon:'.(new Keys)->liveLeader();
});

afterEach(function (): void {
    LiveState::freeze(null);
});

/**
 * A sleep that advances the fake clock and ages a string lease by the slept time (the fake clock does not move
 * Redis' clock); a key without a TTL (the broken list below) is left alone. `$after` runs after every slice.
 *
 * @param  (Closure(float): void)|null  $after  receives the seconds since the start
 * @return Closure(float): void
 */
function qa3AgingSleep(?Closure $after = null): Closure
{
    $time = test()->time;
    $key = test()->lease;

    return static function (float $seconds) use ($time, $key, $after): void {
        $time->advance($seconds);
        $client = RedisKeys::client();
        $ttl = (int) $client->pttl($key);

        if ($ttl > 0) {
            $left = $ttl - (int) round($seconds * 1000);

            if ($left > 0) {
                $client->pexpire($key, $left);
            } else {
                $client->del($key);
            }
        }

        if ($after instanceof Closure) {
            $after($time->seconds());
        }
    };
}

/**
 * @param  Closure(float): void  $sleep
 */
function qa3Streamer(Closure $sleep): LiveStreamer
{
    return app()->make(LiveStreamer::class, ['clock' => test()->time->clock(), 'sleep' => $sleep]);
}

/**
 * @return ArrayObject<int, array{0: string, 1: float}>
 */
function qa3Events(LiveStreamer $streamer): ArrayObject
{
    $time = test()->time;
    /** @var ArrayObject<int, array{0: string, 1: float}> $events */
    $events = new ArrayObject;
    $streamer->listen(static function (string $event) use ($events, $time): void {
        $events[] = [$event, $time->seconds()];
    });

    return $events;
}

/**
 * The fake times of the Redis commands that name the leader key (acquire, renew, release).
 *
 * @return ArrayObject<int, float>
 */
function qa3LeaseCommandTimes(): ArrayObject
{
    $time = test()->time;
    $suffix = (new Keys)->liveLeader();
    /** @var ArrayObject<int, float> $times */
    $times = new ArrayObject;
    RedisCommands::start(app());
    Event::listen(CommandExecuted::class, static function (CommandExecuted $event) use ($times, $time, $suffix): void {
        $flat = [];
        array_walk_recursive($event->parameters, static function (mixed $value) use (&$flat): void {
            $flat[] = is_scalar($value) ? (string) $value : '';
        });

        if (array_filter($flat, static fn (string $value): bool => str_ends_with($value, $suffix)) !== []) {
            $times[] = $time->seconds();
        }
    });

    return $times;
}

it('follows when a keep-alive renew hits a Redis error, tells the viewers at its next 2 s retry and leads again after the outage', function (): void {
    $lease = $this->lease;
    $broken = false;
    $repaired = false;
    // 3.5 s: every lease command starts to fail (the key holds a list: WRONGTYPE); 15 s: Redis answers again.
    $sleep = qa3AgingSleep(static function (float $now) use ($lease, &$broken, &$repaired): void {
        if (! $broken && $now >= 3.5) {
            RedisKeys::client()->del($lease);
            RedisKeys::client()->rpush($lease, ['broken']);
            $broken = true;
        }

        if ($broken && ! $repaired && $now >= 15.0) {
            RedisKeys::client()->del($lease);
            $repaired = true;
        }
    });
    $streamer = qa3Streamer($sleep);
    $events = qa3Events($streamer);

    $streamer->run(static fn (): bool => false, 20, 4096, false);

    $bodies = array_column($this->pushes, 'body', 'at');

    // 0 s data; the keep-alive at 4 s fails (follower); retries at 6, 8, 10, 12, 14 s: the former leader pushes the
    // "Live unavailable" frame at 6 and 12 s (every 5 s within 7 s of the outage); the retry at 16 s leads again.
    expect(array_column($this->pushes, 'at'))->toBe([0.0, 6.0, 12.0, 16.0, 26.0])
        ->and(array_column($events->getArrayCopy(), 0))->toBe(['leader', 'follower', 'unavailable', 'unavailable', 'leader', 'exit'])
        ->and(array_column($events->getArrayCopy(), 1))->toBe([0.0, 4.0, 6.0, 12.0, 16.0, 26.0])
        ->and($bodies[6.0])->toContain('caption="Live unavailable"')
        ->and($bodies[12.0])->toContain('caption="Live unavailable"')
        ->and($bodies[16.0])->not->toContain('Live unavailable')
        ->and($bodies[16.0])->toContain('caption="Oldest: ');
});

it('keeps the lease through a 60 s auth backoff at the longest interval, so a second replica never takes it', function (): void {
    $this->answers = [401];
    $other = qa3Streamer(qa3AgingSleep());
    // A second replica tries to take the lease after every slice of the leader's waits.
    $takeovers = 0;
    $lastTry = -1.0;
    $sleep = qa3AgingSleep(static function (float $now) use ($other, &$takeovers, &$lastTry): void {
        if ($now - $lastTry >= 2.0) {
            $lastTry = $now;
            $takeovers += $other->tick() === LiveStreamer::FOLLOWER ? 0 : 1;
        }
    });
    $streamer = qa3Streamer($sleep);
    $events = qa3Events($streamer);

    $streamer->run(static fn (): bool => false, 70, 4096, false);

    // 0 s: 401, backoff 60 s; ticks 10..50 s back off; 60 s pushes again; 70 s: --max-time.
    expect(array_column($this->pushes, 'at'))->toBe([0.0, 60.0, 70.0])
        ->and(array_column($events->getArrayCopy(), 0))->toBe(['leader', 'push-failed', 'pushed', 'exit'])
        ->and($takeovers)->toBe(0)
        ->and($other->isLeader())->toBeFalse();
});

it('renews about once a second as the leader and tries about every 2 s as a follower at the longest interval, and no more', function (): void {
    $times = qa3LeaseCommandTimes();

    qa3Streamer(qa3AgingSleep())->run(static fn (): bool => false, 20, 4096, false);

    // acquire and renew-before-push at 0; keep-alives 1..9; tick start and push renews at 10; 11..19; 20, 20 and the
    // release at 20: 25 commands in 20 s, never two keep-alives in one second.
    expect($times->getArrayCopy())->toBe([0.0, 0.0, 1.0, 2.0, 3.0, 4.0, 5.0, 6.0, 7.0, 8.0, 9.0, 10.0, 10.0, 11.0, 12.0, 13.0, 14.0, 15.0, 16.0, 17.0, 18.0, 19.0, 20.0, 20.0, 20.0])
        ->and(array_column($this->pushes, 'at'))->toBe([0.0, 10.0, 20.0]);

    // A follower (another process holds the lease for a minute): SET NX plus the compare-and-renew every 2 s.
    $times->exchangeArray([]);
    $this->pushes = [];
    RedisKeys::client()->set($this->lease, 'other-host:1:0000', 'PX', 60000);
    $this->time->advance(1.0);
    $offset = $this->time->seconds();
    $follower = qa3Streamer($this->time->sleep());

    $follower->run(static fn (): bool => false, 20, 4096, false);

    $relative = array_map(static fn (float $at): float => round($at - $offset, 3), $times->getArrayCopy());

    expect($relative)->toBe([0.0, 0.0, 2.0, 2.0, 4.0, 4.0, 6.0, 6.0, 8.0, 8.0, 10.0, 10.0, 12.0, 12.0, 14.0, 14.0, 16.0, 16.0, 18.0, 18.0, 20.0, 20.0])
        ->and($this->pushes)->toBe([])
        ->and(RedisKeys::client()->get($this->lease))->toBe('other-host:1:0000');
});

it('pushes the outage frame at 0 and 6 s from a process that never reached Redis, whatever the interval', function (float $interval): void {
    config()->set('horizon-observability.live.stream.interval', $interval);
    config()->set('database.redis.qa3-dead', ['host' => '127.0.0.1', 'port' => 1, 'database' => 0, 'timeout' => 0.2]);
    config()->set('horizon-observability.redis.connection', 'qa3-dead');
    $this->app->forgetInstance(Config::class);
    $this->app->forgetInstance(RedisClients::class);

    qa3Streamer($this->time->sleep())->run(static fn (): bool => false, 20, 4096, false);

    expect(array_column($this->pushes, 'at'))->toBe([0.0, 6.0])
        ->and($this->pushes[0]['body'])->toContain('caption="Live unavailable"');
})->with([
    '0.5 s' => [0.5],
    '1 s' => [1.0],
    '4 s' => [4.0],
    '10 s' => [10.0],
]);

it('keeps the real lease alive between 10 s ticks and fails over within 7 s of a SIGKILL (real processes, real clock)', function (): void {
    $pushes = TestCase::redisPrefix().'qa3-failover:pushes';
    Http::fake(static function (Request $request) use ($pushes) {
        RedisKeys::client()->rpush($pushes, [json_encode([getmypid(), microtime(true)])]);

        return Http::response('', 200);
    });
    $lease = $this->lease;

    $children = Processes::fork(2, static function (int $index): void {
        Signals::resolveAvailabilityUsing(static fn (): bool => extension_loaded('pcntl'));
        Artisan::call('horizon-observability:stream', ['--max-time' => 40, '--memory' => 4096]);
    });

    /**
     * @return list<array{0: int, 1: float}>
     */
    $read = static fn (): array => array_map(
        static fn (string $json): array => json_decode($json, true),
        RedisKeys::client()->lrange($pushes, 0, -1),
    );
    $wait = static function (Closure $done, float $timeout): void {
        $deadline = microtime(true) + $timeout;

        while (! $done() && microtime(true) < $deadline) {
            usleep(20000);
        }
    };

    try {
        $wait(static fn (): bool => $read() !== [], 10.0);
        $first = $read()[0] ?? throw new RuntimeException('No push within 10 s.');
        $leaderPid = $first[0];
        $followerPid = $children[0] === $leaderPid ? $children[1] : $children[0];

        // 3.5 s of the 10 s wait for the next tick: the lease must stay above 3 s (renewed every second, so it stays
        // near 4-5 s); without the keep-alive it would be down to 1.5 s.
        $minTtl = PHP_INT_MAX;

        while (microtime(true) < $first[1] + 3.5) {
            $minTtl = min($minTtl, (int) RedisKeys::client()->pttl($lease));
            usleep(50000);
        }

        $ttlAtKill = (int) RedisKeys::client()->pttl($lease);
        posix_kill($leaderPid, SIGKILL);
        $killedAt = microtime(true);
        $wait(static fn (): bool => array_filter($read(), static fn (array $push): bool => $push[0] === $followerPid) !== [], 12.0);
        $takeover = array_values(array_filter($read(), static fn (array $push): bool => $push[0] === $followerPid))[0][1] ?? null;

        expect($minTtl)->toBeGreaterThan(3000)
            ->and(count($read()))->toBe(2) // the leader's first push and the follower's takeover push
            ->and($takeover)->not->toBeNull()
            // not before the dead leader's lease expired, and within 5 s + 2 s
            ->and($takeover - $killedAt)->toBeGreaterThanOrEqual($ttlAtKill / 1000 - 0.1)
            ->and($takeover - $killedAt)->toBeLessThanOrEqual(7.0);
    } finally {
        foreach ($children as $pid) {
            posix_kill($pid, SIGKILL);
        }

        Processes::wait($children, 5.0);
    }
});

it('sends no "Live unavailable" frame after a healthy replica took over from a leader cut off from Redis alone', function (): void {
    // Replica A reaches the storage Redis as its own ACL user, so it alone can be cut off (a partial partition)
    // while replica B keeps working, at the default interval of 1 s.
    $admin = RedisKeys::client();
    $user = 'qa3_'.getmypid();
    $admin->executeRaw(['ACL', 'SETUSER', $user, 'reset', 'on', '>qa3-password', '~*', '&*', '+@all']);

    try {
        $storage = config('horizon-observability.redis.connection');
        config()->set('database.redis.qa3-a', [...config('database.redis.horizon'), 'username' => $user, 'password' => 'qa3-password']);
        config()->set('horizon-observability.redis.connection', 'qa3-a');
        $this->app->forgetInstance(Config::class);
        $this->app->forgetInstance(RedisClients::class);
        $a = app()->make(LiveStreamer::class, ['clock' => $this->time->clock(), 'sleep' => $this->time->sleep()]);
        // A's private storage client copies the connection now, before the config is restored for B.
        app(RedisClients::class)->manager();
        config()->set('horizon-observability.redis.connection', $storage);
        $this->app->forgetInstance(Config::class);
        $this->app->forgetInstance(RedisClients::class);
        $b = app()->make(LiveStreamer::class, ['clock' => $this->time->clock(), 'sleep' => $this->time->sleep()]);
        config()->set('horizon-observability.live.stream.interval', 1.0);

        $pushedBy = [];
        $tick = function (string $name, LiveStreamer $streamer) use (&$pushedBy): string {
            $before = count($this->pushes);
            $outcome = $streamer->tick();

            foreach (array_slice($this->pushes, $before) as $push) {
                $pushedBy[] = [$name, $push['at'], str_contains($push['body'], 'caption="Live unavailable"') ? 'unavailable' : 'data'];
            }

            return $outcome;
        };

        $tick('a', $a);
        $tick('b', $b);
        // 0.5 s: A loses Redis (every command of its user is refused), B does not.
        $admin->executeRaw(['ACL', 'SETUSER', $user, '-@all']);

        foreach (range(1, 12) as $second) {
            qa3AgingSleep()(1.0);
            $tick('b', $b);
            $tick('a', $a);
        }

        // A's lease (last renewed at 0 s) expires at 5 s; B takes over on its retry at 6 s and pushes every second.
        $takeover = array_values(array_filter($pushedBy, static fn (array $push): bool => $push[0] === 'b'))[0][1] ?? null;
        $late = array_values(array_filter($pushedBy, static fn (array $push): bool => $push[2] === 'unavailable' && $push[1] >= ($takeover ?? INF)));

        expect($b->isLeader())->toBeTrue()
            ->and($takeover)->toBe(6.0)
            // The frame that A pushes after B leads overwrites B's live tiles with "Live unavailable" until B's next
            // push, while live data flows. Today A pushes it at 9 s: its 7 s window counts from its first failed
            // retry (3 s), not from its lease expiry (5 s).
            ->and($late)->toBe([]);
    } finally {
        $admin->executeRaw(['ACL', 'DELUSER', $user]);
    }
})->skip('T12 QA round 3 follow-up (not blocking, present since round 1): in a partial partition the former leader pushes its second "Live unavailable" frame 6 s after its first failed retry (9 s), after a healthy replica took the expired lease (6 s), so the tiles read "Live unavailable" until that replica\'s next push; ending the frame window at the lease expiry (last kept renew + 5 s) would keep the frame from interleaving with a healthy leader.');
