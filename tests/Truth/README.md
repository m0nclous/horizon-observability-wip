# Exporter truth tests

Every exported metric must equal the ground truth, and tests must prove it, so that refactors, optimisations and
dependency updates cannot silently make the numbers lie. This suite runs deterministic scenarios with known outcomes
through real queue workers and compares every family of a real scrape (`GET /horizon/observability/metrics`) with an
independent expectation. The dashboard side (the rendered panel queries evaluated through a real Prometheus and
Grafana) is not covered here yet (known issue #35).

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
- **Expected-value model** (`Support/Model.php`): restates the documented semantics (the families, labels and
  statuses of the main README's metric tables), not the package code. It follows every job through the ready list,
  the delayed set and the reserved set, logs every attempt with its outcome, planned duration, start and wait
  (`max(pushedAt, dispatch + delay)` from the stored payload), and computes the expected samples of a scrape at any
  time, including the 90 s hold of new series. Durations are the only real-time values: buckets are exact because
  planned durations keep `Model::OVERHEAD_SECONDS` (0.2 s) away from every bound, and sums lie within the planned
  total plus 0.2 s per attempt.
- **Independent sources**: `Support/RedisState.php` reads the queues with plain `LLEN`/`ZCARD`/`ZCOUNT` and
  `json_decode`; `Support/HorizonTruth.php` restates the horizon families from Horizon's `MasterSupervisorRepository`
  and `SupervisorRepository`; batches come from `Bus::findBatch()`; job classes from PHP reflection; versions from
  Composer's `InstalledVersions`; Horizon's own `MetricsRepository` and `JobRepository` cross-check the counters.
- **Invariants** (`Support/Invariants.php`), asserted by `Scenario::scrape()` at every scrape of every scenario (the
  tests that drive the attempts collector directly through the `Storage` fixture check what they state):
  histogram buckets never decrease, `count` equals the `+Inf` bucket, sums are finite and 0 without observations;
  every series has the five statuses with whole counts; a series has at most as many duration observations as
  attempts, and a held new series (all five statuses 0) has none; a queue has at most as many waits as attempts
  unless one of its series is held (the lag between the families, decision 1 below); ready jobs per class (with
  `(unscanned)`) add up to the pending jobs that are not due delayed ones; due jobs are part of the delayed and the
  pending ones; the oldest wait is not negative and 0 when nothing is pending (it may be 0 with pending jobs: a job
  pushed at or after the scrape time by the exporter's clock, e.g. from a dispatcher whose clock is ahead); pool
  labels have process series; batch progress is a percentage; every collector with a success series has a
  duration; and from one scrape to the next of a scenario, no counter sample (the counter families, every bucket,
  count and sum) decreases unless `meta.reset_at` changed in between, the documented reset (`ResetTruthTest`). A
  series that disappears starts again from its next value. `InvariantsTruthTest` puts them under pressure.
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
and one info line (`ResetTruthTest`). The real restart runs a private Redis from a `redis-server` binary or, without
one, from a Docker image (`TRUTH_REDIS_IMAGE`, default `redis:7.4-alpine`; CI passes the server image of each leg); it
is skipped only outside CI when neither exists, and fails on CI.

## Decisions taken with this suite (the pilot's honesty bugs)

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

The dashboard cannot tell a series born inside the window (count its first value) from a series it simply saw for the
first time (a scrape job or a replica added later, a Prometheus outage longer than the lookback: never count it).
Only the exporter knows which series are new, so the fix is in the exporter: `record_attempt.lua` writes the creation
time of a (connection, queue, job_class) with its first attempt and of a queue with its first wait (hash `created`,
only for series without counts), and `AttemptsCollector` exports a series created less than 90 s ago as 0. Every
scraper records a 0 first and then counts the attempts in full, with Prometheus' own `increase()` as well as with the
dashboard's queries, which stay as they are. Series without a creation time are exported as they are, so old totals
never count as new. `probes/first-value/run.sh` repeats the probe with the real exporter and real workers of two
checkouts, one before the fix and one with it (3 attempts of a new class, then 2; truth 5): before `increase()` 2.10
and `delta()` 2, after 5.06 and 5 (`probes/first-value/results-real.txt`). Both probes ran with the first hold of
75 s; its length does not change their results, which need only one 0 sample per scraper.

**One clock: the storage Redis'**. The creation time and the age are both read from the storage Redis' `TIME`, inside
`record_attempt.lua` (only when it writes a creation time) and inside `new_series.lua`, which the collector runs once
per scrape right after the counters. No worker, exporter replica or scraper clock enters the hold, so every replica
behind the one scrape target makes the same decision, and a worker clock ahead or behind changes nothing.
`new_series.lua` also deletes the creation time of every series it releases, so a released series is never held
again, whatever any clock does later (a step back of the Redis clock, a replica that answers later): an exported
counter never drops back to 0. Before, the hold compared the worker's clock with the clock of whichever replica
answered: two replicas 15 s apart exported 0, 3, 0, 3 at the end of the hold, which Prometheus v3.15.0 took for a
reset (`increase()` 6.02 for 3 attempts, `resets()` 1; `probes/replica-clocks/results.txt`), and a worker clock more
than 90 s ahead dropped an exported counter back to 0 for 180 s. With the real exporter of both checkouts
(`probes/replica-clocks/run-real.sh`: two replicas 15 s apart behind one target, a worker 120 s ahead, 3 attempts of
a new class, truth 3) Prometheus v3.15.0 counted `increase()` 9.18 with `resets()` 3 before and 3.01 with `resets()`
0 after, the samples 0 until 90 s after the attempts and 3 from then on (`probes/replica-clocks/results-real.txt`).
Tests pin the server clock (`Redis\ServerClock`, Storage's `server:`, the scenario clock); the production path
(`TIME`, nothing pinned) has its own cases in `FirstValueTruthTest`, on the cluster leg (`ClusterStorageTest`) and on
a Redis 6.2 that replicates scripts verbatim.

The hold (`NEW_SERIES_HOLD_SECONDS`) is sized for a stated budget on that clock. A scraper whose last scrape before
the creation read the counters at r sees the series first at its next successful scrape and exports it as 0 if that
scrape's hold read comes less than 90 s after the creation, which is certain when it comes less than 90 s after r:
the successful scrapes of one scraper must read the counters less than 90 s apart. That covers every supported
interval (S <= 60 s) with 30 s to spare: scrapes less than 15 s off their slots at S = 60 s (twice the 7.5 s
deviation within which every duration extreme still reaches two scrapes at S = 60 s), or up to 7 failed scrapes in a
row at S = 10 s. `FirstValueTruthTest` checks the schedules of 5, 10, 15, 30, 45 and 60 s with slots at, between and
15 s after the creation, deviations of ±(15 s - 1 ms) in four patterns (including the worst: the read before the
creation 15 s early, 1 ms before it, and the next one 15 s late), each with a worker clock an hour behind, in sync
and an hour ahead and two replicas answering in turn with clocks up to an hour off the Redis clock (up to two hours
apart); the worst case (89.999 s) is held by 1 ms. Outside the budget (a slower interval, longer gaps between
successful scrapes, a step of the Redis clock) a new series can start without its 0 sample, as without the hold (an
undercount of its first attempts, never a decrease). A creation time ahead of the Redis clock (only after the clock
stepped back) is held until the clock is 90 s past it, and released at once when it is 90 s or more ahead.

Redis requirements: `TIME`, `INFO` and `HGETALL` are non-deterministic and followed by writes in these scripts, so
they ask for effects replication first (`redis.replicate_commands()`): the default since Redis 5, the only mode since
Redis 7 and in Valkey (where the call is a no-op), and needed on Redis 6.2 configured with
`lua-replicate-commands no`. On a Redis Cluster the scripts run on the node of the package's slot and use its clock; a
failover or a move to another node changes the run id, and the reset guard then deletes the counters and the
creation times together, so creation times of two clocks never meet. A server that refuses `TIME` (an ACL without
`@fast`) still records every attempt, without a creation time (its series is not held); the scrape then marks the
attempts collector as failed (`attempts:hold`, so the problem is visible) and holds the series that still have a
creation time without releasing any, so nothing exported decreases. Allowing `TIME` (`+time`) restores the hold.

The cost (the first three bounded by the hold):
- the first attempts of a new (connection, queue, job_class), and the first waits of a new queue, show up to 90 s
  late (the live overview's ring buffer is not delayed);
- they are counted when the hold ends, so a range that starts inside a hold also counts that series' attempts from
  before the range, and a range that ends inside it misses them;
- the families lag each other while a series is held: a new class on a known queue has its attempts and durations
  held, but the queue's wait histogram (an existing series) counts its first waits at once, so for up to 90 s that
  queue exports more waits than attempts (the waits invariant above allows that only while a series of the queue is
  held);
- in Redis: one `new_series.lua` `EVAL` per scrape in place of the `HGETALL created` before it (the same 10 round
  trips): `TIME`, `HGETALL` of the creation times (only series younger than 90 s stay there) and `HDEL`s of the
  released ones; one `TIME` in `record_attempt.lua` for the first attempt of a new series or the first wait of a new
  queue.

### 2. balance=false pooled queues need the exporter's queue map

`horizon_supervisor_processes` keeps Horizon's pool label (`queue="mail,sms"`), which no per-queue series matches,
and PromQL cannot split a label value into several series (a bounded `label_replace` unroll would multiply every use
and silently drop the queues beyond its bound). The pooled queues therefore had no row in the queue table #5, no
processes and no busy workers (`probes/pooled-queues/results.txt`). The exporter knows the queue list of every
supervisor, so it now exports `horizon_supervisor_queues{supervisor, queue, pool}` (always 1): every queue a
supervisor works, with the pool label of its processes. On the same data the #5 rows, the processes per queue and the
busy workers come out right:

- rows: `group by (queue, supervisor) (last_over_time(ho_horizon_supervisor_queues[1d]))` instead of the same on
  `ho_horizon_supervisor_processes`;
- processes per queue: `sum by (queue) (ho_horizon_supervisor_queues * on (supervisor, pool) group_left ()
  label_replace(ho_horizon_supervisor_processes, "pool", "$1", "queue", "(.*)"))`;
- the supervisor of each queue (utilisation, Supervisor filter): `group by (supervisor, queue)
  (ho_horizon_supervisor_queues)`.

While the masters disagree on a supervisor's options (a rolling deploy that changes a balance=false queue list or the
balance), both masters' pools work the common queues: the map then has a series for every pool that works a queue
(`mail` in `mail,sms` and in `mail,sms,push`), so the processes per queue add up over both (before a fix, only the
first master's pool was mapped and `mail` read 2 processes instead of 5).

A queue option with spaces keeps Horizon's pool labels as well: Horizon splits the option of a balancing supervisor
at the commas without trimming (`'mail, sms'`), so the pool of `sms` and its processes are labelled ` sms`, and the
map pairs the queue `sms` with that pool. A pool label longer than 200 bytes is cut with the hash of the whole label
(`Labels::sanitize()`), so a long balance=false list before and after a deploy that adds a queue at its end stays two
label values instead of one label set twice, which would drop the supervisor families.

The dashboard joins through the map (#32): the Supervisor Processes tile, the queue tables (rows, processes,
utilisation), the Queue filter and the queue table titles count a balance=false pool for each of its queues
(`tests/Grafana/SupervisorProcessesTest.php`).

### Known limits

- `exporter_live_push_failures_total` appears with the first successful push (it is exported only once
  `meta.live_push_at` is set), so failures before it are part of its first value.
- A bucket added to `buckets` starts a new `le` series whose first value is not counted.
- In a local worktree whose `vendor/orchestra/testbench-core/laravel/vendor` link points at another checkout,
  Horizon's processes in `HorizonProcessTruthTest` run that checkout's code; CI has a single checkout.
