<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Tests\Truth\Support;

use DF\HorizonObservability\Tests\Support\Exposition;
use LogicException;

/**
 * The expected-value model of a truth scenario: an independent re-statement of what the exporter must report,
 * written from the documented semantics (Arch Q4, Q6.2, Q6.3 C8, Appendix B), not from the package code.
 *
 * It follows every job of the scenario through Laravel's Redis queue (a ready list, a delayed set, reserved jobs)
 * and logs every attempt with its outcome, its planned duration, its start on the scenario clock and the wait of a
 * first attempt (start minus readiness, `max(pushedAt, dispatch + delay)`). From that it computes, for a scrape at a
 * given time:
 * - `queue_jobs_total` per (connection, queue, job_class, status), all five statuses of a known series;
 * - the duration histogram per series (exact buckets; the sum as an interval, see {@see self::OVERHEAD_SECONDS}) and
 *   the wait histogram per queue (exact, the scenario clock is frozen);
 * - the duration extremes of the attempts in the 150 s window;
 * - the queue state: pending (incl. due delayed), delayed, due, the oldest wait (C8), ready and reserved jobs per class.
 *
 * New series are exported as 0 for their first 75 s (`AttemptsCollector::NEW_SERIES_HOLD_SECONDS`, T16): the model
 * applies the same rule from the time each series got its first count.
 */
final class Model
{
    /**
     * Upper bound of what a measured attempt duration adds to the planned sleep (the worker, Horizon's listeners and
     * the job itself). Planned durations must keep this margin from every bucket bound, so the buckets are exact.
     */
    public const OVERHEAD_SECONDS = 0.1;

    /**
     * `AttemptsCollector::NEW_SERIES_HOLD_SECONDS`, restated.
     */
    public const HOLD_SECONDS = 75;

    /**
     * The extremes cover the current 15-second bucket and the 9 before it (Addendum R3.4).
     */
    private const EXTREMES_BUCKETS = 10;

    private const STATUSES = ['completed', 'released', 'retried', 'failed', 'timed_out'];

    /**
     * @var array<string, array{connection: string, queue: string, class: string, plan: list<string>, tries: int, backoff: int, sleep: float, attempts: int, readiness: float, done: bool}>
     */
    private array $jobs = [];

    /**
     * @var array<string, list<string>> `conn|queue` => ready job ids, head first
     */
    private array $ready = [];

    /**
     * @var array<string, array<string, float>> `conn|queue` => job id => available at
     */
    private array $delayed = [];

    /**
     * @var array<string, string> job id => `conn|queue`
     */
    private array $reserved = [];

    /**
     * @var list<array{connection: string, queue: string, class: string, status: string, duration: float|null, exact: bool, at: float, wait: float|null}>
     */
    private array $attempts = [];

    /**
     * @param  list<float>  $bounds  the configured histogram buckets
     */
    public function __construct(private readonly array $bounds) {}

    /**
     * A job was pushed (Horizon's `JobPushed`, with the payload as stored).
     *
     * @param  array<array-key, mixed>  $payload
     */
    public function pushed(string $id, array $payload, string $connection, string $queue, TruthJob $job, int $delay, float $dispatchedAt): void
    {
        $pushedAt = is_numeric($payload['pushedAt'] ?? null) ? (float) $payload['pushedAt'] : $dispatchedAt;
        $key = $connection.'|'.$queue;

        $this->jobs[$id] = [
            'connection' => $connection,
            'queue' => $queue,
            'class' => $job->label,
            'plan' => $job->plan,
            'tries' => $job->tries,
            'backoff' => $job->backoff,
            'sleep' => $job->sleepMs / 1000,
            'attempts' => 0,
            'readiness' => $delay > 0 ? max($pushedAt, $dispatchedAt + $delay) : $pushedAt,
            'done' => false,
        ];

        if ($delay > 0) {
            $this->delayed[$key][$id] = $dispatchedAt + $delay;
        } else {
            $this->ready[$key][] = $id;
        }
    }

    /**
     * A worker ran the queue at time `$at` until nothing was due (`queue:work --stop-when-empty`).
     */
    public function work(string $connection, string $queue, float $at): void
    {
        while (($id = $this->pop($connection, $queue, $at)) !== null) {
            $this->attempt($id, $at);
        }
    }

