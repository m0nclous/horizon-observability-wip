# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - Unreleased

The first public release: a rewrite of the internal pilot `sushi-market/horizon-prometheus-grafana`. "Changed",
"Removed" and "Fixed" compare with the pilot; `UPGRADE.md` describes the migration.

### Added

- Several applications in one Grafana: every render carries the tag `horizon-observability` and an "Apps" dropdown of
  the dashboards with that tag, so the per-application dashboards (`--uid`, `--job`, `--title`,
  `--infinity-datasource`) link to each other; the README describes the setup.
- `horizon-observability:grafana --down-for=<duration>`: how long Horizon must be down before "Horizon is down" fires
  (default 5 minutes, as before).
- Live columns of now in the Current Workload tables (poll and stream renders): Utilization, Processes, Pending,
  Delayed and Oldest wait read the new `workload` rows of the `/live` snapshot, so they agree with the Live Overview
  tiles instead of the last scrape; the totals of the period stay Prometheus queries.
- `config/horizon-observability.php` with the `HORIZON_OBSERVABILITY_*` environment variables, validated by a typed
  snapshot that replaces invalid values by defaults and reports them (security values fail closed).
- Routes `GET {horizon.path}/observability/metrics`, `/jobs/{pending|completed|failed|delayed}` and `/live`, registered
  ahead of Horizon's catch-all route in either provider order and with cached routes.
- `HorizonObservability::auth()` and `HorizonObservability::resolveBatchKindUsing()` hooks.
- The metric catalog with the default namespace `ho` (`ho_queue_jobs_total`, `ho_exporter_collector_success`, ...).
- The metrics endpoint: Prometheus text format 0.0.4 with real histograms, collectors isolated from each other
  (a failing source never turns the scrape into a 5xx) within a budget taken from
  `X-Prometheus-Scrape-Timeout-Seconds`, and the `ho_exporter_*` self-metrics.
- Access control: bearer tokens (several, for rotation), a separate `api_token` for the job lists and `/live`, an
  IP allow-list, the `HorizonObservability::auth()` callback; open in `local` only when no token is set. Denied
  requests do no cache, database or Redis work. The endpoints stay reachable during maintenance mode.
- Job attempts recorded with one Redis `EVAL` per attempt on a private short-timeout client behind a circuit
  breaker: counters, duration and wait histograms, duration extremes, a counter reset guard and the live ring
  buffer; the legacy `ready_at` side channel only on stacks that need it. The Lua scripts go to Redis without their
  comments: about 4.2 KB per job end instead of 9.6 KB, and 42 KB instead of 74 KB per scrape of five queues.
- Horizon status, supervisors, wait thresholds and links, and the queue state (pending by class, oldest wait,
  reserved, delayed) read with bounded Lua scans, also on Redis Cluster.
- `horizon_supervisor_queues{supervisor, queue, pool}`: the queues of every supervisor, so that `balance => false`
  pools can be joined with the per-queue families.
- The job lists (`pending`, `completed`, `failed`, `delayed`) with bounded scans, the `exceptions` mode and 422
  validation.
- Batch metrics with two bounded queries, batch-table detection in auto mode and batch kinds.
- Job-class discovery that never includes non-class files, with a `bootstrap/cache` file and the
  `horizon-observability:cache` / `:clear` commands (hooked into `optimize` on Laravel 11.27.1+).
- The "Grafana" link in Horizon's sidebar, right after "Dashboard", when a Grafana URL is configured.
- The live snapshot endpoint `{base}/live` (JSON schema v1, shared by all viewers for `live.cache_ttl` seconds) and
  `php artisan horizon-observability:stream` (`--once`, `--max-time`, `--memory`), which pushes the snapshot to
  Grafana Live every `live.stream.interval` seconds (1 by default); any number of replicas can run it, one leads
  through a Redis lock.
- `php artisan horizon-observability:grafana [path]`: renders the "Queues" dashboard and its Grafana alert rules from
  the application's config into `{path}/dashboards/horizon-observability.json` and
  `{path}/alerting/horizon-observability.json`; `--check` exits 1 when the files differ from the render (for CI).
  Options: `--uid`, `--title`, `--job`, `--scrape-interval` (5 s to 60 s, default 10 s), `--folder`,
  `--datasource-uid`, `--live=off|poll|stream`, `--live-poll-interval`, `--live-stream-id`, `--health-rules`,
  `--recording-rules`, `--prometheus-datasource`, `--infinity-datasource`, `--defaults`. The package render is
  committed in `resources/grafana` and published with `vendor:publish --tag=horizon-observability-grafana`.
