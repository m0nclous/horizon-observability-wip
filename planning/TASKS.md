# Tasks

Status values: `todo`, `in progress`, `done`. Update this file in the same commit as the work.
"Arch §X" = section of [ARCHITECTURE.md](ARCHITECTURE.md); "Add §X" = section of [ADDENDUM.md](ADDENDUM.md).
WP ids are the work packages of Arch Q9; read Arch Q9 "Rules for all work packages" before starting any of them
(exclusive file ownership, one integrator for shared files, fixtures per area, exit criteria).

Global exit criteria for every implementation task: Pest green for its groups (with Redis: phpredis **and** predis),
PHPStan level 9 clean on its paths, `pint --test` clean, every confirmed bug it owns has a named test (Arch Appendix H),
public-facing text in English, commit + push.

## Phase 0: design and foundation

Review: 2026-10-17, 6 findings fixed (2ee7b0d, 6829a20, 727e86f). (1) A provider registered from another provider's
`boot()` added a booting callback that never fired: no routes and no warning; a `booted` fallback now wires
it up and logs `routes.late`. (2) `collectors.batches` was cast at boot unlike `Config` (`'0'`, `'false'` kept the
listener). (3) `JobListQuery` parsed integers more strictly than the rule that validated them: `limit=+5` gave 500 rows
and `from=+10` dropped the bound. (4) `LiveScope::cacheKey()` hashed a failed `json_encode()` for invalid UTF-8, so such
scopes shared one key. (5) Missing shared contract: `Topology::redisConnection()` maps a queue connection to its Redis
connection for T05, T06 and T12 (Add §C2.3 item 15). (6) The provider tests would have broken in T13 (grafana
command), T03 (a hint after "locked"), on the lowest legs (legacy `ready_at` listener) and on a Horizon that changes
its own group entries. Gates re-run: Pest on phpredis and predis (redis group on both), PHPStan 9, Pint, Rector,
`composer validate --strict`; C/C′/C2 signatures, Appendix A/A′ config and env names, B/B′ catalog checked against the
documents; lowest legs still deferred to CI.

### T00 Audit, architecture, bootstrap — `done`
Audit (`planning/audit/`), final architecture (`ARCHITECTURE.md`), WP0a bootstrap: legacy code moved to `legacy/`,
goldens frozen in `tests/Fixtures/grafana/legacy/`, new `composer.json`, LICENSE, hygiene files, Pest/PHPStan/Pint/Rector
configs. Commits up to `38d6a79`.

### T01 Architecture addendum R1–R4 — `done`
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
Done (2026-10-17): ADDENDUM.md committed in 2f3fc37 (probes: Add Appendix P on 13.2.4, review Appendix P′ on 12.4.13 and 13.2.4); ARCHITECTURE.md amended in place and the Q9′ delta folded into T02–T15 below in the commit that marks T01 done.

### T02 WP0b: shared contracts and wiring — `done` (depends on T01)
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
Addendum (Add §Q9′ WP0b): the Add §A′ `live.*` keys and validation (`Config` gains the six live properties, Arch
Appendix C), the `Metric` renames to `exporter_*` and the two live cases after `AttemptsDropped` (Add §B′), the `Keys`
additions (Add §C′), `JobListQuery::filtersFromRequest()`, the `live` route (guarded by `live.enabled`) plus the
maintenance exception `$base/live` plus the boot-time key `live.enabled`, and `Live/{LiveScope,LiveSnapshot,Captions}`
implemented in full. Stubs for every other Add §C′ class: `LiveSnapshotBuilder` returns an empty snapshot,
`StreamCommand` exits 1.
Acceptance: Arch Q9 WP0b list (boots in both provider orders, route precedence over Horizon's catch-all with and without
route cache, C16 test, metric catalog test equals Appendix B, arch tests, PHPStan 9 on WP0 files). Addendum: the catalog
test equals Add §B′ (`ho_exporter_*` with the default namespace); `Captions` unit tests cover every caption and level rule
of Add §R4.4 (fixtures shared with the Grafana renderer); the route-precedence test includes `/live`; `live.enabled=false`
leaves no `live` route.
Done (2026-10-17): contracts, wiring, workbench and tests landed; signatures and integrator decisions are in Add §Appendix C2
(read it before starting any Phase B task). Gates: Pest 697 green on phpredis and on predis (1 phpredis-only skip),
PHPStan 9 clean on `src`, `config`, `routes`, `workbench/app`; Pint and Rector clean; lowest legs: deferred to CI (no
PHP 8.2 locally; the 11.23/5.22 facts used were checked upstream). What later tasks must know:
- T03: `AccessPolicy` must use `Config::tokensFor()`, `requiresToken()` and `requiresIp()` (fail closed without
  sentinels, C2.3 item 1); `Authorize`/`ForceJson`/`Exporter`/`TextFormat` are stubs; `Metric::ofCollector()` and
  `Config::COLLECTORS` give the exporter order; `tests/Support/Exposition.php` parses expositions.