    /**
     * A forked worker took the head job at `$at` and holds it (reserved) until {@see self::finish()}.
     */
    public function reserve(string $connection, string $queue, float $at): string
    {
        $id = $this->pop($connection, $queue, $at) ?? throw new LogicException('Nothing to reserve on '.$queue.'.');
        $this->reserved[$id] = $connection.'|'.$queue;

        return $id;
    }

    /**
     * The reserved job finished its attempt as planned (started at `$at`).
     */
    public function finish(string $id, float $at): void
    {
        unset($this->reserved[$id]);
        $this->attempt($id, $at);
    }

    /**
     * The reserved job timed out after `$timeout` seconds: recorded as `timed_out` with the duration capped at the
     * timeout, and failed (its last try).
     */
    public function timedOut(string $id, float $at, float $timeout): void
    {
        unset($this->reserved[$id]);
        $job = &$this->jobs[$id];
        $job['attempts']++;
        $job['done'] = true;
        $this->log($job, 'timed_out', $timeout, true, $at, $job['attempts'] === 1 ? max(0.0, $at - $job['readiness']) : null);
    }

    /**
     * Every attempt logged so far.
     *
     * @return list<array{connection: string, queue: string, class: string, status: string, duration: float|null, exact: bool, at: float, wait: float|null}>
     */
    public function attempts(): array
    {
        return $this->attempts;
    }

    /**
     * `queue_jobs_total` for a scrape at `$at`: labels key => count.
     *
     * @return array<string, int>
     */
    public function jobsTotal(float $at): array
    {
        $samples = [];

        foreach ($this->series() as $series => [$connection, $queue, $class]) {
            $held = $this->held($series, $at);

            foreach (self::STATUSES as $status) {
                $count = 0;

                foreach ($this->attempts as $attempt) {
                    if ($series === $attempt['connection'].'|'.$attempt['queue'].'|'.$attempt['class'] && $attempt['status'] === $status) {
                        $count++;
                    }
                }

                $samples[self::key(['connection' => $connection, 'queue' => $queue, 'job_class' => $class, 'status' => $status])] = $held ? 0 : $count;
            }
        }

        ksort($samples);

        return $samples;
    }

    /**
     * The duration histogram per series for a scrape at `$at`: labels key => cumulative buckets, count, and the sum
     * as `[low, high]`.
     *
     * @return array<string, array{buckets: array<string, int>, count: int, sum: array{0: float, 1: float}}>
     */
    public function durations(float $at): array
    {
        $histograms = [];

        foreach ($this->series() as $series => [$connection, $queue, $class]) {
            $values = [];
            $low = 0.0;
            $high = 0.0;

            if (! $this->held($series, $at)) {
                foreach ($this->attempts as $attempt) {
                    if ($series !== $attempt['connection'].'|'.$attempt['queue'].'|'.$attempt['class'] || $attempt['duration'] === null) {
                        continue;
                    }

                    $values[] = $attempt['duration'];
                    $low += $attempt['duration'];
                    $high += $attempt['duration'] + ($attempt['exact'] ? 0.0 : self::OVERHEAD_SECONDS);
                }
            }

            $histograms[self::key(['connection' => $connection, 'queue' => $queue, 'job_class' => $class])] = [
                'buckets' => $this->cumulative($values),
                'count' => count($values),
                'sum' => [$low, $high],
            ];
        }

        ksort($histograms);

        return $histograms;
    }

    /**
     * The wait histogram per queue for a scrape at `$at` (every queue of a known series; exact).
     *
     * @return array<string, array{buckets: array<string, int>, count: int, sum: float}>
     */
    public function waits(float $at): array
    {
        $histograms = [];

        foreach ($this->series() as [$connection, $queue]) {
            $values = [];
            $first = null;

            foreach ($this->attempts as $attempt) {
                if ($attempt['connection'] === $connection && $attempt['queue'] === $queue && $attempt['wait'] !== null) {
                    $values[] = $attempt['wait'];
                    $first = min($first ?? INF, $attempt['at']);
                }
            }

            if ($first !== null && $at - $first < self::HOLD_SECONDS) {
                $values = [];
            }

            $histograms[self::key(['connection' => $connection, 'queue' => $queue])] = [
                'buckets' => $this->cumulative($values),
                'count' => count($values),
                'sum' => array_sum($values),
            ];
        }

        ksort($histograms);

        return $histograms;
    }

