# Horizon Observability: architecture addendum (R1–R4)

- **Amends:** `ARCHITECTURE.md` (final design, 2026-10-16). Where the two conflict, this addendum wins. Section numbers such as Q5.2 or Appendix E refer to the architecture.
- **Status:** implementation-ready, dated 2026-10-17.
- **Scope:** the owner's four additional requirements:
  - R1: the `ho` namespace and the self-metric names;
  - R2: fast Grafana refresh;
  - R3: precision at slow scrape intervals;
  - R4: a "Live Overview" without scrape lag.
- **Evidence:** probes on Grafana 13.2.4 and Prometheus 3.15.0. The Grafana side included the bundled Prometheus plugin 13.2.3, Infinity 4.1.1 and Business Text 6.3.0. The data was synthetic with a realistic volume. Every "verified" statement below was observed in these probes, or read in the Grafana 13.2.4 sources shipped as source maps in the image. Appendix P lists the scripts, commands and raw results.
- **Review:** an adversarial review on 2026-10-17 re-ran the key probes on **Grafana 13.2.4 and 12.4.13** (both versions are supported, minimum 12.3). It found two Grafana 12 differences (push role, rows sharing a timestamp), a broken hidden-panel mechanism, a stampede-unsafe freshness check and a non-scrape-agnostic stall rule, all corrected below. "Verified on 12 and 13" marks facts observed on both versions. Appendix P′ lists the review probes; the Review log at the end lists every change and what stays unverified.

Words used with a fixed meaning:
- **S**: the Prometheus scrape interval of the package's scrape job.
- **reference stack**: the probe environment:
  - hardware: 4 vCPU and 15 GB RAM;
  - images: Docker with host networking, Prometheus 3.15.0 and Grafana 13.2.4;
  - data: the *medium* synthetic profile (Appendix P), about 5,300 active series with 26 hours of 15-second history;
  - browser: headless Chromium at 1920×1080 in kiosk mode.
- **refresh cycle**: from an auto-refresh tick to the last data response of the visible panels.
- **live snapshot**: the JSON document computed from Redis and Horizon at request time (R4).
- **poll mode / stream mode**: the two Live Overview renderings (R4).
- **live tile**: an Overview tile whose current value comes from the live snapshot.
- Panel ids are those of today's `queues.json` and stay stable:
  - Overview tiles: #1 Horizon, #3 Supervisor Processes, #2 Pending, #8 Completed, #9 Delayed, #4 Failed, #21 Alerts;
  - #23 is the hidden alert-sound panel;
  - tables: #5 Queues (repeated per supervisor), #17 Batches, #6 Jobs, #16 batch list;
  - #7 Pending by job and #14 Completed by job;
  - #18, #19, #20 and #22 are the Infinity job lists;
  - rows: #10 Overview, #11 Current Workload, #12 Details · Jobs, #13 Details · Batches.

---

## A0. Summary

### A0.1 Owner requirements: verdicts

