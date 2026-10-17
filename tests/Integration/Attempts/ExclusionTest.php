<?php

declare(strict_types=1);

use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Redis\RedisClients;
use DF\HorizonObservability\Tests\Fixtures\Attempts\Events;
use DF\HorizonObservability\Tests\Fixtures\Attempts\Jobs\CompletingJob;
use DF\HorizonObservability\Tests\Fixtures\Attempts\Jobs\FailingJob;
use DF\HorizonObservability\Tests\Fixtures\Attempts\Storage;
use DF\HorizonObservability\Tests\Support\QueuePayloads;
use DF\HorizonObservability\Tests\Support\RedisCommands;
use Illuminate\Queue\BackgroundQueue;
use Illuminate\Queue\DatabaseQueue;
use Illuminate\Queue\DeferredQueue;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Jobs\DatabaseJob;
use Illuminate\Support\Defer\DeferredCallbackCollection;

/*
| Only RedisJobs that passed JobProcessing are recorded (C6). Laravel 12.45+ fires JobAttempted in SyncQueue, which
| deferred and background queues extend; the pilot recorded those attempts and wrote to Redis from the web process
| (a 60 s connect timeout per sync dispatch with an unreachable metrics Redis).
*/

pest()->group('redis');

beforeEach(function (): void {
    $this->commands = RedisCommands::start($this->app);
});

it('sync, deferred and background leave no keys (C6)', function (): void {
    config()->set('queue.connections.deferred', ['driver' => 'deferred']);
    config()->set('queue.connections.background', ['driver' => 'background']);
    // The background queue defers to the process concurrency driver; run it in this process instead.
    config()->set('concurrency.driver.process', ['driver' => 'sync']);

    CompletingJob::dispatch()->onConnection('sync');
    CompletingJob::dispatchSync();

    try {
        FailingJob::dispatchSync();
    } catch (RuntimeException) {
        // A failed sync attempt is rethrown to the caller.
    }

    $ran = 2;

    if (class_exists(DeferredQueue::class)) {
        CompletingJob::dispatch()->onConnection('deferred');
        $ran++;
    }

    if (class_exists(BackgroundQueue::class)) {
        CompletingJob::dispatch()->onConnection('background');
        $ran++;
    }

    app(DeferredCallbackCollection::class)->invoke();

    expect(cache()->get('completing-job'))->toBe($ran)
        ->and(Storage::packageKeys())->toBe([])
        ->and($this->commands->count(RedisClients::STORAGE))->toBe(0);
});

it('makes no Redis call for a sync dispatch even when the storage is unreachable (C6, C11)', function (): void {
    config()->set('database.redis.unreachable', ['host' => '10.255.255.1', 'port' => 6379, 'database' => 0]);
    config()->set('horizon-observability.redis.connection', 'unreachable');
    $this->app->forgetInstance(Config::class);
    $this->app->forgetInstance(RedisClients::class);

    $started = hrtime(true);
    CompletingJob::dispatch()->onConnection('sync');

    expect((hrtime(true) - $started) / 1e9)->toBeLessThan(0.5)
        ->and(cache()->get('completing-job'))->toBe(1);
});

it('does not track jobs of a database connection', function (): void {
    $record = (object) [
        'id' => 1,
        'queue' => 'default',
        'payload' => QueuePayloads::encode(QueuePayloads::job()),
        'attempts' => 0,
        'reserved_at' => null,
        'available_at' => time(),
        'created_at' => time(),
    ];
    $job = new DatabaseJob($this->app, new DatabaseQueue(app('db')->connection(), 'jobs'), $record, 'database', 'default');

    event(new JobProcessing('database', $job));
    event(Events::attempted($job, connectionName: 'database'));

    expect(Storage::packageKeys())->toBe([])
        ->and($this->commands->count())->toBe(0);
});
