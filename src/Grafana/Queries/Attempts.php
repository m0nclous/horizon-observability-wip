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
     * Two graph steps as a duration: the step as Grafana prints it and again in milliseconds (`10s10000ms` = 20 s; a
     * Prometheus duration takes each unit once, largest first, and the step is at least the 5-second scrape interval).
     */
    private const TWO_STEPS = Promql::INTERVAL.'${__interval_ms}ms';

    /**
     * @param  string  $startLookback  how far back the start of a period looks for the last counter value:
     *                                 max(10m, 10 x the scrape interval) (ADDENDUM R3.3; the pilot looked back a day)
     */
    public function __construct(private Promql $promql, private string $startLookback) {}

    /**
     * Attempts in the window, exactly: the counter difference without increase()'s extrapolation (legacy
     * `attempts_delta`), per series (the replica dedupe's labels: job, connection, queue, job_class and status), then
     * summed by (queue, job_class, status). The start is the last value within the lookback before the window, so a
     * scrape gap at the boundary does not break the count; a series without one (it appeared inside the window: a new
     * class, or the scrape job or a replica of an application added later; or it is back after an outage longer than
     * the lookback over the start of the window) starts at its first value in the window. After a counter reset
     * (negative difference) the value after the reset counts, for that series only.
     *
     * The start is chosen per series, before the sum: a sum by (queue, job_class, status) has a start as soon as one of
     * its series has one, so a series that appears inside the window next to another job's series of the same queue,
     * class and status added its whole first value (its history), and a reset of one series made the sum's difference
     * negative and counted every series' whole value (T13 QA round 3).
     */
    public function delta(string $statuses, string $window): string
    {
        $status = [Promql::re('status', $statuses)];
        $now = $this->promql->selector(Metric::JobsTotal, ...$status);
        // The full-range fallback stays (ADDENDUM R2.4 O7): bounding it to the first LOOKBACK of the window would
        // count a series' whole value after a Prometheus outage longer than LOOKBACK over the start of the window
        $start = Promql::paren(Promql::either(
            $this->promql->over('last_over_time', Metric::JobsTotal, $this->startLookback, $status, offset: $window),
            $this->promql->over('min_over_time', Metric::JobsTotal, $window, $status),
        ));

        // The value after a reset where the difference is negative. Every series with a value now has a start (the
        // lookback covers Prometheus' 5 minutes), so `or now` adds exactly those, and the start, whose fallback reads
        // every sample of the window, is evaluated once (the legacy `or (now < start)` evaluated it twice)
        return $this->sum(Promql::paren(Promql::either(Promql::paren($now.' - '.$start).' >= 0', $now)));
    }

    /**
     * Attempts per graph step (legacy `attempts_per_step`), per series as {@see delta()}, then summed by (queue,
     * job_class, status): point t is the counter increase over (t, t + step], never below 0, so the points add up to
     * {@see delta()} over the period at any step. Grafana aligns the first point to the step size, at or before the
     * period start, and the last at or before the period end, so the first step starts at the series' value at
     * `${__from}` and the last ends at its value at `${__to}` (a series without a value then appeared after the
     * period). A step edge takes the last value inside the step, else the current one, so a staleness marker after a
     * missed scrape does not zero the step. A series with no value at the start of a step (new, or back after a gap)
     * starts at its first value inside the step: attempts during the gap are lost but not recounted. A counter reset
     * counts 0 for its step on that series, as on the tiles ({@see perStepTotal()}).
     *
     * The legacy form clipped with the values instead of the times (max with the value at the period start, min with
     * the one at the end), so after a reset every later step of the series counted its whole value; and it chose the
     * start per (queue, job_class, status) sum (T13 QA round 3, {@see delta()}).
     */
    public function perStep(string $statuses): string
    {
        $status = [Promql::re('status', $statuses)];
        $nextStep = '-'.Promql::INTERVAL;
        $step = Promql::paren(Promql::INTERVAL_MS.' / 1000');

        $stepEnd = Promql::paren(Promql::either(
            $this->promql->over('last_over_time', Metric::JobsTotal, Promql::INTERVAL, $status, offset: $nextStep),
            $this->promql->shifted(Metric::JobsTotal, $status, offset: $nextStep),
        ));
        $stepStart = Promql::paren(Promql::either(
            $this->promql->over('last_over_time', Metric::JobsTotal, Promql::INTERVAL, $status),
            $this->promql->selector(Metric::JobsTotal, ...$status),
        ));
        $stepFirst = $this->promql->over('min_over_time', Metric::JobsTotal, Promql::INTERVAL, $status, offset: $nextStep);
        $periodEnd = $this->promql->shifted(Metric::JobsTotal, $status, at: Promql::TO_SECONDS);
        $periodStart = $this->promql->over('last_over_time', Metric::JobsTotal, $this->startLookback, $status, at: Promql::FROM_SECONDS);
        $firstStep = Promql::paren(Promql::paren('vector(time()) <= '.Promql::FROM_SECONDS).' + '.$step.' > '.Promql::FROM_SECONDS);
        $lastStep = Promql::paren('vector(time()) + '.$step.' > '.Promql::TO_SECONDS);

        $later = Promql::either(Promql::andOn($periodEnd, $lastStep), Promql::unlessOn($stepEnd, $lastStep));
        $since = Promql::either(Promql::andOn($periodStart, $firstStep), $stepStart, $stepFirst);

        return $this->sum(Promql::call('clamp_min', Promql::paren($later).' - '.Promql::paren($since), '0'));
    }

    /**
     * Attempts per graph step summed over every selected series (the Completed and Failed tiles): the points add up to
     * the attempts of the period exactly.
     *
     * Per series, one to one: the difference of two consecutive counter values, each the last value within the
     * lookback (a missed scrape or a gap shorter than the lookback loses no attempts), only for a series present at
     * both, and never below 0. So a series that appears inside the period (a new class, a replica or the scrape job of
     * an application added later) counts from its first value, never the first value itself, like {@see delta()}: T16
     * exports a new series as 0 first, so the attempts it is born with count. A counter reset counts 0 for its step on
     * that series (after a Redis restart T16 recreates the series at 0 as well), and a pruned class stops counting.
     *
     * The counter values come from one subquery at the graph step (`idelta()` of its last two points), which reads
     * the lookback once per step instead of twice. Subquery points are multiples of the step since 1970, Grafana's
     * steps too unless the browser's UTC offset shifts them (a 2-hour step at UTC+3); point t is the increase between
     * the subquery points around it. The first point that reaches the period start is clipped to it, an earlier one
     * dropped, and the last point is completed or clipped to the period end, with sums of per-series differences at
     * those fixed times (evaluated once per query).
     *
     * The aggregate-first form of ADDENDUM R2.4 O4 counted the whole value of a series that appears inside the period
     * (T13 QA round 2).
     */
    public function perStepTotal(string $statuses): string
    {
        $status = [Promql::re('status', $statuses)];
        $values = Promql::paren($this->promql->over('last_over_time', Metric::JobsTotal, $this->startLookback, $status));
        $at = fn (string $time): string => $this->promql->over('last_over_time', Metric::JobsTotal, $this->startLookback, $status, at: $time);
        $step = Promql::paren(Promql::INTERVAL_MS.' / 1000');
        $previous = 'floor(vector(time()) / '.$step.') * '.$step;

        // Point t: the two subquery points up to one step after t, i.e. its own (aligned) or the ones around it
        $steps = Promql::sum(Promql::call('clamp_min', Promql::range('idelta', $values, self::TWO_STEPS.':'.Promql::INTERVAL, '-'.Promql::INTERVAL), '0'));
        // The attempts between the subquery point before the period start and the start; after the last subquery
        // point and the period end (negative when that point is after the end)
        $beforeStart = Promql::sum($at(Promql::FROM_SECONDS).' - '.Promql::range('last_over_time', $values, Promql::INTERVAL.':'.Promql::INTERVAL, at: Promql::FROM_SECONDS));
        $toEnd = Promql::sum($at(Promql::TO_SECONDS).' - '.Promql::range('last_over_time', $values, Promql::INTERVAL.':'.Promql::INTERVAL, '-'.Promql::INTERVAL, 'end()'));
        $startStep = Promql::paren($previous.' <= '.Promql::FROM_SECONDS).' + '.$step.' > '.Promql::FROM_SECONDS;
        $lastStep = 'vector(time()) + '.$step.' > '.Promql::TO_SECONDS;
        $beforePeriod = $previous.' + '.$step.' <= '.Promql::FROM_SECONDS;

        return Promql::unlessOn(
            Promql::paren(
                $steps
                .' - '.Promql::paren(Promql::either(Promql::andOn($beforeStart, Promql::paren($startStep)), 'vector(0)'))
                .' + '.Promql::paren(Promql::either(Promql::andOn($toEnd, Promql::paren($lastStep)), 'vector(0)')),
            ),
            Promql::paren($beforePeriod),
        );
    }

    private function sum(string $expr): string
    {
        return Promql::sum($expr, ...self::LABELS);
    }
}
