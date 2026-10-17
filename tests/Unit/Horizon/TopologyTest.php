<?php

declare(strict_types=1);

use DF\HorizonObservability\Horizon\Topology;

function topology(): Topology
{
    return new Topology(app('config'));
}

it('reads the workbench topology of the testing environment', function (): void {
    expect(topology()->environment())->toBe('testing')
        ->and(topology()->queuesByConnection())->toBe(['redis' => ['default', 'slow', 'reports', 'mail', 'sms']])
        ->and(topology()->queues())->toBe([
            ['connection' => 'redis', 'queue' => 'default'],
            ['connection' => 'redis', 'queue' => 'slow'],
            ['connection' => 'redis', 'queue' => 'reports'],
            ['connection' => 'redis', 'queue' => 'mail'],
            ['connection' => 'redis', 'queue' => 'sms'],
        ]);
});

it('prefers horizon.env over app.env', function (): void {
    config()->set('horizon.env', 'production');
    config()->set('horizon.environments', ['production' => ['s' => ['connection' => 'redis', 'queue' => 'prod']]]);

    expect(topology()->environment())->toBe('production')
        ->and(topology()->queuesByConnection())->toBe(['redis' => ['prod']]);
});

it('matches environments with Str::is, first match wins', function (): void {
    config()->set('horizon.env', 'staging-2');
    config()->set('horizon.environments', [
        'production' => ['s' => ['connection' => 'redis', 'queue' => 'prod']],
        'staging-*' => ['s' => ['connection' => 'redis', 'queue' => 'staging']],
        '*' => ['s' => ['connection' => 'redis', 'queue' => 'fallback']],
    ]);

    expect(topology()->queuesByConnection())->toBe(['redis' => ['staging']]);
});

it('merges horizon.defaults like Horizon', function (): void {
    config()->set('horizon.defaults', [
        'supervisor-1' => ['connection' => 'redis', 'queue' => ['default'], 'maxProcesses' => 1],
        'supervisor-extra' => ['connection' => 'redis', 'queue' => 'extra', 'maxProcesses' => 1],
    ]);
    config()->set('horizon.environments', ['testing' => ['supervisor-1' => ['queue' => ['high', 'default']]]]);

    expect(topology()->queuesByConnection())->toBe(['redis' => ['high', 'default', 'extra']]);
});

it('skips supervisors that do not start and groups by connection', function (): void {
    config()->set('horizon.defaults', []);
    config()->set('horizon.environments', ['testing' => [
        'a' => ['connection' => 'redis', 'queue' => 'a, b', 'maxProcesses' => 2],
        'off' => ['connection' => 'redis', 'queue' => 'off', 'maxProcesses' => 0],
        'other' => ['connection' => 'redis-two', 'queue' => ['b'], 'processes' => 1],
        'dupe' => ['connection' => 'redis', 'queue' => 'b'],
    ]]);

    expect(topology()->queuesByConnection())->toBe(['redis' => ['a', 'b'], 'redis-two' => ['b']]);
});

it('uses the connection default queue for a supervisor without queue', function (): void {
    config()->set('queue.connections.redis.queue', 'jobs');
    config()->set('horizon.defaults', []);
    config()->set('horizon.environments', ['testing' => ['s' => ['connection' => 'redis']]]);

    expect(topology()->queuesByConnection())->toBe(['redis' => ['jobs']]);
});

it('has no queues without a matching environment or plan', function (mixed $environments): void {
    config()->set('horizon.environments', $environments);

    expect(topology()->queuesByConnection())->toBe([])
        ->and(topology()->queues())->toBe([]);
})->with([
    'no match' => [['production' => ['s' => ['connection' => 'redis', 'queue' => 'q']]]],
    'not an array' => [null],
    'plan not an array' => [['testing' => 'broken']],
]);

it('throws like Horizon on an invalid plan', function (): void {
    config()->set('horizon.environments', ['testing' => ['s' => ['connection' => 'redis', 'queue' => 'q', 'minProcesses' => 0]]]);

    topology()->queuesByConnection();
})->throws(Exception::class, 'minProcesses');

it('reads the wait thresholds like LongWaitDetected', function (): void {
    config()->set('horizon.waits', ['redis:default' => 10, 'redis:slow' => '30', 'redis:off' => 0, 'redis:bad' => 'x']);

    expect(topology()->waits())->toBe(['redis:default' => 10, 'redis:slow' => 30, 'redis:off' => 0])
        ->and(topology()->waitThreshold('redis', 'default'))->toBe(10)
        ->and(topology()->waitThreshold('redis', 'slow'))->toBe(30)
        ->and(topology()->waitThreshold('redis', 'unlisted'))->toBe(Topology::DEFAULT_WAIT_SECONDS)
        ->and(topology()->waitThreshold('redis', 'off'))->toBeNull();
});

it('maps a queue connection to its Redis connection like the Redis connectors', function (): void {
    config()->set('queue.connections', [
        'redis' => ['driver' => 'redis', 'connection' => 'default', 'queue' => 'default'],
        'queues.long' => ['driver' => 'redis', 'connection' => 'queue', 'queue' => 'long'],
        'implicit' => ['driver' => 'redis', 'queue' => 'default'],
        'blank' => ['driver' => 'redis', 'connection' => null],
        'database' => ['driver' => 'database', 'table' => 'jobs'],
        'broken' => 'redis',
    ]);

    expect(topology()->redisConnection('redis'))->toBe('default')
        ->and(topology()->redisConnection('queues.long'))->toBe('queue')
        ->and(topology()->redisConnection('implicit'))->toBe('default')
        ->and(topology()->redisConnection('blank'))->toBe('default')
        ->and(topology()->redisConnection('database'))->toBeNull()
        ->and(topology()->redisConnection('broken'))->toBeNull()
        ->and(topology()->redisConnection('missing'))->toBeNull();
});
