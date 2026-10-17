<?php

declare(strict_types=1);

use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Live\Stream\LiveStreamer;
use DF\HorizonObservability\Redis\Keys;
use DF\HorizonObservability\Tests\Integration\Live\Stream\Support\StreamClock;
use DF\HorizonObservability\Tests\Integration\Live\Support\LiveState;
use DF\HorizonObservability\Tests\Support\RedisKeys;
use DF\HorizonObservability\Tests\TestCase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

/*
| Adversarial probes of the stream loop (T12 QA round 2): the tick-start timing of the round 1 fix, and the lease and
| the follower retry against the whole configurable `live.stream.interval` range (Appendix A': 0.5 to 10 s), with
| the lease lifetime of 5 s and the follower retry of 2 s of Addendum R4.6.
*/

pest()->group('redis');

beforeEach(function (): void {
    config()->set('horizon-observability.live.stream.grafana_url', 'http://grafana-qa2:3000');
    config()->set('horizon-observability.live.stream.token', 'glsa_qa2-token-0123456789');
    config()->set('horizon-observability.live.stream.id', 'probe');
    config()->set('horizon-observability.live.cache_ttl', 0);
    $this->app->forgetInstance(Config::class);

    LiveState::horizon($this->app);
    LiveState::queues($this->app);

    $this->pushes = [];
    // Seconds since the start, on the real clock unless a test swaps in a fake one.
    $started = hrtime(true) / 1e9;
    $this->seconds = static fn (): float => hrtime(true) / 1e9 - $started;
    Http::fake(function (Request $request) {
        $this->pushes[] = round(($this->seconds)(), 1);

        return Http::response('', 200);
    });
});

afterEach(function (): void {
    LiveState::freeze(null);
});

it('keeps the leader lease between ticks at an interval of 5.5 s (real clock, real lease expiry)', function (): void {
    config()->set('horizon-observability.live.stream.interval', 5.5);
    $this->app->forgetInstance(Config::class);
    $streamer = app(LiveStreamer::class);

    // ticks at 0 and 5.5 s; --max-time=5 ends the run after the second tick (--memory well above the whole suite's
    // usage, which passes 128 MB, so the run does not stop after the first tick)
    $streamer->run(static fn (): bool => false, 5, 4096, false);

    // Before T12 round 3 the lease (PX 5000) was renewed only at a tick start and before a push, so it expired 0.5 s
    // before the second tick: the leader "lost" it to nobody, pushed nothing in that tick and led again one tick
    // later, so a single replica pushed every 11 s and two replicas handed the lease back and forth. Now the leader
    // renews it every second while it waits.
    expect($this->pushes)->toHaveCount(2)
        ->and($streamer->lastOutcome())->toBe(LiveStreamer::PUSHED);
});

it('lets a follower retry every 2 s whatever the push interval, so a failover takes at most about 7 s', function (): void {
    config()->set('horizon-observability.live.stream.interval', 4.0);
    $this->app->forgetInstance(Config::class);
    $time = new StreamClock(LiveState::now());
    $this->seconds = $time->seconds(...);
    $lease = TestCase::redisPrefix().'horizon:'.(new Keys)->liveLeader();
    $expired = false;
    // The fake clock does not move Redis' clock: the leader's lease (renewed at 0 s) is deleted when it would
    // expire, at 5 s.
    $sleep = static function (float $seconds) use ($time, $lease, &$expired): void {
        $time->advance($seconds);

        if (! $expired && $time->seconds() >= 5.0) {
            RedisKeys::client()->del($lease);
            $expired = true;
        }
    };
    $leader = app()->make(LiveStreamer::class, ['clock' => $time->clock(), 'sleep' => $sleep]);
    $follower = app()->make(LiveStreamer::class, ['clock' => $time->clock(), 'sleep' => $sleep]);

    // 0 s: the leader pushes and is killed right after; from 0.1 s the follower runs its loop (ticks every 4 s).
    expect($leader->tick())->toBe(LiveStreamer::PUSHED);
    $time->advance(0.1);
    $follower->run(static fn (): bool => false, 9, 4096, false);

    // Addendum R4.6: "Followers retry every 2 s, so failover takes at most about 7 s" (5 s lease + 2 s retry). Before
    // T12 round 3 the follower retried only on its own ticks (0.1, 4.1, 8.1 s), so it took over at 8.1 s, and in
    // general up to 5 s + interval after the leader's last renew; now it retries at 0.1, 2.1, 4.1 and 6.1 s.
    expect($expired)->toBeTrue()
        ->and($this->pushes[0])->toBe(0.0)
        ->and($this->pushes[1] ?? null)->not->toBeNull()
        ->and($this->pushes[1])->toBeLessThanOrEqual(7.1);
});
