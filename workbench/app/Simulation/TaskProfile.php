<?php

declare(strict_types=1);

namespace Workbench\App\Simulation;

/**
 * One simulated task: a job name (its `displayName`, so every task is a row of its own on the dashboard) on a queue of
 * the workbench Horizon topology, with its volume, duration and outcome mix.
 *
 * Percentages are of the task's jobs. In the one-shot run `$timeouts` is a count of timed-out jobs; in the continuous
 * run a task with timeouts times out {@see self::CONTINUOUS_TIMEOUT_PERCENT} of its jobs.
 */
final readonly class TaskProfile
{
    /**
     * Timed-out share (percent) of a task with timeouts in the continuous run; each timeout kills a worker.
     */
    public const CONTINUOUS_TIMEOUT_PERCENT = 1.0;

    /**
     * Every failure writes a failed_jobs row, so the continuous run fails three times less often than the one-shot run.
     */
    public const CONTINUOUS_FAIL_FACTOR = 0.3;

    public function __construct(
        public string $name,
        public string $queue,
        public int $count,
        public float $perMinute,
        public float $meanSeconds,
        public float $failPercent = 0.0,
        public float $retryPercent = 0.0,
        public int $timeouts = 0,
        public float $exhaustPercent = 0.0,
        public float $releasePercent = 0.0,
    ) {}

    /**
     * The tasks of every simulated queue: default (supervisor-default), slow and reports (supervisor-slow), mail and
     * sms (supervisor-pool, balance=false).
     *
     * @return list<self>
     */
    public static function catalog(): array
    {
        return [
            new self('TestPingJob', 'default', 200, 120, 0.05),
            new self('TestSendSmsJob', 'default', 120, 40, 0.2, failPercent: 2, retryPercent: 5),
            new self('TestPushNotificationJob', 'default', 100, 30, 0.3, retryPercent: 10, releasePercent: 5),
            new self('TestOrderSyncJob', 'default', 80, 20, 0.8, failPercent: 5, retryPercent: 5, exhaustPercent: 3),
            new self('TestPaymentCheckJob', 'default', 60, 15, 0.5, failPercent: 10),
            new self('TestWebhookJob', 'default', 60, 25, 0.4, retryPercent: 30, exhaustPercent: 5),
            new self('TestGeocodeJob', 'default', 50, 10, 1.0, failPercent: 20),
            new self('TestCacheWarmupJob', 'default', 40, 4, 1.5),
            new self('TestImageResizeJob', 'default', 20, 2, 3.0, timeouts: 1),
            new self('TestBrokenJob', 'default', 10, 0.5, 0.2, failPercent: 100),
            new self('TestCleanupJob', 'slow', 30, 4, 1.0, releasePercent: 10),
            new self('TestCompressJob', 'slow', 25, 3, 2.0, timeouts: 1),
            new self('TestImportJob', 'slow', 20, 1.5, 4.0),
            new self('TestExportJob', 'slow', 15, 1, 6.0, failPercent: 5),
            new self('TestRecalculateJob', 'slow', 10, 0.5, 8.0, retryPercent: 10),
            new self('TestSalesReportJob', 'reports', 8, 0.5, 5.0),
            new self('TestDailyReportJob', 'reports', 6, 0.3, 10.0),
            new self('TestFinanceReportJob', 'reports', 4, 0.2, 15.0, failPercent: 25),
            new self('TestWelcomeMailJob', 'mail', 60, 20, 0.3, failPercent: 1, retryPercent: 2),
            new self('TestInvoiceMailJob', 'mail', 30, 8, 0.6, failPercent: 2, exhaustPercent: 1),
            new self('TestNewsletterMailJob', 'mail', 40, 10, 0.2, retryPercent: 5),
            new self('TestOtpSmsJob', 'sms', 80, 30, 0.1, failPercent: 1, releasePercent: 5),
            new self('TestReminderSmsJob', 'sms', 30, 6, 0.15, failPercent: 3, retryPercent: 5),
        ];
    }

    /**
     * The simulated queues, in catalog order.
     *
     * @return list<string>
     */
    public static function queues(): array
    {
        return array_values(array_unique(array_map(static fn (self $task): string => $task->queue, self::catalog())));
    }

    /**
     * @return list<self>
     */
    public static function forQueue(string $queue): array
    {
        return array_values(array_filter(self::catalog(), static fn (self $task): bool => $task->queue === $queue));
    }

    /**
     * Timeout of every job of the task, like a real job class: twice the mean duration, at least 5 s (a failure
     * writes a failed_jobs row, which a short timeout would not cover).
     */
    public function timeoutSeconds(): int
    {
        return max(5, (int) ceil($this->meanSeconds * 2));
    }

    /**
     * One-shot counts per outcome at a scale; timeouts first, then failures, exhaustions, retries and releases, the
     * rest completes.
     *
     * @return array<string, int> outcome value => jobs, in {@see Outcome} order without zero entries
     */
    public function oneShotCounts(float $scale): array
    {
        $count = (int) round($this->count * $scale);
        $timedOut = min($count, $this->timeouts);
        $failed = min($count - $timedOut, (int) round($count * $this->failPercent / 100));
        $exhausted = min($count - $timedOut - $failed, (int) round($count * $this->exhaustPercent / 100));
        $retried = min($count - $timedOut - $failed - $exhausted, (int) round($count * $this->retryPercent / 100));
        $released = min($count - $timedOut - $failed - $exhausted - $retried, (int) round($count * $this->releasePercent / 100));
        $counts = [
            Outcome::Completed->value => $count - $timedOut - $failed - $exhausted - $retried - $released,
            Outcome::Released->value => $released,
            Outcome::Retried->value => $retried,
            Outcome::Exhausted->value => $exhausted,
            Outcome::Failed->value => $failed,
            Outcome::TimedOut->value => $timedOut,
        ];

        return array_filter($counts, static fn (int $jobs): bool => $jobs > 0);
    }

    /**
     * The continuous-run outcome for a roll in [0, 100).
     */
    public function continuousOutcome(float $roll): Outcome
    {
        $timedOut = $this->timeouts > 0 ? self::CONTINUOUS_TIMEOUT_PERCENT : 0.0;
        $failed = $timedOut + $this->failPercent * self::CONTINUOUS_FAIL_FACTOR;
        $exhausted = $failed + $this->exhaustPercent * self::CONTINUOUS_FAIL_FACTOR;
        $retried = $exhausted + $this->retryPercent;
        $released = $retried + $this->releasePercent;

        return match (true) {
            $roll < $timedOut => Outcome::TimedOut,
            $roll < $failed => Outcome::Failed,
            $roll < $exhausted => Outcome::Exhausted,
            $roll < $retried => Outcome::Retried,
            $roll < $released => Outcome::Released,
            default => Outcome::Completed,
        };
    }
}
