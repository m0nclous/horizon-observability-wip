<?php

declare(strict_types=1);

use DF\HorizonObservability\Batches\BatchLinks;
use DF\HorizonObservability\Horizon\Supervisors;
use DF\HorizonObservability\Horizon\Topology;
use DF\HorizonObservability\Live\LiveScope;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;

/**
 * @param  array{supervisors?: list<string>|null, batchKinds?: list<string>|null, queues?: list<string>|null, jobClasses?: list<string>|null}  $filters
 */
function liveScope(array $filters, ?SupervisorRepository $repository = null): LiveScope
{
    $repository ??= Mockery::mock(SupervisorRepository::class)->shouldNotReceive('all')->getMock();

    return LiveScope::fromFilters(
        [...['supervisors' => null, 'batchKinds' => null, 'queues' => null, 'jobClasses' => null], ...$filters],
        new Topology(app('config')),
        new Supervisors(Mockery::mock(MasterSupervisorRepository::class), $repository),
        new BatchLinks,
    );
}

it('covers every topology queue for the full scope', function (): void {
    $scope = LiveScope::full(new Topology(app('config')));

    expect($scope->supervisors)->toBeNull()
        ->and($scope->jobFilter)->toBeFalse()
        ->and($scope->jobClasses)->toBeNull()
        ->and($scope->queueNames())->toBe(['default', 'slow', 'reports', 'mail', 'sms'])
        ->and(liveScope([]))->toEqual($scope);
});

it('scopes by the queues of the selected running supervisors', function (): void {
    $repository = Mockery::mock(SupervisorRepository::class);
    $repository->shouldReceive('all')->once()->andReturn([
        (object) ['name' => 'm:supervisor-slow', 'master' => 'm', 'options' => ['connection' => 'redis', 'queue' => 'slow,reports']],
        (object) ['name' => 'm:supervisor-pool', 'master' => 'm', 'options' => ['connection' => 'redis', 'queue' => 'mail,sms']],
    ]);

    $scope = liveScope(['supervisors' => ['supervisor-slow']], $repository);

    expect($scope->supervisors)->toBe(['supervisor-slow'])
        ->and($scope->queues)->toBe([['connection' => 'redis', 'queue' => 'slow'], ['connection' => 'redis', 'queue' => 'reports']])
        ->and($scope->jobFilter)->toBeFalse();
});

it('gives an empty scope for a supervisor that is neither running nor configured', function (): void {
    $repository = Mockery::mock(SupervisorRepository::class);
    $repository->shouldReceive('all')->andReturn([]);

    expect(liveScope(['supervisors' => ['supervisor-gone']], $repository)->queues)->toBe([]);
});

it('scopes a configured supervisor that is not running by its configured queues (Horizon stopped)', function (): void {
    $repository = Mockery::mock(SupervisorRepository::class);
    $repository->shouldReceive('all')->andReturn([]);

    $scope = liveScope(['supervisors' => ['supervisor-slow', 'supervisor-gone']], $repository);

    expect($scope->supervisors)->toBe(['supervisor-slow', 'supervisor-gone'])
        ->and($scope->queues)->toBe([['connection' => 'redis', 'queue' => 'slow'], ['connection' => 'redis', 'queue' => 'reports']])
        ->and(liveScope(['supervisors' => ['supervisor-default'], 'queues' => ['default', 'slow']], $repository)->queueNames())->toBe(['default']);
});

it('scopes a running supervisor by the queues it runs, not by the configured ones', function (): void {
    // A deploy changed the config (supervisor-slow: slow,reports), but the running supervisor-slow still runs slow
    // only; supervisor-default is not running anywhere and falls back to its configured queue.
    $repository = Mockery::mock(SupervisorRepository::class);
    $repository->shouldReceive('all')->andReturn([
        (object) ['name' => 'm:supervisor-slow', 'master' => 'm', 'options' => ['connection' => 'redis', 'queue' => 'slow']],
    ]);

    expect(liveScope(['supervisors' => ['supervisor-slow']], $repository)->queueNames())->toBe(['slow'])
        ->and(liveScope(['supervisors' => ['supervisor-slow', 'supervisor-default']], $repository)->queueNames())->toBe(['default', 'slow']);
});

it('intersects with the queue filter', function (): void {
    expect(liveScope(['queues' => ['sms', 'missing', 'default']])->queueNames())->toBe(['default', 'sms']);
});

it('marks a job filter', function (): void {
    $scope = liveScope(['jobClasses' => ['App\\Jobs\\SendInvoice']]);

    expect($scope->jobFilter)->toBeTrue()
        ->and($scope->jobClasses)->toBe(['App\\Jobs\\SendInvoice'])
        ->and($scope->queueNames())->toBe(['default', 'slow', 'reports', 'mail', 'sms']);
});

// BatchLinks (T07) reads the links from the package storage, so this case needs Redis.
it('narrows to the batch links with a batch filter', function (): void {
    $scope = liveScope(['batchKinds' => ['import'], 'jobClasses' => ['App\\Jobs\\SendInvoice']]);

    expect($scope->jobFilter)->toBeTrue()
        ->and($scope->queues)->toBe([])
        ->and($scope->jobClasses)->toBe([]);
})->group('redis');

it('has a cache key of the normalised scope', function (): void {
    $a = new LiveScope(['b', 'a'], [['connection' => 'redis', 'queue' => 'slow'], ['connection' => 'redis', 'queue' => 'default']], true, ['Y', 'X']);
    $b = new LiveScope(['a', 'b', 'a'], [['connection' => 'redis', 'queue' => 'default'], ['connection' => 'redis', 'queue' => 'slow']], true, ['X', 'Y']);

    expect($a->cacheKey())->toBe($b->cacheKey())
        ->and($a->cacheKey())->toMatch('/^[0-9a-f]{40}$/')
        ->and($a->cacheKey())->not->toBe((new LiveScope(['a', 'b'], $a->queues, false, ['X', 'Y']))->cacheKey())
        ->and((new LiveScope(null, [], false, null))->cacheKey())->not->toBe((new LiveScope([], [], false, null))->cacheKey())
        ->and((new LiveScope(null, [['connection' => 'redis', 'queue' => 'a']], false, null))->cacheKey())
        ->not->toBe((new LiveScope(null, [['connection' => 'other', 'queue' => 'a']], false, null))->cacheKey());
});

it('keeps scopes with invalid UTF-8 names apart', function (): void {
    $scope = static fn (string $queue): LiveScope => new LiveScope(null, [['connection' => 'redis', 'queue' => $queue]], false, null);

    expect($scope("bad\xFF")->cacheKey())->not->toBe($scope("bad\xFE")->cacheKey())
        ->and($scope("bad\xFF")->cacheKey())->not->toBe((new LiveScope(null, [], false, null))->cacheKey());
});

it('lists each queue name once', function (): void {
    expect((new LiveScope(null, [['connection' => 'a', 'queue' => 'q'], ['connection' => 'b', 'queue' => 'q']], false, null))->queueNames())->toBe(['q']);
});
