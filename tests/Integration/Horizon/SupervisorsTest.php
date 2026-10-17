<?php

declare(strict_types=1);

use DF\HorizonObservability\Horizon\Supervisors;
use DF\HorizonObservability\Horizon\SupervisorSnapshot;
use DF\HorizonObservability\Tests\Support\HorizonState;

pest()->group('redis');

it('reads the supervisors Horizon writes (C14)', function (): void {
    HorizonState::using($this->app)
        ->master('host-a1b2', supervisors: ['host-a1b2:supervisor-default', 'host-a1b2:supervisor-pool'])
        ->supervisor('host-a1b2:supervisor-default', HorizonState::options('host-a1b2:supervisor-default', queue: 'default', maxProcesses: 3), ['redis:default' => 3])
        ->supervisor('host-a1b2:supervisor-pool', HorizonState::options('host-a1b2:supervisor-pool', queue: 'mail,sms', balance: false, maxProcesses: 2), ['redis:mail,sms' => 2]);

    $supervisors = app(Supervisors::class);

    expect($supervisors->all())->toEqual([
        new SupervisorSnapshot('supervisor-default', 'host-a1b2', 'redis', 'auto', 1, 3, ['default' => 3], ['default']),
        new SupervisorSnapshot('supervisor-pool', 'host-a1b2', 'redis', 'off', 1, 2, ['mail,sms' => 2], ['mail', 'sms']),
    ])->and($supervisors->status())->toBe(1);
});

it('reports a paused master as 0 and no master as -1', function (): void {
    $supervisors = app(Supervisors::class);

    expect($supervisors->status())->toBe(-1)
        ->and($supervisors->all())->toBe([]);

    HorizonState::using($this->app)->master('host-a', 'running')->master('host-b', 'paused');

    expect($supervisors->status())->toBe(0);
});
