<?php

declare(strict_types=1);

use DF\HorizonObservability\Batches\BatchKind;
use DF\HorizonObservability\Batches\BatchReader;
use DF\HorizonObservability\Batches\BatchSnapshot;
use DF\HorizonObservability\Batches\BatchStatus;

/*
| The status matrix of a batch row: BatchReader::snapshot() builds the BatchSnapshot of one row.
*/

it('classifies a batch row', function (int $total, int $pending, int $failed, ?int $finished, ?int $cancelled, BatchStatus $status, ?int $closedAt): void {
    $snapshot = BatchReader::snapshot([
        'id' => 'b', 'name' => 'n', 'total_jobs' => $total, 'pending_jobs' => $pending, 'failed_jobs' => $failed,
        'created_at' => 1000, 'finished_at' => $finished, 'cancelled_at' => $cancelled,
    ], new BatchKind);

    expect([$snapshot->status, $snapshot->closedAt])->toBe([$status, $closedAt])
        ->and(BatchReader::status($total, $pending, $failed, $finished, $cancelled))->toBe($status);
})->with([
    'running' => [10, 5, 0, null, null, BatchStatus::Running, null],
    'running, some failed' => [10, 5, 2, null, null, BatchStatus::Running, null],
    'row created before its jobs were added' => [0, 0, 0, null, null, BatchStatus::Running, null],
    'finished' => [10, 0, 0, 2000, null, BatchStatus::Finished, 2000],
    'finished with failures' => [10, 1, 1, 2000, null, BatchStatus::Failures, 2000],
    'empty batch closed by Laravel' => [0, 0, 0, 1000, null, BatchStatus::Finished, 1000],
    'allowFailures, every job ran, failures' => [10, 3, 3, null, null, BatchStatus::Failures, 1000],
    'all done without finished_at' => [10, 0, 0, null, null, BatchStatus::Finished, 1000],
    'cancelled' => [10, 5, 0, null, 1500, BatchStatus::Cancelled, 1500],
    'cancelled with failures' => [10, 5, 2, null, 1500, BatchStatus::Cancelled, 1500],
    'cancelled, finished later' => [10, 0, 0, 1800, 1500, BatchStatus::Cancelled, 1500],
]);

it('computes the progress as Horizon does', function (int $total, int $pending, int $progress): void {
    $snapshot = BatchReader::snapshot(['id' => 'b', 'total_jobs' => $total, 'pending_jobs' => $pending, 'failed_jobs' => 0, 'created_at' => 1], new BatchKind);

    expect($snapshot->progress)->toBe($progress);
})->with([
    'no jobs' => [0, 0, 0],
    'none processed' => [3, 3, 0],
    'rounded down' => [3, 2, 33],
    'rounded up' => [3, 1, 67],
    'half' => [2, 1, 50],
    'all' => [7, 0, 100],
]);

it('reads rows as objects or arrays and keeps the raw name', function (): void {
    $row = ['id' => 'b1', 'name' => 'Import 2026-10-17', 'total_jobs' => 4, 'pending_jobs' => 4, 'failed_jobs' => 0, 'created_at' => 1700000000];
    $expected = new BatchSnapshot('b1', 'Import 2026-10-17', 'Import', BatchStatus::Running, 4, 4, 0, 0, 1700000000, null);

    expect(BatchReader::snapshot($row, new BatchKind))->toEqual($expected)
        ->and(BatchReader::snapshot((object) $row, new BatchKind))->toEqual($expected);
});

it('reads a corrupt number past the integer range as missing instead of wrapping it (PHP 8.5)', function (): void {
    // (int) 1e30 wraps on PHP 8.2-8.4 and warns on 8.5 (failOnWarning), which would fail the whole collector.
    $snapshot = BatchReader::snapshot([
        'id' => 'b', 'name' => 'n', 'total_jobs' => 1e30, 'pending_jobs' => '1e30', 'failed_jobs' => '2',
        'created_at' => '1700000000.9', 'finished_at' => -1e30, 'cancelled_at' => 1700000100.5,
    ], new BatchKind);

    expect([$snapshot->total, $snapshot->pending, $snapshot->failed, $snapshot->createdAt, $snapshot->closedAt])
        ->toBe([0, 0, 2, 1700000000, 1700000100])
        ->and(BatchReader::snapshot(['id' => 'b', 'total_jobs' => 1, 'pending_jobs' => PHP_INT_MIN, 'failed_jobs' => 0, 'created_at' => 1], new BatchKind)->progress)
        ->toBe(0);
});
