<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Collectors;

use DF\HorizonObservability\Attempts\AttemptStatus;
use DF\HorizonObservability\Attempts\AttemptStore;
use DF\HorizonObservability\Attempts\CounterResetGuard;
use DF\HorizonObservability\Config\Config;
use DF\HorizonObservability\Metrics\HistogramSnapshot;
use DF\HorizonObservability\Metrics\Labels;
use DF\HorizonObservability\Metrics\Metric;
use DF\HorizonObservability\Metrics\MetricFamily;
use DF\HorizonObservability\Metrics\ScrapeContext;
use DF\HorizonObservability\Redis\Keys;
use DF\HorizonObservability\Redis\RedisScriptFailed;
use DF\HorizonObservability\Redis\ServerClock;
use Throwable;

/**
 * Collector `attempts` (Arch Q1.4, Q4, Q6.10, Q6.11; Addendum R3.4, R4.11): `queue_jobs_total`, the duration and wait
 * histograms, the duration extremes of the last 150 seconds, `exporter_attempts_dropped_total` and the live push
 * self-metrics, read from the storage written by the workers.
 *
 * Per scrape: the reset guard `EVAL`, the five counter hashes, one `new_series` `EVAL`, one `extremes_read` `EVAL`,
 * `meta` and the job-class set (read only), on the short-timeout reader of the storage connection. Values are
 * absolute (no registry, C3).
 * - Every (connection, queue, job_class) of `queue_jobs_total` is zero-filled with all five statuses, so the first
 *   failure or timeout of a known class is visible to `increase()`, and has a duration histogram (all zero until
 *   its first duration); every queue of those has a wait histogram (all zero until its first wait).
 * - **New series start at 0** (T16, owner decision 13). Prometheus cannot count the first sample of a series:
 *   `increase()` and the dashboard's exact differences start from it, so a class or queue whose first scrape showed
 *   3 completed attempts counted 0 of them. The exporter knows when a series was created
 *   ({@see Keys::created()}, written by `record_attempt.lua` with its first count) and exports a series created less
 *   than {@see self::NEW_SERIES_HOLD_SECONDS} ago as 0: every scraper records at least one 0 sample before the real
 *   value, which then counts in full. The creation time and the age are both on the storage Redis' clock
 *   ({@see ServerClock}: `TIME` inside `record_attempt.lua` and `new_series.lua`), so the clocks of the workers, of
 *   the replicas behind the one scrape target (Arch Q1.5) and of the scrapers never enter the hold, and the end of
 *   the hold is one decision for every replica. `new_series.lua` deletes the creation time of a released series, so
 *   it is never held again, whatever any clock does later: an exported counter never drops back to 0. Series without
 *   a creation time (the counters of a scrape job added later, of a replica that starts later, data from before the
 *   creation times, released series) are exported as they are, so their old totals are never counted as new. The
 *   cost: the first attempts of a new (connection, queue, job_class) and the first waits of a new queue show up to
 *   90 s late, and while a new class of a known queue is held, that queue's wait histogram (not held) already counts
 *   its first waits.
 * - Fields of classes that left the class set (idle for 30 days and pruned by `record_attempt.lua` when the full set
 *   needed room for a new class, Arch Q4) are not exported and are deleted lazily, at most
 *   {@see self::FORGET_LIMIT} per scrape; `(other)` always stays.
 * - Histograms are cumulative over the configured bounds ({@see HistogramSnapshot}).
 * - `exporter_attempts_dropped_total` is always exported; the live push series only once a stream leader pushed
 *   (`meta.live_push_at`).
 * - A failing reset guard or deletion keeps the data but marks the collector as failed; so does a failing hold
 *   script (e.g. `TIME` refused by an ACL), which holds every series that still has a creation time instead.
 *
 * @internal
 */
