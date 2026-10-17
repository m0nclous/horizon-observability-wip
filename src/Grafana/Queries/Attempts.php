<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Grafana\Queries;

use DF\HorizonObservability\Grafana\Promql;
use DF\HorizonObservability\Metrics\Metric;

/**
 * Exact attempt counts from the `queue_jobs_total` counter.
 *
 * @internal
 */
final readonly class Attempts
{
    public const COMPLETED = 'completed';

    public const FAILED = 'failed|timed_out';

    public const FINISHED = 'completed|failed|timed_out';

    public const RETRIED = 'retried';

    public const TIMED_OUT = 'timed_out';

    private const LABELS = ['queue', 'job_class', 'status'];

    /**
     * How far back the start of a period looks for the last counter value.
     */
    private const START_LOOKBACK = '1d';

    public function __construct(private Promql $promql) {}

    /**
     * Attempts in the window, exactly: the counter difference without increase()'s extrapolation (legacy
     * `attempts_delta`). The start is the last value within a day before the window, so a scrape gap at the
     * boundary does not break the count; a series that did not exist before starts at its first value. After a
     * counter reset (negative difference) the value after the reset counts.
     */
    public function delta(string $statuses, string $window): string
    {
        $status = [Promql::re('status', $statuses)];
        $now = $this->sum($this->promql->selector(Metric::JobsTotal, ...$status));
        $start = Promql::paren(Promql::either(
            $this->sum($this->promql->over('last_over_time', Metric::JobsTotal, self::START_LOOKBACK, $status, offset: $window)),
            $this->sum($this->promql->over('min_over_time', Metric::JobsTotal, $window, $status)),
        ));

        return $this->clampedDifference($now, $start);
    }

    /**
     * Attempts per graph step (legacy `attempts_per_step`): point t is the counter increase over (t, t + step],
     * clipped to the period, so the points add up to {@see delta()} over the period at any step. Grafana aligns
     * the first step to the step size, so the outer steps are cut by the start and the end of the period (the
     * counter never decreases: max with the start, min with the end). A step edge takes the last value inside
     * the step, else the current one, so a staleness marker after a missed scrape does not zero the step. With
     * no value at the start of a step (new series, or back after a gap) the first value inside the step is the
     * start: attempts during the gap are lost but not recounted.
     */
    public function perStep(string $statuses): string
    {
        $status = [Promql::re('status', $statuses)];
        $nextStep = '-'.Promql::INTERVAL;

        $stepEnd = Promql::paren(Promql::either(
            $this->sum($this->promql->over('last_over_time', Metric::JobsTotal, Promql::INTERVAL, $status, offset: $nextStep)),
            $this->sum($this->promql->shifted(Metric::JobsTotal, $status, offset: $nextStep)),
        ));
        $stepStart = Promql::paren(Promql::either(
            $this->sum($this->promql->over('last_over_time', Metric::JobsTotal, Promql::INTERVAL, $status)),
            $this->sum($this->promql->selector(Metric::JobsTotal, ...$status)),
        ));
        $stepFirst = $this->sum($this->promql->over('min_over_time', Metric::JobsTotal, Promql::INTERVAL, $status, offset: $nextStep));
        $periodEnd = $this->sum($this->promql->shifted(Metric::JobsTotal, $status, at: Promql::TO_SECONDS));
        $periodStart = $this->sum($this->promql->over('last_over_time', Metric::JobsTotal, self::START_LOOKBACK, $status, at: Promql::FROM_SECONDS));

        $later = Promql::paren(Promql::either(
            Promql::paren($stepEnd.' <= '.$periodEnd),
            Promql::paren(Promql::andOn($periodEnd, $stepEnd, ...self::LABELS)),
            Promql::paren(Promql::unlessOn($stepEnd, $periodEnd, ...self::LABELS)),
        ));
        $since = Promql::paren(Promql::either(
            Promql::paren($stepStart.' >= '.$periodStart),
            Promql::paren(Promql::andOn($periodStart, $stepStart, ...self::LABELS)),
            Promql::paren(Promql::unlessOn($stepStart, $periodStart, ...self::LABELS)),
            Promql::paren(Promql::unlessOn($stepFirst, $stepStart, ...self::LABELS)),
        ));

        return $this->clampedDifference($later, $since);
    }

    /**
     * `end - start`, or `end` alone where the counter was reset in between.
     */
    private function clampedDifference(string $end, string $start): string
    {
        return Promql::paren(Promql::either(
            Promql::paren($end.' - '.$start).' >= 0',
            Promql::paren($end.' < '.$start),
        ));
    }

    private function sum(string $expr): string
    {
        return Promql::sum($expr, ...self::LABELS);
    }
}
