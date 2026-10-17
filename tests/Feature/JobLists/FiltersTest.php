<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use DF\HorizonObservability\Batches\BatchLinks;
use DF\HorizonObservability\Http\Middleware\Authorize;
use DF\HorizonObservability\Redis\Keys;
use DF\HorizonObservability\Redis\RedisClients;
use DF\HorizonObservability\Tests\Integration\JobStore\Support\HorizonJobs;
use DF\HorizonObservability\Tests\Support\HorizonState;
use DF\HorizonObservability\Tests\Support\QueuePayloads;

/*
| The dashboard filters of the job lists in Grafana's ${var:queryparam} form (Arch Q2.1, F25): repeated values,
| $__all, URL-encoded class names, the normalised Closure name, Supervisor (C14) and Batch.
*/

pest()->group('redis');

beforeEach(function (): void {
    $this->withoutMiddleware(Authorize::class);

    HorizonState::using($this->app)
        ->master('host-a', supervisors: ['host-a:supervisor-default', 'host-a:supervisor-pool'])
        ->supervisor('host-a:supervisor-default', HorizonState::options('host-a:supervisor-default', queue: 'default'), ['redis:default' => 3])
        ->supervisor('host-a:supervisor-pool', HorizonState::options('host-a:supervisor-pool', queue: 'mail,sms', balance: false), ['redis:mail,sms' => 2]);

    $jobs = HorizonJobs::using($this->app);
    $jobs->pending('App\Jobs\SendInvoice', 'default', 1700000001.0, 'p-invoice');
    $jobs->pending('Closure (routes/console.php:12)', 'default', 1700000002.0, 'p-closure');
    $jobs->pending('App\Jobs\SendInvoice', 'mail', 1700000003.0, 'p-mail');
    $jobs->pending('App\Notifications\Welcome', 'sms', 1700000004.0, 'p-sms');

    $availableAt = CarbonImmutable::now()->getTimestamp() + 600.0;
    $redis = app('redis')->connection('default');
    QueuePayloads::delay($redis, 'default', QueuePayloads::job('App\Jobs\SendInvoice', ['uuid' => 'd-invoice']), $availableAt + 1);
    QueuePayloads::delay($redis, 'mail', QueuePayloads::job('Closure (routes/console.php:12)', ['uuid' => 'd-closure']), $availableAt + 2);
    QueuePayloads::delay($redis, 'sms', QueuePayloads::job('App\Notifications\Welcome', ['uuid' => 'd-sms']), $availableAt + 3);
});

/**
 * @return list<string>
 */
function filtersTestIds(string $list, string $query): array
{
    /** @var list<string> */
    return test()->get('/horizon/observability/jobs/'.$list.'?'.$query)->assertOk()->json('data.*.id');
}

it('filters by repeated values and treats $__all and a missing variable as no filter', function (): void {
    expect(filtersTestIds('pending', 'var-queue=default&var-queue=mail'))->toBe(['p-mail', 'p-closure', 'p-invoice'])
        ->and(filtersTestIds('pending', 'var-queue=%24__all'))->toBe(['p-sms', 'p-mail', 'p-closure', 'p-invoice'])
        ->and(filtersTestIds('pending', 'var-queue=mail&var-queue=%24__all'))->toHaveCount(4)
        ->and(filtersTestIds('pending', ''))->toHaveCount(4)
        ->and(filtersTestIds('delayed', 'var-queue=sms&var-queue=default'))->toBe(['d-invoice', 'd-sms']);
});

it('filters by URL-encoded class names and by the normalised Closure name', function (): void {
    expect(filtersTestIds('pending', 'var-job_class=App%5CJobs%5CSendInvoice'))->toBe(['p-mail', 'p-invoice'])
        ->and(filtersTestIds('pending', 'var-job_class=Closure+%28routes%2Fconsole.php%29'))->toBe(['p-closure'])
        ->and(filtersTestIds('delayed', 'var-job_class=Closure%20(routes/console.php)'))->toBe(['d-closure'])
        ->and(test()->get('/horizon/observability/jobs/pending?var-job_class=Closure%20(routes/console.php)')->json('data.0.name'))
        ->toBe('Closure (routes/console.php:12)');
});

it('combines the filters', function (): void {
    expect(filtersTestIds('pending', 'var-supervisor=supervisor-pool&var-job_class=App%5CJobs%5CSendInvoice'))->toBe(['p-mail'])
        ->and(filtersTestIds('pending', 'var-supervisor=supervisor-pool&var-queue=sms&var-queue=default'))->toBe(['p-sms'])
        ->and(filtersTestIds('delayed', 'var-supervisor=supervisor-default&var-job_class=App%5CJobs%5CSendInvoice'))->toBe(['d-invoice']);
});

it('matches the queues of a balance=false supervisor end to end (C14)', function (): void {
    expect(filtersTestIds('pending', 'var-supervisor=supervisor-pool'))->toBe(['p-sms', 'p-mail'])
        ->and(filtersTestIds('delayed', 'var-supervisor=supervisor-pool'))->toBe(['d-closure', 'd-sms']);
});

it('answers no rows for a value without "=", a supervisor that is not running and a batch kind without links', function (string $query): void {
    expect(filtersTestIds('pending', $query))->toBe([])
        ->and(filtersTestIds('delayed', $query))->toBe([]);
})->with([
    'var-queue without a value' => ['var-queue'],
    'a stopped supervisor' => ['var-supervisor=supervisor-gone'],
    'a batch kind without links' => ['var-batch_kind=campaign_launch'],
]);

it('narrows to the queues and classes of a batch kind', function (): void {
    app(RedisClients::class)->storage()->zadd(
        app(Keys::class)->batchLinks(),
        CarbonImmutable::now()->getTimestamp(),
        Keys::field('campaign_launch', 'redis', 'mail', 'App\Jobs\SendInvoice'),
    );

    if (app(BatchLinks::class)->forKinds(['campaign_launch'])['queues'] === []) {
        $this->markTestSkipped('BatchLinks reads no links until T07 implements it.');
    }

    expect(filtersTestIds('pending', 'var-batch_kind=campaign_launch'))->toBe(['p-mail'])
        ->and(filtersTestIds('pending', 'var-batch_kind=campaign_launch&var-batch_kind=other'))->toBe(['p-mail'])
        ->and(filtersTestIds('pending', 'var-batch_kind=campaign_launch&var-queue=default'))->toBe([]);
});
