<?php

declare(strict_types=1);

namespace Workbench\App\Simulation;

/**
 * One simulated batch kind. Names carry numbers or a UUID like real batches; the package's batch-kind normaliser strips
 * them, so every kind is one row. A failing job cancels a batch without allowFailures; with allowFailures the batch
 * finishes with failed jobs.
 */
final readonly class BatchProfile
{
    /**
     * Timeout of batch jobs: the batch bookkeeping (a locked job_batches row) counts towards the job timeout, and with
     * a slow database the jobs of one batch wait for each other longer than they work.
     */
    public const JOB_TIMEOUT_SECONDS = 60;

    /**
     * @param  string  $pattern  sprintf pattern of the batch name: %d becomes a number, %s a UUID
     * @param  array{0: int, 1: int}  $continuousSize  jobs per batch in the continuous run, from and to
     * @param  int  $weight  how often the continuous run picks this kind
     */
    public function __construct(
        public string $pattern,
        public string $task,
        public string $queue,
        public int $oneShotSize,
        public float $oneShotMeanSeconds,
        public array $continuousSize,
        public float $continuousMeanSeconds,
        public int $failed,
        public bool $allowFailures,
        public int $weight,
    ) {}

    /**
     * @return list<self>
     */
    public static function catalog(): array
    {
        return [
            new self('test_import_%d', 'TestBatchImportJob', 'slow', 8, 2.0, [150, 300], 3.0, 0, false, 5),
            new self('test_report_%s', 'TestBatchReportJob', 'reports', 6, 3.0, [60, 120], 4.0, 1, true, 3),
            new self('test_sync_%d', 'TestBatchSyncJob', 'slow', 6, 1.5, [40, 60], 2.0, 1, false, 2),
        ];
    }

    /**
     * The batch kind as the dashboard shows it.
     */
    public function kind(): string
    {
        return rtrim(str_replace(['%d', '%s'], '', $this->pattern), '_');
    }
}
