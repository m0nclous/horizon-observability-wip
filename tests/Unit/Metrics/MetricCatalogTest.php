<?php

declare(strict_types=1);

use DF\HorizonObservability\Metrics\Metric;
use DF\HorizonObservability\Metrics\MetricType;

/*
| The catalog equals Arch Appendix B as amended by Addendum R1, R3.4 and B': case, family, type, labels, collector
| and HELP, in declaration order (C17: no spatie families, no horizon_current_processes).
*/

const APPENDIX_B = [
    ['HorizonStatus', 'horizon_status', 'gauge', [], 'horizon', 'Horizon status: 1 running, 0 paused (at least one master supervisor paused), -1 no master supervisor.'],
    ['SupervisorProcesses', 'horizon_supervisor_processes', 'gauge', ['supervisor', 'queue'], 'horizon', 'Worker processes of a Horizon supervisor per queue; balance=false supervisors report their pooled queue list.'],
    ['SupervisorMinProcesses', 'horizon_supervisor_min_processes', 'gauge', ['supervisor'], 'horizon', 'Configured minimum worker processes of a Horizon supervisor.'],
    ['SupervisorMaxProcesses', 'horizon_supervisor_max_processes', 'gauge', ['supervisor'], 'horizon', 'Configured maximum worker processes of a Horizon supervisor.'],
    ['SupervisorInfo', 'horizon_supervisor_info', 'gauge', ['supervisor', 'balance', 'connection'], 'horizon', 'Horizon supervisor settings; always 1.'],
    ['WaitThreshold', 'horizon_wait_threshold_seconds', 'gauge', ['connection', 'queue'], 'horizon', 'Long-wait threshold of a queue from horizon.waits, seconds.'],
    ['HorizonInfo', 'horizon_info', 'gauge', ['url', 'api_path', 'tts_url'], 'horizon', 'Links for the Grafana dashboard: Horizon URL, job-list API path and text-to-speech URL; always 1.'],
    ['QueuePendingJobs', 'queue_pending_jobs', 'gauge', ['connection', 'queue'], 'queues', 'Jobs ready to run and waiting for a worker, including delayed jobs that are already due.'],
    ['QueueDelayedJobs', 'queue_delayed_jobs', 'gauge', ['connection', 'queue'], 'queues', 'Delayed jobs (dispatched or released with a delay), including due ones; see queue_delayed_due_jobs.'],
    ['QueueDelayedDueJobs', 'queue_delayed_due_jobs', 'gauge', ['connection', 'queue'], 'queues', 'Delayed jobs whose delay has expired but that have not been moved to the queue yet.'],
    ['QueueOldestPendingJobAge', 'queue_oldest_pending_job_age_seconds', 'gauge', ['connection', 'queue'], 'queues', 'Seconds the oldest ready first-attempt job has waited since it became available; 0 when nothing is waiting.'],
    ['QueueJobsPending', 'queue_jobs_pending', 'gauge', ['connection', 'queue', 'job_class'], 'queues', 'Ready jobs per job class in the scanned head of the queue; (unscanned) counts the rest.'],
    ['QueueJobsReserved', 'queue_jobs_reserved', 'gauge', ['connection', 'queue', 'job_class'], 'queues', 'Jobs currently reserved by workers per job class.'],
    ['JobsTotal', 'queue_jobs_total', 'counter', ['connection', 'queue', 'job_class', 'status'], 'attempts', 'Finished job attempts by outcome: completed, released, retried, failed, timed_out.'],
    ['JobDuration', 'queue_job_duration_seconds', 'histogram', ['connection', 'queue', 'job_class'], 'attempts', 'Duration of job attempts, seconds.'],
    ['JobWait', 'queue_job_wait_seconds', 'histogram', ['connection', 'queue'], 'attempts', 'Seconds a job waited in the queue before its first attempt started.'],
    ['JobDurationMin', 'queue_job_duration_min_seconds', 'gauge', ['connection', 'queue', 'job_class'], 'attempts', 'Shortest job attempt during the last 150 seconds, seconds.'],
    ['JobDurationMax', 'queue_job_duration_max_seconds', 'gauge', ['connection', 'queue', 'job_class'], 'attempts', 'Longest job attempt during the last 150 seconds, seconds.'],
    ['AttemptsDropped', 'exporter_attempts_dropped_total', 'counter', [], 'attempts', 'Job attempts not recorded because the metrics Redis was unavailable.'],
    ['LivePushTimestamp', 'exporter_live_push_timestamp_seconds', 'gauge', [], 'attempts', 'Time of the last successful Grafana Live push of the live overview, Unix seconds.'],
    ['LivePushFailures', 'exporter_live_push_failures_total', 'counter', [], 'attempts', 'Failed Grafana Live pushes of the live overview.'],
    ['JobClasses', 'queue_job_classes', 'gauge', ['job_class'], 'job_classes', 'Queueable job classes found in the application code; always 1.'],
    ['BatchesRunning', 'queue_batches_running', 'gauge', ['batch'], 'batches', 'Running job batches per batch kind.'],
    ['BatchJobs', 'queue_batch_jobs', 'gauge', ['batch', 'state'], 'batches', 'Jobs of running batches per batch kind and state: total, pending, failed.'],
    ['BatchOldestAge', 'queue_batch_oldest_age_seconds', 'gauge', ['batch'], 'batches', 'Age of the oldest running batch of a kind, seconds.'],
    ['BatchJobCounts', 'queue_batch_job_counts', 'gauge', ['batch', 'name', 'state'], 'batches', 'Jobs of a recent batch per state: total, pending, failed.'],
    ['BatchCreated', 'queue_batch_created_timestamp_seconds', 'gauge', ['batch', 'name'], 'batches', 'Creation time of a recent batch, Unix seconds.'],
    ['BatchClosed', 'queue_batch_closed_timestamp_seconds', 'gauge', ['batch', 'name'], 'batches', 'Finish or cancel time of a recent batch, Unix seconds.'],
    ['BatchJobClasses', 'queue_batch_job_classes', 'gauge', ['batch', 'queue', 'job_class'], 'batches', 'Queues and job classes seen in batches of a kind during the last 7 days; always 1.'],
    ['BatchInfo', 'queue_batch_info', 'gauge', ['batch', 'queue', 'connection'], 'batches', 'Queues and connections of a batch kind; always 1.'],
    ['BatchProgress', 'queue_batch_progress', 'gauge', ['batch', 'name', 'status', 'id'], 'batches', 'Progress of a recent batch, percent of processed jobs.'],
    ['CollectorSuccess', 'exporter_collector_success', 'gauge', ['collector'], 'self', 'Whether the last run of a metrics collector succeeded: 1 or 0.'],
    ['CollectorDuration', 'exporter_collector_duration_seconds', 'gauge', ['collector'], 'self', 'Duration of the last run of a metrics collector, seconds.'],
    ['BuildInfo', 'exporter_build_info', 'gauge', ['version', 'php', 'laravel', 'horizon', 'redis_client'], 'self', 'Versions of the package, PHP, Laravel, Horizon and the Redis client; always 1.'],
    ['ConfigProblems', 'exporter_config_problems', 'gauge', [], 'self', 'Number of invalid configuration values replaced by defaults; see php artisan horizon-observability:check.'],
];

