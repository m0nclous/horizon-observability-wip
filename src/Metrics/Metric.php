<?php

declare(strict_types=1);

namespace DF\HorizonObservability\Metrics;

/**
 * The single catalog of metric families (Arch Q1.2 and Appendix B, Addendum R1 and B'), in exposition order.
 *
 * The value is the family name without the namespace; the exporter prefixes it with `<namespace>_`. Family names,
 * types, label names and label value sets are the public metric contract (Arch Q4); HELP texts are English, ASCII-only
 * and never contain the namespace. Collectors, the Grafana renderer, the README metric table and the contract tests
 * read this enum. The exposed names are SemVer-covered; the PHP enum itself is not.
 *
 * @internal
 */
enum Metric: string
{
    case HorizonStatus = 'horizon_status';
    case SupervisorProcesses = 'horizon_supervisor_processes';
    case SupervisorMinProcesses = 'horizon_supervisor_min_processes';
    case SupervisorMaxProcesses = 'horizon_supervisor_max_processes';
    case SupervisorInfo = 'horizon_supervisor_info';
    case SupervisorQueues = 'horizon_supervisor_queues';
    case WaitThreshold = 'horizon_wait_threshold_seconds';
    case HorizonInfo = 'horizon_info';
    case QueuePendingJobs = 'queue_pending_jobs';
    case QueueDelayedJobs = 'queue_delayed_jobs';
    case QueueDelayedDueJobs = 'queue_delayed_due_jobs';
    case QueueOldestPendingJobAge = 'queue_oldest_pending_job_age_seconds';
    case QueueJobsPending = 'queue_jobs_pending';
    case QueueJobsReserved = 'queue_jobs_reserved';
    case JobsTotal = 'queue_jobs_total';
    case JobDuration = 'queue_job_duration_seconds';
    case JobWait = 'queue_job_wait_seconds';
    case JobDurationMin = 'queue_job_duration_min_seconds';
    case JobDurationMax = 'queue_job_duration_max_seconds';
    case AttemptsDropped = 'exporter_attempts_dropped_total';
    case LivePushTimestamp = 'exporter_live_push_timestamp_seconds';
    case LivePushFailures = 'exporter_live_push_failures_total';
    case JobClasses = 'queue_job_classes';
    case BatchesRunning = 'queue_batches_running';
    case BatchJobs = 'queue_batch_jobs';
    case BatchOldestAge = 'queue_batch_oldest_age_seconds';
    case BatchJobCounts = 'queue_batch_job_counts';
    case BatchCreated = 'queue_batch_created_timestamp_seconds';
    case BatchClosed = 'queue_batch_closed_timestamp_seconds';
    case BatchJobClasses = 'queue_batch_job_classes';
    case BatchInfo = 'queue_batch_info';
    case BatchProgress = 'queue_batch_progress';
    case CollectorSuccess = 'exporter_collector_success';
    case CollectorDuration = 'exporter_collector_duration_seconds';
    case BuildInfo = 'exporter_build_info';
    case ConfigProblems = 'exporter_config_problems';

    /**
     * Collector id of the self-metrics, which the exporter itself produces.
     */
    public const SELF = 'self';

    public function type(): MetricType
    {
        return match ($this) {
            self::JobsTotal, self::AttemptsDropped, self::LivePushFailures => MetricType::Counter,
            self::JobDuration, self::JobWait => MetricType::Histogram,
            default => MetricType::Gauge,
        };
    }

    public function help(): string
    {
        return match ($this) {
            self::HorizonStatus => 'Horizon status: 1 running, 0 paused (at least one master supervisor paused), -1 no master supervisor.',
            self::SupervisorProcesses => 'Worker processes of a Horizon supervisor per queue; balance=false supervisors report their pooled queue list.',
            self::SupervisorMinProcesses => 'Configured minimum worker processes of a Horizon supervisor.',
            self::SupervisorMaxProcesses => 'Configured maximum worker processes of a Horizon supervisor.',
            self::SupervisorInfo => 'Horizon supervisor settings; always 1.',
            self::SupervisorQueues => 'Queues of a Horizon supervisor, one series per queue; pool is the queue label of its process pool in horizon_supervisor_processes (a balance=false pool joins its queues with commas); always 1.',
            self::WaitThreshold => 'Long-wait threshold of a queue from horizon.waits, seconds.',
            self::HorizonInfo => 'Links for the Grafana dashboard: Horizon URL, job-list API path and text-to-speech URL; always 1.',
            self::QueuePendingJobs => 'Jobs ready to run and waiting for a worker, including delayed jobs that are already due.',
            self::QueueDelayedJobs => 'Delayed jobs (dispatched or released with a delay), including due ones; see queue_delayed_due_jobs.',
            self::QueueDelayedDueJobs => 'Delayed jobs whose delay has expired but that have not been moved to the queue yet.',
            self::QueueOldestPendingJobAge => 'Seconds the oldest ready first-attempt job has waited since it became available; 0 when nothing is waiting.',
            self::QueueJobsPending => 'Ready jobs per job class in the scanned head of the queue; (unscanned) counts the rest.',
            self::QueueJobsReserved => 'Jobs currently reserved by workers per job class.',
            self::JobsTotal => 'Finished job attempts by outcome: completed, released, retried, failed, timed_out; a new series reads 0 for its first 90 seconds.',
            self::JobDuration => 'Duration of job attempts, seconds.',
            self::JobWait => 'Seconds a job waited in the queue before its first attempt started.',
            self::JobDurationMin => 'Shortest job attempt during the last 150 seconds, seconds.',
            self::JobDurationMax => 'Longest job attempt during the last 150 seconds, seconds.',
            self::AttemptsDropped => 'Job attempts not recorded because the metrics Redis was unavailable.',
            self::LivePushTimestamp => 'Time of the last successful Grafana Live push of the live overview, Unix seconds.',
            self::LivePushFailures => 'Failed Grafana Live pushes of the live overview.',
            self::JobClasses => 'Queueable job classes found in the application code; always 1.',
            self::BatchesRunning => 'Running job batches per batch kind.',
            self::BatchJobs => 'Jobs of running batches per batch kind and state: total, pending, failed.',
            self::BatchOldestAge => 'Age of the oldest running batch of a kind, seconds.',
            self::BatchJobCounts => 'Jobs of a recent batch per state: total, pending, failed.',
            self::BatchCreated => 'Creation time of a recent batch, Unix seconds.',
            self::BatchClosed => 'Finish or cancel time of a recent batch, Unix seconds.',
            self::BatchJobClasses => 'Queues and job classes seen in batches of a kind during the last 7 days; always 1.',
            self::BatchInfo => 'Queues and connections of a batch kind; always 1.',
            self::BatchProgress => 'Progress of a recent batch, percent of processed jobs.',
            self::CollectorSuccess => 'Whether the last run of a metrics collector succeeded: 1 or 0.',
            self::CollectorDuration => 'Duration of the last run of a metrics collector, seconds.',
            self::BuildInfo => 'Versions of the package, PHP, Laravel, Horizon and the Redis client; always 1.',
            self::ConfigProblems => 'Number of invalid configuration values replaced by defaults; see php artisan horizon-observability:check.',
        };
    }

