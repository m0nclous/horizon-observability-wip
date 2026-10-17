<?php

declare(strict_types=1);

use DF\HorizonObservability\Batches\BatchLinks;
use DF\HorizonObservability\Horizon\Supervisors;
use DF\HorizonObservability\Horizon\Topology;
use DF\HorizonObservability\Live\LiveScope;
use DF\HorizonObservability\Live\LiveSnapshot;
use DF\HorizonObservability\Live\LiveSnapshotBuilder;
use DF\HorizonObservability\Live\LiveSnapshotCache;
use DF\HorizonObservability\Redis\Keys;
use DF\HorizonObservability\Tests\Integration\Live\Support\LiveState;
use DF\HorizonObservability\Tests\Support\QueuePayloads;
use DF\HorizonObservability\Tests\Support\RedisKeys;
use DF\HorizonObservability\Tests\TestCase;

/*
| Adversarial probes of the live snapshot (T12 QA round 1): the numbers must equal what the exporter and the
| off-mode dashboard show for the same Redis state (owner decision 13).
*/

pest()->group('redis');

beforeEach(function (): void {
    LiveState::freeze(LiveState::now());
});

afterEach(function (): void {
    LiveState::freeze(null);
});

/**
 * @param  array{supervisors?: list<string>|null, batchKinds?: list<string>|null, queues?: list<string>|null, jobClasses?: list<string>|null}  $filters
 */
function qaLiveBuild(array $filters = []): LiveSnapshot
{
    $scope = LiveScope::fromFilters(
        [...['supervisors' => null, 'batchKinds' => null, 'queues' => null, 'jobClasses' => null], ...$filters],
        app(Topology::class),
        app(Supervisors::class),
        app(BatchLinks::class),
    );

    return app(LiveSnapshotBuilder::class)->build($scope, LiveState::now());
}

it('uses the full scope of the stream for the poll endpoint without filters (one cache entry)', function (): void {
    $full = LiveScope::full(app(Topology::class));
    $unfiltered = LiveScope::fromFilters(
        ['supervisors' => null, 'batchKinds' => null, 'queues' => null, 'jobClasses' => null],
        app(Topology::class),
        app(Supervisors::class),
        app(BatchLinks::class),
    );

    expect($unfiltered->cacheKey())->toBe($full->cacheKey());
});

it('levels the oldest wait from the exact age, as the off-mode tile does', function (float $age, int $level): void {
    LiveState::horizon(app());
    // default: horizon.waits 10 s. The exporter exports round(age, 3) and the off-mode tile compares
    // age / limit > 1 and > 2 (Waits::levels()), so 10.4 s is level 1 and 20.4 s level 2.
    QueuePayloads::push(LiveState::redis(app()), 'default', LiveState::job('App\\Jobs\\SendInvoice', LiveState::NOW - $age));

    $snapshot = qaLiveBuild(['queues' => ['default']]);

    expect($snapshot->queues[0]['level'])->toBe($level)
        ->and($snapshot->pending['level'])->toBe($level);
})->with([
    'just under the limit' => [9.6, 0],
    'just under twice the limit' => [19.6, 1],
]);

it('levels an oldest wait just over the limit like the exporter and the off-mode tile', function (float $age, int $level): void {
    LiveState::horizon(app());
    QueuePayloads::push(LiveState::redis(app()), 'default', LiveState::job('App\\Jobs\\SendInvoice', LiveState::NOW - $age));

    $snapshot = qaLiveBuild(['queues' => ['default']]);

    // Today the ratio is taken from the rounded age (10 / 10 = 1.0, 20 / 10 = 2.0), so both cases read one level low.
    expect($snapshot->queues[0]['level'])->toBe($level)
        ->and($snapshot->pending['level'])->toBe($level)
        ->and($snapshot->pending['color'])->toBe($level === 1 ? 'yellow' : 'red');
})->with([
    'just over the limit' => [10.4, 1],
    'just over twice the limit' => [20.4, 2],
]);

it('caches the snapshot of a queue whose name is not valid UTF-8 (no rebuild per request)', function (): void {
    LiveState::horizon(app());
    config()->set('horizon.environments.testing.supervisor-default.queue', ["caf\xE9"]);
    QueuePayloads::push(LiveState::redis(app()), "caf\xE9", LiveState::job('App\\Jobs\\SendInvoice', LiveState::NOW - 3));
    $scope = LiveScope::full(app(Topology::class));
    $fresh = TestCase::redisPrefix().'horizon:'.(new Keys)->liveSnapshot($scope->cacheKey());

    $cache = app(LiveSnapshotCache::class);
    $first = $cache->get($scope);
    $cache->get($scope);

    expect($first->pending['value'])->toBe(1)
        ->and(RedisKeys::client()->exists($fresh))->toBe(1)
        ->and($cache->stats())->toMatchArray(['builds' => 1, 'hits' => 1]);
});
