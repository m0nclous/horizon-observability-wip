<?php

declare(strict_types=1);

use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Horizon\Topology;
use DF\HorizonObservability\Live\LiveScope;
use DF\HorizonObservability\Live\LiveSnapshot;
use DF\HorizonObservability\Live\LiveSnapshotCache;
use DF\HorizonObservability\Redis\Keys;
use DF\HorizonObservability\Redis\RedisClients;
use DF\HorizonObservability\Tests\Integration\Live\Support\LiveState;
use DF\HorizonObservability\Tests\Integration\Live\Support\Processes;
use DF\HorizonObservability\Tests\Support\RedisCommands;
use DF\HorizonObservability\Tests\Support\RedisKeys;
use DF\HorizonObservability\Tests\TestCase;
use Illuminate\Foundation\Application;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Repositories\RedisMasterSupervisorRepository;

/*
| The snapshot cache (Addendum R4.2): 1 MGET per hit, freshness by Redis key expiry (never by comparing clocks),
| one build per TTL across workers and replicas, the stale copy while another request builds, the wait for the
| builder, the uncached fallback, nothing stored for a failed build, and "Live unavailable" when its Redis fails.
*/

pest()->group('redis');

const LIVE_CACHE_BUILDS = 'live-cache-test:builds';

beforeEach(function (): void {
    LiveState::freeze(LiveState::now());
    LiveState::horizon($this->app);
    LiveState::queues($this->app);
    $this->scope = LiveScope::full(app(Topology::class));
    $this->hash = $this->scope->cacheKey();
    $keys = new Keys;
    $raw = static fn (string $key): string => TestCase::redisPrefix().'horizon:'.$key;
    $this->fresh = $raw($keys->liveSnapshot($this->hash));
    $this->stale = $raw($keys->liveSnapshotStale($this->hash));
    $this->lock = $raw($keys->liveSnapshotLock($this->hash));
    $this->builds = TestCase::redisPrefix().LIVE_CACHE_BUILDS;
    countLiveBuilds($this->app);
});

afterEach(function (): void {
    LiveState::freeze(null);
});

/**
 * Count the builds in Redis (the first read of every build is the Horizon status), optionally making each build
 * take a while.
 */
function countLiveBuilds(Application $app, int $buildMilliseconds = 0): void
{
    $real = new RedisMasterSupervisorRepository($app->make('redis'));
    $counter = TestCase::redisPrefix().LIVE_CACHE_BUILDS; // the prefix carries the pid: taken here, not in a child
    $masters = Mockery::mock(MasterSupervisorRepository::class);
    $masters->shouldReceive('all')->andReturnUsing(static function () use ($real, $buildMilliseconds, $counter): array {
        RedisKeys::client()->incr($counter);
        usleep($buildMilliseconds * 1000);

        return $real->all();
    });
    $app->instance(MasterSupervisorRepository::class, $masters);
}

function liveBuildCount(): int
{
    return (int) RedisKeys::client()->get(TestCase::redisPrefix().LIVE_CACHE_BUILDS);
}

/**
 * @param  (Closure(int): void)|null  $sleep
 */
function liveCache(?Closure $sleep = null): LiveSnapshotCache
{
    return app()->make(LiveSnapshotCache::class, $sleep instanceof Closure ? ['sleep' => $sleep] : []);
}

it('builds on a miss, stores the fresh and the stale copy and releases the lock', function (): void {
    $snapshot = liveCache()->get($this->scope);
    $client = RedisKeys::client();

    expect($snapshot->pending['value'])->toBe(5)
        ->and(liveBuildCount())->toBe(1)
        ->and(json_decode((string) $client->get($this->fresh), true))->toBe(json_decode($snapshot->toJson(), true))
        ->and($client->get($this->stale))->toBe($client->get($this->fresh))
        ->and($client->pttl($this->fresh))->toBeGreaterThan(500)->toBeLessThanOrEqual(1000)
        ->and($client->pttl($this->stale))->toBeGreaterThan(9500)->toBeLessThanOrEqual(10000)
        ->and($client->exists($this->lock))->toBe(0);
});

it('answers a hit with one MGET and no build', function (): void {
    liveCache()->get($this->scope);
    LiveState::freeze(LiveState::now()->addMilliseconds(250));
    $commands = RedisCommands::start($this->app);
    $cache = liveCache();

    $snapshot = $cache->get($this->scope);

    expect($commands->names())->toBe(['MGET'])
        ->and($commands->commands()[0]['connection'])->toBe(RedisClients::READER.'horizon')
        ->and(liveBuildCount())->toBe(1)
        ->and($snapshot->ageMs)->toBe(250)
        ->and($snapshot->generatedAtMs())->toBe(1792201716123)
        ->and($cache->stats())->toMatchArray(['hits' => 1, 'builds' => 0]);
});

