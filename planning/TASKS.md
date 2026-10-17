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
  config keys; plan delta (new WP "Live", changes to WP2/WP3/WP8B/WP10). Also define: config/env keys for stream
  (e.g. `HORIZON_OBSERVABILITY_LIVE_GRAFANA_URL`, `..._LIVE_TOKEN`, `..._LIVE_STREAM`), the minimal Grafana role for
  `/api/live/push/<id>` verified on 12.4.13 and 13.2.4, the leader-lock key and TTL (Appendix E), the files T12 owns
  (e.g. `src/Live/**`, `Http/LiveController.php`, `Console/StreamCommand.php`, `tests/{Unit,Integration,Feature}/Live/*`),
  and a probe of `min_refresh_interval` behaviour and of the whole-dashboard cost at the poll refresh on both versions.
- R2 explicitly overrides the Arch Q5 rejected alternative "Recording rules for attempts_delta": recording rules are
  opt-in (`--recording-rules`), and the default render must not depend on them.

Progress (2026-10-17, cloud session): R2 measured on Grafana 13.2.4 with synthetic medium-production data (4 supervisors,
12 queues, 160 classes, 16 buckets, 8 batch kinds, range 1 h); harness and results in `planning/reference/addendum-probes/`.
Full refresh cycle: legacy 6.5–7.8 s (19–21 requests); v1 (counter baseline `[1d]`→`[10m]`) 3.5–3.7 s; v2 (+`job_class`
allValue `.+`) 2.8–3.1 s; v3 (+variables refresh on load) 2.5–2.6 s; v4 (aggregate-first per-step formula for tiles 4/8,
`formulas.py`) brings panel 4 from ~2600 ms to ~100–130 ms per target (`results/v4-replay.txt`). PromQL replay sum: legacy
14.8 s → v3 4.3 s. Re-run: `synth.py`/`backfill.py` → `promtool tsdb create-blocks-from openmetrics` → Prometheus →
Grafana (`stack/`) → `node probe/measure.mjs <dashboard url> out.json` → `python3 -I probe/replay.py out.json <prometheus url>`.
The `/live` JSON schema was prototyped in `probe/fakeapp.py` (`snapshot()`). Remaining if ADDENDUM.md is absent:
12.4.13 runs, Live push probe, R1/R3/R4 text.

Acceptance: document committed; probes recorded (what was verified on which Grafana version). Then edit
ARCHITECTURE.md in place and add under its title "Amended by ADDENDUM.md R1–R4 (2026-10-17); where they differ the
addendum wins": R1 replace the metric namespace `laravel`/`laravel_` with `ho`/`ho_` (lines ~43, 503, 536, 599-600, 747,
756, 840, 1904, 1906; do NOT touch the Redis prefix `laravel_horizon:` at ~1013 and ~2309) and rename self-metrics to
`exporter_*` (~218-222, 243, 554, 640, 800, 2034, 2045-2048); R3 scrape interval 10 s in Q2.5, Q5.1, Q5.2, 8.3; R4 add
the `/live` route (name `horizon-observability.live`, api-token auth) to Q2.1 and the routes snippet, to the Q2.4
maintenance except list, live/stream keys to Appendix A and the Q3 env table, `horizon-observability:stream` as the 7th
command in Q7 and the public API, live classes to the Q7 tree and Appendix C, ring-buffer and leader-lock keys to
Appendix E, the extra KEYS/ARGV of `record_attempt.lua` to Appendix D; Grafana `image: ${GRAFANA_IMAGE:-grafana/grafana:13.2.4}`
and a `[12.4.13, 13.2.4]` matrix for the grafana/e2e jobs in 8.3/8.5; CI branches `master` and `claude/**` instead of
`main` in 8.5/8.7; Q5.7 item 7 glyphs `(· U+00B7, — U+2014, ✓, ⚠ U+26A0 + U+FE0E, ◷, ⧖)`; 0.4 cite
`planning/audit/dashboard-contract.md`; WP11 "deletion of legacy/ and planning/" and compare with `planning/baseline/dashboard-*.png`;
Q2.2 item 4 probes are in `planning/reference/probes/`.