| Requirement | Verdict | What ships | Why |
|---|---|---|---|
| **R1** Default namespace `ho`; self-metrics `<ns>_exporter_*` | **Accepted as proposed.** | `ho_queue_jobs_total`, `ho_horizon_status`, … The five self-metrics become `<ns>_exporter_collector_success`, `_collector_duration_seconds`, `_build_info`, `_config_problems` and `_attempts_dropped_total`. Two new live self-metrics: `<ns>_exporter_live_push_timestamp_seconds` and `_live_push_failures_total`. Office keeps `HORIZON_OBSERVABILITY_NAMESPACE=office`. | One prefix (`ho_exporter_`) lists all package health series. It follows the `*_exporter_build_info` convention and never reads `ho_horizon_observability_*`. The node_exporter style (`ho_scrape_collector_*`) was considered and rejected (R1). |
| **R2** Fast refresh | **Accepted. Measured: a refresh drops from 6.5–7.1 s to 0.66–0.94 s with a pixel-identical look (0.84–1.30 s with the shipped variable policy, before O6).** The sequential PromQL replay was reproduced by the review (legacy 14.7 s, v5 1.47 s, v5b 1.40 s). These numbers exclude the batch-scope join that O2 needs for correctness; with the cheap form specified in O2 it adds about +45 ms per scoped range target at Batch=All (measured), to be absorbed by O6. | Five verified optimisations (O1–O5), three further ones (O6–O8), datasource settings, a budget and a repeatable measurement method in the dev stack. | Prometheus evaluation is the bottleneck: 14.8 s of PromQL per refresh and 4.6 billion samples. Three things dominate: `[1d]` counter lookbacks, a job-class regex of 160 alternatives inlined about 300 times, and the per-supervisor table recomputed once per supervisor. Browser connections and HTTP/2 are not a factor (measured). |
| **R3** No precision loss at slow scrapes | **Accepted for counters and histograms, and for min/max after a storage change. Gauges are sampled by nature. The recommended interval is 10 s; 5–60 s is supported.** | A separate scrape job at 10 s. Every dashboard window is derived from one hidden `scrape_interval` variable or from render constants; Grafana's min interval is set per panel. Duration extremes use 15-second buckets merged over 150 s, so at S = 60 s each attempt reaches at least two scrapes while the scrape-time deviation stays within ±7.5 s, and at least one within ±37 s. | Counters are exact by construction. The only scrape-dependent windows were `BUSY_WINDOW = 15s`, the implicit `$__interval ≥ S` assumption and the 60-second min/max windows. |
| **R4** Live Overview (poll and stream) | **Accepted, with one Grafana constraint.** In stream mode a single stat panel cannot combine a live stream with Prometheus history. The look deviations this causes are listed in R4.1 for the owner to accept. | `php artisan horizon-observability:grafana --live=off\|poll\|stream` (default `poll`, the owner's confirmed default); the row is renamed "Live Overview" when live is on. **Poll:** `GET {base}/live` returns a snapshot cached 1 s with stampede protection. Pending and Delayed keep their Prometheus sparkline and take the live value, caption and colour (Mixed + `merge` + `configFromData`, verified on 12 and 13). **Stream:** `php artisan horizon-observability:stream` pushes every second to Grafana Live under a Redis leader lock. Pending and Supervisor Processes show the live value, caption and colour via `filterByValue` + `groupBy` + `rowsToFields` (verified on 12 and 13). Delayed keeps a 15-minute sparkline seeded from a windowed push (verified on 12 and 13, 180 rows). A Redis ring buffer of 5-second buckets gives a live RPM and failures per minute. | Verified on Grafana 12.4.13 and 13.2.4: the Mixed datasource combines sub-queries with `forkJoin`, so a never-completing live stream yields an empty panel. Live frames carry no refId, so `configFromData` cannot read config from a second live query. Grafana 12 keeps only the last of several pushed rows that share a timestamp, so the rows of one push are stamped 1 ms apart. The working shapes are recorded in R4.8. |

### A0.2 What changes in the architecture

| Q / Appendix | Change |
|---|---|
| Q1.4, Appendix B | Self-metric names (R1). Two live self-metrics. The HELP of `queue_job_duration_{min,max}_seconds` becomes "during the last 150 seconds" (R3). |
| Q2.1 | A third route, `GET\|HEAD {base}/live` (`horizon-observability.live`), with `ForceJson` and `Authorize:api`, registered only when `live.enabled`. The maintenance exception gains `"$base/live"`. |
| Q3, Appendix A | Default namespace `ho`. New `live.*` keys (6 env vars, `HORIZON_OBSERVABILITY_LIVE_ENABLED` … `_LIVE_STREAM_INTERVAL`). The boot-time keys list gains `live.enabled`. |
| Q4 | Default namespace `laravel` → `ho`. The rejected-alternatives entry "`horizon_observability_` as the business prefix" stays rejected. |
| Q5.1 | New render options: `--live` (default **`poll`**, so the committed `--defaults` render is the poll render), `--live-poll-interval`, `--live-stream-id`. `--scrape-interval` now defaults to `10s` and drives every window (R3). |
| Q5.2 | New hidden variable `scrape_interval`. `busy_window` defaults to `max(15s, 2×S)`. Refresh defaults to `S` (off and stream modes) or `--live-poll-interval` (poll mode). Variable refresh policy changes (O3). New hidden panel #24, the shared queue source (O5), which must carry an explicit `maxDataPoints`. |
| Q5.3 | Stream mode adds a core rule `<uid>-live` ("Live overview stalled", based on `timestamp()` so it is scrape-interval agnostic) and includes it in the Alerts tile selector. `<uid>-collector` reads `<ns>_exporter_collector_success`. |
| Q5.6 | WP8B: step 2 renders the prefix `ho_` (R1); a new step 12 applies the R3 windows. New sequential packages WP8C (performance) and WP8D (live panels). |
| Q5.7 | Item 2 also accepts the sentinel label `__batch_scope__` (O2) and `timestamp(sel) - sel` inside `max by (job)` (rule `<uid>-live`). Item 3 also accepts `${api_path}/live` with the `LiveSnapshot` fields as columns. Item 4: 5 core rules with `--live=stream`. |
| Q6.11 | Job end: the record `EVAL` gains 2 commands (ring buffer). The live snapshot costs Q + 5 Redis round trips per build (measured: 5.6 ms p50 for 20 queues on loopback) and at most 1 build per `cache_ttl` per scope across all replicas. |
| Q7 | `src/Live/**`, `Http/LiveController`, `Console/StreamCommand` (public command `horizon-observability:stream`), three new Lua files (`extremes_read`, `ring_read`, `lock`). |
| Q8.3 | Dev stack: a 10-second scrape job, a perf profile (synthetic backfill, continuation exporter, `bin/perf.mjs`), Grafana Live settings and a bootstrapped service account; Grafana 12.4.13 and 13.2.4 via `GRAFANA_IMAGE`. |
| Q9 | New WP-L1 (snapshot and endpoint), WP-L2 (stream command), WP8C and WP8D. WP0b, WP1, WP2, WP3, WP9, WP10 and WP11 change (Q9′). Q9′ maps them to the task ids of `planning/TASKS.md`. |
| Appendices C, D, E | Additions in C′, D′ and E′ below. |

### A0.3 Facts this addendum relies on (verified)

**Grafana 13.2.4 and 12.4.13: Live**

| Fact | Evidence |
|---|---|
| `POST /api/live/push/<stream id>` with Influx line protocol. **13.2.4** checks RBAC action `live:push`: the minimum basic role is **Editor** (Viewer and None get 403 "Permissions needed: live:push"; Editor and Admin get 200). **12.4.13** accepts any authenticated service account, including role None and Viewer (200). A token with role Editor therefore works on both. | Probe: service accounts per role on 13.2.4 (`results/live/push-*.txt`, re-run by the review) and 12.4.13 (review `gf/roles.py`) |
| Service account tokens are created with `POST /api/serviceaccounts` (`{"name","role"}`) and then `POST /api/serviceaccounts/<id>/tokens`. Token names are unique per org (a duplicate gives 400 `ErrTokenAlreadyExists`). | Probe, 12 and 13 |
| The channel is `stream/<stream id>/<measurement>`. In the WebSocket it appears org-scoped as `default/stream/<id>/<measurement>` (org 1). | Probe: WebSocket frames, 12 and 13 |
| A line-protocol push becomes one frame per measurement. The frame's fields are a `labels` string column, `time`, then the fields alphabetically. A first field named `labels` puts the browser frame in "labels" push mode, which aligns rows by time per label set. **13.2.4 keeps one row per tagless line; 12.4.13 keeps only the last of the rows that share a timestamp.** Rows stamped 1 ms apart are kept on both. | Probe P108/P402; review probes `gf/rv-debug`, `gf/rv-live2` (12 and 13); `StreamingDataFrame.push()` source |
| A newly subscribing panel immediately receives the last pushed frame (schema and data). Later pushes are **appended**, and the buffer is trimmed to `maxLength = maxDataPoints` and `maxDelta = range`, or to `maxDelta = buffer` ms with `maxLength = 2 × maxDataPoints` when the target sets `buffer`. | Probe P401/P403; `grafana/datasource.ts` and `StreamingDataFrame.ts` (identical logic in 12.4.13) |
| On every dashboard auto-refresh a live panel re-subscribes, and for about one second its data holds two pushes, so `rowsToFields` produces duplicate fields ("default · max 3 1", "… 2"). A `groupBy` on (`tile`, `key`) with `last` before `rowsToFields` removes them. | Review probe `gf/rv-live2`: 8–18 of 140 samples duplicated without `groupBy`, 0 with it (12 and 13) |
| If no row of a push has a `value` field, the frame has no `value` column, `rowsToFields` returns the frame unchanged and the stat shows the `level` field ("level 0"). A numeric sentinel (`value=-1`) with a value mapping `-1 → —` keeps the caption and shows "—". | Review probe `gf/rv-live` (13.2.4); `rowsToFields.ts` |
| Streaming frames have **no refId** (`refId` comes from the pushed schema, which never has one), and `StreamingDataFrame` does not copy the schema name. So `configFromData` (which selects its config frame by refId), `byRefId` and `byName` filters cannot address them. | Probe P202/P301–P317; source |
| `POST /api/live/publish` of a JSON frame to a `stream/…` channel returns 403 even for Admin. Field config cannot be pushed. | Probe, 12 and 13 |
| Push responses: malformed body → 500; an invalid or missing token → **401** when anonymous auth is off (both versions) and 403 with anonymous auth on (13.2.4); stream ids with a space, a dot or 64 characters are accepted (200). Pushes take 13–32 ms on localhost. | Probe, 12 and 13 |
| `[live]` defaults: `max_connections = 100` per instance, `message_size_limit = 8 MB` and `client_queue_max_size = 4 MB`. `ha_engine` is empty: "Live features work only on a single Grafana server" unless `ha_engine = redis`. | `conf/defaults.ini` in both images (identical) |

**Grafana 13.2.4 (and 12.4.13 where stated): dashboards, datasources and transformations**

| Fact | Evidence |
|---|---|
| The Mixed datasource collects each sub-datasource with `toArray()` and combines them with `forkJoin`. A panel mixing Prometheus with a Grafana Live measurement renders **nothing**. Mixed with Prometheus and `-- Dashboard --` pointing at a live panel also renders nothing (the Dashboard datasource waits for a Done state that streams never reach). | Probe P102/P105; `MixedDataSource.ts` (same in 12.4.13), `dashboard/datasource.ts` |
| Mixed with Prometheus (range) and Infinity works. `merge` (scoped with `filter: {id: byRefId, options: "A\|L"}`) followed by `configFromData` (refId C: `caption`→`displayName`, `color`→`color`) gives one value with the Prometheus sparkline, the live value, the live caption and the live background colour. When the live value is `null` (Job or Batch filter), `lastNotNull` shows the last Prometheus value. | Probe P201/P206 (forced `red` rendered as rgb 225,24,26); review probe `poll/rv-poll` R801/R805/R806 on 12.4.13 and 13.2.4 |
| `rowsToFields` with mappings (`caption`→`field.name`, `value`→`field.value`, `color`→`color`) works on Infinity rows and on live rows, one field per row. `filterByValue` selects the tile rows. Every unmapped field becomes a label of the output fields, so `tile`, `key`, `level`, `time` and `labels` must be mapped to `__ignore` (or dropped by `groupBy`); otherwise captions read like `… {key="", level="0"}`. | Probe P204/P401/P402; review probes on 12 and 13; `rowsToFields.ts` `getLabelsFromRow()` |
| The `color` handler of `configFromData` and `rowsToFields` sets `{mode: fixed, fixedColor: <value>}`. Named colours (`green`, `yellow`, `red`) work. | `fieldToConfigMapping.ts`; probe |
| `configFromData` matches mapping `fieldName` against the field **display name**, uses `allValues` for `mappings.*` and `lastNotNull` otherwise. `partitionByValues` only processes `data[0]`. | Source; probe P301–P317 |
| `byRefId` accepts `A\|L`; the `byName` frame matcher is a regex on `frame.name`. | `refIdMatcher.ts`, `nameMatcher.ts` |
| A windowed push (K rows per push, panel `maxDataPoints = K`) keeps exactly the latest window in the browser and seeds the sparkline on page open. `timeFrom` plus `hideTimeOverride: true` fits the sparkline to the window without a header badge. | Probe P403/P404 (K = 60); review probe `gf/rv-live` P505 with K = 180 rows 5 s apart on 12 and 13 |
| Scenes re-queries every variable with `refresh: 2` (on time range change) on **every auto-refresh**. Panels depending on them wait. | `SceneVariableSet.js`; captures (6 variable requests per refresh) |
| `SceneDataTransformer` interpolates variables, including repeat-local values, in transformation options. A repeated table using `-- Dashboard --` plus `filterByValue(supervisor == ${supervisor_title})` shows the right rows per repeat. | Source; probe `probe-share`; review probe `gf/rv-share` (12 and 13) |
| The `-- Dashboard --` source runs **once per refresh when it is a top-level panel**, also below the viewport, independent of the number of repeated consumers. In a collapsed row it runs twice per refresh on 13.2.4 and once on 12.4.13. **A source panel that is `display: none` before its first render never runs, and its consumers stay empty**, because `SceneQueryRunner` waits for a container width unless the panel sets `maxDataPoints`. With an explicit `maxDataPoints` the hidden source runs once per refresh. | Probe `share-refresh`, `share2-refresh`; review probes `gf/rv-share` (hidden from start: 0 runs; hidden after first render: 1 per refresh; hidden from start with `maxDataPoints: 100`: 1 per refresh; 12 and 13); `SceneQueryRunner.js` (`!maxDataPoints && maxDataPointsFromWidth && !_containerWidth`) |
| `[dashboards] min_refresh_interval` defaults to 5 s. A provisioned dashboard with `refresh: "1s"` is **stored as `5s`** and refreshes every 5 s. With `GF_DASHBOARDS_MIN_REFRESH_INTERVAL=1s` and a changed file, it stores `1s` and refreshes every second. The setting is applied at provisioning time. | Probe `probe-refresh`; review probe (stored `5s` on 12.4.13 and 13.2.4) |
| A panel-level `interval` (min interval) is interpolated (`$scrape_interval` → `intervalMs 10000`) and wins over the datasource `timeInterval` (15 s) even when it is lower. Any variable type works: Scenes interpolates `minInterval` generically. | Probe P502; `SceneQueryRunner.js` `minInterval ? interpolate(this, minInterval) : ds.interval` (12 and 13) |
| `$__rate_interval` is **not** derived from the panel min interval: the plugin sends the target's own Min step (`interval: templateSrv.replace(target.interval)`), and the backend derives `$__rate_interval` from it or, when empty, from the datasource `timeInterval`. The dashboard uses no `rate()`, so this only matters for future panels. | Plugin `datasource.ts` (13.2.3); the backend part is from Grafana's promlib and was not re-read |
| Prometheus plugin `jsonData`: `incrementalQuerying` (bool), `incrementalQueryOverlapWindow` (default `10m`), `cacheLevel` (`Low` default, `Medium`, `High`, `None`), `httpMethod`, `timeInterval`, `queryTimeout`, `prometheusType`, `prometheusVersion`, `disableRecordingRules`, `seriesLimit`. Incremental querying applies to a **panel request only if no target is instant, no expression contains `$__range` and the range ends at `now`**. The cache key includes the **interpolated** expression, so any target using `${__from}` or `${__to}` changes key on every refresh and is always fully re-queried. Otherwise only the overlap window is re-queried (measured 609–629 s instead of 3,600 s). | Plugin source maps (`datasource.ts` `shouldUseIncrementalQuery`, `QueryCache.requestInfo`, `getPrometheusTargetSignature`; same in 12.4.13); probe |
| Serving Grafana over HTTP/2 changes nothing (v5: 0.72–0.83 s; legacy: 7.2–7.4 s). | Probe |

**Prometheus 3.15.0 and the current dashboard**

| Fact | Evidence |
|---|---|
| Legacy dashboard, 1-hour range, reference stack: first load 7.8 s, refresh 6.5–7.1 s. 19 requests per refresh, 6 of them variable queries. 14.8 s of PromQL evaluated sequentially per refresh, 4.6 G queryable samples and 2,143 KB of interpolated PromQL. | `results/legacy-1h.json`, `*.replay.json`; replay reproduced by the review (14.7 s, 4.6 G, 2,143 KB; v5 1.47 s, 55 M; v5b 1.40 s, 48 M) |
| `job_class` "All" is the explicit list, so `$job_class` interpolates to a regex of 160 alternatives. Tile #8's 7.8 KB template becomes 350 KB of PromQL, #4 becomes 438 KB and each #5 repeat 292 KB. | Captures |
| `last_over_time(x[1d] offset 1h)` over 180 counter series costs 85 ms and 1.04 M samples. `[10m]` costs 3 ms and 7.2 k. | Probe |
| On continuous data, the aggregate-first per-step formula (R2, O4) returns **exactly** the legacy `attempts_per_step` points: 0 of 241 differ, same sums (96,532 completed; 530 failed or timed out). It takes 43 ms and 79 ms instead of 775 ms and 1,665 ms. | Probe `formulas.py` |
| Legacy vs v5 at a fixed 1-hour range is **pixel-identical** (1920×1150, Overview plus Current Workload). The legacy Failed tile was still loading after 9 s. | Probe screenshots; review pixel diff (no differing pixel) |
| The O2 batch scope is not free. Wrapping the per-series vector in `unless on (job_class) <excluded classes>` raised #8 A from 52–66 ms to 403 ms when the exclusion set is always evaluated; with the absent-label switch of O2 it costs 96 ms at Batch=All and 308 ms at Batch=one kind. | Review probe `review/promql/batch_scope.py` against the probe TSDB |
| `laravel/framework` requires `guzzlehttp/guzzle`: `^7.8` at v11.23.0, `^7.8.2` at v12.0.0 and `^7.8.2 \|\| ^8.0` at v13.37.0. The Laravel `Http` client therefore adds no dependency at any supported floor. | `composer.json` at the v11.23.0 and v12.0.0 tags (raw GitHub, fetched by the review); installed v13.37.0 copy |

---

## R1. Metric namespace and self-metrics

### Decision

- **Namespace.** `namespace` (env `HORIZON_OBSERVABILITY_NAMESPACE`) defaults to **`ho`**. Names are `<ns>_<family>`, for example `ho_queue_jobs_total`, `ho_horizon_status`, `ho_horizon_supervisor_processes` and `ho_queue_job_duration_seconds_bucket`. An invalid value falls back to `ho` and reports a config problem. `''` still means no prefix (documented as not recommended, because `exporter_build_info` is generic).
- **Self-metrics** use the prefix `<ns>_exporter_` (collector id `self`). The `Metric` enum values (the name without namespace) are:

  | Case | Old family (architecture Q1.4) | New family | Type | Labels |
  |---|---|---|---|---|
  | CollectorSuccess | `horizon_observability_collector_success` | **`exporter_collector_success`** | gauge | `collector` |
  | CollectorDuration | `horizon_observability_collector_duration_seconds` | **`exporter_collector_duration_seconds`** | gauge | `collector` |
  | BuildInfo | `horizon_observability_build_info` | **`exporter_build_info`** | gauge (1) | `version`, `php`, `laravel`, `horizon`, `redis_client` |
  | ConfigProblems | `horizon_observability_config_problems` | **`exporter_config_problems`** | gauge | – |
  | AttemptsDropped | `horizon_observability_attempts_dropped_total` | **`exporter_attempts_dropped_total`** | counter | – |
  | LivePushTimestamp | – | **`exporter_live_push_timestamp_seconds`** (new, R4.11) | gauge | – |
  | LivePushFailures | – | **`exporter_live_push_failures_total`** (new, R4.11) | counter | – |

  `exporter_` means "the package's own health". `attempts_dropped_total` is produced by workers and the live series by the stream command, but all of them are exported by the exporter from storage `meta`. One prefix therefore lists all package health: `{__name__=~"ho_exporter_.+"}`.
- **Office continuity** is unchanged: `HORIZON_OBSERVABILITY_NAMESPACE=office` keeps `office_queue_*` and `office_horizon_*`. Self-metrics are new in any case (`office_exporter_*`). Appendix G gains one line: "self-metrics are `office_exporter_*`".
- **Everything that renders names follows:**
  - the committed `--defaults` render uses the prefix `ho_`;
  - the opt-in rule `<uid>-collector` is `min by (job, collector) (<ns>_exporter_collector_success{job=~"J"}) < 1`;
  - the README metric table, the `MetricCatalogTest` and the `MetricDocsTest`.
- No other family changes name or labels.

### Rationale

- The owner's choice. `ho_` is short and does not collide with known exporters (`app_`, `laravel_`, `node_`, `process_`, `go_`). `ho_horizon_status` reads naturally, unlike `horizon_horizon_status`.
- `<ns>_exporter_*` gives every package health series one discoverable prefix and matches the `*_exporter_build_info` convention (for example `node_exporter_build_info`).

### Rejected alternatives

- **node_exporter style** (`ho_scrape_collector_success`, `ho_scrape_collector_duration_seconds`): it splits self-metrics over two prefixes, and `scrape_` reads like Prometheus' own synthetic series (`scrape_duration_seconds`).
- **`ho_recorder_attempts_dropped_total`**: more precise about who drops attempts, but it breaks the single health prefix.
- **Keeping `laravel` as the default**: overruled by the owner.

### Risks

| Risk | Mitigation |
|---|---|
| `ho` is opaque to newcomers | The README and HELP texts explain it; the namespace is configurable. |
| Generic names with namespace `''` | Documented; `check` warns when the namespace is empty. |

---

## R2. Dashboard refresh performance

### Decision

#### 2.1 Measured causes

Reference stack, 1-hour range, after the first load. Each variant applies one more change to the legacy JSON with no visual change. The variants are text-level rewrites in `probe/variants.py` and `probe/variant5.py`.

| Variant | Change | First load | Refresh cycle | Requests / refresh | PromQL time / refresh (sequential replay) | Interpolated PromQL / refresh |
|---|---|---|---|---|---|---|
| v0 legacy | – | 7.8 s | 6.5–7.1 s | 19 (6 variables) | 14.8 s, 4.6 G samples | 2,143 KB |
| v1 | O1: counter lookback `[1d]` → `[10m]` | 4.8 s | 3.5–3.7 s | 19 | 5.1 s, 44 M | 2,143 KB |
| v2 | + O2: `job_class` "All" as `.+` | 4.1 s | 2.85–3.1 s | 19 | 4.4 s | 150 KB |
| v3 | + O3: variables refreshed on load | 4.1 s | 2.5–2.6 s | 13 | 4.2 s | 144 KB |
| v4 | + O4: aggregate-first tile sparklines (#8, #4) | 3.0 s | 1.37–1.68 s | 13 | 2.7 s | 127 KB |
| **v5** | + O5: shared source for the repeated Queues table (#5) | **2.1 s** | **0.66–0.94 s** | **10** | **1.45 s** | **57 KB** |
| v5b | v5 with `batch_kind` and `batch_title` kept on time-range refresh (the shipped O3 policy) | 2.1–2.8 s | 0.84–1.30 s | 12 | 1.40 s (review replay) | 58 KB |

- **6-hour range:** legacy 7.7 s per refresh; v4 2.2–2.5 s; v5 1.4–2.0 s (2.8 s of PromQL).
- **HTTP/2:** no change.
- **Look:** legacy and v5 screenshots at a fixed range are pixel-identical.
- **Overview row** (time from the refresh tick to the last Overview tile response): legacy 6.5–7.1 s, v5 0.48–0.72 s, v5b 0.81–0.97 s. The tiles wait for the `batch_kind` variable in v5b.
- **Upper bound for O2:** v2–v5b set `allValue: .+` without the batch-scope join (`probe/variants.py` says so). The join that O2 specifies adds about 45 ms per scoped range target at Batch=All (#8 A 52 → 96 ms, review probe) and small amounts on instant targets. WP8C measures it; O6 is expected to absorb it.

Causes, in order of weight:
- **C-P1, the `[1d]` lookbacks in `attempts_delta` and `attempts_per_step`.**
  - The start value is `last_over_time(sel[1d] offset R)`. There are 60 occurrences in the template, multiplied by the 4 table repeats.
  - Inside range queries, `last_over_time(sel[1d] @ from)` is evaluated as step-invariant, but every occurrence still decodes a day of samples per series.
  - Tile #4 A alone took 2.6 s and #8 A 1.3 s.
- **C-P2, the `job_class` "All" list** is inlined into every job-filtered selector.
- **C-P3, variables with `refresh: 2`** are re-queried on every auto-refresh: 6 queries with 2–3 dependency hops, about 350 ms before any panel starts.
- **C-P4, `attempts_per_step` at series level:** about 20 binary set operations per step on 180–360 series.
- **C-P5, the repeated Queues table (#5)** computes all queues once per supervisor and then filters with `and on (queue) …{supervisor=~"$supervisor_title"}`. PromQL has no push-down, so N supervisors cost N times.
- **C-P6, five attempts columns in #5 and #6** are computed by six separate `attempts_delta` evaluations (C, E, H, I, J×2): 374 ms against 50 ms for one query by (queue, status).
- **C-P7, the `min_over_time(sel[$__range])` start fallback** scales with the range length and dominates at 6 hours.

**Not causes:**
- browser connection limits (HTTP/2 measured without gain);
- Grafana backend overhead (panel time ≈ Prometheus time);
- Infinity job lists: the Details rows are collapsed, and the lists run only when expanded;
- incremental querying: most tiles and all tables have instant targets or `$__range`, which Grafana excludes, and the remaining range-only targets use `${__from}`/`${__to}`, which change the cache key on every refresh. Only #9 can use it.

#### 2.2 Measurement method (dev stack, WP9 owns the tools)

1. **Data.** `vendor/bin/testbench workbench:perf-data --profile=medium --hours=26 --out=build/perf/om` writes OpenMetrics chunks aligned to 2-hour windows.
   - **Profile `medium`:** 4 supervisors, 12 queues, 160 job classes (180 queue/class tuples), 16 duration buckets, and 8 batch kinds with a new batch every 10 minutes. That gives about 5,300 active series and about 12,000 over 26 hours with batch churn.
   - **Profile `large`:** 10 supervisors, 40 queues and 500 classes.

   Every value is a pure function of time. The probe implementation is `probe/synth.py`, and WP9 ports it to PHP. `docker compose --profile perf run --rm promtool tsdb create-blocks-from openmetrics …` converts each chunk into the `perf-prometheus` volume. About 75 s plus 4.5 min on the reference stack.
2. **Continuation.** `vendor/bin/testbench workbench:perf-exporter --port=8391` serves `/metrics` from the same model. The `perf` Prometheus scrapes it at S (10 s), so relative ranges ending at "now" are continuous.
   - The exporter must **not** emit `job` or `instance` labels. Prometheus would rename them to `exported_*` and duplicate every series (observed).
   - Start it right after the backfill, or backfill the gap.
3. **Capture.** `node bin/perf.mjs capture --url "<dashboard>?from=now-1h&to=now&kiosk" --seconds 70 --out build/perf/<name>.json`.
   - Playwright with `locale: 'en-US'` and viewport 1920×1080.
   - It records every `/api/ds/query` and datasource resource call with its `X-Panel-Id` header, request body and start/end times.
   - It groups them into cycles: bursts separated by 2 s or more of silence.
4. **Replay.** `node bin/perf.mjs replay build/perf/<name>.json --prometheus http://localhost:9090` re-runs every captured target against `/api/v1/query(_range)?stats=all`:
   - three times per target, keeping the median wall time;
   - recording `evalTotalTime`, `totalQueryableSamples` and `peakSamples`.

   It substitutes `$__interval`, `$__interval_ms`, `$__range`, `$__range_s` and `$__rate_interval` as Grafana's backend does, using the captured `intervalMs` as the step. The probe's `replay.py` floors the step at 15 s; the port must not, or 10-second dashboards are replayed at the wrong resolution.
5. **Report.** `node bin/perf.mjs report build/perf/*.json --budget docker/perf/budget.json` prints:
   - per-panel and per-target tables;
   - totals: refresh p50/p95 over cycles 2–4, requests per refresh, sequential PromQL ms, samples and interpolated KB.

   It exits 1 on a budget violation.
6. **CI:**
   - The `grafana` job runs the **static** gates on every PR. They need no Prometheus and are computed from the rendered JSON with "All" selections: request count, interpolated PromQL size, no `[1d]` on counter selectors, and the variable refresh policy.
   - The weekly `e2e` job runs steps 1–5 on the medium profile and uploads the report; initially non-blocking, blocking after WP11.

#### 2.3 Performance budget

Reference stack, medium profile, `--live=off`, all filters "All". Wall times are p50 over refresh cycles 2–4 unless stated.

| Metric | Budget | v5b measured |
|---|---|---|
| Refresh cycle, 1-hour range | **≤ 1.0 s p50, ≤ 1.5 s p95** | 0.84–1.30 s |
| Overview row complete, 1-hour range | **≤ 1.0 s p50** | 0.81–0.97 s (v5: 0.48–0.72 s) |
| Refresh cycle, 6-hour range | ≤ 2.0 s p50 | 1.4–2.0 s (v5) |
| First load, 1-hour range | ≤ 3.0 s | 2.1–2.8 s |
| Requests per refresh (visible rows) | ≤ 12 | 12 |
| Variable queries per auto-refresh | ≤ 2 (`batch_kind`, `batch_title`) | 2 |
| Sequential PromQL per refresh, 1 hour (replay) | ≤ 1.5 s | 1.40 s (v5b, review replay); about +0.1 s expected from the O2 batch scope |
| Interpolated PromQL per refresh (All) | ≤ 100 KB; ≤ 25 KB per panel | 57 KB (v5) |
| Poll mode, extra cost per refresh | ≤ 5 Infinity calls, each served from the snapshot cache | 5 |
| Live snapshot build (20 queues, Redis part) | ≤ 50 ms p95 | 8.5 ms p95 on loopback with 1.5 KB payloads; 30 ms p95 with 20 KB payloads (review `snap/bench.php`) |

O6 (combined attempts query) is required in WP8C to give the 1-hour p50 a margin of at least 30 % and to absorb the O2 scope cost. Expected, not measured end to end: about −0.3 s of PromQL.

`planning/TASKS.md` (T01) states a target of "Overview < 0.3 s, whole dashboard < 1.5 s". The whole-dashboard target matches the p95 budget. The Overview target is not reachable by a refresh-driven render: the tiles wait for the variables and for Prometheus. The owner's need for an always-current Overview is met by R4 instead: in stream mode the live tiles update every second, independent of the refresh cycle; in poll mode they show values computed at request time (no scrape lag) at every refresh, but a Mixed tile still waits for its Prometheus sparkline query because Mixed joins its sub-queries.

#### 2.4 Optimisation catalogue (WP8C, each one a commit with a screenshot diff and a perf report)

| Id | Optimisation | Panels | Measured effect | Look |
|---|---|---|---|---|
| **O1** | **Counter baseline lookback** `LOOKBACK = max(10m, 10×S)` (10m for S ≤ 60 s) replaces `[1d]` in `attempts_delta()` and `attempts_per_step()` (start = last value within LOOKBACK before the period, else the first value in the period, as today). Attempts are lost only if Prometheus had a gap longer than LOOKBACK right before the period start, the same as legacy behaviour inside a gap. | #4 A/B/C, #8 A/B, #5 C/E/H/I/J, #6 B/C/D/H, #14 A/B, #17 D/G/H, #16 | 6.8 → 3.6 s; 14.8 → 5.1 s of PromQL | none |
| **O2** | **`job_class` "All" = `.+`** (`allValue`). The legacy "All" list also carried the Batch scope (its options are the classes of the selected batch kinds), so the scope moves into the PromQL. Every job-filtered per-series vector `V` (after its range function, before aggregation to queue level) becomes `V unless on (job_class) BATCH_EXCLUDED`; see the expression after this table. `__batch_scope__` is a label no stored series can carry (Prometheus drops `__`-prefixed labels after relabelling). With Batch=All, `$batch_kind` is `.*`, the matcher `__batch_scope__!~".*"` selects nothing and the set is empty and almost free. With kinds selected, it selects every job series and the set is the classes outside those kinds: the classes the legacy list left out. **Do not** use the `batch_filtered()` pattern `(X and on (job_class) …) or (X and on () BATCH_IS_ALL)` or an always-evaluated exclusion set: Prometheus evaluates both `or` branches (2× the cost of X) or the set at every step (#8 A 52 → 403 ms). Equality with the legacy output is a contract test with Batch=All and with Batch=one kind. | every job-filtered selector | 3.6 → 3.0 s; 2,143 → 150 KB, measured without the scope. Scope cost (review): #8 A 52 → 96 ms at Batch=All, 308 ms at Batch=one kind; with one kind the set excluded 157 classes and kept the 4 (queue, class) rows of that kind | none |
| **O3** | **Variable refresh policy.** On dashboard load (`refresh: 1`): `supervisor`, `queue`, `job_class`, `supervisor_title`, `horizon_url`, `tts_url`, `api_path`, `prometheus_job`, `datasource` and `app_api`. These are instant or `[1d]`/`[7d]` lookbacks that do not depend on the range. On time range change (`refresh: 2`): only `batch_kind` and `batch_title`, which use `$__range`. | all panels | all on load: 3.0 → 2.55 s, 19 → 13 requests; shipped policy (v5b): +0.2–0.3 s against all on load | Supervisor, queue and class lists no longer follow a changed time range until reload (documented) |
| **O4** | **Aggregate-first per-step deltas** for the tile sparklines (#8 A, #4 A). See the expression after this table. Values are identical to legacy on continuous data. The only difference: a series that disappears (a job class pruned after 30 idle days) zeroes one step instead of being ignored. | #8 A, #4 A | #8 775 → 43 ms; #4 1,665 → 79 ms; 2.55 → 1.5 s | none |
| **O5** | **Shared queue source.** A new **hidden top-level panel #24** ("Queues source", table, 12 targets) evaluates the #5 targets once for all supervisors. The trailing `and on (queue) …{supervisor=~"$supervisor_title"}` becomes `* on (queue) group_left (supervisor) group by (queue, supervisor) (last_over_time(<ns>_horizon_supervisor_processes{…}[1d]))`. #5 keeps `repeat: supervisor_title`. Its datasource becomes `{"type":"datasource","uid":"-- Dashboard --"}` with `targets: [{"refId":"A","panelId":24,"withTransforms":false}]`, its first transformation is `filterByValue` on `supervisor == ${supervisor_title}`, and its organise step excludes `supervisor`. #24 sits in the hidden top grid row next to #23, whose `afterRender` hides `panel-23` and `panel-24` and shifts the grid once. **#24 must set `"maxDataPoints": 100`** (any explicit value; its targets are instant): without it, a source that is `display: none` before its first render never runs and every #5 repeat stays empty (verified on 12 and 13); with it, the hidden source runs once per refresh whatever the timing of #23's script. | #5 (+ #24) | 1.5 → 0.73 s; 2.7 → 1.45 s of PromQL; pixel-identical | none |
| **O6** | **One attempts query per table.** #24 gets one target `sum by (queue, supervisor, status) (<attempts_delta aggregated by queue and status, statuses completed\|failed\|timed_out\|retried>)` instead of C, E, H, I and J. In #5, "Grouping to matrix" (rows `queue`, columns `status`) pivots it, then `calculateField` builds Failed = failed + timed_out and Success rate = completed / (completed + failed + timed_out). #6 does the same with (queue, job_class, status). Column names and order are kept by the existing organise step. | #5/#24, #6 | replay: 374 → 50 ms per evaluation | none (screenshot gate) |
| O7 | **The full-range start fallback is kept; no optimisation.** `min_over_time(sel[$__range])` (C-P7) stays. Bounding it to the first LOOKBACK of the period (`min_over_time(sel[LOOKBACK] @ ${__from:date:seconds} offset -LOOKBACK)`, with series lacking a start counted from 0) is cheaper but **rejected**: after a Prometheus outage longer than LOOKBACK covering the period start, it would count a series' whole cumulative value. The cost is contained instead by O5 and O6: the fallback is evaluated about twice per refresh instead of 12 or more times. Long ranges get O9. | #5/#24, #6, #4 B/C, #17 | – | none |
| **O8** | #3 B (colour mappings) becomes an **instant** query at `${__to}`, because only the last value's colour is shown. | #3 | small | none |
| O9 | **Optional recording rules** (opt-in `--recording-rules`; lowest priority in WP8C; the default render never depends on them, which overrides the Q5 rejected alternative as `planning/TASKS.md` requires). `ho:queue_jobs:sum_by_queue_status = sum by (job, connection, queue, status) (max by (job, connection, queue, job_class, status) (ho_queue_jobs_total))` and the equivalent for histograms, rendered to `{path}/prometheus/horizon-observability.rules.yml`. The recorded series have no `job_class`, so they can only serve panels while Job and Batch are "All"; whether the flagged render switches selectors is decided by a WP8C measurement at 6 h and longer ranges. | long ranges | not measured | none |

The O2 exclusion set (the builder renders `<ns>` and the job scope):

```
BATCH_EXCLUDED =
    group by (job_class) ({__name__=~"<ns>_queue_jobs_total|<ns>_queue_jobs_pending|<ns>_queue_jobs_reserved",
                           job=~"$prometheus_job", __batch_scope__!~"$batch_kind"})
  unless on (job_class)
    group by (job_class) (<ns>_queue_batch_job_classes{job=~"$prometheus_job", batch=~"$batch_kind"})
```

The O4 per-step expression:

```
clamp_min(
    clamp_max(sum(last_over_time(S[LOOKBACK] offset -$__interval)), scalar(sum(last_over_time(S[LOOKBACK] @ ${__to:date:seconds}))))
  - clamp_min(sum(last_over_time(S[LOOKBACK])),
              scalar(sum(last_over_time(S[LOOKBACK] @ ${__from:date:seconds}) or min_over_time(S[$__range] @ ${__to:date:seconds})))),
  0) or on () 0 * group(<ns>_horizon_status)
```

In this expression, `S` is `<ns>_queue_jobs_total{…filters…, status=~"…"}` wrapped in `max by (job, connection, queue, job_class, status)` (Q5.6 step 10), and each of its `last_over_time(S[…])` and `min_over_time(S[…])` vectors is followed by `unless on (job_class) BATCH_EXCLUDED` (O2) before `sum`.

#### 2.5 Grafana and Prometheus settings (shipped examples and README)

- **Prometheus datasource** (`docker/grafana/provisioning/datasources/prometheus.yaml` and the README example):
  - `jsonData.httpMethod: POST`: required. Legacy queries of up to 438 KB do not fit in a GET URL, and even optimised panels are 10–25 KB.
  - `jsonData.timeInterval: <S>`;
  - `jsonData.prometheusType: Prometheus`, `jsonData.prometheusVersion: 3.15.0`;
  - `jsonData.incrementalQuerying: true` with `incrementalQueryOverlapWindow: 10m`: harmless, and it helps only #9 (off and poll modes). Every other panel has an instant target or `$__range` (excluded), or uses `${__from}`/`${__to}` (#3 A/B, #8 A, #4 A, #14), whose interpolated expression changes the cache key on every refresh, so those panels are always fully re-queried and never served stale points. After O8, #3 also has an instant target.
- **Per-panel min interval.** Every Prometheus panel gets `"interval": "$scrape_interval"` (R3). Verified interpolated, and it overrides the datasource `timeInterval`. `$__rate_interval` would still follow the datasource `timeInterval`, which is one more reason to set `timeInterval: <S>`; the dashboard uses no `rate()` today.
- **Refresh:**
  - off and stream modes: the default `refresh` is S (10 s);
  - poll mode: `--live-poll-interval` (5 s by default).

  `timepicker.refresh_intervals` contains the chosen value. Provisioning clamps `refresh` to `min_refresh_interval` (5 s by default, verified), so going faster needs `GF_DASHBOARDS_MIN_REFRESH_INTERVAL` **and** a re-provisioned file.
- **Prometheus server:** the defaults (`--query.max-concurrency=20`, lookback delta 5m) are fine for S ≤ 60 s. No flags are required.
- **Not recommended:**
  - HTTP/2 (no gain measured);
  - Grafana query caching (Enterprise only);
  - a refresh below S, which only re-reads the same samples.

### Rationale

- The budget is met by removing work, not by caching. Every optimisation keeps the PromQL readable and the panels unchanged (pixel-identical end state).
- O1 and O4 keep the owner's exactness semantics: per-step values are identical to legacy on continuous data.
- O5 uses only stock Grafana mechanics (the `-- Dashboard --` datasource and variable interpolation in transformations), verified on 12.4.13 and 13.2.4, provided #24 sets `maxDataPoints`.
- O2's absent-label switch keeps the cost of the Batch filter at zero while it is "All", without a second dashboard variant.

### Rejected alternatives

- **Recording rules as the main fix:** they add Prometheus config to every install. They stay optional (O9).
- **Increasing the refresh interval:** it hides the cost instead of removing it.
- **A `queues` label on `horizon_supervisor_info`**, so each repeat could use `queue=~"${supervisor_title:raw}"`: it changes the metric contract and needs regex escaping in label values. O5 achieves the same 1× cost.
- **Increase-based approximations (`increase(…[$__interval])`):** extrapolated and not exact. The owner wants exact counts.
- **Prometheus `anchored` range selectors:** experimental, behind a feature flag.

### Risks

| Risk | Mitigation |
|---|---|
| O5's hidden #24 depends on #23's JS (Business Text) | Business Text is already required (`__requires`). If the plugin is missing, the top strip shows the "plugin not found" panel and #24 as a thin visible table, which degrades the look but keeps the data correct. |
| A hidden #24 might not run when hidden by CSS | Verified by the review: without `maxDataPoints`, a #24 hidden before its first render never runs (empty #5 on 12 and 13); with `maxDataPoints: 100` it runs once per refresh. A contract test asserts `maxDataPoints` on #24; the WP8C e2e asserts non-empty #5 repeats with #23's script active. Fallback: #24 in the collapsed "Details · Jobs" row (2 runs per refresh on 13.2.4, 1 on 12.4.13). |
| A `viewPanel=` deep link to a #5 repeat may not activate #24 | Not verified (the review's probe could not address the repeat). WP8C checks `?viewPanel=<#5 key>`; if it is empty, document it. |
| O2 changes how the batch scope reaches job selectors | Contract test comparing panel results for Batch=All and Batch=kind, legacy against new, on seeded data. The metric-contract test (Q5.7 item 2) whitelists only `__batch_scope__` as a non-catalog label. |
| The absent-label switch relies on Prometheus semantics (an absent label matches as `""`) | Stable PromQL semantics since 1.x, verified on 3.15.0. `promtool check rules` and the Batch=All/kind equality tests run on every CI leg. |
| O3 lists stop following time-range changes | Documented; a page reload refreshes them. The lists are effectively static. |
| The budget is hardware-dependent | Static gates (request count, KB) are deterministic. Wall-time gates run on the pinned reference profile in CI. |

---

## R3. Scrape interval and precision

### Decision

#### 3.1 What is exact and what is sampled

| Family | Nature | Precision at interval S |
|---|---|---|
| `queue_jobs_total`, `queue_job_duration_seconds_*`, `queue_job_wait_seconds_*` | Counters and histograms kept in Redis | **Exact for any S.** Every attempt is counted in storage, and per-period and per-step counts are differences of counters. Only a Prometheus gap longer than LOOKBACK (10m) right before a period start loses counts. |
| `queue_job_duration_{min,max}_seconds` | Windowed extremes | **Exact for S ≤ 60 s** after the change in 3.4: each attempt is visible for more than 135 s, so every attempt reaches at least one scrape while the scrape-time jitter stays within ±37 s, and at least two (one failed scrape tolerated) within ±7.5 s. |
| `queue_pending_jobs`, `queue_delayed_*`, `queue_oldest_pending_job_age_seconds`, `queue_jobs_pending/reserved`, `horizon_supervisor_processes` | Gauges | **Sampled every S.** Spikes shorter than S are invisible to Prometheus. The Live Overview (R4) shows the current values without scrape lag. |
| `horizon_status` | Gauge | Outages shorter than S can be missed by Uptime. The tile uses the worst sample per step. |
| `queue_batch_*` | Gauges from the DB | Sampled. Each batch stays exported for 1 h after closing, so none is missed at S ≤ 60 s. |

#### 3.2 Recommended scrape job (README, `install` output, dev stack)

```yaml
- job_name: myapp-horizon                  # separate job: the package endpoint only
  metrics_path: /horizon/observability/metrics
  scrape_interval: 10s                     # recommended; supported 5s–60s
  scrape_timeout: 8s                       # <= interval; exporter budget = min(8 s, timeout − 0.5 s)
  authorization: { type: Bearer, credentials_file: /etc/prometheus/secrets/horizon-observability-token }
  static_configs: [{ targets: ['myapp.internal:8080'] }]
```

- **Why 10 s:** gauges are twice as fresh as at 15 s. The audit measured a 0.03 s scrape, so the cost stays small.
- **Storage:** about 1.5× the samples of 15 s, about 60 MB per day for the medium profile at about 1.3 bytes per sample.
- **Supported range:**
  - **5 s** works with `scrape_timeout ≤ 5s`, which makes the budget 4.5 s;
  - **60 s** works with every guarantee in 3.1;
  - below 5 s is not supported, because the exporter budget would be under 4.5 s.

#### 3.3 Interval-agnostic windows

The `grafana` command option `--scrape-interval` (default **`10s`**) sets:
- the default of a new hidden textbox variable `scrape_interval` (URL-overridable as `?var-scrape_interval=30s`);
- the render-time constants derived from S.

| Where | Today | New |
|---|---|---|
| `BUSY_WINDOW` (#5 Utilization, #3 zone) | literal `[15s]` | `[$busy_window]`; the hidden variable defaults to `max(15s, 2×S)` (10 s → `20s`, 60 s → `120s`). The ratio `sum_over_time(reserved[W]) / count_over_time(processes[W])` is a per-sample average, so any W ≥ S is correct. 2×S guarantees at least one sample with jitter. |
| `[$__interval]` windows in range queries (#1, #7, `attempts_per_step`) | assume `$__interval ≥ S` via the datasource `timeInterval` | every Prometheus panel gets `"interval": "$scrape_interval"`, so `$__interval ≥ S` (verified). `$__rate_interval` is not used; it would follow the datasource `timeInterval` or a per-target Min step, not the panel min interval (source) |
| Counter start lookback | `[1d]` | `[LOOKBACK]` with `LOOKBACK = max(10m, 10×S)` |
| RPM (#8 B, off mode) | `attempts_delta('completed', '5m') / 5` | window `max(5m, 5×S)`; divisor in minutes |
| Alerts tile and sound | `[90s]`, `[15m]`, `[15m:]` | unchanged: these depend on the alert-rule group interval (1m), not on S. Rendered as `1.5 × RULE_INTERVAL` and the stabilisation constant. |
| `SUPERVISOR_UTIL_AT_END` `[1s:1s] @ to` | instant subquery | unchanged |
| Variable lookbacks `[1d]`, `[7d]` | on load (O3) | unchanged |
| Alert rules | `increase(…[15m])`, `for: 5m`, interval 1m | unchanged; valid for S ≤ 60 s (15 or more samples per window, lookback delta 5m) |
| Dashboard `refresh` | `15s` | S (off and stream modes) or `--live-poll-interval` (poll mode) |

#### 3.4 Duration min/max windows tolerant of 60 s scrapes

- **Today:** the min/max of an attempt at time `a` sits in the 60-second window `⌊a/60⌋`. Exports merge the current and previous window, so the attempt is visible until the end of the next window: only about 60 s in the worst case. A 60-second scrape with one late scrape misses it.
- **New storage:** buckets of 15 s.
  - **Write:** `record_attempt.lua` updates `extremes:<⌊a/15⌋>:min|max` with TTL **300 s**.
  - **Read:** `extremes_read.lua` (new) merges the **current bucket and the 9 previous ones** (KEYS = 10 min keys, then 10 max keys) with `min` of mins and `max` of maxes.
- **Guarantee:** an attempt in bucket `b` is exported by every scrape at `t` with `⌊t/15⌋ ∈ [b, b+9]`, that is `t ∈ [a, 15(b+10))`, more than 135 s. With nominal scrapes every S and a per-scrape deviation within ±j:
  - at least **one** scrape sees every attempt while `135 − 2j > S`: at S = 60 s, j < 37.5 s;
  - at least **two** scrapes see it while `135 − 2j > 2S`: at S = 60 s, j < 7.5 s (or a late-only delay below 15 s), so one failed scrape loses nothing;
  - a Monte Carlo check (`review/sim/extremes.py`, 40,000 trials per cell) gives minimum counts of 2 at S = 60 s for |j| ≤ 7.5 s and 1 up to |j| = 37 s; 2 at S = 45 s; 4 at S = 30 s; 13 at S = 10 s. The legacy 60-second windows reach 0 at S = 60 s as soon as there is any jitter.

  Prometheus scrapes each target at a fixed phase, so real jitter is the scrape latency (milliseconds to the scrape timeout). Clock skew between workers (which pick the bucket) and the web node (which picks the read keys) adds to j; NTP is assumed.
- **Side effect:** exported extremes include attempts up to 150 s old, so `min/max_over_time(…[$__range])` may include attempts up to 150 s before the range start (documented; less than 5 % of a 1-hour range).
- **HELP texts:**
  - "Shortest job attempt during the last 150 seconds, seconds."
  - "Longest job attempt during the last 150 seconds, seconds."
- **Read cost:** 20 `HGETALL` inside one `EVAL`, instead of 4. Each (connection, queue, class) field can appear in up to 10 buckets, so the script merges inside Redis and returns one pair per field, as today's PHP merge of two windows does.

### Rationale

- Counters already carry the exact information. Only window choices and the extremes were tied to 15 s or 60 s.
- One variable (`scrape_interval`) and render constants keep the PromQL readable and the dashboard reusable across environments with different intervals.

### Rejected alternatives

- **Exporting extremes as histograms only:** this loses the exact min and max the owner relies on.
- **A sliding window in one hash with per-field timestamps:** more Lua and the same guarantee.
- **Requiring S ≤ 15 s:** contradicts the owner's requirement.
- **`$__rate_interval` as `BUSY_WINDOW`:** about 4×S, which is too smooth for the Utilization bar.

### Risks

| Risk | Mitigation |
|---|---|
| Users keep `timeInterval: 15s` on a shared datasource while scraping at 60 s | The panel-level `interval` overrides it (verified); `check` prints the expected scrape config. |
| Scrape deviation of ±7.5 s or more at S = 60 s (or app clock skew of that size) | Every attempt is still seen once up to ±37 s; only the tolerance for a failed scrape is lost. Documented; Prometheus scrapes at a fixed phase, so the deviation is the scrape latency. |

---

## R4. Live Overview

### Decision

#### 4.1 Modes, render options and per-tile behaviour

```
php artisan horizon-observability:grafana … {--live=poll}                off | poll | stream
                                             {--live-poll-interval=5s}   poll: dashboard refresh (>= Grafana min_refresh_interval)
                                             {--live-stream-id=}         stream: Grafana Live stream id; default config('horizon-observability.live.stream.id')
```

- **Default `poll`.** The owner confirmed on 2026-10-17 that the committed `resources/grafana` render and `composer test:grafana` use `--live=poll` (`planning/README.md`, decision 9). `--defaults` therefore renders poll mode; `off` stays available.
- When live ≠ off, row #10 is titled **"Live Overview"**. Otherwise it stays "Overview".
- Mode guidance (README):
  - **stream** is recommended for wallboards and many viewers: 1-second tiles, with the dashboard refresh staying at S;
  - **poll** suits setups without a Grafana token or WebSocket proxy, and few viewers. Every visible panel refreshes at the poll interval: about 1.45 s of PromQL per viewer per refresh at 1 hour (v5), so plan for 3 or fewer concurrent viewers at 5 s.

| Tile | off | poll | stream |
|---|---|---|---|
| #1 Horizon (Uptime) | Prometheus | Prometheus | Prometheus |
| #3 Supervisor Processes | Prometheus | **live** via Infinity `tiles[]` + `TILE_ROWS` (`filterByValue(tile=supervisor)`, `groupBy`, `rowsToFields`: value, caption "name · max N", colour). **No sparkline.** | **live** via the Grafana Live `tiles` channel, same transformations, 1 s. **No sparkline.** |
| #2 Pending | Prometheus | **Mixed:** Prometheus sparkline + live value (merge) + live caption and colour (`configFromData`) | **live** via `TILE_ROWS` (value, caption "Oldest: …", colour), 1 s. **No sparkline.** |
| #8 Completed | Prometheus | Prometheus number + **live caption** "RPM: n" (Infinity) | same as poll; the caption updates at the dashboard refresh |
| #9 Delayed | Prometheus | **Mixed:** Prometheus sparkline + live value | **live** windowed series: 15 minutes at 5 s, 1-second pushes, seeded sparkline (`timeFrom: 15m`, `hideTimeOverride`) |
| #4 Failed, #21 Alerts | Prometheus | Prometheus | Prometheus |

**Look deviations in live modes** (the owner accepts them by choosing a live mode; `--live=off` keeps today's look exactly):
- #3 loses its per-supervisor sparkline in poll and stream modes. The supervisor count is dynamic, and no stock transformation can merge a live row into each Prometheus series.
- #2 loses its sparkline in stream mode (Mixed cannot carry a stream, R4.8).
- The #8 caption "RPM: n" counts the last complete 60 s (ring buffer) instead of the 5-minute average.
- #9 in stream mode shows the last 15 minutes whatever the dashboard range (`timeFrom`).
- Poll mode refreshes the whole dashboard every 5 s instead of every S.

Filters:
- **Poll mode** passes the dashboard filters to the endpoint (4.3).
  - Supervisor and Queue scope the snapshot.
  - An active Job or Batch filter sets `pending.value` and `delayed.value` to `null`. `merge` then falls back to the Prometheus value, which keeps today's class-filtered semantics (verified on 12 and 13), and the caption is "Oldest: —" with level 0, as today's B and C targets give under a filter.
  - The Supervisor tile counts only workers busy with the selected classes (reserved jobs by class).
- **Stream mode** shows the whole application. Filters apply to every other panel (documented).

#### 4.2 Live snapshot service and cache

`Live\LiveSnapshotBuilder::build(LiveScope $scope, CarbonImmutable $now): LiveSnapshot` reads, all on reader clients (Q6.6):
1. **Horizon:** `Horizon\Supervisors` (the repositories, as in the exporter):
   - status: 1, 0 or −1;
   - per supervisor: processes, min and max, balance, connection and queues.
2. **Queues in scope:** one `queue_state.lua` call per queue in **live mode** (D′):
   - `LLEN`, reserved `ZCARD`, delayed `ZCARD`, due delayed `ZCOUNT` and the earliest due score;
   - readiness of the first first-attempt job within the first **20** head items, read with at most **1 MB** of payload per queue (`max_bytes` in live mode);
   - reserved jobs per class, only when a Job or Batch filter is active (at most 1,000 members).
3. **Wait thresholds** from `horizon.waits` (via Topology).
4. **Throughput** from the ring buffer (4.5): the 12 most recent **complete** 5-second buckets, a 60-second window lagging at most 5 s.
5. **Derivations.** The rules live in `Live\Captions` (shared constants used by the Grafana renderer):
   - pending = Σ (`LLEN` + due delayed), the same as `queue_pending_jobs`;
   - delayed = Σ (delayed − due);
   - oldest age per queue = `now − min(first fresh readiness, earliest due score)`;
   - wait ratio = age / threshold; pending level = `(ratio > 1) + (ratio > 2)` of the worst queue;
   - busy = Σ reserved over the supervisor's queues; utilisation = `min(1, busy / max_processes)`;
   - zone = `(round(100u) ≥ 70) + (round(100u) ≥ 90)`; while Horizon is not running, processes are shown as 0 and the zone is 2;
   - colours are `green`, `yellow` and `red` by level, and `text` for uncoloured tiles;
   - caption formats are identical to the dashboard (4.4).
6. **Cost per build:** Q + 5 round trips: Horizon's repositories read masters and supervisors in 4 round trips whatever their number (`ZREVRANGEBYSCORE` plus one pipelined `HMGET` batch each), then Q `EVAL`s (Q = queues in scope, O(20) items each) and 1 ring `EVAL` (12 `HGETALL`). A Batch filter adds 1 `ZRANGE` of the batch links. **Measured** (review `snap/bench.php`, phpredis on loopback, 4 supervisors, 500 jobs per queue): 20 queues with 1.5 KB payloads 5.6 ms p50 / 8.5 ms p95 (29 round trips including the cache); 20 KB payloads 20 ms p50 / 30 ms p95; 40 queues 9.0 ms p50. One queue `EVAL` blocks Redis for 0.1–0.8 ms. On a LAN, add about 0.3–0.5 ms per round trip; Laravel's request bootstrap comes on top.

`Live\LiveSnapshotCache::get(LiveScope)` is a stale-while-revalidate cache on the storage connection, safe against stampedes. **Freshness is decided by Redis key expiry, never by comparing `generated_at` with the local clock**: app servers' clocks differ, and a replica whose clock runs ahead would see every snapshot as stale and rebuild on every request.

```
fresh = live:snapshot:<sha1(scope)>         PX ttl_ms                  (ttl_ms = live.cache_ttl × 1000, default 1000)
stale = live:snapshot-stale:<sha1(scope)>   PX max(2000, 10 × ttl_ms)
lock  = live:snapshot-lock:<sha1(scope)>    PX 2000

[f, s] = MGET fresh stale                                  # 1 round trip on a hit (both keys share the hash tag)
if f:                                         return f
if SET lock <token> NX PX 2000:                            # one builder per scope across all PHP workers and replicas
    try   s' = build(); SET fresh json(s') PX ttl_ms; SET stale json(s') PX max(2000, 10 × ttl_ms)
    finally lock.lua release <token>                       # compare-and-delete
    return s'
if s:                                         return s     # stale while another request rebuilds; age_ms shows it
poll GET fresh every 50 ms for up to 500 ms; return it if found
return build() without caching                             # holder died or is slow; counted in a debug counter
```

At most one build per `ttl_ms` per scope succeeds across all replicas, measured on the Redis clock. A build that fails (Redis or Horizon error) stores nothing, so the next request after the lock is released tries again. `age_ms` = local now − `generated_at` is informational only. `cache_ttl = 0` disables the cache (tests). The stream leader uses the same cache, so a running stream also warms poll requests for the full scope.

#### 4.3 HTTP endpoint

| Method | Path | Name | Middleware | Response |
|---|---|---|---|---|
| GET, HEAD | `{base}/live` (default `/horizon/observability/live`) | `horizon-observability.live` | `ForceJson`, `Authorize:api`, then the configured middleware | the 4.4 JSON, `Cache-Control: no-store` |

- **Query parameters:** the same `var-supervisor`, `var-batch_kind`, `var-queue` and `var-job_class` as the job lists (`JobListQuery::VARIABLES`), in `${var:queryparam}` form, parsed by the same code (`JobListQuery::filtersFromRequest()`, C′). `$__all` or absence means no filter. Unknown parameters are ignored, and invalid input gives 422.
  - Supervisor and Queue resolve to a queue set (the supervisors' split queues ∩ the queue list).
  - An empty resolved scope returns zeros with no queue Redis calls (C13 semantics).
- **Status:**
  - **200** whenever authorised, including partial failures (listed in `errors[]`; the affected values are `null`);
  - 401 or 403 from `Authorize`;
  - the route is absent (404) when `live.enabled = false`.
- **Registration:** in `routes/horizon-observability.php`, guarded by `config('horizon-observability.live.enabled', true)`. The maintenance exception gains `"$base/live"`.

#### 4.4 Snapshot JSON schema v1 (public, SemVer-covered)

```json
{
  "v": 1,
  "generated_at": 1792201716123,
  "age_ms": 140,
  "ttl_ms": 1000,
  "scope": { "supervisors": null, "queues": ["default", "emails"], "job_filter": false },
  "horizon": { "status": 1, "level": 0, "color": "green", "caption": "Running" },
  "pending":  { "value": 7, "oldest_seconds": 17, "oldest_queue": "default", "wait_ratio": 0.28,
                "level": 0, "color": "green", "caption": "Oldest: 17 s · default" },
  "delayed":  { "value": 0, "due": 0 },
  "reserved": { "value": 3 },
  "throughput": { "window_seconds": 60, "bucket_seconds": 5, "completed": 42, "failed": 1, "retried": 0,
                  "completed_per_minute": 42, "failed_per_minute": 1.0, "caption": "RPM: 42" },
  "supervisors": [
    { "name": "supervisor-default", "display": "default", "connection": "redis", "balance": "auto",
      "processes": 3, "min_processes": 1, "max_processes": 3, "busy": 2, "utilization": 0.667,
      "level": 0, "color": "green", "caption": "default · max 3", "queues": ["default"] }
  ],
  "queues": [
    { "connection": "redis", "queue": "default", "pending": 7, "reserved": 2, "delayed": 0, "delayed_due": 0,
      "oldest_seconds": 17, "wait_threshold_seconds": 60, "wait_ratio": 0.28, "level": 0, "color": "green",
      "completed_per_minute": 40, "failed_per_minute": 1.0, "supervisors": ["supervisor-default"] }
  ],
  "tiles": [
    { "tile": "pending",    "key": "",                   "caption": "Oldest: 17 s · default", "value": 7,  "color": "green", "level": 0 },
    { "tile": "delayed",    "key": "",                   "caption": "",                       "value": 0,  "color": "text",  "level": 0 },
    { "tile": "supervisor", "key": "supervisor-default", "caption": "default · max 3",        "value": 3,  "color": "green", "level": 0 },
    { "tile": "throughput", "key": "",                   "caption": "RPM: 42",                "value": 42, "color": "text",  "level": 0 }
  ],
  "errors": []
}
```

| Field | Unit / values | Rule |
|---|---|---|
| `generated_at` | Unix ms | When the build started. In poll mode it is the Infinity `Time` column. |
| `age_ms`, `ttl_ms` | ms | Cache age at response time; the cache TTL. |
| `horizon.status` | 1, 0, −1 | As `horizon_status`. Caption `Running`, `Paused` or `Stopped`; level 0 or 2. |
| `pending.value` | jobs, or `null` | Σ ready jobs in scope (ready = `LLEN` + due delayed). `null` when a Job or Batch filter is active, or on error. |
| `pending.oldest_seconds` / `oldest_queue` | s, int / string or `null` | The worst queue by wait ratio; 0 and `null` when nothing waits. |
| `pending.caption` | string | `Oldest: —` when age = 0, a filter is active or on error. `Oldest: N s · Q` when age < 120 s. `Oldest: round(N/60) min · Q` otherwise. `Live unavailable` on error. |
| `pending.level` / `color` | 0, 1, 2 / `green`, `yellow`, `red` | `(ratio > 1) + (ratio > 2)`; 0 and `green` when a filter is active. |
| `delayed.value` / `due` | jobs, or `null` | Σ (delayed − due) / Σ due; `null` when a filter is active. |
| `reserved.value` | jobs | Σ reserved, which is busy workers. |
| `throughput.*` | counts in `window_seconds` (60); per-minute rates | From the ring: completed = `completed`; failed = `failed` + `timed_out`; retried = `retried`. `released` is not counted. Caption `RPM: n`, or `RPM: —` without ring data. |
| `supervisors[]` | – | Sorted by `display`. `processes` is 0 while Horizon is not running. `busy` counts reserved jobs of the selected classes when a Job filter is active. Caption `<name without "supervisor-"> · max <max>`. |
| `queues[]` | – | Per queue in scope. `wait_threshold_seconds` is `null` when the queue has no `horizon.waits` entry, which gives level 0. |
| `tiles[]` | – | **The rows pushed in stream mode** (4.7), so poll and stream panels share `TILE_ROWS`. `value` is a float or `null`; the line protocol encodes `null` as `-1`. `key` is required: `TILE_ROWS` groups by (`tile`, `key`). |
| `errors[]` | `{source, message}` | For example `{"source":"queue:default","message":"RedisScriptFailed: …"}`. Messages are short and never contain payloads. |

#### 4.5 Attempt ring buffer

- **Key:** `live:ring:<⌊t/5⌋>` (hash), field `conn␟queue␟status` → count, TTL **180 s**, so about 36 buckets (3 minutes) stay readable.
- **Write:** in `record_attempt.lua` step 6, when `ARGV ring_ttl > 0`: `HINCRBY ring <field> 1` and `EXPIRE ring ring_ttl`. That is 2 extra commands in the existing `EVAL`, with no extra round trip. PHP passes `ring_ttl = 180` when `live.enabled`, otherwise 0. The ring key is always passed in `KEYS` (D′), and the key is hash-tagged like every package key.
- **Read:** `ring_read.lua` takes `KEYS` = the 12 complete buckets before the current one and returns summed `field, count` pairs. The window `[5(c−12), 5c)` with `c = ⌊now/5⌋` is exactly 60 s, and the value lags by at most 5 s. Workers pick the bucket from their own clock and the reader from its own, so clock skew shifts attempts between neighbouring buckets; no attempt is counted twice, and the per-minute value stays within one bucket of the truth with NTP.
- **Reset guard:** the ring is not reset by `reset_guard.lua`. It holds only deltas and expires by itself.

#### 4.6 Stream command

```
php artisan horizon-observability:stream
    {--once        : compute and push one snapshot, then exit}
    {--max-time=3600 : exit after N seconds; the process manager restarts it}
    {--memory=128  : exit when memory exceeds N MB}
```

- **Preconditions:** `live.enabled`, `live.stream.grafana_url` and `live.stream.token` are set. Otherwise the command exits 1 with the missing env var named. Run one or more replicas per application environment under supervisord, systemd or a Kubernetes Deployment. Horizon cannot host it.
- **Loop** (`Live\Stream\LiveStreamer`, interval `live.stream.interval`, 1.0 s):
  1. Leader election: `SET live:leader <host:pid:rand> NX PX 5000`. Followers retry every 2 s, so failover takes at most about 7 s.
  2. **Lease checks.** The leader renews with `lock.lua renew` (compare-and-`PEXPIRE 5000`) at the start of every tick **and before every push call**. A renew that returns 0 means the lease was lost (expired during a long tick, or Redis failed over): the process stops at once, pushes nothing more in that tick and becomes a follower. A tick can outlast the lease (a snapshot build slowed by reader timeouts, plus up to 3 s per URL push: connect 1 s + timeout 2 s); renewing before each push makes a leader that lost its lease notice it before it pushes, with one or several URLs.
  3. The leader gets the snapshot for the full scope from `LiveSnapshotCache::get(LiveScope::full())`.
  4. The leader maintains the delayed window: every 5 s it runs `LPUSH live:window:delayed "<unix_ms>:<value>"` and `LTRIM 0 178`, with a 1-hour TTL. A new leader therefore continues the window.
  5. It encodes the payload with `LineProtocol::encode()` (4.7) and pushes it with `Http::timeout(2)->connectTimeout(1)->withToken($token)->withBody($lines, 'text/plain')->post("$url/api/live/push/$id")` to each configured URL.
  6. Success: `HSET meta live_push_at <unix float>`. Failure: `HINCRBY meta live_push_failures 1`, plus a throttled log through `FailureReporter` with an HTTP status hint. Backoff is 1, 2, 4, 8 and 15 s, or 60 s for 401 and 403. During a backoff the leader keeps renewing every second but does not push. After **5 consecutive failed ticks** it releases the lease and waits 10 s before competing again, so a replica with a working route to Grafana can take over (if Grafana is down for everyone, leadership merely rotates).
  7. It sleeps until the next tick (no overlap) and checks `--max-time` and `--memory`.
- **Signals:** with pcntl, SIGTERM and SIGINT finish the tick, release the lock (`lock.lua release`, compare-and-`DEL`) and exit 0. Without pcntl the lock simply expires after 5 s.

#### 4.7 Influx line protocol (public, SemVer-covered)

Two measurements per push, **tagless**, and **row k of the `tiles` measurement stamped `T + k ms`** (k = 0, 1, …, in a fixed order: pending, delayed, supervisors by `display`, throughput). Grafana 13.2.4 keeps tagless rows that share a timestamp, but Grafana 12.4.13 keeps only the last of them (labels push mode aligns rows by time); 1 ms apart, every row survives on both (verified):

```
tiles tile="pending",key="",caption="Oldest: 17 s · default",value=7.0,color="green",level=0.0 1792201716123000000
tiles tile="delayed",key="",caption="",value=0.0,color="text",level=0.0 1792201716124000000
tiles tile="supervisor",key="supervisor-default",caption="default · max 3",value=3.0,color="green",level=0.0 1792201716125000000
tiles tile="throughput",key="",caption="RPM: 42",value=42.0,color="text",level=0.0 1792201716126000000
delayed value=4.0 1792200820000000000
… 178 more rows, 5 s apart …
delayed value=0.0 1792201716123000000
```

| Measurement | Channel | Fields | Rows per push |
|---|---|---|---|
| `tiles` | `stream/<id>/tiles` | `tile` (string: `pending`, `delayed`, `supervisor`, `throughput`), `key` (string), `caption` (string), `value` (float; **`-1` encodes `null`**), `color` (string), `level` (float) | 3 + supervisors, 1 ms apart |
| `delayed` | `stream/<id>/delayed` | `value` (float) | 180: 179 window points plus the current one |

- **Encoding:**
  - numbers are floats (no `i` suffix), so the field types never change between pushes;
  - `value` is never omitted. If no row of a push carried `value`, the frame would lose its `value` column and `rowsToFields` would fall back to showing `level` (verified). `null` is pushed as the sentinel `-1` (counts are never negative), and every stream tile maps `-1` to "—";
  - string field values escape `\` and `"`;
  - captions never contain newlines;
  - timestamps are Unix nanoseconds from the app clock; the `T + k ms` spacing needs fewer than 500 tile rows (the panel `buffer` is 500 ms).
- **Unavailable frame** (`LineProtocol::unavailable()`): the same rows (or only the pending row when no supervisor is known) with `caption="Live unavailable"`, `value=-1.0`, `color="text"`, `level=0.0`. The tiles then read "Live unavailable —" (verified on 12 and 13).
- The `throughput` row is part of the contract but no panel reads it in 0.1 (#8 takes its caption from the poll endpoint).
- **Size:** about 0.5 KB plus 0.1 KB per supervisor for `tiles`, and about 6.5 KB for `delayed` (7.4 KB per push measured with 2 supervisors).

#### 4.8 Grafana panel JSON

Only the properties that differ from the `--live=off` render of the same panel are shown. All other properties (gridPos, fieldConfig defaults, `textMode`, `text.titleSize`, `colorMode`) are unchanged. `FILTERS` = `${supervisor:queryparam}&${batch_kind:queryparam}&${queue:queryparam}&${job_class:queryparam}`.

**Poll #2 Pending** (verified shape: probe P201/P206 on 13.2.4; review R801/R805/R806 on 12.4.13 and 13.2.4, including the forced-red colour and the `null` fallback):

```json
{
  "datasource": { "type": "datasource", "uid": "-- Mixed --" },
  "interval": "$scrape_interval",
  "targets": [
    { "refId": "A", "datasource": { "type": "prometheus", "uid": "${datasource}" }, "editorMode": "code",
      "expr": "<off-mode #2 expression A, unchanged>", "legendFormat": "__auto", "range": true, "instant": false },
    { "refId": "L", "datasource": { "type": "yesoreyeram-infinity-datasource", "uid": "${app_api}" },
      "type": "json", "source": "url", "parser": "backend", "format": "table",
      "url": "${api_path}/live?FILTERS", "url_options": { "method": "GET", "data": "" }, "root_selector": "",
      "columns": [ { "selector": "generated_at", "text": "Time", "type": "timestamp_epoch" },
                   { "selector": "pending.value", "text": "Value", "type": "number" } ] },
    { "refId": "C", "datasource": { "type": "yesoreyeram-infinity-datasource", "uid": "${app_api}" },
      "type": "json", "source": "url", "parser": "backend", "format": "table",
      "url": "${api_path}/live?FILTERS", "url_options": { "method": "GET", "data": "" }, "root_selector": "",
      "columns": [ { "selector": "pending.caption", "text": "caption", "type": "string" },
                   { "selector": "pending.color", "text": "color", "type": "string" } ] }
  ],
  "transformations": [
    { "id": "merge", "filter": { "id": "byRefId", "options": "A|L" }, "options": {} },
    { "id": "configFromData", "options": { "configRefId": "C", "mappings": [
        { "fieldName": "caption", "handlerKey": "displayName" },
        { "fieldName": "color", "handlerKey": "color" } ] } }
  ]
}
```

Notes:
- Expression A must return one unlabeled series. Its field is then named `Value`, as Infinity's `Value` column is, and `merge` appends the live row: `lastNotNull` = live value, sparkline = Prometheus history plus the live tail.
- The off-mode B and C targets (caption, level) and their transformations are dropped.

**Poll #9 Delayed:** the same A and L, with `delayed.value` instead of `pending.value`. No C. Transformations: only `merge` with `A|L`.

**Poll and stream #8 Completed:** A is unchanged (O4). B (Prometheus RPM) and its three transformations are replaced by:

```json
{ "datasource": { "type": "datasource", "uid": "-- Mixed --" },
  "targets": [ "<A unchanged>",
    { "refId": "C", "datasource": { "type": "yesoreyeram-infinity-datasource", "uid": "${app_api}" },
      "type": "json", "source": "url", "parser": "backend", "format": "table",
      "url": "${api_path}/live?FILTERS", "url_options": { "method": "GET", "data": "" }, "root_selector": "",
      "columns": [ { "selector": "throughput.caption", "text": "caption", "type": "string" } ] } ],
  "transformations": [ { "id": "configFromData", "options": { "configRefId": "C", "mappings": [
      { "fieldName": "caption", "handlerKey": "displayName" } ] } } ] }
```

**Poll #3 Supervisor Processes** (verified: P204 on 13.2.4; review R802 with `key` and `groupBy` on 12.4.13 and 13.2.4):

```json
{
  "datasource": { "type": "yesoreyeram-infinity-datasource", "uid": "${app_api}" },
  "targets": [ { "refId": "S", "datasource": { "type": "yesoreyeram-infinity-datasource", "uid": "${app_api}" },
    "type": "json", "source": "url", "parser": "backend", "format": "table",
    "url": "${api_path}/live?FILTERS", "url_options": { "method": "GET", "data": "" }, "root_selector": "tiles",
    "columns": [ { "selector": "tile", "text": "tile", "type": "string" },
                 { "selector": "key", "text": "key", "type": "string" },
                 { "selector": "caption", "text": "caption", "type": "string" },
                 { "selector": "value", "text": "value", "type": "number" },
                 { "selector": "color", "text": "color", "type": "string" } ] } ],
  "transformations": "TILE_ROWS(\"supervisor\")",
  "fieldConfig": { "defaults": { "mappings": "SENTINEL" } },
  "options": { "graphMode": "none" }
}
```

The `key` column is required: without it `groupBy` would collapse all supervisors into one tile.

`TILE_ROWS(t)` is the transformation list shared by poll and stream tiles (verified by the review on 12.4.13 and 13.2.4: `gf/rv-live2` Q703/Q704 and `poll/rv-poll` R802):

```json
[ { "id": "filterByValue", "options": { "type": "include", "match": "any", "filters": [
      { "fieldName": "tile", "config": { "id": "equal", "options": { "value": "<t>" } } } ] } },
  { "id": "groupBy", "options": { "fields": {
      "tile":    { "operation": "groupby" },
      "key":     { "operation": "groupby" },
      "caption": { "operation": "aggregate", "aggregations": ["last"] },
      "value":   { "operation": "aggregate", "aggregations": ["last"] },
      "color":   { "operation": "aggregate", "aggregations": ["last"] } } } },
  { "id": "rowsToFields", "options": { "mappings": [
      { "fieldName": "caption (last)", "handlerKey": "field.name" },
      { "fieldName": "value (last)",   "handlerKey": "field.value" },
      { "fieldName": "color (last)",   "handlerKey": "color" },
      { "fieldName": "tile",           "handlerKey": "__ignore" },
      { "fieldName": "key",            "handlerKey": "__ignore" } ] } } ]
```

- `groupBy` keeps one row per (`tile`, `key`), the last one received. It removes the duplicate fields that appear for about a second after every dashboard auto-refresh while a live panel re-subscribes (8–18 of 140 samples without it, 0 with it), and it drops `time`, `labels` and `level`, which `rowsToFields` would otherwise turn into labels of the fields.
- `SENTINEL` is the value mapping `[{"type": "value", "options": {"-1": {"text": "—", "color": "text", "index": 0}}}]`, merged into the panel's existing `mappings`. Poll tiles carry it too, harmlessly (`null` already shows `noValue`).

**Stream #3 Supervisor Processes and #2 Pending** (verified by the review on 12.4.13 and 13.2.4, `gf/rv-live2`):

```json
{
  "datasource": { "type": "datasource", "uid": "grafana" },
  "targets": [ { "refId": "A", "datasource": { "type": "datasource", "uid": "grafana" },
                 "queryType": "measurements", "channel": "stream/<id>/tiles", "buffer": 500 } ],
  "transformations": "TILE_ROWS(\"supervisor\") for #3, TILE_ROWS(\"pending\") for #2",
  "fieldConfig": { "defaults": { "mappings": "SENTINEL" } },
  "options": { "graphMode": "none" }
}
```

`"buffer": 500` sets `maxDelta` to 500 ms (and `maxLength` to 2 × `maxDataPoints`): with pushes 1 s apart and the rows of one push stamped 1 ms apart, the buffer holds exactly the latest push. `"buffer": 1` (the earlier draft) breaks on Grafana 12, where same-timestamp rows collapse, and cannot hold rows stamped 1 ms apart.

**Stream #9 Delayed** (verified: P404 on 13.2.4 with 60 rows; review `gf/rv-live` P505 with 180 rows on 12.4.13 and 13.2.4):

```json
{
  "datasource": { "type": "datasource", "uid": "grafana" },
  "targets": [ { "refId": "A", "datasource": { "type": "datasource", "uid": "grafana" },
                 "queryType": "measurements", "channel": "stream/<id>/delayed" } ],
  "maxDataPoints": 180,
  "timeFrom": "15m",
  "hideTimeOverride": true,
  "options": { "graphMode": "area", "textMode": "value" }
}
```

Other render changes:
- **Row #10** is titled "Live Overview" in poll and stream modes.
- **`__requires`** keeps Infinity in every mode and adds nothing for stream mode: Grafana Live and the `-- Grafana --` datasource are core. Stream mode also needs the poll endpoint for the #8 caption.
- **`docker/grafana/live-probe.json`** (owned by T12/WP-L2) is a small dashboard built from exactly these panel shapes. It is the e2e fixture for both versions, independent of the WP8D renderer.

#### 4.9 Grafana and proxy requirements (README, `install`, `check`)

**Stream mode:**
- **Service account:** create one with role **Editor** and a token:
  - `POST /api/serviceaccounts {"name":"horizon-observability-live","role":"Editor"}`;
  - `POST /api/serviceaccounts/<id>/tokens {"name":"horizon-observability-live"}`.

  Store the token in `HORIZON_OBSERVABILITY_LIVE_GRAFANA_TOKEN`.
  - Editor is the minimum on Grafana 13 (`live:push`, verified on 13.2.4). Grafana 12.4.13 accepts any service account, even role None (verified), so a None-role token is the least privilege there, but it starts failing with 403 after an upgrade to 13. The README recommends Editor and documents the 12.x option; `check --live-push-test` reports a 403 with the hint "role Editor needed on Grafana 13".
  - Editor can edit dashboards in its org, so keep the token server-side only. It never reaches browsers.
  - On Grafana Enterprise or Cloud, a custom role with only `live:push` is preferable.
- **Grafana Live must be enabled** (the default, `[live] max_connections = 100`, one WebSocket per open browser tab).
- **Reverse proxies** must pass WebSocket upgrades on `/api/live/ws` (`Upgrade`, `Connection` headers; not probed here, as the probes ran without a proxy). Set `[live] allowed_origins` when Grafana is served on another origin than `root_url`.
- **Several Grafana instances need** `[live] ha_engine = redis` (with `ha_engine_address`). Otherwise a push reaches only the viewers of the instance that received it. Without the HA engine, list every instance in `live.stream.grafana_url` (comma-separated); the leader pushes to each.

**Poll mode:**
- The Infinity datasource of the job lists is reused.
- Faster than 5 s needs `[dashboards] min_refresh_interval` lowered **and** the dashboard re-provisioned: provisioning clamps `refresh` to the server minimum (verified).

#### 4.10 Failure handling

| Failure | Poll | Stream | Effect on Prometheus tiles |
|---|---|---|---|
| Grafana down or unreachable | not applicable | push fails (connect error) → `live_push_failures` + throttled log, backoff up to 15 s; after 5 failed ticks the leader releases the lock so that another replica can try (4.6) | none |
| Grafana restarted | not applicable | the managed-stream cache is lost; the next push (≤ 1 s) restores it; browsers reconnect | none |
| Several Grafana instances without the HA engine | works (per-request Infinity) | viewers on other instances get no data → documented requirement; multi-URL push as fallback | none |
| Storage Redis down | the cache and the build fail → 200 with `errors[]`, `null` values, caption `Live unavailable` and colour `text` (the stale copy lives in the same Redis, so nothing stale can be served). Pending and Delayed fall back to the Prometheus value through `merge`. | the lock cannot be taken. Each stream process then pushes an idempotent "unavailable" `tiles` frame (`caption=Live unavailable`, `value=-1`, `color=text`) at most once every 5 s, so tiles visibly stop being live ("Live unavailable —") | `collector_success = 0` |
| Horizon's queue Redis partly failing | that queue goes to `errors[]` and is excluded from the sums | same, via the snapshot | partial `collector_success = 0` (Q1.3) |
| Several app replicas | one Redis cache: at most 1 build per `cache_ttl` per scope in total, timed by Redis key expiry (app clock skew cannot defeat it) | Redis leader lock: at most one pusher; a leader that loses its lease stops before its next push; failover ≤ 7 s | none |
| Token missing | not applicable | command exits 1 naming `HORIZON_OBSERVABILITY_LIVE_GRAFANA_TOKEN`; `check` reports it when stream mode is configured | none |
| Token invalid or role too low | not applicable | 401 (invalid or missing token, anonymous auth off) or 403 (`live:push` on Grafana 13 with a role below Editor, or an invalid token with anonymous auth on) → logged once per 5 min with the hint "service account token invalid, or role Editor needed on Grafana 13", backoff 60 s | none |
| Malformed payload (bug) | not applicable | Grafana answers 500 → failure counter; the log includes the payload size; covered by golden tests | none |
| Invalid stream id | not applicable | Grafana accepts it (verified), so `Config` validates `^[A-Za-z0-9_-]{1,64}$` and falls back to the default with a problem | none |
| Stream process killed (SIGKILL) | not applicable | the lock expires in 5 s; another replica takes over. With no replica, tiles freeze at the last frame and **`<uid>-live` fires after about 30 s + S + 1 m** plus up to one rule-evaluation interval (4.11) | none |
| Snapshot slower than the interval | not applicable | pushes are sequential (no overlap); `age_ms` shows it | none |
| `min_refresh_interval` above the poll interval | provisioning clamps; the effective poll is the server minimum | not applicable | none |
| Clock skew between app and Grafana | `Time` of the live row is skewed; the sparkline tail may be shifted | timestamps from the app; the windowed sparkline is shifted by the skew | none (NTP documented) |

#### 4.11 Live self-metrics and the stall rule

- **Exporter series** (collector `attempts`, read from `meta`):
  - `<ns>_exporter_live_push_timestamp_seconds`: gauge, the Unix time of the last successful push by any stream leader. Absent until the first push.
  - `<ns>_exporter_live_push_failures_total`: counter.
- **Rule `<uid>-live`** "Live overview stalled", rendered only with `--live=stream` and included in the Alerts tile selector and the sound:
  - expression `max by (job) (timestamp(<ns>_exporter_live_push_timestamp_seconds{job=~"J"}) - <ns>_exporter_live_push_timestamp_seconds{job=~"J"})`: the age of the last push **at scrape time**. `timestamp()` must wrap the raw selector (after an aggregation it returns the evaluation time); the outer `max by (job)` is the replica dedupe;
  - condition `> 30`, `for: 1m`;
  - noData OK, so it stays quiet before the stream ever ran.

  The earlier draft `time() - max by (job) (…)` also counted the time since the last scrape: at S = 60 s a healthy stream reads up to about 61 s, which is above 30 for half of every scrape interval, so the rule would fire falsely at S ≥ 30 s. The `timestamp()` form is scrape-interval agnostic (R3); verified that `timestamp()` returns the sample time on Prometheus 3.15.0.
- **Alert contract** (Q5.7 item 4): 4 rules by default, 5 with `--live=stream`, plus 2 with `--health-rules`.

### Rationale

- **Poll keeps the full look** for single-series tiles at the cost of refreshing the dashboard faster. **Stream gives 1-second tiles** at no Prometheus cost but, because of Grafana's Mixed and refId mechanics, without Prometheus sparklines on the live tiles. These were verified, not assumed, and the owner can choose per render.
- **One snapshot model** (`tiles[]` equals the pushed rows, with `null` pushed as `-1`) keeps the poll and stream panels on the same transformations (`TILE_ROWS`) and contract tests.
- **The cache and the leader lock** make cost independent of viewer and replica counts. The ring buffer reuses the existing record `EVAL`.

### Rejected alternatives

- **Mixed with Prometheus and Grafana Live, or Prometheus and a `-- Dashboard --` reference to a live panel:** renders nothing (verified; `forkJoin`).
- **Dynamic captions as tags or field names:** tags turn rows into per-label columns and leave stale fields; a field name per caption loses history on every change.
- **`configFromData` on a second live query, `partitionByValues` or `byName` tricks:** live frames have no refId or name, and `partitionByValues` drops all frames but the first (verified).
- **`/api/live/publish` with field config:** 403 for stream channels (verified).
- **Static value mappings for every process count:** a dashboard of about 70 KB, and still no captions.
- **Seeding the stream sparkline from Prometheus:** a new subscriber only receives the last frame.
- **Business Text tiles drawing their own stat:** fragile, and changes the look.
- **A per-panel refresh interval:** not available in Grafana 12 or 13.
- **`"buffer": 1` with one shared timestamp per push** (the earlier draft): Grafana 12 keeps only the last row (verified).
- **Omitting `value` for `null`:** a push without any `value` makes the stat show `level` (verified); the `-1` sentinel replaces it.
- **SSE or WebSocket from the app straight to the browser:** cross-origin, and auth tokens in browsers.
- **Leaderless pushing from every replica:** duplicate rows in the buffers and N× pushes.

### Risks

| Risk | Mitigation |
|---|---|
| Grafana changes Live internals (labels mode, buffer trimming); 12.4.13 and 13.2.4 already differ in how they keep same-timestamp rows | The shapes in R4.8 avoid both behaviours (rows 1 ms apart, `groupBy`). The CI grafana/e2e matrix runs `docker/grafana/live-probe.json` on 12.4.13 and 13.2.4, and the weekly e2e on Grafana latest; `__requires` pins `grafana >= 12.3.0`. 12.3.x itself was not probed. |
| An Editor token is broad | Documented; Enterprise custom role recommended; on Grafana 12 a None-role token works; server-side only; rotation via env. |
| Live tiles flicker on every dashboard refresh | Fixed by `groupBy` in `TILE_ROWS` (verified on 12 and 13); the stream e2e samples the tiles 4 times a second across 3 refreshes and fails on duplicate fields. |
| Poll mode multiplies Prometheus load by viewers × refresh | Mode guidance; budget R2.3; stream recommended for wallboards. |
| Live colours flicker with instantaneous busy counts | Documented (no smoothing in 0.1). A 3-sample smoothing in the stream command is a later option. |
| Stream tiles ignore dashboard filters | Documented; poll mode honours them. |

---

## Appendix A′. `config/horizon-observability.php` changes

```php
    /*
    | Metric name prefix: "<namespace>_<family>", e.g. ho_queue_jobs_total; self-metrics ho_exporter_*. '' = no prefix.
    */
    'namespace' => env('HORIZON_OBSERVABILITY_NAMESPACE', 'ho'),

    // … unchanged keys …

    /*
    | Live Overview. enabled: GET {path}/live (live snapshot JSON for Grafana) and the recent-attempts ring buffer
    | written by workers. cache_ttl: seconds a snapshot is shared by all viewers and replicas (0 = no cache).
    | stream: settings of "php artisan horizon-observability:stream", which pushes the snapshot to Grafana Live.
    */
    'live' => [
        'enabled' => env('HORIZON_OBSERVABILITY_LIVE_ENABLED', true),
        'cache_ttl' => (float) env('HORIZON_OBSERVABILITY_LIVE_CACHE_TTL', 1.0),
        'stream' => [
            // Grafana base URL reachable from the app; comma-separated for several Grafana instances without Live HA.
            'grafana_url' => env('HORIZON_OBSERVABILITY_LIVE_GRAFANA_URL'),
            // Grafana service account token; role Editor (live:push) on Grafana 13, any role on 12. Never logged.
            'token' => env('HORIZON_OBSERVABILITY_LIVE_GRAFANA_TOKEN'),
            // Grafana Live stream id: channels stream/<id>/tiles and stream/<id>/delayed. Must match the dashboard render.
            'id' => env('HORIZON_OBSERVABILITY_LIVE_STREAM_ID', 'horizon_observability'),
            // Seconds between pushes.
            'interval' => (float) env('HORIZON_OBSERVABILITY_LIVE_STREAM_INTERVAL', 1.0),
        ],
    ],
```

**Environment variables (additions to the Q3 table):**

| Env var | Key | Default |
|---|---|---|
| `HORIZON_OBSERVABILITY_NAMESPACE` | `namespace` | **`ho`** (was `laravel`) |
| `HORIZON_OBSERVABILITY_LIVE_ENABLED` | `live.enabled` | `true` (named like `HORIZON_OBSERVABILITY_ENABLED`) |
| `HORIZON_OBSERVABILITY_LIVE_CACHE_TTL` | `live.cache_ttl` | `1.0` |
| `HORIZON_OBSERVABILITY_LIVE_GRAFANA_URL` | `live.stream.grafana_url` | null (stream command refuses to start) |
| `HORIZON_OBSERVABILITY_LIVE_GRAFANA_TOKEN` | `live.stream.token` | null (stream command refuses to start) |
| `HORIZON_OBSERVABILITY_LIVE_STREAM_ID` | `live.stream.id` | `horizon_observability` |
| `HORIZON_OBSERVABILITY_LIVE_STREAM_INTERVAL` | `live.stream.interval` | `1.0` |

**Validation (additions to Q3):**

| Key | Rule; on violation |
|---|---|
| `live.enabled` | boolean |
| `live.cache_ttl` | in [0, 10]; otherwise 1.0 + problem |
| `live.stream.grafana_url` | comma-separated http(s) URLs; invalid entries dropped + problem |
| `live.stream.token` | non-empty string, or null. `#[\SensitiveParameter]`; never printed by `about` or `check` (only "set" or "missing"). |
| `live.stream.id` | `^[A-Za-z0-9_-]{1,64}$`; otherwise the default + problem (Grafana does not validate it) |
| `live.stream.interval` | in [0.5, 10]; otherwise 1.0 + problem |

- **Boot-time keys:** the booting callback also reads `live.enabled` (route registration and the maintenance exception).
- **Config DTO additions** (Appendix C′): `liveEnabled`, `liveCacheTtl`, `liveGrafanaUrls` (`list<string>`), `liveToken` (`?string`), `liveStreamId` and `liveStreamInterval`.
- **Internal constants** (Q3 table additions):

  | Area | Constants |
  |---|---|
  | Ring | buckets 5 s; TTL 180 s; window 60 s (12 complete buckets) |
  | Snapshot | live head scan 20 items and 1 MB per queue; fresh copy kept `cache_ttl`; stale copy kept `max(2 s, 10 × cache_ttl)`; build lock 2 s; follower wait 500 ms |
  | Stream | leader TTL 5 s, renewed every tick and before every push; follower retry 2 s; push timeout 2 s, connect 1 s; backoff 1, 2, 4, 8, 15 s (60 s for 401/403); lease released after 5 consecutive failed ticks, then 10 s pause; delayed window 179 × 5 s + current; "unavailable" frame every 5 s at most; tile rows 1 ms apart; `null` pushed as -1 |
  | Live panels | stream tiles `buffer` 500 ms; value mapping -1 → "—"; #24 `maxDataPoints` 100 |
  | Extremes | buckets 15 s; merge 10 buckets; TTL 300 s |
  | Dashboard | `LOOKBACK = max(10m, 10×S)`; `busy_window = max(15s, 2×S)` |

## Appendix B′. Metric catalog changes (`Metrics\Metric`)

| Case | Family (without namespace) | Type | Labels | Collector | HELP |
|---|---|---|---|---|---|
| JobDurationMin | `queue_job_duration_min_seconds` | gauge | connection, queue, job_class | attempts | Shortest job attempt during the last 150 seconds, seconds. |
| JobDurationMax | `queue_job_duration_max_seconds` | gauge | connection, queue, job_class | attempts | Longest job attempt during the last 150 seconds, seconds. |
| AttemptsDropped | **`exporter_attempts_dropped_total`** | counter | – | attempts | Job attempts not recorded because the metrics Redis was unavailable. |
| LivePushTimestamp | **`exporter_live_push_timestamp_seconds`** (new) | gauge | – | attempts | Time of the last successful Grafana Live push of the live overview, Unix seconds. |
| LivePushFailures | **`exporter_live_push_failures_total`** (new) | counter | – | attempts | Failed Grafana Live pushes of the live overview. |
| CollectorSuccess | **`exporter_collector_success`** | gauge | collector | self | Whether the last run of a metrics collector succeeded: 1 or 0. |
| CollectorDuration | **`exporter_collector_duration_seconds`** | gauge | collector | self | Duration of the last run of a metrics collector, seconds. |
| BuildInfo | **`exporter_build_info`** | gauge | version, php, laravel, horizon, redis_client | self | Versions of the package, PHP, Laravel, Horizon and the Redis client; always 1. |
| ConfigProblems | **`exporter_config_problems`** | gauge | – | self | Number of invalid configuration values replaced by defaults; see php artisan horizon-observability:check. |

- Every other row of Appendix B is unchanged.
- **Declaration order:** the two live cases follow AttemptsDropped.
- **`since()`:** `0.1.0` for all cases.
- **Reserved-label test:** extended to the new cases.
- **Example names** with the default namespace: `ho_queue_jobs_total`, `ho_exporter_collector_success`.

## Appendix C′. Interface additions (land in WP0b)

```php
namespace DF\HorizonObservability\Live;

/** Resolved scope of a snapshot. Frozen in WP0b. */
final readonly class LiveScope
{
    /** @param list<string>|null $supervisors null = all  @param list<array{connection: string, queue: string}> $queues */
    public function __construct(public ?array $supervisors, public array $queues, public bool $jobFilter, public ?array $jobClasses) {}
    public static function full(\DF\HorizonObservability\Horizon\Topology $topology): self;
    /** @param array{supervisors: list<string>|null, batchKinds: list<string>|null, queues: list<string>|null, jobClasses: list<string>|null} $filters */
    public static function fromFilters(array $filters, \DF\HorizonObservability\Horizon\Topology $topology,
                                       \DF\HorizonObservability\Horizon\Supervisors $supervisors, \DF\HorizonObservability\Batches\BatchLinks $links): self;
    public function cacheKey(): string;                                   // sha1 of the normalised scope
}
// JobListQuery needs a JobList, which /live has none of, so the filter parsing is shared instead (Http, WP0b):
//   JobListQuery::filtersFromRequest(Request $request): array{supervisors: ?list<string>, batchKinds: ?list<string>,
//                                                             queues: ?list<string>, jobClasses: ?list<string>}   // 422 on invalid
//   JobListQuery::fromRequest() calls it, so both endpoints parse var-* identically.

/** Typed snapshot, schema v1 (R4.4). Frozen in WP0b; toArray() is the public JSON. */
final readonly class LiveSnapshot
{
    public const SCHEMA_VERSION = 1;
    /** @return array<string, mixed> */ public function toArray(): array;
    /** @return list<array{tile: string, key: string, caption: string, value: float|null, color: string, level: int}> */
    public function tiles(): array;
    public function generatedAtMs(): int;
}

/** Caption, level and colour rules shared with src/Grafana (one source of truth). Implemented in WP0b. */
final class Captions
{
    public const WAIT_WARN = 1.0; public const WAIT_CRIT = 2.0; public const UTIL_WARN = 70; public const UTIL_CRIT = 90;
    public static function oldest(?int $ageSeconds, ?string $queue): string;     // "Oldest: 17 s · default" | "Oldest: 3 min · slow" | "Oldest: —"
    public static function supervisor(string $name, int $maxProcesses): string;  // "default · max 3"
    public static function rpm(?int $perMinute): string;                         // "RPM: 42" | "RPM: —"
    public static function waitLevel(?float $ratio): int;                        // 0|1|2
    public static function utilizationLevel(float $utilization, bool $horizonRunning): int;
    public static function color(int $level): string;                            // green|yellow|red
}

final class LiveSnapshotBuilder { public function build(LiveScope $scope, \Carbon\CarbonImmutable $now): LiveSnapshot; }
final class LiveSnapshotCache   { public function get(LiveScope $scope): LiveSnapshot; }      // R4.2 algorithm
final class AttemptRing
{
    public function key(int $unixSeconds): string;                       // live:ring:<unix div 5>, via Keys
    public function ttl(): int;                                          // 180, or 0 when live is disabled
    /** @return array<string, array<string, int>> "connection\x1fqueue" => status => count over the 60 s window */
    public function window(\Carbon\CarbonImmutable $now): array;
}

namespace DF\HorizonObservability\Live\Stream;

final class LineProtocol
{
    /**
     * tiles rows stamped $timeNs + k ms in R4.7 order; null values encoded as -1.0.
     * @param list<array{0: int, 1: float}> $delayedWindow [unix_ms, value], ascending
     */
    public static function encode(\DF\HorizonObservability\Live\LiveSnapshot $snapshot, int $timeNs, array $delayedWindow): string;
    /** @param list<array{tile: string, key: string}> $rows the rows of the last successful push, or [] for pending only */
    public static function unavailable(int $timeNs, array $rows): string;
}
final readonly class PushResult { public function __construct(public bool $ok, public ?int $status, public ?string $error, public float $seconds) {} }
final class GrafanaLivePusher { public function push(string $baseUrl, string $streamId, #[\SensitiveParameter] string $token, string $body): PushResult; }
final class LeaderLock { public function acquire(): bool; /** false = lease lost: stop pushing */ public function renew(): bool; public function release(): void; public function token(): string; }
final class LiveStreamer { /** @param \Closure(): bool $shouldStop */ public function run(\Closure $shouldStop, int $maxSeconds, int $memoryMb, bool $once): int; }
```

**Changed frozen signatures:**
- `Redis\Keys` gains:
  - `ring(int $bucket): string`;
  - `liveSnapshot(string $hash): string`, `liveSnapshotStale(string $hash): string` and `liveSnapshotLock(string $hash): string`;
  - `liveLeader(): string`;
  - `liveDelayedWindow(): string`;
  - `livePattern(): string` (for `flush`).
- `Redis\Keys::extremes(int $window, string $kind)` keeps its name; its argument becomes the 15-second bucket (`unix div 15`), renamed `$bucket`.
- `Http\JobListQuery` gains the static `filtersFromRequest()` shown above.
- `Config\Config` gains the six live properties (A′).

## Appendix D′. Lua contract changes

**`record_attempt.lua`** (WP2):
- **KEYS:**
  1. `attempts`, `duration:buckets`, `duration:sum`, `wait:buckets`, `wait:sum`;
  2. **`extremes:<unix div 15>:min`, `extremes:<unix div 15>:max`**;
  3. `job_classes`, `meta`;
  4. **`live:ring:<unix div 5>`**;
  5. optionally `ready_at:<uuid>`.
- **ARGV:** as before, with the extremes TTL now **300**, plus **`ring_ttl`** (180, or 0 = disabled), inserted after the class idle TTL and before `now`. PHP derives both bucket keys from the same `now` it passes in `ARGV`.
- **Step 6 (new):** if `ring_ttl > 0`: `HINCRBY KEYS[ring] conn␟queue␟status 1` and `EXPIRE KEYS[ring] ring_ttl`.

**`extremes_read.lua`** (new, WP2):
- **KEYS:** the 10 min keys (current bucket and 9 previous), then the 10 max keys.
- **Returns:** `{min_pairs…, '\x1e', max_pairs…}` with `field, value` pairs merged by `min` and `max`. Missing keys are skipped.

**`queue_state.lua`** (WP3; contract extended, parity test unchanged):
- **ARGV:** now, scan_limit, reserved_scan_limit, max_bytes, **mode** (`full`, as today, or `live`).
- **In `live` mode:**
  - the readiness scan stops at `min(scan_limit, 20)` items and at `max_bytes`, which the builder sets to 1 MB (20 KB payloads cost 0.9 ms of Redis time per queue, measured);
  - `pending_pairs` is empty;
  - `reserved_pairs` is returned only when `reserved_scan_limit > 0`, which the builder sets when a Job or Batch filter is active.

**`ring_read.lua`** (new, WP-L1):
- **KEYS:** the 12 complete 5-second bucket keys before the current one.
- **Returns:** a flat list of `field, sum`.

**`lock.lua`** (new, **WP-L1**, because the snapshot cache needs it first; WP-L2's `LeaderLock` reuses it):
- **KEYS:** the lock key.
- **ARGV:** op (`renew` or `release`), token, ttl_ms.
- **Behaviour:** `renew` runs `PEXPIRE` only if the value equals token; `release` runs `DEL` only if the value equals token.
- **Returns:** 1 or 0.

## Appendix E′. Redis key schema additions

All keys sit under `{horizon-observability}:v1:` on the storage connection, as in Appendix E, so they are cluster-safe.

| Key | Type | Fields or members | TTL |
|---|---|---|---|
| `extremes:<unix div 15>:min`, `…:max` | hash | `conn␟queue␟class` → seconds (**replaces** the 60-second windows) | 300 s |
| `live:ring:<unix div 5>` | hash | `conn␟queue␟status` → count | 180 s |
| `live:snapshot:<sha1(scope)>` | string | fresh snapshot JSON (schema v1); presence means fresh | `cache_ttl` (1 s) |
| `live:snapshot-stale:<sha1(scope)>` | string | the same JSON, served while another request rebuilds | `max(2 s, 10 × cache_ttl)` |
| `live:snapshot-lock:<sha1(scope)>` | string | lock token | 2 s |
| `live:leader` | string | `host:pid:rand` of the stream leader | 5 s, renewed every tick and before every push |
| `live:window:delayed` | list | `"<unix_ms>:<value>"`, newest first, trimmed to 179 | 1 h |
| `meta` (existing hash) | hash | new fields `live_push_at` (Unix float) and `live_push_failures` | – |

`horizon-observability:flush` also deletes `live:*`, using `SCAN` with the prefix.

## Q7′. Package tree additions

```
src/
  Live/
    LiveScope.php                 (WP0b) scope from the shared var-* filters / full(); cacheKey()
    LiveSnapshot.php              (WP0b) typed schema v1; toArray(); tiles()
    Captions.php                  (WP0b) caption/level/colour rules shared with src/Grafana
    LiveSnapshotBuilder.php       (WP-L1) Horizon + queue_state live mode + ring → snapshot
    LiveSnapshotCache.php         (WP-L1) stale-while-revalidate cache; freshness by Redis key expiry; Redis lock
    AttemptRing.php               (WP-L1) ring key/ttl for the writer; 60 s window reader
    Stream/
      LiveStreamer.php            (WP-L2) leader, build, encode, push, backoff, meta updates
      LeaderLock.php              (WP-L2) SET NX PX + lock.lua
      LineProtocol.php            (WP-L2) tiles + delayed measurements; escaping; unavailable frame
      GrafanaLivePusher.php       (WP-L2) Http client POST /api/live/push/{id}
      PushResult.php              (WP-L2)
  Http/LiveController.php         (WP-L1) GET {base}/live
  Console/StreamCommand.php       (WP-L2) PUBLIC horizon-observability:stream
  Grafana/Live/{LiveMode.php, LivePanels.php}   (WP8D)
  Grafana/Perf/StaticBudget.php   (WP8C) request count / interpolated size / [1d] checks used by tests and --check
resources/lua/{extremes_read,ring_read,lock}.lua   (extremes_read: WP2; ring_read and lock: WP-L1)
docker/grafana/live-probe.json                  (WP-L2 / T12) e2e fixture with the R4.8 panel shapes
docker/perf/{budget.json, prometheus.yml}       (WP9)
bin/perf.mjs                                    (WP9)
workbench/app/Console/Commands/{PerfDataCommand.php, PerfExporterCommand.php}   (WP9)
workbench/app/Perf/SyntheticModel.php           (WP9) port of probe/synth.py
```

**Public API additions:**
- the command `horizon-observability:stream` and its options;
- the route `horizon-observability.live` and its query parameters;
- the snapshot JSON schema v1;
- the line-protocol measurements and fields (4.7);
- the `live.*` config keys and env vars;
- the render options `--live`, `--live-poll-interval` and `--live-stream-id`;
- the dashboard variable `scrape_interval`;
- the rule uid suffix `-live`;
- the Grafana Live channel names `stream/<id>/tiles` and `stream/<id>/delayed`.

## Q9′. Work-package delta

**Changed packages:**

| WP | Change | Acceptance additions |
|---|---|---|
| **WP0b** | Implement the A′ config keys and validation, the `Metric` renames and the two new cases (B′), the `Keys` additions (C′), `JobListQuery::filtersFromRequest()`, the `live` route plus maintenance exception plus boot-time key, and `Live/{LiveScope,LiveSnapshot,Captions}` in full. Stubs for every other C′ class: the builder returns an empty snapshot, the stream command exits 1. | Catalog test equals B′; `Captions` unit tests cover every caption and level rule of R4.4 (shared fixtures with the Grafana renderer); the route-precedence test includes `/live`. |
| **WP1** | Self-metric names; live self-metrics read from `meta`. | Golden exposition with `ho_exporter_*`; live series absent until `live_push_at` exists. |
| **WP2** | `record_attempt.lua` ring write and 15-second extremes; `extremes_read.lua`; `AttemptStore` reads merge 10 buckets. | Extremes visibility test: an attempt recorded at `t` is exported by simulated scrapes at `t + 0…135 s` and not after `15(b+10)`; a table test over S ∈ {5, 10, 15, 30, 45, 60} s and deviations up to ±7.5 s asserts at least two exporting scrapes (R3.4). Ring test: N attempts in known buckets give the exact window counts. A disabled live mode writes no ring key. |
| **WP3** | `queue_state.lua` live mode; `QueueInspector::live()`. | Parity test unchanged; live mode scans at most 20 items and 1 MB; reserved pairs only on request. |
| **WP8B** | Step 2 uses prefix `ho_`. **New step 12**: R3 windows (the `scrape_interval` variable, per-panel `interval`, the `busy_window` formula, `LOOKBACK`, `--scrape-interval` default `10s`, the refresh derivation). | Contract test: no literal `[15s]` and no `[1d]` on counter selectors in the committed render; every Prometheus panel has `interval: "$scrape_interval"`. |
| **WP9** | Perf profile and tools (R2.2); the dev Prometheus job at 10 s; the dev Grafana (`GRAFANA_IMAGE` 12.4.13 or 13.2.4) with `GF_DASHBOARDS_MIN_REFRESH_INTERVAL=1s` and a bootstrap script `docker/grafana/bootstrap-live.sh`, which creates the Editor service account and token and writes the token to `docker/grafana/live-token` (gitignored, as `planning/TASKS.md` T11 specifies); a compose service `stream` (profile `app`) running `testbench horizon-observability:stream` with the token read from that file. | `bin/perf.mjs capture/replay/report` reproduces the R2.1 legacy row within ±25 % on the reference profile; `bootstrap-live.sh` is idempotent; both Grafana images provision without errors. |
| **WP10** | `check`: the live block (enabled; stream URL, token set or missing, stream id; optional `--live-push-test`, which pushes one `tiles` frame to stream id `<id>_check` and reports the HTTP status with the R4.10 hints for 401 and 403). `install`: prints the Prometheus job at 10 s, the Grafana service-account commands (role Editor) and the render commands for each mode. `flush`: `live:*`. | Seeded cases: missing token, 401 token, 403 token, invalid stream id. |
| **WP11** | README sections: Live Overview (modes, requirements, failure modes), Performance (budget, settings), Scrape interval. Weekly e2e: perf report, plus screenshots for `--live=poll` and `--live=stream`. | The budget report is green on the weekly run; live screenshots show live values that change between two captures 2 s apart (stream). |

**New packages:**

| WP | Owns | Depends on | Acceptance |
|---|---|---|---|
| **WP-L1 Live snapshot and endpoint** (phase B) | `Live/{LiveSnapshotBuilder,LiveSnapshotCache,AttemptRing}`, `Http/LiveController`, `resources/lua/{ring_read,lock}.lua`, `tests/{Unit,Integration,Feature}/Live/*` (except `Stream/`) | WP0b contracts; WP2 ring write and WP3 live mode (develops against stubs and seeded keys) | JSON golden for a seeded state, every R4.4 field and unit. Filters: supervisor and queue scope; a job filter gives `null` values; an empty scope makes 0 queue calls. Cache: 50 concurrent requests make **1** build per TTL (build counter) across 2 simulated replicas **whose clocks differ by 5 s**; the stale copy serves at most 10 s old data. A Redis failure gives 200 with `errors[]` and caption `Live unavailable`. Auth matrix as the job lists. Cost: ≤ Q + 5 round trips per build and 1 `MGET` per cache hit (command counter); build ≤ 50 ms p95 for 20 queues in the dev stack. |
| **WP-L2 Stream command** (phase B/C) | `Live/Stream/*`, `Console/StreamCommand`, `docker/grafana/live-probe.json`, `tests/{Unit,Integration}/Live/Stream/*`, `tests/Feature/Console/StreamCommandTest` | WP-L1 (`LiveSnapshotCache`, `lock.lua`), WP0b | Line-protocol golden (escaping, floats, rows 1 ms apart, `-1` for `null`, unavailable frame). A fake Grafana HTTP server (testbench route or `Http::fake`) answering 200, 401, 403, 500 and timeout drives the backoff, the release after 5 failed ticks and the `meta` counters. Two `LiveStreamer` instances on one Redis produce exactly one pusher; a leader whose lease was taken over pushes nothing after its next renew; killing the leader means the follower pushes within 7 s. SIGTERM releases the lock. `--once` and `--max-time` work. e2e in the dev stack on Grafana 12.4.13 and 13.2.4 with `live-probe.json`: tiles update at least every 2 s and show no duplicate fields across 3 dashboard refreshes. |
| **WP8C Dashboard performance** (phase C, after WP8B, same owner chain `src/Grafana/**`) | `src/Grafana/**` (continues), `Grafana/Perf/StaticBudget`, `tests/Grafana/PerfBudgetTest`, the `resources/grafana/js/alert-sound.js` hide list | WP8B, WP9 tools | O1–O8 (O9 last, opt-in), each its own commit with a screenshot diff (layout projection green) and a `bin/perf.mjs` report, on Grafana 12.4.13 and 13.2.4. The R2.3 budget is met on the reference profile, including the O2 batch scope. Static gates in CI: ≤ 12 requests per refresh, ≤ 100 KB interpolated PromQL with All, the O3 variable policy, no `[1d]` on counters, #24 has `maxDataPoints`, no `batch_filtered()`-style duplicate branch. Batch=All and Batch=kind equality tests for O2. e2e: every #5 repeat is non-empty with #23's script active. |
| **WP8D Live panels** (phase C, after WP8C) | `src/Grafana/Live/**`, `tests/Grafana/Live*Test`, the renders `tests/Fixtures/grafana/{off,stream}/*` (the default render is poll) | WP8C, WP0b (`LiveSnapshot`, `Captions`); WP-L1 and WP-L2 for the e2e | Three renders (off, poll, stream) pass the Q5.7 contract tests; the panel JSON equals R4.8 (`TILE_ROWS`, `buffer: 500`, `SENTINEL`, `key` column). Every Infinity column selector and every line-protocol field used by panels exists in `LiveSnapshot` and `LineProtocol` (contract test). Row title, `__requires`, the `<uid>-live` rule (`timestamp()` form) and the selector. e2e on 12.4.13 and 13.2.4: poll tiles show the live value within one refresh; stream tiles within 2 s; screenshots attached. |

**Critical path and schedule:**

```
WP0a ─┬─> WP0b ─┬─> WP1..WP7, WP9 ─────────────┐
      │         ├─> WP-L1 ─> WP-L2 ─────────────┤
      │         │   (needs WP2 ring + WP3 live)  ├─> WP10 ─> WP11
      └─> WP8A ─> WP8B ─> WP8C ─> WP8D ─────────┘
```

| When | Work |
|---|---|
| Day 0 | WP0a; WP0b (with the C′ contracts); WP8A from the afternoon |
| Days 1–4 | WP1–WP7, WP9, WP-L1 (against stubs from day 1); WP8A continues |
| Days 4–6 | WP8B; WP-L2; WP10 |
| Days 6–7 | WP8C |
| Days 7–8 | WP8D |
| Days 8–9 | WP11 |

The critical path is WP0a → WP8A → WP8B → WP8C → WP8D → WP11, about 2 days longer than in Q9.

**Mapping to `planning/TASKS.md`** (the progress record of the repository; its ids win for scheduling):

| TASKS id | Work packages here | Note |
|---|---|---|
| T02 | WP0b | includes the C′ contracts, `filtersFromRequest()` and the live route |
| T03 | WP1 | live self-metrics |
| T04 | WP2 | ring write, 15-second extremes |
| T05 | WP3 | `queue_state.lua` live mode; T12 needs it, so T12 also depends on T05 (or develops against a stub) |
| T11 | WP9 | perf tools, `bootstrap-live.sh`, both Grafana images |
| T12 | WP-L1 + WP-L2 | one task; owns `lock.lua` and `docker/grafana/live-probe.json` |
| T13 | WP8B + WP8C + WP8D | one sequential task, in that order |
| T14 | WP10 | |
| T15 | WP11 | |

---

## Appendix P. Probe log (reproducible)

Everything lives under `/tmp/claude-0/-home-user-horizon-observability/cc6491ff-4b68-5ae7-bac7-d93fef57187c/scratchpad/design/addendum/`. The repository was not modified.

**Stack:**
- Prometheus `quay.io/prometheus/prometheus:latest` (3.15.0) on port 9391.
- Grafana `grafana/grafana:latest` (13.2.4) on port 3301 with `--network host`, provisioning in `stack/grafana/`.
  - Plugins were installed with `GF_PLUGINS_PREINSTALL_SYNC` through the agent proxy: `HTTPS_PROXY=http://127.0.0.1:32939` (the task's 37347 was stale) plus `SSL_CERT_FILE` mounted. Only `HTTPS_PROXY` is supported by the proxy.
- `probe/fakeapp.py` on port 8391: `/metrics` continues the synthetic history; `/live` returns a prototype snapshot.

**Data:**
- `probe/synth.py` is the model and `probe/backfill.py` the 2-hour OpenMetrics chunks.
- 26 h at 15 s, 5,279 active series; 5.7 GB of text; converted with `promtool tsdb create-blocks-from openmetrics` per chunk in 4 min 24 s, giving 42 MB of blocks.
- `horizon_info` has 7 days of history at 60 s.

**Performance:**
- `probe/measure.mjs` (Playwright capture), `probe/replay.py` (stats=all), `probe/variants.py` and `probe/variant5.py` (v1–v5), `probe/formulas.py` (O4 equality and cost).
- Results: `results/{legacy,v1,v2,v3,v4,v5,v5b}-*.json`, `*.replay.json`, `*-replay.txt`; screenshots `results/{legacy-shot3,v5-shot3}.png` (pixel-identical).

**Live:**
- Dashboards: `probe/live_dashboard{,2,3,4}.py`, rendered to `stack/grafana/dashboards/probe/live*.json`.
- Pushers: `probe/pusher{,2,3,4}.py`; sampler: `probe/sample.mjs`.
- Results: `results/live/*` (tokens redacted from this document; `push-<role>.txt` holds the role answers).

**Grafana source:**
- `probe/srcmap.py` extracts original TypeScript from the shipped source maps. The extracted files (`gfsrc/src/**`) are the basis for the source-level facts in A0.3.

**Measured numbers not repeated above:**
- Legacy per target (1 h): #4 A 2,596 ms / 3.0 G samples; #8 A 1,330 ms / 1.5 G; #5 J 840 ms per repeat; #5 E 376 ms per repeat; #17 D 503 ms.
- v4 per target (1 h): #5 J 135–157 ms per repeat; #4 B 126 ms; #4 A 100 ms.
- `[1d]` against `[10m]` lookback: 85 ms / 1,036,800 samples against 3 ms / 7,200 (180 series).
- Combined attempts per table: 374 ms (C+E+H+I+J) against 50 ms (one aggregate-first query by queue and status) or 110 ms (per-series semantics).
- Incremental querying: after the first full 3,600 s request, refreshes requested 609–629 s.
- Live push latency: 15–21 ms on localhost. Windowed push size: about 4–24 KB at 60 rows with 4 supervisors (probe format).

## Appendix P′. Review probes (2026-10-17)

Everything lives under `/tmp/claude-0/-home-user-horizon-observability/cc6491ff-4b68-5ae7-bac7-d93fef57187c/scratchpad/design/review/`. Containers used non-default ports and were removed afterwards; the repository was not modified.

| Probe | Files | What it showed |
|---|---|---|
| Push auth | `gf/roles.py` on Grafana 13.2.4 (port 3411) and 12.4.13 (port 3412), anonymous auth off; the token files were deleted with the containers | 13: None/Viewer 403, Editor/Admin 200. 12: every role 200. Invalid or missing token 401 on both; malformed body 500; stream ids with a dot or 64 characters accepted; `/api/live/publish` to `stream/…` 403 for Admin; provisioned `refresh: 1s` stored as `5s` on both |
| Stream tiles, addendum draft | `gf/mkdash.py`, `gf/pusher.py`, `gf/sample.mjs` (dashboard `rv-live`) | 13: draft `TILE_ROWS` renders; pushes without `value` show "level 0"; `-1` + mapping shows "—"; duplicate fields on every refresh. 12: draft tiles show "—" only. Delayed window with 180 rows renders on both |
| Raw frames | `gf/mkdebug.py`, `gf/sample2.mjs` (`rv-debug`) | 12 keeps one row per timestamp (the last), 13 keeps all; WebSocket schema `labels, time, caption, color, key, level, tile, value` |
| Corrected stream shape | `gf/mklive2.py`, `gf/pusher2.py`, `gf/sample3.mjs` (`rv-live2`, refresh 5 s, 140 samples at 250 ms) | Rows 1 ms apart + `buffer: 500` render on both; without `groupBy` 8–18 duplicated samples, with `groupBy` 0; colours green/yellow/red applied on 12 |
| Shared source #24 | `gf/mkshare.py`, `gf/mkshare2.py`, `gf/share.mjs` (`rv-share`, TestData) | Visible top-level: 1 run per refresh. Bottom of the page: 1. Collapsed row: 2 on 13, 1 on 12. `display: none` from first render: 0 runs, consumers empty. Hidden after first render: 1. Hidden from start with `maxDataPoints: 100`: 1, consumers filled. Same on 12 and 13 |
| Poll shapes | `poll/fakeapp.py` (probe copy with `tiles[]` and `generated_at`), `poll/mkpoll.py`, `poll/psample.mjs` (`rv-poll`, Infinity 4.1.1 on 12.4.13 port 3413 and 13.2.4 port 3414) | Mixed + `merge` + `configFromData` shows the live value and caption; forced red applied; a missing live value falls back to the Prometheus value; `TILE_ROWS` with `key` and `groupBy` renders the supervisors; identical on 12 and 13 |
| PromQL replay | `replay-tool.py` (copy of `probe/replay.py`), `replay/*.txt`, Prometheus 3.15.0 on port 9491 over a copy of the probe TSDB (`promdata/`) | legacy 14.7 s / 4.6 G samples / 2,143 KB; v5 1.47 s / 55 M / 57 KB; v5b 1.40 s / 48 M / 58 KB; Overview row from the captures |
| Batch scope | `promql/batch_scope.py` | #8 A: 52–66 ms unscoped, 403 ms with an always-evaluated exclusion set, 96 ms with the absent-label switch at Batch=All, 308 ms with one kind; `__batch_scope__!~".*"` selects nothing |
| Extremes windows | `sim/extremes.py` (Monte Carlo) | Minimum exporting scrapes per attempt by S and deviation; legacy 60-second windows reach 0 at S = 60 s |
| Snapshot cost | `snap/bench.php` (phpredis, `redis-server` on port 6390) | 20 queues: 5.6 ms p50 / 8.5 ms p95 (1.5 KB payloads), 20 ms / 30 ms (20 KB); 40 queues 9.0 ms p50; 29 round trips per build incl. cache |
| Sources | `prom13/src` (bundled Prometheus plugin 13.2.3), `gf12src` (12.4.13 sources extracted from the image's source maps with `probe/srcmap.py`) | Incremental-query conditions and target signature; `$__rate_interval` source; 12 vs 13 diffs of `StreamingDataFrame`, `rowsToFields`, `fieldToConfigMapping`, `MixedDataSource`, `SceneQueryRunner` (functionally identical) |
| Floors | `fw-v11.23.0.json`, `fw-v12.0.0.json` | guzzle `^7.8` and `^7.8.2` |
| Look | PIL diff of `results/legacy-shot3.png` and `results/v5-shot3.png` | no differing pixel |

## Review log (2026-10-17)

**Changed** (the pre-review text is kept as `ADDENDUM.pre-review.md`):
1. **Grafana 12 support.** The addendum only probed 13.2.4, although Grafana 12 and 13 are both supported (`planning/README.md`, decision 4). The review re-ran the live probes on 12.4.13 and recorded per-version facts in A0.3.
2. **Push role** (A0.3, R4.9, R4.10, A′): Editor is the minimum on 13.2.4 only; 12.4.13 accepts any role. Invalid or missing tokens give 401 with anonymous auth off (previously unverified). Error hints were updated.
3. **Stream tile shape** (A0.1, A0.3, R4.7, R4.8, C′):
   - `"buffer": 1` with one shared timestamp fails on 12.4.13, so rows are now stamped 1 ms apart with `"buffer": 500`;
   - `TILE_ROWS` gained `groupBy`, which removes the duplicate fields after every dashboard refresh;
   - `null` is pushed as `-1` with a value mapping, because omitting `value` made the tile show `level`;
   - the poll #3 query gained the `key` column.
4. **O5 hidden source** (A0.3, O5, R2 risks, WP8C): a `display: none` #24 never runs without an explicit `maxDataPoints`. Fixed and verified on both versions. The collapsed-row run count differs by version.
5. **O2 batch scope** (A0.3, R2.1, O2, budget): the measured O2 gain excluded the batch scope. The `batch_filtered()` pattern doubles the cost and an always-evaluated set costs 6×. It is replaced by an `unless` with an absent-label switch, measured at +44 ms on #8 A at Batch=All.
6. **Incremental querying** (A0.3, R2.1, R2.5): the cache key contains the interpolated expression, so `${__from}`/`${__to}` targets are never served incrementally, and O8 makes #3 instant. Only #9 benefits; the claim about `@ ${__to}` correctness was wrong reasoning.
7. **`$__rate_interval`** (A0.3, R2.5, R3.3): it is not derived from the panel min interval; the claim "verified" was removed (the dashboard does not use it).
8. **Snapshot cost and cache** (R4.2, budget, WP-L1, A′, E′):
   - the cost is Q + 5 round trips, not "1 + supervisors"; measured 5.6 ms p50 for 20 queues;
   - live mode gained a 1 MB scan cap;
   - freshness now comes from Redis key expiry (fresh and stale keys, `MGET`): the draft compared `generated_at` with the local clock, which defeats the 1-build-per-TTL bound under app clock skew;
   - WP-L1's cost acceptance (`≤ 3 + Q`) was inconsistent and is fixed.
9. **Leader lock** (R4.6, C′, A′, WP-L2): renew before every push and stop on a failed renew (a tick can outlast the 5 s lease); release after 5 failed ticks so another replica can try.
10. **Stall rule** (R4.11, R4.10, A0.2): `time() - max(…)` fired falsely at S ≥ 30 s. It is now `max by (job) (timestamp(x) - x)`, which is scrape-interval agnostic.
11. **Extremes guarantee** (R3.1, R3.4, R3 risks, WP2): the "jitter below 15 s" bound holds only for late-only delays, and "one failed scrape tolerated at S ≤ 45 s" was too conservative (two scrapes, hence one tolerated failure, already hold at S = 60 s). The section now gives two-sided bounds (±37.5 s for one scrape, ±7.5 s for two at S = 60 s), checked by simulation.
12. **Consistency with ARCHITECTURE.md and `planning/`:**
    - env var `HORIZON_OBSERVABILITY_LIVE` → `HORIZON_OBSERVABILITY_LIVE_ENABLED`;
    - `--live` defaults to `poll` (owner decision 9 in `planning/README.md`);
    - `LiveScope::fromQuery(JobListQuery)` could not work for `/live` (no `JobList`), so it is replaced by the shared `filtersFromRequest()`;
    - `lock.lua` moved to WP-L1, which needs it first;
    - `Keys::extremes()` keeps its name;
    - Q5.7 contract-test amendments;
    - a mapping to the `planning/TASKS.md` ids (T12 = WP-L1 + WP-L2, T13 = WP8B–D, T12 also depends on T05);
    - the bootstrap token goes to `docker/grafana/live-token` as T11 specifies;
    - O9 is opt-in rather than "out of scope", as T01/T13 require.
13. **Minor fixes:**
    - guzzle floors verified (`^7.8` at 11.23.0);
    - push size 7.4 KB;
    - replay step must not be floored at 15 s;
    - Overview-row timings and budget line;
    - look deviations of the live modes listed for the owner;
    - live frames do carry a schema name, which the browser frame does not keep.

**Still unverified:**
- O6 end to end (the Grouping-to-matrix pivot keeping the table look) and O8/O9; O2 panel-level equality with legacy at Batch=kind (only the exclusion set and its cost were checked on synthetic data).
- The full refresh-cycle timings after O2's scope and O6 (expected about ±0.1 s), the large profile, other hardware and Grafana 12 timings; the 60 MB/day storage estimate (arithmetic only).
- `viewPanel=` deep links to a #5 repeat with the hidden #24.
- Reverse-proxy WebSocket requirements, `allowed_origins`, and Grafana Live HA (`ha_engine = redis`) across several instances; Grafana 12.0–12.3 and versions after 13.2.4.
- The rewritten snapshot cache, ring buffer, `record_attempt.lua`/`extremes_read.lua` changes, leader failover, SIGTERM release and backoff: design only. The extremes guarantee is simulated, not tested against real scrapes. The snapshot cost was measured on loopback without Laravel's request bootstrap.
- A hidden textbox variable in the panel interval: confirmed in Scenes source on both versions (`interpolate()` of `minInterval`), not by a probe.
- The `-1` sentinel in poll mode (poll uses `null`, which shows `noValue`) and the unavailable frame after a real Redis outage.
