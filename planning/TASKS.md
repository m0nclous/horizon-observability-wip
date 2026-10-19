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

Review: 2026-10-17, 3 findings fixed, 1f549a8, b5cc095 (+ a2f5dbf, e8af5e0), 515c6ae. (1) `JobClassCache` took the
scan marker of the Redis cache store (a number comes back as a string) for a dead discovery and warned "did not
finish" whenever two requests raced. (2) `BatchLinks` read and pruned the links on the worker-side storage client
(`worker_timeout`) instead of the scrape-timeout reader (Arch Q1.3, Q6.6), unlike every other scrape-path read.
(3) The breaker's warning used the cache-backed throttle: with the cache on the same unreachable Redis, a worker or
a dispatching request paid the cache timeout on top of the one `worker_timeout` (C11); new
`FailureReporter::reportLocally()` (Add §C2.4), also for the job-class cache's store failures. Checked without
findings: Appendix H names for C1-C6, C8-C11, C13-C20, C22, C24, C25, A01, A05; cross-task keys, labels, units,
HELP texts and config keys; PHP 8.2 APIs. Gates: Pest 1745 + 1 skip on phpredis, 1743 + 3 skips on predis, cluster
8/8 on both, promtool test, PHPStan 9, Pint, Rector, `php -l` on 8.2.34. Open (not fixed, for T14/T15): the
throttled logs of the scrape and job-list paths still use the default cache, and the batch queries wait for the
app's DB connect timeout, so an app without Redis or DB timeouts can still overrun the scrape budget (a `check`
warning, T14; README, T15); lowest legs: CI.

Integration: 2026-10-17. Merges f965a54..14782c0 (`--no-ff`, order T03, T05, T04, T07, T06, T08, T09, T11; no
conflicts, suite green after each), integrator commits 42cc742..f5c42ed plus this TASKS update. Resolved: testbench.yaml
without `laravel: '@testbench'` (testbench-core 9.x); `Tests\Support\Apps::DEFAULTS` pin the test environment (array
cache, in-memory DB also for batches and failed jobs, `app.key`), so the suite no longer depends on `composer build`;
`LogRecorder::channel()`, `RunsWorker --memory=4096`, phpunit `memory_limit` 1G; Horizon floor `^5.29.1` (Laravel
>= 11.27.0 with Horizon <= 5.29.0 is a fatal `pop()` mismatch, checked on upstream tags), predis dev floor `^2.0.2`;
config problems logged in the console and on the scrape only (no cache I/O on denied requests, Arch Q2.4);
`FailureReporter` logs with the `null` store; `DelayedJobStore` uses `QueueInspector::queueKey()` (cluster); the live
self-metrics only in `AttemptsCollector`; `CircuitBreaker` counts dropped attempts only; the maintenance exception
names the four lists; repeated `--exclude-group` (a comma list excluded nothing); PROMTOOL in the coverage job; Add
§C2.4/C2.5, Arch Q6.11 and Appendix D aligned. Gates: Pest 1751 green on phpredis (cluster group and promtool test
included), 1749 + 2 phpredis-only skips on predis; cluster group 8/8 on both clients (single-node cluster with
`--cluster-announce-ip 127.0.0.1`); PHPStan 9, Pint, Rector, `composer validate --strict`, `composer normalize
--dry-run`; `php -l` on 8.2.34 and 8.5.11 over src, config, routes, tests, workbench (293 files); workbench smoke
(401 without token, scrape 200 and promtool-clean, all five collectors 1, job lists and `/live` 200, Grafana link in
`/horizon`). Lowest legs: deferred to CI.

### T03 WP1 Exposition and access — `done`
Arch Q1, Q2.3–2.5, Q9 table. Own text renderer (format 0.0.4, real histograms), exporter with per-collector isolation and
scrape-timeout budget, self-metrics, `MetricsController`, `AccessPolicy`, `Authorize`/`ForceJson` middleware.
Addendum (Add §Q9′ WP1): self-metric names `<ns>_exporter_*`; the live self-metrics are read from `meta` (Add §R4.11;
their `Metric` cases belong to collector `attempts` per Add §B′, so coordinate the exposition with T04).
Acceptance: Arch Q9 WP1 row (golden exposition, promtool-clean, isolation/partial failure/budget, auth matrix incl.
rotation, api_token, IPs, callback, maintenance exception; C3, C4, C5-endpoint). Addendum: golden exposition with
`ho_exporter_*`; the live series are absent until `meta.live_push_at` exists. promtool check:
`docker run --rm -i --entrypoint promtool quay.io/prometheus/prometheus:v3.15.0 check metrics < tests/Fixtures/exposition/<file>.txt`.
Done (2026-10-17, 721e34d..75ceb40, merged in f965a54; review 290ca18, 1e5597a, 4b1f4ff, 75ceb40: 4 findings fixed):
TextFormat 0.0.4 (empty label values omitted, `le` normalised), Exporter isolated and budgeted
(`X-Prometheus-Scrape-Timeout-Seconds`, `ho_exporter_*`), MetricsController (HEAD skips the scrape), AccessPolicy/
Authorize/ForceJson (the callback must return `true`; IPs checked before tokens; denials logged at info in-process, no
cache, DB or Redis work); golden exposition promtool-clean (automated when PROMTOOL is set); C1, C3, C4, C5, C17 named
tests; lowest legs deferred to CI.

### T04 WP2 Attempts — `done`
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
Done (2026-10-17, fbe6a19..d618323, merged in 0d87e6d; review a2b3544, 5bd4f7d, d618323: idle classes pruned only when
the class set is full, `le` independent of `serialize_precision`, legacy-stack test fixes): `record_attempt`/
`extremes_read`/`reset_guard` Lua, AttemptTracker, AttemptStore, CounterResetGuard, LegacyReadyAt, AttemptsCollector (9
commands per scrape, plus the live push self-metrics from `meta`); C2 C6 C9 C10 C11 C18 C19 C20 A01 A05 (and C21) tests
on both clients plus the cluster group; extremes guarantee for S 5–60 s with ±7.5 s; ring writer contract; T04 tests
green on Laravel 11.39.1 / Horizon 5.29.1 (reviewer, locally); the other lowest legs deferred to CI.

### T05 WP3 Horizon and queue state — `done`
Arch Q6.3, Q9 table. `HorizonCollector`, `QueueCollector`, `HorizonUrl`, `QueueInspector`/`QueueSnapshot`,
Lua `queue_state`, readiness parity with `PayloadClock`.
Addendum (Add §Q9′ WP3, §D′): `queue_state.lua` live mode (ARGV `mode` = `full` or `live`) and `QueueInspector::live()`;
T12 needs it.
Acceptance: Arch Q9 WP3 row (C5, C8, C14-metrics, C17) incl. `horizon_info{url,api_path,tts_url}`. Addendum: parity
test unchanged; live mode scans at most 20 items and 1 MB per queue; reserved pairs only on request.
Done (2026-10-17, d2d8904..03797fc, merged in efda089; review 47fc972, f654ccb, 03797fc: cluster queues read under the
framework's hash tag, a 16 MB reserved cap, a budget check between the two Horizon reads): `queue_state.lua` (full and
live), QueueInspector (`supports()`, `queueKey()`)/QueueSnapshot, HorizonCollector, QueueCollector, HorizonUrl
(`apiPath()`; keeps the path of `app.url`); C5, C8, C9-queues, C12, C14-metrics, C17 and C19 parity tests on both
clients and the cluster group, T05 tests also on Redis 6.2; lowest legs deferred to CI.

