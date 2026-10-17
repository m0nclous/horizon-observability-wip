<?php

declare(strict_types=1);

use DF\HorizonObservability\Redis\Keys;
use DF\HorizonObservability\Tests\Fixtures\Attempts\Jobs\CompletingJob;
use DF\HorizonObservability\Tests\Fixtures\Attempts\Jobs\FailingJob;
use DF\HorizonObservability\Tests\Fixtures\Attempts\Jobs\NestingJob;
use DF\HorizonObservability\Tests\Fixtures\Attempts\Jobs\ReleasingJob;
use DF\HorizonObservability\Tests\Fixtures\Attempts\Storage;
use DF\HorizonObservability\Tests\Support\RunsWorker;

/*
| AttemptTracker with a real in-process worker on Horizon's Redis queue (Arch 8.4): completed, released, retried,
| failed, max attempts exceeded (no duration), a nested dispatchSync(), and the wait of first attempts only.
*/

pest()->group('redis');

uses(RunsWorker::class);

beforeEach(function (): void {
    $this->keys = new Keys;
    // Final failures are logged by the worker's failed-job provider; the workbench has no failed_jobs table.
    config()->set('queue.failed.driver', 'null');
    $this->app->forgetInstance('queue.failer');
});

it('records a completed attempt with its duration and wait', function (): void {
    CompletingJob::dispatch(20)->onConnection('redis');

    $this->runWorker();

    expect(Storage::statuses(CompletingJob::class))->toBe(['completed' => 1])
        ->and(Storage::durations(CompletingJob::class))->toBe(1)
        ->and(Storage::waits())->toBe(1)
        ->and((float) Storage::hash($this->keys->durationSum())['redis|default|'.CompletingJob::class])->toBeGreaterThanOrEqual(0.02);
});

it('records a release, then the completed second attempt without a second wait', function (): void {
    ReleasingJob::dispatch()->onConnection('redis');

    $this->runWorker();

    expect(Storage::statuses(ReleasingJob::class))->toBe(['completed' => 1, 'released' => 1])
        ->and(Storage::durations(ReleasingJob::class))->toBe(2)
        ->and(Storage::waits())->toBe(1);
});

it('records an exception with a retry as retried, then the final failure', function (): void {
    FailingJob::dispatch(2)->onConnection('redis');

    $this->runWorker();

    expect(Storage::statuses(FailingJob::class))->toBe(['failed' => 1, 'retried' => 1])
        ->and(Storage::durations(FailingJob::class))->toBe(2);
});

it('records max attempts exceeded as failed without a duration', function (): void {
    ReleasingJob::dispatch(1)->onConnection('redis');

    $this->runWorker();

    expect(Storage::statuses(ReleasingJob::class))->toBe(['failed' => 1, 'released' => 1])
        ->and(Storage::durations(ReleasingJob::class))->toBe(1);
});

it('records a job that runs a nested dispatchSync once and the nested job not at all', function (): void {
    NestingJob::dispatch()->onConnection('redis');

    $this->runWorker();

    expect(Storage::statuses(NestingJob::class))->toBe(['completed' => 1])
        ->and(Storage::statuses(CompletingJob::class))->toBe([])
        ->and(cache()->get('completing-job'))->toBe(1);
});
