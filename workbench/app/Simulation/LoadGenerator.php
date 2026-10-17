<?php

declare(strict_types=1);

namespace Workbench\App\Simulation;

use Illuminate\Bus\PendingBatch;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Str;
use InvalidArgumentException;
use Random\Engine\Mt19937;
use Random\Randomizer;

/**
 * Dispatches simulated jobs and batches with a seedable random source (workbench:simulate-load, GET /demo/dispatch).
 * Everything goes through the Bus facade, so Bus::fake() and Queue::fake() see it.
 */
final readonly class LoadGenerator
{
    private Randomizer $random;

    public function __construct(
        private string $connection,
        ?int $seed = null,
    ) {
        $this->random = new Randomizer($seed === null ? new Mt19937 : new Mt19937($seed));
    }

    /**
     * Dispatches `$count` jobs of a task with outcomes rolled as in the continuous run; the number dispatched.
     */
    public function stream(TaskProfile $task, int $count, int $delaySeconds = 0): int
    {
        for ($i = 0; $i < $count; $i++) {
            $this->dispatch(SimulatedJob::of($task, $task->continuousOutcome($this->float() * 100)), $task->queue, $delaySeconds);
        }

        return max(0, $count);
    }

    /**
     * The one-shot jobs of a task at a scale, shuffled; dispatch them with {@see self::dispatch()}.
     *
     * @return list<SimulatedJob>
     */
    public function oneShot(TaskProfile $task, float $scale): array
    {
        $jobs = [];

        foreach ($task->oneShotCounts($scale) as $outcome => $count) {
            for ($i = 0; $i < $count; $i++) {
                $jobs[] = SimulatedJob::of($task, Outcome::from($outcome));
            }
        }

        return $jobs;
    }

    /**
     * @param  list<SimulatedJob>  $jobs
     * @return list<SimulatedJob>
     */
    public function shuffle(array $jobs): array
    {
        return array_values($this->random->shuffleArray($jobs));
    }

    public function dispatch(SimulatedJob $job, string $queue, int $delaySeconds = 0): void
    {
        $job->onConnection($this->connection)->onQueue($queue);

        if ($delaySeconds > 0) {
            $job->delay($delaySeconds);
        }

        Bus::dispatch($job);
    }

    /**
     * Dispatches one batch of a kind; its name.
     *
     * @param  bool  $continuous  the continuous-run size and duration instead of the one-shot ones
     */
    public function batch(BatchProfile $profile, bool $continuous): string
    {
        $size = $continuous ? $this->between($profile->continuousSize) : $profile->oneShotSize;
        $mean = $continuous ? $profile->continuousMeanSeconds : $profile->oneShotMeanSeconds;
        $failed = min($size, $profile->failed);
        $jobs = [];

        for ($i = 0; $i < $size; $i++) {
            $outcome = $i < $failed ? Outcome::Failed : Outcome::Completed;
            $jobs[] = new SimulatedJob($profile->task, $outcome, $mean, BatchProfile::JOB_TIMEOUT_SECONDS);
        }

        // The failing job is not first, so the batch gets some work done before it is cancelled or fails.
        $jobs = $this->shuffle($jobs);
        $name = str_contains($profile->pattern, '%s')
            ? sprintf($profile->pattern, Str::uuid()->toString())
            : sprintf($profile->pattern, $this->random->getInt(1, 9999));

        $this->pendingBatch($jobs)
            ->name($name)
            ->allowFailures($profile->allowFailures)
            ->onConnection($this->connection)
            ->onQueue($profile->queue)
            ->dispatch();

        return $name;
    }

    /**
     * A task of a queue, frequent tasks more often (weighted by the continuous rate).
     */
    public function weightedTask(string $queue): TaskProfile
    {
        $tasks = TaskProfile::forQueue($queue);

        if ($tasks === []) {
            throw new InvalidArgumentException(sprintf('No simulated task runs on the queue "%s".', $queue));
        }

        $roll = $this->float() * array_sum(array_map(static fn (TaskProfile $task): float => $task->perMinute, $tasks));

        foreach ($tasks as $task) {
            if (($roll -= $task->perMinute) <= 0) {
                return $task;
            }
        }

        return end($tasks);
    }

    /**
     * A batch kind of the continuous run, successful kinds more often.
     */
    public function weightedBatch(): BatchProfile
    {
        $profiles = BatchProfile::catalog();
        $roll = $this->random->getInt(1, array_sum(array_map(static fn (BatchProfile $profile): int => $profile->weight, $profiles)));

        foreach ($profiles as $profile) {
            if (($roll -= $profile->weight) <= 0) {
                return $profile;
            }
        }

        return $profiles[0];
    }

    /**
     * A Poisson-distributed count with the given mean (Knuth's algorithm; the means here are small).
     */
    public function poisson(float $mean): int
    {
        if ($mean <= 0) {
            return 0;
        }

        $limit = exp(-$mean);
        $count = 0;
        $product = $this->float();

        while ($product > $limit) {
            $count++;
            $product *= $this->float();
        }

        return $count;
    }

    /**
     * @param  array{0: int, 1: int}  $range
     */
    public function between(array $range): int
    {
        return $this->random->getInt($range[0], $range[1]);
    }

    /**
     * A float in [0, 1) (Randomizer::nextFloat() needs PHP 8.3).
     */
    public function float(): float
    {
        return $this->random->getInt(0, (1 << 53) - 1) / (1 << 53);
    }

    /**
     * @param  list<SimulatedJob>  $jobs
     */
    private function pendingBatch(array $jobs): PendingBatch
    {
        return Bus::batch($jobs);
    }
}
