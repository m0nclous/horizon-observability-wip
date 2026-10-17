<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Grafana\Queries;

use DF\HorizonObservability\Grafana\Promql;

/**
 * What the dashboard filters (Supervisor, Batch, Queue, Job) select, and the row sets most panels join on.
 *
 * @internal
 */
final readonly class Selection
{
    public function __construct(private Promql $promql) {}

    /**
     * Non-empty while the filter shows "All": its text (`${var:text}`) is compared with "All" through labels
     * (legacy `filter_is_all`).
     */
    public static function isAll(string $variable): string
    {
        return Promql::paren(Promql::andOn(
            Promql::withLabel('vector(1)', 'filter', '${'.$variable.':text}'),
            Promql::withLabel('vector(1)', 'filter', 'All'),
            'filter',
        ));
    }

    /**
     * Every configured queue: the pending-jobs gauge is exported for each queue, empty ones included
     * (legacy `QUEUES`).
     */
    public function queues(): string
    {
        return Promql::group($this->promql->selector('queue_pending_jobs'), 'queue');
    }

    /**
     * 0 while the application exports any metrics, which tells "zero" from "no data" (legacy `ZERO_IF_METRICS`).
     */
    public function zeroIfMetrics(): string
    {
        return 'on () 0 * '.Promql::group($this->promql->selector('horizon_status'));
    }

    /**
     * Narrows the rows of $expr to the queues or classes ($label) of the selected batch kinds; no narrowing while
     * Batch is "All" (legacy `batch_filtered`).
     */
    public function batchFiltered(string $expr, string $label): string
    {
        $classes = $this->promql->selector('queue_batch_job_classes', Promql::re('batch', '$batch_kind'));

        return Promql::paren(Promql::either(
            Promql::andOn(Promql::paren($expr), Promql::group($classes, $label), $label),
            Promql::andOn(Promql::paren($expr), self::isAll('batch_kind')),
        ));
    }

    /**
     * Non-empty while Batch and Job are both "All": queue figures are then taken for the whole queue
     * (legacy `ALL_JOBS_SELECTED`).
     */
    public function allJobsSelected(): string
    {
        return Promql::paren(Promql::andOn(self::isAll('job_class'), self::isAll('batch_kind')));
    }

    /**
     * The whole-queue value while every job is selected; with a batch or jobs selected the value over their
     * classes, or nothing (a dash) when no per-class value exists (legacy `by_job_selection`).
     */
    public function byJobSelection(string $wholeQueue, ?string $selectedJobs = null): string
    {
        $whole = Promql::paren(Promql::andOn(Promql::paren($wholeQueue), $this->allJobsSelected()));

        if ($selectedJobs === null) {
            return $whole;
        }

        return Promql::either($whole, Promql::paren(Promql::unlessOn(Promql::paren($selectedJobs), $this->allJobsSelected())));
    }

    /**
     * Non-empty while Queue, Supervisor and Batch are "All": classes that never ran have no queue, supervisor
     * or batch and are shown only then (legacy `UNFILTERED`).
     */
    public static function unfiltered(): string
    {
        return Promql::andOn(Promql::andOn(self::isAll('queue'), self::isAll('supervisor')), self::isAll('batch_kind'));
    }
}
