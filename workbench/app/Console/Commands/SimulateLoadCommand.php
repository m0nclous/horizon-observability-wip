<?php

declare(strict_types=1);

namespace Workbench\App\Console\Commands;

use DF\HorizonObservability\Horizon\Topology;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Workbench\App\Simulation\BatchProfile;
use Workbench\App\Simulation\LoadGenerator;
use Workbench\App\Simulation\Outcome;
use Workbench\App\Simulation\SimulatedJob;
use Workbench\App\Simulation\TaskProfile;

/**
 * Generates queue load for the workbench dashboard (Arch 8.2, 8.3; replaces the pilot's queue-metrics:load-test).
 *
 * It lives in the workbench only, so it never ships with the package (C25). It refuses the production environment and
 * any queue connection that does not use the redis driver: with sync the jobs would run inline in the command.
 *
 * One-shot (default): every task's jobs spread over --spread seconds after a --warmup, plus --batches batches of each
 * kind. A warm-up job per task runs first, so Prometheus sees every attempt counter before the load starts.
 *
 * --continuous: a production-like stream until stopped (Ctrl+C, SIGTERM or --duration). Every second each task gets a
 * Poisson-distributed number of jobs around its rate, modulated by a slow wave; bursts, overloads and batches arrive
 * at random intervals.
 */
final class SimulateLoadCommand extends Command
{
    /** Each task's rate changes by +-50 % over a 20-minute period, with its own phase. */
    public const WAVE_PERIOD_SECONDS = 1200;

    public const WAVE_AMPLITUDE = 0.5;

    /** A burst: 30-80 jobs of one frequent default task at once, like a mailing. */
    public const BURST_EVERY_SECONDS = [300, 720];

    public const BURST_SIZE = [30, 80];

    /** Bursts pick among the most frequent default tasks. */
    public const BURST_TASKS = 6;

    /**
     * An overload: one queue runs 6-10 times its rate for 1-3 minutes and gets 30 jobs at once when it starts, so the
     * slow queues with rare long jobs reach their worker limits too.
     */
    public const OVERLOAD_EVERY_SECONDS = [900, 1800];

    public const OVERLOAD_SECONDS = [60, 180];

    public const OVERLOAD_FACTOR = [6, 10];

    public const OVERLOAD_DUMP = 30;

    /** Batches of the continuous run are large (one or two minutes of progress), so they come every 4-8 minutes. */
    public const BATCH_EVERY_SECONDS = [240, 480];

    protected $signature = 'workbench:simulate-load
        {--queue=* : Simulated queue to load, repeatable (default: all of default, slow, reports, mail, sms)}
        {--connection= : Queue connection (default: queue.default); it must use the redis driver}
        {--scale=1 : Multiplier of the job counts (one-shot) or of the rates (--continuous)}
        {--spread=120 : One-shot: seconds over which the jobs become available}
        {--warmup=30 : One-shot: seconds after the warm-up jobs before the load starts; 0 = no warm-up}
        {--batches=1 : Batches of each kind (one-shot); 0 = none. With --continuous any value above 0 enables batches}
        {--continuous : Production-like load until stopped}
        {--duration=0 : With --continuous: stop after this many seconds; 0 = until Ctrl+C or SIGTERM}
        {--seed= : Seed of the random source, for reproducible runs}';

    protected $description = 'Generate queue load for the workbench dashboard (development only)';

