<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Grafana\Queries;

use DF\HorizonObservability\Grafana\Promql;
use DF\HorizonObservability\Metrics\Metric;

/**
 * How long pending jobs wait, relative to the `horizon.waits` limit of their queue.
 *
 * @internal
 */
final readonly class Waits
{
    /**
     * Durations below this many seconds print as "5 s", longer ones as "17 min".
     */
    public const SECONDS_TEXT_LIMIT = 120;

    public function __construct(
        private Promql $promql,
        private Selection $selection,
    ) {}

    /**
     * Oldest pending job age over the wait limit per queue (legacy `WAIT_RATIO`, also the long-wait alert).
     */
    public function ratio(): string
    {
        return Promql::binary(
            Promql::max($this->promql->selector(Metric::QueueOldestPendingJobAge), 'queue'),
            '/',
            Promql::max($this->promql->selector(Metric::WaitThreshold), 'queue'),
            on: ['queue'],
        );
    }

    /**
     * Level 0, 1 or 2 of a ratio: above the warning factor (1), plus above the critical factor (2). Written as
     * `clamp(ceil(ratio) - 1, 0, 2)`, which evaluates the ratio once (its p95 is a histogram quantile over the
     * period) where the two comparisons evaluated it twice; WaitLevelTest pins it to the factors and the comparisons.
     */
    public static function levels(string $ratio): string
    {
        return Promql::call('clamp', Promql::call('ceil', $ratio).' - 1', '0', '2');
    }

    /**
     * Level of $value against the wait limit of its queue (legacy `OLDEST_LEVEL`, `WAIT_P95_LEVEL`).
     */
    public function levelAgainstLimit(string $value): string
    {
        return self::levels(Promql::binary($value, '/', $this->queueWaitLimit(), on: ['queue']));
    }

    /**
     * The worst level over all queues (legacy `WAIT_LEVEL`).
     */
    public function worstLevel(): string
    {
        return Promql::max(self::levels($this->ratio()));
    }

    /**
     * Oldest pending job age of the queue with the worst ratio, when it is not zero and every job is selected
     * (legacy `WORST_AGE`). The parentheses matter: comparisons bind tighter than `and`.
     */
    public function worstAge(): string
    {
        $age = Promql::andOn(
            Promql::max($this->promql->selector(Metric::QueueOldestPendingJobAge), 'queue'),
            Promql::topk(1, $this->ratio()),
            'queue',
        );

        return Promql::paren(Promql::andOn(Promql::paren($age).' > 0', $this->selection->allJobsSelected()));
    }

    /**
     * Oldest pending job age per queue, 0 for configured queues without one (legacy `OLDEST_AGE`).
     */
    public function oldestAge(): string
    {
        $queues = $this->selection->queues();

        return Promql::paren(Promql::either(
            Promql::andOn(Promql::max($this->promql->selector(Metric::QueueOldestPendingJobAge), 'queue'), $queues, 'queue'),
            '0 * '.$queues,
        ));
    }

    /**
     * The `horizon.waits` limit per selected queue (legacy `QUEUE_WAIT_LIMIT`).
     */
    public function queueWaitLimit(): string
    {
        return Promql::max($this->promql->selector(Metric::WaitThreshold, Promql::re('queue', '$queue')), 'queue');
    }

    /**
     * p95 of the wait for a free worker before the first attempt over the period; NaN (no waits) is dropped
     * (legacy `WAIT_P95`).
     */
    public function waitP95(): string
    {
        $buckets = $this->promql->over('increase', Metric::JobWait, Promql::RANGE, [Promql::re('queue', '$queue')], series: 'bucket');

        return Promql::paren(Promql::call('histogram_quantile', '0.95', Promql::sum($buckets, 'queue', 'le')).' >= 0');
    }

    /**
     * The value per queue as text in $label: "5 s" below two minutes (rounded to $secondsStep), then "17 min".
     * count_values turns the number into the label; the sample value is meaningless (legacy `duration_label`).
     */
    public static function durationLabel(string $expr, string $label, string $secondsStep = '1'): string
    {
        $seconds = Promql::countValues($label, Promql::call('round', Promql::paren($expr).' < '.self::SECONDS_TEXT_LIMIT, $secondsStep), 'queue');
        $minutes = Promql::countValues($label, Promql::call('round', Promql::paren(Promql::paren($expr).' >= '.self::SECONDS_TEXT_LIMIT).' / 60'), 'queue');

        return Promql::paren(Promql::either(
            Promql::labelReplace($seconds, $label, '$1 s', $label, '(.*)'),
            Promql::labelReplace($minutes, $label, '$1 min', $label, '(.*)'),
        ));
    }
}
