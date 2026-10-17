<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use DF\HorizonObservability\Http\JobList;
use DF\HorizonObservability\Http\JobRows;
use DF\HorizonObservability\Http\Middleware\Authorize;
use DF\HorizonObservability\Tests\Integration\JobStore\Support\HorizonJobs;
use DF\HorizonObservability\Tests\Support\QueuePayloads;
use Illuminate\Testing\TestResponse;
use Symfony\Component\HttpFoundation\Response;

/*
| GET {base}/jobs/{list} (Arch Q2.1, Q8.4 "Job lists: JSON snapshots per list"). The legacy code is not runnable,
| so tests/Fixtures/job-lists/<list>.json were written by hand from
| legacy/src/Http/Resources/Horizon{Pending,Completed,Failed,Delayed}JobResource.php and the Q2.1 table: the same
| fields, order and units (epoch ms, runtime in seconds to 3 decimals, horizon_path relative to Horizon's URL).
| Access is T03's (tests/Feature/Access); these tests let every request through.
*/

pest()->group('redis');

/**
 * The query string the dashboard sends (Arch Q2.5) with every filter at "All".
 */
const JSON_SNAPSHOT_TEST_QUERY = '?limit=500&from=1699990000000&to=1700090000000&var-supervisor=%24__all&var-batch_kind=%24__all&var-queue=%24__all&var-job_class=%24__all';

beforeEach(function (): void {
    $this->withoutMiddleware(Authorize::class);
});

function jsonSnapshotTestFixture(string $list): string
{
    return (string) file_get_contents(dirname(__DIR__, 2).'/Fixtures/job-lists/'.$list.'.json');
}

/**
 * The body must be the fixture minified with the response flags: same keys, order, types and escaping.
 *
 * @param  TestResponse<Response>  $response
 */
function jsonSnapshotTestAssert(TestResponse $response, JobList $list): void
{
    $expected = json_decode(jsonSnapshotTestFixture($list->value), true, flags: JSON_THROW_ON_ERROR);

    $response->assertOk()
        ->assertHeader('Content-Type', 'application/json')
        ->assertHeader('Cache-Control', 'no-store, private');

    expect($response->getContent())->toBe(json_encode($expected, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE))
        ->and(array_map(array_keys(...), $expected['data']))->each->toBe(JobRows::fields($list));
}

it('answers the pending list like the pilot', function (): void {
    $jobs = HorizonJobs::using($this->app);
    $jobs->pending('App\Jobs\SendInvoice', 'default', 1700000100.1234, '9a3c6f8e-1f2a-4c55-8f0e-0d1c2b3a4f50');
    $jobs->pending('App\Jobs\Отчёт', 'mail', 1700000200.5, '4f1d2e3c-5b6a-4789-9a0b-1c2d3e4f5a60');

    jsonSnapshotTestAssert($this->get('/horizon/observability/jobs/pending'.JSON_SNAPSHOT_TEST_QUERY), JobList::Pending);
});

it('answers the completed list like the pilot', function (): void {
    $jobs = HorizonJobs::using($this->app);
    $jobs->completed('App\Jobs\ImportFeed', 'slow', 1700000000.25, 1700000001.0, 1700000003.4567, '11111111-2222-4333-8444-555555555555');
    $jobs->completed('Closure (routes/console.php:12)', 'default', 1700000010.0, 1700000010.5, 1700000012.5, '22222222-3333-4444-8555-666666666666');

    jsonSnapshotTestAssert($this->get('/horizon/observability/jobs/completed'.JSON_SNAPSHOT_TEST_QUERY), JobList::Completed);
});

it('answers the failed list like the pilot', function (): void {
    $jobs = HorizonJobs::using($this->app);
    $jobs->failed('App\Jobs\Charge', 'default', 1700000020.0, 1700000021.2346, 3, "Illuminate\\Database\\QueryException: SQLSTATE[HY000] [2002] Connection refused (Connection: mysql, SQL: select 1) in /app/vendor/laravel/framework/src/Illuminate/Database/Connection.php:825\nStack trace:\n#0 {main}", '33333333-4444-4555-8666-777777777777');
    $jobs->failed('App\Jobs\Отчёт', 'mail', null, 1700000030.5, 1, "App\\Exceptions\\ShouldntReport@anonymous\0/app/app/Jobs/Simulated.php:40\$1a: Simulated failure in /app/app/Jobs/Simulated.php:41\nStack trace:\n#0 {main}", '44444444-5555-4666-8777-888888888888');

    jsonSnapshotTestAssert($this->get('/horizon/observability/jobs/failed'.JSON_SNAPSHOT_TEST_QUERY), JobList::Failed);
});

it('answers the delayed list like the pilot, ignoring the period', function (): void {
    $redis = app('redis')->connection('default');
    QueuePayloads::delay($redis, 'default', QueuePayloads::job('App\Jobs\SendReminder', ['uuid' => '55555555-6666-4777-8888-999999999999', 'pushedAt' => '1699999990.1234']), 1700000060.5);
    QueuePayloads::delay($redis, 'default', QueuePayloads::job('App\Jobs\Import', ['uuid' => '66666666-7777-4888-8999-aaaaaaaaaaaa', 'attempts' => 2], ['pushedAt']), 1700000030.0);
    QueuePayloads::delay($redis, 'slow', QueuePayloads::job('App\Jobs\Отчёт', ['uuid' => '77777777-8888-4999-8aaa-bbbbbbbbbbbb', 'pushedAt' => 1699999000.5]), 1700000045.25);
    QueuePayloads::delay($redis, 'default', QueuePayloads::job('App\Jobs\Due'), 1699999999.0);
    $this->travelTo(CarbonImmutable::createFromTimestamp(1700000000));

    jsonSnapshotTestAssert($this->get('/horizon/observability/jobs/delayed?limit=500&from=1&to=2'), JobList::Delayed);
});

it('answers {"data":[]} when nothing is there', function (string $list): void {
    $this->get('/horizon/observability/jobs/'.$list.JSON_SNAPSHOT_TEST_QUERY)
        ->assertOk()
        ->assertHeader('Cache-Control', 'no-store, private');

    expect($this->get('/horizon/observability/jobs/'.$list)->getContent())->toBe('{"data":[]}');
})->with(JobList::values());

it('answers HEAD without a body', function (): void {
    HorizonJobs::using($this->app)->pending(id: 'p-1');

    $response = $this->call('HEAD', '/horizon/observability/jobs/pending');

    $response->assertOk()->assertHeader('Content-Type', 'application/json');
    expect($response->getContent())->toBe('');
});

it('caps the rows at the limit', function (): void {
    $jobs = HorizonJobs::using($this->app);

    foreach (range(1, 3) as $index) {
        $jobs->pending(pushedAt: 1700000000.0 + $index, id: 'p-'.$index);
    }

    expect($this->get('/horizon/observability/jobs/pending?limit=2')->json('data.*.id'))->toBe(['p-3', 'p-2'])
        ->and($this->get('/horizon/observability/jobs/pending?limit=1000')->json('data.*.id'))->toBe(['p-3', 'p-2', 'p-1']);
});