    public function handle(Repository $config, Topology $topology): int
    {
        if ($this->laravel->isProduction()) {
            $this->error('The load simulation never runs in production.');

            return self::FAILURE;
        }

        $connection = $this->stringOption('connection') ?? $config->get('queue.default');
        $connection = is_string($connection) ? $connection : '';
        $driver = $config->get("queue.connections.{$connection}.driver");

        if ($driver !== 'redis') {
            $this->error(sprintf(
                'The load simulation needs a redis queue connection; "%s" uses %s.',
                $connection,
                is_string($driver) ? 'the '.$driver.' driver' : 'no configured driver',
            ));

            return self::FAILURE;
        }

        $queues = $this->queues();
        $unknown = array_diff($queues, TaskProfile::queues());

        if ($unknown !== []) {
            $this->error('Unknown simulated queues: '.implode(', ', $unknown).'. Known: '.implode(', ', TaskProfile::queues()).'.');

            return self::FAILURE;
        }

        $idle = array_diff($queues, $topology->queuesByConnection()[$connection] ?? []);

        if ($idle !== []) {
            $this->warn('Horizon does not process these queues, their jobs will stay queued: '.implode(', ', $idle));
        }

        $seed = $this->option('seed');
        $generator = new LoadGenerator($connection, is_numeric($seed) ? (int) $seed : null);
        $scale = max(0.0, $this->floatOption('scale', 1.0));
        $batches = max(0, (int) $this->floatOption('batches', 1.0));

        if ($this->option('continuous') === true) {
            return $this->continuous($generator, $queues, $scale, $batches > 0, max(0, (int) $this->floatOption('duration', 0.0)));
        }

        return $this->oneShot($generator, $queues, $scale, $batches);
    }

    /**
     * @param  list<string>  $queues
     */
    private function oneShot(LoadGenerator $generator, array $queues, float $scale, int $batches): int
    {
        $spread = max(0, (int) $this->floatOption('spread', 120.0));
        $warmup = max(0, (int) $this->floatOption('warmup', 30.0));
        $rows = [];

        foreach ($queues as $queue) {
            $jobs = [];

            foreach (TaskProfile::forQueue($queue) as $task) {
                $taskJobs = $generator->oneShot($task, $scale);
                $jobs = [...$jobs, ...$taskJobs];

                if ($warmup > 0) {
                    $generator->dispatch(SimulatedJob::warmup($task), $queue);
                }

                $counts = $task->oneShotCounts($scale);
                $rows[] = [
                    $queue,
                    $task->name,
                    count($taskJobs),
                    $counts[Outcome::Completed->value] ?? 0,
                    $counts[Outcome::Released->value] ?? 0,
                    $counts[Outcome::Retried->value] ?? 0,
                    $counts[Outcome::Exhausted->value] ?? 0,
                    $counts[Outcome::Failed->value] ?? 0,
                    $counts[Outcome::TimedOut->value] ?? 0,
                ];
            }

            // Tasks interleaved, so every moment of the run has a mix.
            $jobs = $generator->shuffle($jobs);

            foreach ($jobs as $index => $job) {
                $generator->dispatch($job, $queue, $warmup + intdiv($spread * $index, max(1, count($jobs))));
            }
        }

        $this->table(['Queue', 'Task', 'Jobs', 'Complete', 'Released', 'Retried', 'Exhausted', 'Failed', 'Timed out'], $rows);

        if ($batches > 0) {
            foreach (BatchProfile::catalog() as $profile) {
                for ($i = 0; $i < $batches; $i++) {
                    $generator->batch($profile, false);
                }
            }

            $this->info(sprintf(
                'Batches: %d of each kind: test_import on slow (no failures), test_report on reports (one failing job, '
                .'allowFailures) and test_sync on slow (one failing job, so it is cancelled).',
                $batches,
            ));
        }

        $this->info(sprintf(
            'The load starts in %d s and lasts about %d s. Released and retried jobs complete on their second attempt; '
            .'exhausted jobs fail after 3 attempts; each timed-out job kills its worker.%s',
            $warmup,
            $spread,
            $warmup > 0 ? ' Every task also got one warm-up job.' : '',
        ));

        return self::SUCCESS;
    }