final readonly class AttemptsCollector implements Collector
{
    /**
     * Stale hash fields deleted per scrape at most.
     */
    public const FORGET_LIMIT = 1000;

    /**
     * Seconds a new counter series is exported as 0, so that every scraper exports at least one 0 sample of it.
     *
     * Measured on one clock, the storage Redis' ({@see ServerClock}). A scraper whose last scrape before the creation
     * read the counters at r sees the new series first at its next successful scrape and exports it as 0 if that
     * scrape's hold read comes less than 90 s after the creation, which is certain when it comes less than 90 s
     * after r: the successful scrapes of one scraper must read the counters less than 90 s apart (less the one round
     * trip from the counter read to the hold read). That covers S <= 60 s (Addendum R3) with 30 s to spare, e.g.
     * scrapes less than 15 s off their slots in both directions at S = 60 s (twice the deviation budget of Addendum
     * R3.4), or up to 7 failed scrapes in a row at S = 10 s. No other clock enters: the workers' and the exporter
     * replicas' clocks may be anything. Beyond the budget (a slower scrape interval, longer gaps, a step of the Redis
     * clock) a new series can start without its 0 sample, as without the hold, but no exported counter ever
     * decreases. Proven by tests/Truth/FirstValueTruthTest at the worst alignment, with any worker and replica clocks.
     */
    public const NEW_SERIES_HOLD_SECONDS = 90;

    public function __construct(
        private AttemptStore $store,
        private CounterResetGuard $guard,
        private Keys $keys,
        private Config $config,
    ) {}

    public function name(): string
    {
        return 'attempts';
    }

    public function collect(ScrapeContext $context): iterable
    {
        $now = $context->now();

        try {
            $this->guard->check($now);
        } catch (Throwable $e) {
            $context->reportFailure('attempts:reset_guard', $e);
        }

        // The hold is read right after the hashes: a series created in between has no counts in them yet, so a
        // series with counts always has its creation time there, unless it was released already. The class set is
        // read last: a class admitted after the hashes were read has no field in them yet, so a brand-new class is
        // never taken for a stale one.
        $counters = $this->store->counters();

        try {
            $held = array_fill_keys($this->store->held(self::NEW_SERIES_HOLD_SECONDS), true);
        } catch (RedisScriptFailed $e) {
            // E.g. TIME refused by an ACL: hold every series that still has a creation time and release none, so no
            // exported counter can drop back to 0 later.
            $context->reportFailure('attempts:hold', $e);
            $held = array_fill_keys($this->store->createdFields(), true);
        }

        $extremes = $this->store->extremes($now);
        $meta = $this->store->meta();
        $known = array_fill_keys($this->store->jobClasses(), true);
        $known[Labels::OTHER] = true;

        /** @var array<string, list<string>> $stale */
        $stale = [];
        /** @var array<string, array{connection: string, queue: string, job_class: string}> $tuples */
        $tuples = [];

        $families = [
            $this->jobsTotal($counters['attempts'], $known, $held, $stale, $tuples),
            $this->durations($counters['durationBuckets'], $counters['durationSum'], $known, $held, $tuples, $stale),
            $this->waits($counters['waitBuckets'], $counters['waitSum'], $held, $tuples),
            $this->extremes(Metric::JobDurationMin, $extremes['min'], $known),
            $this->extremes(Metric::JobDurationMax, $extremes['max'], $known),
            ...$this->meta($meta),
        ];

        if ($stale !== [] && ! $context->deadlineExceeded()) {
            try {
                $this->store->forget($this->limit($stale));
            } catch (Throwable $e) {
                $context->reportFailure('attempts:forget', $e);
            }
        }

        return $families;
    }

    /**
     * @param  array<string, string>  $fields  `conn\x1fqueue\x1fclass\x1fstatus` => count
     * @param  array<string, true>  $known
     * @param  array<string, true>  $held  raw fields of the series exported as 0 ({@see AttemptStore::held()})
     * @param  array<string, list<string>>  $stale
     * @param  array<string, array{connection: string, queue: string, job_class: string}>  $tuples  filled: series id => labels
     */
    private function jobsTotal(array $fields, array $known, array $held, array &$stale, array &$tuples): MetricFamily
    {
        /** @var array<string, array<string, int>> $counts */
        $counts = [];

        foreach ($fields as $field => $value) {
            $parts = Keys::splitField($field);

            if (count($parts) !== 4 || ! isset($known[$parts[2]])) {
                $stale[$this->keys->attempts()][] = $field;

                continue;
            }

            $status = AttemptStatus::tryFrom($parts[3]);

            if (! $status instanceof AttemptStatus) {
                continue;
            }

            $labels = $this->jobLabels($parts[0], $parts[1], $parts[2]);
            $id = Keys::field(...array_values($labels));
            $tuples[$id] = $labels;
            $count = isset($held[Keys::field($parts[0], $parts[1], $parts[2])]) ? 0 : $this->integer($value);
            $counts[$id][$status->value] = ($counts[$id][$status->value] ?? 0) + $count;
        }

        ksort($tuples, SORT_STRING);
        $family = MetricFamily::of(Metric::JobsTotal);

        foreach ($tuples as $id => $labels) {
            foreach (AttemptStatus::cases() as $status) {
                $family->set([...$labels, 'status' => $status->value], $counts[$id][$status->value] ?? 0);
            }
        }

        return $family;
    }

    /**
     * @param  array<string, string>  $buckets  `conn\x1fqueue\x1fclass\x1fle` => non-cumulative count
     * @param  array<string, string>  $sums  `conn\x1fqueue\x1fclass` => seconds
     * @param  array<string, true>  $known
     * @param  array<string, true>  $held
     * @param  array<string, array{connection: string, queue: string, job_class: string}>  $tuples  the series of queue_jobs_total
     * @param  array<string, list<string>>  $stale
     */
    private function durations(array $buckets, array $sums, array $known, array $held, array $tuples, array &$stale): MetricFamily
    {
        /** @var array<string, array{labels: array<string, string>, buckets: array<string, int>, sum: float}> $series */
        $series = [];

        foreach ($tuples as $id => $labels) {
            $series[$id] = ['labels' => $labels, 'buckets' => [], 'sum' => 0.0];
        }

        foreach ($buckets as $field => $value) {
            $parts = Keys::splitField($field);

            if (count($parts) !== 4 || ! isset($known[$parts[2]])) {
                $stale[$this->keys->durationBuckets()][] = $field;

                continue;
            }

            $labels = $this->jobLabels($parts[0], $parts[1], $parts[2]);
            $id = Keys::field(...array_values($labels));
            $series[$id]['labels'] = $labels;
            $series[$id]['sum'] ??= 0.0;
            $series[$id]['buckets'] ??= [];

            if (! isset($held[Keys::field($parts[0], $parts[1], $parts[2])])) {
                $series[$id]['buckets'][$parts[3]] = ($series[$id]['buckets'][$parts[3]] ?? 0) + $this->integer($value);
            }
        }

        foreach ($sums as $field => $value) {
            $parts = Keys::splitField($field);

            if (count($parts) !== 3 || ! isset($known[$parts[2]])) {
                $stale[$this->keys->durationSum()][] = $field;

                continue;
            }

            $id = Keys::field(...array_values($this->jobLabels($parts[0], $parts[1], $parts[2])));

            if (isset($series[$id]) && ! isset($held[$field])) {
                $series[$id]['sum'] += $this->float($value);
            }
        }

        return $this->histogram(Metric::JobDuration, $series);
    }

    /**
     * @param  array<string, string>  $buckets  `conn\x1fqueue\x1fle` => non-cumulative count
     * @param  array<string, string>  $sums  `conn\x1fqueue` => seconds
     * @param  array<string, true>  $held
     * @param  array<string, array{connection: string, queue: string, job_class: string}>  $tuples  the series of queue_jobs_total
     */
    private function waits(array $buckets, array $sums, array $held, array $tuples): MetricFamily
    {
        /** @var array<string, array{labels: array<string, string>, buckets: array<string, int>, sum: float}> $series */
        $series = [];

        foreach ($tuples as ['connection' => $connection, 'queue' => $queue]) {
            $labels = ['connection' => $connection, 'queue' => $queue];
            $series[Keys::field($connection, $queue)] ??= ['labels' => $labels, 'buckets' => [], 'sum' => 0.0];
        }

        foreach ($buckets as $field => $value) {
            $parts = Keys::splitField($field);

            if (count($parts) !== 3) {
                continue;
            }

            $labels = $this->queueLabels($parts[0], $parts[1]);
            $id = Keys::field(...array_values($labels));
            $series[$id]['labels'] = $labels;
            $series[$id]['sum'] ??= 0.0;
            $series[$id]['buckets'] ??= [];

            if (! isset($held[Keys::field($parts[0], $parts[1])])) {
                $series[$id]['buckets'][$parts[2]] = ($series[$id]['buckets'][$parts[2]] ?? 0) + $this->integer($value);
            }
        }

        foreach ($sums as $field => $value) {
            $parts = Keys::splitField($field);

            if (count($parts) !== 2) {
                continue;
            }

            $id = Keys::field(...array_values($this->queueLabels($parts[0], $parts[1])));

            if (isset($series[$id]) && ! isset($held[$field])) {
                $series[$id]['sum'] += $this->float($value);
            }
        }

        return $this->histogram(Metric::JobWait, $series);
    }

    /**
     * @param  array<string, array{labels: array<string, string>, buckets: array<string, int>, sum: float}>  $series
     */
    private function histogram(Metric $metric, array $series): MetricFamily
    {
        ksort($series, SORT_STRING);
        $family = MetricFamily::of($metric);

        foreach ($series as ['labels' => $labels, 'buckets' => $buckets, 'sum' => $sum]) {
            $family->observe($labels, HistogramSnapshot::cumulative($buckets, $this->config->buckets), $sum);
        }

        return $family;
    }

    /**
     * @param  array<string, string>  $fields  `conn\x1fqueue\x1fclass` => seconds
     * @param  array<string, true>  $known
     */
    private function extremes(Metric $metric, array $fields, array $known): MetricFamily
    {
        /** @var array<string, array{labels: array<string, string>, value: float}> $series */
        $series = [];
        $min = $metric === Metric::JobDurationMin;

        foreach ($fields as $field => $value) {
            $parts = Keys::splitField($field);

            if (count($parts) !== 3 || ! isset($known[$parts[2]]) || ! is_numeric($value)) {
                continue;
            }

            $labels = $this->jobLabels($parts[0], $parts[1], $parts[2]);
            $id = Keys::field(...array_values($labels));
            $seconds = (float) $value;

            if (! isset($series[$id]) || ($min ? $seconds < $series[$id]['value'] : $seconds > $series[$id]['value'])) {
                $series[$id] = ['labels' => $labels, 'value' => $seconds];
            }
        }

        ksort($series, SORT_STRING);
        $family = MetricFamily::of($metric);

        foreach ($series as ['labels' => $labels, 'value' => $seconds]) {
            $family->set($labels, $seconds);
        }

        return $family;
    }

    /**
     * @param  array<string, string>  $meta
     * @return list<MetricFamily>
     */
    private function meta(array $meta): array
    {
        $families = [MetricFamily::of(Metric::AttemptsDropped)->set([], $this->integer($meta['dropped'] ?? '0'))];

        if (isset($meta['live_push_at']) && is_numeric($meta['live_push_at'])) {
            $families[] = MetricFamily::of(Metric::LivePushTimestamp)->set([], (float) $meta['live_push_at']);
            $families[] = MetricFamily::of(Metric::LivePushFailures)->set([], $this->integer($meta['live_push_failures'] ?? '0'));
        }

        return $families;
    }

    /**
     * At most {@see self::FORGET_LIMIT} fields in total.
     *
     * @param  array<string, list<string>>  $stale
     * @return array<string, list<string>>
     */
    private function limit(array $stale): array
    {
        $left = self::FORGET_LIMIT;
        $limited = [];

        foreach ($stale as $key => $fields) {
            if ($left <= 0) {
                break;
            }

            $limited[$key] = array_slice($fields, 0, $left);
            $left -= count($limited[$key]);
        }

        return $limited;
    }

    /**
     * @return array{connection: string, queue: string, job_class: string}
     */
    private function jobLabels(string $connection, string $queue, string $class): array
    {
        return ['connection' => $this->value($connection), 'queue' => $this->value($queue), 'job_class' => $this->value($class)];
    }

    /**
     * @return array{connection: string, queue: string}
     */
    private function queueLabels(string $connection, string $queue): array
    {
        return ['connection' => $this->value($connection), 'queue' => $this->value($queue)];
    }

    private function value(string $value): string
    {
        $value = Labels::sanitize($value);

        return $value === '' ? Labels::UNKNOWN : $value;
    }

    private function integer(string $value): int
    {
        return is_numeric($value) ? max(0, (int) $value) : 0;
    }

    private function float(string $value): float
    {
        return is_numeric($value) && is_finite((float) $value) ? (float) $value : 0.0;
    }
}