### T06 WP4 Job lists — `done`
Arch Q2, Q9 table. `JobListController`, `JobListService`, `JobRows`, `ExceptionSummary`, `Horizon/JobStore`,
`Queues/DelayedJobStore`, Lua `job_details`/`delayed_jobs`.
Acceptance: Arch Q9 WP4 row (JSON fields/units identical to legacy, C13 empty filter = 0 Redis commands, C14, 422s).
The legacy code is not runnable: write the expected JSON snapshots by hand from
`legacy/src/Http/Resources/Horizon{Pending,Completed,Failed,Delayed}JobResource.php` and the Arch Q2.1 field table.
Done (2026-10-17, 2f31ca5..4108b88, merged in c7c9992; review 04e12ee, 06eb61b, 4108b88: 4 findings fixed): job lists
with pilot-identical JSON (hand-written snapshots in `tests/Fixtures/job-lists`), bounded Lua-assisted scans (at most 20
calls; delayed calls at most 8 MB), a Redis failure answers 503 `Job list unavailable.`, the `class` exceptions mode
shows a class name only; C4, C13, C14 named tests (mutation-checked); integration: the delayed list reads the
cluster-aware key (7cd636b); lowest legs deferred to CI.

### T07 WP5 Batches — `done`
Arch Q6.7, Q9 table. `BatchKind`, `BatchReader` (two bounded queries), `BatchLinks`, `BatchesCollector`.
Acceptance: Arch Q9 WP5 row (C1, C9-batches, C15; DynamoDB/missing table detection).
Done (2026-10-17, b078d6f..20f0923, merged in 967c517; review c9ad397, cfc9172, 20f0923: the detection memo expires 10
minutes after the check, the link read is capped at the 10 000 newest, phpredis error replies fail like predis):
BatchKind (hex tokens, hook), BatchReader (cached detection, two bounded queries with `LIMIT 5000`), BatchLinks (memo,
breaker, hourly prune, one `ZRANGE`), BatchesCollector (100 kinds + `(other)`, 200/2000 batch caps, newest batch per
(kind, name)); C1 "dynamodb driver skipped", C9 CapsTest, C15 BoundedQueryTest; lowest legs deferred to CI.

### T08 WP6 Job-class discovery — `done`
Arch Q6.9, Q9 table. `Discovery/*`, `JobClassesCollector`, `CacheCommand`/`ClearCommand`, workbench discovery fixtures.
Acceptance: Arch Q9 WP6 row (C22; `optimize` hook iff Laravel >= 11.27.1).
Done (2026-10-17, afefdab..d4270da, merged in cf0a6f6; review 44efa6a, 68dab78, d4270da: a 512 KiB prefilter cap,
classes without extends/implements never loaded, a scan marker against fatal-looping discovery): token-prefiltered
discovery (never includes helpers, misnamed or include/require files; Composer `findFile` guard; `__invoke` accepted),
`bootstrap/cache` file + 1 h runtime cache, `queue_job_classes` collector, cache/clear with the `optimize` hook iff
>= 11.27.1; C22 tests (JobClassFinderTest "functions file never included", CacheCommandTest); integrator-request afefdab
accepted (the ArchTest exemption narrowed to the two abstract fixtures in 9f23d67); lowest legs deferred to CI.

### T09 WP7 Horizon menu link — `done`
Arch Q7, Q9 table. `InjectGrafanaLink` middleware + `menu-link.blade.php`.
Acceptance: link right after "Dashboard" on Horizon 5.22 … latest; absent without URL; JSON untouched (C16 rendering).
Done (2026-10-17, e71d41b, b9c8d10, merged in 9f2c308; review: 0 defects): InjectGrafanaLink + `menu-link.blade.php`; the
link sits right after "Dashboard" in the real `GET /horizon` in both provider orders, checked on Horizon 5.22.0, 5.44.0
and 5.50.1 (the sidebar is identical in all 71 tags 5.22.0–5.50.1); absent without a valid URL or when disabled; the
JSON API is byte-identical; missing markers leave the page untouched (C16); full lowest-leg suite deferred to CI.