it('decides freshness by Redis expiry, so replicas with clocks 5 s apart share one build', function (): void {
    liveCache()->get($this->scope); // replica A at T

    LiveState::freeze(LiveState::now()->addSeconds(5)); // replica B, clock 5 s ahead
    $ahead = liveCache()->get($this->scope);
    LiveState::freeze(LiveState::now()->subSeconds(5)); // replica C, clock 5 s behind
    $behind = liveCache()->get($this->scope);

    expect(liveBuildCount())->toBe(1)
        ->and($ahead->generatedAtMs())->toBe(1792201716123)
        ->and($ahead->ageMs)->toBe(5000)
        ->and($behind->ageMs)->toBe(0);
});

it('rebuilds once the fresh copy expired in Redis', function (): void {
    liveCache()->get($this->scope);
    RedisKeys::client()->del($this->fresh);

    liveCache()->get($this->scope);

    expect(liveBuildCount())->toBe(2);
});

it('serves the stale copy while another request holds the lock', function (): void {
    liveCache()->get($this->scope);
    $client = RedisKeys::client();
    $client->del($this->fresh);
    $client->set($this->lock, 'another-request', 'PX', 2000);
    LiveState::freeze(LiveState::now()->addSeconds(3));
    $cache = liveCache(static fn (int $ms): never => throw new LogicException('a stale copy needs no wait'));

    $snapshot = $cache->get($this->scope);

    expect(liveBuildCount())->toBe(1)
        ->and($snapshot->ageMs)->toBe(3000)
        ->and($cache->stats())->toMatchArray(['stale' => 1, 'builds' => 0])
        // the stale copy is at most 10 x TTL (10 s) old: it expires in Redis after that
        ->and($client->pttl($this->stale))->toBeLessThanOrEqual(10000)
        ->and($client->get($this->lock))->toBe('another-request');
});

it('waits up to 500 ms for the builder when there is no stale copy', function (): void {
    $client = RedisKeys::client();
    $client->set($this->lock, 'another-request', 'PX', 2000);
    $json = LiveSnapshot::empty($this->scope, 1792201716000, 1000)->toJson();
    $polls = 0;
    $cache = liveCache(static function (int $ms) use (&$polls, $client, $json): void {
        expect($ms)->toBe(50);

        if (++$polls === 3) {
            $client->set(TestCase::redisPrefix().'horizon:'.(new Keys)->liveSnapshot(LiveScope::full(app(Topology::class))->cacheKey()), $json, 'PX', 1000);
        }
    });

    $snapshot = $cache->get($this->scope);

    expect($polls)->toBe(3)
        ->and($snapshot->generatedAtMs())->toBe(1792201716000)
        ->and(liveBuildCount())->toBe(0)
        ->and($cache->stats())->toMatchArray(['waited' => 1, 'builds' => 0, 'uncached' => 0]);
});

it('builds without caching when the lock holder died or is slow', function (): void {
    RedisKeys::client()->set($this->lock, 'a-dead-request', 'PX', 2000);
    $polls = 0;
    $cache = liveCache(static function () use (&$polls): void {
        $polls++;
    });

    $snapshot = $cache->get($this->scope);

    expect($polls)->toBe(10)
        ->and($snapshot->pending['value'])->toBe(5)
        ->and(liveBuildCount())->toBe(1)
        ->and(RedisKeys::client()->exists($this->fresh))->toBe(0)
        ->and(RedisKeys::client()->get($this->lock))->toBe('a-dead-request')
        ->and($cache->stats())->toMatchArray(['uncached' => 1]);
});

it('stores a snapshot with partial errors but not a failed build', function (): void {
    RedisKeys::client()->del(TestCase::redisPrefix().'queues:slow');
    RedisKeys::client()->set(TestCase::redisPrefix().'queues:slow', 'not a list');

    $partial = liveCache()->get($this->scope);

    expect($partial->errors)->toHaveCount(1)
        ->and(RedisKeys::client()->exists($this->fresh))->toBe(1);

    RedisKeys::client()->del($this->fresh, $this->stale);
    $masters = Mockery::mock(MasterSupervisorRepository::class);
    $masters->shouldReceive('all')->andThrow(new RuntimeException('Connection refused'));
    $this->app->instance(MasterSupervisorRepository::class, $masters);
    config()->set('database.redis.dead', ['host' => '127.0.0.1', 'port' => 1, 'database' => 0, 'timeout' => 0.2]);
    config()->set('queue.connections.redis.connection', 'dead');
    $this->app->forgetInstance(RedisClients::class);
    // the ring stays readable (storage up), but Horizon and every queue fail: no live data for the tiles
    RedisKeys::client()->del($this->lock);

    $failed = liveCache()->get($this->scope);

    expect($failed->isAvailable())->toBeFalse()
        ->and($failed->pending['caption'])->toBe('Live unavailable')
        ->and(RedisKeys::client()->exists($this->fresh))->toBe(0)
        ->and(RedisKeys::client()->exists($this->stale))->toBe(0)
        ->and(RedisKeys::client()->exists($this->lock))->toBe(0);
});

it('treats a corrupt or foreign cached value as a miss', function (string $value): void {
    RedisKeys::client()->set($this->fresh, $value, 'PX', 1000);

    $snapshot = liveCache()->get($this->scope);

    expect($snapshot->pending['value'])->toBe(5)
        ->and(liveBuildCount())->toBe(1);
})->with(['not json' => ['{'], 'schema v2' => ['{"v":2}'], 'a list' => ['[1,2]'], 'empty' => ['']]);

