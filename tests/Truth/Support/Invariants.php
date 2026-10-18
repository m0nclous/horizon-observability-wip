<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\Truth\Support;

use DF\HorizonObservability\Metrics\Metric;

/**
 * Invariants every scrape must satisfy, whatever the scenario (owner decision 13): violations as readable lines.
 *
 * - histograms: buckets never decrease with `le`, `count` equals the `+Inf` bucket, the sum is finite and not
 *   negative, and 0 without observations;
 * - `queue_jobs_total`: every series has the five statuses with whole, non-negative counts; a series has at most as
 *   many duration observations as attempts; a held new series (all five statuses 0, `AttemptsCollector`'s hold) has
 *   no duration observations either; a queue has at most as many waits as attempts unless one of its series is held:
 *   the wait histogram of a known queue is not held, so it counts the first waits of a new class at once while the
 *   class's attempts read 0 for the hold (the documented lag between the families, tests/Truth/README.md);
 * - queues: ready jobs per class (with `(unscanned)`) add up to the pending jobs that are not due delayed ones, due
 *   jobs are part of the delayed and the pending ones, the oldest wait is not negative and 0 when nothing is pending
 *   (it can be 0 with pending jobs too: a job pushed at or after the scrape time by the exporter's clock, e.g. by a
 *   dispatcher whose clock is ahead);
 * - supervisors: every pool label of `horizon_supervisor_queues` is a process label of the same supervisor;
 * - batches: progress is a percentage;
 * - self: every collector with a success series has a duration series, and success is 0 or 1;
 * - across the scrapes of one scenario ({@see self::decreases()}): counters (the counter families and every bucket,
 *   count and sum of the histograms) never decrease, except at a documented reset (`meta.reset_at` changed). A series
 *   that disappears (a pruned class, a restart without the dataset, a failed collector) starts again from its next
 *   value.
 */
final class Invariants
{
    private const STATUSES = ['completed', 'released', 'retried', 'failed', 'timed_out'];

    /**
     * @return list<string>
     */
    public static function violations(Samples $samples): array
    {
        return [
            ...self::histograms($samples, Metric::JobDuration->value),
            ...self::histograms($samples, Metric::JobWait->value),
            ...self::attempts($samples),
            ...self::queues($samples),
            ...self::supervisors($samples),
            ...self::batches($samples),
            ...self::self($samples),
        ];
    }

    /**
     * The counter samples of a scrape: the counter families and every bucket, count and sum of the histograms,
     * `family{labels}` => value.
     *
     * @return array<string, float>
     */
    public static function counters(Samples $samples): array
    {
        $counters = [];
        $names = [Metric::JobsTotal->value, Metric::AttemptsDropped->value, Metric::LivePushFailures->value];

        foreach ([Metric::JobDuration->value, Metric::JobWait->value] as $histogram) {
            array_push($names, $histogram.'_bucket', $histogram.'_count', $histogram.'_sum');
        }

        foreach ($names as $name) {
            foreach ($samples->series($name) as $key => $value) {
                $counters[$name.'{'.$key.'}'] = $value;
            }
        }

        return $counters;
    }

    /**
     * Counter samples that decreased from one scrape to the next ({@see self::counters()}); a sample missing from the
     * later scrape is not a decrease.
     *
     * @param  array<string, float>  $before
     * @param  array<string, float>  $after
     * @return list<string>
     */
    public static function decreases(array $before, array $after): array
    {
        $violations = [];

        foreach ($after as $series => $value) {
            if (isset($before[$series]) && $value < $before[$series]) {
                $violations[] = sprintf('%s decreased from %s to %s without a documented reset', $series, $before[$series], $value);
            }
        }

        return $violations;
    }

    /**
     * @return list<string>
     */
    private static function histograms(Samples $samples, string $family): array
    {
        $violations = [];

        foreach ($samples->histograms($family) as $series => $histogram) {
            $previous = -1;

            foreach ($histogram['buckets'] as $le => $count) {
                if ($count < $previous) {
                    $violations[] = sprintf('%s{%s}: bucket le=%s (%d) below the previous one (%d)', $family, $series, $le, $count, $previous);
                }

                $previous = $count;
            }

            if (($histogram['buckets']['+Inf'] ?? null) !== $histogram['count']) {
                $violations[] = sprintf('%s{%s}: count %d is not the +Inf bucket', $family, $series, $histogram['count']);
            }

            if (! is_finite($histogram['sum']) || $histogram['sum'] < 0 || ($histogram['count'] === 0 && $histogram['sum'] !== 0.0)) {
                $violations[] = sprintf('%s{%s}: sum %s with %d observations', $family, $series, $histogram['sum'], $histogram['count']);
            }
        }

        return $violations;
    }