- T04: `AttemptRing::key()`/`ttl()` and `Keys::field()`/`EXTREMES_BUCKET_SECONDS`/`RING_BUCKET_SECONDS` are final; the
  breaker opens on any `Throwable` and restores a dropped count taken inside a failing write; `AttemptTracker` is
  resolved at boot (no Redis, no eager `Config` in its constructor); `AttemptOutcome` is Q6.2 verbatim (tests are yours).
- T05/T06/T12: `Topology::queues()`, `waits()`, `waitThreshold()` (default 60 s, see C2.3 item 5) and
  `redisConnection()` (queue connection to Redis connection for `RedisClients::reader()`, C2.3 item 15), the
  `QueueSnapshot` data class and `QueueInspector::live()`; parity fixtures in `tests/Fixtures/payloads.php`.
- T06/T13: `JobRows::fields()` freezes the Q2.1 fields; `JobList::horizonPath()`.
- T12: build snapshots with `LiveSnapshot::compose()`/`unavailable()`; the cache stores `toJson()` and reads
  `fromArray()`.
- T13: `Live\Captions` and `tests/Fixtures/live/captions.php` are the caption source; the provider registers
  `Console\GrafanaCommand` as soon as the class exists (no provider edit).
- All: Redis tests use `pest()->group('redis')` and the per-process prefix `hot_<pid>:` (no `FLUSHDB`); build apps in a
  fixed provider order with `tests/Support/Apps::inspect()`; Rector skips the two rules that would rewrite frozen
  signatures. Not created here: `resources/lua/*`, `resources/views/menu-link.blade.php`, `resources/grafana/**`.

## Phase B: parallel work packages (all depend on T02; T10 only on T00)

### T03 WP1 Exposition and access — `todo`
Arch Q1, Q2.3–2.5, Q9 table. Own text renderer (format 0.0.4, real histograms), exporter with per-collector isolation and
scrape-timeout budget, self-metrics, `MetricsController`, `AccessPolicy`, `Authorize`/`ForceJson` middleware.
Addendum (Add §Q9′ WP1): self-metric names `<ns>_exporter_*`; the live self-metrics are read from `meta` (Add §R4.11;
their `Metric` cases belong to collector `attempts` per Add §B′, so coordinate the exposition with T04).
Acceptance: Arch Q9 WP1 row (golden exposition, promtool-clean, isolation/partial failure/budget, auth matrix incl.
rotation, api_token, IPs, callback, maintenance exception; C3, C4, C5-endpoint). Addendum: golden exposition with
`ho_exporter_*`; the live series are absent until `meta.live_push_at` exists. promtool check:
`docker run --rm -i --entrypoint promtool quay.io/prometheus/prometheus:v3.15.0 check metrics < tests/Fixtures/exposition/<file>.txt`.

