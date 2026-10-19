<?php

declare(strict_types=1);

use Illuminate\Bus\PendingBatch;
use Illuminate\Support\Facades\Bus;
use Workbench\App\Simulation\BatchProfile;
use Workbench\App\Simulation\Outcome;
use Workbench\App\Simulation\SimulatedJob;
use Workbench\App\Simulation\TaskProfile;

/*
| workbench:simulate-load (replaces the pilot's queue-metrics:load-test). It refuses production and every queue
| connection without the redis driver (with sync the jobs would run inline in the command).
*/

beforeEach(function (): void {
    // testbench.yaml sets QUEUE_CONNECTION=redis for the workbench; the test environment defaults to database.
    config()->set('queue.default', 'redis');
    Bus::fake();
});

/**
 * @return list<SimulatedJob>
 */
function simulationDispatched(): array
{
    return array_values(Bus::dispatched(SimulatedJob::class)->all());
}

it('dispatches the one-shot scenario with warm-up jobs and batches', function (): void {
    $this->artisan('workbench:simulate-load', ['--seed' => 7])
        ->expectsOutputToContain('The load starts in 30 s and lasts about 120 s.')
        ->assertSuccessful();

    $tasks = TaskProfile::catalog();
    $expected = array_sum(array_map(static fn (TaskProfile $task): int => array_sum($task->oneShotCounts(1.0)), $tasks));
    $jobs = simulationDispatched();
    $warmups = array_filter($jobs, static fn (SimulatedJob $job): bool => $job->delay === null);
    $delays = array_map(static fn (SimulatedJob $job): mixed => $job->delay, array_filter($jobs, static fn (SimulatedJob $job): bool => $job->delay !== null));

    expect($jobs)->toHaveCount($expected + count($tasks))
        ->and($warmups)->toHaveCount(count($tasks))
        ->and(min($delays))->toBe(30)
        ->and(max($delays))->toBeLessThan(150)
        ->and(array_unique(array_map(static fn (SimulatedJob $job): ?string => $job->connection, $jobs)))->toBe(['redis'])
        ->and(array_values(array_unique(array_map(static fn (SimulatedJob $job): ?string => $job->queue, $jobs))))->toEqualCanonicalizing(TaskProfile::queues());

    foreach ($tasks as $task) {
        $ofTask = array_filter($jobs, static fn (SimulatedJob $job): bool => $job->name === $task->name && $job->delay !== null);
        $outcomes = array_count_values(array_map(static fn (SimulatedJob $job): string => $job->outcome->value, $ofTask));

        expect($outcomes)->toEqualCanonicalizing($task->oneShotCounts(1.0));
    }

    Bus::assertBatchCount(count(BatchProfile::catalog()));
    Bus::assertBatched(static fn (PendingBatch $batch): bool => str_starts_with((string) $batch->name, 'test_report_')
        && $batch->allowsFailures()
        && $batch->queue() === 'reports'
        && $batch->jobs->count() === 6);
});

it('loads only the selected queues at a scale, without warm-up or batches', function (): void {
    $this->artisan('workbench:simulate-load', ['--queue' => ['slow'], '--scale' => 2, '--warmup' => 0, '--spread' => 0, '--batches' => 0])
        ->assertSuccessful();

    $jobs = simulationDispatched();
    $expected = array_sum(array_map(static fn (TaskProfile $task): int => array_sum($task->oneShotCounts(2.0)), TaskProfile::forQueue('slow')));

    expect($jobs)->toHaveCount($expected)
        ->and(array_unique(array_map(static fn (SimulatedJob $job): ?string => $job->queue, $jobs)))->toBe(['slow'])
        ->and(array_unique(array_map(static fn (SimulatedJob $job): mixed => $job->delay, $jobs)))->toBe([null]);

    Bus::assertBatchCount(0);
});

it('is reproducible with a seed', function (): void {
    $run = function (): array {
        Bus::fake();
        $this->artisan('workbench:simulate-load', ['--queue' => ['default'], '--seed' => 42, '--batches' => 0])->assertSuccessful();

        return array_map(static fn (SimulatedJob $job): string => $job->name.'/'.$job->outcome->value.'/'.$job->delay, simulationDispatched());
    };

    expect($run())->toBe($run());
});

it('streams jobs and a batch in continuous mode until the duration ends', function (): void {
    $started = microtime(true);

    $this->artisan('workbench:simulate-load', ['--continuous' => true, '--duration' => 1, '--scale' => 20, '--seed' => 3])
        ->expectsOutputToContain('Continuous load on default, slow, reports, mail, sms at 20x the base rates for 1 s.')
        ->expectsOutputToContain('Load stopped.')
        ->assertSuccessful();

    $outcomes = array_unique(array_map(static fn (SimulatedJob $job): Outcome => $job->outcome, simulationDispatched()), SORT_REGULAR);

    expect(microtime(true) - $started)->toBeLessThan(3.0)
        ->and(simulationDispatched())->not->toBeEmpty()
        ->and($outcomes)->toContain(Outcome::Completed);

    Bus::assertBatchCount(1);
});

it('refuses to run in production', function (): void {
    app()['env'] = 'production';

    $this->artisan('workbench:simulate-load')
        ->expectsOutputToContain('never runs in production')
        ->assertFailed();

    Bus::assertNothingDispatched();
});

it('refuses queue connections without the redis driver', function (string $connection, string $message): void {
    $this->artisan('workbench:simulate-load', ['--connection' => $connection])
        ->expectsOutputToContain($message)
        ->assertFailed();

    Bus::assertNothingDispatched();
})->with([
    'sync runs jobs inline' => ['sync', '"sync" uses the sync driver'],
    'database' => ['database', '"database" uses the database driver'],
    'unknown' => ['nope', '"nope" uses no configured driver'],
]);

it('refuses unknown queues', function (): void {
    $this->artisan('workbench:simulate-load', ['--queue' => ['default', 'nope']])
        ->expectsOutputToContain('Unknown simulated queues: nope.')
        ->assertFailed();

    Bus::assertNothingDispatched();
});

it('warns about queues Horizon does not process', function (): void {
    config()->set('horizon.environments.testing.supervisor-pool.queue', ['mail']);

    $this->artisan('workbench:simulate-load', ['--queue' => ['sms'], '--batches' => 0])
        ->expectsOutputToContain('Horizon does not process these queues, their jobs will stay queued: sms')
        ->assertSuccessful();
});
