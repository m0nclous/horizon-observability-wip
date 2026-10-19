<?php

declare(strict_types=1);

namespace Workbench\App\Simulation;

use Illuminate\Bus\Batchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

/**
 * The job of the load simulation (workbench only, never shipped). One class carries every simulated task: the
 * package and Horizon take the job name from `displayName()`, so each task is a row of its own.
 *
 * A job of a cancelled batch returns at once, as real batch jobs do.
 */
final class SimulatedJob implements ShouldQueue
{
    use Batchable;
    use Queueable;

    public int $tries;

    /**
     * @param  float  $durationSeconds  mean duration; each attempt runs between half and one and a half of it
     */
    public function __construct(
        public string $name,
        public Outcome $outcome,
        public float $durationSeconds,
        public int $timeout,
    ) {
        $this->tries = $outcome->tries();
    }

    public static function of(TaskProfile $task, Outcome $outcome): self
    {
        return new self($task->name, $outcome, $task->meanSeconds, $task->timeoutSeconds());
    }

    /**
     * A job that completes at once: the first attempt of a task, so its counters exist before the load starts.
     */
    public static function warmup(TaskProfile $task): self
    {
        return new self($task->name, Outcome::Completed, 0.0, $task->timeoutSeconds());
    }

    public function displayName(): string
    {
        return $this->name;
    }

    public function handle(): void
    {
        if ($this->batch()?->cancelled() === true) {
            return;
        }

        $this->sleepFor($this->plannedSeconds(random_int(500, 1500) / 1000));

        $attempt = $this->attempts();

        match ($this->outcome) {
            Outcome::Failed => $this->fail(SimulatedFailure::for($this->name, $this->outcome)),
            Outcome::Released => $attempt === 1 ? $this->release(Outcome::RELEASE_DELAY_SECONDS) : null,
            Outcome::Retried => $attempt === 1 ? throw SimulatedFailure::for($this->name, $this->outcome) : null,
            Outcome::Exhausted => throw SimulatedFailure::for($this->name, $this->outcome),
            Outcome::Completed, Outcome::TimedOut => null,
        };
    }

    /**
     * Seconds the attempt runs: a timed-out job sleeps 2 s past its timeout, every other job `$spread` times the mean
     * duration, so the average and the p95 differ on the dashboard.
     */
    public function plannedSeconds(float $spread): float
    {
        return $this->outcome === Outcome::TimedOut ? $this->timeout + 2.0 : $this->durationSeconds * $spread;
    }

    /**
     * usleep() returns early on a signal (Horizon sends SIGTERM to a worker when it scales down), so the job sleeps
     * until its deadline in slices.
     */
    private function sleepFor(float $seconds): void
    {
        $until = hrtime(true) + (int) round($seconds * 1e9);

        while (($left = $until - hrtime(true)) > 0) {
            usleep(min(intdiv($left, 1000), 1_000_000));
        }
    }
}
