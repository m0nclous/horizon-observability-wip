<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Grafana\Queries;

use DF\HorizonObservability\Grafana\Promql;
use DF\HorizonObservability\Metrics\Metric;

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
     *
     * The text sits in a PromQL string literal unescaped (Grafana's `text` format is raw). The Job option texts are the
     * classes without their namespace (Variables::jobQuery()), so a real option holds no backslash. Known limit, as in
     * the pilot: a value that matches no option (a shared link with a class that is no longer exported) has the value
     * as its text, and a batch kind, queue or supervisor is its own text; a backslash or a double quote in such a text
     * makes the expression unparseable, and the panels that test the filter show the query error, never a number.
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
     * (legacy `QUEUES`). With $window, every queue exported within it: the rows of a table of the period, so an
     * application whose scrape failed at the end of the period, or that stopped inside it, keeps its queues and
     * their attempts.
     */
    public function queues(?string $window = null): string
    {
        return Promql::group($window === null
            ? $this->promql->selector(Metric::QueuePendingJobs)
            : $this->promql->over('last_over_time', Metric::QueuePendingJobs, $window), 'queue');
    }

    /**
     * 0 while the application exports any metrics, which tells "zero" from "no data" (legacy `ZERO_IF_METRICS`).
     */
    public function zeroIfMetrics(): string
    {
        return 'on () 0 * '.Promql::group($this->promql->selector(Metric::HorizonStatus));
    }

    /**
     * Non-empty while Batch and Job are both "All": queue figures are then taken for the whole queue
     * (legacy `ALL_JOBS_SELECTED`).
     */
    public static function allJobsSelected(): string
    {
        return Promql::paren(Promql::andOn(self::isAll('job_class'), self::isAll('batch_kind')));
    }

    /**
     * The whole-queue value while every job is selected; with a batch or jobs selected the value over their
     * classes, or nothing (a dash) when no per-class value exists (legacy `by_job_selection`).
     */
    public function byJobSelection(string $wholeQueue, ?string $selectedJobs = null): string
    {
        $whole = Promql::paren(Promql::andOn(Promql::paren($wholeQueue), self::allJobsSelected()));

        if ($selectedJobs === null) {
            return $whole;
        }

        return Promql::either($whole, Promql::paren(Promql::unlessOn(Promql::paren($selectedJobs), self::allJobsSelected())));
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
