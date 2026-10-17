# Exporter truth tests (T16)

Owner decision 13: every exported metric must equal the ground truth, and tests must prove it, so that refactors,
optimisations and dependency updates cannot silently make the numbers lie. This suite runs deterministic scenarios
with known outcomes through real queue workers and compares every family of a real scrape
(`GET /horizon/observability/metrics`) with an independent expectation. The dashboard side is T17.

Run it with the other Redis tests (127.0.0.1:6379, group `redis`; also tagged `truth`) on both clients:

```bash
vendor/bin/pest --group=truth
REDIS_CLIENT=predis vendor/bin/pest --group=truth
```

## How it works

- **Scenario engine** (`Support/Scenario.php`): `TruthJob`s follow a plan of one step per attempt (`complete`,
  `throw`, `fail`, `release:<s>`, `hang`) with a known duration (`sleepMs`). They go through Horizon's Redis queue and
  real `queue:work` workers in the test process, or in a forked worker for a timeout (the worker's own SIGALRM
  handler) and for a job held reserved during a scrape. The clock (Laravel's and CarbonImmutable's) is frozen on whole
  seconds near the real time and moved by the scenario; delays, backoffs, readiness and waits are therefore exact.
  `HorizonProcessTruthTest` runs a real `testbench horizon` with its own supervisor and worker processes.
- **Expected-value model** (`Support/Model.php`): restates the documented semantics (Arch Q4, Q6.2, Q6.3 C8,
  Appendix B), not the package code. It follows every job through the ready list, the delayed set and the reserved
  set, logs every attempt with its outcome, planned duration, start and wait (`max(pushedAt, dispatch + delay)` from
  the stored payload), and computes the expected samples of a scrape at any time, including the 75 s hold of new
  series. Durations are the only real-time values: buckets are exact because planned durations keep
  `Model::OVERHEAD_SECONDS` (0.1 s) away from every bound, and sums lie within the planned total plus 0.1 s per
  attempt.
- **Independent sources**: `Support/RedisState.php` reads the queues with plain `LLEN`/`ZCARD`/`ZCOUNT` and
  `json_decode`; `Support/HorizonTruth.php` restates the horizon families from Horizon's `MasterSupervisorRepository`
  and `SupervisorRepository`; batches come from `Bus::findBatch()`; job classes from PHP reflection; versions from
  Composer's `InstalledVersions`; Horizon's own `MetricsRepository` and `JobRepository` cross-check the counters.
- **Invariants** (`Support/Invariants.php`), checked at every scrape of every scenario: histogram buckets never
  decrease, `count` equals the `+Inf` bucket, sums are finite and 0 without observations; every series has the five
  statuses with whole counts; observations and waits never exceed attempts; ready jobs per class (with
  `(unscanned)`) add up to the pending jobs that are not due delayed ones; due jobs are part of the delayed and the
  pending ones; the oldest wait is 0 exactly when nothing is pending; pool labels have process series; batch progress
  is a percentage; every collector with a success series has a duration; counters never decrease except at a
  documented reset (`ResetTruthTest`).
- **Scrape intervals** (`ScrapeIntervalTruthTest`): ten minutes of steady load scraped every 5, 10 and 60 s in runs of
  their own: every scrape equals the model at its time, the 60 s grid is identical across the intervals (so is
  `increase()` between any two of its points), and every series starts at 0.
- **Coverage gate** (`CoverageTest.php`): fails when a catalog family (`Metrics\Metric`) is missing from the table
  below, when a listed test file does not exist or when it does not reference its family.

## Coverage

| Family | Case | Truth tests |
|---|---|---|
| `horizon_status` | HorizonStatus | HorizonTruthTest, HorizonProcessTruthTest |
| `horizon_supervisor_processes` | SupervisorProcesses | HorizonTruthTest, HorizonProcessTruthTest |
| `horizon_supervisor_min_processes` | SupervisorMinProcesses | HorizonTruthTest |
| `horizon_supervisor_max_processes` | SupervisorMaxProcesses | HorizonTruthTest |
| `horizon_supervisor_info` | SupervisorInfo | HorizonTruthTest |
| `horizon_supervisor_queues` | SupervisorQueues | HorizonTruthTest, HorizonProcessTruthTest |
| `horizon_wait_threshold_seconds` | WaitThreshold | HorizonTruthTest |
| `horizon_info` | HorizonInfo | HorizonTruthTest |
| `queue_pending_jobs` | QueuePendingJobs | QueueStateTruthTest |
| `queue_delayed_jobs` | QueueDelayedJobs | QueueStateTruthTest |
| `queue_delayed_due_jobs` | QueueDelayedDueJobs | QueueStateTruthTest |
| `queue_oldest_pending_job_age_seconds` | QueueOldestPendingJobAge | QueueStateTruthTest |
| `queue_jobs_pending` | QueueJobsPending | QueueStateTruthTest, InvariantsTruthTest |
| `queue_jobs_reserved` | QueueJobsReserved | QueueStateTruthTest, InvariantsTruthTest |
| `queue_jobs_total` | JobsTotal | AttemptsTruthTest, FirstValueTruthTest, ScrapeIntervalTruthTest, ResetTruthTest, ExclusionTruthTest, HorizonCrossCheckTruthTest, HorizonProcessTruthTest |
| `queue_job_duration_seconds` | JobDuration | AttemptsTruthTest, FirstValueTruthTest, ScrapeIntervalTruthTest |
| `queue_job_wait_seconds` | JobWait | AttemptsTruthTest, FirstValueTruthTest, ScrapeIntervalTruthTest |
| `queue_job_duration_min_seconds` | JobDurationMin | AttemptsTruthTest |
| `queue_job_duration_max_seconds` | JobDurationMax | AttemptsTruthTest |
| `exporter_attempts_dropped_total` | AttemptsDropped | SelfMetricsTruthTest |
| `exporter_live_push_timestamp_seconds` | LivePushTimestamp | SelfMetricsTruthTest |
| `exporter_live_push_failures_total` | LivePushFailures | SelfMetricsTruthTest |
| `queue_job_classes` | JobClasses | JobClassesTruthTest |
| `queue_batches_running` | BatchesRunning | BatchesTruthTest |
| `queue_batch_jobs` | BatchJobs | BatchesTruthTest |
| `queue_batch_oldest_age_seconds` | BatchOldestAge | BatchesTruthTest |
| `queue_batch_job_counts` | BatchJobCounts | BatchesTruthTest |
| `queue_batch_created_timestamp_seconds` | BatchCreated | BatchesTruthTest |
| `queue_batch_closed_timestamp_seconds` | BatchClosed | BatchesTruthTest |
| `queue_batch_job_classes` | BatchJobClasses | BatchesTruthTest |
| `queue_batch_info` | BatchInfo | BatchesTruthTest |
| `queue_batch_progress` | BatchProgress | BatchesTruthTest |
| `exporter_collector_success` | CollectorSuccess | SelfMetricsTruthTest |
| `exporter_collector_duration_seconds` | CollectorDuration | SelfMetricsTruthTest |
| `exporter_build_info` | BuildInfo | SelfMetricsTruthTest |
| `exporter_config_problems` | ConfigProblems | SelfMetricsTruthTest |

