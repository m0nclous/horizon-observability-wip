# T17 Dashboard truth tests: final design

Status: final design (2026-10-19), for the T17 implementers and the T17 spec and adversarial testers. Written from three
designs ("fidelity", "determinism", "cost") and two independent judgements:
- base: "fidelity" (judgement 1 winner) merged with "determinism" (judgement 2 winner);
- grafted: the cheap guards of "cost";
- every assumption the judges flagged was settled in the code or by an experiment (section 17).

Where this document and an earlier design disagree, this document wins.

Owner sources: `planning/README.md` decisions 7, 8, 9, 12, 13 and 14; TASKS T13, T16 and T17; ARCHITECTURE Q5, Q9 and
appendix B; ADDENDUM R2, R3, R4 and B′; T16's `tests/Truth/**` on master; T13's renders and code on `wip/T13-grafana`.
The round-4 head examined is `d007725` (local branch of the T13 implementer, not pushed yet). The pushed head is
`9edfae6`.

---

## 1. Goals and non-goals

### Goals

- **G1. Truth.** Every number the rendered dashboard shows must equal the ground truth of a deterministic scenario. That covers
  tile values, captions, colours and levels, table cells, series points, variable options and repeat clones, in all three renders
  (`--live=off`, `poll` = the committed render, `stream`), on Grafana 12.4.13 and 13.2.4.
- **G2. Fidelity.** The queries evaluated are exactly what Grafana sends: the real frontend interpolates them in a real browser
  and the real backend substitutes the macros and chooses the step. T17 never re-implements either.
- **G3. Cases (decision 13 and T17).** All versus each filter, the empty state, counter resets, two replicas, scrape
  intervals 5, 10 and 60 s, and ranges of 15 m, 1 h and 6 h. On top of these, the conditions that broke T13 in QA:
  - a series that appears between step boundaries;
  - a scrape job added later whose first sample carries history;
  - balance=false pooled queues;
  - the grid shift of a non-UTC browser;
  - Grafana versions choosing different steps;
  - namespaced job classes.
- **G4. DOM check.** Tile texts, captions, colours and table cells as a user sees them.
- **G5. Coverage gate.** Every panel id and target refId of every render has a spec and at least one PR case in which
  its expectation is non-trivial. A new or changed panel, target or variable turns CI red until T17 covers it.
- **G6. CI gate.** The `dashboard-truth` job runs on changes to collectors, Lua, `src/Grafana/**`, `composer.json` and
  the suite itself, and weekly, within the owner's limited Actions budget.
- **G7. Proven sensitivity.**
  - The suite is red on the T13 round-3 forms of the two confirmed defects and green on the fixed forms.
  - It stays silent on the refuted `${job_class:text}` item, because a false alarm of that kind is impossible by
    construction.

### Non-goals

- **N1. Exporter truth.** T16 owns it. T17 re-asserts T16's model at every recorded scrape (section 7.1) only to tell an
  "exporter mismatch" apart from a dashboard defect.
- **N2. Out of scope.** Performance (WP8C budget, `bin/perf.mjs`), the look and the screenshot review (T13, T15),
  live-update latency (LiveE2eTest, the weekly e2e of T13 and T15).
- **N3. Alert rules.** The rule expressions in `resources/grafana/alerting` are not evaluated. The Alerts tile and #23 are
  checked on synthetic `GRAFANA_ALERTS` series whose shape is calibrated weekly against a real state-history write.
- **N4. No re-implementation.** Grafana's variable interpolation, macro substitution and step computation are never
  re-implemented. The cost design's PHP emulator is rejected, because it is the same class of re-implementation that
  produced the refuted round-3 probe.
- **N5. Grafana versions.** Only 12.4.13 and 13.2.4. A weekly `grafana/grafana:latest` leg is optional and non-blocking
  (owner question 5).
- **N6. Changes to other tasks' code.** T17 never edits `src/**`, `tests/Truth/**` (T16) or T13's tests. Dashboard findings go
  to T13 through the board's QA lines, and questions of truth go to the owner.

---

## 2. Decisions at a glance

| Topic | Decision | Origin |
|---|---|---|
| Queries | Captured from the real frontend (Chromium, Playwright 1.56.1) on both versions. The final PromQL and step come from `meta.executedQueryString` and the frame grid. | fidelity, determinism |
| Data | The real exporter with real workers. In-process scrapes on a virtual clock are ingested into Prometheus v3.15.0 by remote write, with an emulated scrape loop: target labels, `up`, StaleNaN, `le`/`quantile` normalisation, empty labels dropped. | all three |
| Ingest check | Per PR, a short excerpt is really scraped by a parity Prometheus and must store the same samples. The full check runs weekly. | cost and determinism, strengthened |
| Time frame | One fixed past epoch per dataset (2025-03-03 + k·8 days), recorded directly at that epoch, so nothing is rebased. Namespaced clock shims for Horizon and `Illuminate\Bus`. A fake browser clock at the dataset end. | determinism + fidelity |
| Ranges and view | Relative ranges on the fake clock by default, which is what users run. Absolute ranges only for boundary cases. The normal (non-kiosk) view at 1920 and 1366 px, height 5000. Kiosk mode weekly. | fidelity |
| Steps | Never computed. They are read from the frames. Every range target is also replayed browserless at S and at the next coarser Grafana step. | cost |
| Live endpoints | The real app routes are served by a pinned-clock router over the kept Redis world. `horizon-observability:stream --once` pushes to each Grafana. | fidelity, determinism |
| Expectations | From T16's model and the scrape table it verifies, through restated documented semantics, never from the dashboard's PromQL. Counters use honest per-sample-pair semantics. | all three, judges |
| Tolerance | Exact. Measured durations are exact against the recorded values, which were bounds-checked against T16's model. The three `increase()` cells are exact against Prometheus' restated extrapolation, plus an honesty bound (owner question 4). | determinism |
| Deviations | An owner-approved deviation register with exact formulas, and an open-findings register (xfail) that must be empty for `done`. | cost + new |
| Gates | Static: spec, PR case, non-trivial expectation, consumed fields, README sync. Runtime: no vacuous pass. Sensitivity: round-3 overlay and synthetic mutants. | merged |
| CI | One workflow and one job running both versions on one runner: about 12 min per qualifying PR push, about 45 min weekly. | fidelity, determinism |

---

## 3. Architecture and data flow

### Stages

Five stages are orchestrated by `tests/DashboardTruth/bin/run` (PHP CLI, also `composer test:dashboard-truth`). Each
writes artifacts under `build/dashboard-truth/<run>/`, and each stage can be resumed from its predecessors' artifacts.

| Option | Meaning |
|---|---|
| `--from=record\|ingest\|stack\|capture\|assert` | Resume from a stage |
| `--keep` | Leave the stack and Redis worlds running |
| `--set=pr\|full` | Case set |
| `--cases=C04,C24` | Run only these cases |
| `--grafana=12\|13\|both` | Grafana versions |
| `--ci` | CI mode |

```
T16 engine (Scenario, TruthJob, Model, HorizonTruth, Invariants, Samples, tests/Support/{Exposition,HorizonState})
  │
  │ 1 RECORD   Pest, groups dashboard-truth-record + redis, one test per dataset (env DASHBOARD_TRUTH_RECORD=1)
  │            virtual clock at epoch E_k; scrape schedule of every target; L1 check at every scrape
  ▼
datasets/<name>/{recording.ndjson, manifest.json}   Redis world ho_t17_<run>_<name>: + <name>.sqlite (kept)
  │
  │ 2 INGEST   PHP CLI: ScrapeEmulation → RemoteWrite (time-major, epoch order) → one Prometheus
  │            ScrapeParity: a short excerpt really scraped by a parity Prometheus → identical samples
  │
  │ 3 STACK    Prometheus v3.15.0 (tmpfs, remote-write receiver, no scrape jobs, retention 100y)
  │            Grafana 12.4.13 + 13.2.4 (cached plugins, shipped Infinity datasource → router)
  │            router: php -S, real workbench app, active world, Carbon pinned at V_k, request/response log
  │            dashboards imported by API (uid/id changed only); calibration dashboard; live tokens
  ▼
  │ 4 CAPTURE  Node + Playwright: per case × version, real page, fake clock, rows expanded,
  │            stream --once between subscription and reading for stream cases
  ▼
observations/<grafana>/<case>.json  (requests, responses, executedQueryString, resource calls, WebSocket frames,
                                      scene variables and panels incl. clones and transformed data, DOM, router log)
  │
  │ 5 ASSERT   Pest, group dashboard-truth, one test per case × version (env DASHBOARD_TRUTH_STACK=1)
  │            coarser-step replays → expectation engine → comparator (frame, transformed, DOM) → gates
  ▼
report.{md,json}: case, version, panel/clone, refId, level, expected, actual, executed PromQL and step,
                  category (dashboard defect | exporter mismatch | infrastructure | case setup), replay command,
                  element screenshot of the failing panel
```

### Rules that hold across stages

- **Separate processes.** Record and assert run in separate Pest processes. Capture is Node. Everything in between is
  plain PHP or shell.
- **Inputs of the assert stage.** It reads only artifacts, plus browserless replays against the still-running Grafanas.
  It never queries Prometheus directly, so every evaluated value went through Grafana.
- **Failure categories.**
  - `exporter mismatch`: an L1 failure.
  - `dashboard defect`: an L2 failure.
  - `infrastructure`: stack, timeout, attribution or an unanswered target.
  - `case setup`: a URL value that is not an option, or a leftover `$`.

  Only `dashboard defect` is ever a finding against T13.
- **Kept Redis worlds.** A world's Redis keys and its SQLite database stay alive from recording to capture.
  `Record/WorldKeeper` deletes them at the end of the run, from a shell trap, so this also happens on failure. `--keep`
  keeps them for local re-capture.

---

## 4. Clocks and the time frame

### Epochs

- `E_k = 1740960000 + k·691200` (2025-03-03T00:00:00Z + k × 8 days).
  - Dataset k covers [E_k, V_k], with V_k = E_k + its span (2 h or 7 h).
  - V_k is hour-aligned, so every step Grafana can choose for ranges up to 6 h (15 s, 20 s, 30 s, 1 m, 2 m, 5 m, 10 m,
    15 m…) has the same phase against the events in every run.
- Pages are byte-identical across runs, except the measured durations.
- The 8-day spacing exceeds the dashboard's longest lookback: `[7d:5m]` in `horizon_url`, `tts_url` and `api_path`, and `[1d]` in
  `supervisor` and #24 M. Datasets therefore never see each other's series.
- All datasets live in one Prometheus. They are written in epoch order (verified: X3).

### Recorder clock

- `Scenario::start($test)`, then immediately `advance(E_k − now)`.
  - The offset is negative. `advance()` is `CarbonImmutable::addMicroseconds`, so no T16 change is needed, and the
    `Scenario::startAt()` that the fidelity design proposed is dropped.
  - `Scenario::freeze()` pins Carbon, CarbonImmutable and the storage `ServerClock`, so T16's 90 s new-series hold runs on
    the virtual clock.