### T02 WP0b: shared contracts and wiring — `todo` (depends on T01)
Arch Q9 WP0b + addendum changes. Implement in full: `config/horizon-observability.php` (Arch Appendix A + addendum keys),
`routes/horizon-observability.php` (metrics, jobs/{list}, live), `HorizonObservabilityServiceProvider` (routes from an
application `booting` callback, Horizon menu-link middleware appended to `horizon.middleware` from the booting callback,
`enabled=false` inert), `HorizonObservability` (static hooks), `Config/*`, `Metrics/{Metric,MetricType,MetricFamily,
Sample,InvalidMetric,Labels,ScrapeContext}` (full catalog, Appendix B with R1 names), `Collectors/Collector`,
`Redis/{Keys,Lua,RedisClients,CircuitBreaker}`, `Support/{FailureReporter,Versions}`, `Attempts/{AttemptStatus,
JobClassLabel}`, `Batches/{BatchStatus,BatchSnapshot,BatchLink}`, `Queues/PayloadClock`, `Horizon/{Topology,Supervisors,
SupervisorSnapshot}`, `Http/{RoutePath,JobList,JobListQuery,AccessDecision}`. Safe stubs for every other class of the
Arch Q7 tree **except `src/Grafana/**` (T10) and `Console/GrafanaCommand` (T13)**, plus the addendum's live classes.
Where Appendix C gives no signature, define the minimal public signature the provider, routes and tests need and record
it in ADDENDUM.md "Appendix C2: signatures frozen in T02"; later tasks may add methods but must not change these.
Integrator duties: align tool configs with Arch 8.6/8.8 or record the deviation in the addendum (PHPStan paths
`[src, config, routes, workbench/app]` with `checkMissingCallableSignature: true`; pint `final_class` vs an arch test
"classes are final"; rector-laravel set; `.gitattributes` export-ignore for `/SECURITY.md`, `/docs`, `/tools`). Workbench: `testbench.yaml`,
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
rotation, api_token, IPs, callback, maintenance exception; C3, C4, C5-endpoint). promtool check:
`docker run --rm -i --entrypoint promtool quay.io/prometheus/prometheus:v3.15.0 check metrics < tests/Fixtures/exposition/<file>.txt`.

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
The legacy code is not runnable: write the expected JSON snapshots by hand from
`legacy/src/Http/Resources/Horizon{Pending,Completed,Failed,Delayed}JobResource.php` and the Arch Q2.1 field table.

### T07 WP5 Batches — `todo`
Arch Q6.7, Q9 table. `BatchKind`, `BatchReader` (two bounded queries), `BatchLinks`, `BatchesCollector`.
Acceptance: Arch Q9 WP5 row (C1, C9-batches, C15; DynamoDB/missing table detection).

### T08 WP6 Job-class discovery — `todo`
Arch Q6.9, Q9 table. `Discovery/*`, `JobClassesCollector`, `CacheCommand`/`ClearCommand`, workbench discovery fixtures.
Acceptance: Arch Q9 WP6 row (C22; `optimize` hook iff Laravel >= 11.27.1).

### T09 WP7 Horizon menu link — `todo`
Arch Q7, Q9 table. `InjectGrafanaLink` middleware + `menu-link.blade.php`.
Acceptance: link right after "Dashboard" on Horizon 5.22 … latest; absent without URL; JSON untouched (C16 rendering).