    /**
     * The duration extremes of a scrape at `$at`: labels key => `[min low, min high, max low, max high]`, for the
     * series with an attempt in the 150-second window.
     *
     * @return array<string, array{0: float, 1: float, 2: float, 3: float}>
     */
    public function extremes(float $at): array
    {
        $oldest = intdiv((int) floor($at), 15) - self::EXTREMES_BUCKETS + 1;
        $extremes = [];

        foreach ($this->attempts as $attempt) {
            if ($attempt['duration'] === null || intdiv((int) floor($attempt['at']), 15) < $oldest) {
                continue;
            }

            $key = self::key(['connection' => $attempt['connection'], 'queue' => $attempt['queue'], 'job_class' => $attempt['class']]);
            $slack = $attempt['exact'] ? 0.0 : self::OVERHEAD_SECONDS;
            $current = $extremes[$key] ?? [INF, INF, -INF, -INF];
            $extremes[$key] = [
                min($current[0], $attempt['duration']),
                min($current[1], $attempt['duration'] + $slack),
                max($current[2], $attempt['duration']),
                max($current[3], $attempt['duration'] + $slack),
            ];
        }

        ksort($extremes);

        return $extremes;
    }

    /**
     * Ready jobs plus due delayed ones (`queue_pending_jobs`).
     */
    public function pending(string $connection, string $queue, float $at): int
    {
        return count($this->ready[$connection.'|'.$queue] ?? []) + $this->due($connection, $queue, $at);
    }

    /**
     * Delayed jobs, due ones included (`queue_delayed_jobs`).
     */
    public function delayed(string $connection, string $queue): int
    {
        return count($this->delayed[$connection.'|'.$queue] ?? []);
    }

    /**
     * Delayed jobs whose time has come (`queue_delayed_due_jobs`).
     */
    public function due(string $connection, string $queue, float $at): int
    {
        return count(array_filter($this->delayed[$connection.'|'.$queue] ?? [], static fn (float $score): bool => $score <= $at));
    }

    /**
     * C8: seconds since the readiness of the first first-attempt job in the ready list or since the earliest due
     * delayed job, whichever is older; 0 when nothing is pending; null when neither exists.
     */
    public function oldest(string $connection, string $queue, float $at, int $scanLimit = 1000): ?float
    {
        if ($this->pending($connection, $queue, $at) === 0) {
            return 0.0;
        }

        $times = [];

        foreach (array_slice($this->ready[$connection.'|'.$queue] ?? [], 0, max(1, $scanLimit)) as $id) {
            if ($this->jobs[$id]['attempts'] === 0) {
                $times[] = $this->jobs[$id]['readiness'];

                break;
            }
        }

        $due = array_filter($this->delayed[$connection.'|'.$queue] ?? [], static fn (float $score): bool => $score <= $at);

        if ($due !== []) {
            $times[] = min($due);
        }

        return $times === [] ? null : max(0.0, $at - min($times));
    }

    /**
     * Ready jobs per class in the scanned head of the list, the rest as `(unscanned)` (`queue_jobs_pending`; due
     * delayed jobs are not in the list yet).
     *
     * @return array<string, int>
     */
    public function pendingByClass(string $connection, string $queue, int $scanLimit = 1000): array
    {
        $classes = [];
        $ready = $this->ready[$connection.'|'.$queue] ?? [];

        foreach (array_slice($ready, 0, $scanLimit) as $id) {
            $classes[$this->jobs[$id]['class']] = ($classes[$this->jobs[$id]['class']] ?? 0) + 1;
        }

        if (count($ready) > $scanLimit) {
            $classes['(unscanned)'] = count($ready) - $scanLimit;
        }

        ksort($classes);

        return $classes;
    }

    /**
     * Reserved jobs per class (`queue_jobs_reserved`).
     *
     * @return array<string, int>
     */
    public function reservedByClass(string $connection, string $queue): array
    {
        $classes = [];

        foreach ($this->reserved as $id => $key) {
            if ($key === $connection.'|'.$queue) {
                $classes[$this->jobs[$id]['class']] = ($classes[$this->jobs[$id]['class']] ?? 0) + 1;
            }
        }

        ksort($classes);

        return $classes;
    }

    /**
     * The sorted `name="value"` key of a label set, as {@see Exposition::key()}.
     *
     * @param  array<string, string>  $labels
     */
    public static function key(array $labels): string
    {
        ksort($labels);

        return implode(',', array_map(
            static fn (string $name, string $value): string => $name.'="'.addcslashes($value, "\\\"\n").'"',
            array_keys($labels),
            $labels,
        ));
    }

