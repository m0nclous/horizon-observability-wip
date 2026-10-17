<?php

declare(strict_types=1);

use DF\HorizonObservability\Metrics\Metric;
use DF\HorizonObservability\Tests\Truth\Support\Samples;
use DF\HorizonObservability\Tests\Truth\Support\Scenario;
use DF\HorizonObservability\Tests\Truth\Support\Scenarios;
use Laravel\Horizon\Contracts\JobRepository;
use Laravel\Horizon\Contracts\MetricsRepository;

/*
| Exporter truth (T16): the attempt counters agree with Horizon's own bookkeeping of the same jobs, which Horizon's
| listeners write independently of the package: MetricsRepository (throughput per queue and per job, i.e. the
| completed jobs) and JobRepository (completed and failed jobs). A failed job is the last attempt of a job, failed
| or timed out.
*/

pest()->group('redis', 'truth');

afterEach(function (): void {
    Scenario::stop();
});

it('counts the completed attempts Horizon counts per queue and per job (MetricsRepository)', function (): void {
    $scenario = Scenarios::attempts($this);
    $samples = new Samples($scenario->scrape());
    $metrics = app(MetricsRepository::class);
    $completedByQueue = [];
    $completedByClass = [];

    foreach ($samples->series(Metric::JobsTotal->value) as $key => $value) {
        $labels = Samples::labels($key);

        if ($labels['status'] === 'completed') {
            $completedByQueue[$labels['queue']] = ($completedByQueue[$labels['queue']] ?? 0) + (int) $value;
            $completedByClass[$labels['job_class']] = ($completedByClass[$labels['job_class']] ?? 0) + (int) $value;
        }
    }

    expect($completedByQueue)->not->toBe([]);

    foreach ($completedByQueue as $queue => $count) {
        expect((int) $metrics->throughputForQueue($queue))->toBe($count, (string) $queue);
    }

    foreach ($completedByClass as $class => $count) {
        expect((int) $metrics->throughputForJob($class))->toBe($count, (string) $class);
    }

    // Horizon measures nothing the exporter does not count as completed.
    expect(array_sum(array_map(static fn (string $job): int => (int) $metrics->throughputForJob($job), $metrics->measuredJobs())))
        ->toBe(array_sum($completedByClass));
});

it('counts the failed jobs Horizon lists as failed attempts and final timeouts (JobRepository)', function (): void {
    $scenario = Scenarios::attempts($this);
    $samples = new Samples($scenario->scrape());
    $byStatus = $samples->sumBy(Metric::JobsTotal->value, 'status');
    $jobs = app(JobRepository::class);

    // Every timeout of the scenario is the last try of its job.
    expect((int) $jobs->countFailed())->toBe((int) ($byStatus['failed'] + $byStatus['timed_out']))
        ->and((int) $jobs->countCompleted())->toBe((int) $byStatus['completed']);
});