- The dashboard keeps the pilot's panels and look on Grafana 12.3+ (verified on 12.4.13 and 13.2.4): every query is
  scoped to the Prometheus job of the package endpoint (hidden `prometheus_job` variable), deduplicates replicas and
  derives its windows from the scrape interval; a refresh sends at most 12 requests.
- The Live Overview row: its live tiles (Supervisor Processes, Pending, Delayed and the RPM caption of Completed) read
  the live endpoint at every dashboard refresh (5 s) with `--live=poll` (the default render); `--live=stream` updates
  the first three every second from Grafana Live; `--live=off` reads every tile from Prometheus.
- Alert rules "No queue metrics", "Horizon is down", "Queue wait over 2x limit" and "High failure rate"
  (uids `<uid>-no-metrics`, `-down`, `-long-wait`, `-failures`), "Live overview stalled" in stream mode, and the
  opt-in health rules for a failing collector and duplicate exporters. The dashboard sounds an alarm and speaks the
  firing rules, through a Piper server or the browser's own speech synthesis (`tts_url=browser`).
- Optional Prometheus recording rules (`--recording-rules`), and provisioning examples for the Infinity datasource
  with a bearer token, the dashboard provider and Grafana's alert state history (`resources/grafana/provisioning`).
- `php artisan horizon-observability:install`: publishes the config, writes a token to `.env` (never over an
  existing value), prints the Prometheus scrape job (10 s), the Grafana steps and the render command of each live
  mode, and checks the setup; `--docker` writes a local Prometheus and Grafana stack preprovisioned with the
  dashboard, the alert rules, the datasources and the plugins (`docker compose -f docker-compose.observability.yml
  up -d`); `--demo` dispatches sample jobs. Running it again changes nothing.
- `php artisan horizon-observability:demo`: a short burst of sample jobs (completed, failed, retried, released,
  delayed, a batch) so an empty application shows data; refuses in production.
- `php artisan horizon-observability:check` (`--json`, `--strict`, `--live-push-test`): configuration, access,
  route precedence, Redis connections and timeouts, Horizon, batches, job classes, a dry run of every collector and
  the live stream settings, with one fix line per problem; exits 1 on errors for deploy pipelines.
- `php artisan horizon-observability:flush`: deletes exactly the package's Redis keys, live data included.
- A "Horizon Observability" section in `php artisan about`.

### Changed

- Rewrite of the internal `sushi-market/horizon-prometheus-grafana` pilot as the public
  `sushi-market/horizon-observability` package (namespace `DF\HorizonObservability`), under the MIT license.
- Supports PHP 8.2+, Laravel 11.23 to 13, phpredis and predis (the pilot: Laravel 12).
- Requires `laravel/horizon` ^5.29.1: Horizon 5.22.0 to 5.29.0 are a fatal error on Laravel 11.27.0 and later
  (`RedisQueue::pop()` signature).
- The metrics have their own endpoint and namespace (`HORIZON_OBSERVABILITY_NAMESPACE`, default `ho`) instead of
  spatie/laravel-prometheus' `/metrics` and `prometheus.default_namespace`; `HORIZON_OBSERVABILITY_NAMESPACE=office`
  keeps the pilot's metric names.
- The job lists moved from `/metrics/horizon/{list}-jobs` to `{base}/jobs/{list}`; their fields are unchanged.
- Without a token, IPs or an auth callback the endpoints answer only in `local` (the pilot was open while
  `PROMETHEUS_ALLOWED_IPS` was empty).
- `queue_oldest_pending_job_age` is now `queue_oldest_pending_job_age_seconds`, measured for the oldest first-attempt
  job from when it became available (`max(pushedAt, createdAt + delay)`).
- The duration and wait histograms are exported with `TYPE histogram` (the pilot declared three counter families
  each); the series are unchanged.
- `queue_job_duration_min_seconds` and `_max_seconds` cover the last 150 s in 15-second buckets instead of the current
  and the previous minute.
- A new attempt series (connection, queue and job class) and a new queue's wait histogram read 0 for their first
  90 seconds, on the storage Redis' clock, so that Prometheus and the dashboard count their first attempts.
