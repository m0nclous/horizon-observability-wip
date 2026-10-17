<?php

declare(strict_types=1);

use DF\HorizonObservability\Horizon\Supervisors;
use DF\HorizonObservability\Horizon\SupervisorSnapshot;
use Laravel\Horizon\Contracts\MasterSupervisorRepository;
use Laravel\Horizon\Contracts\SupervisorRepository;

/**
 * @param  list<object>  $masters
 * @param  list<object>  $supervisors
 */
function supervisorsFrom(array $masters, array $supervisors): Supervisors
{
    $masterRepository = Mockery::mock(MasterSupervisorRepository::class);
    $masterRepository->shouldReceive('all')->andReturn($masters);
    $supervisorRepository = Mockery::mock(SupervisorRepository::class);
    $supervisorRepository->shouldReceive('all')->andReturn($supervisors);

    return new Supervisors($masterRepository, $supervisorRepository);
}

it('builds snapshots from repository records (C14)', function (): void {
    $snapshot = Supervisors::snapshot((object) [
        'name' => 'host-a1b2:supervisor-pool',
        'master' => 'host-a1b2',
        'pid' => '1001',
        'status' => 'running',
        'processes' => ['redis:mail,sms' => 2],
        'options' => ['connection' => 'redis', 'queue' => 'mail, sms', 'balance' => false, 'minProcesses' => 1, 'maxProcesses' => 2],
    ]);

    expect($snapshot)->toEqual(new SupervisorSnapshot('supervisor-pool', 'host-a1b2', 'redis', 'off', 1, 2, ['mail,sms' => 2], ['mail', 'sms']))
        ->and($snapshot?->totalProcesses())->toBe(2);
});

it('tolerates partial records', function (): void {
    expect(Supervisors::snapshot((object) ['name' => 'solo']))->toEqual(new SupervisorSnapshot('solo', '', '', 'off', 1, 1, [], []))
        ->and(Supervisors::snapshot((object) ['name' => '']))->toBeNull()
        ->and(Supervisors::snapshot((object) []))->toBeNull()
        ->and(Supervisors::snapshot((object) ['name' => 'm:s', 'processes' => ['x:default' => 2, 'default' => 1], 'options' => ['connection' => 'redis']])?->processes)
        ->toBe(['default' => 3]);
});

it('maps the balance strategies', function (mixed $balance, string $expected): void {
    expect(Supervisors::balance($balance))->toBe($expected);
})->with([
    'auto' => ['auto', 'auto'],
    'simple' => ['simple', 'simple'],
    'false' => [false, 'off'],
    'false string' => ['false', 'off'],
    'off' => ['off', 'off'],
    'unknown' => ['weighted', 'off'],
    'null' => [null, 'off'],
]);

it('sorts the running supervisors', function (): void {
    $supervisors = supervisorsFrom([], [
        (object) ['name' => 'host-b:supervisor-slow', 'master' => 'host-b', 'options' => ['connection' => 'redis', 'queue' => 'slow']],
        (object) ['name' => 'host-a:supervisor-default', 'master' => 'host-a', 'options' => ['connection' => 'redis', 'queue' => 'default']],
        (object) ['name' => 'host-b:supervisor-default', 'master' => 'host-b', 'options' => ['connection' => 'redis', 'queue' => 'default']],
        'not a record',
    ]);

    expect(array_map(static fn (SupervisorSnapshot $s): string => $s->master.':'.$s->name, $supervisors->all()))
        ->toBe(['host-a:supervisor-default', 'host-b:supervisor-default', 'host-b:supervisor-slow']);
});

it('derives the Horizon status from the masters', function (array $statuses, int $expected): void {
    $masters = array_map(static fn (string $status): object => (object) ['name' => 'm', 'status' => $status], $statuses);

    expect(supervisorsFrom($masters, [])->status())->toBe($expected);
})->with([
    'no master' => [[], -1],
    'running' => [['running'], 1],
    'all running' => [['running', 'running'], 1],
    'one paused' => [['running', 'paused'], 0],
    'paused' => [['paused'], 0],
]);