Also covered: sync, deferred, background and database-queue jobs are never counted (`ExclusionTruthTest`); a Redis
restart with and without the old dataset and a restored dataset reset the counters, documented by `meta.reset_at`
and one info line (`ResetTruthTest`; the real restart needs a `redis-server` binary and is skipped without one).

## Decisions taken with this suite (T13 QA round 1, the pilot's honesty bugs)

### 1. The first value of a new counter series belongs to the exporter

The pilot's Completed and Failed tiles dropped the first value of a new series: Prometheus cannot count the first
sample of a series, so `increase()` and the dashboard's exact differences start from it. Evidence on Prometheus
v3.15.0 (`probes/first-value`, scrape interval 5 s, a new class born with 3 completed attempts, 5 at the end of the
window; truth 5):

| | `increase()` | dashboard `delta()` | dashboard counting the first value |
|---|---|---|---|
| without the fix | 2.05 | **0** | 5 |
| a scrape job added later (old totals 102, truth 0) | 0 | 0 | **102** |
| with the exporter's hold | 5.12 | **5** | 5 |
| the late scrape job, with the hold | 0 | **0** | 102 |

The dashboard cannot tell a series born inside the window (count its first value) from a series it simply saw for
the first time (a scrape job or a replica added later, a Prometheus outage longer than the lookback: never count it).
Only the exporter knows which series are new, so the fix is in the exporter: `record_attempt.lua` writes the creation
time of a (connection, queue, job_class) with its first attempt and of a queue with its first wait (hash `created`,
only for series without counts), and `AttemptsCollector` exports a series created less than 75 s ago
(`NEW_SERIES_HOLD_SECONDS`: the longest supported scrape interval plus 15 s for jitter and clock skew) as 0. Every
scraper records a 0 first and then counts the attempts in full, with Prometheus' own `increase()` as well as with the
dashboard's queries, which stay as they are. The cost: the first attempts of a new series show up to 75 s late
(the live overview's ring buffer is not delayed). Series without a creation time are exported as they are, so old
totals never count as new. `probes/first-value/run.sh` repeats the probe with the real exporter and real workers of
two checkouts, the integration branch before the fix and this one (3 attempts of a new class, then 2; truth 5):
before `increase()` 2.10 and `delta()` 2, after 5.06 and 5 (`probes/first-value/results-real.txt`).

### 2. balance=false pooled queues need the exporter's queue map

`horizon_supervisor_processes` keeps Horizon's pool label (`queue="mail,sms"`, Arch Q4), which no per-queue series
matches, and PromQL cannot split a label value into several series (a bounded `label_replace` unroll would multiply
every use and silently drop the queues beyond its bound). The pooled queues therefore had no row in the queue table
#5, no processes and no busy workers (`probes/pooled-queues/results.txt`). The exporter knows the queue list of every
supervisor, so it now exports `horizon_supervisor_queues{supervisor, queue, pool}` (always 1): every queue a
supervisor works, with the pool label of its processes. On the same data the #5 rows, the processes per queue and
the busy workers come out right:

- rows: `group by (queue, supervisor) (last_over_time(ho_horizon_supervisor_queues[1d]))` instead of the same on
  `ho_horizon_supervisor_processes`;
- processes per queue: `sum by (queue) (ho_horizon_supervisor_queues * on (supervisor, pool) group_left ()
  label_replace(ho_horizon_supervisor_processes, "pool", "$1", "queue", "(.*)"))`;
- the supervisor of each queue (utilisation, Supervisor filter): `group by (supervisor, queue)
  (ho_horizon_supervisor_queues)`.

The dashboard change is T13's (`src/Grafana/Panels/Queues.php` `$supervised`, `Queries/Workers::supervisorQueues()`,
`queueBusy()`, `processesValue()`, `Variables`); T17 proves it.

### Known limits

- `exporter_live_push_failures_total` appears with the first successful push (Add §B′), so failures before it are
  part of its first value.
- A bucket added to `buckets` starts a new `le` series whose first value is not counted.
- In a local worktree whose `vendor/orchestra/testbench-core/laravel/vendor` link points at another checkout,
  Horizon's processes in `HorizonProcessTruthTest` run that checkout's code; CI has a single checkout.
