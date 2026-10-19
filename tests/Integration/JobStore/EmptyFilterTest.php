<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use DF\HorizonObservability\Horizon\JobStore;
use DF\HorizonObservability\Http\JobList;
use DF\HorizonObservability\Http\JobListQuery;
use DF\HorizonObservability\Http\JobListService;
use DF\HorizonObservability\Queues\DelayedJobStore;
use DF\HorizonObservability\Redis\Keys;
use DF\HorizonObservability\Redis\RedisClients;
use DF\HorizonObservability\Tests\Integration\JobStore\Support\HorizonJobs;
use DF\HorizonObservability\Tests\Support\HorizonState;
use DF\HorizonObservability\Tests\Support\QueuePayloads;
use DF\HorizonObservability\Tests\Support\RedisCommands;

/*
| An empty resolved filter (a supervisor that is not running, a batch kind without links, disjoint filters) returns
| {"data":[]} without reading any job. The pilot intersected the filter to [] and still scanned up to 10 000 ids (20
| ZRANGEBYSCORE calls plus 10 000 HMGETs, and the delayed payloads of every queue).
*/

pest()->group('redis');

beforeEach(function (): void {
    // Rows that any scan would find.
    $jobs = HorizonJobs::using($this->app);
    $jobs->pending('App\Jobs\SendInvoice', 'default', 1700000001.0, 'p-1');
    $jobs->completed('App\Jobs\SendInvoice', 'default', completedAt: 1700000002.0, id: 'c-1');
    $jobs->failed('App\Jobs\SendInvoice', 'default', failedAt: 1700000003.0, id: 'f-1');
    QueuePayloads::delay(app('redis')->connection('default'), 'default', QueuePayloads::job(), CarbonImmutable::now()->getTimestamp() + 600.0);
});

function emptyFilterTestQuery(JobList $list, ?array $supervisors = null, ?array $batchKinds = null, ?array $queues = null, ?array $jobClasses = null): JobListQuery
{
    return new JobListQuery($list, 500, null, null, $supervisors, $batchKinds, $queues, $jobClasses);
}

/**
 * Commands that read jobs: anything on the package's reader clients except the batch-link read (a package key, not a
 * job), and any job-set scan or script on any connection. (Horizon's own supervisor repository reads the
 * `supervisors` set with ZREVRANGEBYSCORE and a pipeline of HMGETs on the app's `horizon` connection.)
 *
 * @return list<string>
 */
function emptyFilterTestJobReads(RedisCommands $commands): array
{
    return array_values(array_map(
        static fn (array $command): string => $command['connection'].' '.$command['command'],
        array_filter(
            $commands->commands(),
            static fn (array $command): bool => (str_starts_with($command['connection'], RedisClients::READER)
                && ($command['parameters'][0] ?? null) !== (new Keys)->batchLinks())
                || in_array($command['command'], ['ZRANGEBYSCORE', 'EVAL'], true),
        ),
    ));
}

it('makes zero redis commands for an empty queue or class filter', function (): void {
    $commands = RedisCommands::start($this->app);
    $now = CarbonImmutable::now();
    $results = [];

    foreach ([JobList::Pending, JobList::Completed, JobList::Failed] as $list) {
        $results[] = app(JobStore::class)->rows(emptyFilterTestQuery($list), [], null);
        $results[] = app(JobStore::class)->rows(emptyFilterTestQuery($list), null, []);
    }

    $results[] = app(DelayedJobStore::class)->rows([], null, 500, $now);
    $results[] = app(DelayedJobStore::class)->rows([['connection' => 'redis', 'queue' => 'default']], [], 500, $now);

    expect(array_unique($results, SORT_REGULAR))->toBe([[]])
        ->and($commands->count())->toBe(0)
        // The same stores find the seeded rows without the empty filter.
        ->and(app(JobStore::class)->rows(emptyFilterTestQuery(JobList::Pending), null, null))->toHaveCount(1)
        ->and(app(DelayedJobStore::class)->rows([['connection' => 'redis', 'queue' => 'default']], null, 500, $now))->toHaveCount(1);
});

it('reads no job for a supervisor that is not running', function (JobList $list): void {
    HorizonState::using($this->app)
        ->master('host-a', supervisors: ['host-a:supervisor-default'])
        ->supervisor('host-a:supervisor-default', HorizonState::options('host-a:supervisor-default'), ['redis:default' => 3]);
    $commands = RedisCommands::start($this->app);

    expect(app(JobListService::class)->rows(emptyFilterTestQuery($list, supervisors: ['supervisor-gone'])))->toBe([])
        ->and(emptyFilterTestJobReads($commands))->toBe([])
        // Only Horizon's own supervisor repository ran, on the app's horizon connection.
        ->and($commands->count())->toBeGreaterThan(0)
        ->and(array_unique(array_column($commands->commands(), 'connection')))->toBe(['horizon']);
})->with([JobList::Pending, JobList::Completed, JobList::Failed, JobList::Delayed]);

it('reads no job for a batch kind without links', function (JobList $list): void {
    $commands = RedisCommands::start($this->app);

    expect(app(JobListService::class)->rows(emptyFilterTestQuery($list, batchKinds: ['campaign_launch'])))->toBe([])
        ->and(emptyFilterTestJobReads($commands))->toBe([])
        // Only the batch links were read, once, on the storage reader.
        ->and($commands->names())->toBe(['ZRANGE'])
        ->and($commands->names(RedisClients::READER.'horizon'))->toBe(['ZRANGE']);
})->with([JobList::Pending, JobList::Completed, JobList::Failed, JobList::Delayed]);

it('reads no job and no batch link for disjoint filters', function (JobList $list): void {
    HorizonState::using($this->app)
        ->master('host-a', supervisors: ['host-a:supervisor-pool'])
        ->supervisor('host-a:supervisor-pool', HorizonState::options('host-a:supervisor-pool', queue: 'mail,sms', balance: false), ['redis:mail,sms' => 2]);
    $commands = RedisCommands::start($this->app);
    $service = app(JobListService::class);

    expect($service->rows(emptyFilterTestQuery($list, supervisors: ['supervisor-pool'], batchKinds: ['campaign_launch'], queues: ['default'])))->toBe([])
        ->and(emptyFilterTestJobReads($commands))->toBe([])
        ->and(array_unique(array_column($commands->commands(), 'connection')))->toBe(['horizon'])
        ->and($commands->reset()->count())->toBe(0)
        // Without a Supervisor filter, nothing is read at all.
        ->and($service->rows(emptyFilterTestQuery($list, batchKinds: ['campaign_launch'], queues: ['default'], jobClasses: [])))->toBe([])
        ->and($commands->count())->toBe(0);
})->with([JobList::Pending, JobList::Completed, JobList::Failed, JobList::Delayed]);

it('reads no delayed job for queues outside the Horizon topology', function (): void {
    $commands = RedisCommands::start($this->app);

    expect(app(JobListService::class)->rows(emptyFilterTestQuery(JobList::Delayed, queues: ['not-run-by-horizon'])))->toBe([])
        ->and($commands->count())->toBe(0);
});
