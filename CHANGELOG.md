# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

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
  buffer; the legacy `ready_at` side channel only on stacks that need it.
- Horizon status, supervisors, wait thresholds and links, and the queue state (pending by class, oldest wait,
  reserved, delayed) read with bounded Lua scans, also on Redis Cluster.
- The job lists (`pending`, `completed`, `failed`, `delayed`) with bounded scans, the `exceptions` mode and 422
  validation.
- Batch metrics with two bounded queries, batch-table detection in auto mode and batch kinds.
- Job-class discovery that never includes non-class files, with a `bootstrap/cache` file and the
  `horizon-observability:cache` / `:clear` commands (hooked into `optimize` on Laravel 11.27.1+).
- The "Grafana" link in Horizon's sidebar, right after "Dashboard", when a Grafana URL is configured.

### Changed

- Rewrite of the internal `sushi-market/horizon-prometheus-grafana` pilot as the public
  `sushi-market/horizon-observability` package (namespace `DF\HorizonObservability`).
- Requires `laravel/horizon` ^5.29.1: Horizon 5.22.0 to 5.29.0 are a fatal error on Laravel 11.27.0 and later
  (`RedisQueue::pop()` signature).
