<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use DF\HorizonObservability\Attempts\AttemptStatus;
use DF\HorizonObservability\Attempts\AttemptStore;
use DF\HorizonObservability\Attempts\LegacyReadyAt;
use DF\HorizonObservability\Collectors\AttemptsCollector;
use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Redis\Keys;
use DF\HorizonObservability\Redis\RedisClients;
use DF\HorizonObservability\Tests\Fixtures\Attempts\Events;
use DF\HorizonObservability\Tests\Fixtures\Attempts\Storage;
use DF\HorizonObservability\Tests\TestCase;
use Predis\Client;

/*
| Package storage on a Redis Cluster connection (Arch Q6.5, C10). The pilot pipelined (phpredis RedisCluster has no
| pipeline()) and its scripts touched keys of different slots (CROSSSLOT). Every package key carries the hash tag
| {horizon-observability}, every script touches only its KEYS, and INFO runs inside Lua on the slot's node.
|
| Needs a cluster node: REDIS_CLUSTER_HOST (127.0.0.1) and REDIS_CLUSTER_PORT (7000), e.g.
| redis-server --port 7000 --cluster-enabled yes --cluster-announce-ip 127.0.0.1 --save '' --appendonly no, then
| redis-cli -p 7000 CLUSTER ADDSLOTSRANGE 0 16383 (phpredis 5 cannot follow the empty endpoint a node without an
| announced IP reports).
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

    foreach ([Config::class, RedisClients::class, AttemptStore::class] as $abstract) {
        $this->app->forgetInstance($abstract);
    }

    $this->keys = new Keys;
    $this->now = CarbonImmutable::createFromTimestamp(1_700_000_012.5);
});

afterEach(function (): void {
    if (property_exists($this, 'cleanup') && $this->cleanup !== null) {
        ($this->cleanup)();
    }
});

it('records attempts with every key in one slot of a cluster connection (C10)', function (): void {
    expect(app(RedisClients::class)->isCluster('ho-cluster'))->toBeTrue()
        ->and(Storage::record(duration: 0.3, startedAt: 1_700_000_010.0, readyAt: 1_700_000_009.0, uuid: 'job-1', now: $this->now))->toBeTrue()
        ->and(Storage::record(status: AttemptStatus::Failed, duration: 1.5, now: $this->now))->toBeTrue()
        ->and(Storage::hash($this->keys->attempts()))->toBe([
            'redis|default|App\\Jobs\\SendInvoice|completed' => '1',
            'redis|default|App\\Jobs\\SendInvoice|failed' => '1',
        ]);

    $keys = $this->node->keys(TestCase::redisPrefix().'*');
    $slots = array_unique(array_map(fn (string $key): int => (int) $this->node->executeRaw(['CLUSTER', 'KEYSLOT', $key]), $keys));

    sort($keys);
    $root = TestCase::redisPrefix().Keys::ROOT;

    expect($keys)->toBe([
        $root.'attempts',
        $root.'created',
        $root.'duration:buckets',
        $root.'duration:sum',
        $root.'extremes:113333334:max',
        $root.'extremes:113333334:min',
        $root.'job_classes',
        $root.'live:ring:340000002',
        $root.'wait:buckets',
        $root.'wait:sum',
    ])->and($slots)->toHaveCount(1);
});

it('collects from a cluster connection, reset guard included (C10)', function (): void {
    Storage::record(duration: 0.3, startedAt: 1_700_000_010.0, readyAt: 1_700_000_009.0, now: $this->now);

    // After the hold of new series (T16), so the counter shows its value.
    $scrape = Storage::scrape($this->now->addSeconds(AttemptsCollector::NEW_SERIES_HOLD_SECONDS));

    expect($scrape['failures'])->toBe([])
        ->and($scrape['samples']['queue_jobs_total']['connection=redis,queue=default,job_class=App\\Jobs\\SendInvoice,status=completed'])->toBe(1)
        ->and($scrape['samples']['queue_job_duration_max_seconds'])->toBe(['connection=redis,queue=default,job_class=App\\Jobs\\SendInvoice' => 0.3])
        ->and(Storage::hash($this->keys->meta()))->toHaveKey('run_id');
});

it('stores ready_at on a cluster connection (C10)', function (): void {
    app(LegacyReadyAt::class)->onQueued(Events::queued('redis', 'job-2', 30));

    expect(Storage::exists($this->keys->readyAt('job-2')))->toBeTrue();
});
