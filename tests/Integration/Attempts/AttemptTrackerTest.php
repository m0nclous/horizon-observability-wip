<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use DF\HorizonObservability\Attempts\AttemptTracker;
use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Redis\Keys;
use DF\HorizonObservability\Tests\Fixtures\Attempts\Events;
use DF\HorizonObservability\Tests\Fixtures\Attempts\FakeJob;
use DF\HorizonObservability\Tests\Fixtures\Attempts\FakeRedisJob;
use DF\HorizonObservability\Tests\Fixtures\Attempts\Storage;
use DF\HorizonObservability\Tests\Support\LogRecorder;
use DF\HorizonObservability\Tests\Support\QueuePayloads;
use DF\HorizonObservability\Tests\Support\RedisCommands;
use DF\HorizonObservability\Tests\Support\RedisKeys;
use DF\HorizonObservability\Tests\TestCase;
use Illuminate\Queue\Events\JobExceptionOccurred;
use Illuminate\Queue\Events\JobProcessing;
use Illuminate\Queue\Events\JobTimedOut;
use Illuminate\Queue\Jobs\SyncJob;
use Illuminate\Queue\MaxAttemptsExceededException;

/*
| AttemptTracker state machine (Arch Q6.2): every event sequence of the table, with real RedisJobs and real events of
| the installed Laravel, dispatched through the app's event dispatcher (the provider subscribed the tracker at boot).
*/

pest()->group('redis');

beforeEach(function (): void {
    $this->keys = new Keys;
    $this->travelTo(CarbonImmutable::createFromTimestamp(1_700_000_100.5));
    $this->job = static fn (array $overrides = [], string $class = 'App\\Jobs\\SendInvoice'): FakeRedisJob => FakeRedisJob::make(
        QueuePayloads::job($class, [...['pushedAt' => '1700000098.5', 'createdAt' => 1_700_000_098, 'delay' => null], ...$overrides]),
    );
});

it('records a completed attempt with its duration and the wait of a first attempt', function (): void {
    $job = ($this->job)();

    event(new JobProcessing('redis', $job));
    event(Events::attempted($job));

    expect(Storage::hash($this->keys->attempts()))->toBe(['redis|default|App\\Jobs\\SendInvoice|completed' => '1'])
        ->and(Storage::hash($this->keys->durationBuckets()))->toBe(['redis|default|App\\Jobs\\SendInvoice|0.05' => '1'])
        ->and(Storage::hash($this->keys->waitBuckets()))->toBe(['redis|default|2.5' => '1'])
        ->and(Storage::hash($this->keys->waitSum()))->toBe(['redis|default' => '2']);
});

it('makes no Redis call at JobProcessing (C11)', function (): void {
    $job = ($this->job)();
    $commands = RedisCommands::start($this->app);

    event(new JobProcessing('redis', $job));

    expect($commands->count())->toBe(0);

    event(Events::attempted($job));

    expect($commands->names())->toBe(['EVAL']);
});

it('records a released attempt without a wait', function (): void {
    $job = ($this->job)(['attempts' => 1]);

    event(new JobProcessing('redis', $job));
    $job->release(10);
    event(Events::attempted($job));

    expect(Storage::hash($this->keys->attempts()))->toBe(['redis|default|App\\Jobs\\SendInvoice|released' => '1'])
        ->and(Storage::exists($this->keys->waitBuckets()))->toBeFalse();
});

it('records an exception with a retry as retried', function (): void {
    $job = ($this->job)();

    event(new JobProcessing('redis', $job));
    $job->release();
    event(Events::attempted($job, threw: true));

    expect(Storage::hash($this->keys->attempts()))->toBe(['redis|default|App\\Jobs\\SendInvoice|retried' => '1']);
});

it('records a final failure with its duration', function (): void {
    $job = ($this->job)(['attempts' => 2]);

    event(new JobProcessing('redis', $job));
    $job->markAsFailed();
    event(Events::attempted($job, threw: true));

    expect(Storage::hash($this->keys->attempts()))->toBe(['redis|default|App\\Jobs\\SendInvoice|failed' => '1'])
        ->and(Storage::hash($this->keys->durationBuckets()))->toHaveCount(1);
});

it('records max attempts exceeded as failed without a duration sample', function (): void {
    $job = ($this->job)(['attempts' => 3]);

    event(new JobProcessing('redis', $job));
    $job->markAsFailed();
    event(new JobExceptionOccurred('redis', $job, new MaxAttemptsExceededException('Too many attempts.')));
    event(Events::attempted($job, threw: true));

    expect(Storage::hash($this->keys->attempts()))->toBe(['redis|default|App\\Jobs\\SendInvoice|failed' => '1'])
        ->and(Storage::exists($this->keys->durationBuckets()))->toBeFalse()
        ->and(Storage::exists($this->keys->durationSum()))->toBeFalse();
});

it('keeps the duration of an attempt whose handled exception is not max attempts', function (): void {
    $job = ($this->job)();

    event(new JobProcessing('redis', $job));
    event(new JobExceptionOccurred('redis', $job, new RuntimeException('Boom.')));
    $job->release();
    event(Events::attempted($job, threw: true));

    expect(Storage::hash($this->keys->durationBuckets()))->toBe(['redis|default|App\\Jobs\\SendInvoice|0.05' => '1']);
});

