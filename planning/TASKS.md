# Tasks

Status values: `todo`, `in progress`, `done`. Update this file in the same commit as the work.
"Arch §X" = section of [ARCHITECTURE.md](ARCHITECTURE.md); "Add §X" = section of [ADDENDUM.md](ADDENDUM.md).
WP ids are the work packages of Arch Q9; read Arch Q9 "Rules for all work packages" before starting any of them
(exclusive file ownership, one integrator for shared files, fixtures per area, exit criteria).

Global exit criteria for every implementation task: Pest green for its groups (with Redis: phpredis **and** predis),
PHPStan level 9 clean on its paths, `pint --test` clean, every confirmed bug it owns has a named test (Arch Appendix H),
public-facing text in English, commit + push.

## Phase 0: design and foundation

### T00 Audit, architecture, bootstrap — `done`
Audit (`planning/audit/`), final architecture (`ARCHITECTURE.md`), WP0a bootstrap: legacy code moved to `legacy/`,
goldens frozen in `tests/Fixtures/grafana/legacy/`, new `composer.json`, LICENSE, hygiene files, Pest/PHPStan/Pint/Rector
configs. Commits up to `38d6a79`.

### T01 Architecture addendum R1–R4 — `in progress`
Output: `planning/ADDENDUM.md`, same style as the architecture (Decision / Rationale / Rejected / Risks), implementation-ready.
If the file is missing or incomplete, (re)write it from this spec, verifying facts with probes on Grafana 12.4.13 and 13.2.4:
- **R1 namespace.** Default `ho`; update Arch Appendix A (`'namespace' => env('HORIZON_OBSERVABILITY_NAMESPACE', 'ho')`)
  and Appendix B (self-metrics renamed to `exporter_collector_success`, `exporter_collector_duration_seconds`,
  `exporter_build_info`, `exporter_config_problems`, `exporter_attempts_dropped_total`, all prefixed with the namespace).
- **R2 dashboard performance.** Causes of ~5 s panel refreshes (65 targets, ~100 KB PromQL, `queue_jobs_total` inlined
  320 times, variables with `[1d]`/`[7d]` lookbacks, range vs instant queries, Infinity endpoint cost). A repeatable
  measurement method in the dev stack with realistic data, a budget (target: Overview < 0.3 s, whole dashboard < 1.5 s),
  and a catalogue of look-preserving optimisations mapped to panel ids: query reuse via the `-- Dashboard --` datasource,
  instant queries for tables/stats, `$__rate_interval`, variable refresh on load only, Prometheus datasource settings
  (incremental querying, `timeInterval`, POST), optional recording rules rendered with the prefix.
- **R3 scrape interval.** Separate scrape job for the package endpoint, recommended 10 s, supported 5–60 s; remove every
  15 s assumption (e.g. generator `BUSY_WINDOW`, fixed `[90s]`/`[1m]` windows); min/max duration windows must not miss
  attempts at a 60 s interval with jitter.
- **R4 Live Overview, two modes chosen at render time** (`horizon-observability:grafana --live=off|poll|stream`; row title
  "Live Overview" when live): a shared live-snapshot service (Horizon status, supervisor processes vs max, pending +
  oldest wait + level, delayed, busy workers, live throughput); `poll` = `GET {base}/live` JSON (same auth as job lists,
  ~1 s stampede-safe cache) for Infinity; `stream` = `php artisan horizon-observability:stream` pushing Influx line
  protocol to Grafana Live `/api/live/push/<id>` every second with a service-account token and a Redis leader lock;
  range tiles (Completed, Failed rate, Uptime, Alerts) stay on Prometheus; live RPM/failures from a Redis ring buffer
  (e.g. 5 s buckets, ~2 min) written inside the record-attempt EVAL; tiles keep their look (live value + Prometheus
  sparkline via Mixed datasource/transformations); exact panel JSON for both modes; failure handling; Redis keys;
  config keys; plan delta (new WP "Live", changes to WP2/WP3/WP8B/WP10).
Acceptance: document committed; probes recorded (what was verified on which Grafana version).

