<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Live\Stream\LineProtocol;
use DF\HorizonObservability\Live\Stream\LiveStreamer;
use DF\HorizonObservability\Redis\Keys;
use DF\HorizonObservability\Redis\RedisClients;
use DF\HorizonObservability\Tests\Integration\Live\Stream\Support\StreamClock;
use DF\HorizonObservability\Tests\Integration\Live\Support\LiveState;
use DF\HorizonObservability\Tests\Support\LogRecorder;
use DF\HorizonObservability\Tests\Support\RedisCommands;
use DF\HorizonObservability\Tests\Support\RedisKeys;
use DF\HorizonObservability\Tests\TestCase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Redis\Events\CommandExecuted;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;

/*
| The stream loop (Addendum R4.6, R4.10) on a fake clock against a fake Grafana (Http::fake): the push and its
| bookkeeping, the per-URL backoff for 200, 401, 403, 500 and timeouts, the release after 5 failed ticks, one pusher
| among two streamers, a leader that lost its lease pushes nothing, the delayed window and the "unavailable" frame.
*/

pest()->group('redis');

const STREAM_URL = 'http://grafana-a:3000';
const STREAM_TOKEN = 'glsa_stream-token-0123456789';

beforeEach(function (): void {
    config()->set('horizon-observability.live.stream.grafana_url', STREAM_URL);
    config()->set('horizon-observability.live.stream.token', STREAM_TOKEN);
    config()->set('horizon-observability.live.stream.id', 'probe');
    config()->set('horizon-observability.live.cache_ttl', 0);
    $this->app->forgetInstance(Config::class);

    $this->time = new StreamClock(LiveState::now());
    LiveState::horizon($this->app);
    LiveState::queues($this->app);

    $this->pushes = [];
    $this->answers = [];
    $this->onPush = null;
    Http::fake(function (Request $request) {
        $this->pushes[] = ['at' => $this->time->seconds(), 'url' => $request->url(), 'body' => $request->body()];

        if ($this->onPush instanceof Closure) {
            ($this->onPush)($request);
        }

        $answer = array_shift($this->answers) ?? 200;

        if ($answer === 'timeout') {
            throw new ConnectionException('cURL error 28: Operation timed out after 2000 milliseconds');
        }

        return Http::response($answer === 200 ? '' : '{"message":"refused"}', $answer);
    });

    $this->raw = static fn (string $key): string => TestCase::redisPrefix().'horizon:'.$key;
    $this->keys = new Keys;
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

function liveStreamer(): LiveStreamer
{
    $time = test()->time;

    return app()->make(LiveStreamer::class, ['clock' => $time->clock(), 'sleep' => $time->sleep()]);
}

/**
 * @return array<string, string>
 */
function liveStreamMeta(): array
{
    $meta = RedisKeys::client()->hgetall(TestCase::redisPrefix().'horizon:'.(new Keys)->meta());

    return is_array($meta) ? $meta : [];
}

/**
 * @return list<float> push times in seconds since the start
 */
function liveStreamPushTimes(): array
{
    return array_column(test()->pushes, 'at');
}

it('becomes the leader, pushes the snapshot and records the push time', function (): void {
    $streamer = liveStreamer();

    expect($streamer->tick())->toBe(LiveStreamer::PUSHED)
        ->and($streamer->isLeader())->toBeTrue()
        ->and($this->pushes)->toHaveCount(1)
        ->and($this->pushes[0]['url'])->toBe('http://grafana-a:3000/api/live/push/probe')
        ->and($this->pushes[0]['body'])->toStartWith('tiles tile="pending",key="",caption="Oldest: 17 s '."\u{00B7}".' default",value=5.0,color="yellow",level=1.0 1792201716123000000')
        ->and($this->pushes[0]['body'])->toContain("\ndelayed value=2.0 1792201716123000000\n")
        ->and(liveStreamMeta()['live_push_at'] ?? null)->toBe('1792201716.123')
        ->and(RedisKeys::client()->get(($this->raw)($this->keys->liveLeader())))->toContain(':'.getmypid().':');

    Http::assertSent(static fn (Request $request): bool => $request->header('Authorization') === ['Bearer '.STREAM_TOKEN]);
});

it('backs off 1, 2, 4, 8 s, releases after 5 failed ticks and competes again 10 s later', function (): void {
    $this->answers = [500, 500, 'timeout', 500, 500];
    $logs = new LogRecorder;
    $this->app->instance('log', $logs);
    $streamer = liveStreamer();

    $streamer->run(static fn (): bool => false, 25, 4096, false);

    expect(liveStreamPushTimes())->toBe([0.0, 1.0, 3.0, 7.0, 15.0, 25.0])
        ->and(liveStreamMeta()['live_push_failures'] ?? null)->toBe('5')
        ->and((float) (liveStreamMeta()['live_push_at'] ?? 0))->toBe(1792201716.123 + 25)
        ->and(implode("\n", $logs->messages('warning')))->toContain('live push to http://grafana-a:3000 (HTTP 500) failed: RuntimeException: HTTP 500: {"message":"refused"} (Grafana rejected or failed to process the push); retrying in 1 s')
        ->and(implode("\n", $logs->messages('warning')))->toContain('live push to http://grafana-a:3000 (no answer) failed: RuntimeException: ConnectionException: cURL error 28')
        ->and(implode("\n", $logs->messages('warning')))->not->toContain(STREAM_TOKEN);
});

it('backs off 60 s after a 401 or a 403', function (int $status): void {
    $this->answers = [$status, $status, $status, $status, $status];

    liveStreamer()->run(static fn (): bool => false, 250, 4096, false);

    expect(liveStreamPushTimes())->toBe([0.0, 60.0, 120.0, 180.0, 240.0, 250.0])
        ->and(liveStreamMeta()['live_push_failures'] ?? null)->toBe('5');
})->with([401, 403]);

it('counts each backoff from the start of the failed tick, so a slow push still retries on time', function (int|string $answer, array $times): void {
    // Each refused push costs 300 ms, as it does against a real Grafana (QA round 1: the retries came one tick late).
    $this->answers = array_fill(0, 5, $answer);
    $this->onPush = fn (): mixed => $this->time->advance(0.3);

    liveStreamer()->run(static fn (): bool => false, 25, 4096, false);

    expect(liveStreamPushTimes())->toBe($times)
        ->and(liveStreamMeta()['live_push_failures'] ?? null)->toBe('5');
})->with([
    // 1, 2, 4, 8 s; released at the 5th failed tick (15 s) and leading again 10 s later
    'HTTP 500' => [500, [0.0, 1.0, 3.0, 7.0, 15.0, 25.0]],
    'timeout' => ['timeout', [0.0, 1.0, 3.0, 7.0, 15.0, 25.0]],
]);

it('retries a 401 after exactly 60 s, not 61 s, when the push takes time', function (): void {
    $this->answers = [401];
    $this->onPush = fn (): mixed => $this->time->advance(0.3);

    liveStreamer()->run(static fn (): bool => false, 61, 4096, false);

    expect(array_slice(liveStreamPushTimes(), 0, 2))->toBe([0.0, 60.0]);
});

it('releases the lease after the 5th failed tick so another replica can take over', function (): void {
    $this->answers = [500, 500, 500, 500, 500];
    $streamer = liveStreamer();

    foreach ([0, 1, 3, 7] as $at) {
        $this->time->advance($at - $this->time->seconds());
        expect($streamer->tick())->toBe(LiveStreamer::FAILED);
    }

    $this->time->advance(15 - $this->time->seconds());

    expect($streamer->tick())->toBe(LiveStreamer::FAILED)
        ->and($streamer->isLeader())->toBeFalse()
        ->and(RedisKeys::client()->exists(($this->raw)($this->keys->liveLeader())))->toBe(0);
});

it('keeps renewing while it backs off, without pushing', function (): void {
    $this->answers = [500];
    $streamer = liveStreamer();
    $streamer->tick();
    RedisKeys::client()->pexpire(($this->raw)($this->keys->liveLeader()), 1000);
    $this->time->advance(0.5);

    expect($streamer->tick())->toBe(LiveStreamer::BACKING_OFF)
        ->and($this->pushes)->toHaveCount(1)
        ->and(RedisKeys::client()->pttl(($this->raw)($this->keys->liveLeader())))->toBeGreaterThan(4000);
});

it('makes exactly one of two streamers on one Redis push', function (): void {
    $a = liveStreamer();
    $b = liveStreamer();
    $outcomes = ['a' => [], 'b' => []];

    foreach (range(1, 8) as $ignored) {
        $outcomes['a'][] = $a->tick();
        $outcomes['b'][] = $b->tick();
        $this->time->advance(1.0);
    }

    expect(array_unique($outcomes['a']))->toBe([LiveStreamer::PUSHED])
        ->and(array_unique($outcomes['b']))->toBe([LiveStreamer::FOLLOWER])
        ->and($this->pushes)->toHaveCount(8);
});

it('pushes nothing once its lease was taken over', function (): void {
    $a = liveStreamer();
    $b = liveStreamer();
    $a->tick();
    $b->tick();

    // A stalls past its lease; B takes it at its next retry.
    RedisKeys::client()->del(($this->raw)($this->keys->liveLeader()));
    $this->time->advance(2.0);

    expect($b->tick())->toBe(LiveStreamer::PUSHED)
        ->and($a->tick())->toBe(LiveStreamer::LOST)
        ->and($a->isLeader())->toBeFalse()
        ->and($this->pushes)->toHaveCount(2)
        ->and(RedisKeys::client()->get(($this->raw)($this->keys->liveLeader())))->not->toBeNull();

    $this->time->advance(2.0);

    expect($a->tick())->toBe(LiveStreamer::FOLLOWER)
        ->and($this->pushes)->toHaveCount(2);
});

it('renews before every push and stops when the lease is gone', function (): void {
    config()->set('horizon-observability.live.stream.grafana_url', 'http://grafana-a:3000, http://grafana-b:3000');
    $this->app->forgetInstance(Config::class);
    $streamer = liveStreamer();
    $streamer->tick();
    $this->pushes = [];
    // While the first push of the next tick is in flight, another process takes the lease.
    $this->onPush = function (): void {
        RedisKeys::client()->set(($this->raw)($this->keys->liveLeader()), 'other-host:1:0000');
    };
    $this->time->advance(1.0);

    expect($streamer->tick())->toBe(LiveStreamer::LOST)
        ->and(array_column($this->pushes, 'url'))->toBe(['http://grafana-a:3000/api/live/push/probe']);
});

it('backs off per URL and counts a tick as failed only when no URL took the push', function (): void {
    config()->set('horizon-observability.live.stream.grafana_url', 'http://grafana-a:3000, http://grafana-b:3000');
    $this->app->forgetInstance(Config::class);
    $this->onPush = function (Request $request): void {
        if (str_contains($request->url(), 'grafana-b')) {
            throw new ConnectionException('cURL error 7: Failed to connect');
        }
    };

    liveStreamer()->run(static fn (): bool => false, 8, 4096, false);

    $byUrl = [];

    foreach ($this->pushes as $push) {
        $byUrl[parse_url($push['url'], PHP_URL_HOST)][] = $push['at'];
    }

    expect($byUrl['grafana-a'])->toBe([0.0, 1.0, 2.0, 3.0, 4.0, 5.0, 6.0, 7.0, 8.0])
        ->and($byUrl['grafana-b'])->toBe([0.0, 1.0, 3.0, 7.0])
        ->and(liveStreamMeta()['live_push_failures'])->toBe('4');
});

it('continues the delayed window every 5 s with a 1-hour TTL', function (): void {
    $streamer = liveStreamer();

    $streamer->run(static fn (): bool => false, 12, 4096, false);

    $window = RedisKeys::client()->lrange(($this->raw)($this->keys->liveDelayedWindow()), 0, -1);
    $last = end($this->pushes)['body'];

    expect($window)->toBe(['1792201726123:2', '1792201721123:2', '1792201716123:2'])
        ->and(RedisKeys::client()->pttl(($this->raw)($this->keys->liveDelayedWindow())))->toBeGreaterThan(3500000)
        // the points before the push, then the current value at the push time
        ->and($last)->toEndWith("\ndelayed value=2.0 1792201716123000000\ndelayed value=2.0 1792201721123000000\ndelayed value=2.0 1792201726123000000\ndelayed value=2.0 1792201728123000000\n");
});

it('stamps the delayed window points 5 s apart however long a build takes', function (): void {
    // The first build takes 400 ms, the others none: points stamped after the build would come 5.6 s apart.
    $slow = true;
    RedisCommands::start($this->app);
    Event::listen(CommandExecuted::class, function (CommandExecuted $event) use (&$slow): void {
        if ($slow && strtoupper($event->command) === 'EVAL') {
            $slow = false;
            $this->time->advance(0.4);
        }
    });

    liveStreamer()->run(static fn (): bool => false, 12, 4096, false);

    expect($slow)->toBeFalse()
        ->and(RedisKeys::client()->lrange(($this->raw)($this->keys->liveDelayedWindow()), 0, -1))->toBe(['1792201726123:2', '1792201721123:2', '1792201716123:2'])
        ->and(liveStreamPushTimes())->toBe([0.4, 1.0, 2.0, 3.0, 4.0, 5.0, 6.0, 7.0, 8.0, 9.0, 10.0, 11.0, 12.0])
        // every push carries the tick start as its time (the snapshot of that tick)
        ->and($this->pushes[0]['body'])->toStartWith('tiles tile="pending",key="",caption="Oldest: 17 s');
});

it('keeps at most 179 window points', function (): void {
    $key = ($this->raw)($this->keys->liveDelayedWindow());
    $start = 1792201716123 - 300 * 5000;

    foreach (range(0, 299) as $i) {
        RedisKeys::client()->lpush($key, [($start + $i * 5000).':7']);
    }

    liveStreamer()->tick();

    $body = $this->pushes[0]['body'];

    expect(RedisKeys::client()->llen($key))->toBe(LineProtocol::WINDOW_POINTS)
        // 179 window points (the one just stored is the current value) plus the current value
        ->and(substr_count($body, "\ndelayed "))->toBe(LineProtocol::WINDOW_POINTS + 1);
});

it('pushes the unavailable frame every 5 s during the first 7 s of an outage from a process that never reached Redis', function (): void {
    config()->set('database.redis.dead', ['host' => '127.0.0.1', 'port' => 1, 'database' => 0, 'timeout' => 0.2]);
    config()->set('horizon-observability.redis.connection', 'dead');
    $this->app->forgetInstance(Config::class);
    $this->app->forgetInstance(RedisClients::class);

    liveStreamer()->run(static fn (): bool => false, 20, 4096, false);

    // follower retries at 0, 2, 4, 6, ... s; frames at 0 and 6 s; none after the 7 s window (Grafana keeps the last)
    expect(liveStreamPushTimes())->toBe([0.0, 6.0])
        ->and($this->pushes[0]['body'])->toBe('tiles tile="pending",key="",caption="Live unavailable",value=-1.0,color="text",level=0.0 1792201716123000000'."\n");
});

it('lets the leader that lost Redis tell the viewers with its last tiles, and keeps a follower silent', function (): void {
    $leader = liveStreamer();
    $follower = liveStreamer();
    $leader->tick();
    $follower->tick();
    $this->pushes = [];
    // A Redis error for every lease command: the leader key holds a list (WRONGTYPE).
    RedisKeys::client()->del(($this->raw)($this->keys->liveLeader()));
    RedisKeys::client()->rpush(($this->raw)($this->keys->liveLeader()), ['broken']);
    $outcomes = [];

    foreach (range(1, 12) as $ignored) {
        $this->time->advance(1.0);
        $outcomes[] = $leader->tick().'/'.$follower->tick();
    }

    $frames = array_map(static fn (array $push): string => $push['body'], $this->pushes);

    // the leader: lease lost at 1 s, then the frame at 3 and 9 s (every 5 s, within 7 s of the outage);
    // the follower, which had reached Redis, never pushes
    expect(array_slice($outcomes, 0, 3))->toBe(['lost/follower', 'follower/unavailable', 'unavailable/follower'])
        ->and(liveStreamPushTimes())->toBe([3.0, 9.0])
        ->and(substr_count($frames[0], 'caption="Live unavailable"'))->toBe(6)
        ->and($frames[0])->toContain('tiles tile="supervisor",key="supervisor-default",caption="Live unavailable",value=-1.0,color="text",level=0.0');
});

it('stops on the signal flag and releases the lease', function (): void {
    $ticks = 0;
    $streamer = liveStreamer();
    $streamer->listen(static function (string $event) use (&$ticks): void {
        $ticks += $event === 'exit' ? 100 : 0;
    });

    $code = $streamer->run(fn (): bool => count($this->pushes) >= 3, 0, 4096, false);

    expect($code)->toBe(0)
        ->and($this->pushes)->toHaveCount(3)
        ->and($ticks)->toBe(100)
        ->and(RedisKeys::client()->exists(($this->raw)($this->keys->liveLeader())))->toBe(0);
});

it('stops after --max-time and above --memory', function (): void {
    expect(liveStreamer()->run(static fn (): bool => false, 3, 4096, false))->toBe(0)
        ->and(liveStreamPushTimes())->toBe([0.0, 1.0, 2.0, 3.0]);

    $this->pushes = [];

    expect(liveStreamer()->run(static fn (): bool => false, 0, 1, false))->toBe(0)
        ->and($this->pushes)->toHaveCount(1);
});

it('answers --once with 0 after a push and 1 otherwise', function (): void {
    expect(liveStreamer()->run(static fn (): bool => false, 0, 4096, true))->toBe(0)
        ->and(RedisKeys::client()->exists(($this->raw)($this->keys->liveLeader())))->toBe(0);

    $this->answers = [500];

    expect(liveStreamer()->run(static fn (): bool => false, 0, 4096, true))->toBe(1);

    RedisKeys::client()->set(($this->raw)($this->keys->liveLeader()), 'other-host:1:0000');
    $follower = liveStreamer();

    expect($follower->run(static fn (): bool => false, 0, 4096, true))->toBe(1)
        ->and($follower->lastOutcome())->toBe(LiveStreamer::FOLLOWER)
        ->and(RedisKeys::client()->get(($this->raw)($this->keys->liveLeader())))->toBe('other-host:1:0000');
});

it('computes the backoff from the consecutive failures and the status', function (int $failures, ?int $status, int $seconds): void {
    expect(LiveStreamer::backoff($failures, $status))->toBe($seconds);
})->with([
    [1, 500, 1], [2, null, 2], [3, 500, 4], [4, 502, 8], [5, 500, 15], [9, 500, 15],
    [1, 401, 60], [1, 403, 60], [7, 403, 60],
]);