it('records a timeout with the duration capped at the timeout', function (): void {
    $job = ($this->job)(['timeout' => 0.001]);

    event(new JobProcessing('redis', $job));
    usleep(20_000);
    event(new JobTimedOut('redis', $job));

    expect(Storage::hash($this->keys->attempts()))->toBe(['redis|default|App\\Jobs\\SendInvoice|timed_out' => '1'])
        ->and(Storage::hash($this->keys->extremes(113_333_340, 'max')))->toBe(['redis|default|App\\Jobs\\SendInvoice' => '0.001']);
});

it('records the real wait of a retried job that was delayed (C19)', function (): void {
    // A Horizon retry keeps createdAt and delay but gets a fresh pushedAt; the pilot added the old delay to it and
    // recorded a wait of 0.
    $job = ($this->job)(['createdAt' => 1_699_996_500, 'delay' => 600, 'pushedAt' => '1700000095.5', 'attempts' => 0]);

    event(new JobProcessing('redis', $job));
    event(Events::attempted($job));

    expect(Storage::hash($this->keys->waitBuckets()))->toBe(['redis|default|5' => '1'])
        ->and(Storage::hash($this->keys->waitSum()))->toBe(['redis|default' => '5']);
});

it('records the wait of a job delayed at dispatch from createdAt + delay', function (): void {
    $job = ($this->job)(['createdAt' => 1_700_000_000, 'delay' => 99, 'pushedAt' => '1700000000.25']);

    event(new JobProcessing('redis', $job));
    event(Events::attempted($job));

    expect(Storage::hash($this->keys->waitSum()))->toBe(['redis|default' => '1.5']);
});

it('normalises the job class from the payload displayName', function (): void {
    $job = ($this->job)([], 'Closure (routes/web.php:42)');

    event(new JobProcessing('redis', $job));
    event(Events::attempted($job));

    expect(Storage::hash($this->keys->attempts()))->toBe(['redis|default|Closure (routes/web.php)|completed' => '1']);
});

it('ignores JobAttempted without JobProcessing', function (): void {
    event(Events::attempted(($this->job)()));

    expect(RedisKeys::all(TestCase::redisPrefix()))->toBe([]);
});

it('ignores jobs that are not RedisJobs (C6)', function (): void {
    $sync = new SyncJob($this->app, QueuePayloads::encode(QueuePayloads::job()), 'sync', 'default');
    $other = new FakeJob(QueuePayloads::job(), 'database');

    foreach ([$sync, $other] as $job) {
        event(new JobProcessing($job->getConnectionName(), $job));
        event(Events::attempted($job));
        event(new JobTimedOut($job->getConnectionName(), $job));
    }

    expect(RedisKeys::all(TestCase::redisPrefix()))->toBe([]);
});

it('records nothing while collectors.attempts is off', function (): void {
    config()->set('horizon-observability.collectors.attempts', false);
    $this->app->forgetInstance(Config::class);
    $job = ($this->job)();

    event(new JobProcessing('redis', $job));
    event(Events::attempted($job));

    expect(RedisKeys::all(TestCase::redisPrefix()))->toBe([]);
});

it('keeps the state of an outer job across a nested dispatchSync', function (): void {
    $outer = ($this->job)();
    $nested = new SyncJob($this->app, QueuePayloads::encode(QueuePayloads::job('App\\Jobs\\Nested')), 'sync', 'sync');

    event(new JobProcessing('redis', $outer));
    event(new JobProcessing('sync', $nested));
    event(Events::attempted($nested, connectionName: 'sync'));
    event(Events::attempted($outer));

    expect(Storage::hash($this->keys->attempts()))->toBe(['redis|default|App\\Jobs\\SendInvoice|completed' => '1']);
});

it('tracks interleaved jobs independently', function (): void {
    $first = ($this->job)([], 'App\\Jobs\\First');
    $second = ($this->job)([], 'App\\Jobs\\Second');

    event(new JobProcessing('redis', $first));
    event(new JobProcessing('redis', $second));
    $first->markAsFailed();
    event(Events::attempted($first, threw: true));
    event(Events::attempted($second));

    expect(Storage::hash($this->keys->attempts()))->toBe([
        'redis|default|App\\Jobs\\First|failed' => '1',
        'redis|default|App\\Jobs\\Second|completed' => '1',
    ]);
});

it('never lets a failure reach the worker and logs it throttled', function (): void {
    $this->app->instance('log', $log = new LogRecorder);
    $broken = FakeRedisJob::raw('{"attempts":');

    event(new JobProcessing('redis', $broken));
    event(new JobProcessing('redis', $broken));
    event(Events::attempted($broken));

    expect(RedisKeys::all(TestCase::redisPrefix()))->toBe([])
        ->and($log->messages('warning'))->toHaveCount(1)
        ->and($log->messages('warning')[0])->toStartWith('horizon-observability: tracker failed: TypeError');
});

it('releases the state with the job object', function (): void {
    $tracker = app(AttemptTracker::class);
    $job = ($this->job)();
    $tracker->onProcessing(new JobProcessing('redis', $job));
    $states = (fn () => $this->states)->call($tracker);

    expect($states)->toHaveCount(1);

    unset($job);
    gc_collect_cycles();

    expect($states)->toHaveCount(0);
});
