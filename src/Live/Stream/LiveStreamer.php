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
 * 1. **Leader election.** A follower tries `SET live:leader NX PX 5000` every {@see self::FOLLOWER_RETRY_SECONDS}
 *    whatever the interval (its wait for the next tick ends early when the retry is due, and a tick runs at once), so
 *    a failover takes at most about 7 s. The leader renews the lease at the start of every tick, before every push
 *    and, while it waits for the next tick, at least every {@see self::KEEPALIVE_SECONDS}, so the lease never lapses
 *    between ticks at any interval (up to 10 s); a failed renew (the lease expired during a long tick, or was taken
 *    over) stops the tick at once and makes the process a follower, so a leader that lost its lease pushes nothing
 *    more.
 * 2. **Snapshot** of the full scope, built anew through {@see LiveSnapshotCache::refresh()}, which also keeps the
 *    copy fresh for poll requests of the full scope.
 * 3. **Delayed window**: when its newest point is 5 s old, `LPUSH live:window:delayed "<unix_ms>:<value>"`,
 *    `LTRIM 0 178`, 1-hour TTL, so a new leader continues the window. Points and pushes are stamped with the wall
 *    clock at the tick start, so the points are 5 s apart whatever the snapshot build took.
 * 4. **Push** of {@see LineProtocol::encode()} (or {@see LineProtocol::unavailable()} when the snapshot has no live
 *    data) to every configured Grafana URL that is not backing off.
 * 5. **Bookkeeping**: a successful push sets `meta.live_push_at`; every failed push increments
 *    `meta.live_push_failures` and logs a throttled line with the status hint and the payload size. Each URL backs off
 *    1, 2, 4, 8, then 15 s, or 60 s after a 401 or 403; while backing off the leader keeps renewing but does not
 *    push. After {@see self::RELEASE_AFTER_FAILED_TICKS} consecutive ticks in which no push succeeded the leader
 *    releases the lease and waits {@see self::RELEASE_PAUSE_SECONDS} before competing again, so a replica with a
 *    working route to Grafana can take over.
 *
 * Every wait (a backoff, the follower retry, the pause after a release, the "unavailable" cadence) counts from the
 * start of the tick that set it, not from the end of a push, and a tick that starts within
 * {@see self::DUE_TOLERANCE_SECONDS} of a due time is on time: the ticks are aligned to their start, so a push that
 * takes 50 ms still retries on the tick 1, 2, 4, 8 and 15 s later (pushes at 0, 1, 3, 7 and 15 s) instead of one tick
 * late each time.
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

    /**
     * While it waits for the next tick, the leader renews the lease at least this often (it lives
     * {@see LeaderLock::TTL_MS}), so the lease never lapses between ticks at an interval of up to 10 s. At intervals of
     * 1 s or less the tick's own renew always comes first and no extra command is sent.
     */
    public const KEEPALIVE_SECONDS = 1.0;

    public const RELEASE_AFTER_FAILED_TICKS = 5;

    public const RELEASE_PAUSE_SECONDS = 10.0;

    public const UNAVAILABLE_EVERY_SECONDS = 5.0;

    /**
     * The "unavailable" frame is pushed during this long of an outage (the lease lifetime plus the follower retry:
     * by then a replica that still reaches Redis has taken over, or nobody pushes and Grafana keeps the frame).
     */
    public const UNAVAILABLE_WINDOW_SECONDS = 7.0;

    public const WINDOW_STEP_MS = 5000;

    /**
     * A due time this close after a tick start counts as due at that tick: both are tick starts, so they differ only
     * by clock jitter (a sleep that returned a little early), never by a push or a build.
     */
    public const DUE_TOLERANCE_SECONDS = 0.05;

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

    /**
     * Monotonic time, taken just before the command, of the last acquire or renew that kept the lease.
     */
    private float $renewedAt = 0.0;

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
        // Laravel's clock, like the exporter's and the snapshot's (LiveSnapshotCache).
        $wall = now()->toImmutable();

        if (! $this->leader) {
            if (! $this->isDue($this->nextAttemptAt, $now)) {
                return self::FOLLOWER;
            }

            $attemptAt = ($this->clock)();

            try {
                $this->leader = $this->lock->acquire();
            } catch (Throwable $e) {
                $this->reporter->reportLocally('live.leader', $e);
                $this->nextAttemptAt = $now + self::FOLLOWER_RETRY_SECONDS;

                return $this->outage($now, $wall);
            }

            $this->redisReached();

            if (! $this->leader) {
                $this->nextAttemptAt = $now + self::FOLLOWER_RETRY_SECONDS;

                return self::FOLLOWER;
            }

            $this->renewedAt = $attemptAt;
            $this->urls = [];
            $this->failedTicks = 0;
            $this->emit('leader', sprintf('Became the stream leader: pushing to %d Grafana URL(s) as stream %s.', count($this->config->liveGrafanaUrls), $this->config->liveStreamId));
        } elseif (! $this->renew($now)) {
            return self::LOST;
        }

        $due = array_values(array_filter(
            $this->config->liveGrafanaUrls,
            fn (string $url): bool => $this->isDue($this->urls[$url]['until'] ?? 0.0, $now),
        ));

        if ($due === []) {
            return self::BACKING_OFF;
        }

        $snapshot = $this->cache->refresh(LiveScope::full($this->topology));
        $nowMs = (int) floor((float) $wall->format('U.u') * 1000);
        $timeNs = $nowMs * LineProtocol::ROW_SPACING_NS;
        $body = $snapshot->isAvailable()
            ? LineProtocol::encode($snapshot, $timeNs, $this->delayedWindow($snapshot, $nowMs))
            : LineProtocol::unavailable($timeNs, $this->lastRows);
        $pushed = false;

        foreach ($due as $url) {
            if (! $this->renew($now)) {
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

            $this->failed($url, $result, strlen($body), $now);
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
            $this->nextAttemptAt = $now + self::RELEASE_PAUSE_SECONDS;
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

    /**
     * @param  float  $tickStart  the monotonic start of the tick (or of the wait for the next tick), from which a lost
     *                            leader retries
     */
    private function renew(float $tickStart): bool
    {
        $renewingAt = ($this->clock)();

        try {
            $renewed = $this->lock->renew();
            $this->redisReached();

            if ($renewed) {
                $this->renewedAt = $renewingAt;

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
        $this->nextAttemptAt = $tickStart + self::FOLLOWER_RETRY_SECONDS;

        return false;
    }

    /**
     * Whether a time set from an earlier tick start is due at the tick that starts at `$now`.
     */
    private function isDue(float $at, float $now): bool
    {
        return $at <= $now + self::DUE_TOLERANCE_SECONDS;
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

    /**
     * @param  float  $tickStart  the monotonic start of the tick: the backoff counts from it, so the retry lands on a tick
     */
    private function failed(string $url, PushResult $result, int $bytes, float $tickStart): void
    {
        $failures = ($this->urls[$url]['failures'] ?? 0) + 1;
        $backoff = self::backoff($failures, $result->status);
        $this->urls[$url] = ['failures' => $failures, 'until' => $tickStart + $backoff];
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
    private function outage(float $now, CarbonImmutable $wall): string
    {
        if ($this->outageSince === null) {
            $this->outageSince = $now;
            $this->outageMayPush = $this->outageAsLeader || ! $this->reachedRedis;
        }

        if (! $this->outageMayPush || $now - $this->outageSince >= self::UNAVAILABLE_WINDOW_SECONDS) {
            return self::UNAVAILABLE;
        }

        if ($this->lastUnavailableAt !== null && ! $this->isDue($this->lastUnavailableAt + self::UNAVAILABLE_EVERY_SECONDS, $now)) {
            return self::UNAVAILABLE;
        }

        $this->lastUnavailableAt = $now;
        $body = LineProtocol::unavailable((int) floor((float) $wall->format('U.u') * 1000) * LineProtocol::ROW_SPACING_NS, $this->lastRows);

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

            // Both stamps are tick starts, so jitter must not push the point to the next tick (5 s, not 6 s, apart).
            if ($value !== null && ($newest === null || $nowMs - $newest >= self::WINDOW_STEP_MS - (int) (self::DUE_TOLERANCE_SECONDS * 1000))) {
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
     * Sleep until the monotonic time `$until` (the next tick) in slices of at most 0.1 s, so a signal stops the wait.
     *
     * The wait does not depend on the interval for the lease (Addendum R4.6): the leader renews it every
     * {@see self::KEEPALIVE_SECONDS} unless the next tick comes first (it renews anyway), and a follower ends the wait
     * when its retry is due, so the next tick tries to take the lease every {@see self::FOLLOWER_RETRY_SECONDS}. A
     * leader whose keep-alive renew fails becomes a follower here, like at a tick.
     *
     * @param  Closure(): bool  $shouldStop
     */
    private function pause(float $until, Closure $shouldStop): void
    {
        while (! $shouldStop() && ($remaining = $until - ($now = ($this->clock)())) > 0.0005) {
            if ($this->leader) {
                $renewAt = $this->renewedAt + self::KEEPALIVE_SECONDS;

                if ($renewAt < $until - self::DUE_TOLERANCE_SECONDS) {
                    if ($this->isDue($renewAt, $now)) {
                        $this->renew($now);

                        continue;
                    }

                    $remaining = min($remaining, $renewAt - $now);
                }
            } elseif ($this->isDue($this->nextAttemptAt, $now)) {
                return;
            } else {
                $remaining = min($remaining, $this->nextAttemptAt - $now);
            }

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
