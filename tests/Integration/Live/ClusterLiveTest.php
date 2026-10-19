<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use DF\HorizonObservability\Attempts\AttemptStatus;
use DF\HorizonObservability\Attempts\AttemptStore;
use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Horizon\Topology;
use DF\HorizonObservability\Live\AttemptRing;
use DF\HorizonObservability\Live\LiveScope;
use DF\HorizonObservability\Live\LiveSnapshotCache;
use DF\HorizonObservability\Live\Stream\LeaderLock;
use DF\HorizonObservability\Live\Stream\LiveStreamer;
use DF\HorizonObservability\Redis\Keys;
use DF\HorizonObservability\Redis\RedisClients;
use DF\HorizonObservability\Tests\Fixtures\Attempts\Storage;
use DF\HorizonObservability\Tests\TestCase;
use Illuminate\Support\Facades\Http;
use Predis\Client;

/*
| The live keys on a Redis Cluster storage connection: the ring read, the snapshot cache (MGET of the fresh and stale
| copies, the build lock), the leader lease, the delayed window and the meta fields all live under the
| {horizon-observability} hash tag, so every command and script touches one slot.
|
| Needs a cluster node: REDIS_CLUSTER_HOST (127.0.0.1) and REDIS_CLUSTER_PORT (7000), see ClusterStorageTest.
*/

pest()->group('cluster');

beforeEach(function (): void {
    $host = (string) (getenv('REDIS_CLUSTER_HOST') ?: '127.0.0.1');
    $port = (int) (getenv('REDIS_CLUSTER_PORT') ?: 7000);
    $this->node = new Client(['host' => $host, 'port' => $port, 'timeout' => 0.5]);

    try {
        $enabled = str_contains((string) $this->node->executeRaw(['INFO', 'cluster']), 'cluster_enabled:1');
    } catch (Throwable $e) {
        $this->markTestSkipped(sprintf('No Redis Cluster node at %s:%d: %s', $host, $port, $e->getMessage()));
    }

    if (! $enabled) {
        $this->markTestSkipped(sprintf('Redis at %s:%d is not in cluster mode.', $host, $port));
    }

    $this->cleanup = function (): void {
        foreach ($this->node->keys(TestCase::redisPrefix().'*') as $key) {
            $this->node->del($key);
        }
    };
    ($this->cleanup)();

    config()->set('database.redis.clusters.ho-cluster', [['host' => $host, 'port' => $port, 'database' => 0]]);
    config()->set('horizon-observability.redis.connection', 'ho-cluster');
    config()->set('horizon-observability.live.stream.grafana_url', 'http://grafana-a:3000');
    config()->set('horizon-observability.live.stream.token', 'glsa_cluster-token-0123456789');

    foreach ([Config::class, RedisClients::class, AttemptStore::class] as $abstract) {
        $this->app->forgetInstance($abstract);
    }
});

afterEach(function (): void {
    if (property_exists($this, 'cleanup') && $this->cleanup !== null) {
        ($this->cleanup)();
    }
});

it('reads the ring, caches snapshots, leads and pushes with every live key in one slot', function (): void {
    Http::fake(['*' => Http::response('', 200)]);
    $now = CarbonImmutable::createFromTimestamp(1_700_000_012.5);
    Storage::record(status: AttemptStatus::Completed, duration: null, now: CarbonImmutable::createFromTimestamp(1_700_000_005.0));
    Storage::record(status: AttemptStatus::Failed, duration: null, now: CarbonImmutable::createFromTimestamp(1_700_000_001.0));

    expect(app(RedisClients::class)->isCluster('ho-cluster'))->toBeTrue()
        ->and(app(AttemptRing::class)->window($now))->toBe(["redis\x1fdefault" => ['completed' => 1, 'failed' => 1]]);

    $cache = app(LiveSnapshotCache::class);
    $scope = LiveScope::full(app(Topology::class));
    $first = $cache->get($scope);
    $second = $cache->get($scope);

    expect($first->errors)->toBe([])
        ->and($second->generatedAtMs())->toBe($first->generatedAtMs())
        ->and($cache->stats())->toMatchArray(['builds' => 1, 'hits' => 1, 'failures' => 0]);

    $lock = app(LeaderLock::class);

    expect($lock->acquire())->toBeTrue()
        ->and($lock->renew())->toBeTrue();

    $lock->release();
    $streamer = app(LiveStreamer::class);

    expect($streamer->tick())->toBe(LiveStreamer::PUSHED);

    $root = TestCase::redisPrefix().Keys::ROOT;
    $keys = $this->node->keys(TestCase::redisPrefix().'*');
    $slots = array_unique(array_map(fn (string $key): int => (int) $this->node->executeRaw(['CLUSTER', 'KEYSLOT', $key]), $keys));

    expect($keys)->toContain($root.'live:leader')
        ->and($keys)->toContain($root.'live:window:delayed')
        ->and($keys)->toContain($root.'live:snapshot:'.$scope->cacheKey())
        ->and($keys)->toContain($root.'live:snapshot-stale:'.$scope->cacheKey())
        ->and($this->node->hget($root.'meta', 'live_push_at'))->not->toBeNull()
        ->and($slots)->toHaveCount(1);

    $streamer->stop();

    expect($this->node->exists($root.'live:leader'))->toBe(0);
});