    /**
     * @param  list<string>  $queues
     */
    private function continuous(LoadGenerator $generator, array $queues, float $scale, bool $withBatches, int $duration): int
    {
        $running = true;
        $this->trap([SIGINT, SIGTERM], static function () use (&$running): void {
            $running = false;
        });

        $started = time();
        $deadline = $duration > 0 ? $started + $duration : null;
        $phases = [];
        $nextBurst = $started + $generator->between(self::BURST_EVERY_SECONDS);
        $nextOverload = $started + $generator->between(self::OVERLOAD_EVERY_SECONDS);
        // The first batch comes at once, so the batch table is not empty for the first minutes.
        $nextBatch = $started;
        $overload = null;
        $dispatched = 0;
        $reportedAt = $started;

        $this->info(sprintf(
            'Continuous load on %s at %sx the base rates%s. Stop with Ctrl+C or SIGTERM.',
            implode(', ', $queues),
            $scale,
            $deadline === null ? '' : sprintf(' for %d s', $duration),
        ));

        while ($running && ($deadline === null || time() < $deadline)) {
            $tick = microtime(true);
            $now = time();

            if ($overload !== null && $now >= $overload['until']) {
                $this->line(sprintf('[%s] overload of %s ended', date('H:i:s'), $overload['queue']));
                $overload = null;
            }

            if ($overload === null && $now >= $nextOverload) {
                $overload = [
                    'queue' => $queues[$generator->between([0, count($queues) - 1])],
                    'factor' => $generator->between(self::OVERLOAD_FACTOR),
                    'until' => $now + $generator->between(self::OVERLOAD_SECONDS),
                ];
                $nextOverload = $overload['until'] + $generator->between(self::OVERLOAD_EVERY_SECONDS);

                for ($i = 0; $i < self::OVERLOAD_DUMP; $i++) {
                    $dispatched += $generator->stream($generator->weightedTask($overload['queue']), 1);
                }

                $this->line(sprintf(
                    '[%s] overload of %s: %dx the rate for %d s',
                    date('H:i:s'),
                    $overload['queue'],
                    $overload['factor'],
                    $overload['until'] - $now,
                ));
            }

            foreach ($queues as $queue) {
                $factor = $scale * ($overload !== null && $overload['queue'] === $queue ? $overload['factor'] : 1);

                foreach (TaskProfile::forQueue($queue) as $task) {
                    $phases[$task->name] ??= $generator->float() * 2 * M_PI;
                    $angle = 2 * M_PI * ($now - $started) / self::WAVE_PERIOD_SECONDS + $phases[$task->name];
                    $mean = $task->perMinute / 60 * $factor * (1 + self::WAVE_AMPLITUDE * sin($angle));
                    $dispatched += $generator->stream($task, $generator->poisson($mean));
                }
            }

            if ($now >= $nextBurst && in_array('default', $queues, true)) {
                $candidates = array_slice(TaskProfile::forQueue('default'), 0, self::BURST_TASKS);
                $task = $candidates[$generator->between([0, count($candidates) - 1])];
                $size = $generator->between(self::BURST_SIZE);
                $dispatched += $generator->stream($task, $size);
                $nextBurst = $now + $generator->between(self::BURST_EVERY_SECONDS);
                $this->line(sprintf('[%s] burst: %d x %s', date('H:i:s'), $size, $task->name));
            }

            if ($withBatches && $now >= $nextBatch) {
                $profile = $generator->weightedBatch();
                $name = $generator->batch($profile, true);
                $nextBatch = $now + $generator->between(self::BATCH_EVERY_SECONDS);
                $this->line(sprintf('[%s] batch %s on %s', date('H:i:s'), $name, $profile->queue));
            }

            if ($now - $reportedAt >= 60) {
                $this->line(sprintf('[%s] jobs dispatched in the last minute: %d', date('H:i:s'), $dispatched));
                $dispatched = 0;
                $reportedAt = $now;
            }

            usleep(max(0, 1_000_000 - (int) ((microtime(true) - $tick) * 1_000_000)));
        }

        $this->info('Load stopped.');

        return self::SUCCESS;
    }

    /**
     * @return list<string>
     */
    private function queues(): array
    {
        $option = $this->option('queue');
        $queues = is_array($option) ? array_values(array_filter($option, static fn (mixed $queue): bool => is_string($queue) && $queue !== '')) : [];

        return $queues === [] ? TaskProfile::queues() : array_values(array_unique($queues));
    }

    private function stringOption(string $name): ?string
    {
        $value = $this->option($name);

        return is_string($value) && $value !== '' ? $value : null;
    }

    private function floatOption(string $name, float $default): float
    {
        $value = $this->option($name);

        return is_numeric($value) ? (float) $value : $default;
    }
}
