# Dashboard truth tests (T17)

Owner decision 13: every number the rendered dashboard shows must equal the ground truth of a deterministic
scenario, and tests must prove it on Grafana 12.4.13 and 13.2.4. The exporter side is T16 (`tests/Truth`). The design
is `planning/T17-DESIGN.md`; this README is the skeleton the work packages fill (WP8 owns it).

Status: **WP1, the thin end-to-end slice**: one panel (#8 Completed, off render), one case (S01) and a negative
control (S02), both Grafana versions, every stage real.

## Pipeline

```
RECORD   Pest (RecordTest, DASHBOARD_TRUTH_RECORD=1): T16's Scenario on a virtual clock at the dataset's epoch E_k,
         real workers, a real in-process scrape at every slot, L1 check against T16's Model at every scrape
         -> datasets/<name>/{recording.ndjson, manifest.json, <name>.sqlite}, Redis world ho_t17_<run>_<name>: kept
INGEST   ScrapeEmulation (target labels, up, le/quantile normalisation, StaleNaN) -> RemoteWrite (protobuf,
         all-literal snappy, time-major) into Prometheus v3.15.0 (tmpfs, remote-write receiver, no scrape jobs)
STACK    Grafana 12.4.13 and 13.2.4 (host networking, cached read-only plugins), the shipped Infinity datasource
         pointing at the router (php -S Server/router.php: the real workbench app on the world, Carbon pinned at V),
         the renders imported by API under t17-<mode> with only uid and id changed -> plan.json
CAPTURE  browser/observe.mjs, one Chromium per version: fake clock at V, 1920 x 5000, rows expanded, refresh=1h,
         the completion criterion -> observations/<grafana>/<case>.json (requests, responses, scene, DOM, router log)
ASSERT   Pest (DashboardTruthTest, DASHBOARD_TRUTH_STACK=1): every spec on Grafana's grid and on the replays at S and
         the next coarser step, the DOM, the checks, then the runtime gate -> report.{md,json}
```

Everything lives under `build/dashboard-truth/<run>/`. Expectations come from the stored samples through restated
documented semantics (`Expect/Core`), never from the dashboard's PromQL (`IndependenceTest`).

## How to run

```bash
npm i --no-save --no-package-lock playwright@1.56.1     # once; Chromium via npx playwright install chromium
composer test:dashboard-truth                           # = php tests/DashboardTruth/bin/run, slot 0
php tests/DashboardTruth/bin/run --slot=30              # another port block (several agents on one machine)
```

| Option | Meaning |
|---|---|
| `--from=record\|ingest\|stack\|capture\|assert` | resume from a stage (`capture`/`assert` need a stack kept with `--keep`) |
| `--run=<id>` | the run to resume (default: a new one) |
| `--keep` | leave the stack, the router and the Redis worlds running (`bin/run cleanup --run=<id>` removes them) |
| `--set=pr\|full`, `--cases=S01,S02` | the case set, or only these cases |
| `--grafana=12\|13\|both` | Grafana versions (default both) |
| `--slot=<N>` | ports: Prometheus 19000+N, Grafana 13.2.4 13000+N, 12.4.13 13500+N, router 18000+N (env overrides: `PROMETHEUS_PORT`, `GRAFANA_PORT`, `GRAFANA12_PORT`, `APP_PORT`, `COMPOSE_PROJECT_NAME`) |
| `--mutant=<name> --expect-red` | import a synthetic mutant (`Mutants/Synthetic.php`) and require its cases to fail with a dashboard defect |
| `--ci` | CI output groups |

`bin/run replay --run-dir=<dir> --case=S01 --grafana=13 --panel=8 --ref=A --step=10s` re-posts a captured query at
a step and prints its frame; every failure in the report carries this command. `bin/run compare <run A> <run B>`
compares the observations of two runs (the determinism check, `Compare/Determinism`): the sent queries, the frames
(series and rows order-insensitive), the DOM and the scene variables must be identical. Only the measured durations
are masked: attempt durations (#6 F, G, I, J and their DOM cells) and Horizon's job runtimes and record stamps (the
job lists' Runtime, their times compared to the second), plus the random job ids in the job-list links.

The record stage needs Redis on `REDIS_HOST:REDIS_PORT` (127.0.0.1:6379); it writes only under its world prefix and
`bin/run` deletes the worlds at the end (also on failure and SIGINT/SIGTERM) unless `--keep`. Normal `pest` runs skip
`RecordTest` and `DashboardTruthTest` and run the static gates and self-tests (group `grafana`).

## Datasets

| Dataset | Epoch | Span | Targets | Content |
|---|---|---|---|---|
| `slice` | E_0 = 2025-03-03T00:00Z | 1 h 40 min | `app` / `app:8080`, S 10 s, phase 3.2 s | `supervisor-default` (default, auto, 3 processes); Truth\Invoice completed, failed and retried every 30 s; batch `import`; Truth\Born held birth 5 s after the 2-minute boundary at 3600 s |

## Cases

| Case | Dataset | Render | Range | Filters | TZ | Width | Purpose |
|---|---|---|---|---|---|---|---|
| S01 | slice | off | now-1h | All | UTC | 1920 | the slice: #8 Completed |
| S02 | slice | off | now-1h | `var-queue=no-such-queue` | UTC | 1920 | negative control: must fail as `case setup`, never as a finding |

## Coverage

| Render | Panel | refId | Meaning | Cases | Tolerance |
|---|---|---|---|---|---|
| off, poll, stream (identical JSON) | #8 Completed | A | completed attempts per step (tile semantics); the number is their sum C(from, to) | S01 | exact |
| off | #8 Completed | B | `RPM: n`, completed per minute over the last max(5 m, 5 x S), rounded | S01 | exact |
| off | #8 Completed | DOM | `Completed \| RPM: n \| N` | S01 | exact |

Every other target of the nine renders (off, poll, stream at S = 10, 5 and 60 s) is listed in `Expect/Specs::PENDING`
(63 of 64 (panel, refId) pairs; #8 B stays pending for its poll and stream form). The static gate (`CoverageTest`)
fails on a target that is neither covered nor pending; PENDING must be empty by WP8.

## Tolerances

| Class | Targets | Rule |
|---|---|---|
| exact | #8 A, #8 B, #8 DOM | every point, label set and text exactly; floats to 1e-9 relative |

## Deviation register

Empty in WP1. D1 (a gap longer than LOOKBACK right before `from` loses its attempts, ADDENDUM R3.1/R2.4 O1) is part
of the counter rule's baseline itself. The candidates D-R1, D-R2, D-G and D-L wait for owner questions 1-3.

## Open findings

None.

## Sensitivity evidence

- Mutant `completed-retried` (#8 A counts retried attempts as completed): killed on both versions, 8 dashboard
  defects in S01 (grid, both replays and DOM per version); CI runs it after every PR run.
- Negative control S02: fails as `case setup` only, on both versions.
- Determinism: two full local runs (`wp1a`, `wp1b`, each recording its own world) gave identical observations, 4 of 4
  (`bin/run compare`, measured durations masked).

## Stage timings

| Stage | Local (24 threads, slot 30) | CI runner (ubuntu-24.04, 2 vCPU) |
|---|---|---|
| record | 5.6 s | (filled from the first green CI run) |
| ingest | 1.2 s | |
| stack | 2.0 s | |
| capture (2 cases x 2 versions) | 8.7 s | |
| assert | 0.5 s | |
| total | 18 s | |

## Known limits (WP1)

- `Expect/Core/Counters` models one instance per job (the replica dedupe is WP5) and the All selection only.
- The fixed browser clock is released (`page.clock.setSystemTime(V)`) once every query panel has answered: under a
  fixed `Date.now()` the `-- Dashboard --` datasource of #5 never leaves Loading. Every query is sent on the fixed
  clock; a query sent after the release on another range is an infrastructure failure.
- Horizon trims its job records with `EXPIREAT now + trim`, which on the 2025 virtual clock is already past, so the
  recorder keeps them with a ten-year trim (`RecordingCase`); `TtlFreeze` persists them.

## Ownership (design section 13)

WP1 created every file below; once the slice is merged into the T17 branch each passes to its work package (Arch Q9
rule 1: one owner per file).

| Files | Owner after WP1 |
|---|---|
| `Contracts/**` (CaseDef, CaseContext, Mode, Level, Category, Grid, Durations, Expectation, TargetSpec, DomSpec, PanelSpec, Observation, Recording, Manifest, Schema, `schema/*.schema.json`) | WP8 |
| `RecordTest.php`, `Record/**` (RecordingCase, Recorder, HorizonWorld, HorizonClock, horizon-clock-shim.php, TtlFreeze, WorldKeeper), `Datasets/**`, `Server/**` | WP2 |
| `Ingest/**`, `Unit/Ingest/**` | WP3 |
| `stack/**`, `browser/**` | WP4 |
| `Expect/Core/**`, `Unit/Expect/**` | WP5 |
| `Expect/Panels/Completed.php` | WP6 |
| `Expect/Specs.php`, `Cases/**`, `Compare/**`, `Mutants/**`, `Unit/Compare/**`, `DashboardTruthTest.php`, `CoverageTest.php`, `IndependenceTest.php`, `bin/run`, `README.md`, `.github/workflows/dashboard-truth.yml` | WP8 |
