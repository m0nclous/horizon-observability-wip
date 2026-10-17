<?php

declare(strict_types=1);

use DF\HorizonObservability\Tests\Support\RunsWorker;
use Illuminate\Queue\Events\JobFailed;
use Illuminate\Queue\Events\JobProcessed;
use Illuminate\Queue\Events\JobReleasedAfterException;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Workbench\App\Simulation\Outcome;
use Workbench\App\Simulation\SimulatedFailure;
use Workbench\App\Simulation\SimulatedJob;

/*
| The simulated outcomes on a real Redis queue with an in-process worker (T11): each outcome produces the attempt
| sequence the dashboard counts. Timed-out jobs kill their worker, so they are covered by the unit test only.
*/

pest()->group('redis');

uses(RunsWorker::class);

beforeEach(function (): void {
    config()->set('queue.failed.driver', 'null');
});

/**
 * Runs one simulated job to the end with `$work` (the worker) and returns its attempt events in order.
 *
 * @param  Closure(): mixed  $work
 * @return list<string>
 */
function simulationAttempts(Closure $work, Outcome $outcome): array
{
    $events = [];
    Event::listen(JobProcessed::class, static function (JobProcessed $event) use (&$events): void {
        $events[] = $event->job->isReleased() ? 'released' : ($event->job->hasFailed() ? 'failed' : 'completed');
    });
    Event::listen(JobReleasedAfterException::class, static function () use (&$events): void {
        $events[] = 'retried';
    });
    Event::listen(JobFailed::class, static function (JobFailed $event) use (&$events): void {
        $events[] = 'failed: '.$event->exception::class;
    });

    dispatch((new SimulatedJob('TestPingJob', $outcome, 0.0, 5))->onConnection('redis')->onQueue('default'));
    $work();

    return $events;
}

it('produces the attempt sequence of each outcome', function (Outcome $outcome, array $expected): void {
    expect(simulationAttempts(fn (): int => $this->runWorker('default'), $outcome))->toBe($expected);
})->with([
    'completed' => [Outcome::Completed, ['completed']],
    'retried completes on its second attempt' => [Outcome::Retried, ['retried', 'completed']],
    'exhausted fails after three attempts' => [Outcome::Exhausted, ['retried', 'retried', 'failed: '.SimulatedFailure::class]],
    'failed fails itself without throwing' => [Outcome::Failed, ['failed: '.SimulatedFailure::class, 'failed']],
]);

it('releases itself with a delay on the first attempt', function (): void {
    expect(simulationAttempts(fn (): int => $this->runWorker('default'), Outcome::Released))->toBe(['released'])
        ->and(Queue::connection('redis')->size('default'))->toBe(1)
        ->and(Queue::connection('redis')->delayedSize('default'))->toBe(1);
});