### T02 WP0b: shared contracts and wiring — `todo` (depends on T01)
Arch Q9 WP0b + addendum changes. Implement in full: `config/horizon-observability.php` (Arch Appendix A + addendum keys),
`routes/horizon-observability.php` (metrics, jobs/{list}, live), `HorizonObservabilityServiceProvider` (routes from an
application `booting` callback, Horizon menu-link middleware appended to `horizon.middleware` from the booting callback,
`enabled=false` inert), `HorizonObservability` (static hooks), `Config/*`, `Metrics/{Metric,MetricType,MetricFamily,
Sample,InvalidMetric,Labels,ScrapeContext}` (full catalog, Appendix B with R1 names), `Collectors/Collector`,
`Redis/{Keys,Lua,RedisClients,CircuitBreaker}`, `Support/{FailureReporter,Versions}`, `Attempts/{AttemptStatus,
JobClassLabel}`, `Batches/{BatchStatus,BatchSnapshot,BatchLink}`, `Queues/PayloadClock`, `Horizon/{Topology,Supervisors,
SupervisorSnapshot}`, `Http/{RoutePath,JobList,JobListQuery,AccessDecision}`. Safe stubs with final signatures (Arch
Appendix C) for every other class of the Arch Q7 tree and the addendum's live classes. Workbench: `testbench.yaml`,
`workbench/config/*`, `workbench/app/Providers/WorkbenchServiceProvider.php`. Tests: `tests/Support/*`,
`tests/Fixtures/payloads.php`, unit tests for everything implemented, `tests/Feature/Provider/{RoutePrecedenceTest,
HorizonMiddlewareTest,InertTest}`.
Acceptance: Arch Q9 WP0b list (boots in both provider orders, route precedence over Horizon's catch-all with and without
route cache, C16 test, metric catalog test equals Appendix B, arch tests, PHPStan 9 on WP0 files).

## Phase B: parallel work packages (all depend on T02; T10 only on T00)

### T03 WP1 Exposition and access — `todo`
Arch Q1, Q2.3–2.5, Q9 table. Own text renderer (format 0.0.4, real histograms), exporter with per-collector isolation and
scrape-timeout budget, self-metrics, `MetricsController`, `AccessPolicy`, `Authorize`/`ForceJson` middleware.
Acceptance: Arch Q9 WP1 row (golden exposition, promtool-clean, isolation/partial failure/budget, auth matrix incl.
rotation, api_token, IPs, callback, maintenance exception; C3, C4, C5-endpoint).

### T04 WP2 Attempts — `todo`
Arch Q6.2–6.6, 6.8, 6.10, Q9 table. `AttemptTracker` (WeakMap, RedisJob after JobProcessing only, Laravel 11.23/12/13
shims), `AttemptStore` (one EVAL per attempt, private short-timeout client, circuit breaker), `CounterResetGuard`,
`LegacyReadyAt` folded into the EVAL, `HistogramSnapshot`, `AttemptsCollector`, Lua `record_attempt`/`reset_guard`.
**Addendum:** write the live throughput ring buffer in the same EVAL; min/max windows tolerant of 60 s scrapes.
Acceptance: Arch Q9 WP2 row (C2, C6, C11, C18, C19, C20, A01, A05) + ring buffer and 60 s window tests.

### T05 WP3 Horizon and queue state — `todo`
Arch Q6.3, Q9 table. `HorizonCollector`, `QueueCollector`, `HorizonUrl`, `QueueInspector`/`QueueSnapshot`,
Lua `queue_state`, readiness parity with `PayloadClock`.
Acceptance: Arch Q9 WP3 row (C5, C8, C14-metrics, C17) incl. `horizon_info{url,api_path,tts_url}`.

### T06 WP4 Job lists — `todo`
Arch Q2, Q9 table. `JobListController`, `JobListService`, `JobRows`, `ExceptionSummary`, `Horizon/JobStore`,
`Queues/DelayedJobStore`, Lua `job_details`/`delayed_jobs`.
Acceptance: Arch Q9 WP4 row (JSON fields/units identical to legacy, C13 empty filter = 0 Redis commands, C14, 422s).

### T07 WP5 Batches — `todo`
Arch Q6.7, Q9 table. `BatchKind`, `BatchReader` (two bounded queries), `BatchLinks`, `BatchesCollector`.
Acceptance: Arch Q9 WP5 row (C1, C9-batches, C15; DynamoDB/missing table detection).