it('equals Appendix B as amended by Addendum B-prime, in declaration order', function (): void {
    $actual = array_map(static fn (Metric $metric): array => [
        $metric->name,
        $metric->value,
        $metric->type()->value,
        $metric->labels(),
        $metric->collector(),
        $metric->help(),
    ], Metric::cases());

    expect($actual)->toBe(APPENDIX_B);
});

it('puts the two live self-metrics right after AttemptsDropped', function (): void {
    $names = array_map(static fn (Metric $metric): string => $metric->name, Metric::cases());
    $index = array_search('AttemptsDropped', $names, true);

    expect(array_slice($names, (int) $index, 3))->toBe(['AttemptsDropped', 'LivePushTimestamp', 'LivePushFailures']);
});

it('names every self-metric ho_exporter_* with the default namespace', function (): void {
    $exporter = array_values(array_filter(Metric::cases(), static fn (Metric $metric): bool => str_starts_with($metric->value, 'exporter_')));

    expect(array_map(static fn (Metric $metric): string => 'ho_'.$metric->value, $exporter))->toBe([
        'ho_exporter_attempts_dropped_total',
        'ho_exporter_live_push_timestamp_seconds',
        'ho_exporter_live_push_failures_total',
        'ho_exporter_collector_success',
        'ho_exporter_collector_duration_seconds',
        'ho_exporter_build_info',
        'ho_exporter_config_problems',
    ]);
});

it('dropped the redundant and spatie families (C17)', function (): void {
    $values = array_map(static fn (Metric $metric): string => $metric->value, Metric::cases());

    expect($values)->not->toContain('horizon_current_processes')
        ->and($values)->not->toContain('queue_oldest_pending_job_age')
        ->and(Metric::BatchProgress->labels())->not->toContain('size')
        ->and(Metric::BatchProgress->labels())->not->toContain('created')
        ->and(Metric::HorizonInfo->labels())->toContain('api_path');
});

it('uses no reserved label names', function (Metric $metric): void {
    foreach ($metric->labels() as $label) {
        expect($label)->not->toBeIn(['job', 'instance', 'le'])
            ->and(str_starts_with($label, '__'))->toBeFalse()
            ->and($label)->toMatch('/^[a-z_][a-z0-9_]*$/');
    }

    expect($metric->labels())->toBe(array_values(array_unique($metric->labels())));
})->with(Metric::cases());

it('has valid, unique family names and suffix conventions', function (Metric $metric): void {
    expect($metric->value)->toMatch('/^[a-z_][a-z0-9_]*$/')
        ->and(str_ends_with($metric->value, '_total'))->toBe($metric->type() === MetricType::Counter)
        ->and(str_ends_with($metric->value, '_bucket') || str_ends_with($metric->value, '_sum') || str_ends_with($metric->value, '_count'))->toBeFalse();
})->with(Metric::cases());

it('has ASCII HELP texts without the namespace', function (Metric $metric): void {
    expect(preg_match('/^[\x20-\x7e]+$/', $metric->help()))->toBe(1)
        ->and($metric->help())->not->toContain('ho_')
        ->and($metric->help())->not->toContain("\n");
})->with(Metric::cases());

it('dates every family 0.1.0', function (Metric $metric): void {
    expect($metric->since())->toBe('0.1.0');
})->with(Metric::cases());

it('has unique values', function (): void {
    $values = array_map(static fn (Metric $metric): string => $metric->value, Metric::cases());

    expect($values)->toBe(array_values(array_unique($values)));
});

it('lists the families of a collector in catalog order', function (): void {
    expect(Metric::ofCollector('attempts'))->toBe([
        Metric::JobsTotal, Metric::JobDuration, Metric::JobWait, Metric::JobDurationMin, Metric::JobDurationMax,
        Metric::AttemptsDropped, Metric::LivePushTimestamp, Metric::LivePushFailures,
    ])
        ->and(Metric::ofCollector('self'))->toBe([Metric::CollectorSuccess, Metric::CollectorDuration, Metric::BuildInfo, Metric::ConfigProblems])
        ->and(Metric::ofCollector('unknown'))->toBe([]);
});
