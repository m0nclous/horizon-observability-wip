# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [0.1.0] - Unreleased

The first public release.

### Added

- Support for PHP 8.2+, Laravel 11.23 to 13 and Horizon 5.29.1+ (Horizon 5.22.0 to 5.29.0 are a fatal error on
  Laravel 11.27.0 and later: `RedisQueue::pop()` signature), with phpredis or predis. The runtime dependencies are
  PHP, Laravel and Horizon only.
- `config/horizon-observability.php` with the `HORIZON_OBSERVABILITY_*` environment variables, validated by a typed
  snapshot that replaces invalid values by defaults and reports them (security values fail closed).
- Routes `GET {horizon.path}/observability/metrics`, `/jobs/{pending|completed|failed|delayed}` and `/live`, registered
  ahead of Horizon's catch-all route in either provider order and with cached routes.
- Access control: bearer tokens (several, for rotation), a separate `api_token` for the job lists and `/live`, an
  IP allow-list, the `HorizonObservability::auth()` callback; open in `local` only when no token is set. Denied
  requests do no cache, database or Redis work. The endpoints stay reachable during maintenance mode.
- `HorizonObservability::auth()` and `HorizonObservability::resolveBatchKindUsing()` hooks.
- The metric catalog with the default namespace `ho` (`HORIZON_OBSERVABILITY_NAMESPACE`; `ho_queue_jobs_total`,
  `ho_exporter_collector_success`, ...).
- Bounded labels: attempt job classes are capped by `max_job_classes` (500), pending and reserved classes at 100 per
  queue, batch kinds at 100, and the rest is `(other)`; the other sentinel values are `(unscanned)` and `(unknown)`.
  Label values longer than 200 bytes are cut and end with `...` and the CRC32 of the whole value, so two long values
  with the same start stay apart.
- The metrics endpoint for a separate Prometheus job (10 s recommended): Prometheus text format 0.0.4 with real
  histograms, collectors isolated from each other (a failing source never turns the scrape into a 5xx; only its
  families are missing, with `exporter_collector_success` 0) within a budget taken from
  `X-Prometheus-Scrape-Timeout-Seconds`, and the `ho_exporter_*` self-metrics.
- Job attempts of queue workers (not sync, deferred or background jobs) recorded with one Redis `EVAL` per attempt on
  a private short-timeout client behind a circuit breaker, so a slow or unreachable Redis does not hold up jobs:
  counters, duration and wait histograms, duration extremes of the last 150 s, a counter reset guard and the live
  ring buffer; the legacy `ready_at` side channel only on stacks that need it. A new attempt series and a new queue's
  wait histogram read 0 for their first 90 seconds, so that Prometheus counts their first attempts. The keys live on
  Horizon's `horizon` connection under `{horizon-observability}:v1:`. The Lua scripts go to Redis without their
  comments: about 4.2 KB per job end, and 42 KB per scrape of five queues.
- Horizon status, supervisors, wait thresholds and links, and the queue state (pending by class, oldest wait,
  reserved, delayed) read with bounded Lua scans, also on Redis Cluster. `queue_oldest_pending_job_age_seconds` is
  measured for the oldest first-attempt job from when it became available (`max(pushedAt, createdAt + delay)`), so
  dispatch delays and earlier attempts raise no long-wait alert.
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
  `--datasource-uid`, `--live=off|poll|stream`, `--live-poll-interval`, `--live-stream-id`, `--down-for`,
  `--health-rules`, `--recording-rules`, `--prometheus-datasource`, `--infinity-datasource`, `--defaults`. The package
  render is committed in `resources/grafana` and published with `vendor:publish --tag=horizon-observability-grafana`.
- The "Queues" dashboard for Grafana 12.3+ (verified on 12.4.13 and 13.2.4): every query is scoped to the Prometheus
  job of the package endpoint (hidden `prometheus_job` variable), deduplicates replicas, takes the exact period edges
  and derives its windows from the scrape interval; a refresh sends at most 12 requests.
- Several applications in one Grafana: every render carries the tag `horizon-observability` and an "Apps" dropdown of
  the dashboards with that tag, so the per-application dashboards (`--uid`, `--job`, `--title`,
  `--infinity-datasource`) link to each other; the README describes the setup.
- The Live Overview row: its live tiles (Supervisor Processes, Pending, Delayed, the RPM caption of Completed and the
  status of the Horizon tile) read the live endpoint at every dashboard refresh (5 s) with `--live=poll` (the default
  render); `--live=stream` updates them every second from Grafana Live; `--live=off` reads every tile from
  Prometheus. The columns of now in the Current Workload tables (Utilization, Processes, Pending, Delayed and Oldest
  wait) follow the same mode through the `workload` rows of the snapshot, so they agree with the tiles; the totals of
  the period stay Prometheus queries.
- Alert rules "No queue metrics", "Horizon is down" (after `--down-for`, default 5 minutes), "Queue wait over 2x
  limit" and "High failure rate" (uids `<uid>-no-metrics`, `-down`, `-long-wait`, `-failures`), "Live overview
  stalled" in stream mode, and the opt-in health rules for a failing collector and duplicate exporters; they
  deduplicate replicas like the dashboard. The Alerts tile counts the pending and firing rules at the end of the
  range: red while one fires, yellow while one is pending and for 15 minutes after a resolved alert last fired
  ("Stable in Nm"), green otherwise. The dashboard sounds an alarm and speaks the firing rules, through a Piper server
  or the browser's own speech synthesis (`tts_url=browser`).
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
