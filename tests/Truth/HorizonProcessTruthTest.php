<?php

declare(strict_types=1);

use Carbon\Carbon;
use Carbon\CarbonImmutable;
use DF\HorizonObservability\Collectors\AttemptsCollector;
use DF\HorizonObservability\Metrics\Metric;
use DF\HorizonObservability\Tests\Fixtures\Attempts\Jobs\CompletingJob;
use DF\HorizonObservability\Tests\Fixtures\Attempts\Storage;
use DF\HorizonObservability\Tests\Truth\Support\HorizonTruth;
use DF\HorizonObservability\Tests\Truth\Support\Model;
use DF\HorizonObservability\Tests\Truth\Support\Samples;
use DF\HorizonObservability\Tests\Truth\Support\Scenario;
use Laravel\Horizon\Contracts\SupervisorRepository;
use Symfony\Component\Process\Process;

/*
| Exporter truth (T16) with a real Horizon: `testbench horizon` starts the workbench's three supervisors (auto,
| auto with two queues, a balance=false pool of mail and sms) as separate processes. The horizon families equal what
| that Horizon registered in its repositories, read before and after the scrape until both reads agree (the auto
| balancer may move a process in between), and its own worker processes count every attempt of the jobs this test
| dispatches, the pooled queues included. The jobs are CompletingJob fixtures: Horizon's processes run the skeleton's
| artisan, whose vendor link may point at another checkout in a local worktree (CI has one checkout).
*/

pest()->group('redis', 'truth');

afterEach(function (): void {
    Scenario::stop();
});

function horizonProcessWait(callable $condition, float $seconds, string $message, Process $horizon): void
{
    $deadline = microtime(true) + $seconds;

    while (! $condition()) {
        if (microtime(true) > $deadline || ! $horizon->isRunning()) {
            throw new RuntimeException($message.".\n".$horizon->getOutput().$horizon->getErrorOutput());
        }

        usleep(100_000);
    }
}

it('exports the supervisors a real Horizon registers and the attempts its workers run', function (): void {
    $scenario = Scenario::start($this);
    Scenario::stop(); // a real Horizon runs on the real clock
    // The test process's environment (key prefixes, Redis client) plus stores that need no tables: the test database
    // is this process's in-memory SQLite.
    $horizon = new Process([PHP_BINARY, 'vendor/bin/testbench', 'horizon'], dirname(__DIR__, 2), [
        'APP_ENV' => 'testing',
        'CACHE_STORE' => 'array',
        'DB_CONNECTION' => 'sqlite',
        'DB_DATABASE' => ':memory:',
        'QUEUE_FAILED_DRIVER' => 'null',
    ], null, null);
    $horizon->start();

    try {
        $supervisors = app(SupervisorRepository::class);
        horizonProcessWait(static function () use ($supervisors): bool {
            $running = array_filter($supervisors->all(), static fn (object $record): bool => array_sum((array) ($record->processes ?? [])) > 0);

            return count($running) === 3;
        }, 60, 'Horizon did not start its three supervisors', $horizon);

        $jobs = ['default' => 4, 'reports' => 2, 'mail' => 3, 'sms' => 2];

        foreach ($jobs as $queue => $count) {
            foreach (range(1, $count) as $ignored) {
                CompletingJob::dispatch()->onConnection('redis')->onQueue($queue);
            }
        }

        $redis = $scenario->queueRedis();
        horizonProcessWait(static function () use ($redis, $jobs): bool {
            foreach (array_keys($jobs) as $queue) {
                if ((int) $redis->llen('queues:'.$queue) + (int) $redis->zcard('queues:'.$queue.':reserved') > 0) {
                    return false;
                }
            }

            return true;
        }, 60, 'Horizon did not work the queues', $horizon);
        // A worker records an attempt after it deleted the job, so the queues empty a moment before the last record.
        horizonProcessWait(static fn (): bool => (Storage::statuses(CompletingJob::class)['completed'] ?? 0) === array_sum($jobs), 30, 'Horizon\'s workers did not record every attempt', $horizon);

        // Horizon's repositories only list masters and supervisors that reported within the last 15 or 30 s, so the
        // horizon families are compared on the real clock.
        $truth = app(HorizonTruth::class);

        for ($try = 0; $try < 20; $try++) {
            $before = $truth->expected();
            $samples = new Samples($scenario->scrape());
            $after = $truth->expected();

            if ($before === $after) {
                break;
            }
        }

        $scraped = [];

        foreach (Metric::ofCollector('horizon') as $metric) {
            if ($metric !== Metric::HorizonInfo && ($series = $samples->series($metric->value)) !== []) {
                $scraped[$metric->value] = $series;
            }
        }

        expect($scraped)->toBe($after)
            ->and($samples->value(Metric::HorizonStatus->value))->toBe(1.0)
            ->and($samples->series(Metric::SupervisorProcesses->value))->toHaveKey(Model::key(['supervisor' => 'supervisor-pool', 'queue' => 'mail,sms']))
            ->and($samples->series(Metric::SupervisorQueues->value))->toHaveKeys([
                Model::key(['supervisor' => 'supervisor-pool', 'queue' => 'mail', 'pool' => 'mail,sms']),
                Model::key(['supervisor' => 'supervisor-pool', 'queue' => 'sms', 'pool' => 'mail,sms']),
            ]);

        // The attempts past the hold of their new series: this scrape runs 95 s (the hold + 5 s) ahead of the
        // workers' clock.
        $later = CarbonImmutable::now()->addSeconds(AttemptsCollector::NEW_SERIES_HOLD_SECONDS + 5);
        Carbon::setTestNow($later);
        CarbonImmutable::setTestNow($later);
        $samples = new Samples($scenario->scrape());

        foreach ($jobs as $queue => $count) {
            expect($samples->value(Metric::JobsTotal->value, ['connection' => 'redis', 'queue' => $queue, 'job_class' => CompletingJob::class, 'status' => 'completed']))
                ->toBe((float) $count, $queue);
        }
    } finally {
        $horizon->signal(SIGTERM);
        $deadline = microtime(true) + 60;

        while ($horizon->isRunning() && microtime(true) < $deadline) {
            usleep(100_000);
        }

        $horizon->stop(0);
    }
});
