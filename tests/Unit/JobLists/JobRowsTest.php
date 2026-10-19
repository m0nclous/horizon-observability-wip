<?php

declare(strict_types=1);

use DF\HorizonObservability\Config\ExceptionMode;
use DF\HorizonObservability\Http\JobList;
use DF\HorizonObservability\Http\JobRows;

/*
| Row formatting of the job lists (Arch Q2.1): the pilot's fields and units, from
| legacy/src/Http/Resources/Horizon{Pending,Completed,Failed,Delayed}JobResource.php.
*/

/**
 * @param  array<string, mixed>  $overrides
 * @return array{id: string, name: string, queue: string, created_at: float|null, reserved_at: float|null, completed_at: float|null, failed_at: float|null, attempts: int, exception: string|null}
 */
function jobRowsTestRow(array $overrides = []): array
{
    /** @var array{id: string, name: string, queue: string, created_at: float|null, reserved_at: float|null, completed_at: float|null, failed_at: float|null, attempts: int, exception: string|null} */
    return [
        'id' => '9a3c6f8e-1f2a-4c55-8f0e-0d1c2b3a4f50',
        'name' => 'App\Jobs\SendInvoice',
        'queue' => 'default',
        'created_at' => 1700000000.1234,
        'reserved_at' => 1700000001.25,
        'completed_at' => 1700000003.4567,
        'failed_at' => 1700000004.012,
        'attempts' => 3,
        'exception' => "Illuminate\\Database\\QueryException: SQLSTATE[HY000] in /app/Connection.php:825\nStack trace:",
        ...$overrides,
    ];
}

it('formats pending rows', function (): void {
    $row = JobRows::horizon(JobList::Pending, jobRowsTestRow(), ExceptionMode::Full);

    expect($row)->toBe([
        'id' => '9a3c6f8e-1f2a-4c55-8f0e-0d1c2b3a4f50',
        'name' => 'App\Jobs\SendInvoice',
        'queue' => 'default',
        'queued_at' => 1700000000123,
        'horizon_path' => 'jobs/pending/9a3c6f8e-1f2a-4c55-8f0e-0d1c2b3a4f50',
    ])->and(array_keys($row))->toBe(JobRows::fields(JobList::Pending));
});

it('formats completed rows with the runtime in seconds rounded to 3 decimals', function (): void {
    $row = JobRows::horizon(JobList::Completed, jobRowsTestRow(), ExceptionMode::Full);

    expect($row)->toBe([
        'id' => '9a3c6f8e-1f2a-4c55-8f0e-0d1c2b3a4f50',
        'name' => 'App\Jobs\SendInvoice',
        'queue' => 'default',
        'queued_at' => 1700000000123,
        'completed_at' => 1700000003457,
        'runtime' => 2.207,
        'horizon_path' => 'jobs/completed/9a3c6f8e-1f2a-4c55-8f0e-0d1c2b3a4f50',
    ])->and(array_keys($row))->toBe(JobRows::fields(JobList::Completed));
});

it('formats failed rows with attempts, runtime and the summarised exception', function (): void {
    $row = JobRows::horizon(JobList::Failed, jobRowsTestRow(), ExceptionMode::Full);

    expect($row)->toBe([
        'id' => '9a3c6f8e-1f2a-4c55-8f0e-0d1c2b3a4f50',
        'name' => 'App\Jobs\SendInvoice',
        'queue' => 'default',
        'attempts' => 3,
        'failed_at' => 1700000004012,
        'runtime' => 2.762,
        'exception' => 'QueryException: SQLSTATE[HY000]',
        'horizon_path' => 'failed/9a3c6f8e-1f2a-4c55-8f0e-0d1c2b3a4f50',
    ])->and(array_keys($row))->toBe(JobRows::fields(JobList::Failed))
        ->and(JobRows::horizon(JobList::Failed, jobRowsTestRow(), ExceptionMode::ClassOnly)['exception'])->toBe('QueryException')
        ->and(JobRows::horizon(JobList::Failed, jobRowsTestRow(), ExceptionMode::None)['exception'])->toBeNull();
});

it('gives a null runtime to a job that failed before a worker reserved it', function (): void {
    $row = JobRows::horizon(JobList::Failed, jobRowsTestRow(['reserved_at' => null, 'exception' => null]), ExceptionMode::Full);

    expect($row['runtime'])->toBeNull()
        ->and($row['exception'])->toBeNull()
        ->and($row['failed_at'])->toBe(1700000004012);
});

