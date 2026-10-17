<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Live\Stream;

use Carbon\CarbonImmutable;
use Closure;
use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Horizon\Topology;
use DF\HorizonObservability\Live\LiveRedis;
use DF\HorizonObservability\Live\LiveScope;
use DF\HorizonObservability\Live\LiveSnapshot;
use DF\HorizonObservability\Live\LiveSnapshotCache;
use DF\HorizonObservability\Redis\Keys;
use DF\HorizonObservability\Redis\RedisClients;
use DF\HorizonObservability\Support\FailureReporter;
use RuntimeException;
use Throwable;

/**
 * The stream loop (Addendum R4.6): one tick every `live.stream.interval` seconds, no overlap.
 *
 * 1. **Leader election.** A follower tries `SET live:leader NX PX 5000` every {@see self::FOLLOWER_RETRY_SECONDS},
 *    so a failover takes at most about 7 s. The leader renews the lease at the start of every tick and before every
 *    push; a failed renew (the lease expired during a long tick, or was taken over) stops the tick at once and makes
 *    the process a follower, so a leader that lost its lease pushes nothing more.
 * 2. **Snapshot** of the full scope, built anew through {@see LiveSnapshotCache::refresh()}, which also keeps the
 *    copy fresh for poll requests of the full scope.
 * 3. **Delayed window**: when its newest point is 5 s old, `LPUSH live:window:delayed "<unix_ms>:<value>"`,
 *    `LTRIM 0 178`, 1-hour TTL, so a new leader continues the window.
 * 4. **Push** of {@see LineProtocol::encode()} (or {@see LineProtocol::unavailable()} when the snapshot has no live
 *    data) to every configured Grafana URL that is not backing off.
 * 5. **Bookkeeping**: a successful push sets `meta.live_push_at`; every failed push increments
 *    `meta.live_push_failures` and logs a throttled line with the status hint and the payload size. Each URL backs off
 *    1, 2, 4, 8, then 15 s, or 60 s after a 401 or 403; while backing off the leader keeps renewing but does not
 *    push. After {@see self::RELEASE_AFTER_FAILED_TICKS} consecutive ticks in which no push succeeded the leader
 *    releases the lease and waits {@see self::RELEASE_PAUSE_SECONDS} before competing again, so a replica with a
 *    working route to Grafana can take over.
 * 6. **Storage Redis down**: the lease cannot be taken. The process that led when it lost Redis (or one that never
 *    reached it since it started) pushes the idempotent "unavailable" frame at most every
 *    {@see self::UNAVAILABLE_EVERY_SECONDS} during the first {@see self::UNAVAILABLE_WINDOW_SECONDS}; Grafana keeps the
 *    last frame, so the tiles read "Live unavailable" until a leader pushes again. A follower cut off from Redis
 *    alone stays silent, so it cannot interleave the frame with a healthy leader's pushes.
 *
 * {@see self::run()} stops between ticks when `$shouldStop` returns true (signals), after `$maxSeconds` (0 = never)
 * or above `$memoryMb`, and releases the lease on the way out.
 *
 * @internal
 */
final class LiveStreamer
{
    public const FOLLOWER_RETRY_SECONDS = 2.0;

    public const RELEASE_AFTER_FAILED_TICKS = 5;

    public const RELEASE_PAUSE_SECONDS = 10.0;

    public const UNAVAILABLE_EVERY_SECONDS = 5.0;

    /**
     * The "unavailable" frame is pushed during this long of an outage (the lease lifetime plus the follower retry:
     * by then a replica that still reaches Redis has taken over, or nobody pushes and Grafana keeps the frame).
     */
    public const UNAVAILABLE_WINDOW_SECONDS = 7.0;

    public const WINDOW_STEP_MS = 5000;

    public const WINDOW_TTL_MS = 3600000;