- Already virtual, no change needed:
  - Laravel `createdAt`, `availableAt`, delayed migration and `retry_after`;
  - the package itself (`now()`, `ScrapeContext`, `BatchReader`, `LiveSnapshotBuilder`, `JobListService`);
  - the `HorizonState` heartbeats (Carbon).

### Clock shims

`Record/horizon-clock-shim.php` holds three namespaced functions. Each reads Carbon's test now, and passes through to the
global function when no test now is set.

| Shim | Returns | Effect |
|---|---|---|
| `Laravel\Horizon\microtime()` | The frozen virtual time | `JobPayload::prepare()` `pushedAt` = the virtual dispatch time. Horizon's own `Stopwatch` reads 0; the package never uses it. |
| `Laravel\Horizon\Repositories\microtime()` | Virtual time + real time since Carbon's test now last changed | `RedisJobRepository` reserved/completed/failed stamps and index scores are virtual; job runtimes stay real, so the job-list runtime column is not 0. |
| `Illuminate\Bus\time()` | `floor(virtual)` | `DatabaseBatchRepository` `created_at`, `finished_at`, `cancelled_at`. This replaces the determinism design's BatchClock re-clocking. |

**Load order.** `RecordTest.php` requires the shim at file load, and only when `DASHBOARD_TRUTH_RECORD=1`, so normal Pest
runs never define it. PHP caches how it resolves an unqualified call. Verified (X1): a `JobPayload` call made before the
shim was loaded kept the real `microtime` for the rest of the process.

**Guards in the recorder.**
- Every TruthJob payload's `pushedAt` equals its virtual dispatch time.
- Every batch row's timestamps are virtual.
- Every Horizon job-record stamp lies in [virtual, virtual + 5 s].
- A violation fails with a pointer to the shim. That catches a Horizon or Laravel update that switches to `\microtime`
  or Carbon.

**Laravel legs.** The approach works on every Laravel leg: readiness = max(pushedAt, createdAt + delay) is virtual
whether or not the payload carries `createdAt` and `delay`. The determinism design's "delay ≥ 1 for every dispatch" and
its "Laravel 13 only" restriction are not needed.

### Other clocks

- **Browser.** `page.clock.setFixedTime(V_k)`, or the case's own `now`.
  - Timers keep running, and `refresh=1h` in the URL stops auto-refresh (verified: X8). The refresh-cycle case is the only
    exception.
  - Verified on both versions: `now-6h` resolves to exactly V_k − 6 h on a 2025 clock (X4).
- **Router and `stream --once`.** Carbon is pinned at V_k.
- **Real time that remains:**
  - attempt durations (`hrtime`), which are measured, bounds-checked by L1 and then exact;
  - Redis key TTLs, frozen at the end of recording (section 6.2);
  - Horizon job runtimes, real by design of the second shim.

---

## 5. Interpolation fidelity

**Rule:** T17 contains no code that substitutes a dashboard variable, a Grafana macro or a step. Each layer is evaluated
by the component that does it in production, on both pinned versions.

1. **Dashboard variables** are interpolated by the Grafana frontend in Chromium.
   - Cases set filters as `var-*` URL values, which is what links and bookmarks do. One PR case selects the Job filter in
     the picker by its visible text, to cover the UI path.
   - The resolved `value`, `text` and `options` of every variable are read back from the scene graph
     (`window.__grafanaSceneContext.state.$variables`, verified on both versions: X10).
   - Verified by fidelity and cost: with `var-job_class=App%5CJobs%5CSendEmail`, `${job_class:text}` is sent as `SendEmail`
     (the option text from the namespace-stripping regex) and `$job_class` as the regex-escaped value. The refuted round-3
     probe substituted the full class; a capture cannot.
2. **Guards against the refuted class of error:**
   - Every `var-*` URL value must resolve to an option value of its variable, as the scene shows it. Otherwise the case
     fails as `case setup`, never as a finding. A case that deliberately needs a non-option value declares `custom: true`
     and states Grafana's documented custom-value behaviour as its expectation.
   - No `$` reference may remain in a captured expression except these:
     - the backend macros (`$__interval`, `$__interval_ms`, `$__range`, `$__range_s`, `$__rate_interval`);
     - `$1`…`$9` in `label_replace` replacements;
     - in Infinity bodies, the JSONata functions of an allow-list computed from the render (`$count`, `$merge`,
       `$split`, …).
   - `IndependenceTest` forbids the harness from using `tests/Grafana/Support/Promtool`, whose `interpolate()` maps
     `${x:text}` to `All` and every variable to `.*`.
3. **Macros and the step** belong to Grafana's backend.
   - Verified: `$__interval`, `$__interval_ms` and `$__range` reach `/api/ds/query` unsubstituted, and `${__interval_ms}`
     arrives as `$__interval_ms`.
   - Each Prometheus frame carries `meta.executedQueryString` (`Expr: …\nStep: 2m0s`). Range frames carry the aligned grid:
     the first point is aligned down, at or before `from`.
   - Expected values are evaluated on that grid with that step. Grafana's choices are inputs, never re-derived.
4. **The step depends on the version and the width.**
   - In the normal view at 1920 px and 6 h, the two versions send identical expressions but different `maxDataPoints`:
     #2 and #8 get 240 on 13.2.4 (2 m step) and 258 on 12.4.13 (1 m).
   - In kiosk mode both get 304/308 and 1 m (X5). The judges' conflicting observations are both true; the difference is
     the side menu's width.
   - Expectations are therefore per version. PR cases use the normal view at 1920 and 1366 px; kiosk, 2560 and 1024 px run
     weekly.
5. **Time zone.** The dashboard time zone is `browser`.
   - A relative range sends `utcOffsetSec` = the zone offset (Asia/Kathmandu: 20700) and shifts the 2 m grid by 60 s. The
     last point is then 60 s before `to` (X6).
   - An absolute range sends 0.
   - The T13 `perStepTotal()` docblock relies on epoch-aligned subquery points, so this is a real condition. PR cases use
     UTC and Asia/Kathmandu; America/St_Johns runs weekly.
6. **Coarser-step replays** are the only requests T17 itself sends.
   - The captured body is re-posted to `/api/ds/query` with only `maxDataPoints` and `intervalMs` changed: once for step S
     (`intervalMs` = S·1000, `maxDataPoints` = min(11000, range/S + 1)) and once for the next coarser Grafana step.
   - The frontend-interpolated variables stay as captured, and the backend substitutes the macros again for the new step.
   - Verified (X7): an unchanged replay returns byte-identical frames. `maxDataPoints` 60 (or `intervalMs` 300000) turns a
     2 m target into 5 m with 73 points and the same tile sum.
   - Expectations are computed on the replay's own grid.
7. **Mixed panels, `-- Dashboard --`, Infinity and Live.**
   - Mixed panels send one request per datasource type; both are captured under the same X-Panel-Id.
   - #5 reads #24 through `-- Dashboard --` and sends no request. Its truth is #24's frames, plus #5's transformed data per
     clone, plus its DOM.
   - Infinity URLs (`${api_path}/live?…`, `${api_path}/jobs/<list>?from=${__from}&to=${__to}&…`) are interpolated by the
     frontend. They are recorded twice: in the `/api/ds/query` body and in the router's log of what it actually received.
   - Live tiles are read from the received WebSocket frames, the scene and the DOM.
8. **Repeat clones** send hashed X-Panel-Ids, which no common hash reproduces.
   - Attribution: VizPanel key (`panel-7-clone-1`) → `$data…request.panelId` → scoped `$variables` (queue `mail`).
   - This is cross-checked against the DOM's `data-viz-panel-id` (`mail$panel-7`) and against the scoped literal in the
     executed query.
   - An unattributed or contradictory request is an `infrastructure` failure.
9. **Refresh.** Only cycle 0, the first load, is compared. The weekly refresh-cycle case keeps the dashboard's own
   refresh and asserts that cycles 0 and 2 are equal. With a relative range ending at now, incremental querying is active
   (ADDENDUM R2.5).

---

## 6. Data strategy

### 6.1 Live scrape, backfill or remote write

| Option | Verdict |
|---|---|
| Live scraping of the dev stack | Rejected. Real time, so a 6-hour range takes 6 hours; random scrape phases; Redis TTL races. |
| `promtool tsdb create-blocks-from openmetrics` | Rejected. OpenMetrics cannot express a staleness marker (verified by three designers): a vanished gauge stays visible for 5 minutes, e.g. reserved jobs of a class, closed batches, terminated supervisors, a series after a reset or a failed scrape. That is wrong data, giving false alarms or masking defects. |
| Remote write with an emulated scrape loop | **Chosen.** Deterministic timestamps and explicit StaleNaN. It bypasses Prometheus' parser and scrape loop, which is why ScrapeParity runs on every PR (section 6.3). |

### 6.2 Recorder

**Test and world.**
- One Pest test per dataset: `RecordTest`, groups `dashboard-truth-record` and `redis`, test case `Record/RecordingCase`.
- `RecordingCase` is a `TestCase` subclass in T17's own directory, applied with `uses()` in the file. It points
  `REDIS_PREFIX`/`HORIZON_PREFIX` at the world prefix `ho_t17_<run>_<dataset>:` before the app boots, not at `hot_<pid>:`,
  so `TestCase::tearDown()` does not delete the world.
- It uses a file SQLite database per world, because batches must outlive the test (the `testing` database is in memory),
  plus the world token and T16's buckets.

**Timeline and schedule.**
- Events are offsets from E_k.
- `ScrapeSchedule` lists the targets: job, instance, interval S, phase, seeded LCG jitter, failed slots and active
  interval. The recorder visits the union of all scrape times in order.
- Union scraping is sound because the exposition at t is a pure function of the state at t:
  - the hold release is decided at the scrape on the pinned Redis clock;
  - extremes and ring buckets are read by bucket name.

  The only exception is `exporter_collector_duration_seconds` (real time), which no panel reads.
- The T16 hold budget requires a scraper's successful scrapes to be less than 90 s apart (T16 README). Failed slots and
  outages are therefore placed outside every hold, e.g. the failed slot at S = 60.

**At each slot.**
1. `Record/HorizonWorld` writes the Horizon master and supervisor records of the topology at that time through
   `tests/Support/HorizonState`: process counts, pause, stop, terminated supervisor.
2. The real in-process scrape runs through T16's `Scenario::scrape()`: real endpoint, token, Invariants and the decrease
   check.
3. The recorder stores `{target, t_ms, ok, exposition}`.

A failed slot stores `ok: false` and no exposition. `up` and the stale markers come from the ingest.

**L1 check at every scrape** (section 7.1). A mismatch fails as `exporter mismatch`.

