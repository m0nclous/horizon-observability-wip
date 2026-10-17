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

    /**
     * The statuses the tables count, in one query (ADDENDUM R2.4 O6).
     */
    public const COUNTED = 'completed|failed|timed_out|retried';

    private const LABELS = ['queue', 'job_class', 'status'];

    /**
     * @param  string  $startLookback  how far back the start of a period looks for the last counter value:
     *                                 max(10m, 10 x the scrape interval) (ADDENDUM R3.3; the pilot looked back a day)
     */
    public function __construct(private Promql $promql, private string $startLookback) {}

    /**
     * Attempts in the window, exactly: the counter difference without increase()'s extrapolation (legacy
     * `attempts_delta`). The start is the last value within the lookback before the window, so a scrape gap at
     * the boundary does not break the count; a series that did not exist before starts at its first value. After a
     * counter reset (negative difference) the value after the reset counts.
     */
    public function delta(string $statuses, string $window): string
    {
        $status = [Promql::re('status', $statuses)];
        $now = $this->sum($this->promql->selector(Metric::JobsTotal, ...$status));
        $start = Promql::paren(Promql::either(
            $this->sum($this->promql->over('last_over_time', Metric::JobsTotal, $this->startLookback, $status, offset: $window)),
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
        $periodStart = $this->sum($this->promql->over('last_over_time', Metric::JobsTotal, $this->startLookback, $status, at: Promql::FROM_SECONDS));

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
     * Attempts per graph step summed over every selected series, aggregate first (ADDENDUM R2.4 O4): point t is the
     * increase of the sum over (t, t + step], clipped to the period. The same values as the sum of {@see perStep()}
     * on continuous data at a fraction of the cost (#8 A 775 -> 43 ms in the T01 probe), because the set operations
     * act on one series instead of every (queue, class, status).
     *
     * A step edge takes the last value within the lookback, so a missed scrape does not zero a step. The first step
     * starts at the value at the start of the period (a series born inside the period at its first value), the last
     * one ends at the value at the end; only those two steps are clipped. A counter reset or a pruned class lowers
     * the sum: that step is 0 and the steps around it count as before, where clipping every step would zero the whole
     * period up to the reset. A class born inside the period counts the attempts it was born with.
     */
    public function perStepTotal(string $statuses): string
    {
        $status = [Promql::re('status', $statuses)];
        $sum = static fn (string $expr): string => Promql::sum($expr);

        $next = $sum($this->promql->over('last_over_time', Metric::JobsTotal, $this->startLookback, $status, offset: '-'.Promql::INTERVAL));
        $current = $sum($this->promql->over('last_over_time', Metric::JobsTotal, $this->startLookback, $status));
        $periodEnd = $sum($this->promql->over('last_over_time', Metric::JobsTotal, $this->startLookback, $status, at: Promql::TO_SECONDS));
        $periodStart = $sum(Promql::either(
            $this->promql->over('last_over_time', Metric::JobsTotal, $this->startLookback, $status, at: Promql::FROM_SECONDS),
            $this->promql->over('min_over_time', Metric::JobsTotal, Promql::RANGE, $status, at: Promql::TO_SECONDS),
        ));

        // The clip of a boundary step, and no clip (an infinite bound) for the steps between
        $only = static fn (string $bound, string $step, string $unbounded): string => Promql::call('scalar', Promql::either(
            Promql::paren(Promql::andOn($bound, Promql::paren($step))),
            'vector('.$unbounded.')',
        ));
        $lastStep = 'vector(time()) + '.Promql::INTERVAL_MS.' / 1000 > '.Promql::TO_SECONDS;
        $firstStep = 'vector(time()) < '.Promql::FROM_SECONDS;

        return Promql::call(
            'clamp_min',
            Promql::call('clamp_max', $next, $only($periodEnd, $lastStep, 'Inf'))
                .' - '.Promql::call('clamp_min', $current, $only($periodStart, $firstStep, '-Inf')),
            '0',
        );
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
