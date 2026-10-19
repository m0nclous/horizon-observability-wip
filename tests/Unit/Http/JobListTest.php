<?php

declare(strict_types=1);

use DF\HorizonObservability\Http\JobList;
use DF\HorizonObservability\Http\JobRows;

it('lists the job lists in route order', function (): void {
    expect(JobList::values())->toBe(['pending', 'completed', 'failed', 'delayed']);
});

it('links to the Horizon UI', function (): void {
    expect(JobList::Pending->horizonPath('42'))->toBe('jobs/pending/42')
        ->and(JobList::Completed->horizonPath('42'))->toBe('jobs/completed/42')
        ->and(JobList::Failed->horizonPath('42'))->toBe('failed/42')
        ->and(JobList::Delayed->horizonPath('42'))->toBeNull();
});

it('freezes the row fields of every list', function (): void {
    expect(JobRows::fields(JobList::Pending))->toBe(['id', 'name', 'queue', 'queued_at', 'horizon_path'])
        ->and(JobRows::fields(JobList::Completed))->toBe(['id', 'name', 'queue', 'queued_at', 'completed_at', 'runtime', 'horizon_path'])
        ->and(JobRows::fields(JobList::Failed))->toBe(['id', 'name', 'queue', 'attempts', 'failed_at', 'runtime', 'exception', 'horizon_path'])
        ->and(JobRows::fields(JobList::Delayed))->toBe(['id', 'name', 'queue', 'attempts', 'queued_at', 'available_at']);
});
