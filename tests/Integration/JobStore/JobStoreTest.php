<?php

declare(strict_types=1);

use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Horizon\JobStore;
use DF\HorizonObservability\Http\JobList;
use DF\HorizonObservability\Http\JobListQuery;
use DF\HorizonObservability\Redis\Lua;
use DF\HorizonObservability\Tests\Integration\JobStore\Support\HorizonJobs;
use DF\HorizonObservability\Tests\Support\LogRecorder;
use DF\HorizonObservability\Tests\Support\RedisCommands;

/*
| Horizon's pending, completed and failed jobs (Arch Q2.1, Q8.4 "JobStore"), seeded through Horizon's own
| JobRepository: newest first, the period filter, expired hashes, filters, chunked and bounded scans, and the
| failed-job details from job_details.lua.
*/

pest()->group('redis');

function jobStoreTestQuery(JobList $list, int $limit = 500, ?int $from = null, ?int $to = null): JobListQuery
{
    return new JobListQuery($list, $limit, $from, $to, null, null, null, null);
}

/**
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function jobStoreTestRow(string $id, string $name, string $queue, array $overrides = []): array
{
    return [
        'id' => $id,
        'name' => $name,
        'queue' => $queue,
        'created_at' => null,
        'reserved_at' => null,
        'completed_at' => null,
        'failed_at' => null,
        'attempts' => 0,
        'exception' => null,
        ...$overrides,
    ];
}

/**
 * @return list<string>
 */
function jobStoreTestIds(JobListQuery $query, ?array $queues = null, ?array $jobClasses = null): array
{
    return array_column(app(JobStore::class)->rows($query, $queues, $jobClasses), 'id');
}

/**
 * The ARGV of a recorded EVAL: phpredis records (script, arguments, numKeys), predis (script, numKeys, ...arguments).
 *
 * @param  array{connection: string, command: string, parameters: array<array-key, mixed>}  $command
 * @return list<mixed>
 */
function jobStoreTestEvalArguments(array $command): array
{
    $parameters = array_values($command['parameters']);

    return is_array($parameters[1] ?? null) ? array_values($parameters[1]) : array_slice($parameters, 2);
}

it('lists pending jobs newest first with their push time', function (): void {
    $jobs = HorizonJobs::using($this->app);
    $jobs->pending('App\Jobs\SendInvoice', 'default', 1700000000.5, 'p-old');
    $jobs->pending('App\Jobs\Отчёт', 'mail', 1700000100.25, 'p-new');

    expect(app(JobStore::class)->rows(jobStoreTestQuery(JobList::Pending), null, null))->toBe([
        jobStoreTestRow('p-new', 'App\Jobs\Отчёт', 'mail', ['created_at' => 1700000100.25]),
        jobStoreTestRow('p-old', 'App\Jobs\SendInvoice', 'default', ['created_at' => 1700000000.5]),
    ]);
});

it('lists completed jobs newest first by completion time', function (): void {
    $jobs = HorizonJobs::using($this->app);
    $jobs->completed('App\Jobs\SendInvoice', 'default', 1700000000.25, 1700000001.0, 1700000003.4567, 'c-1');
    $jobs->completed('App\Jobs\SendReminder', 'slow', 1699999000.0, 1699999001.5, 1700000005.0, 'c-2');
    $jobs->pending('App\Jobs\Pending', 'default', 1700000010.0, 'p-1');

    expect(app(JobStore::class)->rows(jobStoreTestQuery(JobList::Completed), null, null))->toBe([
        jobStoreTestRow('c-2', 'App\Jobs\SendReminder', 'slow', ['created_at' => 1699999000.0, 'reserved_at' => 1699999001.5, 'completed_at' => 1700000005.0]),
        jobStoreTestRow('c-1', 'App\Jobs\SendInvoice', 'default', ['created_at' => 1700000000.25, 'reserved_at' => 1700000001.0, 'completed_at' => 1700000003.4567]),
    ])->and(jobStoreTestIds(jobStoreTestQuery(JobList::Pending)))->toBe(['p-1']);
});

it('lists failed jobs with the attempts from the payload and the first exception line (job_details.lua)', function (): void {
    $jobs = HorizonJobs::using($this->app);
    $jobs->failed('App\Jobs\Import', 'slow', 1700000001.0, 1700000004.012, 3, "Illuminate\\Database\\QueryException: SQLSTATE[HY000] in /app/Connection.php:825\nStack trace:\n#0 {main}", 'f-1');
    $jobs->failed('App\Jobs\Charge', 'default', null, 1700000009.0, 1, 'RuntimeException: Card declined in /app/Charge.php:3', 'f-2');

    expect(app(JobStore::class)->rows(jobStoreTestQuery(JobList::Failed), null, null))->toBe([
        jobStoreTestRow('f-2', 'App\Jobs\Charge', 'default', ['failed_at' => 1700000009.0, 'attempts' => 1, 'exception' => 'RuntimeException: Card declined in /app/Charge.php:3']),
        jobStoreTestRow('f-1', 'App\Jobs\Import', 'slow', ['reserved_at' => 1700000001.0, 'failed_at' => 1700000004.012, 'attempts' => 3, 'exception' => 'Illuminate\Database\QueryException: SQLSTATE[HY000] in /app/Connection.php:825']),
    ]);
});

