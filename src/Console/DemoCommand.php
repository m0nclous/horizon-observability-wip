<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Console;

use DF\HorizonObservability\Demo\DemoJob;
use DF\HorizonObservability\Horizon\Topology;
use DF\HorizonObservability\Support\FailureReporter;
use Illuminate\Console\Command;
use Illuminate\Contracts\Config\Repository;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Bus;
use Random\Randomizer;
use Throwable;

/**
 * PUBLIC `horizon-observability:demo` (owner decision 12): dispatches a short burst of sample jobs so that an empty
 * application shows data on the dashboard at once: jobs that complete, fail, fail once and succeed on the retry,
 * release themselves once, are delayed by 30 to 120 s, plus one batch.
 *
 * - The jobs go to the first queue Horizon processes on the default queue connection (or on the first redis queue
 *   connection of the Horizon supervisors), so the running `php artisan horizon` picks them up.
 * - It refuses in production. Running it again only dispatches another burst; the jobs touch nothing.
 */
final class DemoCommand extends Command
{
    public const MAX_JOBS = 1000;

    public const BATCH_SIZE = 10;

    /**
     * Names of the sample jobs (shown as `Demo\<name>`).
     */
    public const NAMES = ['SendInvoiceEmail', 'ResizeImage', 'SyncInventory', 'GenerateReport', 'NotifySubscribers'];

    /**
     * @var string
     */
    protected $signature = 'horizon-observability:demo
                            {--jobs=200 : Number of sample jobs, 1 to 1000 (plus a batch of 10)}';

    /**
     * @var string
     */
    protected $description = 'Dispatch a short burst of sample jobs so the dashboard shows data (not in production)';

    public function handle(Topology $topology, Repository $config): int
    {
        if ($this->laravel->environment('production') === true) {
            $this->components->error('The demo dispatches sample jobs and refuses to run in production.');

            return self::FAILURE;
        }

        $count = $this->option('jobs');
        $count = is_int($count) ? (string) $count : $count;

        if (! is_string($count) || preg_match('/^\d{1,4}$/', $count) !== 1 || (int) $count < 1 || (int) $count > self::MAX_JOBS) {
            $this->components->error(sprintf('--jobs must be an integer from 1 to %d.', self::MAX_JOBS));

            return self::FAILURE;
        }

        try {
            $target = $this->target($topology, $config);
        } catch (Throwable $e) {
            $this->components->error('Horizon\'s configuration cannot be read: '.FailureReporter::describe($e));

            return self::FAILURE;
        }

        if ($target === null) {
            $this->components->error(sprintf(
                'Horizon processes no redis queue in the environment "%s": add a supervisor to config/horizon.php first.',
                $topology->environment(),
            ));

            return self::FAILURE;
        }

        [$connection, $queue] = $target;
        $plan = self::plan((int) $count);
        $random = new Randomizer;

        try {
            foreach ($plan as $index => [$outcome, $delay]) {
                $pending = DemoJob::dispatch(self::NAMES[$index % count(self::NAMES)], $outcome, $this->duration($random))
                    ->onConnection($connection)
                    ->onQueue($queue);

                if ($delay > 0) {
                    $pending->delay($delay);
                }

                // A pending dispatch is sent when it is destroyed; inside the try, so a failure is reported here.
                unset($pending);
            }
        } catch (Throwable $e) {
            $this->components->error(sprintf('Dispatching to %s/%s failed: %s', $connection, $queue, FailureReporter::describe($e)));

            return self::FAILURE;
        }

        $batch = $this->batch($connection, $queue, $random);
        $counts = array_count_values(array_map(static fn (array $step): string => $step[1] > 0 ? 'delayed' : $step[0], $plan));

        $this->components->info(sprintf(
            'Dispatched %d sample jobs to %s/%s: %d complete, %d fail, %d fail once and retry, %d release once, %d are delayed by 30 to 120 s%s.',
            count($plan),
            $connection,
            $queue,
            $counts['completed'] ?? 0,
            $counts['failed'] ?? 0,
            $counts['retried'] ?? 0,
            $counts['released'] ?? 0,
            $counts['delayed'] ?? 0,
            $batch,
        ));
        $this->line('  Horizon processes them while it runs (php artisan horizon); the dashboard shows them within a scrape.');

        return self::SUCCESS;
    }

    /**
     * The outcome and delay (seconds, 0 = none) of each job: 10 % fail, 10 % retry once, 5 % release once, 10 % are
     * delayed, the rest completes; interleaved so that every name gets every outcome.
     *
     * @return list<array{0: string, 1: int}>
     */
    public static function plan(int $count): array
    {
        $failed = intdiv($count, 10);
        $retried = intdiv($count, 10);
        $released = intdiv($count, 20);
        $delayed = intdiv($count, 10);
        $plan = [];

        for ($i = 0; $i < $count; $i++) {
            $plan[] = match (true) {
                $i < $failed => ['failed', 0],
                $i < $failed + $retried => ['retried', 0],
                $i < $failed + $retried + $released => ['released', 0],
                $i < $failed + $retried + $released + $delayed => ['completed', 30 + (($i * 7) % 91)],
                default => ['completed', 0],
            };
        }

        // Spread the outcomes over the burst instead of failing everything first.
        $spread = [];

        foreach ($plan as $i => $step) {
            $spread[($i * 37) % $count] ??= $step;
        }

        ksort($spread);

        return count($spread) === $count ? array_values($spread) : $plan;
    }

    /**
     * A duration in milliseconds: mostly short, some long, so the duration histogram has a shape.
     */
    private function duration(Randomizer $random): int
    {
        $roll = $random->getInt(1, 100);

        return match (true) {
            $roll <= 50 => $random->getInt(50, 200),
            $roll <= 85 => $random->getInt(200, 800),
            default => $random->getInt(800, 2500),
        };
    }

    /**
     * One batch of {@see self::BATCH_SIZE} jobs (one of them fails, the batch allows failures); a note for the summary.
     */
    private function batch(string $connection, string $queue, Randomizer $random): string
    {
        $jobs = [];

        for ($i = 0; $i < self::BATCH_SIZE; $i++) {
            $jobs[] = new DemoJob('ImportCustomers', $i === 0 ? 'failed' : 'completed', $this->duration($random));
        }

        try {
            Bus::batch($jobs)->name('Demo import')->allowFailures()->onConnection($connection)->onQueue($queue)->dispatch();
        } catch (Throwable $e) {
            // A query error repeats the whole SQL; the driver's message says what is wrong.
            $reason = $e instanceof QueryException && $e->getPrevious() instanceof Throwable ? $e->getPrevious()->getMessage() : FailureReporter::describe($e);
            $this->components->warn('The demo batch was skipped: '.$reason.' (batches need php artisan make:queue-batches-table and php artisan migrate).');

            return '';
        }

        return sprintf(', plus a batch of %d', self::BATCH_SIZE);
    }

    /**
     * The queue connection and queue of the burst: the first Horizon queue on the default queue connection, else the
     * first one on a redis queue connection; null when Horizon processes none.
     *
     * @return array{0: string, 1: string}|null
     */
    private function target(Topology $topology, Repository $config): ?array
    {
        $default = $config->get('queue.default');
        $candidates = [];

        foreach ($topology->queues() as $entry) {
            if ($topology->redisConnection($entry['connection']) !== null) {
                $candidates[] = [$entry['connection'], $entry['queue']];
            }
        }

        foreach ($candidates as $candidate) {
            if ($candidate[0] === $default) {
                return $candidate;
            }
        }

        return $candidates[0] ?? null;
    }
}
