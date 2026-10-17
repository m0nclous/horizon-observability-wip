<?php

declare(strict_types=1);

use DF\HorizonObservability\Http\JobList;
use DF\HorizonObservability\Http\JobListQuery;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

function jobListRequest(string $query): Request
{
    return Request::create('/horizon/observability/jobs/pending'.($query === '' ? '' : '?'.$query));
}

it('pins the dashboard variable names', function (): void {
    expect(JobListQuery::VARIABLES)->toBe(['supervisor', 'batch_kind', 'queue', 'job_class']);
});

it('defaults to 500 rows without filters', function (): void {
    $query = JobListQuery::fromRequest(jobListRequest(''), JobList::Pending);

    expect($query)->toEqual(new JobListQuery(JobList::Pending, 500, null, null, null, null, null, null));
});

it('parses the limit and the period', function (): void {
    $query = JobListQuery::fromRequest(jobListRequest('limit=25&from=1700000000000&to=1700003600000'), JobList::Completed);

    expect($query->limit)->toBe(25)
        ->and($query->fromMs)->toBe(1700000000000)
        ->and($query->toMs)->toBe(1700003600000);
});

it('ignores the period for delayed jobs', function (): void {
    $query = JobListQuery::fromRequest(jobListRequest('from=1&to=2'), JobList::Delayed);

    expect($query->fromMs)->toBeNull()
        ->and($query->toMs)->toBeNull();
});

it('reads repeated var parameters in queryparam form', function (): void {
    $query = JobListQuery::fromRequest(jobListRequest('var-queue=default&var-queue=slow&var-supervisor=supervisor-1&var-queue=default'), JobList::Pending);

    expect($query->queues)->toBe(['default', 'slow'])
        ->and($query->supervisors)->toBe(['supervisor-1'])
        ->and($query->batchKinds)->toBeNull()
        ->and($query->jobClasses)->toBeNull();
});

it('treats $__all as no filter', function (): void {
    $filters = JobListQuery::filtersFromRequest(jobListRequest('var-queue=%24__all&var-job_class=$__all&var-batch_kind=import'));

    expect($filters)->toBe(['supervisors' => null, 'batchKinds' => ['import'], 'queues' => null, 'jobClasses' => null]);
});

it('decodes url-encoded backslashes and spaces', function (): void {
    $filters = JobListQuery::filtersFromRequest(jobListRequest('var-job_class=App%5CJobs%5CSendInvoice&var-job_class=Closure+%28routes%2Fweb.php%29'));

    expect($filters['jobClasses'])->toBe(['App\\Jobs\\SendInvoice', 'Closure (routes/web.php)']);
});

it('ignores unknown and malformed parameters', function (): void {
    $filters = JobListQuery::filtersFromRequest(jobListRequest('var-unknown=x&var-queue[]=a&queue=b&&var-=c&var-supervisor'));

    expect($filters)->toBe(['supervisors' => [''], 'batchKinds' => null, 'queues' => null, 'jobClasses' => null]);
});

it('keeps an empty selection as a filter that matches nothing', function (): void {
    expect(JobListQuery::filtersFromRequest(jobListRequest('var-queue='))['queues'])->toBe(['']);
});

it('answers 422 for invalid input', function (string $query): void {
    JobListQuery::fromRequest(jobListRequest($query), JobList::Failed);
})->with([
    'limit 0' => ['limit=0'],
    'limit too high' => ['limit=1001'],
    'limit not a number' => ['limit=abc'],
    'limit array' => ['limit[]=1'],
    'negative from' => ['from=-1'],
    'from not a number' => ['from=yesterday'],
    'from after to' => ['from=2000&to=1000'],
    'invalid UTF-8 filter' => ['var-queue=%FF'],
])->throws(ValidationException::class);

it('answers 422 for invalid filters on /live too', function (): void {
    JobListQuery::filtersFromRequest(jobListRequest('var-job_class=%C3%28'));
})->throws(ValidationException::class);