it('filters by the period on the list time, inclusive, in Unix milliseconds', function (): void {
    $jobs = HorizonJobs::using($this->app);

    foreach ([10, 20, 30] as $second) {
        $jobs->completed(completedAt: 1700000000.0 + $second, id: 'c-'.$second);
    }

    expect(jobStoreTestIds(jobStoreTestQuery(JobList::Completed, from: 1700000010000, to: 1700000020000)))->toBe(['c-20', 'c-10'])
        ->and(jobStoreTestIds(jobStoreTestQuery(JobList::Completed, from: 1700000010001, to: 1700000020000)))->toBe(['c-20'])
        ->and(jobStoreTestIds(jobStoreTestQuery(JobList::Completed, from: 1700000020000)))->toBe(['c-30', 'c-20'])
        ->and(jobStoreTestIds(jobStoreTestQuery(JobList::Completed, to: 1700000019999)))->toBe(['c-10'])
        ->and(jobStoreTestIds(jobStoreTestQuery(JobList::Completed, from: 1700000031000)))->toBe([]);
});

it('skips expired hashes and hashes without the list time', function (): void {
    $jobs = HorizonJobs::using($this->app);
    $jobs->pending(pushedAt: 1700000001.0, id: 'p-expired');
    $jobs->pending(pushedAt: 1700000002.0, id: 'p-kept');
    $jobs->pending(pushedAt: 1700000003.0, id: 'p-no-time');
    $jobs->connection()->del('p-expired');
    $jobs->connection()->hdel('p-no-time', 'created_at');

    expect(jobStoreTestIds(jobStoreTestQuery(JobList::Pending)))->toBe(['p-kept']);
});

it('filters by queue and by the normalised job class', function (): void {
    $jobs = HorizonJobs::using($this->app);
    $jobs->pending('App\Jobs\SendInvoice', 'default', 1700000001.0, 'p-invoice');
    $jobs->pending('Closure (routes/console.php:12)', 'default', 1700000002.0, 'p-closure');
    $jobs->pending('App\Jobs\SendInvoice', 'mail', 1700000003.0, 'p-mail');

    expect(jobStoreTestIds(jobStoreTestQuery(JobList::Pending), ['default']))->toBe(['p-closure', 'p-invoice'])
        ->and(jobStoreTestIds(jobStoreTestQuery(JobList::Pending), null, ['Closure (routes/console.php)']))->toBe(['p-closure'])
        ->and(jobStoreTestIds(jobStoreTestQuery(JobList::Pending), ['mail', 'default'], ['App\Jobs\SendInvoice']))->toBe(['p-mail', 'p-invoice'])
        ->and(jobStoreTestIds(jobStoreTestQuery(JobList::Pending), ['sms']))->toBe([]);
});

it('stops at the limit and reads no more ids than the page needs without a filter', function (): void {
    $jobs = HorizonJobs::using($this->app);

    foreach (range(1, 5) as $index) {
        $jobs->pending(pushedAt: 1700000000.0 + $index, id: 'p-'.$index);
    }

    $commands = RedisCommands::start($this->app);

    expect(jobStoreTestIds(jobStoreTestQuery(JobList::Pending, limit: 2)))->toBe(['p-5', 'p-4'])
        ->and(array_count_values($commands->names())['ZRANGEBYSCORE'] ?? 0)->toBe(1)
        ->and(json_encode($commands->commands()[0]['parameters']))->toContain('2');
});

it('reads the next chunk when expired hashes leave the page short', function (): void {
    $jobs = HorizonJobs::using($this->app);
    $jobs->pending(pushedAt: 1700000000.0, id: 'p-live');
    $jobs->orphans('pending_jobs', [1700000001.0, 1700000002.0]);
    $commands = RedisCommands::start($this->app);

    expect(jobStoreTestIds(jobStoreTestQuery(JobList::Pending, limit: 2)))->toBe(['p-live'])
        ->and(array_count_values($commands->names())['ZRANGEBYSCORE'] ?? 0)->toBe(2);
});