### T04 WP2 Attempts — `todo`
Arch Q6.2–6.6, 6.8, 6.10, Q9 table. `AttemptTracker` (WeakMap, RedisJob after JobProcessing only, Laravel 11.23/12/13
shims), `AttemptStore` (one EVAL per attempt, private short-timeout client, circuit breaker), `CounterResetGuard`,
`LegacyReadyAt` folded into the EVAL, `HistogramSnapshot`, `AttemptsCollector`, Lua `record_attempt`/`reset_guard`.
**Addendum (Add §Q9′ WP2, §R3.4, §R4.5, §D′):** `record_attempt.lua` writes the live ring buffer
(`live:ring:<unix div 5>`, always in `KEYS`; `ring_ttl` 180, or 0 when live is disabled) and 15-second extremes (TTL
300 s); new `resources/lua/extremes_read.lua`; `AttemptStore` reads merge 10 buckets; `AttemptsCollector` also exports
`exporter_live_push_timestamp_seconds` and `exporter_live_push_failures_total` from `meta` (Add §B′). Coordinate the
ring contract with T12.
Acceptance: Arch Q9 WP2 row (C2, C6, C11, C18, C19, C20, A01, A05). Addendum: extremes visibility test (an attempt
recorded at `t` is exported by simulated scrapes at `t + 0…135 s` and not after `15(b+10)`); a table test over
S ∈ {5, 10, 15, 30, 45, 60} s and deviations up to ±7.5 s asserts at least two exporting scrapes (Add §R3.4); ring test
(N attempts in known buckets give the exact window counts); a disabled live mode writes no ring key.

### T05 WP3 Horizon and queue state — `todo`
Arch Q6.3, Q9 table. `HorizonCollector`, `QueueCollector`, `HorizonUrl`, `QueueInspector`/`QueueSnapshot`,
Lua `queue_state`, readiness parity with `PayloadClock`.
Addendum (Add §Q9′ WP3, §D′): `queue_state.lua` live mode (ARGV `mode` = `full` or `live`) and `QueueInspector::live()`;
T12 needs it.
Acceptance: Arch Q9 WP3 row (C5, C8, C14-metrics, C17) incl. `horizon_info{url,api_path,tts_url}`. Addendum: parity
test unchanged; live mode scans at most 20 items and 1 MB per queue; reserved pairs only on request.

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
Review (2026-10-17, 3b430d6): `Json` floats now exactly Python `repr()` and independent of the `precision` ini; every selector via `Promql::selector()` (T13 job/dedupe seam); gates re-run, also on PHP 8.2.34.

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
Addendum (Add §Q9′ WP9, §R2.2, §Q7′): the perf profile and tools: `workbench/app/Console/Commands/{PerfDataCommand,
PerfExporterCommand}.php` (`workbench:perf-data`, `workbench:perf-exporter`; no `job`/`instance` labels),
`workbench/app/Perf/SyntheticModel.php` (port of `planning/reference/addendum-probes/probe/synth.py`), `bin/perf.mjs`
(`capture`, `replay`, `report`; the replay must not floor the step at 15 s), `docker/perf/{budget.json,prometheus.yml}`
and a compose `perf` profile; the dev Grafana gets `GF_DASHBOARDS_MIN_REFRESH_INTERVAL=1s` and a service account with
role Editor (`bootstrap-live.sh`); a compose service `stream` (profile `app`) runs `testbench horizon-observability:stream`
with the token read from `docker/grafana/live-token` (usable once T12 lands); the weekly `e2e` job runs Add §R2.2 steps
1–5 on the medium profile and uploads the report (non-blocking until T15).
Acceptance: Arch Q9 WP9 row; `docker compose up` provisions Grafana 12 and 13 without errors. Addendum:
`bin/perf.mjs capture/replay/report` reproduces the Add §R2.1 legacy row within ±25 % on the reference profile;
`bootstrap-live.sh` is idempotent.

### T12 WP-L1 + WP-L2 Live backend — `todo` (depends on T02, T03, T04, T05, T11)
Add §R4, §Q9′. One task: WP-L1, then WP-L2. Live snapshot service, `GET {base}/live` controller with ~1 s stampede-safe
cache, `horizon-observability:stream` command (Grafana Live push, leader lock, backoff). The config keys, the route and
`Live/{LiveScope,LiveSnapshot,Captions}` come from T02. Coordinate the ring buffer contract with T04; the snapshot needs
the T05 `queue_state.lua` live mode (develop against stubs and seeded keys until T04 and T05 land).
- **WP-L1 Live snapshot and endpoint** owns `Live/{LiveSnapshotBuilder,LiveSnapshotCache,AttemptRing}`,
  `Http/LiveController`, `resources/lua/{ring_read,lock}.lua`, `tests/{Unit,Integration,Feature}/Live/*` (except `Stream/`).