### T10 WP8A Grafana golden port — `done` (depends only on T00; start first, it is on the critical path)
Arch Q5.1, 5.6 (M1, M2), Q9 table. Port `legacy/grafana/generate_queues_dashboard.py` to PHP under `src/Grafana/**`
with a `LegacyProfile`. Acceptance: output decodes equal to (ideally byte-identical with) `tests/Fixtures/grafana/legacy/queues.json`;
alert rules equal the parsed legacy `horizon.yml`; tests `tests/Grafana/{GoldenPortTest,LegacyAlertsTest,LayoutProjectionTest}`.
Note: `alert-sound.js` has no trailing newline by design (it equals the panel #23 `afterRender` string); read it verbatim.
The Python reference reproduces the golden: `python3 -I legacy/grafana/generate_queues_dashboard.py out.json --prefix office`
(Python 3.12+).
Progress (2026-10-17, f8bd16d): M1 byte-identical (sha256 equals the Python output), M2 `===` the parsed YAML; seams in `DashboardOptions` (live/job/dedupe rejected until T13); dead `STATUS_EXPR`/`batches_table` not ported; lowest legs: deferred to CI.

### T11 WP9 Dev stack, CI, load simulation — `todo`
Arch Q8.2–8.5, Q9 table. `docker-compose.yml` (Redis, Prometheus with remote-write receiver, Grafana with Infinity +
Business Text plugins and provisioning, `GRAFANA_IMAGE` switch for **12.4.13 / 13.2.4**), `docker/**`, `.github/**`
(matrix PHP × Laravel 11/12/13 × lowest/stable × phpredis/predis × Redis/Valkey + cluster leg; static, grafana, dist,
coverage jobs; weekly e2e screenshots on both Grafana versions), `bin/screenshot.mjs`, `workbench/app/Simulation/**`,
`SimulateLoadCommand` (replaces legacy load test), `workbench/routes/web.php`. Prometheus scrape job at 10 s.
Depends on T02; acceptance item "Prometheus target up with bearer auth" only after T03 (the T02 `Authorize` stub denies).
Until T13 lands, T11 owns placeholder provisioning in `docker/grafana/provisioning/{datasources,dashboards,alerting}`
pointing at `tests/Fixtures/grafana/legacy/{queues.json,horizon.yml}`; T13 switches the mounts to `resources/grafana/**`.
Grafana: `image: ${GRAFANA_IMAGE:-grafana/grafana:13.2.4}`; in `.github/workflows` the grafana and e2e jobs use
`strategy.matrix.grafana: [grafana/grafana:12.4.13, grafana/grafana:13.2.4]` passed as `GRAFANA_IMAGE` (Infinity 4.1.1
needs >= 12.2.5, Business Text 6.3.0 needs >= 12.3.0). CI triggers: push to `master` and `claude/**`, plus
`pull_request` (the owner must enable Actions on the wip repo). `docker/grafana/bootstrap-live.sh` creates a service
account and token via `POST /api/serviceaccounts` and `/api/serviceaccounts/<id>/tokens` (admin:admin) and writes it to
`docker/grafana/live-token` (gitignored), for stream mode.
Acceptance: Arch Q9 WP9 row; `docker compose up` provisions Grafana 12 and 13 without errors.

### T12 Live backend (new WP from the addendum) — `todo` (depends on T02, T03, T04, T11)
Add §R4. Live snapshot service, `GET {base}/live` controller with ~1 s stampede-safe cache, `horizon-observability:stream`
command (Grafana Live push, leader lock, backoff), config keys. Coordinate the ring buffer contract with T04.
Owns the files listed in the addendum (e.g. `src/Live/**`, `Http/LiveController.php`, `Console/StreamCommand.php`,
`tests/{Unit,Integration,Feature}/Live/*`, `docker/grafana/live-probe.json`).
Acceptance: per addendum; snapshot for 20 queues computed within the budget; poll and stream verified on Grafana 12 and
13 with `docker/grafana/live-probe.json` built from the addendum panel JSON (not with T13 output).

## Phase C: sequential tail

### T13 WP8B Grafana parametrisation, performance, live rendering — `todo` (depends on T03–T08, T10, T11, T12)
Arch Q5.2–5.7 + Add §R2–R4. `Console/GrafanaCommand` (`--check`, `--live=off|poll|stream`, prefix/uid/thresholds),
`resources/grafana/**` (default dashboard + alert rules JSON + provisioning examples + optional recording rules),
scrape-interval-agnostic windows, query optimisations with measured before/after timings, contract tests
(`tests/Grafana/*`). Each optimisation step is its own commit with a screenshot on Grafana 12 and 13.
Screenshots per step: run `node bin/screenshot.mjs` (1920x3600, `from=now-1h`, after >= 15 min of `composer load`) with
`GRAFANA_IMAGE` = 12.4.13 and 13.2.4 and commit `planning/screenshots/T13-step<N>-g{12,13}-{overview,details}.png`.
Look check = side-by-side review of panel set and order, titles, gridPos, colours and threshold colours, units, legends
and row titles (except "Live Overview"); compare g12 vs g13 of the same step and the final g13 shots with
`planning/baseline/dashboard-{overview,details,full}.png`; write the verdict in the step commit message.
Before deleting `tests/Fixtures/grafana/legacy/*`, freeze the legacy layout projection to
`tests/Fixtures/grafana/default/layout-projection.json` and point `LayoutProjectionTest` at it.
Acceptance: Arch Q9 WP8B row; performance budget met; look unchanged vs `planning/baseline/`.

### T14 WP10 Operations commands — `todo` (depends on T03–T09, T12)
Arch Q7, Q9 table. `install`, `check`, `flush` commands, `Support/Diagnostics`, `about` integration.
Acceptance: Arch Q9 WP10 row.

### T15 WP11 Integration, docs, release — `todo` (depends on everything)
README (English: install, config, Prometheus scrape job at 10 s, Grafana 12/13 setup, live modes, security), UPGRADE.md
(office migration, Arch Appendix G, namespace `office`), CONTRIBUTING, SECURITY, CODE_OF_CONDUCT, `art/` screenshots,
`tests/Contract/*`, delete `legacy/` and `planning/`, full matrix green, side-by-side screenshots vs baseline on both
Grafana versions, every Appendix H bug mapped to a passing test, office dry run, tag `v0.1.0`.
Stop and ask the owner before the office dry run, pushing any tag, Packagist registration and the repository transfer.
When deleting `legacy/` and `planning/`, rewrite CLAUDE.md to contributor conventions only (no planning references) and
remove `/legacy` and `/planning` from `.gitattributes`.