    /**
     * Backoff after the 1st, 2nd, ... consecutive failed push of a URL; the last value repeats.
     */
    public const BACKOFF_SECONDS = [1, 2, 4, 8, 15];

    /**
     * Backoff after a 401 or 403 (token or role): retrying sooner cannot help.
     */
    public const AUTH_BACKOFF_SECONDS = 60;

    /**
     * Tick outcomes.
     */
    public const PUSHED = 'pushed';

    public const FAILED = 'failed';

    public const BACKING_OFF = 'backing off';

    public const FOLLOWER = 'follower';

    public const LOST = 'lost';

    public const UNAVAILABLE = 'unavailable';

    /**
     * @var Closure(): float
     */
    private readonly Closure $clock;

    /**
     * @var Closure(float): void
     */
    private readonly Closure $sleep;

    private bool $leader = false;

    /**
     * Monotonic time from which a follower tries to take the lease again.
     */
    private float $nextAttemptAt = 0.0;

    private int $failedTicks = 0;

    /**
     * @var array<string, array{failures: int, until: float}> URL => backoff state
     */
    private array $urls = [];

    /**
     * @var list<array{tile: string, key: string}> the tile rows of the last successful push
     */
    private array $lastRows = [];

    private ?float $lastUnavailableAt = null;

    /**
     * Whether a lease command reached the storage Redis since the process started.
     */
    private bool $reachedRedis = false;

    /**
     * Monotonic start of the current storage outage, null while Redis answers.
     */
    private ?float $outageSince = null;

    private bool $outageAsLeader = false;

    private bool $outageMayPush = false;

    private ?string $lastOutcome = null;

    private bool $shutdownRegistered = false;

    /**
     * @var list<Closure(string, string): void>
     */
    private array $listeners = [];

    /**
     * @param  (Closure(): float)|null  $clock  monotonic seconds, for tests; default `hrtime()`
     * @param  (Closure(float): void)|null  $sleep  sleeps the given seconds, for tests; default `usleep()`
     */
    public function __construct(
        private readonly Config $config,
        private readonly LiveSnapshotCache $cache,
        private readonly Topology $topology,
        private readonly LeaderLock $lock,
        private readonly GrafanaLivePusher $pusher,
        private readonly RedisClients $clients,
        private readonly Keys $keys,
        private readonly FailureReporter $reporter,
        ?Closure $clock = null,
        ?Closure $sleep = null,
    ) {
        $this->clock = $clock ?? static fn (): float => hrtime(true) / 1e9;
        $this->sleep = $sleep ?? static function (float $seconds): void {
            usleep((int) round($seconds * 1e6));
        };
    }

    /**
     * Receive the loop's events (`leader`, `follower`, `pushed`, `push-failed`, `released`, `unavailable`, `exit`)
     * with a one-line English message, e.g. for the command's output.
     *
     * @param  Closure(string, string): void  $listener
     */
    public function listen(Closure $listener): void
    {
        $this->listeners[] = $listener;
    }

    /**
     * @param  Closure(): bool  $shouldStop  checked between ticks (signals)
     * @param  int  $maxSeconds  exit after this many seconds; 0 = never
     * @param  int  $memoryMb  exit when the process uses more memory
     * @param  bool  $once  one tick: 0 when it pushed, 1 otherwise
     * @return int the command's exit code
     */
    public function run(Closure $shouldStop, int $maxSeconds, int $memoryMb, bool $once): int
    {
        $started = ($this->clock)();
        $interval = $this->config->liveStreamInterval;

        if (! $this->shutdownRegistered) {
            // Another signal handler may exit() the process (testbench's console does on SIGTERM), which skips the
            // finally below; a shutdown function still gives the lease up instead of leaving it for 5 s.
            register_shutdown_function(function (): void {
                $this->stop();
            });
            $this->shutdownRegistered = true;
        }

        try {
            while (true) {
                $tickStarted = ($this->clock)();
                $this->lastOutcome = $this->tick();

                if ($once) {
                    return $this->lastOutcome === self::PUSHED ? 0 : 1;
                }

                if ($shouldStop()) {
                    $this->emit('exit', 'Stopping on a signal.');

                    return 0;
                }

                if ($maxSeconds > 0 && ($this->clock)() - $started >= $maxSeconds) {
                    $this->emit('exit', sprintf('Stopping after --max-time=%d seconds.', $maxSeconds));

                    return 0;
                }

                if (memory_get_usage(true) > $memoryMb * 1048576) {
                    $this->emit('exit', sprintf('Stopping above --memory=%d MB.', $memoryMb));

                    return 0;
                }

                $this->pause($tickStarted + $interval, $shouldStop);

                if ($shouldStop()) {
                    $this->emit('exit', 'Stopping on a signal.');

                    return 0;
                }
            }
        } finally {
            $this->stop();
        }
    }

