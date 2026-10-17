<?php

declare(strict_types=1);

namespace Workbench\App\Simulation;

/**
 * What a simulated job does, chosen when it is dispatched. Each outcome produces the attempt statuses the dashboard
 * counts (`queue_jobs_total{status}`).
 */
enum Outcome: string
{
    /** Runs for its duration and succeeds: completed. */
    case Completed = 'completed';

    /** Releases itself back to the queue (5 s delay) on the first attempt, then succeeds: released, completed. */
    case Released = 'released';

    /** Throws on the first attempt and succeeds on the second: retried, completed. */
    case Retried = 'retried';

    /** Throws on every one of its 3 attempts: retried, retried, failed (Attempts 3 in Failed Jobs). */
    case Exhausted = 'exhausted';

    /** Fails itself through fail() without throwing: failed. */
    case Failed = 'failed';

    /** Sleeps past its timeout, so the worker is killed and restarted by Horizon: timed_out. */
    case TimedOut = 'timed_out';

    /**
     * Seconds a released job waits before its second attempt.
     */
    public const RELEASE_DELAY_SECONDS = 5;

    /**
     * Attempts the job is allowed (`$tries`).
     */
    public function tries(): int
    {
        return match ($this) {
            self::Released, self::Retried => 2,
            self::Exhausted => 3,
            default => 1,
        };
    }
}
