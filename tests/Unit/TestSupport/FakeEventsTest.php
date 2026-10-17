<?php

declare(strict_types=1);

use DF\HorizonObservability\Attempts\AttemptOutcome;
use DF\HorizonObservability\Tests\Support\FakeEvents;
use Illuminate\Contracts\Queue\Job;

it('builds the Laravel 11.23-12 and 13 event shapes', function (): void {
    $job = Mockery::mock(Job::class);
    $job->shouldReceive('timeout')->andReturn(90);

    expect(get_object_vars(FakeEvents::attempted12($job, true)))->toBe(['connectionName' => 'redis', 'job' => $job, 'exceptionOccurred' => true])
        ->and(get_object_vars(FakeEvents::attempted13($job)))->toBe(['connectionName' => 'redis', 'job' => $job, 'exception' => null])
        ->and(get_object_vars(FakeEvents::timedOut($job)))->toBe(['connectionName' => 'redis', 'job' => $job])
        ->and(get_object_vars(FakeEvents::timedOut($job, 30, 'other')))->toBe(['connectionName' => 'other', 'job' => $job, 'timeout' => 30])
        ->and(AttemptOutcome::threw(FakeEvents::attempted12($job, true)))->toBeTrue()
        ->and(AttemptOutcome::threw(FakeEvents::attempted12($job)))->toBeFalse()
        ->and(AttemptOutcome::threw(FakeEvents::attempted13($job, new RuntimeException('x'))))->toBeTrue()
        ->and(AttemptOutcome::threw(FakeEvents::attempted13($job)))->toBeFalse()
        ->and(AttemptOutcome::timeoutSeconds(FakeEvents::timedOut($job, 30), $job))->toBe(30.0)
        ->and(AttemptOutcome::timeoutSeconds(FakeEvents::timedOut($job), $job))->toBe(90.0);
});