    /**
     * One tick of the loop; the outcome is one of the class constants.
     */
    public function tick(): string
    {
        $now = ($this->clock)();

        if (! $this->leader) {
            if ($now < $this->nextAttemptAt) {
                return self::FOLLOWER;
            }

            try {
                $this->leader = $this->lock->acquire();
            } catch (Throwable $e) {
                $this->reporter->reportLocally('live.leader', $e);
                $this->nextAttemptAt = $now + self::FOLLOWER_RETRY_SECONDS;

                return $this->outage($now);
            }

            $this->redisReached();

            if (! $this->leader) {
                $this->nextAttemptAt = $now + self::FOLLOWER_RETRY_SECONDS;

                return self::FOLLOWER;
            }

            $this->urls = [];
            $this->failedTicks = 0;
            $this->emit('leader', sprintf('Became the stream leader: pushing to %d Grafana URL(s) as stream %s.', count($this->config->liveGrafanaUrls), $this->config->liveStreamId));
        } elseif (! $this->renew()) {
            return self::LOST;
        }

        $due = array_values(array_filter(
            $this->config->liveGrafanaUrls,
            fn (string $url): bool => ($this->urls[$url]['until'] ?? 0.0) <= $now,
        ));

        if ($due === []) {
            return self::BACKING_OFF;
        }

        $snapshot = $this->cache->refresh(LiveScope::full($this->topology));
        $wall = CarbonImmutable::now();
        $nowMs = (int) floor((float) $wall->format('U.u') * 1000);
        $timeNs = $nowMs * LineProtocol::ROW_SPACING_NS;
        $body = $snapshot->isAvailable()
            ? LineProtocol::encode($snapshot, $timeNs, $this->delayedWindow($snapshot, $nowMs))
            : LineProtocol::unavailable($timeNs, $this->lastRows);
        $pushed = false;

        foreach ($due as $url) {
            if (! $this->renew()) {
                return self::LOST;
            }

            $result = $this->pusher->push($url, $this->config->liveStreamId, (string) $this->config->liveToken, $body);

            if ($result->ok) {
                if (($this->urls[$url]['failures'] ?? 0) > 0) {
                    $this->emit('pushed', sprintf('Pushing to %s again.', GrafanaLivePusher::display($url)));
                }

                unset($this->urls[$url]);
                $pushed = true;

                continue;
            }

            $this->failed($url, $result, strlen($body));
        }

        if ($pushed) {
            $this->failedTicks = 0;
            $this->meta('hset', ['live_push_at', sprintf('%.3F', (float) $wall->format('U.u'))]);

            if ($snapshot->isAvailable()) {
                $this->lastRows = array_map(static fn (array $row): array => ['tile' => $row['tile'], 'key' => $row['key']], $snapshot->tiles());
            }

            return self::PUSHED;
        }

        if (++$this->failedTicks >= self::RELEASE_AFTER_FAILED_TICKS) {
            $this->releaseLease();
            $this->nextAttemptAt = ($this->clock)() + self::RELEASE_PAUSE_SECONDS;
            $this->emit('released', sprintf(
                'Released the leader lease after %d failed ticks; competing again in %.0f s.',
                self::RELEASE_AFTER_FAILED_TICKS,
                self::RELEASE_PAUSE_SECONDS,
            ));
        }

        return self::FAILED;
    }