- **WP-L2 Stream command** owns `Live/Stream/*`, `Console/StreamCommand`, `docker/grafana/live-probe.json`,
  `tests/{Unit,Integration}/Live/Stream/*`, `tests/Feature/Console/StreamCommandTest`; it reuses `LiveSnapshotCache` and
  `lock.lua`.

Acceptance WP-L1: JSON golden for a seeded state, every Add §R4.4 field and unit. Filters: supervisor and queue scope; a
job filter gives `null` values; an empty scope makes 0 queue calls. Cache: 50 concurrent requests make **1** build per TTL
(build counter) across 2 simulated replicas whose clocks differ by 5 s; the stale copy serves at most 10 s old data. A
Redis failure gives 200 with `errors[]` and caption `Live unavailable`. Auth matrix as the job lists. Cost: ≤ Q + 5 round
trips per build and 1 `MGET` per cache hit (command counter); snapshot build ≤ 50 ms p95 for 20 queues in the dev stack.
Acceptance WP-L2: line-protocol golden (escaping, floats, rows 1 ms apart, `-1` for `null`, unavailable frame). A fake
Grafana HTTP server (testbench route or `Http::fake`) answering 200, 401, 403, 500 and timeout drives the backoff, the
release after 5 failed ticks and the `meta` counters. Two `LiveStreamer` instances on one Redis produce exactly one
pusher; a leader whose lease was taken over pushes nothing after its next renew; killing the leader means the follower
pushes within 7 s. SIGTERM releases the lock. `--once` and `--max-time` work. e2e in the dev stack on Grafana 12.4.13 and
13.2.4 with `docker/grafana/live-probe.json` built from the Add §R4.8 panel JSON (not with T13 output): poll and stream
tiles show live values, stream tiles update at least every 2 s, and no duplicate fields appear across 3 dashboard
refreshes.

## Phase C: sequential tail

### T13 WP8B + WP8C + WP8D Grafana parametrisation, performance, live rendering — `todo` (depends on T03–T08, T10, T11, T12)
Arch Q5.2–5.7 + Add §R2–R4, §Q9′. One sequential task in this order: **WP8B, then WP8C, then WP8D** (one owner chain of
`src/Grafana/**`). `Console/GrafanaCommand` (`--check`, `--live=off|poll|stream`, prefix/uid/thresholds),
`resources/grafana/**` (default dashboard + alert rules JSON + provisioning examples + optional recording rules),
scrape-interval-agnostic windows, query optimisations with measured before/after timings, contract tests
(`tests/Grafana/*`). Each optimisation step is its own commit with a screenshot on Grafana 12 and 13.
- **WP8B** (Arch Q5.6 steps 1–12): step 2 renders the prefix `ho_`; new step 12 applies the Add §R3.3 windows (the
  `scrape_interval` variable, per-panel `interval`, `busy_window = max(15s, 2×S)`, `LOOKBACK = max(10m, 10×S)`,
  `--scrape-interval` default `10s`, the refresh derivation). Acceptance: Arch Q9 WP8B row; contract test: no literal
  `[15s]` and no `[1d]` on counter selectors in the committed render; every Prometheus panel has
  `interval: "$scrape_interval"`.
- **WP8C dashboard performance** (Add §R2.4) also owns `Grafana/Perf/StaticBudget`, `tests/Grafana/PerfBudgetTest` and the
  `resources/grafana/js/alert-sound.js` hide list. O1–O8, then O9 last and opt-in (`--recording-rules`), each its own
  commit with a screenshot diff (layout projection green) and a `bin/perf.mjs` report, on Grafana 12.4.13 and 13.2.4.
  Acceptance: the Add §R2.3 budget is met on the reference profile, including the O2 batch scope. Static gates in CI:
  ≤ 12 requests per refresh, ≤ 100 KB interpolated PromQL with All, the O3 variable policy, no `[1d]` on counters, #24 has
  `maxDataPoints`, no `batch_filtered()`-style duplicate branch. Batch=All and Batch=kind equality tests for O2. e2e:
  every #5 repeat is non-empty with #23's script active.