    /**
     * @return list<string>
     */
    private static function attempts(Samples $samples): array
    {
        $violations = [];
        $statuses = [];
        $perSeries = [];
        $perQueue = [];

        foreach ($samples->series(Metric::JobsTotal->value) as $key => $value) {
            $labels = Samples::labels($key);
            $status = $labels['status'];
            unset($labels['status']);
            $series = Model::key($labels);
            $statuses[$series][] = $status;
            $perSeries[$series] = ($perSeries[$series] ?? 0) + $value;
            $queue = Model::key(['connection' => $labels['connection'], 'queue' => $labels['queue']]);
            $perQueue[$queue] = ($perQueue[$queue] ?? 0) + $value;

            if ($value < 0 || floor($value) !== $value) {
                $violations[] = sprintf('queue_jobs_total{%s}: %s is not a whole count', $key, $value);
            }
        }

        foreach ($statuses as $series => $found) {
            sort($found);
            $all = self::STATUSES;
            sort($all);

            if ($found !== $all) {
                $violations[] = sprintf('queue_jobs_total{%s}: statuses %s', $series, implode(', ', $found));
            }
        }

        // A held series: all five statuses 0 (an exported series has at least one count otherwise).
        $held = [];

        foreach ($perSeries as $series => $total) {
            if ($total === 0.0) {
                $labels = Samples::labels($series);
                $held[Model::key(['connection' => $labels['connection'], 'queue' => $labels['queue']])][] = $series;
            }
        }

        foreach ($samples->histograms(Metric::JobDuration->value) as $series => $histogram) {
            if ($histogram['count'] > ($perSeries[$series] ?? 0)) {
                $violations[] = sprintf('queue_job_duration_seconds{%s}: %d observations for %d attempts', $series, $histogram['count'], $perSeries[$series] ?? 0);
            }
        }

        foreach ($samples->histograms(Metric::JobWait->value) as $queue => $histogram) {
            if ($histogram['count'] > ($perQueue[$queue] ?? 0) && ! isset($held[$queue])) {
                $violations[] = sprintf('queue_job_wait_seconds{%s}: %d waits for %d attempts, and no series of the queue is held', $queue, $histogram['count'], $perQueue[$queue] ?? 0);
            }
        }

        return $violations;
    }

    /**
     * @return list<string>
     */
    private static function queues(Samples $samples): array
    {
        $violations = [];
        $byClass = [];

        foreach ($samples->series(Metric::QueueJobsPending->value) as $key => $value) {
            $labels = Samples::labels($key);
            $queue = Model::key(['connection' => $labels['connection'], 'queue' => $labels['queue']]);
            $byClass[$queue] = ($byClass[$queue] ?? 0) + $value;
        }

        $delayed = $samples->series(Metric::QueueDelayedJobs->value);
        $due = $samples->series(Metric::QueueDelayedDueJobs->value);
        $oldest = $samples->series(Metric::QueueOldestPendingJobAge->value);

        foreach ($samples->series(Metric::QueuePendingJobs->value) as $queue => $pending) {
            $dueJobs = $due[$queue] ?? 0.0;

            if (($byClass[$queue] ?? 0.0) !== $pending - $dueJobs) {
                $violations[] = sprintf('queue_jobs_pending{%s}: %s by class for %s pending and %s due', $queue, $byClass[$queue] ?? 0, $pending, $dueJobs);
            }

            if ($dueJobs > ($delayed[$queue] ?? 0.0) || $dueJobs > $pending) {
                $violations[] = sprintf('queue_delayed_due_jobs{%s}: %s due, %s delayed, %s pending', $queue, $dueJobs, $delayed[$queue] ?? 0, $pending);
            }

            if (isset($oldest[$queue]) && ($oldest[$queue] < 0 || ($pending === 0.0 && $oldest[$queue] !== 0.0))) {
                $violations[] = sprintf('queue_oldest_pending_job_age_seconds{%s}: %s with %s pending', $queue, $oldest[$queue], $pending);
            }
        }

        return $violations;
    }

    /**
     * @return list<string>
     */
    private static function supervisors(Samples $samples): array
    {
        $violations = [];
        $pools = [];

        foreach ($samples->labelSets(Metric::SupervisorProcesses->value) as $labels) {
            $pools[$labels['supervisor']][$labels['queue']] = true;
        }

        foreach ($samples->labelSets(Metric::SupervisorQueues->value) as $labels) {
            if (isset($pools[$labels['supervisor']]) && ! isset($pools[$labels['supervisor']][$labels['pool']])) {
                $violations[] = sprintf('horizon_supervisor_queues: pool "%s" of %s has no processes series', $labels['pool'], $labels['supervisor']);
            }
        }

        return $violations;
    }

    /**
     * @return list<string>
     */
    private static function batches(Samples $samples): array
    {
        $violations = [];

        foreach ($samples->series(Metric::BatchProgress->value) as $key => $value) {
            if ($value < 0 || $value > 100) {
                $violations[] = sprintf('queue_batch_progress{%s}: %s', $key, $value);
            }
        }

        return $violations;
    }

    /**
     * @return list<string>
     */
    private static function self(Samples $samples): array
    {
        $violations = [];
        $durations = $samples->series(Metric::CollectorDuration->value);

        foreach ($samples->series(Metric::CollectorSuccess->value) as $key => $value) {
            if ($value !== 0.0 && $value !== 1.0) {
                $violations[] = sprintf('exporter_collector_success{%s}: %s', $key, $value);
            }

            if (! isset($durations[$key])) {
                $violations[] = sprintf('exporter_collector_duration_seconds{%s}: missing', $key);
            }
        }

        return $violations;
    }
}