    /**
     * The outcome of the last tick {@see self::run()} made (a class constant), or null before the first.
     */
    public function lastOutcome(): ?string
    {
        return $this->lastOutcome;
    }

    /**
     * Whether this process holds the lease (as far as it knows).
     */
    public function isLeader(): bool
    {
        return $this->leader;
    }

    /**
     * Seconds of backoff after the given consecutive failure of a URL.
     */
    public static function backoff(int $failures, ?int $status): int
    {
        if ($status === 401 || $status === 403) {
            return self::AUTH_BACKOFF_SECONDS;
        }

        return self::BACKOFF_SECONDS[min(max(1, $failures), count(self::BACKOFF_SECONDS)) - 1];
    }

    /**
     * Release the lease if this process holds it (shutdown).
     */
    public function stop(): void
    {
        if ($this->leader) {
            $this->releaseLease();
        }
    }

    private function renew(): bool
    {
        try {
            $renewed = $this->lock->renew();
            $this->redisReached();

            if ($renewed) {
                return true;
            }

            $this->emit('follower', 'Lost the leader lease (it expired or another process took it over); following.');
        } catch (Throwable $e) {
            // The leader lost the storage Redis: it was feeding the tiles, so it may tell the viewers (outage()).
            $this->outageAsLeader = true;
            $this->reporter->reportLocally('live.leader', $e);
            $this->emit('follower', 'Could not renew the leader lease: '.FailureReporter::describe($e));
        }

        $this->leader = false;
        $this->nextAttemptAt = ($this->clock)() + self::FOLLOWER_RETRY_SECONDS;

        return false;
    }

    private function releaseLease(): void
    {
        $this->leader = false;
        $this->urls = [];
        $this->failedTicks = 0;

        try {
            $this->lock->release();
        } catch (Throwable $e) {
            // It expires within 5 s anyway.
            $this->reporter->reportLocally('live.leader', $e);
        }
    }

    private function failed(string $url, PushResult $result, int $bytes): void
    {
        $failures = ($this->urls[$url]['failures'] ?? 0) + 1;
        $backoff = self::backoff($failures, $result->status);
        $this->urls[$url] = ['failures' => $failures, 'until' => ($this->clock)() + $backoff];
        $this->meta('hincrby', ['live_push_failures', 1]);

        $answer = $result->status === null ? 'no answer' : 'HTTP '.$result->status;
        $reason = sprintf('%s (%s); retrying in %d s', $result->error ?? $answer, GrafanaLivePusher::hint($result->status), $backoff);
        // Throttled per URL and answer: a changed answer (500, then 401) is logged at once.
        $this->reporter->reportLocally(sprintf('live push to %s (%s)', GrafanaLivePusher::display($url), $answer), new RuntimeException($reason), ['bytes' => $bytes, 'status' => $result->status]);
        $this->emit('push-failed', sprintf('The push to %s failed: %s.', GrafanaLivePusher::display($url), $reason));
    }

    /**
     * The storage Redis answered (a lease command did not throw): any outage is over.
     */
    private function redisReached(): void
    {
        $this->reachedRedis = true;
        $this->outageSince = null;
        $this->outageAsLeader = false;
    }