- **WP8D live panels** (Add §R4.8) owns `src/Grafana/Live/**`, `tests/Grafana/Live*Test` and the renders
  `tests/Fixtures/grafana/{off,stream}/*` (the default render is poll). Acceptance: the three renders (off, poll, stream)
  pass the Arch Q5.7 contract tests; the panel JSON equals Add §R4.8 (`TILE_ROWS`, `buffer: 500`, `SENTINEL`, `key`
  column); every Infinity column selector and every line-protocol field used by panels exists in `LiveSnapshot` and
  `LineProtocol` (contract test); row title, `__requires`, the `<uid>-live` rule (`timestamp()` form) and the selector;
  e2e on 12.4.13 and 13.2.4: poll tiles show the live value within one refresh, stream tiles within 2 s; screenshots
  attached.
Screenshots per step: run `node bin/screenshot.mjs` (1920x3600, `from=now-1h`, after >= 15 min of `composer load`) with
`GRAFANA_IMAGE` = 12.4.13 and 13.2.4 and commit `planning/screenshots/T13-step<N>-g{12,13}-{overview,details}.png`.
Look check = side-by-side review of panel set and order, titles, gridPos, colours and threshold colours, units, legends
and row titles (except "Live Overview"); compare g12 vs g13 of the same step and the final g13 shots with
`planning/baseline/dashboard-{overview,details,full}.png`; write the verdict in the step commit message.
Before deleting `tests/Fixtures/grafana/legacy/*`, freeze the legacy layout projection to
`tests/Fixtures/grafana/default/layout-projection.json` and point `LayoutProjectionTest` at it.
Acceptance: Arch Q9 WP8B row and the Add §Q9′ WP8B, WP8C and WP8D rows; performance budget met; look unchanged vs
`planning/baseline/` with `--live=off`; the poll and stream renders deviate only as listed in Add §R4.1 (pending owner
acceptance, README decision 9).

### T14 WP10 Operations commands — `todo` (depends on T03–T09, T12)
Arch Q7, Q9 table. `install`, `check`, `flush` commands, `Support/Diagnostics`, `about` integration.
Addendum (Add §Q9′ WP10): `check` gains the live block (enabled; stream URL, token set or missing, stream id; optional
`--live-push-test`, which pushes one `tiles` frame to stream id `<id>_check` and reports the HTTP status with the Add
§R4.10 hints for 401 and 403); `install` prints the Prometheus job at 10 s, the Grafana service-account commands (role
Editor) and the render commands for each live mode; `flush` also deletes `live:*`. `tests/Feature/Console/StreamCommandTest`
belongs to T12.
Acceptance: Arch Q9 WP10 row. Addendum: seeded live cases (missing token, 401 token, 403 token, invalid stream id).

### T15 WP11 Integration, docs, release — `todo` (depends on everything)
README (English: install, config, Prometheus scrape job at 10 s, Grafana 12/13 setup, live modes, security), UPGRADE.md
(office migration, Arch Appendix G, namespace `office`), CONTRIBUTING, SECURITY, CODE_OF_CONDUCT, `art/` screenshots,
`tests/Contract/*`, delete `legacy/` and `planning/`, full matrix green, side-by-side screenshots vs baseline on both
Grafana versions, every Appendix H bug mapped to a passing test, office dry run, tag `v0.1.0`.
Addendum (Add §Q9′ WP11): README sections Live Overview (modes, requirements, failure modes), Performance (budget,
settings) and Scrape interval; the weekly e2e adds the perf report and screenshots for `--live=poll` and `--live=stream`,
and becomes blocking. Acceptance additions: the budget report is green on the weekly run; live screenshots show live
values that change between two captures 2 s apart (stream).
Stop and ask the owner before the office dry run, pushing any tag, Packagist registration and the repository transfer.
When deleting `legacy/` and `planning/`, rewrite CLAUDE.md to contributor conventions only (no planning references) and
remove `/legacy` and `/planning` from `.gitattributes`.