### T10 WP8A Grafana golden port — `done` (depends only on T00; start first, it is on the critical path)
Arch Q5.1, 5.6 (M1, M2), Q9 table. Port `legacy/grafana/generate_queues_dashboard.py` to PHP under `src/Grafana/**`
with a `LegacyProfile`. Acceptance: output decodes equal to (ideally byte-identical with) `tests/Fixtures/grafana/legacy/queues.json`;
alert rules equal the parsed legacy `horizon.yml`; tests `tests/Grafana/{GoldenPortTest,LegacyAlertsTest,LayoutProjectionTest}`.
Note: `alert-sound.js` has no trailing newline by design (it equals the panel #23 `afterRender` string); read it verbatim.
The Python reference reproduces the golden: `python3 -I legacy/grafana/generate_queues_dashboard.py out.json --prefix office`
(Python 3.12+).
Progress (2026-10-17, f8bd16d): M1 byte-identical (sha256 equals the Python output), M2 `===` the parsed YAML; seams in `DashboardOptions` (live/job/dedupe rejected until T13); dead `STATUS_EXPR`/`batches_table` not ported; lowest legs: deferred to CI.
Review (2026-10-17, 3b430d6): `Json` floats now exactly Python `repr()` and independent of the `precision` ini; every selector via `Promql::selector()` (T13 job/dedupe seam); gates re-run, also on PHP 8.2.34.

### T11 WP9 Dev stack, CI, load simulation — `done`
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
Done (2026-10-17, c7f9056..3374891, merged in 14782c0; review 10b8d4f, cb670ba, 3374891: 6 findings fixed, among them
loopback-only ports, a per-event concurrency group, PHP 8.5 and 128M portability): docker-compose dev stack (Grafana
12.4.13 and 13.2.4 provision cleanly; Prometheus target up with bearer auth at 10 s; app image with
`variables_order=EGPCS`), CI (16 legs, cluster, static, coverage, grafana, dist, weekly e2e; release.yml),
`workbench:simulate-load` (C25 tests), perf profile (SyntheticModel byte-identical; the R2.1 deterministic figures
reproduce exactly, wall times +12–30 % on the shared host), `bootstrap-live.sh` idempotent; integrator-request 1739346
(normalized composer.json) accepted; the coverage threshold (85 %) was not measured locally.

### T12 WP-L1 + WP-L2 Live backend — `done` (depends on T02, T03, T04, T05, T11)
QA: 2026-10-17 20:27 UTC: round 3 approved by the spec and adversarial testers. round 3 approved by the spec tester: round 2 F1-F3 fixed with tests (L11 lowest leg, Carbon 2.72.2: LiveTruthTest green on phpredis and predis; lease keep-alive every 1 s and follower retry every 2 s; Pending caption from the exact age), round 1 fixes still covered (every reverted fix fails its tests); gates green on both clients incl. cluster, phpstan, pint, rector and PHP 8.2 lint; live-probe e2e green on Grafana 12.4.13 and 13.2.4 incl. --growing; real-process failover 5.0 s at interval 4 s | round 3 approved by the adversarial tester: lease keep-alive and 2 s follower retry hold at intervals up to 10 s (Redis error during keep-alive, 60 s backoff, command cost, outage cadence, real-process SIGKILL failover within 7 s), the caption from the exact age equals the off-mode PromQL rule at a 1 ms step, and the live clock follows Laravel's under CarbonImmutable and non-UTC timezones; follow-up (not blocking): a former leader in a partial partition pushes a second 'Live unavailable' frame after a healthy takeover (skipped repro in a847188); gates green on phpredis and predis, PHPStan, Pint, Rector, PHP 8.2 lint
QA: 2026-10-17 18:12 UTC: round 2 rejected: 5 findings. round 2 rejected by the spec tester: (1) Laravel 11 prefer-lowest (Carbon 2.72): LiveTruthTest fails on phpredis and predis (exporter 7 pending vs /live 5) because the live path reads CarbonImmutable::now() and the exporter Laravel's now(); (2) live.stream.interval >= 5 s lets the 5 s leader lease lapse between ticks, and followers retry only on their own ticks (failover 5 s + interval, not about 7 s; R4.6); (3) the Pending caption uses the age rounded to seconds (119.6 s reads "2 min" vs the off-mode "120 s"; 149.6 s "3 min" vs "2 min"); round 1 fixes verified with tests; gates, cluster and e2e on Grafana 12.4.13/13.2.4 green | round 2 rejected by the adversarial tester: (1) the stream lease (PX 5000) is renewed only at tick starts and pushes, so at an allowed live.stream.interval >= 5 s it expires between ticks (one push per two intervals, the lease flips between replicas), and followers retry only on their own ticks, so above 2 s a failover takes up to 5 s + interval instead of about 7 s; (2) the Pending caption rounds the age to seconds and then the minutes again (119.6 s '2 min' vs off-mode '120 s', 149.6 s '3 min' vs '2 min'); round 1 F1-F4 verified fixed; probes skipped in d4d0651
QA: 2026-10-17 14:26 UTC: round 1 rejected: 4 findings. round 1 rejected by the adversarial tester: (1) a Supervisor filter while Horizon is stopped answers 0 pending instead of the queue's jobs or null; (2) the wait level uses the rounded age (10.4 s over a 10 s limit reads green, 20.4 s yellow); (3) an invalid UTF-8 queue or supervisor name defeats the cache (toJson throws, every request builds); (4) each push backoff is one tick late (0, 2, 5, 10, 19 s instead of 0, 1, 3, 7, 15 s); repro tests skipped in 0d04137
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
Notes from phase B (2026-10-17): the ring fields are `conn␟queue␟status` with the AttemptStatus values, TTL 180 from
`AttemptRing::ttl()`, written by `record_attempt.lua` and untouched by the reset guard (T04); call
`QueueInspector::supports()` before `live()` (it throws InvalidArgumentException or UnsupportedTopology otherwise) and
build keys with `queueKey()` (cluster hash tag, as DelayedJobStore does); BatchLinks reads at most the 10 000 newest
links and throws on error replies on both clients; the live self-metrics are exported by AttemptsCollector from `meta`
(both absent until `live_push_at` exists; the reset guard `HDEL`s `live_push_failures` on a restore); the compose
`stream` service is ready; LiveScopeTest's batch case is in the `redis` group; Laravel 13.26+ `Queue::forward()` makes a
forwarded queue look empty to every reader (T05 review).

## Phase C: sequential tail

### T13 WP8B + WP8C + WP8D Grafana parametrisation, performance, live rendering — `done` (depends on T03–T08, T10, T11, T12)
QA: 2026-10-19 13:53 UTC: accepted under the prod-first rule (decision 15) after QA round 4 and one focused verification: the round 4 major (delta() dropped a series without a value at the period end) is fixed in 21438ea (each series ends at its last value inside the period; the queue rows and the #17 class map come from the period too), proven by SeriesEndTest (one failed scrape at the end, a series stopped 45 min before the end, a reset then a stop) and the testers' probes; the minor period-edge finding is parked as T19 item 5 (probes skipped in 58cc671); the verifier approved with no critical finding (own promtool probes on off/poll/stream, round 3 fixes intact, gates and CI 39261698466 green); R2.3 met (6h refresh p50 382-400 ms, 12 requests, 71.6 KB); pest phpredis 3263 / predis 3261 on the merge with master; two minor verifier notes go to T19 (batch-kind exclusion from instant selectors, instant queue variable)
QA: 2026-10-19 11:41 UTC: round 4 rejected by both testers on the same 2 findings, both confirmed by independent verifiers; under the prod-first rule (decision 15) the major is fixed now with one focused verification instead of a round 5, and the minor goes to T19 item 5. round 4: both round 3 findings verified fixed on every Attempts panel (about 120 /api/ds/query sweeps at 5/10/60 s scrapes, 15m/1h/6h, UTC offsets; tables = tiles = #14 = raw-sample truth with 3 scrape jobs), the refuted probe corrected, R2.3 met (1h p50 235-251 ms, 6h 315-351 ms, 12 requests, 71 KB), e2e 6/6 on 12.4.13 and 13.2.4, pest phpredis 2787 / predis 2785, phpstan, pint, rector, 8.2 lint, CI 39234784188 green | findings: (1, major) Attempts::delta() ends each series at a bare instant selector, so a series without a value at the period end (moved or renamed scrape job, decommissioned app, one failed scrape or an outage at the end) loses all its attempts in #24 C/#5, #6 B, #4 B/C, #8 B and #17 (job rename: 2,054/16 vs 4,259/37; one failed scrape: 0 vs 2,043), a regression of 5075855; (2, minor) the tiles and the tables differ at the period edges because Grafana rounds ${__from/__to:date:seconds} while the tables use the exact edges, and with a UTC offset that is not a multiple of the step the last tile step stops short (Kathmandu, 2 min: 13,196 vs 13,283); failing probes in c39942c and 982293a
QA: 2026-10-19 10:16 UTC: owner granted QA round 4 after the round 3 escalation (2026-10-19); round 3 findings fixed: (1) Attempts::delta()/perStep() choose the start per series (dedupe labels kept through the difference, then summed), perStep() clips its edges by time and counts 0 for a reset step (PerSeriesStartTest: 250/20 where the render had 1,250/70, the reset case 26 not 5,031); (2) the tiles count a series from its first value inside the step with one shared idelta subquery and per-series edge terms at fixed times (TileStepTest at 10 s/15 s/1 min/2 min, past periods, UTC offsets; R2.3 met: 1h refresh p50 216/202 ms, 6h 295/279 ms on 12.4.13/13.2.4); (3) refuted: AdversarialRound3FilterTest corrected to send the display text, the remaining custom-value/batch-kind case documented as a known limit; ADDENDUM O4/O7/R2.3/risks amended (ca3fd2f); CI 39234784188 green
QA: 2026-10-19 08:11 UTC: round 3 rejected: 2 findings confirmed by independent verification, 1 refuted; escalated to the owner: 3 rejected rounds. round 3 approved by the spec tester: R2.3 budget met on the perf profile (1h refresh p50 205-242 ms, 6h 270-338 ms on 12.4.13 and 13.2.4, 12 requests, 64.3 KB), static gates, off/poll/stream contract tests, test:grafana with recording rules, e2e 6/6 on both versions, screenshots vs baseline, beb59d9 matches the render, round 1-2 findings fixed; pest phpredis 2387 / predis 2385 with promtool, phpstan, pint, rector, 8.2 lint; CI 39210435216 24/24 | round 3 rejected by the adversarial tester: (1) Attempts::delta()/perStep() choose the start per (queue, job_class, status) sum, so a series of another scrape job that appears inside the range adds its whole history to the queue tables, #6, #4 Success/threshold, off RPM, #17 and #14 under All (16,017/101 vs 13,223/79; a Redis flush of one of two apps 5,029 vs 25): verified, major, pilot behaviour that T13 rewrote; (2) perStepTotal's idelta counts a series that appears between step boundaries from the first subquery point, so the Completed/Failed tiles lose up to one step when the step exceeds the scrape interval (2,871/2,640 vs 2,920; T16's 90 s hold does not cover the Failed tile's 2 min step at 6 h): verified, minor, O4 regression; (3) ${job_class:text} in the Job-filter caption: refuted (the option text is the namespace-stripped display, so a real option never holds a backslash; only a custom value matching no option fails, as on master), so AdversarialRound3FilterTest in 9edfae6 must be corrected; failing probes in 9edfae6
QA: 2026-10-18 00:29 UTC: round 2 rejected: 11 findings. round 2 rejected by the spec tester: rector --dry-run fails (GrafanaCommand class-name string in HorizonObservabilityServiceProvider.php:61 and ServiceProviderTest.php:61; the CI static job would fail); R2.3 1h refresh/Overview p50 over budget (1014-1146 ms in 4 QA runs on 12.4.13 and 13.2.4, 1153-1195 ms in the wp8c reports, budget 1000 ms); O9 --recording-rules not implemented and not deferred; R2.4 O4-O7, R4.8 interval and deleteRules deviations not recorded in the ADDENDUM; verified: round 1 findings fixed (promtool tests green), pest 2368 phpredis / 2366 predis with PROMTOOL, phpstan, pint, 8.2 lint, e2e poll/stream/#5 green on both Grafana versions, replica equality on all three renders | round 2 rejected by the adversarial tester: (1) WP8C O4 boundary-only clipping counts the full history of series that appear in the range (a new scrape job with All jobs: Completed 100,024/Failed 2,000 instead of 24/0; WP8B and delta() give 24/0; tiles and tables disagree in the dev stack, against T16's never-count-first-values rule); (2) a failed /live call shows the PromQL as the Pending and Completed captions on 12.4.13 and 13.2.4 (displayName fallback verified); (3) a Job/Batch filter gives the poll/stream Completed caption 'RPM: —' vs the class RPM off, a deviation R4.1 does not list; (4) the grafana command renders poll with live.enabled=false; (5) rector --dry-run with a clean cache fails on the provider and ServiceProviderTest (::class); (6) O9 --recording-rules missing; failing tests in 5ce5a35; e2e green on both versions
QA: 2026-10-17 16:22 UTC: round 1 rejected by both testers: only WP8B is delivered (WP8C performance and WP8D live panels not started; committed render must be --live=poll); no committed truth test evaluates rendered expressions (decision 13); collector health rule wrapped in the replica dedupe hides a failing replica; supervisor_title and #16 F fail with duplicate series when two apps share names; api_path/horizon_url/tts_url stay stale for 7 days after a change; --datasource-uid help text cut at the closing brace; relative path uses getcwd() not base_path(); failing tests in a9dec36. For T16/T17: Completed/Failed tiles drop a new counter series' first value; balance=false pooled queues get no rows in #5
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
Notes from phase B (2026-10-17): `JobRows::fields()` is unchanged and every column of the Infinity panels #18–#22
exists in the rows (T06); docker-compose.yml has three `# T13:` mounts to switch to `resources/grafana/**`, then delete
the placeholder provisioning `docker/grafana/provisioning/{datasources/horizon-observability.yaml,dashboards}` (the CI
jobs read the uid from the mounted file); write `build/grafana-rules.yml` and use `->group('grafana')` /
`->group('e2e')` so the CI jobs pick them up (T11); the pilot's horizon-failures rule logs a no-data template error that
T13's rules replace; TextFormat omits empty label values (`horizon_info{tts_url}` is absent without TTS); the batch kind
`(other)` matches no links as a filter.

### T18 Make CI green on the integration branch — `done` (depends on T11)
QA: 2026-10-17 20:27 UTC: round 3 approved by the spec and adversarial testers. round 3 approved by the spec tester: PayloadClock now treats non-finite attempts as a first attempt, like queue_state.lua, proven by 6 parity fixtures plus PayloadClockTest, AttemptTrackerTest and ReadinessParityAttemptsRangeTest; PR #20 body and Arch 8.1/8.5/Rationale match the workflow, and 86e784c asks for the T15 note; PR run 39061905164 22/22 green (floors 11.23.0/5.29.1/2.0.2 with Pest 3.0.0, 12.0.1/5.30.3, 13.0.0/5.44.0; coverage 98.0 %), dispatch run 39062277484 24/24 incl. e2e; local pest phpredis 1789 and predis 1787, cluster 8/8 on both, phpstan 9, pint, rector, 8.2 lint 297 files; merges cleanly into aa136c0 | round 3 approved by the adversarial tester: the 03e291d non-finite attempts fix agrees with queue_state.lua on all 30 new edge cases in a279809 (cjson repeated-key path, DBL_MAX/INF/subnormal/-0.0, the |attempts|<1 boundary, numeric strings with whitespace, sign and long exponents), green on phpredis and predis against Redis 6.2/7.0/7.4/8 and Valkey 7.2/8 and on PHP 8.5; PR #20 body and Arch 8.1/8.5 match CI 39061905164 (11.23.0/5.29.1/2.0.2, Pest 3.0.0/PHPUnit 11.3.4, coverage 98.0 %); the local merge with the integration head is green; CI 39066618937 on a279809 green
QA: 2026-10-17 18:40 UTC: round 2 rejected: 5 findings. round 2 rejected by the spec tester: PayloadClock::isFirstAttempt() (e4a634d) reads a non-finite attempts (1e999, "1e999", -1e999) as a retry while its Lua twin queue_state.lua counts it as a first attempt (C8/C19 parity, agreed before; PayloadClockTest pins the divergence, no parity fixture; fix: is_finite guard plus fixtures); PR #20 body, Arch 8.1/8.5/rationale and the T15 note still say COMPOSER_NO_BLOCKING / L11.39.1 / coverage unmeasured (no integrator-request); gates (pest phpredis+predis, cluster, phpstan, pint, rector, 8.2 lint), CI run 39040463193 (22/22, floors 11.23.0/12.0.1/13.0.0, coverage 98.0 %) and the round 1 fixes verified | round 2 rejected by the adversarial tester: e4a634d broke first-attempt parity between queue_state.lua and PayloadClock for non-finite attempts (1e999/-1e999/"1e999": first attempt in Lua, retry in PHP; failing contract test 15ff82d on every leg); PR #20 body still describes COMPOSER_NO_BLOCKING and an L11.39.1 floor; floor check proven by WorkflowFloorCheckTest (9bac125); trivial RedisKeys REDIS_CACHE_DB=0 fix in 3bf0c06
QA: 2026-10-17 13:53 UTC: round 1 rejected: 7 findings. round 1 rejected by the adversarial tester: COMPOSER_NO_BLOCKING/NO_SECURITY_BLOCKING also turn off Composer 2.10's malware blocking on all 16 legs (use COMPOSER_POLICY_ADVISORIES_BLOCK=0, verified); PHP 8.5 makes the pending/completed job lists answer 503 for a stored time >= ~9.2e15 s (JobRows::milliseconds int cast; wrapped value on 8.2-8.4); failing tests in 2a34d1b; L11.23.0 suite green with Pest ^3.0 after a test fix (27f659d)
Stop-the-line task (owner decision 14). The first runs of `.github/workflows/tests.yml` on
`claude/hopeful-hawking-njeu3v` fail: most legs at the `composer show ...` step, the Laravel 11 legs at "Resolve the
leg", the static job at PHPStan and the coverage job (85 % minimum, never measured). Diagnose every failing job from
the run logs (GitHub MCP tools), fix the workflow and/or code, and prove it on a draft PR to the integration branch.
Acceptance: every job of `tests` green on the PR head, including the prefer-lowest legs (L11 with Horizon ^5.29.1 and
predis ^2.0.2, L12, L13) on phpredis and predis and the cluster job; the coverage threshold measured and met (or
lowered to the measured value with a written justification); then green on the integration branch after the merge.

### T14 WP10 Operations commands — `done` (depends on T03–T09, T12)
QA: 2026-10-19 11:07 UTC: accepted by the owner on 2026-10-19 after the round 4 escalation: the round 1-3 findings are fixed and the spec tester approved round 4; the 3 minor round 4 findings (error replies outside RedisErrorReply's list such as NOREPLICAS and TIME inside scripts; the Horizon Timeouts fix ignoring block_for; a later commented no-value token line) move to the patch backlog T19, and their probes in 4f8e293 are parked with skip('T19 ...') so they turn into regression tests when fixed
QA: 2026-10-19 10:59 UTC: round 4 rejected: 3 minor findings, all confirmed by independent verification; escalated to the owner again. round 4 approved by the spec tester: the round 3 findings are fixed with tests that build the real clients (CheckRedisClientTest for phpredis/predis single and cluster, Horizon, persistent, prefix; CheckRedisRepliesTest on redis 7.4/6.2 and valkey 7.2/8), differential fuzz of the Timeouts verdict and fix line against RedisManager clients agrees, install over null/(NULL)/empty literals writes the token in place and re-runs as a no-op, --docker resolves the token; pest phpredis 2910 / predis 2908, phpstan, pint, rector, 8.2 lint; real --live-push-test 200/403/401; first-dashboard e2e on a T13 trial merge 44 s / 34 s; trivial test 48d0d7f; CI 39231925782 and 39236204678 green | round 4 rejected by the adversarial tester: (1) error replies outside RedisErrorReply's list still get 'Check that Redis is reachable' under a reachable Storage line: NOREPLICAS (min-replicas-to-write) and a server without TIME, which the package only calls inside scripts where the reply is 'Unknown (Redis) command called from (Lua) script' (verified, minor); (2) the Horizon connection's Timeouts fix adds read_timeout 2.0 to database.redis.default, which the redis queue connection also uses, so with block_for 5 every idle blocking pop fails after 2 s on both clients (verified, minor); (3) install with KEY=null and a later KEY=null # comment fills only the first line, so the app still reads no token and install exits 1 blaming an unclosed quote (verified, minor); failing probes in 4f8e293; about 9,000 random timeout placements matched the real clients on L13.37/predis 3.7 and L11.23/predis 2.0.2; trial merge with T13 green
QA: 2026-10-19 09:39 UTC: owner granted QA round 4 after the round 3 escalation (2026-10-19); round 3 findings fixed: (1) check judges the client each Laravel connector builds (phpredis/predis, server/cluster: timeouts, persistent, prefix) and every fix line names a place that client reads, proven against the real clients on every CI leg; (2) Redis error replies of a server that answered get their own fix (READONLY: point at the writable primary; ACL refusals: the refused command and its ACL SETUSER rule, also inside scripts on Redis 6.2/7/Valkey; OOM, auth and more), tested on real servers; (3) install writes the token in place of null/(null)/empty/(empty) as Laravel's env helper reads them (cases, quotes, CRLF, --docker, re-run no-op)
QA: 2026-10-19 08:15 UTC: round 3 rejected: 3 findings, all confirmed by independent verification; escalated to the owner: 3 rejected rounds. round 3 approved by the spec tester: every Arch Q7/Q9 WP10 and Add Q9' WP10 item and decisions 12-13, round 1-2 findings fixed with tests; pest phpredis 2341 / predis 2339, phpstan, pint, rector, 8.2 lint; real --live-push-test 200/403/401 on 13.2.4 and 12.4.13; dry-run counts equal /metrics; flush deleted exactly the 18 package keys; install re-run no-op; first-dashboard e2e on a T13 trial merge 45 s / 44 s; trivial L11.23 test-order fix in 155d15a, CI 39211736441 green | round 3 rejected by the adversarial tester: (1) check's Timeouts block merges Redis options as only the phpredis single-server connector does, so predis with read_write_timeout in database.redis.options or the connection's options and a phpredis cluster with timeouts on its nodes get 'has ... timeout 2 s' ok while the client has none (verified, major; also a false Persistent warning on predis); (2) a read-only storage Redis gets the fix 'Check that Redis is reachable' right after Storage reports it reachable (verified, minor); (3) install treats HORIZON_OBSERVABILITY_TOKEN=null/(null)/empty/(empty) as an existing token, writes nothing and exits 1 with 'shorter than 16 characters' (verified, minor); failing probes in 01b1577; trial merge with T13: 2 conflicts (provider command list and its test), gates and first-dashboard e2e green
QA: 2026-10-18 03:08 UTC: round 2 rejected: 6 findings. round 2 rejected by the spec tester: check probes routes with the raw domain, so HORIZON_OBSERVABILITY_DOMAIN=https://metrics.internal or {tenant}.metrics.internal (routes answer; EndpointUrl handles both) gives 6 false 'no route answers it' errors and exit 1 in check and install; untested check states (Storage Prefix braces and the batching driver, both Arch Q7 items; Queues topology/non-redis/unreachable; live off with stream settings; Configuration Enabled/Namespace/Config file; database/sqlsrv/memcached store timeouts) and unasserted numbers (Storage worker/scrape timeouts, live 'shared for N s', last push 'N s ago', non-zero error/warning totals), all correct in probes; gates green (pest phpredis 2168+2 skips, predis 2166+4, cluster 12/12 on both, phpstan 9, pint, rector, 8.2 lint 343 files), round 1 fixes each have a test, trial merge with T13 green for T14 (only T13's 3 known round 2 probes fail), first-dashboard e2e on the merge: Grafana 13 34 s, 12 27 s, home page /d/horizon-observability on both, second install no-op | round 2 rejected by the adversarial tester: check's Timeouts block reports Laravel 13's failover and storage cache stores as '(…, no network)' ok, even with a Redis store without timeouts or the skeleton's MySQL database store in front, or an S3 disk; a package domain written with its scheme (https://metrics.internal, which Laravel's routes accept) makes the Routes check build http://https://… and report all 6 endpoints as 'no route answers it' (check and install exit 1, wrong fix); failing probes in ba3b284; round 1 fixes confirmed on real containers (Grafana 12.4.13 with domain and rotation list, 13.2.4 with live off: targets up, Infinity OK, home redirect, re-run no-op), flush on 3-master and 1-node clusters and a read-only replica, Redis 6.2 and Valkey 7.2; trivial test portability fix in 0ae42fd
QA: 2026-10-18 00:29 UTC: round 1 rejected: 11 findings. round 1 rejected by the spec tester: check's dry run counts empty families (attempts '6 families, 1 sample' vs 1 family in /metrics) and no test proves the dry-run counts; the Job classes cap and age, Grafana link active/invalid/not-on-route, Capabilities and slow-dry-run results are untested; install --docker renders --live=poll when live.enabled=false; gates (pest phpredis 2123 and predis 2121, cluster 12/12 on both, phpstan 9, pint, rector, 8.2 lint, L11.39.1/Horizon 5.29.1 leg), first-dashboard e2e on the T13 merge (Grafana 13 41 s, 12 36 s, re-run no-op) and real --live-push-test 200/401/403 are green | round 1 rejected by the adversarial tester: flush buffers every key name (about 400 B per key, 128M exhausted at 600k ready_at keys); install --docker sends a comma-separated rotation token list whole as one bearer to Prometheus and Infinity, so both get 401 (seen on Prometheus 3.15.0 and Grafana 12.4.13); --token with a space, ' #' or a quote breaks or truncates .env while install exits 0; live.enabled=false still gets a poll render and /live; HORIZON_OBSERVABILITY_DOMAIN is ignored by the snippets; the Grafana 13.2.4 home page is a copy without alert annotations; DemoCommandTest is clock-flaky; failing probes in 603dd64
Arch Q7, Q9 table. `install`, `check`, `flush` commands, `Support/Diagnostics`, `about` integration.
Addendum (Add §Q9′ WP10): `check` gains the live block (enabled; stream URL, token set or missing, stream id; optional
`--live-push-test`, which pushes one `tiles` frame to stream id `<id>_check` and reports the HTTP status with the Add
§R4.10 hints for 401 and 403); `install` prints the Prometheus job at 10 s, the Grafana service-account commands (role
Editor) and the render commands for each live mode; `flush` also deletes `live:*`. `tests/Feature/Console/StreamCommandTest`
belongs to T12.
Acceptance: Arch Q9 WP10 row. Addendum: seeded live cases (missing token, 401 token, 403 token, invalid stream id).
Onboarding (README decision 12, outranks other T14 wording where they conflict): `install` is interactive and
idempotent with `--no-interaction` defaults: publishes the config, generates a token and writes
`HORIZON_OBSERVABILITY_TOKEN` to `.env` (never overwriting an existing value), detects the app URL and Horizon path, prints
the exact Prometheus scrape job and Grafana steps, and ends with the URL to open. `install --docker` writes
`docker-compose.observability.yml` + `docker/observability/**` (Prometheus at 10 s with bearer auth, Grafana 13 with
Infinity + Business Text, datasources, the rendered dashboard and alerts) so `docker compose -f docker-compose.observability.yml up -d`
shows the dashboard with data; `--grafana=12` selects 12.4.13. A demo (`install --demo` or `horizon-observability:demo
{--jobs=200}`) dispatches a short burst of sample jobs (completed, failed, retried, delayed, one batch) on the default
Redis queue, refuses in production, and is safe to run twice. `check` prints one actionable fix line per problem.
Acceptance additions: a scripted e2e (`tests/e2e/first-dashboard.sh`, weekly CI) starting from `composer create-project
laravel/laravel` + Horizon reaches a dashboard with non-empty Overview tiles in <= 5 minutes using only the README quick
start commands; `install` re-run is a no-op; `--docker` output passes `docker compose config`.
Notes from phase B (2026-10-17): `AccessPolicy::mode()` strings (Add §C2.4); `BatchReader::driver()`,
`connectionName()`, `table()` and `available()` (throws when the database cannot be reached); `JobClassCache::generatedAt()`;
the dry run can parse `exporter_collector_success`/`_duration_seconds` from `Exporter::scrape()` (which also logs config
problems); report whether the Grafana link is active (valid URL and the middleware in `horizon.middleware`, T09);
ServiceProviderTest "runs the other command stubs successfully" still runs install, check and flush: adapt it when they
become real (T08 removed cache and clear).

T14 addition (phase B review): `check` warns when the default cache store or the batching DB connection has no
connect/read timeout (a hung store can cost a scrape its budget), and the README says so (T15).

### T15 WP11 Integration, docs, release — `in progress` (depends on everything)
**Prod-first scope (owner, 2026-10-19; README decision 15).** T15 starts now, in parallel with the end of T13 and before
T17, and keeps only what the office production launch needs: README (quick start first, then configuration, the 10 s
scrape job, Grafana 12/13 setup, live modes, security, production setup, known limitations incl. the owner-approved
dashboard deviations and the T19 known issues), UPGRADE.md (office migration, Appendix G, namespace `office`), CHANGELOG,
CONTRIBUTING, SECURITY, CODE_OF_CONDUCT, one `art/` screenshot, a cold-read review of the README, full matrix green, then
the owner stops: office dry run, tag `v0.1.0`, Packagist, repository transfer. Before `legacy/` and `planning/` are deleted,
the T19 backlog moves to GitHub issues. Deferred to T19: the blocking weekly e2e with the perf report and live
screenshots, the Appendix H mapping audit, new side-by-side screenshot sets (T13 QA already compared them).
README (English: install, config, Prometheus scrape job at 10 s, Grafana 12/13 setup, live modes, security), UPGRADE.md
(office migration, Arch Appendix G, namespace `office`), CONTRIBUTING, SECURITY, CODE_OF_CONDUCT, `art/` screenshots,
`tests/Contract/*`, delete `legacy/` and `planning/`, full matrix green, side-by-side screenshots vs baseline on both
Grafana versions, every Appendix H bug mapped to a passing test, office dry run, tag `v0.1.0`.
Addendum (Add §Q9′ WP11): README sections Live Overview (modes, requirements, failure modes), Performance (budget,
settings) and Scrape interval; the weekly e2e adds the perf report and screenshots for `--live=poll` and `--live=stream`,
and becomes blocking. Acceptance additions: the budget report is green on the weekly run; live screenshots show live
values that change between two captures 2 s apart (stream).
Onboarding (README decision 12): README opens with a 3-step quick start (`composer require`, `php artisan
horizon-observability:install --docker --demo`, open Grafana) and one screenshot; configuration, security, live modes,
performance and production setup follow below. Acceptance: a newcomer can follow the quick start without reading anything
else (validated by the T14 e2e script and by a cold-read review of the README by a fresh agent).
Stop and ask the owner before the office dry run, pushing any tag, Packagist registration and the repository transfer.
When deleting `legacy/` and `planning/`, rewrite CLAUDE.md to contributor conventions only (no planning references) and
remove `/legacy` and `/planning` from `.gitattributes`.
Notes from phase B (2026-10-17) for the README: indexes on `job_batches(created_at)`, `(finished_at)`, `(cancelled_at)`
and scheduling `queue:prune-batches`; the 10 000-link read cap and that `(other)` cannot be a filter (T07); narrow
`job_class_paths` when `horizon-observability:cache` (and so `optimize`) dies on an unloadable class, and the runtime
cache can be up to 1 h stale after a deploy without `cache` (T08); re-cache routes after changing the Grafana URL on
Horizon < 5.45, and run `config:clear` after `composer remove` (T09); Horizon's `next` (6.x) has no Blade sidebar, so the
link is silently absent there (T09); Laravel 13.26+ `Queue::forward()` (T05); the dev loop, the perf profile
(`docker/perf/README.md`) and the `variables_order` note (T11); the Horizon floor `^5.29.1`. For the full matrix (T18): the L11
prefer-lowest leg resolves Laravel 11.23.0 with Horizon 5.29.1, predis 2.0.2 and Pest 3.0.0 (the leg pins Pest ^3.0,
since Pest 3.8 needs collision 8.7 and so Laravel >= 11.39.1; the floor check fails the leg if it moves), so 11.23–11.39.0
are no longer covered by the shim tests only; the 85 % coverage threshold is measured at 98.0 % (CI runs 39030156036 and
39040463193). Residual risks (T04): on 13.33+ with
`killOnTimeout=false` a SIGALRM right after the record `EVAL` opens the breaker for 30 s; predis plus a ready_at or
batch-link write interrupted by SIGALRM can desync the private client.

## Phase D: honesty (owner decision 13)

### T16 Exporter truth tests — `done` (depends on T03–T08, T12)
QA: 2026-10-19 08:06 UTC: round 3 approved by the spec and adversarial testers. round 3 approved by the spec tester: one clock (the storage Redis' TIME) in record_attempt.lua and new_series.lua with a sticky release, the queue map over every master's pools (deploy case mail 5 / sms 5 / push 3), round 1 fixes still covered, 7 mutations caught; Arch Q1.5/Q4/Q6.11/App D/App E and Add D′ match the scripts; pest phpredis 2443 + 3 skips, predis 2441 + 5, cluster 10/10 on both clients, Redis 6.2 with lua-replicate-commands no green, phpstan, pint, rector, composer validate --strict, 8.2 lint; real replica-clocks probe on Prometheus v3.15.0 increase() 3.04, resets() 0 for 3; CI 39215793258 and 39219353087 22/22; trivial README wording fix in d98dae0 | round 3 approved by the adversarial tester: fuzz of 25 seeds x 250 steps (Redis clock -1 h..+200 s, worker ±1 h, replicas ±15 min, 15 % failing hold scripts) never lowered a counter, bucket or sum on phpredis or predis and fails 5/5 without the HDEL; 1001-field release and inf/nan/1e400 values; real Prometheus v3.15.0 with replicas at -1 h/+1 h and workers at -1 h/+2 h gave increase() 3.11 and 2.09 for 3 and 2, resets() 0; multi-master queue map for numeric, repeated and balance-changing deploys; cost 10 round trips per scrape, 1 TIME per new series; gates green on both clients, CI 39215793258 22/22
QA: 2026-10-18 03:47 UTC: round 2 rejected: 4 findings. round 2 rejected by the adversarial tester: the hold compares the worker's clock with whichever replica answers, so under the Arch Q1.5 one-target contract two replicas 15 s apart (inside the stated budget) export 0,3,0,3 at the end of the hold (Prometheus v3.15.0: increase() 6.02 for 3 attempts, resets() 1; probes/replica-clocks); a worker clock more than 90 s ahead drops an already exported counter to 0 for 180 s later on (the README says such a creation time is not trusted); a balance=false option changed during a deploy keeps only the first master's pool, so processes per queue read 2 instead of 5; suggested fix: Redis TIME for created and for the age; failing probes in ac5c928; gates green at c288694: pest phpredis 2426 / predis 2424, phpstan, pint, rector, 8.2 lint, CI 39093535411 22/22 incl. the real restart on valkey 8 and redis 6.2
QA: 2026-10-18 01:28 UTC: round 1 rejected: 8 findings. round 1 rejected by the spec tester: (1) the real Redis restart truth test is skipped on every CI leg (no redis-server; run 39079692868), so 'Redis restart reset' is gated only by the simulated run_id change; (2) the 75 s hold claims 15 s for jitter and clock skew, but the 5-60 s dataset never tests the worst alignment (max first age 67.5 s vs 74.999 s at S=60) or any skew (an abs() mutation survives), and at S=60 with ±7.5 s any worker clock behind the exporter breaks it; (3) the README says invariants are checked at every scenario scrape, but checking them in Scenario::scrape() fails 2 tests on both clients (waits 4 > attempts 3 for a held new class on a known queue; oldest age 0 with 3 pending); (4) Arch Q6.11 (9 commands, EVAL size) and ADDENDUM D′ (record_attempt KEYS without created) are stale; gates green on phpredis and predis incl. cluster 9/9, phpstan, pint, rector, 8.2 lint, promtool, CI 22/22; probes reproduce | round 1 rejected by the adversarial tester: horizon_supervisor_queues renumbers numeric queue names (array spread over int keys, so queues 1,2,7 become 0,1,2; HorizonTruth also drops queue "0"); a balance=false option with a repeated queue gets pool "mail,sms" instead of Horizon's "mail,sms,mail"; the 75 s hold makes a known queue export more waits than attempts when a new class arrives there, breaking the suite's own invariant, and no scenario covers it; Arch Q4 contract rows and the T15/T17 notes still lack the hold and the zero-filled histograms (failing probes in e7abae0; trivial coverage-gate fix in 4e0b5d0); gates green at 063b66a: pest phpredis 2171 / predis 2169, cluster 9/9, phpstan, pint, rector, 8.2 lint; truth group green on Redis 6.2/8, Valkey 7.2/8 and PHP 8.5, CI 39079692868 green
Scenario engine in `tests/Truth/` (scenario DSL: queues, jobs with outcomes, durations, delays, batches; a deterministic
clock where possible; real worker via RunsWorker) + expected-value model. Truth tests per metric family of the catalog
(Appendix B/B′): exact equality with the scenario's expected values; invariants; cross-check with Horizon's JobRepository/
SupervisorRepository/MasterSupervisorRepository; scrape-interval invariance of increase() (simulated scrapes at 5/10/60 s);
Redis restart reset; sync/deferred/background exclusion. Coverage gate: a test fails if a catalog family has no truth test.
Acceptance: green on phpredis and predis; each family listed in a coverage table in the test README.

### T17 Dashboard truth tests — `in progress` (depends on T13, T16, T11)
**Owner decisions (2026-10-19)** on the design questions of planning/T17-DESIGN.md section 18: counter resets and
in-range scrape gaps are documented as is (deviations D-R1, D-R2, D-G; ADDENDUM R3.1 to be amended), range-fed gauge
tiles in `--live=off` show the last grid point (D-L), the three `increase()` cells (#6 F/G, #24 K) stay approximate with
an honesty bound, and the CI policy is as proposed (label-gated drafts; ready PRs, master and weekly always; a required
check once T13 and T17 are merged; one job for both Grafana versions; a weekly non-blocking `grafana:latest` leg).
**Prod-first (README decision 15):** T17 stops after WP1 (the thin end-to-end slice on `wip/T17-dashboard-truth`); WP2-WP8
move to the patch backlog T19 and resume after the production launch.
Pipeline: T16 scenarios → workbench app → real Prometheus (dev stack, 10 s scrape, or promtool backfill of recorded
expositions for determinism) → Grafana 12.4.13 and 13.2.4 → for every panel/target of the rendered dashboard (off, poll,
stream) evaluate through `/api/ds/query` with interpolated variables and compare with the expected values from the
scenario model (tile values, captions, colours/levels, table cells, series points within a tolerance only where
Prometheus extrapolation is documented). Cases: All vs each filter, empty state, counter reset, 2 replicas, scrape 5/10/60 s,
range 15 m / 1 h / 6 h. Playwright DOM check of tile texts. CI job `dashboard-truth` on changes to `src/Grafana/**`,
collectors, Lua, `composer.json`, plus weekly. Coverage gate: every panel id and target refId has at least one truth case.
T13 acceptance addition: every WP8B/8C/8D step keeps T17 green (T13 may start T17's harness early).

## Phase E: after the production launch

### T19 Patch backlog — `todo` (depends on T15)
Everything the owner deferred under the prod-first rule (README decision 15): non-critical QA findings and notes, fixed
in batches after office runs the package in production. Before T15 deletes `planning/`, every item moves to a GitHub
issue (label `backlog`) and this section points there. Each item names its origin; parked probes are skipped tests whose
skip reason names the item, so a fix turns them into regression tests.
1. check: Redis error replies outside the RedisErrorReply list get the reachability fix under a reachable Storage line:
   NOREPLICAS (min-replicas-to-write), TIME refused inside the package scripts ("Unknown (Redis) command called from (Lua)
   script"), "max number of clients reached", CLUSTERDOWN/TRYAGAIN (T14 round 4; probes in AdversarialRound4CheckTest).
2. check: the Horizon connection's Timeouts fix ('read_timeout' => 2.0 on database.redis.<horizon.use>) ignores
   queue.connections.*.block_for of the redis queue on the same connection; following it makes idle blocking pops fail
   (T14 round 4; probe in AdversarialRound4CheckTest). Until fixed, the README warns to keep read_timeout above block_for.
3. install: KEY=null followed by a later commented no-value KEY=null # x fills only the first line (T14 round 4; probe in
   AdversarialRound4InstallTest).
4. check, minor wording and edge cases (T14 rounds 3-4 notes): a url query with a non-positive timeout gets a fix line
   that has no effect; 'Job classes / Cap' and 'Live / Last push' answer an ACL refusal with "Fix the storage connection
   first"; phpredis returns false instead of throwing for NOPERM/WRONGTYPE on ZRANGE/HGETALL (BatchLinks wraps it,
   AttemptStore reads no classes); EnvFile reads `null#x` and escaped quotes unlike phpdotenv; 'shared for 0 s' with
   cache_ttl 0; lastPush can read 1 s low; EndpointUrl ignores a parameterised domain; --live-push-test with live disabled
   prints nothing; the Docker URL question comes before the install banner; on predis a connection url query overrides
   the private clients' timeout caps (pre-existing, RedisClients::standalone()).
5. Dashboard: tiles and tables differ at the period edges. Grafana formats \${__from/__to:date:seconds} with Math.round
   while the tables use the exact millisecond edges, so a scrape between an edge and its rounded second counts in one of
   them only; with a browser UTC offset that is not a multiple of the step the tiles' last step stops short of the period
   end (Asia/Kathmandu, Failed tile at 2 min over 6 h: 13,196 vs 13,283); #14 has no beforePeriod guard, so its points can
   sum above delta() when from rounds onto a boundary. Suggested fix: `@ ${__from}e-3` / `@ ${__to}e-3` (Prometheus 3.15.0
   accepts the millisecond literal). T13 round 4 finding 2 (both testers); probes in c39942c and 982293a, parked with skip.
6. Dashboard: a pooled balance=false supervisor is left out of the off-mode #3 Supervisor Processes tile and the #5 tables
   under Queue=All; the dashboard should use T16's horizon_supervisor_queues (T13 round 3 note, T17 design 14).
7. Dashboard: a custom Job/Batch variable value that matches no option and contains a backslash (stale shared link) breaks
   the PromQL string of the filter text (pre-existing since the pilot; T13 round 3 finding 3 remainder).
8. Dashboard: Supervisor Processes under a Job filter with no busy workers may show '—' where 0 is honest (T17 design 14,
   unconfirmed).
9. T17 WP2-WP8 (planning/T17-DESIGN.md section 16): the full dashboard truth suite, coverage gate to empty PENDING,
   sensitivity gate, the required `dashboard-truth` check; the deviation register with the owner decisions of 2026-10-19.
10. Exporter (T16 notes): Lua::eval sends whole scripts (record_attempt.lua 8.5 KB per job end; strip comments or use
    EVALSHA); label values cut at 200 bytes can duplicate label sets of long balance=false pools (InvalidMetric drops the
    supervisor families); spaced balance=auto queue options differ between the model and the collector; HELP texts of
    the held histograms do not mention the 90 s hold; FirstValueTruthTest's 1 s margin; a test across the 500-field HDEL
    chunk; Arch Q7 tree and ADDENDUM C2.4 lack new_series.lua, Redis/ServerClock and the new @internal methods.
11. T15 deferrals: the blocking weekly e2e with the perf report and the poll/stream live screenshots, the Appendix H mapping
    audit.
12. Dashboard (T13 final verification): the batch-kind exclusion in every attempts query is built from instant
    selectors, so with a Batch filter a class that is no longer exported at the period end (failed scrape at the end, an
    app stopped inside the period) slips past it and the tables overcount (936 vs 351); take the exclusion's class sets
    over the period, like Selection::queues($__range). The `queue` variable comes from the instant pending gauge
    (allValue null), so after a reload the queues of an app decommissioned inside the period are filtered out of tiles
    and tables alike.
13. Installer and docs (T15 cold read and writers): with --docker, install prints http://localhost:3000 whatever
    GRAFANA_PORT is (InstallCommand::DEFAULT_GRAFANA_URL), and its stream-mode curl lines too; DockerStack's compose
    header hard-codes port 8000; a fresh skeleton's install prints the read_timeout fix of item 2 to every new user; the
    CI dist job does not require resources/ or UPGRADE.md in the archive; the tests.yml header still says the e2e job is
    "non-blocking until T15"; the compose `stream` service restart-loops until docker/grafana/live-token exists; the PR
    template says "Unreleased" while the CHANGELOG uses "[0.1.0] - Unreleased"; the links to the security advisories
    form and Discussions work only after the transfer to sushi-market and once both are enabled in the repository.
14. Process: the frozen board tooling branch `wip/board-sync` (owner, 2026-10-19), only if the board is kept after the
    repository transfer.

