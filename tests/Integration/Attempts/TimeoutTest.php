<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use DF\HorizonObservability\Redis\Keys;
use DF\HorizonObservability\Redis\RedisClients;
use DF\HorizonObservability\Tests\Fixtures\Attempts\Events;
use DF\HorizonObservability\Tests\Fixtures\Attempts\FakeRedisJob;
use DF\HorizonObservability\Tests\Fixtures\Attempts\Jobs\SleepingJob;
use DF\HorizonObservability\Tests\Fixtures\Attempts\Storage;
use DF\HorizonObservability\Tests\Support\QueuePayloads;
use DF\HorizonObservability\Tests\Support\RedisCommands;
use DF\HorizonObservability\Tests\Support\RedisKeys;
use DF\HorizonObservability\Tests\Support\RunsWorker;
use DF\HorizonObservability\Tests\TestCase;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Queue\TimeoutExceededException;
use Illuminate\Queue\Worker;

/*
| SIGALRM races (C20). (a) The worker keeps the alarm armed while JobAttempted listeners run, so a late JobTimedOut
| can follow JobAttempted; on 13.33+ with killOnTimeout=false JobAttempted follows JobTimedOut. The pilot counted
| such an attempt twice. (b) JobTimedOut fires from the signal handler, possibly while the job is half-way through a
| Redis pipeline: the pilot's record ran exec() on the job's own phpredis client and committed the job's commands.
*/

pest()->group('redis');

uses(RunsWorker::class);

beforeEach(function (): void {
    $this->keys = new Keys;
    $this->travelTo(CarbonImmutable::createFromTimestamp(1_700_000_100.5));
    $this->job = static fn (): FakeRedisJob => FakeRedisJob::make(QueuePayloads::job('App\\Jobs\\SendInvoice', ['timeout' => 60]));
});

it('counts a late JobTimedOut after JobAttempted once (C20)', function (): void {
    $job = ($this->job)();

    event(new JobProcessing('redis', $job));
    event(Events::attempted($job));
    event(new JobTimedOut('redis', $job));

    expect(Storage::hash($this->keys->attempts()))->toBe(['redis|default|App\\Jobs\\SendInvoice|completed' => '1']);
});

it('counts a JobAttempted after JobTimedOut once, as timed out (C20, killOnTimeout=false)', function (): void {
    $job = ($this->job)();

    event(new JobProcessing('redis', $job));
    event(new JobTimedOut('redis', $job));
    $job->markAsFailed();
    event(new JobExceptionOccurred('redis', $job, new TimeoutExceededException('Timed out.')));
    event(Events::attempted($job, threw: true));

    expect(Storage::hash($this->keys->attempts()))->toBe(['redis|default|App\\Jobs\\SendInvoice|timed_out' => '1'])
        ->and(Storage::hash($this->keys->durationBuckets()))->toBe(['redis|default|App\\Jobs\\SendInvoice|0.05' => '1']);
});

it('records a timeout through the private storage client only (C20)', function (): void {
    $commands = RedisCommands::start($this->app);
    $job = ($this->job)();

    event(new JobProcessing('redis', $job));
    event(new JobTimedOut('redis', $job));

    expect($commands->names(RedisClients::STORAGE))->toBe(['EVAL'])
        ->and(array_diff(array_column($commands->commands(), 'connection'), [RedisClients::STORAGE]))->toBe([]);
});

it('leaves the half-built pipeline of the job untouched (C20)', function (): void {
    $client = app('redis')->connection('horizon')->client();

    if (! $client instanceof Redis) {
        $this->markTestSkipped('Only phpredis clients have a pipeline mode a foreign exec() could flush.');
    }

    $job = ($this->job)();
    event(new JobProcessing('redis', $job));

    $client->multi(Redis::PIPELINE);
    $client->set('job-key', 'from the job');

    event(new JobTimedOut('redis', $job));

    $prefixed = TestCase::redisPrefix().'horizon:job-key';

    expect(RedisKeys::all($prefixed))->toBe([])
        ->and(Storage::hash($this->keys->attempts()))->toBe(['redis|default|App\\Jobs\\SendInvoice|timed_out' => '1']);

    $client->exec();

    expect(RedisKeys::all($prefixed))->toBe([$prefixed]);
});

it('records a real worker timeout once when the worker is not killed (C20, Laravel 13.33+)', function (): void {
    if (! property_exists(Worker::class, 'killOnTimeout') || ! extension_loaded('pcntl')) {
        $this->markTestSkipped('Worker::$killOnTimeout needs Laravel 13.33+ and pcntl.');
    }

    // The handler fails the job before JobTimedOut; the workbench has no failed_jobs table.
    config()->set('queue.failed.driver', 'null');
    $this->app->forgetInstance('queue.failer');
    $this->travelBack();
    Worker::$killOnTimeout = false;

    try {
        SleepingJob::dispatch()->onConnection('redis');
        $this->runWorker(options: ['--timeout' => 1]);
    } finally {
        Worker::$killOnTimeout = true;
    }

    $durations = Storage::hash($this->keys->durationBuckets());

    expect(Storage::hash($this->keys->attempts()))->toBe([
        'redis|default|'.SleepingJob::class.'|timed_out' => '1',
    ])->and($durations)->toBe(['redis|default|'.SleepingJob::class.'|1' => '1'])
        ->and((float) Storage::hash($this->keys->durationSum())['redis|default|'.SleepingJob::class])->toBeLessThanOrEqual(1.0)->toBeGreaterThan(0.5);
});