**Model extensions** live in T17's own `Record/`; `tests/Truth/**` stays unchanged:
- `ResetEpochs`: after a simulated reset (the `ResetTruthTest` mechanism, a run_id change), counters restart held at 0.
- `ReservedAtEnd`: a forked worker is SIGKILLed while it runs a job, so the job stays in the reserved set through the
  capture. Model: `reserve()` without `finish()`.
- `HorizonWorld`: the topology timeline.
- `DelayedWindow`: `LiveStreamer::tick()` under `Http::fake()` every 5 virtual seconds over the last 15 minutes. It builds
  `live:window:delayed`; afterwards the recorder deletes `live:leader`.
- `AlertWindows`: the declared rule states.
- Attempt visibility times: the attempt time, or the first scrape after the hold released it.

**At the end of a dataset.**
- `TtlFreeze` PERSISTs every key with a TTL under the world prefix and records the remaining TTL in the manifest:
  - Horizon master and supervisor records (15/30 s), whose zset scores are V_k;
  - ring buckets (180 s);
  - extremes (300 s);
  - Horizon job records.

  With that, the Redis state stays the state at V_k while the cases run.
- The manifest is written: prefix, SQLite path, token, V_k, targets, topology, events, attempt log, alert windows,
  renders. `recording.ndjson` is kept as a debug artifact.

### 6.3 Ingest and ScrapeParity

**`ScrapeEmulation`** turns each target's recorded scrapes into what Prometheus v3.15.0 stores. The rules were verified by
the designers and are reproduced as WP3 unit tests:
1. Parse the text exposition with T16's `Exposition` parser (handles `+Inf`, `-Inf` and `NaN`).
2. Assert that it carries no `job`/`instance` labels (with `honor_labels: false`, Prometheus would rename them to
   `exported_*`) and no duplicate series.
3. Add `job` and `instance` from the target.
4. Drop empty label values.
5. Normalise `le` and `quantile` to Prometheus 3 float text (`1` → `1.0`, `10` → `10.0`, `0.25`, `+Inf`, `1e+06`).
   Remote write does not do this itself, while a scrape and promtool do.
6. Keep `NaN` and `±Inf` values. Sample timestamps are the slot time in ms.
7. Write `up` = 1 or 0 per slot. The `scrape_*` series are not emulated; no panel reads them.
8. Write StaleNaN (`0x7ff0000000000002`) at the first successful scrape that lacks a series, for every series of the
   previous successful scrape at a failed slot, and at the first slot after a target's active interval ends.

**`RemoteWrite`.**
- Protobuf WriteRequest with all-literal snappy, about 100 lines of PHP, no extension.
- Time-major batches of 60 timestamps. Several recordings of one dataset, such as two apps, are merged time-major.
- Datasets are written in epoch order into one empty Prometheus with tmpfs, `--web.enable-remote-write-receiver`, no
  scrape jobs and `--storage.tsdb.retention.time=100y`. No restart and no out-of-order window are needed (X3).

**`AlertHistory`** writes the synthetic `GRAFANA_ALERTS` series from `AlertWindows`, in the label shape of Grafana's
Prometheus state-history writer. Weekly, `AlertShape` makes a real Grafana with a `for: 0s` test rule write state history
into the parity Prometheus and compares the label sets and cadence.

**`ScrapeParity`** proves the emulation equals a real scrape on the PR's actual exposition, including new families and
label values.
- A replay server (`Parity/replay.php` under `php -S`) serves the recorded expositions of an excerpt in order, and 500 for
  failed slots.
- A parity Prometheus scrapes it at 1 s. The excerpt is about 24 slots of `core`, chosen around a vanishing series, a
  failed slot, a birth and backslash `job_class` values.
- Two comparisons:
  - the raw samples per series: values and label sets, with timestamps mapped to the parity's real scrape times read
    from its `up` samples;
  - the instant visibility right after every scrape, so stale markers sit at the same scrape.
- About 30 s, run while the Grafanas start. Weekly: 300 slots and every family.

### 6.4 Datasets

Each row is one epoch k. Every target scrapes at S with the given phase φ, seeded jitter j and failed slots f.