    /**
     * Label names in exposition order; histograms add `le` to their bucket samples.
     *
     * @return list<string>
     */
    public function labels(): array
    {
        return match ($this) {
            self::HorizonStatus,
            self::AttemptsDropped,
            self::LivePushTimestamp,
            self::LivePushFailures,
            self::ConfigProblems => [],
            self::SupervisorProcesses => ['supervisor', 'queue'],
            self::SupervisorMinProcesses, self::SupervisorMaxProcesses => ['supervisor'],
            self::SupervisorInfo => ['supervisor', 'balance', 'connection'],
            self::SupervisorQueues => ['supervisor', 'queue', 'pool'],
            self::WaitThreshold,
            self::QueuePendingJobs,
            self::QueueDelayedJobs,
            self::QueueDelayedDueJobs,
            self::QueueOldestPendingJobAge,
            self::JobWait => ['connection', 'queue'],
            self::HorizonInfo => ['url', 'api_path', 'tts_url'],
            self::QueueJobsPending,
            self::QueueJobsReserved,
            self::JobDuration,
            self::JobDurationMin,
            self::JobDurationMax => ['connection', 'queue', 'job_class'],
            self::JobsTotal => ['connection', 'queue', 'job_class', 'status'],
            self::JobClasses => ['job_class'],
            self::BatchesRunning, self::BatchOldestAge => ['batch'],
            self::BatchJobs => ['batch', 'state'],
            self::BatchJobCounts => ['batch', 'name', 'state'],
            self::BatchCreated, self::BatchClosed => ['batch', 'name'],
            self::BatchJobClasses => ['batch', 'queue', 'job_class'],
            self::BatchInfo => ['batch', 'queue', 'connection'],
            self::BatchProgress => ['batch', 'name', 'status', 'id'],
            self::CollectorSuccess, self::CollectorDuration => ['collector'],
            self::BuildInfo => ['version', 'php', 'laravel', 'horizon', 'redis_client'],
        };
    }

    /**
     * Id of the collector that produces the family: horizon, queues, attempts, job_classes, batches or self.
     */
    public function collector(): string
    {
        return match ($this) {
            self::HorizonStatus,
            self::SupervisorProcesses,
            self::SupervisorMinProcesses,
            self::SupervisorMaxProcesses,
            self::SupervisorInfo,
            self::SupervisorQueues,
            self::WaitThreshold,
            self::HorizonInfo => 'horizon',
            self::QueuePendingJobs,
            self::QueueDelayedJobs,
            self::QueueDelayedDueJobs,
            self::QueueOldestPendingJobAge,
            self::QueueJobsPending,
            self::QueueJobsReserved => 'queues',
            self::JobsTotal,
            self::JobDuration,
            self::JobWait,
            self::JobDurationMin,
            self::JobDurationMax,
            self::AttemptsDropped,
            self::LivePushTimestamp,
            self::LivePushFailures => 'attempts',
            self::JobClasses => 'job_classes',
            self::BatchesRunning,
            self::BatchJobs,
            self::BatchOldestAge,
            self::BatchJobCounts,
            self::BatchCreated,
            self::BatchClosed,
            self::BatchJobClasses,
            self::BatchInfo,
            self::BatchProgress => 'batches',
            self::CollectorSuccess,
            self::CollectorDuration,
            self::BuildInfo,
            self::ConfigProblems => self::SELF,
        };
    }

    /**
     * Package version that introduced the family.
     */
    public function since(): string
    {
        return '0.1.0';
    }

    /**
     * The families of one collector, in catalog order.
     *
     * @return list<self>
     */
    public static function ofCollector(string $collector): array
    {
        return array_values(array_filter(self::cases(), static fn (self $metric): bool => $metric->collector() === $collector));
    }
}
