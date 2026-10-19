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
     * The statuses the tables count, in one query.
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
     *                                 max(10m, 10 x the scrape interval) (the pilot looked back a day)
     */
    public function __construct(private Promql $promql, private string $startLookback) {}

    /**
     * Attempts in the window, exactly: the counter difference without increase()'s extrapolation (legacy
     * `attempts_delta`), per series (the replica dedupe's labels: job, connection, queue, job_class and status), then
     * summed by (queue, job_class, status). The start is the last value within the lookback before the window, so a
     * scrape gap at the boundary does not break the count; a series without one (it appeared inside the window: a new
     * class, or the scrape job or a replica of an application added later; or it is back after an outage longer than
     * the lookback over the start of the window) starts at its first value in the window. The end is the series' last
     * value inside the window, so a series that stops inside it keeps the attempts it made up to its last sample: a
     * scrape job moved or renamed (such as a move to the endpoint's own scrape job), a Redis connection renamed, an
     * application decommissioned, a failed scrape or an outage at the end of the window. After a counter reset
     * (negative difference) the value after the reset counts, for that series only.
     *
     * The start is chosen per series, before the sum: a sum by (queue, job_class, status) has a start as soon as one of
     * its series has one, so a series that appears inside the window next to another job's series of the same queue,
     * class and status added its whole first value (its history), and a reset of one series made the sum's difference
     * negative and counted every series' whole value. The end was the instant value at the end of the window
     * (Prometheus' 5-minute lookback, nothing after a staleness marker), so a series without one lost every attempt
     * of the window: one failed scrape at the end left the tables at 0 while the tiles counted 2,043.
     */
    public function delta(string $statuses, string $window): string
    {
        $status = [Promql::re('status', $statuses)];
        // The last value in (t - window, t]: range selectors skip staleness markers, and the window is the one the
        // start's lookback ends at and its fallback reads
        $end = $this->promql->over('last_over_time', Metric::JobsTotal, $window, $status);
        // The full-range fallback stays: bounding it to the first LOOKBACK of the window would count a series' whole
        // value after a Prometheus outage longer than LOOKBACK over the start of the window
        $start = Promql::paren(Promql::either(
            $this->promql->over('last_over_time', Metric::JobsTotal, $this->startLookback, $status, offset: $window),
            $this->promql->over('min_over_time', Metric::JobsTotal, $window, $status),
        ));

        // The value after a reset where the difference is negative. Every series with an end has a sample inside the
        // window, so the fallback gives it a start, `or end` adds exactly the reset series, and the start, whose
        // fallback reads every sample of the window, is evaluated once (the legacy `or (now < start)` evaluated it
        // twice)
        return $this->sum(Promql::paren(Promql::either(Promql::paren($end.' - '.$start).' >= 0', $end)));
    }

    /**
     * Attempts per graph step (legacy `attempts_per_step`), per series as {@see delta()}, then summed by (queue,
     * job_class, status): point t is the counter increase over (t, t + step], never below 0, so the points add up to
     * {@see delta()} over the period at any step. Grafana aligns the first point to the step size, at or before the
     * period start, and the last at or before the period end, so the first step starts at the series' value at
     * `${__from}` and the last ends at its last value within the lookback at `${__to}`, as the tiles' last point (a
     * series without one appeared after the period or stopped before the last step; the instant value at `${__to}` left
     * out the last step of a series whose scrape failed at the end). A step edge takes the last value inside the step,
     * else the current one, so a staleness marker after a missed scrape does not zero the step. A series with no value
     * at the start of a step (new, or back after a gap) starts at its first value inside the step: attempts during the
     * gap are lost but not recounted. A counter reset counts 0 for its step on that series, as on the tiles
     * ({@see perStepTotal()}). A point whose step ends at or before the period start counts nothing.
     *
     * The period edges are the exact ones in milliseconds ({@see Promql::FROM}, {@see Promql::TO}), as the tables'
     * instant queries see them; with the edges rounded to whole seconds, a scrape between an edge and its rounded
     * second counted in only one of the two forms, and a point whose step ended before the exact start counted it.
     *
     * The legacy form clipped with the values instead of the times (max with the value at the period start, min with
     * the one at the end), so after a reset every later step of the series counted its whole value; and it chose the
     * start per (queue, job_class, status) sum (see {@see delta()}).
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
        $periodEnd = $this->promql->over('last_over_time', Metric::JobsTotal, $this->startLookback, $status, at: Promql::TO);
        $periodStart = $this->promql->over('last_over_time', Metric::JobsTotal, $this->startLookback, $status, at: Promql::FROM);
        $firstStep = Promql::paren(Promql::paren('vector(time()) <= '.Promql::FROM).' + '.$step.' > '.Promql::FROM);
        $lastStep = Promql::paren('vector(time()) + '.$step.' > '.Promql::TO);
        $beforePeriod = Promql::paren('vector(time()) + '.$step.' <= '.Promql::FROM);

        $later = Promql::either(Promql::andOn($periodEnd, $lastStep), Promql::unlessOn($stepEnd, $lastStep));
        $since = Promql::either(Promql::andOn($periodStart, $firstStep), $stepStart, $stepFirst);

        return Promql::unlessOn($this->sum(Promql::call('clamp_min', Promql::paren($later).' - '.Promql::paren($since), '0')), $beforePeriod);
    }

    /**
     * Attempts per graph step summed over every selected series (the Completed and Failed tiles): the points add up to
     * the attempts of the period exactly, {@see delta()} summed, at any step.
     *
     * Per series, one to one: the difference of the counter values at the end and at the start of the step, never
     * below 0, summed. The values are the last within the lookback (a missed scrape or a gap shorter than the lookback
     * loses no attempts). A series without a value at the start of the step (a new class, a replica or the scrape job
     * of an application added later, or a series back after a gap longer than the lookback) starts at its first value
     * inside the step, so it counts from its first value, never the first value itself, like {@see delta()}: the
     * exporter exports a new series as 0 first (it holds it at 0 for 90 s), so the attempts it is born with count. A
     * counter reset counts 0 for its step on that series (after a Redis restart the exporter recreates the series at 0
     * as well), and a pruned class stops counting.
     *
     * The step edges are subquery points at the graph step. Subquery points are multiples of the step since 1970,
     * Grafana's steps too unless the browser's UTC offset shifts them (a 2-hour step at UTC+3); point t counts the step
     * between the subquery points around it, (PREV, PREV + step]. Inside the period that is `idelta()` of the two
     * points (one evaluation of the counter values per point, shared by the steps), where a series without a value at
     * a subquery point takes its first value of the step after it (its smallest sample there): a series without a
     * value at PREV counts from its first value in the step. The point whose step holds `${__from}` starts at the
     * series' values at `${__from}`, and the last point (`end()`, the one whose step reaches `${__to}`) ends at their
     * values at `${__to}`: per series again, from vectors at those fixed times (evaluated once per query). A series
     * without a value at the start of such a step starts at its smallest sample from one step before the step's point
     * to one step after it, which holds the step's first value at any UTC offset and reaches `${__to}` from the last
     * point; a series without a value at `${__to}` appeared after the period. The points whose step ends before the
     * period are dropped.
     *
     * The period edges are the exact ones in milliseconds ({@see Promql::FROM}, {@see Promql::TO}), as the tables'
     * instant queries see them. With the edges rounded to whole seconds (`${__to:date:seconds}`, Math.round), a scrape
     * between an edge and its rounded second counted on the tables only, and where the browser's UTC offset is not a
     * multiple of the step (Asia/Kathmandu, UTC+5:45, at a 2-minute step) and the period ended less than half a second
     * before one of Grafana's points, no point passed the last-point test: the last step ended at the next subquery
     * point instead of the period end (13,196 failed on the tile, 13,283 on the tables over 6 hours).
     *
     * An earlier aggregate-first form (the difference of the sums) counted the whole value of a series that appears
     * inside the period; the form after it, `idelta()` alone, gave a series without a value at the earlier point no
     * step, so a series that appears between two subquery points lost its attempts up to the next one whenever the
     * graph step was longer than the scrape interval.
     */
    public function perStepTotal(string $statuses): string
    {
        $status = [Promql::re('status', $statuses)];
        $values = Promql::paren($this->promql->over('last_over_time', Metric::JobsTotal, $this->startLookback, $status));
        $at = fn (string $time): string => $this->promql->over('last_over_time', Metric::JobsTotal, $this->startLookback, $status, at: $time);
        $firstAround = fn (string $time): string => $this->promql->over('min_over_time', Metric::JobsTotal, self::TWO_STEPS, $status, offset: '-'.Promql::INTERVAL, at: $time);
        $subquery = Promql::INTERVAL.':'.Promql::INTERVAL;
        $step = Promql::paren(Promql::INTERVAL_MS.' / 1000');
        $previous = 'floor(vector(time()) / '.$step.') * '.$step;

        // The step that holds the period start, the last point, and the points whose step ends before the period
        $startStep = Promql::paren(Promql::paren($previous.' <= '.Promql::FROM).' + '.$step.' > '.Promql::FROM);
        $lastStep = Promql::paren('vector(time()) + '.$step.' > '.Promql::TO);
        $beforePeriod = Promql::paren($previous.' + '.$step.' <= '.Promql::FROM);
        $clamped = static fn (string $end, string $start): string => Promql::paren(Promql::sum(Promql::call('clamp_min', $end.' - '.Promql::paren($start), '0')));

        // Inside the period: the increase between the two subquery points around t, a series without a value at a
        // subquery point taking its first value of the step after it there (one subquery shared by the steps)
        $startable = Promql::paren(Promql::either(
            $this->promql->over('last_over_time', Metric::JobsTotal, $this->startLookback, $status),
            $this->promql->over('min_over_time', Metric::JobsTotal, Promql::INTERVAL, $status, offset: '-'.Promql::INTERVAL),
        ));
        $steps = Promql::paren(Promql::sum(Promql::call(
            'clamp_min',
            Promql::range('idelta', $startable, self::TWO_STEPS.':'.Promql::INTERVAL, '-'.Promql::INTERVAL),
            '0',
        )));
        // The edge points, per series from vectors at fixed times (evaluated once per query, so the selection by the
        // point below costs one sample per step): the step that holds the period start, from the values at ${__from}
        // to those at the next subquery point; the last point, from the values at its subquery point to those at
        // ${__to}; and a point that is both (a range shorter than two steps), from ${__from} to ${__to}
        $fromStart = Promql::either($at(Promql::FROM), $firstAround(Promql::FROM));
        $first = $clamped(Promql::range('last_over_time', $values, $subquery, '-'.Promql::INTERVAL, Promql::FROM), $fromStart);
        $last = $clamped($at(Promql::TO), Promql::either(Promql::range('last_over_time', $values, $subquery, at: 'end()'), $firstAround('end()')));
        $only = $clamped($at(Promql::TO), $fromStart);

        return Promql::unlessOn(
            Promql::paren(Promql::either(
                Promql::unlessOn($steps, Promql::paren(Promql::either($startStep, $lastStep))),
                Promql::andOn(Promql::andOn($only, $startStep), $lastStep),
                Promql::andOn($first, $startStep),
                Promql::andOn($last, $lastStep),
            )),
            $beforePeriod,
        );
    }

    private function sum(string $expr): string
    {
        return Promql::sum($expr, ...self::LABELS);
    }
}
