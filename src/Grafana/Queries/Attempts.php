<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Grafana\Queries;

use DF\HorizonObservability\Grafana\Promql;

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
        $selector = $this->selector($statuses);
        $now = $this->sum($selector);
        $start = Promql::paren(Promql::either(
            $this->sum(Promql::range('last_over_time', $selector, self::START_LOOKBACK, offset: $window)),
            $this->sum(Promql::range('min_over_time', $selector, $window)),
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
        $selector = $this->selector($statuses);
        $nextStep = '-'.Promql::INTERVAL;

        $stepEnd = Promql::paren(Promql::either(
            $this->sum(Promql::range('last_over_time', $selector, Promql::INTERVAL, offset: $nextStep)),
            $this->sum(Promql::offset($selector, $nextStep)),
        ));
        $stepStart = Promql::paren(Promql::either(
            $this->sum(Promql::range('last_over_time', $selector, Promql::INTERVAL)),
            $this->sum($selector),
        ));
        $stepFirst = $this->sum(Promql::range('min_over_time', $selector, Promql::INTERVAL, offset: $nextStep));
        $periodEnd = $this->sum(Promql::at($selector, Promql::TO_SECONDS));
        $periodStart = $this->sum(Promql::range('last_over_time', $selector, self::START_LOOKBACK, at: Promql::FROM_SECONDS));

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

    private function selector(string $statuses): string
    {
        return $this->promql->selector('queue_jobs_total', Promql::re('status', $statuses));
    }

    private function sum(string $expr): string
    {
        return Promql::sum($expr, ...self::LABELS);
    }
}