it('scans a filtered list in chunks of 500 ids', function (): void {
    $jobs = HorizonJobs::using($this->app);
    $jobs->pending('App\Jobs\Rare', 'default', 1700000000.0, 'p-rare');
    $jobs->orphans('pending_jobs', array_map(static fn (int $index): float => 1700001000.0 + $index, range(1, 1200)));
    $commands = RedisCommands::start($this->app);

    expect(JobStore::CHUNK)->toBe(500)
        ->and(jobStoreTestIds(jobStoreTestQuery(JobList::Pending), ['default']))->toBe(['p-rare'])
        ->and(array_count_values($commands->names())['ZRANGEBYSCORE'] ?? 0)->toBe(3);
});

it('scans at most 10000 ids per request', function (): void {
    $jobs = HorizonJobs::using($this->app);
    $jobs->pending('App\Jobs\Rare', 'default', 1700000000.0, 'p-too-old');
    $jobs->orphans('pending_jobs', array_map(static fn (int $index): float => 1700001000.0 + $index, range(1, JobStore::SCAN_LIMIT)));
    $commands = RedisCommands::start($this->app);

    expect(jobStoreTestIds(jobStoreTestQuery(JobList::Pending), null, ['App\Jobs\Rare']))->toBe([])
        ->and(array_count_values($commands->names())['ZRANGEBYSCORE'] ?? 0)->toBe(20);
});

it('reads failed details in one job_details.lua call per 100 rows of the final page only', function (): void {
    $jobs = HorizonJobs::using($this->app);

    foreach (range(1, 150) as $index) {
        $jobs->failed('App\Jobs\Import', 'default', failedAt: 1700000000.0 + $index, attempts: 3, exception: "RuntimeException: Boom {$index}\nStack trace:", id: 'f-'.$index);
    }

    $jobs->failed('App\Jobs\Other', 'slow', failedAt: 1700001000.0, attempts: 1, id: 'f-other');
    $commands = RedisCommands::start($this->app);
    $rows = app(JobStore::class)->rows(jobStoreTestQuery(JobList::Failed, limit: 120), ['default'], null);
    $evals = array_values(array_filter($commands->commands(), static fn (array $command): bool => $command['command'] === 'EVAL'));

    expect($rows)->toHaveCount(120)
        ->and(array_unique(array_column($rows, 'attempts')))->toBe([3])
        ->and($rows[0]['exception'])->toBe('RuntimeException: Boom 150')
        ->and($rows[119]['exception'])->toBe('RuntimeException: Boom 31')
        ->and($evals)->toHaveCount(2)
        ->and(count(jobStoreTestEvalArguments($evals[0])))->toBe(101)
        ->and(count(jobStoreTestEvalArguments($evals[1])))->toBe(21)
        ->and(jobStoreTestEvalArguments($evals[0])[100])->toBe('1000');
});

it('does not read the exception with exceptions = none', function (): void {
    config()->set('horizon-observability.exceptions', 'none');
    app()->forgetInstance(Config::class);
    HorizonJobs::using($this->app)->failed(attempts: 2, exception: 'RuntimeException: secret', id: 'f-1');
    $commands = RedisCommands::start($this->app);
    $rows = app(JobStore::class)->rows(jobStoreTestQuery(JobList::Failed), null, null);
    $evals = array_values(array_filter($commands->commands(), static fn (array $command): bool => $command['command'] === 'EVAL'));

    expect($rows[0]['attempts'])->toBe(2)
        ->and($rows[0]['exception'])->toBeNull()
        ->and($evals)->toHaveCount(1)
        ->and(jobStoreTestEvalArguments($evals[0]))->toBe(['f-1', '0']);
});

it('keeps failed rows without details when job_details.lua fails, and logs it', function (): void {
    $directory = sys_get_temp_dir().'/ho-job-details-'.getmypid().'-'.uniqid();
    mkdir($directory);
    file_put_contents($directory.'/job_details.lua', "return redis.error_reply('ERR broken')");
    $this->app->instance(Lua::class, new Lua($directory));
    $this->app->instance('log', $log = new LogRecorder);
    HorizonJobs::using($this->app)->failed(attempts: 2, exception: 'RuntimeException: Boom', id: 'f-1');

    try {
        $rows = app(JobStore::class)->rows(jobStoreTestQuery(JobList::Failed), null, null);
    } finally {
        unlink($directory.'/job_details.lua');
        rmdir($directory);
    }

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['id'])->toBe('f-1')
        ->and($rows[0]['attempts'])->toBe(0)
        ->and($rows[0]['exception'])->toBeNull()
        ->and($log->messages('warning'))->toHaveCount(1)
        ->and($log->messages('warning')[0])->toContain('jobs.details failed')->toContain('ERR broken');
});

it('has no rows for the delayed list, which is not Horizon\'s', function (): void {
    HorizonJobs::using($this->app)->pending(id: 'p-1');
    $commands = RedisCommands::start($this->app);

    expect(app(JobStore::class)->rows(jobStoreTestQuery(JobList::Delayed), null, null))->toBe([])
        ->and($commands->count())->toBe(0);
});