    /**
     * The lease cannot be taken because the storage Redis cannot be reached: push the idempotent "unavailable"
     * frame to every URL without a lease, at most every 5 s, during the first {@see self::UNAVAILABLE_WINDOW_SECONDS}
     * of the outage, and only from a process that was the leader when it lost Redis or never reached it since it
     * started. Grafana keeps the last frame of a channel for every viewer, so the tiles keep reading "Live
     * unavailable" until a leader pushes again; a follower cut off from Redis alone stays silent instead of
     * interleaving the frame with a healthy leader's pushes.
     */
    private function outage(float $now): string
    {
        if ($this->outageSince === null) {
            $this->outageSince = $now;
            $this->outageMayPush = $this->outageAsLeader || ! $this->reachedRedis;
        }

        if (! $this->outageMayPush || $now - $this->outageSince >= self::UNAVAILABLE_WINDOW_SECONDS) {
            return self::UNAVAILABLE;
        }

        if ($this->lastUnavailableAt !== null && $now - $this->lastUnavailableAt < self::UNAVAILABLE_EVERY_SECONDS) {
            return self::UNAVAILABLE;
        }

        $this->lastUnavailableAt = $now;
        $body = LineProtocol::unavailable((int) floor((float) CarbonImmutable::now()->format('U.u') * 1000) * LineProtocol::ROW_SPACING_NS, $this->lastRows);

        foreach ($this->config->liveGrafanaUrls as $url) {
            $this->pusher->push($url, $this->config->liveStreamId, (string) $this->config->liveToken, $body);
        }

        $this->emit('unavailable', 'The storage Redis cannot be reached: pushed the "Live unavailable" frame.');

        return self::UNAVAILABLE;
    }

    /**
     * The delayed window, ascending, after adding the current value when the newest point is 5 s old.
     *
     * @return list<array{0: int, 1: float}>
     */
    private function delayedWindow(LiveSnapshot $snapshot, int $nowMs): array
    {
        try {
            $redis = $this->clients->storage();
            $key = $this->keys->liveDelayedWindow();
            $reply = LiveRedis::command($redis, 'lrange', [$key, 0, LineProtocol::WINDOW_POINTS - 1]);
            $points = [];

            foreach (is_array($reply) ? $reply : [] as $entry) {
                if (is_string($entry) && preg_match('/^(\d+):(-?\d+(?:\.\d+)?)$/', $entry, $match) === 1) {
                    $points[(int) $match[1]] = (float) $match[2];
                }
            }

            $newest = $points === [] ? null : max(array_keys($points));
            $value = $snapshot->delayed['value'];

            if ($value !== null && ($newest === null || $nowMs - $newest >= self::WINDOW_STEP_MS)) {
                LiveRedis::command($redis, 'lpush', [$key, $nowMs.':'.$value]);
                LiveRedis::command($redis, 'ltrim', [$key, 0, LineProtocol::WINDOW_POINTS - 1]);
                LiveRedis::command($redis, 'pexpire', [$key, self::WINDOW_TTL_MS]);
                $points[$nowMs] = (float) $value;
            }

            ksort($points);
            $window = [];

            foreach ($points as $unixMs => $pointValue) {
                $window[] = [$unixMs, $pointValue];
            }

            return $window;
        } catch (Throwable $e) {
            $this->reporter->reportLocally('live.window', $e);

            return [];
        }
    }

    /**
     * @param  'hset'|'hincrby'  $method
     * @param  array{0: string, 1: string|int}  $arguments
     */
    private function meta(string $method, array $arguments): void
    {
        try {
            LiveRedis::command($this->clients->storage(), $method, [$this->keys->meta(), ...$arguments]);
        } catch (Throwable $e) {
            $this->reporter->reportLocally('live.meta', $e);
        }
    }

    /**
     * Sleep until the monotonic time `$until` in slices of at most 0.1 s, so a signal stops the wait.
     *
     * @param  Closure(): bool  $shouldStop
     */
    private function pause(float $until, Closure $shouldStop): void
    {
        while (! $shouldStop() && ($remaining = $until - ($this->clock)()) > 0.0005) {
            ($this->sleep)(min($remaining, 0.1));
        }
    }

    private function emit(string $event, string $message): void
    {
        foreach ($this->listeners as $listener) {
            $listener($event, $message);
        }
    }
}