- English sentinel label values: `(unscanned)`, `(unknown)` and the overflow value `(other)`; a closure's job class
  drops its line number (`Closure (routes/web.php)`).
- Label values longer than 200 bytes are cut and end with `...` and the CRC32 of the whole value, so two long values
  with the same start (a long `balance => false` queue list before and after a deploy that adds a queue) stay apart
  instead of dropping the supervisor families as a duplicate label set.
- The package's keys live on Horizon's `horizon` connection under `{horizon-observability}:v1:`; the pilot's
  `prometheus:queue:*` keys on the `default` connection are never read.
- Recommended scrape: a separate Prometheus job for the package endpoint every 10 s (the pilot: 15 s on the
  application's job).

### Removed

- The dependencies on `spatie/laravel-prometheus` and `spatie/laravel-data`, and the registration of spatie's
  `HorizonStatusCollector`.
- `horizon_current_processes` (use `sum by (queue) (horizon_supervisor_processes)`), and the `size` and `created`
  labels of `queue_batch_progress`.
- `config/horizon-prometheus-grafana.php`, the `HPG_*` environment variables and the
  `/metrics/horizon/{pending,completed,failed,delayed}-jobs` routes.
- The Python dashboard generator, replaced by `php artisan horizon-observability:grafana`, and the `office` tag of
  the dashboard.
- The `queue-metrics:load-test` command and its job; the load generator is development tooling and is not shipped.

### Fixed

- The Horizon tile stayed red for up to a graph step after Horizon came back (minutes on long ranges) and counted the
  time before the first scrape as down. It now shows the current status, in poll and stream renders the live one,
  and its Uptime counts only the time with metrics.
- `balance => false` pools were missing from the off-mode Supervisor Processes tile, from the queue tables (rows,
  processes and utilisation; the utilisation of such a pool always read 0), from the Queue filter of their supervisor
  and from the queue table titles under Queue = All: the dashboard joins through `horizon_supervisor_queues`.
- Under a Job or Batch filter, Supervisor Processes showed a dash, or left a supervisor out, where no worker ran the
  selected classes; it shows 0 for every supervisor, in every live mode.
- A missing batches table, a non-database batching driver, a database error or an unreachable Horizon Redis turned
  the whole metrics response into a 500; now only the failing collector's families are missing, with
  `exporter_collector_success` 0.
- With predis, job attempts were not recorded at all.
- With a persistent spatie cache, the counters grew on every scrape.
- The job lists were open by default, exposed exception texts and let any client scan up to 10,000 full payloads per
  request, even for a filter that matches nothing.
- Sync, deferred and background jobs were recorded from the web process.
- Every scraped application replica multiplied the sums on the dashboard and in the alert rules.
- The oldest wait counted dispatch delays and earlier attempts, which raised false "Queue wait over 2x limit" alerts;
  retried jobs that had been delayed recorded a wait of 0.
- Label cardinality was unbounded: attempt job classes are capped by `max_job_classes` (500), pending and reserved
  classes at 100 per queue, batch kinds at 100, and the rest is `(other)`.
- Redis Cluster was not supported.
- A slow or unreachable metrics Redis held up every job and every delayed dispatch, and flooded the logs.
- The Supervisor filter of the job lists matched nothing for `balance => false` supervisors.
- The batch collector loaded every unfinished batch on every scrape.
- The Horizon menu link could strip Horizon's `web` middleware.
- Metric names collided with spatie's own queue and Horizon collectors.
- A timed-out job could be counted twice, and the timeout handler could flush the job's half-built Redis pipeline.
- The first scrape after the first deploy wiped the attempt counters.
- Job-class discovery scanned `app/` and loaded its files on every scrape.
- The Completed and Failed tiles dropped the first value of a new counter series.
- The Completed and Failed tiles and the per-job graph took the period edges rounded to whole seconds: they could
  disagree with the tables, lose the last step in a browser time zone that is not a whole hour from UTC (UTC+5:45 at
  a 2-minute step), and the graph could count attempts from before the period.
- With a Batch filter, the tables counted the attempts of a class of another kind that stopped inside the period, and
  Queue = All left out the queues of an application that stopped inside it.
- A shared link with a job class or batch kind that holds a backslash and matches no option turned the panels with
  the filter's All test into query errors.
- The duration minimum and maximum could miss attempts with 60-second scrapes.
