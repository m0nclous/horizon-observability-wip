<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use DF\HorizonObservability\Http\JobList;
use DF\HorizonObservability\Http\JobListQuery;
use DF\HorizonObservability\Http\JobListService;
use DF\HorizonObservability\Tests\Integration\JobStore\Support\HorizonJobs;
use DF\HorizonObservability\Tests\Support\HorizonState;
use DF\HorizonObservability\Tests\Support\QueuePayloads;

/*
| The Supervisor filter of a balance=false supervisor. Horizon runs such a supervisor as one pool whose process label is
| the comma list ("redis:mail,sms"); the pilot compared that label with the single queue of each job, so every table
| came back empty. The queues are now split from the supervisor's queue option.
*/

pest()->group('redis');

beforeEach(function (): void {
    HorizonState::using($this->app)
        ->master('host-a1b2', supervisors: ['host-a1b2:supervisor-default', 'host-a1b2:supervisor-pool'])
        ->supervisor('host-a1b2:supervisor-default', HorizonState::options('host-a1b2:supervisor-default', queue: 'default'), ['redis:default' => 3])
        ->supervisor('host-a1b2:supervisor-pool', HorizonState::options('host-a1b2:supervisor-pool', queue: 'mail,sms', balance: false, maxProcesses: 2), ['redis:mail,sms' => 2]);

    $jobs = HorizonJobs::using($this->app);
    $delayed = app('redis')->connection('default');
    $availableAt = CarbonImmutable::now()->getTimestamp() + 600.0;

    foreach (['default', 'mail', 'sms', 'slow'] as $index => $queue) {
        $jobs->pending('App\Jobs\Notify', $queue, 1700000000.0 + $index, 'p-'.$queue);
        $jobs->completed('App\Jobs\Notify', $queue, completedAt: 1700000100.0 + $index, id: 'c-'.$queue);
        $jobs->failed('App\Jobs\Notify', $queue, failedAt: 1700000200.0 + $index, id: 'f-'.$queue);
        QueuePayloads::delay($delayed, $queue, QueuePayloads::job('App\Jobs\Notify', ['uuid' => 'd-'.$queue]), $availableAt + $index);
    }
});

/**
 * @param  list<string>  $supervisors
 * @return list<string>
 */
function supervisorFilterTestIds(JobList $list, array $supervisors, ?array $queues = null): array
{
    $rows = app(JobListService::class)->rows(new JobListQuery($list, 500, null, null, $supervisors, null, $queues, null));

    return array_map(static fn (array $row): string => (string) $row['id'], $rows);
}

it('matches the queues of a balance=false pool', function (JobList $list, string $prefix, array $expected): void {
    expect(supervisorFilterTestIds($list, ['supervisor-pool']))->toBe(array_map(static fn (string $queue): string => $prefix.$queue, $expected));
})->with([
    'pending' => [JobList::Pending, 'p-', ['sms', 'mail']],
    'completed' => [JobList::Completed, 'c-', ['sms', 'mail']],
    'failed' => [JobList::Failed, 'f-', ['sms', 'mail']],
    'delayed' => [JobList::Delayed, 'd-', ['mail', 'sms']],
]);

it('matches a balanced supervisor and several supervisors at once', function (): void {
    expect(supervisorFilterTestIds(JobList::Pending, ['supervisor-default']))->toBe(['p-default'])
        ->and(supervisorFilterTestIds(JobList::Pending, ['supervisor-default', 'supervisor-pool']))->toBe(['p-sms', 'p-mail', 'p-default'])
        ->and(supervisorFilterTestIds(JobList::Delayed, ['supervisor-pool', 'supervisor-default']))->toBe(['d-default', 'd-mail', 'd-sms']);
});

it('intersects the supervisor queues with the Queue filter', function (): void {
    expect(supervisorFilterTestIds(JobList::Failed, ['supervisor-pool'], ['sms', 'default']))->toBe(['f-sms'])
        ->and(supervisorFilterTestIds(JobList::Delayed, ['supervisor-pool'], ['sms', 'default']))->toBe(['d-sms']);
});

it('compares the name without the master prefix, as the supervisor label does', function (): void {
    expect(supervisorFilterTestIds(JobList::Pending, ['host-a1b2:supervisor-pool']))->toBe([])
        ->and(supervisorFilterTestIds(JobList::Pending, ['pool']))->toBe([]);
});