it('gives null instead of a value computed from 0 for an unreadable time', function (): void {
    $row = JobRows::horizon(JobList::Completed, jobRowsTestRow(['created_at' => null, 'reserved_at' => null]), ExceptionMode::Full);

    expect($row['queued_at'])->toBeNull()
        ->and($row['runtime'])->toBeNull()
        ->and($row['completed_at'])->toBe(1700000003457);
});

it('formats delayed rows; queued_at is null without pushedAt', function (): void {
    $row = JobRows::delayed(['id' => 'abc', 'name' => 'App\Jobs\SendReminder', 'queue' => 'slow', 'attempts' => 2, 'pushed_at' => 1699999990.1234, 'available_at' => 1700000060.5]);

    expect($row)->toBe([
        'id' => 'abc',
        'name' => 'App\Jobs\SendReminder',
        'queue' => 'slow',
        'attempts' => 2,
        'queued_at' => 1699999990123,
        'available_at' => 1700000060500,
    ])->and(array_keys($row))->toBe(JobRows::fields(JobList::Delayed))
        ->and(JobRows::delayed(['id' => 'abc', 'name' => 'x', 'queue' => 'slow', 'attempts' => 0, 'pushed_at' => null, 'available_at' => 1700000060.0])['queued_at'])->toBeNull();
});

it('rounds milliseconds and runtimes like the pilot', function (): void {
    expect(JobRows::milliseconds(1700000000.0005))->toBe(1700000000001)
        ->and(JobRows::milliseconds(1700000000.0004))->toBe(1700000000000)
        ->and(JobRows::milliseconds(null))->toBeNull()
        ->and(JobRows::runtime(1700000000.123, 1700000000.0))->toBe(-0.123)
        ->and(JobRows::runtime(1700000001.0, 1700000003.0))->toBe(2.0)
        ->and(JobRows::runtime(1700000000.0, 1700000000.00049))->toBe(0.0)
        ->and(JobRows::runtime(null, 1.0))->toBeNull()
        ->and(JobRows::runtime(1.0, null))->toBeNull();
});

it('gives no milliseconds for a time past the integer range instead of a wrapped one (PHP 8.5, T18)', function (): void {
    // 2^63 ms is the first value (int) cannot hold: it wraps on PHP 8.2-8.4 and warns on 8.5 (failOnWarning).
    expect(JobRows::milliseconds(1e17))->toBeNull()
        ->and(JobRows::milliseconds(-1e17))->toBeNull()
        ->and(JobRows::milliseconds(9.2233720368547758E15))->toBeNull()
        ->and(JobRows::milliseconds(9.223372036854774E15))->toBe(9223372036854773760)
        ->and(JobRows::milliseconds(-9.2233720368547758E15))->toBe(PHP_INT_MIN)
        ->and(JobRows::milliseconds(INF))->toBeNull()
        ->and(JobRows::milliseconds(NAN))->toBeNull()
        ->and(JobRows::delayed(['id' => 'abc', 'name' => 'x', 'queue' => 'slow', 'attempts' => 0, 'pushed_at' => 1e17, 'available_at' => 1e300]))
        ->toMatchArray(['queued_at' => null, 'available_at' => null]);
});

it('refuses to format delayed rows as Horizon rows', function (): void {
    JobRows::horizon(JobList::Delayed, jobRowsTestRow(), ExceptionMode::Full);
})->throws(LogicException::class);

it('keeps the Q2.1 field contract', function (): void {
    expect(JobRows::fields(JobList::Pending))->toBe(['id', 'name', 'queue', 'queued_at', 'horizon_path'])
        ->and(JobRows::fields(JobList::Completed))->toBe(['id', 'name', 'queue', 'queued_at', 'completed_at', 'runtime', 'horizon_path'])
        ->and(JobRows::fields(JobList::Failed))->toBe(['id', 'name', 'queue', 'attempts', 'failed_at', 'runtime', 'exception', 'horizon_path'])
        ->and(JobRows::fields(JobList::Delayed))->toBe(['id', 'name', 'queue', 'attempts', 'queued_at', 'available_at']);
});
