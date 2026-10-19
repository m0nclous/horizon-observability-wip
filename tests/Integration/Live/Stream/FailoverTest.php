<?php

declare(strict_types=1);

use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Redis\Keys;
use DF\HorizonObservability\Tests\Integration\Live\Support\LiveState;
use DF\HorizonObservability\Tests\Integration\Live\Support\Processes;
use DF\HorizonObservability\Tests\Support\RedisKeys;
use DF\HorizonObservability\Tests\TestCase;
use Illuminate\Console\Signals;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;

/*
| Real stream processes on one Redis, in real time: two `horizon-observability:stream` processes elect one pusher;
| killing the leader (SIGKILL, no release) makes the follower push within 7 s (lease 5 s + retry 2 s); SIGTERM finishes
| the tick, releases the lease at once and exits 0.
*/

pest()->group('redis');

it('fails over within 7 s after the leader is killed and releases the lease on SIGTERM', function (): void {
    config()->set('horizon-observability.live.stream.grafana_url', 'http://grafana-a:3000');
    config()->set('horizon-observability.live.stream.token', 'glsa_stream-token-0123456789');
    config()->set('horizon-observability.live.stream.interval', 0.5);
    $this->app->forgetInstance(Config::class);
    LiveState::queues($this->app);

    $pushes = TestCase::redisPrefix().'failover-test:pushes';
    $exits = TestCase::redisPrefix().'failover-test:exits';
    $leaderKey = TestCase::redisPrefix().'horizon:'.(new Keys)->liveLeader();
    Http::fake(static function (Request $request) use ($pushes) {
        RedisKeys::client()->rpush($pushes, [json_encode([getmypid(), microtime(true)])]);

        return Http::response('', 200);
    });

    $children = Processes::fork(2, static function (int $index) use ($exits): void {
        // Laravel traps no signal while running unit tests; these children stand in for `php artisan` processes.
        Signals::resolveAvailabilityUsing(static fn (): bool => extension_loaded('pcntl'));
        // A forked child inherits the memory of the test process (over 128 MB late in the full suite), which would
        // end the stream on the default --memory=128 after its first tick.
        $code = Artisan::call('horizon-observability:stream', ['--max-time' => 40, '--memory' => 4096]);
        RedisKeys::client()->hset($exits, (string) getmypid(), (string) $code);

    });

    try {
        $leaderPid = awaitStreamLeader($leaderKey, 10.0);
        $followerPid = $children[0] === $leaderPid ? $children[1] : $children[0];

        expect($children)->toContain($leaderPid);
        awaitPushes($pushes, static fn (array $push): bool => $push[0] === $leaderPid, 2, 5.0);

        posix_kill($leaderPid, SIGKILL);
        $killedAt = microtime(true);
        $takeover = awaitPushes($pushes, static fn (array $push): bool => $push[0] === $followerPid && $push[1] > $killedAt, 1, 12.0);

        expect($takeover - $killedAt)->toBeLessThanOrEqual(7.0)
            ->and(awaitStreamLeader($leaderKey, 1.0))->toBe($followerPid);

        posix_kill($followerPid, SIGTERM);
        $terminatedAt = microtime(true);

        while (RedisKeys::client()->exists($leaderKey) === 1 && microtime(true) - $terminatedAt < 3.0) {
            usleep(20000);
        }

        $released = microtime(true) - $terminatedAt;
        Processes::wait([$followerPid], 5.0);

        expect(RedisKeys::client()->exists($leaderKey))->toBe(0)
            ->and($released)->toBeLessThan(2.0)
            ->and(RedisKeys::client()->hget($exits, (string) $followerPid))->toBe('0');
    } finally {
        foreach ($children as $pid) {
            posix_kill($pid, SIGKILL);
        }

        Processes::wait($children, 5.0);
    }
});

/**
 * Wait for the leader lease; the pid in its token (`host:pid:rand`).
 */
function awaitStreamLeader(string $key, float $timeout): int
{
    $deadline = microtime(true) + $timeout;

    while (microtime(true) < $deadline) {
        $token = RedisKeys::client()->get($key);

        if (is_string($token) && preg_match('/:(\d+):[0-9a-f]+$/', $token, $match) === 1) {
            return (int) $match[1];
        }

        usleep(20000);
    }

    throw new RuntimeException('No stream leader within '.$timeout.' s.');
}

/**
 * Wait until $count pushes match; the time of the last of them.
 *
 * @param  Closure(array{0: int, 1: float}): bool  $matches
 */
function awaitPushes(string $key, Closure $matches, int $count, float $timeout): float
{
    $deadline = microtime(true) + $timeout;

    while (microtime(true) < $deadline) {
        $found = array_values(array_filter(
            array_map(static fn (string $json): array => json_decode($json, true), RedisKeys::client()->lrange($key, 0, -1)),
            $matches,
        ));

        if (count($found) >= $count) {
            return (float) $found[$count - 1][1];
        }

        usleep(20000);
    }

    throw new RuntimeException(sprintf('Fewer than %d matching pushes within %.0f s.', $count, $timeout));
}