    private function pop(string $connection, string $queue, float $at): ?string
    {
        $key = $connection.'|'.$queue;
        $due = array_filter($this->delayed[$key] ?? [], static fn (float $score): bool => $score <= $at);
        asort($due);

        foreach (array_keys($due) as $id) {
            unset($this->delayed[$key][$id]);
            $this->ready[$key][] = (string) $id;
        }

        $id = array_shift($this->ready[$key]);

        return is_string($id) ? $id : null;
    }

    private function attempt(string $id, float $at): void
    {
        $job = &$this->jobs[$id];
        $job['attempts']++;
        $number = $job['attempts'];
        $key = $job['connection'].'|'.$job['queue'];
        $wait = $number === 1 ? max(0.0, $at - $job['readiness']) : null;

        if ($job['tries'] > 0 && $number > $job['tries']) {
            // Laravel fails a job popped after its last try before running it (MaxAttemptsExceededException).
            $job['done'] = true;
            $this->log($job, 'failed', null, false, $at, $wait);

            return;
        }

        $step = $job['plan'][$number - 1] ?? 'complete';

        if ($step === 'hang') {
            throw new LogicException('A hanging attempt needs a forked worker with a timeout.');
        }

        $this->checkMargin($job['sleep']);

        if ($step === 'throw' && $number < $job['tries']) {
            $this->delayed[$key][$id] = $at + $job['backoff'];
            $this->log($job, 'retried', $job['sleep'], false, $at, $wait);

            return;
        }

        if (str_starts_with($step, 'release:')) {
            $this->delayed[$key][$id] = $at + (int) substr($step, strlen('release:'));
            $this->log($job, 'released', $job['sleep'], false, $at, $wait);

            return;
        }

        $job['done'] = true;
        $this->log($job, $step === 'complete' ? 'completed' : 'failed', $job['sleep'], false, $at, $wait);
    }

    /**
     * @param  array{connection: string, queue: string, class: string}  $job
     */
    private function log(array $job, string $status, ?float $duration, bool $exact, float $at, ?float $wait): void
    {
        $this->attempts[] = [
            'connection' => $job['connection'],
            'queue' => $job['queue'],
            'class' => $job['class'],
            'status' => $status,
            'duration' => $duration,
            'exact' => $exact,
            'at' => $at,
            'wait' => $wait,
        ];
    }

    /**
     * A planned duration and its overhead must fall into one bucket.
     */
    private function checkMargin(float $duration): void
    {
        foreach ($this->bounds as $bound) {
            if ($duration <= $bound && $duration + self::OVERHEAD_SECONDS > $bound) {
                throw new LogicException(sprintf('A planned duration of %s s is within %s s of the bucket bound %s.', $duration, self::OVERHEAD_SECONDS, $bound));
            }
        }
    }

    /**
     * The series with attempts: `conn|queue|class` => [connection, queue, class], in first-attempt order.
     *
     * @return array<string, array{0: string, 1: string, 2: string}>
     */
    private function series(): array
    {
        $series = [];

        foreach ($this->attempts as $attempt) {
            $series[$attempt['connection'].'|'.$attempt['queue'].'|'.$attempt['class']] ??= [$attempt['connection'], $attempt['queue'], $attempt['class']];
        }

        return $series;
    }

    /**
     * Whether a series got its first count less than the hold before `$at`.
     */
    private function held(string $series, float $at): bool
    {
        $first = null;

        foreach ($this->attempts as $attempt) {
            if ($series === $attempt['connection'].'|'.$attempt['queue'].'|'.$attempt['class']) {
                $first = min($first ?? INF, $attempt['at']);
            }
        }

        return $first !== null && $at - $first < self::HOLD_SECONDS;
    }

    /**
     * Cumulative buckets of the configured bounds, `le` spelled as in the exposition, `+Inf` last.
     *
     * @param  list<float>  $values
     * @return array<string, int>
     */
    private function cumulative(array $values): array
    {
        $buckets = [];

        foreach ($this->bounds as $bound) {
            $buckets[self::le($bound)] = count(array_filter($values, static fn (float $value): bool => $value <= $bound));
        }

        $buckets['+Inf'] = count($values);

        return $buckets;
    }

    private static function le(float $bound): string
    {
        return floor($bound) === $bound ? (string) (int) $bound : (string) $bound;
    }
}