it('answers Live unavailable without building when its Redis fails', function (): void {
    config()->set('database.redis.dead', ['host' => '127.0.0.1', 'port' => 1, 'database' => 0, 'timeout' => 0.2]);
    config()->set('horizon-observability.redis.connection', 'dead');
    $this->app->forgetInstance(Config::class);
    $this->app->forgetInstance(RedisClients::class);
    $cache = liveCache();

    $snapshot = $cache->get($this->scope);

    expect($snapshot->isAvailable())->toBeFalse()
        ->and($snapshot->errors[0]['source'])->toBe('cache')
        ->and($snapshot->horizon['caption'])->toBe('Live unavailable')
        ->and($snapshot->pending['value'])->toBeNull()
        ->and(liveBuildCount())->toBe(0)
        ->and($cache->stats())->toMatchArray(['failures' => 1, 'builds' => 0]);
});

it('builds every time and stores nothing with cache_ttl = 0', function (): void {
    config()->set('horizon-observability.live.cache_ttl', 0);
    $this->app->forgetInstance(Config::class);
    $cache = liveCache();

    $cache->get($this->scope);
    $snapshot = $cache->get($this->scope);

    expect(liveBuildCount())->toBe(2)
        ->and($snapshot->ttlMs)->toBe(0)
        ->and(RedisKeys::all(TestCase::redisPrefix().'horizon:{horizon-observability}:v1:live:snapshot'))->toBe([]);
});

it('keys the cache by scope', function (): void {
    liveCache()->get($this->scope);
    $other = new LiveScope(null, [['connection' => 'redis', 'queue' => 'default']], false, null);

    $snapshot = liveCache()->get($other);

    expect(liveBuildCount())->toBe(2)
        ->and($snapshot->scope['queues'])->toBe(['default']);
});

it('makes one build for 50 concurrent requests on 2 replicas whose clocks differ by 5 s', function (): void {
    countLiveBuilds($this->app, buildMilliseconds: 150);
    // A 5 s TTL: on a loaded CI host a late child must still find the copy fresh (one build per TTL is the claim).
    config()->set('horizon-observability.live.cache_ttl', 5.0);
    $this->app->forgetInstance(Config::class);
    $results = TestCase::redisPrefix().'live-cache-test:results';
    $start = microtime(true) + 1.0;

    $pids = Processes::fork(50, function (int $index) use ($start, $results): void {
        // even children are replica A (the test clock), odd ones replica B, 5 s ahead
        LiveState::freeze(LiveState::now()->addSeconds($index % 2 === 0 ? 0 : 5));

        while (microtime(true) < $start) {
            usleep(200);
        }

        $snapshot = liveCache()->get(LiveScope::full(app(Topology::class)));
        RedisKeys::client()->rpush($results, [json_encode([$index, $snapshot->generatedAtMs(), $snapshot->pending['value'], $snapshot->errors])]);
    });
    Processes::wait($pids);

    $answers = array_map(static fn (string $json): array => json_decode($json, true), RedisKeys::client()->lrange($results, 0, -1));

    expect($answers)->toHaveCount(50)
        ->and(liveBuildCount())->toBe(1)
        ->and(array_unique(array_column($answers, 1)))->toHaveCount(1)
        ->and(array_unique(array_column($answers, 2)))->toBe([5])
        ->and(array_merge(...array_column($answers, 3)))->toBe([]);
});

it('rebuilds and stores on refresh() even while the fresh copy lives (the stream leader)', function (): void {
    liveCache()->get($this->scope);
    $old = RedisKeys::client()->get($this->fresh);
    LiveState::freeze(LiveState::now()->addMilliseconds(400));
    $cache = liveCache();

    $snapshot = $cache->refresh($this->scope);

    expect(liveBuildCount())->toBe(2)
        ->and($snapshot->generatedAtMs())->toBe(1792201716523)
        ->and(RedisKeys::client()->get($this->fresh))->not->toBe($old)
        ->and(RedisKeys::client()->pttl($this->fresh))->toBeGreaterThan(500)
        ->and(RedisKeys::client()->exists($this->lock))->toBe(0)
        ->and($cache->stats())->toMatchArray(['builds' => 1, 'hits' => 0])
        // a poll request right after it is a hit on the leader's build
        ->and(liveCache()->get($this->scope)->generatedAtMs())->toBe(1792201716523)
        ->and(liveBuildCount())->toBe(2);
});

it('answers refresh() like get() while another request builds', function (): void {
    liveCache()->get($this->scope);
    RedisKeys::client()->set($this->lock, 'a-poll-request', 'PX', 2000);
    $cache = liveCache();

    $snapshot = $cache->refresh($this->scope);

    expect(liveBuildCount())->toBe(1)
        ->and($snapshot->generatedAtMs())->toBe(1792201716123)
        ->and($cache->stats())->toMatchArray(['hits' => 1, 'builds' => 0])
        ->and(RedisKeys::client()->get($this->lock))->toBe('a-poll-request');
});
