<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Batches;

/**
 * One row of the batch table as the batches collector exports it. Times are Unix seconds.
 *
 * @internal
 */
final readonly class BatchSnapshot
{
    /**
     * @param  string  $name  the batch name ('' when unnamed)
     * @param  string  $kind  the batch kind label (`BatchKind` or the `resolveBatchKindUsing()` hook)
     * @param  int  $progress  percent of processed jobs, `round((total - pending) / total * 100)`
     * @param  int|null  $closedAt  finish or cancel time; null while running
     */
    public function __construct(
        public string $id,
        public string $name,
        public string $kind,
        public BatchStatus $status,
        public int $total,
        public int $pending,
        public int $failed,
        public int $progress,
        public int $createdAt,
        public ?int $closedAt,
    ) {}
}
