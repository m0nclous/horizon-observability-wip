<?php

declare(strict_types=1);

use DF\HorizonObservability\Horizon\Topology;
use Workbench\App\Simulation\BatchProfile;
use Workbench\App\Simulation\Outcome;
use Workbench\App\Simulation\SimulatedJob;
use Workbench\App\Simulation\TaskProfile;

/*
| The load simulation's scenario (T11): the simulated queues are exactly the queues the workbench Horizon topology runs,
| and the outcome arithmetic of the one-shot and continuous runs.
*/

it('simulates exactly the queues the workbench Horizon runs', function (): void {
    $horizon = app(Topology::class)->queuesByConnection()['redis'] ?? [];

    expect(TaskProfile::queues())->toEqualCanonicalizing($horizon)
        ->and(array_unique(array_map(static fn (BatchProfile $batch): string => $batch->queue, BatchProfile::catalog())))
        ->each->toBeIn($horizon);
});

it('gives every task a unique job name', function (): void {
    $names = [
        ...array_map(static fn (TaskProfile $task): string => $task->name, TaskProfile::catalog()),
        ...array_map(static fn (BatchProfile $batch): string => $batch->task, BatchProfile::catalog()),
    ];

    expect($names)->toHaveCount(count(array_unique($names)));
});

it('covers every attempt status of the dashboard', function (): void {
    $outcomes = [];

    foreach (TaskProfile::catalog() as $task) {
        $outcomes = [...$outcomes, ...array_keys($task->oneShotCounts(1.0))];
    }

    expect(array_values(array_unique($outcomes)))->toEqualCanonicalizing(array_map(static fn (Outcome $outcome): string => $outcome->value, Outcome::cases()));
});

it('splits the one-shot jobs of a task by outcome', function (): void {
    $task = new TaskProfile('X', 'default', 80, 20, 0.8, failPercent: 5, retryPercent: 5, timeouts: 1, exhaustPercent: 3, releasePercent: 10);

    expect($task->oneShotCounts(1.0))->toBe([
        'completed' => 61,
        'released' => 8,
        'retried' => 4,
        'exhausted' => 2,
        'failed' => 4,
        'timed_out' => 1,
    ])
        ->and(array_sum($task->oneShotCounts(2.5)))->toBe(200)
        ->and($task->oneShotCounts(0.0))->toBe([]);
});

it('never schedules more failures than jobs', function (): void {
    $broken = new TaskProfile('Broken', 'default', 3, 1, 0.1, failPercent: 100, retryPercent: 50, timeouts: 5);

    expect($broken->oneShotCounts(1.0))->toBe(['timed_out' => 3]);
});

it('rolls the continuous outcomes by cumulative thresholds', function (): void {
    // timed out 1 %, failed +3 % (10 x 0.3), exhausted +0.6 %, retried +5 %, released +2 %
    $task = new TaskProfile('X', 'default', 10, 10, 1.0, failPercent: 10, retryPercent: 5, timeouts: 1, exhaustPercent: 2, releasePercent: 2);

    expect($task->continuousOutcome(0.5))->toBe(Outcome::TimedOut)
        ->and($task->continuousOutcome(3.9))->toBe(Outcome::Failed)
        ->and($task->continuousOutcome(4.5))->toBe(Outcome::Exhausted)
        ->and($task->continuousOutcome(9.5))->toBe(Outcome::Retried)
        ->and($task->continuousOutcome(11.5))->toBe(Outcome::Released)
        ->and($task->continuousOutcome(11.7))->toBe(Outcome::Completed)
        ->and((new TaskProfile('Y', 'default', 1, 1, 1.0))->continuousOutcome(0.0))->toBe(Outcome::Completed);
});

it('derives the job timeout like a real job class', function (): void {
    expect((new TaskProfile('Fast', 'default', 1, 1, 0.05))->timeoutSeconds())->toBe(5)
        ->and((new TaskProfile('Slow', 'reports', 1, 1, 15.0))->timeoutSeconds())->toBe(30);
});

it('names the batch kinds as the dashboard shows them', function (): void {
    expect(array_map(static fn (BatchProfile $batch): string => $batch->kind(), BatchProfile::catalog()))
        ->toBe(['test_import', 'test_report', 'test_sync']);
});

it('configures a simulated job by its outcome', function (Outcome $outcome, int $tries): void {
    $job = SimulatedJob::of(new TaskProfile('TestPingJob', 'default', 1, 1, 0.5), $outcome);

    expect($job->tries)->toBe($tries)
        ->and($job->timeout)->toBe(5)
        ->and($job->displayName())->toBe('TestPingJob')
        ->and($job->plannedSeconds(1.5))->toBe($outcome === Outcome::TimedOut ? 7.0 : 0.75);
})->with([
    'completed' => [Outcome::Completed, 1],
    'released' => [Outcome::Released, 2],
    'retried' => [Outcome::Retried, 2],
    'exhausted' => [Outcome::Exhausted, 3],
    'failed' => [Outcome::Failed, 1],
    'timed out sleeps past its timeout' => [Outcome::TimedOut, 1],
]);

it('warms a task up with an instant completed job', function (): void {
    $job = SimulatedJob::warmup(new TaskProfile('TestImportJob', 'slow', 1, 1, 4.0));

    expect($job->outcome)->toBe(Outcome::Completed)
        ->and($job->plannedSeconds(1.5))->toBe(0.0)
        ->and($job->timeout)->toBe(8);
});
