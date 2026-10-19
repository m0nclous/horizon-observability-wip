<?php

declare(strict_types=1);

use Illuminate\Support\Facades\Queue;
use Workbench\App\Simulation\SimulatedJob;
use Workbench\App\Simulation\TaskProfile;

/*
| GET /demo/dispatch?count=&queue= (workbench/routes/web.php): a manual burst of simulated jobs.
*/

beforeEach(function (): void {
    config()->set('queue.default', 'redis');
    Queue::fake();
});

it('dispatches ten jobs on the default queue by default', function (): void {
    $this->getJson('/demo/dispatch')
        ->assertOk()
        ->assertExactJson(['connection' => 'redis', 'queue' => 'default', 'dispatched' => 10]);

    Queue::assertPushedOn('default', SimulatedJob::class);
    Queue::assertCount(10);
});

it('dispatches a burst of the tasks of a queue', function (): void {
    $this->getJson('/demo/dispatch?count=25&queue=sms')
        ->assertOk()
        ->assertJsonPath('dispatched', 25);

    $names = array_map(static fn (TaskProfile $task): string => $task->name, TaskProfile::forQueue('sms'));

    Queue::assertCount(25);
    Queue::assertPushed(SimulatedJob::class, static fn (SimulatedJob $job): bool => in_array($job->name, $names, true) && $job->queue === 'sms');
});

it('validates the count and the queue', function (string $query, string $field): void {
    $this->getJson('/demo/dispatch?'.$query)->assertUnprocessable()->assertJsonValidationErrors([$field]);

    Queue::assertNothingPushed();
})->with([
    'zero' => ['count=0', 'count'],
    'too many' => ['count=1001', 'count'],
    'not a number' => ['count=many', 'count'],
    'unknown queue' => ['queue=nope', 'queue'],
]);

it('refuses a default connection without the redis driver', function (): void {
    config()->set('queue.default', 'sync');

    $this->getJson('/demo/dispatch')->assertUnprocessable();

    Queue::assertNothingPushed();
});

it('refuses to run in production', function (): void {
    app()['env'] = 'production';

    $this->getJson('/demo/dispatch')->assertForbidden();

    Queue::assertNothingPushed();
});