| k | Dataset | Span | Targets | Content |
|---|---|---|---|---|
| 0 | `core` | 7 h | `app` / `app:8080`, S 10 s, φ 3.2 s, j ±0.4 s, f 2 (outside holds) | See the `core` list below the table. |
| 1 | `s5` | 2 h | `app`, S 5 s, φ 1.7 s, j ±0.4 s | The `core` mix, compressed. Renders made with `--scrape-interval=5s`. |
| 2 | `s60` | 7 h | `app`, S 60 s, φ 37 s, j ±7 s (twice the real latency, inside the R3.4 budget), f 1 (outside holds) | The `core` mix. A held birth 10 s after a 2-minute boundary. Extremes around 60 s scrapes (R3.4). Renders made with `--scrape-interval=60s`. |
| 3 | `replicas` | 2 h | `rep` / a (φ 0 s) and `rep` / b (φ 4.4 s; b fails 2 slots, so `up` 0 and stale markers), S 10; `rep-single` / a (the same scrapes of a only) | A `core`-like mix. Per-pod replicas of one app on one Redis; T16 proved that replicas with different clocks agree. |
| 4 | `multi-job` | 7 h | `shop` (app A, S 10); `shop-horizon` (app A's scrapes again, from V − 25 min + 5 s); `billing` (app B, S 10) | See the `multi-job` list below the table. |
| 5 | `quiet` | 7 h | `app`, S 10 | Horizon with supervisors and configured queues and no job ever. Paused 10 min at V − 3 h; no master for 5 min at V − 2 h. Cases also use a range before E_5 (no metrics). |
| – | `slice` (WP1) | 1 h 40 min | `app`, S 10 | One supervisor, Truth\Invoice completed and failed, a held birth 5 s after a 2-minute boundary. |

`core` contains:
- **Supervisors:** `default` (queue default, auto, max 3), `slow` (slow and reports, max 4) and `pool` (balance=false
  mail,sms, max 2).
- **Classes:** Truth\Invoice, Truth\Report, Truth\Notice, App\Jobs\SendEmail, App\Jobs\Sync and App\Mail\Sync (the same
  display text "Sync"), and the batch classes.
- **Load:** 6 h of bursts, then the full T16 outcome mix in the last hour: completed, failed, released, retried, and
  timed_out through a fork.
- **Queue state:** delayed jobs, due and not due; a reserved job at V (`ReservedAtEnd`); oldest wait 150 s on `slow`
  against a 60 s limit (red, minute caption).
- **Held births:**
  - Truth\BornH at an hour boundary + 5 s, inside the 6 h range;
  - Truth\Born2 at a 2-minute boundary + 10 s, the defect-2 condition. This corrects fidelity's example of 600·k − 277 s:
    83 s after a boundary, the hold spans the next boundary, so the old form loses nothing at 2 m;
  - control Truth\Born83 at a 2-minute boundary + 83 s.
- **Batch kinds:** import (finished), export (with failures), cleanup (cancelled) and one unnamed.
- **Horizon:** paused for 2 min at V − 4 h; no master for 3 min at V − 2 h.
- **Alert windows:** long-wait firing V − 10 m … V − 2 m; failures pending V − 5 m … V.
- **The delayed window** for stream.

`multi-job` contains:
- **Shared names:** both apps use queue `default` and App\Jobs\SendEmail.
- **`shop-horizon`:** a dedicated scrape job added later, whose first sample carries the history (defect 1 and the
  first-partial-step loss).
- **`billing`:** its storage is reset at V − 40 min (run_id change). It has a 12-minute scrape outage at V − 55 … V − 43,
  before the reset's hold and longer than LOOKBACK.
- **Held births** at a 2-minute boundary + 10 s in `shop`.

**Cases per dataset.** The cases of a dataset pin `var-prometheus_job` wherever the dataset holds more than one job (`replicas`), so
"All" never mixes jobs by accident. In `multi-job`, "All jobs" is the case itself.

### 6.5 App endpoints: router and stream

**`Server/router.php`** runs under `php -S 127.0.0.1:$APP_PORT` with `PHP_CLI_SERVER_WORKERS=4`.
- Per request it reads the active world, which `bin/run` switches between datasets, and boots the workbench app through
  `Server/PinnedApp`:
  - `TESTBENCH_WORKING_PATH` defined;
  - the world's Redis prefix, SQLite database and token;
  - `live.enabled`, `live.cache_ttl = 0`;
  - Carbon pinned at V_k.
- It serves the real package routes `{base}/live` and `{base}/jobs/{list}` with the default `horizon.path`, so `api_path`
  is the default `/horizon/observability`.
- It logs every request and response: path, query, whether a bearer token was sent, status and body hash.
- In `multi-job`, the router serves app A (`shop`). That is the documented limit of one Infinity datasource per dashboard
  (Arch Q5.2 risks): the live tiles and job lists show the datasource's app, and the expectations say so.

**`Server/stream-once.php`** boots the same pinned app and runs `horizon-observability:stream --once` once per Grafana.
- Each Grafana gets its own URL and its own service-account token (Editor, from `docker/grafana/bootstrap-live.sh`).
- The observer calls it after the stream panels have subscribed, and weekly also before.
- Verified on both versions with a 2025 fake clock (X9): a push before the subscription shows the cached frame, and a
  push after it updates the tiles.
- The panel `buffer: 500` is a window relative to the newest row: a malformed push with rows more than 500 ms apart
  dropped the supervisor rows. The pushes therefore always come from the real `LineProtocol`.

---

## 7. Expected-value model

### 7.1 Two layers

**L1: exported series**, asserted by the recorder at every scrape:
- jobs_total, the duration and wait histograms (buckets, counts), extremes and queue state (pending, delayed, due, oldest,
  pending and reserved per class) equal T16's `Model`, with the held new series restated;
- sums and extremes lie within T16's [planned, planned + 0.2 s per attempt];
- the horizon families equal `HorizonTruth`;
- the batch families equal `Bus::findBatch` and the batch-link truth;
- after a reset, counters equal T17's `ResetEpochs`.

After L1, the verified scrape table equals the ground truth plus the documented sampling (scrape lag, hold, gauges sampled
every S, extremes over 150 s). Panel expectations may read it.

**L2: panel semantics** (`Expect/Core`, plus one spec class per panel).
- Restated from these sources only:
  - the T13 panel and query docblocks;
  - ARCHITECTURE Q5;
  - ADDENDUM R2.4 O1–O9, R3.3, R3.4, R4.1 and R4.4.
- Never from `Promql.php` or any PromQL. Guards:
  - `IndependenceTest` forbids `Expect/**` from using `DF\HorizonObservability\Grafana\*` and `Live\Captions`;
  - a grep guard rejects PromQL tokens in `Expect/**`;
  - `Metrics\Metric` (names and labels) is allowed.
- Parameters come from the observation: from/to, each refId's grid and step, the resolved variables, `busy_window`,
  `scrape_interval` and the clone's scoped values.

### 7.2 Core rules (`Expect/Core`)

**Stored samples.** `SeriesStore` holds the samples per series after `ScrapeEmulation`: exactly what Prometheus holds, stale
markers included.

**Visibility:**
- instant at t: the newest sample in (t − 5 m, t], unless a stale marker follows it;
- range [w] at t: the samples in (t − w, t], which is Prometheus 3's left-open window, excluding stale markers;
- subquery [r:s] at t: points at epoch multiples of s in (t − r, t];
- `@` and `offset` as documented;
- replica dedupe: `max by (job, <family labels>)` over instances (Arch Q1.5).

**Counter period count C(selection, from, to)** for jobs_total and the histogram series. It is the honest per-sample-pair
rule:
- Per series, after dedupe, the baseline is the last sample at or before `from` within LOOKBACK = max(10 m, 10 × S),
  with S taken from the scene's `scrape_interval`.
- Without one, the baseline is the first sample in (from, to]. That sample is never counted: it is history, or the hold's
  0.
- Then the sum over consecutive pairs (a, b) up to the last sample ≤ `to` of `b − a` if b ≥ a, else `b` (a reset counts
  its post-reset value, for that pair only).
- Pairs across a scrape gap count their increment, because the exporter counters are exact. The documented
  exception is ADDENDUM R3.1/R2.4 O1: a gap longer than LOOKBACK right before `from` loses its attempts (deviation D1).
- Consequences:
  - a new series held at 0 counts all its attempts;
  - a series first seen with history (a late scrape job, a replica added later) counts only its increase.

**Cross-check against the ground truth.** C equals the number of model attempts in the selection whose visibility time lies in
(baseline time, last sample ≤ `to`], when no registered deviation applies. This ties L2 to T16's attempt log.

**Per-step points**, on the captured grid, with each family's documented semantics:
- **Tiles** (#4 A, #8 A; `Attempts::perStepTotal()` docblock): point t counts (P, P + s] with P = floor(t/s)·s.
  - The step that holds `from` starts at `from`.
  - The last point, whose step reaches `to`, ends at `to`.
  - Points whose step ends before `from` are dropped.
- **Graphs** (#14 A/B; `perStep()` docblock): point t counts (t, t + s] ∩ (from, to].
- Both must add up to C(from, to) at the captured step and at both replay steps. This is a gate of its own, and it is the
  rule that makes defect 2 visible.

**Gauges.** A gauge tile fed by a range query shows the last grid point (stat `lastNotNull`), pending owner question 3.
Instant targets are evaluated at `to`. Per-step gauge series follow their docblock: the value at t, the peak in the step
(`max_over_time`), or the worst status in the step.

**Busy window.** Utilisation = the per-sample average of the reserved jobs in the supervisor's queues over the samples of
`[busy_window]`, divided by its processes (ADDENDUM R3.3), with `busy_window` taken from the scene. Zone = (round(100u) ≥ 70) +
(round(100u) ≥ 90); zone 2 while Horizon is not running.

**Pools.** Each queue maps to its pool and supervisor through `horizon_supervisor_queues` (T16 README §2).

**Histograms.** `histogram_quantile` interpolates linearly inside the bucket, with Prometheus' documented edge rules: lower
bound 0, `+Inf`, monotonicity fix, NaN for count 0.

**Extrapolation.** Prometheus' documented `extrapolatedRate`, restated on the known sample times:
- 1.1 × the average sample interval threshold;
- the half-interval extension;
- the zero clamp for counters.

It is used only for the registered `increase()` targets (section 8).

**Formats and colours.**
- Captions restated from the docblocks and R4.4: `Oldest: N s · Q` with N = round(age) under 120 s, else
  `round(age/60) min · Q`; `—`; `RPM: n`; `Success: x%` truncated to tenths; `name · max N`; `Stable in Nm`; `Uptime`
  tenths; the `level|age / limit` cells.
- The level-threshold encoding 3K/K/0 (Tiles docblock) and zone n + zone/64.
- Grafana's display formatting for the units and decimals each panel declares: thousands separators, decimals,
  durations. A DOM spec pins its panel's unit and decimals statically.
- Colour names (green, yellow, red, text, transparent) map to RGB through the per-version calibration (section 10).

### 7.3 Expectations per panel family

These are the documented meanings the specs restate (WP6 for tiles and live, WP7 for tables and lists).

| Panel / refIds | Modes | Expected value | Model parts |
|---|---|---|---|
| #1 Horizon A, B | all | See the #1 list below the table. | HorizonWorld timeline, Subquery |
| #3 Supervisor Processes | off A, B; poll S; stream A | See the #3 list below the table. | Topology, `reservedByClass` timeline, LiveExpect |
| #2 Pending | off A, B, C; poll A, L, C; stream A | See the #2 list below the table. | Queue-state timeline (Model pending, due, oldest, per class), thresholds, LiveExpect |
| #8 Completed | A, B (off); poll/stream C | A: completed per step (tile semantics); the number is Σ points = C. B (off): `RPM: n`, with n = round(completed in (to − max(5 m, 5 × S), to] per minute). C: the ring caption, completed in [5(c − 12), 5c) with c = ⌊V/5⌋, and the class RPM under a Job/Batch filter (T13 round-2 fix). | Counters, RingBuckets from `Model::attempts()` |
| #9 Delayed | off A; poll A, L; stream A | Σ(delayed − due) per step, last point; `—` under a Job filter. Poll: live delayed at V. Stream: the window points 5 s apart plus the current one; last point. | Queue-state timeline, DelayedWindow |
| #4 Failed A, B, C | all | A: failed + timed_out per step, number Σ. B: `Success: x%` = floor(1000·completed/(completed + failed + timed_out))/10 over the period, `—` without finished attempts. C: colour by failed/clamp_min(finished, 100), yellow ≥ 1 %, red ≥ 5 %; the static thresholds without attempt data. | Counters |
| #21 Alerts A, C, B; #23 A | all (the stream selector adds `live`) | A: non-normal dashboard rules per step. C: level 2 firing, 1 pending or inside the stabilisation period, else 0. B: `Firing`, `Pending`, `Stable in ⌈…⌉m` or `Stable`. #23 A: rules firing in the last 90 s (hidden; frame level). | AlertWindows, AlertsExpect |
| #24 M, A, B, D, F, G, K, C, Z and #5 per clone | all | See the #24 and #5 list below the table. | Topology, pools, Histograms, Extrapolation, Counters |
| #17 Batches A–H, Z | all | Per kind open in the range (created ≤ to and not closed before from): running, jobs pending and failed of running batches, attempts of the kind's classes over the range (`batch_job_classes`, O2), workers, oldest age, RPM, as the Batches docblock states; Z placeholder. | BatchExpect (DB through the Bus shim), Counters over class sets |
| #16 per `batch_title` clone A–F, Z | all | Per recent batch: progress, total/pending/failed, created, closed, status; Z placeholder. | BatchExpect |
| #7 per queue clone A, B, E | all | A: pending per class per step (peak in the step; the legend label `job` is the short class name, so two "Sync" series). B: total. E: the overload line from the supervisors' processes. | Per-class timeline (`pendingByClass`, `reservedByClass` per scrape) |
| #14 per queue clone A, B | all | A: completed per class per step (graph semantics); B: total. | Counters per class |
| #6 A, E, F, G, I, J, Z, B | all | See the #6 list below the table. | Histograms, Extrapolation, extremes timeline, Counters |
| #18–#22 job lists | all | Rows of Horizon's records in [from, to] per list, in the filter scope, limit 500, ordered as JobRows defines. Columns per JobRows; the short class name linked to `horizon_url` + `horizon_path`; exception first line; attempts. Runtime within T16's duration bound (the drift shim makes it real); the `—` row when empty. The from/to in the router log equal the page range. | JobListExpect: an independent read of Horizon's repositories (as T16 cross-checks), joined with model outcomes |
| Variables | all | Options, texts and values. See the Variables list below the table. | VariablesExpect |

#1 Horizon:
- A: per step, the worst status in the step (−1 no master, 0 paused, 1 running).
- B: `Uptime` = floor(1000 × the share of `[$__range:$__interval]` subquery points with status 1)/10 as text.
- Colour by the current status and the uptime rule of the docblock.
- `No metrics` without data.

#3 Supervisor Processes:
- off A: processes per supervisor per step. With a Job or Batch filter: the workers busy with those classes in the
  supervisor's queues. Caption `name · max N`, one value per supervisor sorted by display.
- off B: zone colour at `to`.
- poll S: the `tiles[]` rows with `tile=supervisor` from `/live` at V (R4.4, TILE_ROWS).
- stream A: the same rows from the pushed frame, with −1 shown as `—`.

#2 Pending:
- off A: pending (LLEN + due) per step; with a Job or Batch filter, the scanned pending per class.
- off B: the worst queue by age/limit at `to`, as `Oldest: …`; `—` under a Job or Batch filter or when nothing waits.
- off C: level (r > 1) + (r > 2).
- poll: the live value at V merged over the sparkline; null under a filter, which falls back to Prometheus' last point.
  The caption and colour are live.
- stream: the TILE_ROWS pending row.

#24 and #5:
- M: (queue, supervisor) rows of the queues seen with a supervisor within 1 d, within `$queue`. A balance=false pool
  contributes every one of its queues.
- A: utilisation text and zone.
- B: pending per queue.
- D: processes per queue (0 while Horizon is not running).
- F: `level|N s / L s`.
- G: delayed − due.
- K: wait p95 over the range (owner question 4).
- C: attempts by (queue, status) over the range.
- Z: the `—` row.
- #5 clone: the rows of its supervisor after the merge, filterByValue, organize and calculateField steps (Failed =
  failed + timed_out; Success rate). Asserted as DOM cells with their colours.

#6:
- A: pending per class.
- E: reserved per class.
- F: average duration = increase of the sum / increase of the count.
- G: p95 duration.
- I/J: min/max over the range of the extremes gauges, which include attempts up to 150 s before `from` (R3.4).
- B: attempts by (queue, class, status) over the range, rounded.
- Z: the `—` row.
- Row presence: a series with at least 2 samples in the range (`increase()`).

Variables:
- `prometheus_job`: jobs with `horizon_status` in the range.
- `supervisor`: supervisors seen in 1 d.
- `queue`: queues by supervisor.
- `job_class`: value = the class, text = the namespace-stripped display, so two options read "Sync".
- `batch_kind`: kinds open in the range.
- `supervisor_title` and `batch_title`: the display strings.
- `horizon_url`, `tts_url`, `api_path`, `scrape_interval`, `busy_window`.
- The clone count and titles of every repeat.

### 7.4 Self-tests (WP5, no Docker)

- Every core rule is proven against Prometheus with `promtool test rules` on small reference expressions, never the
  dashboard's own: lookback, left-open ranges, stale markers, subquery alignment, `@`/`offset`, dedupe and resets.
- `Extrapolation` and `Quantile` equal promtool's on at least 500 random sample sets.
- The per-pair counter rule equals the model's attempt log on T16-style timelines.
- The defect-2 synthetic case is computed exactly. Fidelity's capture showed the round-3 tile at 2,148 against a truth of
  2,160.

---

## 8. Tolerance policy, deviations, open findings

### Tolerance

- **Exact:** every count, gauge, level, colour name, timestamp, grid point, label set, caption, formatted cell and row set.
  Floats from the same arithmetic must agree to 1e-9 relative. Frames compare order-insensitively (series order varies
  between loads, verified by determinism); table rows compare as a multiset unless the panel declares `sortBy`.
- **Measured durations** (duration sums, averages, min/max: #6 I/J, the job-list runtime) are exact against the recorded
  samples, which L1 bounds-checked against the model's [planned, planned + 0.2 s per attempt]. A DOM cell must be the
  formatted form of its frame value.
- **`increase()` value cells:** #6 F (average duration), #6 G (p95 duration) and #24 K (wait p95), all `[$__range]`. They
  are compared exactly against Prometheus' documented extrapolation, restated on the stored samples. A separate honesty
  check asserts that |extrapolated − semantic truth| stays within the documented bound: the extrapolation bound for the
  average; for the p95, the bucket that holds the true rank. Both are listed in the README as approximate by design,
  pending owner question 4. The cost design's claim that the dashboard uses no `increase()` was wrong: the round-4 render
  has 28 occurrences.
- **`increase()` row presence** (#6 A/B/E/Z) is modelled exactly: at least 2 samples in the range.
- Nothing else gets a tolerance. Every tolerance is declared in its spec and listed in the README tolerance table, and the
  coverage gate rejects a tolerance that is not listed.

### Deviation register (`Expect/Deviations.php`)

An entry is an owner-approved, documented difference from the honest value. It carries:
- (panel, refId, condition);
- the exact formula of the deviated value;
- its provenance (decision, ADDENDUM section or docblock) and the owner's approval date;
- the case that exercises it.

The comparator then expects the deviated value exactly and reports the honesty gap in the report. Entries:
- **D1 (pre-approved, ADDENDUM R3.1/R2.4 O1):** a Prometheus gap longer than LOOKBACK right before `from` loses its
  attempts.
- **Candidates, pending owner questions 1–3:**
  - D-R1: tables (`delta()`) count only the post-reset value after a reset;
  - D-R2: graphs and tiles count 0 for the step that holds a reset;
  - D-G: a gap inside the range longer than LOOKBACK (tiles) or 5 m (graphs) loses that gap's attempts;
  - D-L: range-fed gauge tiles show the last grid point.
- The hold edge (a range starting inside a hold counts pre-range attempts, one ending inside misses them) is L1 semantics
  documented by T16, not a dashboard deviation. The per-pair rule on samples produces it by itself.

### Open-findings register (`Cases/OpenFindings.php`)

- A known dashboard defect reported to T13 is an xfail: (case, version, panel, refId) → finding id.
- A run fails on any failure that is not registered, and on a registered xfail that passes, which forces clean-up.
- The register must be empty, with every entry fixed or converted into an approved deviation, before T17 moves to
  `testing`.

---

## 9. Case matrix

### How it is built

- **One case = one page load:** (dataset, render, range, filters, time zone, width, view). Every target, variable and DOM
  element on that page is asserted from that one capture. A case therefore never lists values or panels.
- **Fixed multipliers:**
  - both Grafana versions on every case (×2), which also yields two steps per panel for free;
  - both collapsed rows are always expanded;
  - targets whose JSON is identical across renders (hashed) are covered once. Mode-specific targets (#2, #3, #8 B/C,
    #9, the #21/#23 selector in stream) need their mode.
- **The datasets carry the data conditions:** S, replicas, late job, reset, outage, births, empty, pooled queues. The
  views form a covering set over each dataset group.

### PR set: 31 page loads per version, 62 observations

Ranges are relative on the fake clock unless marked "abs". The view is normal and the width 1920 px unless noted.

| Case | Dataset | Render | Range | Filters | TZ | Width | Purpose |
|---|---|---|---|---|---|---|---|
| C01 | core | poll | 1h | All | UTC | 1920 | committed default; live tiles; Mixed merges |
| C02 | core | off | 1h | All | UTC | 1920 | base |
| C03 | core | stream | 1h | All | UTC | 1920 | `stream --once` after subscription |
| C04 | core | off | 6h | All | Asia/Kathmandu | 1920 | births between step boundaries (defect 2), grid shift, uptime with pause and stop |
| C05 | core | poll | 6h | Job = App\Jobs\SendEmail (URL value) | UTC | 1366 | namespaced filter (the refuted item must stay green), live null fallback, `Oldest: —` |
| C06 | core | off | 15m | Queue = slow | UTC | 1920 | red oldest wait, minute caption, 15 s steps |
| C07 | core | off | 1h | Job = App\Jobs\Sync + App\Mail\Sync + Truth\Report | UTC | 1920 | multi-select, the same display text twice |
| C08 | core | poll | 1h | Supervisor = supervisor-pool | UTC | 1920 | balance=false pool |
| C09 | core | off | 15m | Batch = import | UTC | 1366 | batch scope (O2) |
| C10 | core | poll | 1h | Batch = import + Job = one of its classes | UTC | 1920 | batch × job |
| C11 | core | stream | 15m | Queue = default | Asia/Kathmandu | 1920 | stream tiles ignore filters (R4.1), the other panels honour them |
| C12 | core | poll | 1h | Supervisor = default + Queue = default, reports | Asia/Kathmandu | 1920 | combination; poll × non-UTC |
| C13 | core | off | abs [V − 1 h − 23 s, end 30 s inside BornH's hold] | All | UTC | 1920 | range ends inside a hold; `to` off the grid |
| C14 | core | off | abs, starting inside BornH's hold | All | UTC | 1920 | range starts inside a hold |
| C15 | core | poll | 1h | Job picked in the UI by its visible text `SendEmail` | UTC | 1920 | UI path (picker, multi-select) |
| C16 | s5 | poll | 15m | All | UTC | 1920 | S = 5 s |
| C17 | s5 | off | 1h | Queue = default | UTC | 1920 | S = 5 s × filter |
| C18 | s60 | poll | 6h | All | UTC | 1920 | S = 60 s, held birth against a 2 m step |
| C19 | s60 | off | 1h | All | Asia/Kathmandu | 1366 | jitter, failed scrape |
| C20 | s60 | stream | 15m | All | UTC | 1920 | S = 60 s stream |
| C21 | s60 | off, the committed 10 s render | 1h | `var-scrape_interval=60s&var-busy_window=120s` | UTC | 1920 | URL override on a 10 s datasource (R3) |
| C22a | replicas | poll | 1h | prometheus_job = rep | UTC | 1920 | two per-pod replicas, dedupe |
| C22b | replicas | poll | 1h | prometheus_job = rep-single | UTC | 1920 | gate: C22a equals C22b (gauges; counters equal the merged-schedule truth) |
| C23 | replicas | off | 15m | prometheus_job = rep, Supervisor = default | UTC | 1920 | replicas × filter, a failed slot on b |
| C24 | multi-job | off | 1h | All jobs | UTC | 1920 | late job with history, shared names, reset, outage (defect 1) |
| C25 | multi-job | poll | 6h | All jobs | UTC | 1920 | defect 2 at 2 m with a late job |
| C26 | multi-job | off | 1h | prometheus_job = billing | UTC | 1920 | reset inside the range; Infinity tiles of the datasource's app |
| C27 | quiet | poll | 1h | All | UTC | 1920 | zero attempts: `RPM: —`, `Success: —`, dashes |
| C28 | quiet | off | 6h | All | UTC | 1366 | uptime below 100 %, colours while paused and stopped |
| C29 | quiet | stream | 15m | All | UTC | 1920 | quiet live tiles |
| C30 | quiet | off | abs, before E_5 | All | UTC | 1920 | `No metrics` |

### Coverage of the PR set

`Cases::coverage()` checks it in `CoverageTest`, and adding a dimension value fails until it is covered:
- every render meets every range, except stream × 6h (weekly);
- every render meets a non-UTC zone;
- every filter class meets at least 2 renders;
- every S meets at least 2 renders and at least 2 ranges;
- every data condition meets off and poll;
- every repeat panel (#5, #7, #14, #16) is asserted in at least one case with 2 or more clones.

### Weekly additions (about 70 page loads per version)

- **The full core product:** off/poll/stream × 15m/1h/6h × UTC, Asia/Kathmandu and America/St_Johns (−02:30/−03:30).
- **Filters:** every filter class in off at 15m and 6h.
- **Views and widths:** 2560 and 1024 px for C02 and C04; kiosk mode for C01, C02 and C04.
- **Stream:** stream 6h; push before subscription for C03 and C20.
- **Refresh cycles:** C02 with the dashboard's own refresh, comparing cycles 0 and 2.
- **Other datasets:** s5 6h; s60 15m off; the override case at 6h; replicas 6h off and poll; multi-job 15m and 6h off;
  quiet 15m off.
- **Determinism:** every PR case observed twice, with identical frames (order-insensitive) and DOM.
- **SensitivityTest and MutationTest** (section 11).
- **Other checks:** ScrapeParity over 300 slots and every family; AlertShape; the recording leg on predis.
- **Optional:** a non-blocking `grafana/grafana:latest` leg (owner question 5).

---

## 10. DOM check

Done by `browser/dom.mjs` after cycle 0 has completed, on both versions, and asserted by the specs at level `dom`.

**Page setup:**
- locale en-US and the case's time zone;
- the default (dark) theme;
- the case's width, height 5000 (at 1080 the lazy panels #6 and #14 stayed unloaded after the rows were expanded;
  verified by fidelity);
- both rows (`Details · Jobs`, `Details · Batches`) clicked open;
- anonymous Viewer access for the page; admin credentials only for API calls.

**Completion criterion.** The page counts as done only when all of these hold:
- every (panel, refId) of the render, with one instance per repeat value (the clone count from the scene, cross-checked
  with the variable's options), has answered;
- every Live channel has delivered a frame;
- every scene panel's data state is Done;
- no request has been open for 700 ms.

Otherwise the case fails as `infrastructure` after 90 s, never as a pass.

**Reads for each `[data-viz-panel-key]`, clones included:**
- **Stat tiles** (#1, #2, #3 one value per supervisor, #4, #8, #9, #21):
  - the title;
  - each value's caption (the field display name), e.g. `Uptime`, `Oldest: 3 min · slow`, `RPM: 18`, `Success: 97.8%`,
    `default · max 3`, `Stable in 7m`;
  - the value text exactly as formatted (`1,080`, `—`, `No metrics`);
  - whether the sparkline is present;
  - the colour of the value container. It is mapped to a named colour through `browser/calibrate.mjs`, which loads a
    calibration dashboard of fixed-colour stat tiles in the same colour modes on each Grafana, so the gradients are
    measured per version and never hard-coded. Determinism's measured stops serve as a cross-check, e.g. green
    `rgb(66,154,67)→rgb(111,183,87)`.
- **Tables** (#5 per clone, #6, #16 per clone, #17, #18–#22):
  - the headers in order and the row count;
  - every cell text, after value mappings (`—`, `5 s / 30 s`), and the colour of the level-coloured cells;
  - both the `role=cell` and the TableNG `role=gridcell` markup.

  With height 5000, every row of these datasets renders. The scene data stays the full-set reference if a table
  virtualises.
- **Timeseries** (#7, #14 clones): the clone titles and the legend items. Values are checked at the frame and transformed
  levels.

**Everywhere:**
- no panel error indicator, and no `No data` where data is expected;
- the interpolated titles (`${supervisor_title:text}`, `Pending by job: $queue`) equal the variable display texts;
- #23 and #24 are hidden, as QueueSourceE2eTest checks;
- every #5 copy is non-empty.

**Live modes.**
- Poll: the tiles show the router's `/live` values at V, merged over the Prometheus sparkline, with live caption and
  colour.
- Stream: after `stream --once`, the tiles show the pushed values (TILE_ROWS, the −1 sentinel as `—`) with no duplicated
  fields. The received WebSocket frames are compared with LiveExpect too.

**Expected text** = the spec's value through `Format`. If the frame value is right but the text is wrong, the failure is
reported as a formatting defect.

---

## 11. Coverage gate

### 11.1 Static gate

`CoverageTest.php`, group `grafana`, no Docker, about 1 s. It runs in the existing tests.yml `grafana` job on every PR,
and in local `pest`.

**Universe:**
- the committed renders: `resources/grafana` poll, `tests/Fixtures/grafana/{off,stream}`;
- the S = 5 s and 60 s renders, made in memory with the real `Render` and `--scrape-interval`;
- every non-row panel (nested ones and the hidden #23/#24 included) and every target as
  (render, panelId, refId, datasource kind, sha1 of the canonical target JSON);
- every variable, and every displayed panel for the DOM layer.

Today: 19 panels per render; 60 (off), 61 (poll) and 58 (stream) targets; 64 distinct (panel, refId) pairs.

**It fails when:**
1. a target has no registered spec (render-specific where the JSON differs), or a spec names a panel or refId that no render
   has (stale spec);
2. a target hash has no PR case on a render that contains it, or, outside an explicit allow-list with reasons (helper Z
   refIds, empty-state helpers), no PR case whose expectation is non-trivial (non-empty, not all zero or dash);
3. a repeat panel lacks a case with 2 or more clones, a query variable lacks an options spec and a case with a non-trivial
   option list, or a displayed panel lacks a DOM spec and a PR case per render;
4. a field consumed by the panel JSON is not asserted by its spec: `legendFormat {{label}}`, configFromData
   `configRefId`/`fieldName`, labelsToFields, filterByValue and calculateField fields, organize/rename keys, override
   matchers, Infinity columns.

   By default the comparator checks every label exactly. A label may be ignored only through `ignoreLabels([...])`, and
   only when the gate proves no field consumes it;
5. a tolerance is missing from the README tolerance table, a deviation lacks provenance or a case, or the README coverage
   table (render | panel | refId | meaning | cases | tolerance class) differs from the registry, as T16's CoverageTest
   does for families;
6. during the fan-out, a target is neither covered nor listed in `Specs::PENDING`, or `PENDING` lists a covered or stale
   target. `PENDING` must be empty before T17 moves to `testing`.

Failures are actionable, e.g. `panel 25 'New tile' A (off): no spec; add Expect/Panels/NewTile.php and a DOM spec`. A
target that T13 adds or changes therefore turns `grafana` red until T17 covers it.

### 11.2 Runtime gate

The last test of `DashboardTruthTest`:
- for the executed set, every promised (case, version, panel/clone, refId, level) assertion happened at least once with a
  non-trivial expectation where promised;
- any `/api/ds/query` error frame, any panel error indicator, any unattributed request, any unanswered target and any
  router 4xx/5xx is a failure of its own.

### 11.3 Sensitivity gate

`SensitivityTest` and `MutationTest` run weekly. A single SensitivityTest run is part of the T17 acceptance, recorded in
the QA note.

**Round-3 overlay** (`Mutants/round3/`):
- `bin/run mutants:round3` extracts every target whose JSON differs between `9edfae6`'s renders and the base renders,
  restricted to the targets built by `Attempts::delta()`, `perStep()` and `perStepTotal()`:
  #24 C, #6 B, #4 A/B/C, #8 A/B, #17 D/G/H and #14 A/B.
- The overlay is committed together with the base commit id and imported under the uids `t17m-…`.
- It is evaluated on the same recordings, i.e. master's exporter with T16's hold. `9edfae6` does not contain T16
  (`41d971b` is not an ancestor), so its own exporter is never run.
- `expected-failures.json` lists the expected failures:
  - **Defect 1** (per-group start), in C24 and C26: #24 C (every #5 clone), #6 B, #4 B/C, #8 B (off), #17 D/G/H and
    #14 A/B.
  - **Defect 2** (perStepTotal first partial step):
    - C04 and C25: #4 A at 2 m on both versions;
    - C04: #8 A on 13.2.4 only (12.4.13 sends 1 m, and a 90 s hold always spans a 1-minute boundary);
    - C24: #4 A and #8 A at 15 s for the late job's series;
    - every 5 m replay of #4 A and #8 A on both versions.
- **Assertion:** failures(overlay) − failures(base) == the expected list; failures(base) on these targets is empty; C05
  and C15 (namespaced Job filter) show no failure in either. This is the "fail on the old forms, pass on the fixed ones"
  requirement.

**Synthetic mutants** (`Mutants/Synthetic.php`, applied to the in-memory base render). Each must fail at least one named
case:
- the `job=~"$prometheus_job"` matcher dropped;
- the replica `max by` turned into `sum by`;
- `[$__range]` turned into `[1h]`;
- the batch scope removed;
- LOOKBACK of 1 m;
- a literal `[15s]` busy window;
- the level thresholds swapped;
- a caption format changed;
- TILE_ROWS without `groupBy`;
- an Infinity column selector typo.

---

## 12. CI

### Workflow

New workflow `.github/workflows/dashboard-truth.yml` (T17-owned). `tests.yml` is unchanged, because the stack groups
self-skip without their environment variables.

**Triggers:**
- `pull_request` (opened, synchronize, reopened, ready_for_review, labeled) and `push` to `master`, with `paths:`
  - `src/**`, `resources/lua/**`, `resources/grafana/**`, `routes/**`, `config/**`;
  - `composer.json`, `testbench.yaml`, `workbench/**`;
  - `tests/DashboardTruth/**`, `tests/Truth/Support/**`, `tests/Support/**`, `tests/Fixtures/grafana/**`;
  - the workflow file.
- `composer.lock` is not committed, so every run resolves current upstream releases. A dependency bump is caught by the
  next qualifying push and at the latest by the weekly run.
- Draft PRs run only with the label `dashboard-truth` (owner question 5).
- `schedule: '41 6 * * 1'`, after the tests.yml weekly e2e at 05:23.
- `workflow_dispatch` with inputs `set` (pr | full) and `cases`.
- `concurrency: dashboard-truth-${{ github.ref }}-${{ github.event_name }}`, cancel-in-progress except for the schedule.

**Job `dashboard-truth`.** One job on ubuntu-24.04 (2 vCPU, 7 GB for this private repository). Timeout 30 min for the PR
set and 80 min for the full set.
- Service: `redis:7.4-alpine`. setup-php 8.4 with redis, pcntl, posix, pdo_sqlite and mbstring.
  `composer update --prefer-stable`, with the Composer cache.
- `docker pull` of `quay.io/prometheus/prometheus:v3.15.0`, `grafana/grafana:12.4.13` and `grafana/grafana:13.2.4`,
  started in the background while Composer installs.
- `actions/cache` of the Grafana plugin directory (Infinity 4.1.1 and Business Text 6.3.0, about 55 MB), filled once by
  a step that downloads the two pinned zips. Both Grafanas mount it read-only with `GF_PLUGINS_PREINSTALL_DISABLED=true`.
  This replaces `GF_PLUGINS_PREINSTALL_SYNC`: a plugin download timeout killed Grafana 12.4.13 at start during this
  design's experiments (X2).
- `actions/cache` of `~/.cache/ms-playwright` (key `playwright-1.56.1`), then
  `npm i --no-save --no-package-lock playwright@1.56.1` and `npx playwright install --with-deps chromium`.
- `php tests/DashboardTruth/bin/run --ci --set=${set}` runs record → ingest and parity → stack → capture (two browsers,
  one per version) → assert.
- Uploads, always: `report.md`, `report.json` and `observations/**`. On failure, also `recordings/**`, element
  screenshots and the Grafana, Prometheus and router logs.
- Weekly (`set=full`): the full case set, determinism, SensitivityTest, MutationTest, long parity, AlertShape and a
  second recording run with `REDIS_CLIENT=predis`. Optionally a separate non-blocking job on `grafana/grafana:latest`.

**Already present on every PR, at no extra cost:** the static coverage gate, IndependenceTest and the unit self-tests run
in tests.yml's `grafana` job (group `grafana`; promtool through `PROMTOOL`), so even PRs outside the path filter cannot
add an uncovered target.

### Runtime estimate (PR set; WP1 measures, WP8 must keep it at 15 min or less)

| Phase | Estimate | Basis |
|---|---|---|
| Setup (cached) | about 3 min | checkout, PHP, Composer, Playwright, pulls in the background, plugin cache |
| Record 6 datasets | 2–3 min | about 9,000 in-process scrapes at about 6 ms, plus workers and forks; T16 ran 151 scrapes with workers in 1.0 s |
| Ingest and parity | about 0.5 min | 1.4 M samples were written in 1.1 s; parity runs during Grafana start-up |
| Capture: 31 pages × 2 versions, two browsers | 4–5 min | 2.3–2.9 s per page on the dev host (X11), about ×2 on 2 vCPU |
| Replays, assert, report | about 1 min | about 1,800 browserless `/api/ds/query` calls at concurrency 8 |
| **Total** | **about 11–13 min** | weekly about 45 min |

**Budget.** About 40 qualifying pushes a month × 12 min plus 4 weekly runs × 45 min is about 660 runner-minutes a month.
For comparison, `tests.yml` runs about 22 jobs per push. Levers:
- the path filter;
- draft gating;
- cancel-in-progress;
- one job for both versions, so setup and recording are shared;
- moving cases to the weekly set if the 15-minute bound is exceeded.

**Blocking.** On PRs to `master` from the moment the workflow lands there, which is after T13 and T17 are merged. The
owner marks it required in branch protection (owner question 5). Until then it runs on the T17 branch and its PR.

**Local runs.** `composer test:dashboard-truth` (or `tests/DashboardTruth/bin/run`) uses the slot environment:

| Variable | Use | Default |
|---|---|---|
| `COMPOSE_PROJECT_NAME` | containers named `ho-s<N>-*` | – |
| `PROMETHEUS_PORT` | Prometheus | 19000 + N |
| `PARITY_PROMETHEUS_PORT` | parity Prometheus | `PROMETHEUS_PORT` + 500 |
| `GRAFANA_PORT` | Grafana 13.2.4 | 13000 + N |
| `GRAFANA12_PORT` | Grafana 12.4.13 | `GRAFANA_PORT` + 500 |
| `APP_PORT` | router | 18000 + N |
| `REPLAY_PORT` | parity replay server | `APP_PORT` + 500 |

Redis is the host-network `ho-redis`, with kept prefixes. `bin/run` tears down only its own project and prefixes.

---

## 13. File layout and ownership

Every file has exactly one owning work package (Arch Q9 rule 1). WP1 creates the skeleton and minimal implementations.
After the slice merges into the T17 branch, each file passes to the WP named below.

```
tests/DashboardTruth/
  README.md                  pipeline, how to run (slots, bin/run options), datasets, coverage table, tolerance table,
                             deviation register, open findings, sensitivity evidence, known limits          WP8
  CoverageTest.php           static gate (group grafana)                                                   WP8
  IndependenceTest.php       Expect/** uses no Grafana\*, Live\Captions, Promtool; no PromQL tokens       WP8
  RecordTest.php             one test per dataset (groups dashboard-truth-record, redis; env-gated)        WP2
  DashboardTruthTest.php     one test per case × version + runtime gate (group dashboard-truth; env-gated) WP8
  SensitivityTest.php        round-3 overlay, synthetic mutants (group dashboard-truth-weekly)             WP8
  bin/run                    stage orchestrator                                                            WP8
  Contracts/                 PanelSpec, TargetSpec, DomSpec, CaseDef, CaseContext, Mode, Level, Observation,
                             Recording, Manifest; schema/{plan,observation,recording,manifest}.schema.json WP1 → WP8
  Record/                    RecordingCase, Recorder, Timeline, ScrapeSchedule, HorizonWorld, Consistency (L1),
                             ResetEpochs, ReservedAtEnd, DelayedWindow, AlertWindows, TtlFreeze, WorldKeeper,
                             HorizonClock + horizon-clock-shim.php                                          WP2
  Datasets/                  Slice, Core, Scrape5, Scrape60, Replicas, MultiJob, Quiet                      WP2
  Server/                    router.php, PinnedApp.php, stream-once.php, RequestLog.php                     WP2
  Ingest/                    ScrapeEmulation, LabelNormalizer, RemoteWrite, Protobuf, Snappy, AlertHistory, Ingest
  Parity/                    replay.php, ScrapeParity, AlertShape                                           WP3
  stack/                     compose.yml, up.sh, down.sh, provisioning/{datasources/prometheus.yaml}, import.php,
                             calibration-dashboard.json, alert-shape-rule.json, plugins.sh                  WP4
  browser/                   observe.mjs, scene.mjs, dom.mjs, calibrate.mjs, ws.mjs, guards.mjs             WP4
  Expect/Core/               SeriesStore, Visibility, Counters, Gauges, Subquery, Histograms, Extrapolation,
                             Format, Colours, AttemptLog                                                     WP5
  Expect/Deviations.php      deviation register                                                             WP5
  Expect/Panels/             Horizon, SupervisorProcesses, Pending, Completed, Delayed, Failed, Alerts,
                             AlertSound                                                                      WP6
                             QueueSource, Queues, Batches, BatchDetail, PendingByJob, CompletedByJob, Jobs,
                             JobLists                                                                        WP7
  Expect/Live/               LiveExpect, RingBuckets, Captions (restated, not the package's)                WP6
  Expect/Alerts/             AlertsExpect                                                                    WP6
  Expect/Batches/, Expect/JobLists/, Expect/Variables.php                                                    WP7
  Expect/Specs.php           registry (panel, refId, modes, levels, tolerances, consumed fields)            WP8
  Cases/                     Cases.php (catalog, coverage check, pr/full sets), Plan.php, OpenFindings.php  WP8
  Compare/                   Observation, Canonical, Comparator, Replays, Checks, Report                    WP8
  Mutants/                   round3/{overlay.json, expected-failures.json, BASE}, Synthetic.php             WP8
  Unit/Ingest/**             encoder, snappy, normalisation, staleness (group grafana / dashboard-truth-unit) WP3
  Unit/Expect/**             core rules against promtool (group grafana)                                    WP5
.github/workflows/dashboard-truth.yml                                                                        WP1 → WP8
build/dashboard-truth/<run>/  (generated, gitignored through /build/)
  datasets/<name>/{recording.ndjson, manifest.json, <name>.sqlite}, renders/, plan.json,
  observations/<grafana>/<case>.json, router.log, report.{md,json}, screenshots/
```

**Integrator requests** (shared files, requested by WP1):
- `phpunit.xml.dist`: a `<testsuite name="DashboardTruth"><directory>tests/DashboardTruth</directory></testsuite>`.
  Without it Pest never discovers the directory.
- `composer.json`: the script `test:dashboard-truth` = `@php tests/DashboardTruth/bin/run`.

Nothing else changes outside the T17 directory:
- `tests/Pest.php` stays as it is: the T17 files declare `uses(TestCase::class)` or `uses(RecordingCase::class)`
  themselves.
- `.gitignore` already ignores `/build/` and the root `/node_modules/`.
- `tests.yml` needs no change.
- No change to `tests/Truth/**`, `src/**` or the committed renders.

---

## 14. Interplay with T13, T14 and T15

### T13 (QA round 4 in progress)

**Branch.**
- `wip/T17-dashboard-truth` is cut from `master` with `origin/wip/T13-grafana` merged in.
- At the time of writing the pushed T13 head is `9edfae6` (round-3 probes, without T16). The round-4 work exists on the
  implementer's local branch: `5291d85` merges master (T16), `5075855` chooses the start per series (finding 1),
  `7a350c8` counts a series from its first value inside the step (finding 2), and `d007725` corrects the refuted probe.
- T17 merges each T13 push into its branch; it never rebases shared history and never edits `src/**` or T13's tests.

**Proof of the two round-3 defects.** SensitivityTest (section 11.3): red on the round-3 overlay exactly on the expected
list, green on the base. C05 and C15 prove the refuted item stays green.

**Predicted results on the round-4 head**, to be confirmed by WP1, WP6 and WP7, then reported to T13 as QA findings or
raised with the owner:
1. **Pooled balance=false queues.** The round-4 renders have 0 references to `horizon_supervisor_queues` (verified on
   `d007725`; T16 README §2 assigns that change to T13). The pool's #5/#24 rows, processes per queue and busy workers
   will therefore be missing in C08 and in every case with `supervisor-pool`.
2. **Counter reset inside the range.** Tables count only the post-reset value; graphs and tiles count 0 for the reset
   step. The verifier's case gave 26 in the tables and 29 on the tiles (`5075855` message). Owner question 1.
3. **A scrape gap inside the range longer than LOOKBACK (tiles) or 5 m (graphs)** loses the gap's attempts while the
   tables count them. The docblocks say so, but ADDENDUM R3.1 says only a gap before the period start loses counts.
   Owner question 2.
4. **Range-fed gauge tiles** show the last grid point, 60 s before `to` under Asia/Kathmandu at 2 m. Owner question 3.
5. **The `increase()` cells** #6 F/G and #24 K are approximate. Owner question 4.
6. **Possibly: Supervisor Processes under a Job filter** with no busy workers shows `—` where the honest value is 0. This
   is a T13 finding unless T13 documents it.

**Keeping T17 in step.**
- The T13 acceptance addition ("every WP8B/8C/8D step keeps T17 green") becomes enforceable once the slice runs in CI on
  the T17 branch: every T13 push merged there is checked by `dashboard-truth`, and the static gate names every new or
  changed target.
- After both tasks merge, the workflow runs on every qualifying PR.

**T13's own tests.** T13's promtool truth tests stay as fast unit-level checks; T17 does not duplicate them. Filtered-state
probes, by T13 or by testers, should use T17's observer (`bin/run --cases=…`) instead of `Promtool::interpolate`, which is
correct only for "All".

**Ordering.** T17 cannot be `done` before T13 is merged (TASKS T17 dependency).

### T14

There is no dependency. After T14 merges (the provider's command list changes), `stream-once` still calls
`horizon-observability:stream` through Artisan, and nothing in T17 depends on the ops commands.

### T15

- **Weekly runs.** The tests.yml weekly e2e (which T15 makes blocking, adding the perf report and the poll/stream screenshots) and the T17 weekly run are
  separate workflows on Monday, at 05:23 and 06:41 UTC. They do not duplicate each other: the e2e covers real-time
  behaviour, latency and look; T17 covers values.
- **Planning references.** T15 deletes `planning/`. T17's README and spec docblocks therefore cite `src/**` docblocks and
  provenance ids ("ADDENDUM R3.4", "decision 13"). T15 maps those ids to the README sections it writes. The deviation
  register keeps the owner's approval dates.
- **Documentation.** The T15 README documents how to run T17 and carries the tolerance and deviation tables.
- **Appendix H.** In "every Appendix H bug mapped to a passing test", the dashboard rows may point at T17 cases.

---

## 15. Risks

| Risk | Mitigation |
|---|---|
| The remote-write emulation diverges from Prometheus' scrape loop (staleness, `le`, escaping, empty labels, target labels, a future Prometheus) | ScrapeParity on every PR against a real scrape of the PR's own expositions; weekly over 300 slots and every family. Prometheus is pinned to v3.15.0, as in the rest of CI. |
| The clock shims rely on PHP's namespaced-function fallback and on unqualified `microtime()`/`time()` calls in Horizon and `Illuminate\Bus`; PHP caches the resolution, so load order matters | The shim loads at file load of RecordTest. Assertions cover pushedAt, batch timestamps and job-record stamps on every payload and row, with a failure that names the shim. Pass-through when inactive; loaded only with `DASHBOARD_TRUTH_RECORD=1`. Fallback if refused: re-clock batch rows after each step (determinism's BatchClock) and accept real-time job-list windows. |
| `window.__grafanaSceneContext` is a debug hook, not an API (present on both versions) | The frame and DOM levels do not depend on it. Clone attribution cross-checks against `data-viz-panel-id` and the scoped literal. A missing hook fails loudly as `infrastructure`. Versions are pinned; the weekly `latest` leg warns early. |
| Circularity: a spec restates the dashboard's own wrong semantics | Specs restate docblocks, Arch and the ADDENDUM, never PromQL. IndependenceTest and the PromQL-token guard. Core rules are proven against promtool on reference expressions. The attempt-log cross-check. Non-trivial expectations are required. Sensitivity: the round-3 overlay plus synthetic mutants. |
| Redis TTLs during capture (ring 180 s, Horizon records 15/30 s, extremes 300 s) and the real time between recording and capture | TtlFreeze PERSISTs everything under the world prefix; `cache_ttl = 0`; the leader key is deleted; WorldKeeper cleans up through a trap. |
| Flakiness: lazy panels, plugin downloads, Live timing, 2 vCPU running two Grafanas, Prometheus, PHP and two Chromiums | Height 5000; the completion criterion; cycle 0 only; `refresh=1h`; cached read-only plugins; at most two browsers; retries only for infrastructure steps, never for assertions; per-stage timings in the report. |
| Version differences in steps and Live (12.4.13 collapses same-timestamp rows in labels push mode) | Per-version expectations from captured grids. R4.7's 1 ms row spacing comes from the real `LineProtocol`; stream is verified on both versions with the fake clock (X9). |
| The expected reds on the round-4 head (pooled queues, resets, gaps, range-fed gauges, `increase()` cells) stall T17 | Open-findings register (xfail) during development; owner questions 1–4 asked now; T13 findings reported through the board. T17 → `testing` only when the register is empty. |
| Spec churn while T13 changes in round 4 | Specs are keyed by (panel, refId); the static gate pinpoints changed and new targets; T13 pushes are merged into the T17 branch. |
| CI cost under the limited Actions budget | Path filter, draft gating, cancel-in-progress, one job for both versions, a lean PR set (31 pages); the full matrix only weekly; WP1 measures the cost and WP8 enforces the 15-minute bound. |
| Synthetic GRAFANA_ALERTS drift from what Grafana writes | Weekly AlertShape against a real state-history write on both versions. |
| Real job durations make duration cells vary between runs | Exact comparison against recorded, bounds-checked samples; planned durations keep T16's margins from bucket bounds, so buckets and p95 bucket choices stay exact. |
| Two applications behind one Grafana: Infinity tiles show only the datasource's app | Documented limit (Arch Q5.2 risks); multi-job expectations state it explicitly. |

---

## 16. Work packages (ordered)

The order is WP1, then WP2–WP5 in parallel, then WP6 and WP7 in parallel, then WP8.
- Every WP ends green on its groups, on phpredis (and on predis where `redis` applies), and on `pint --test`.
- `phpstan` analyses only `src`, `config`, `routes` and `workbench/app`, so tests are not in its scope.
- WPs that need the stack run in their own slot.

1. **WP1 Thin end-to-end slice** (T17 lead). One panel (#8 Completed, off render), one case (slice dataset, off, 1h, All,
   UTC, 1920), both Grafana versions, in CI. The slice freezes the contracts and proves every stage before the fan-out.
2. **WP2 Recorder, clocks, datasets and app endpoints.**
3. **WP3 Ingest, scrape parity and alert history.**
4. **WP4 Stack and browser observer.**
5. **WP5 Expectation core and deviation register** (no Docker; promtool).
6. **WP6 Tile, live and alert specs.**
7. **WP7 Table, detail, job-list and variable specs.**
8. **WP8 Cases, comparator, gates, sensitivity, CI and README.**

The `work_packages` array of this design carries each brief, its files, dependencies and stack need.

---

## 17. Verified facts

### Experiments for this design

Slot 23: containers `ho-s23-*`, Prometheus 19023, Grafana 13.2.4 on 13023, 12.4.13 on 13523. All containers were removed
afterwards, and the main checkout was not modified.

- **X1. Clock shims.**
  - With the shim loaded first, `JobPayload::prepare()` wrote `pushedAt = 1741003200`, the virtual 2025-03-03T12:00Z.
  - When the same call ran once before the shim was loaded, it kept the real `microtime` (`1792404074.0815`) for the rest
    of the process.
  - `DatabaseBatchRepository` wrote `created_at = 1741003200` and, after the clock moved, `finished_at = 1741003260`.
  - The Repositories shim measured 0.200 s across a 200 ms sleep, so runtimes stay real.
  - Horizon 5.50.1's unqualified call sites: `JobPayload.php:104` and `Stopwatch.php:22/34` (namespace `Laravel\Horizon`);
    `RedisJobRepository.php` 344, 373, 400, 427, 453, 482, 659, 679 and `RedisTagRepository.php` 84, 101
    (`Laravel\Horizon\Repositories`); `Jobs/RetryFailedJob.php:73` (unused).
  - `Illuminate\Bus`: `DatabaseBatchRepository.php` 107, 206, 219, 220.
  - The package uses `now()` and `hrtime` only. The job-list runtime is `completed_at − reserved_at` from
    `RedisJobRepository` (`JobRows::runtime`).
- **X2. Plugin download.**
  - Grafana 12.4.13 with `GF_PLUGINS_PREINSTALL_SYNC` exited at start: `failed to install plugin
    yesoreyeram-infinity-datasource@4.1.1: … context deadline exceeded`.
  - With the plugin directory copied from the 13.2.4 container (Infinity 50 MB, Business Text 5.5 MB), mounted
    read-only, and `GF_PLUGINS_PREINSTALL_DISABLED=true`, it was healthy in 13 s with both plugins.
- **X3. One TSDB for all datasets.** Two synthetic 7 h datasets ending 2025-03-03T07:00Z and 2025-03-10T07:00Z were
  remote-written in time order into one empty Prometheus v3.15.0 (`retention.time=100y`, tmpfs) in 0.5 s. Both were
  queryable at their ends (the sum was 7,728 at each); the head started at 06:00 on 2025-03-03, with older data compacted.
- **X4. Fake clock.** `page.clock.setFixedTime(1740985200000)`: `from=now-6h&to=now` was sent as 1740963600000 to
  1740985200000 on both versions.
- **X5. maxDataPoints and steps at 6 h, 1920 px**, normal view versus kiosk mode:

  | Panel | 13.2.4 normal | 12.4.13 normal | 13.2.4 kiosk | 12.4.13 kiosk |
  |---|---|---|---|---|
  | #2, #8 | 240 (2 m) | 258 (1 m) | 304 (1 m) | 308 (1 m) |
  | #4, #9 | 178 (2 m) | 192 (2 m) | – | – |
  | #1, #21 | 116 (2 m) | 125 (2 m) | – | – |
  | #3 | 364 (1 m) | 391 (1 m) | – | – |
  | #24 | 100 (5 m) | 100 (5 m) | – | – |
  | #6, #7, #14, #16, #17 | 1478 (15 s) | 1478 (15 s) | – | – |
- **X6. Time zones** (Asia/Kathmandu, 13.2.4).
  - A relative 6 h range sent `utcOffsetSec` 20700. #4's first point lay 60 s past a 2-minute boundary and its last point
    at `to` − 60 s.
  - An absolute range sent 0, with the first point at `from` and the last at `to`.
- **X7. Replay at coarser steps.**
  - The captured #4 body re-posted unchanged gave byte-identical frames.
  - `maxDataPoints` 60 gave a 5 m step with 73 points and the same tile sum (144) on continuous synthetic data;
    `intervalMs` 300000 also gave 5 m.
  - `intervalMs` 10000 with `maxDataPoints` 178 stayed at 2 m: the backend takes the larger of the two.
- **X8. Auto-refresh with a fixed fake clock.**
  - Without a refresh override, 41 (relative range) and 43 (absolute range) `/api/ds/query` requests in 22 s.
  - With `refresh=1h`, 10, and none after 1.9 s.
- **X9. Stream under the fake clock**, on both versions (2025).
  - A push before the page subscribed was shown from the cached frame; a push after it updated the tiles (`Pending |
    Oldest: 45 s · default | 11`, `Delayed | 9`).
  - A malformed push whose tile rows were more than 500 ms apart dropped the supervisor rows (`—`).
- **X10. Scene variables.** `__grafanaSceneContext.state.$variables` gave each variable's name, value, text and option
  count on both versions.
- **X11. Page time.** The off render with both rows expanded, 1920×5000 and a 700 ms quiescence wait took 2.83–2.88 s per
  page on 13.2.4 and 2.29–2.43 s on 12.4.13, on the 24-thread dev host. Chromium launches in about 30 ms.
- **X12. Round-4 renders** (`d007725`): 60, 61 and 58 targets in off, poll and stream; 0 references to
  `horizon_supervisor_queues`; 28 `increase(` occurrences. `increase()` sits in #6 A/B/E/F/G/Z and #24 K, with
  `histogram_quantile` in #6 G and #24 K. T16 (`41d971b`) is not an ancestor of `9edfae6`.

### Facts from the three designs, confirmed by the judges

- Frames carry `meta.executedQueryString`. The backend substitutes `$__interval`, `$__interval_ms` and `$__range`, but no
  template variable and not `${__from:date:seconds}` (a 400 parse error, or a silently empty result for a leftover
  `$prometheus_job`).
- `${job_class:text}` is sent as the option text (`SendEmail`); `$job_class` as the regex-escaped value.
- A scrape normalises `le` (`1` → `1.0`) and drops empty label values; promtool backfill normalises `le` but cannot write
  staleness; remote write keeps `le` as sent and accepts StaleNaN.
- Remote write accepts samples up to about 10 min in the future, rejects samples older than the head minus about 1 h once
  newer data exists ("out of bounds"), and writes 1.4 M samples in 1.1 s.
- Clone X-Panel-Ids are hashes, mapped through the scene to the scoped variables and the DOM's `data-viz-panel-id`.
- Series order varies between loads, so comparisons are order-insensitive. `refresh=` and `refresh=false` do not stop
  auto-refresh.
- The hidden `datasource` and `app_api` variables have `skipUrlSync`; `var-scrape_interval` is honoured.
- 1920×1080 left #6 and #14 unloaded after the rows were expanded; 1920×5000 loaded all 19 panels.
- Grafana 12.4.13 keeps only the last of tagless same-timestamp rows in labels push mode; 13.2.4 keeps all of them
  (R4.7).

---

## 18. Owner questions (summary)

1. **Counter resets inside a range.** Honest per-pair truth (T13 must make `delta()` and the per-step forms reset-aware), or
   approved deviations D-R1 and D-R2?
2. **In-range scrape gaps** longer than LOOKBACK (tiles) or 5 m (graphs). Honest (T13 fix), or deviation D-G with ADDENDUM
   R3.1 amended?
3. **Gauge tiles fed by range queries.** The last grid point, or the value at `to`?
4. **The three `increase()` cells.** Accept them as approximate, compared exactly against Prometheus' extrapolation with an
   honesty bound, or require exact forms (decision 8)?
5. **CI policy:**
   - drafts only with a label;
   - a required check after both tasks merge;
   - one job running both versions;
   - an optional weekly non-blocking `grafana:latest` leg.
