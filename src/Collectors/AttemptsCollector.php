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
use Throwable;

/**
 * Collector `attempts` (Arch Q1.4, Q4, Q6.10, Q6.11; Addendum R3.4, R4.11): `queue_jobs_total`, the duration and wait
 * histograms, the duration extremes of the last 150 seconds, `exporter_attempts_dropped_total` and the live push
 * self-metrics, read from the storage written by the workers.
 *
 * Per scrape: the reset guard `EVAL`, the five counter hashes, one `extremes_read` `EVAL`, `meta` and the job-class
 * set (idle classes pruned first), on the short-timeout reader of the storage connection. Values are absolute
 * (no registry, C3).
 * - Every (connection, queue, job_class) of `queue_jobs_total` is zero-filled with all five statuses, so the first
 *   failure or timeout of a known class is visible to `increase()`.
 * - Fields of classes that left the class set (cap overflow, 30 idle days) are not exported and are deleted
 *   lazily, at most {@see self::FORGET_LIMIT} per scrape; `(other)` always stays.
 * - Histograms are cumulative over the configured bounds ({@see HistogramSnapshot}).
 * - `exporter_attempts_dropped_total` is always exported; the live push series only once a stream leader pushed
 *   (`meta.live_push_at`).
 * - A failing reset guard or deletion keeps the data but marks the collector as failed.
 *
 * @internal
 */
final readonly class AttemptsCollector implements Collector
{
    /**
     * Stale hash fields deleted per scrape at most.
     */
    public const FORGET_LIMIT = 1000;

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

        // The class set is read last: a class admitted after the hashes were read has no field in them yet, so a
        // brand-new class is never taken for a stale one.
        $counters = $this->store->counters();
        $extremes = $this->store->extremes($now);
        $meta = $this->store->meta();
        $known = array_fill_keys($this->store->jobClasses($now), true);
        $known[Labels::OTHER] = true;

        /** @var array<string, list<string>> $stale */
        $stale = [];

        $families = [
            $this->jobsTotal($counters['attempts'], $known, $stale),
            $this->durations($counters['durationBuckets'], $counters['durationSum'], $known, $stale),
            $this->waits($counters['waitBuckets'], $counters['waitSum']),
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
     * @param  array<string, list<string>>  $stale
     */
    private function jobsTotal(array $fields, array $known, array &$stale): MetricFamily
    {
        /** @var array<string, array{labels: array{connection: string, queue: string, job_class: string}, counts: array<string, int>}> $series */
        $series = [];

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
            $series[$id]['labels'] = $labels;
            $series[$id]['counts'][$status->value] = ($series[$id]['counts'][$status->value] ?? 0) + $this->integer($value);
        }

        ksort($series, SORT_STRING);
        $family = MetricFamily::of(Metric::JobsTotal);

        foreach ($series as ['labels' => $labels, 'counts' => $counts]) {
            foreach (AttemptStatus::cases() as $status) {
                $family->set([...$labels, 'status' => $status->value], $counts[$status->value] ?? 0);
            }
        }

        return $family;
    }

    /**
     * @param  array<string, string>  $buckets  `conn\x1fqueue\x1fclass\x1fle` => non-cumulative count
     * @param  array<string, string>  $sums  `conn\x1fqueue\x1fclass` => seconds
     * @param  array<string, true>  $known
     * @param  array<string, list<string>>  $stale
     */
    private function durations(array $buckets, array $sums, array $known, array &$stale): MetricFamily
    {
        /** @var array<string, array{labels: array<string, string>, buckets: array<string, int>, sum: float}> $series */
        $series = [];

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
            $series[$id]['buckets'][$parts[3]] = ($series[$id]['buckets'][$parts[3]] ?? 0) + $this->integer($value);
        }

        foreach ($sums as $field => $value) {
            $parts = Keys::splitField($field);

            if (count($parts) !== 3 || ! isset($known[$parts[2]])) {
                $stale[$this->keys->durationSum()][] = $field;

                continue;
            }

            $id = Keys::field(...array_values($this->jobLabels($parts[0], $parts[1], $parts[2])));

            if (isset($series[$id])) {
                $series[$id]['sum'] += $this->float($value);
            }
        }

        return $this->histogram(Metric::JobDuration, $series);
    }

    /**
     * @param  array<string, string>  $buckets  `conn\x1fqueue\x1fle` => non-cumulative count
     * @param  array<string, string>  $sums  `conn\x1fqueue` => seconds
     */
    private function waits(array $buckets, array $sums): MetricFamily
    {
        /** @var array<string, array{labels: array<string, string>, buckets: array<string, int>, sum: float}> $series */
        $series = [];

        foreach ($buckets as $field => $value) {
            $parts = Keys::splitField($field);

            if (count($parts) !== 3) {
                continue;
            }

            $labels = $this->queueLabels($parts[0], $parts[1]);
            $id = Keys::field(...array_values($labels));
            $series[$id]['labels'] = $labels;
            $series[$id]['sum'] ??= 0.0;
            $series[$id]['buckets'][$parts[2]] = ($series[$id]['buckets'][$parts[2]] ?? 0) + $this->integer($value);
        }

        foreach ($sums as $field => $value) {
            $parts = Keys::splitField($field);

            if (count($parts) !== 2) {
                continue;
            }

            $id = Keys::field(...array_values($this->queueLabels($parts[0], $parts[1])));

            if (isset($series[$id])) {
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