### T08 WP6 Job-class discovery — `todo`
Arch Q6.9, Q9 table. `Discovery/*`, `JobClassesCollector`, `CacheCommand`/`ClearCommand`, workbench discovery fixtures.
Acceptance: Arch Q9 WP6 row (C22; `optimize` hook iff Laravel >= 11.27.1).

### T09 WP7 Horizon menu link — `todo`
Arch Q7, Q9 table. `InjectGrafanaLink` middleware + `menu-link.blade.php`.
Acceptance: link right after "Dashboard" on Horizon 5.22 … latest; absent without URL; JSON untouched (C16 rendering).

### T10 WP8A Grafana golden port — `todo` (depends only on T00; start first, it is on the critical path)
Arch Q5.1, 5.6 (M1, M2), Q9 table. Port `legacy/grafana/generate_queues_dashboard.py` to PHP under `src/Grafana/**`
with a `LegacyProfile`. Acceptance: output decodes equal to (ideally byte-identical with) `tests/Fixtures/grafana/legacy/queues.json`;
alert rules equal the parsed legacy `horizon.yml`; tests `tests/Grafana/{GoldenPortTest,LegacyAlertsTest,LayoutProjectionTest}`.

### T11 WP9 Dev stack, CI, load simulation — `todo`
Arch Q8.2–8.5, Q9 table. `docker-compose.yml` (Redis, Prometheus with remote-write receiver, Grafana with Infinity +
Business Text plugins and provisioning, `GRAFANA_IMAGE` switch for **12.4.13 / 13.2.4**), `docker/**`, `.github/**`
(matrix PHP × Laravel 11/12/13 × lowest/stable × phpredis/predis × Redis/Valkey + cluster leg; static, grafana, dist,
coverage jobs; weekly e2e screenshots on both Grafana versions), `bin/screenshot.mjs`, `workbench/app/Simulation/**`,
`SimulateLoadCommand` (replaces legacy load test), `workbench/routes/web.php`. Prometheus scrape job at 10 s.
Acceptance: Arch Q9 WP9 row; `docker compose up` provisions Grafana 12 and 13 without errors.

### T12 Live backend (new WP from the addendum) — `todo`
Add §R4. Live snapshot service, `GET {base}/live` controller with ~1 s stampede-safe cache, `horizon-observability:stream`
command (Grafana Live push, leader lock, backoff), config keys. Coordinate the ring buffer contract with T04.
Acceptance: per addendum; snapshot for 20 queues computed within the budget; poll and stream verified on Grafana 12 and 13.

## Phase C: sequential tail

### T13 WP8B Grafana parametrisation, performance, live rendering — `todo` (depends on T10, T03–T07, T12)
Arch Q5.2–5.7 + Add §R2–R4. `Console/GrafanaCommand` (`--check`, `--live=off|poll|stream`, prefix/uid/thresholds),
`resources/grafana/**` (default dashboard + alert rules JSON + provisioning examples + optional recording rules),
scrape-interval-agnostic windows, query optimisations with measured before/after timings, contract tests
(`tests/Grafana/*`). Each optimisation step is its own commit with a screenshot on Grafana 12 and 13.
Acceptance: Arch Q9 WP8B row; performance budget met; look unchanged vs `planning/baseline/`.

### T14 WP10 Operations commands — `todo` (depends on T03–T09, T12)
Arch Q7, Q9 table. `install`, `check`, `flush` commands, `Support/Diagnostics`, `about` integration.
Acceptance: Arch Q9 WP10 row.

### T15 WP11 Integration, docs, release — `todo` (depends on everything)
README (English: install, config, Prometheus scrape job at 10 s, Grafana 12/13 setup, live modes, security), UPGRADE.md
(office migration, Arch Appendix G, namespace `office`), CONTRIBUTING, SECURITY, CODE_OF_CONDUCT, `art/` screenshots,
`tests/Contract/*`, delete `legacy/` and `planning/`, full matrix green, side-by-side screenshots vs baseline on both
Grafana versions, every Appendix H bug mapped to a passing test, office dry run, tag `v0.1.0`.
