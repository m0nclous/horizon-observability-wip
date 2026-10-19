<?php

declare(strict_types=1);

use DF\HorizonObservability\Attempts\AttemptOutcome;
use DF\HorizonObservability\Attempts\AttemptStatus;
use DF\HorizonObservability\Tests\Fixtures\Attempts\Events;
use DF\HorizonObservability\Tests\Fixtures\Attempts\FakeJob;
use DF\HorizonObservability\Tests\Support\FakeEvents;
use Illuminate\Queue\Events\JobTimedOut;

/*
| The Laravel 11.23 / 12 / 13 shim. The pilot read JobAttempted::$exceptionOccurred,
| which Laravel 13 removed: every released job raised "Undefined property" into the worker. Real events of the
| installed Laravel plus stdClass fakes of the 11.23/12 and 13 shapes run every branch on every CI leg.
*/

it('reads whether the attempt threw from the 11.23/12 shape', function (): void {
    expect(AttemptOutcome::threw(FakeEvents::attempted12(new FakeJob, true)))->toBeTrue()
        ->and(AttemptOutcome::threw(FakeEvents::attempted12(new FakeJob)))->toBeFalse();
});

it('reads whether the attempt threw from the 13 shape', function (): void {
    expect(AttemptOutcome::threw(FakeEvents::attempted13(new FakeJob, new RuntimeException('boom'))))->toBeTrue()
        ->and(AttemptOutcome::threw(FakeEvents::attempted13(new FakeJob)))->toBeFalse();
});

it('treats an event with neither property as not thrown', function (): void {
    expect(AttemptOutcome::threw(new stdClass))->toBeFalse();
});

it('maps the outcome of a real JobAttempted of the installed Laravel', function (string $state, bool $threw, AttemptStatus $status): void {
    $job = match ($state) {
        'released' => FakeJob::releasedOne(),
        'failed' => FakeJob::failedOne(),
        default => new FakeJob,
    };

    expect(AttemptOutcome::of(Events::attempted($job, $threw)))->toBe($status);
})->with([
    'completed' => ['running', false, AttemptStatus::Completed],
    'completed after a handled exception' => ['running', true, AttemptStatus::Completed],
    'released' => ['released', false, AttemptStatus::Released],
    'retried' => ['released', true, AttemptStatus::Retried],
    'failed' => ['failed', true, AttemptStatus::Failed],
    'failed without an exception' => ['failed', false, AttemptStatus::Failed],
]);

it('reads the JobTimedOut timeout of 13.25+ before the job timeout', function (): void {
    $job = new FakeJob(['timeout' => 30]);

    expect(AttemptOutcome::timeoutSeconds(FakeEvents::timedOut($job, 90), $job))->toBe(90.0)
        ->and(AttemptOutcome::timeoutSeconds(new JobTimedOut('redis', $job), $job))->toBe(30.0);
});

it('falls back to the job timeout without the 13.25 property', function (): void {
    $job = new FakeJob(['timeout' => 45]);

    expect(AttemptOutcome::timeoutSeconds(FakeEvents::timedOut($job), $job))->toBe(45.0);
});

it('has no timeout when neither the event nor the job sets one', function (mixed $timeout): void {
    $job = new FakeJob(['timeout' => $timeout]);
    $event = FakeEvents::timedOut($job);

    expect(AttemptOutcome::timeoutSeconds($event, $job))->toBeNull();
})->with([null, 0, -5, 'soon']);

it('ignores an unusable event timeout in favour of the job timeout', function (): void {
    $job = new FakeJob(['timeout' => 20]);
    $event = FakeEvents::timedOut($job);
    $event->timeout = null;

    expect(AttemptOutcome::timeoutSeconds($event, $job))->toBe(20.0);
});
