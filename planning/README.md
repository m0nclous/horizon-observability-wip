# Handoff: rewrite of the Horizon metrics pilot into `sushi-market/horizon-observability`

This folder lets any agent (cloud or local) resume the rewrite at any point without the original chat.
Read this file first, then [TASKS.md](TASKS.md), then the sections of [ARCHITECTURE.md](ARCHITECTURE.md)
that the next task points to. Everything here is export-ignored and will be deleted before the first release (task T15).

## Where things are

| Path | What |
|---|---|
| `planning/TASKS.md` | Ordered task list with status, scope, inputs, owned files and acceptance criteria. **The source of truth for progress.** |
| `planning/ARCHITECTURE.md` | Approved final architecture (Q1–Q9 + appendices A–H: config file, metric catalog, frozen interfaces, Lua contracts, Redis keys, compat matrix, office migration, bug→test map). |
| `planning/ADDENDUM.md` | Approved additions R1–R4 (namespace `ho`, scrape interval, dashboard performance, Live Overview), committed in T01. ARCHITECTURE.md is amended in place to match it; where they still differ, the addendum wins. |
| `planning/audit/` | Audit of the legacy pilot: findings (`findings-*.md`: couplings F01–F29, design A01–A28, bugs C1–C26 with verdicts, dashboard, tooling), the dashboard contract (`dashboard-contract.md`), verified tool versions and dev recipes (`tooling-notes.md`). Paths like `/tmp/claude-0/...` in these files point to the cloud sandbox and no longer exist. |
| `planning/baseline/` | Screenshots of the **current** dashboard (Grafana 13.2.4, workbench data). The rewrite must keep this look; compare final screenshots against them. |
| `planning/reference/` | Helpers: `screenshot-playwright.mjs` (Playwright screenshot script), `probes/` (route-precedence and Horizon-middleware probes cited by Arch Q2.2), `addendum-probes/` (T01 performance harness: synthetic data, backfill, Playwright refresh timing, PromQL replay, dashboard variants v1–v4 and their results). `dev-stack-audit/` is an audit record only (host network, legacy `/metrics` paths), not runnable. |
| `legacy/` | The pilot code (old namespace `SushiMarket\HorizonPrometheusGrafana`), its docs, Python dashboard generator and composer.json. Reference only: not autoloaded, excluded from tools. Deleted in T15. |
| `tests/Fixtures/grafana/legacy/` | Golden files: `queues.json` (dashboard), `horizon.yml` (alert rules), `alert-sound.js` (panel #23 script). The PHP port of the generator must first reproduce them (T10). |

## Owner decisions (do not re-litigate)

1. Composer package `sushi-market/horizon-observability`, root namespace `DF\HorizonObservability`, license MIT.
2. Everything public is **English only**: code, comments, HELP texts, label values, logs, README, docs.
3. Supported versions: PHP `^8.2`; Laravel `^11.23|^12.0|^13.0` (11.23 is the first with `JobAttempted`);
   Horizon `^5.22`, raised to `^5.29.1` in the phase B integration (2026-10-17, Arch Q8 risk row: 5.22.0–5.29.0 are a
   fatal error on Laravel ≥ 11.27.0, see the Horizon facts in Arch 0.4). No other runtime dependencies (no spatie/laravel-prometheus, no spatie/laravel-data, no promphp).
4. **Grafana 12 and 13 are both supported** (minimum 12.3 because the Business Text plugin 6.3.0 needs it).
   Verify dashboard, alert provisioning, live modes and screenshots on `grafana/grafana:12.4.13` and `grafana/grafana:13.2.4`.
5. Architecture in `ARCHITECTURE.md` approved as is, including:
   - endpoints `GET /{horizon.path}/observability/metrics` and `GET /{horizon.path}/observability/jobs/{pending|completed|failed|delayed}`
     (not `/horizon/metrics`: that URL belongs to Horizon's own SPA Metrics page);
   - own config file `config/horizon-observability.php` (not a key inside `config/horizon.php`), env prefix `HORIZON_OBSERVABILITY_`;
   - bearer-token auth (token list, optional Grafana `api_token`, optional IP allow-list), open only in `local` when unconfigured;
   - the Python dashboard generator is ported to PHP (`php artisan horizon-observability:grafana [--check]`), first reproducing the legacy goldens exactly.
6. Default metric namespace is **`ho`** (`ho_queue_jobs_total`, `ho_horizon_status`). Self-metrics are `ho_exporter_*`
   (not `ho_horizon_observability_*`). Office keeps continuity with `HORIZON_OBSERVABILITY_NAMESPACE=office`.
7. Dashboard: keep the current look and behaviour; optimisations are welcome. Make it **fast** (panels must not take ~5 s).
8. Precision: counters/histograms are exact at any scrape interval; recommend a separate Prometheus scrape job for the
   package endpoint at **10 s** (supported 5–60 s); nothing on the dashboard may assume 15 s; min/max duration windows
   must not miss attempts at 60 s scrapes.
9. **Live Overview**: the Overview row shows the current load without scrape lag, in **two selectable modes** chosen at
   render time (`--live=off|poll|stream`), row renamed "Live Overview" when live is on:
   `poll` = JSON endpoint `{base}/live` polled by Infinity (1 s server-side cache);
   `stream` = long-running command pushing to Grafana Live (`/api/live/push/...`) with a Redis leader lock.
   Defaults (confirmed by the owner on 2026-10-17):
   - the committed `resources/grafana` render and `composer test:grafana` use `--live=poll`;
   - Grafana refreshes a whole dashboard at one interval (no per-panel refresh), so `poll` refreshes at the dashboard
     refresh (default 5 s, Grafana's `min_refresh_interval`); the 1 s server cache only protects against stampedes;
     faster polling needs `GF_DASHBOARDS_MIN_REFRESH_INTERVAL` and re-runs every Prometheus query, so it is documented,
     not defaulted;
   - `stream` is the only sub-5 s mode (live panels update independently of the dashboard refresh); it runs as a
     long-lived process (supervisor/systemd) on any number of replicas, one leader via the Redis lock.

   Look deviations of the live modes (ADDENDUM R4.1), **accepted by the owner on 2026-10-17**; `--live=off` keeps today's look exactly:
   - #3 Supervisor Processes loses its per-supervisor sparkline in poll and stream modes (the supervisor count is
     dynamic, and no stock transformation can merge a live row into each Prometheus series);
   - #2 Pending loses its sparkline in stream mode (Mixed cannot carry a stream, ADDENDUM R4.8);
   - the #8 Completed caption "RPM: n" counts the last complete 60 s (ring buffer) instead of the 5-minute average;
   - #9 Delayed in stream mode shows the last 15 minutes whatever the dashboard range (`timeFrom`);
   - poll mode refreshes the whole dashboard every 5 s instead of every scrape interval.
10. Execution mode: full plan with parallel agents and independent reviews per phase.
11. Releases: `0.x` until office runs the package in production, then `1.0.0`.
12. **Zero-friction onboarding** (owner, 2026-10-17): a newcomer must get from `composer require` to a dashboard with
    live data in minutes, without reading long docs. This drives adoption and outranks configurability in defaults:
    - works with zero configuration in `local` (no token needed there, sensible defaults everywhere);
    - one interactive `php artisan horizon-observability:install` that does everything it safely can (publish config,
      generate and write the token to `.env`, print or write the Prometheus scrape job and Grafana provisioning) and
      ends with "open http://localhost:3000";
    - an optional one-command local stack (`install --docker` writes a small compose file with Prometheus + Grafana
      preprovisioned with the dashboard, datasources and plugins, pointed at the app);
    - an optional demo (`install --demo` or a `horizon-observability:demo` command dispatching a short burst of sample
      jobs) so an empty app shows data at once;
    - `check` explains every problem with one actionable fix line;
    - README starts with a 3-step quick start and a screenshot; everything else lives further down;
    - measured: time to first dashboard <= 5 minutes on a fresh Laravel app (scripted e2e).
13. **Honest numbers, proven by tests** (owner, 2026-10-17): every exported metric and every number the dashboard shows
    must equal the ground truth, and tests must prove it, so that refactors, optimisations and dependency updates cannot
    silently make the dashboard lie.
    - Exporter truth (T16): deterministic scenarios with known outcomes (completed, failed, retried, released, timed
      out, delayed, batches, known durations and waits) run through real queue workers; exported values must equal the
      expected ones exactly (counters, histogram buckets/sum/count, gauges against the real Redis/DB state), plus
      invariants (count == +Inf bucket, monotonic buckets, per-class sums == totals incl. "(unscanned)", counters never
      decrease except a documented reset) and a cross-check against Horizon's own repositories.
    - Dashboard truth (T17): the same scenarios scraped by a real Prometheus; every panel query, exactly as rendered and
      with variables interpolated as Grafana does, is evaluated through Grafana's `/api/ds/query` on 12.4.13 and 13.2.4
      and must return the expected values (tiles, captions, colours, table cells, series), including filters, empty
      state, counter resets, two replicas, scrape intervals 5/10/60 s and ranges 15 m/1 h/6 h; a DOM check reads tiles.
    - Every change to collectors, Lua, `src/Grafana/**` or a dependency bump must keep both suites green (CI gate).
14. **Kanban with a QA gate** (owner, 2026-10-17). Board columns: Todo → In Progress → Testing → Done
    (`tools/board/README.md`). `planning/TASKS.md` stays the source of truth; change statuses only with
    `tools/board/set-status.sh` (it commits and pushes `planning/TASKS.md`, the board workflow moves the card).
    - The implementing agent sets `in progress` when it starts and `testing` when all its gates are green and committed.
    - At least two independent tester agents (never the implementer) test every task in `testing`:
      the **spec tester** (each acceptance item, Arch/ADDENDUM conformance, all gates on phpredis and predis, lowest
      legs where possible) and the **adversarial tester** (bug hunt, version compatibility, security, failure
      isolation, cost bounds, and honesty per decision 13: truth tests exist and pass for everything the task touches).
      Testers may fix trivial issues themselves (typos, a missing assertion) and say so; anything else is a finding.
    - Both approve → `set-status.sh Txx done --qa "round N approved by the spec and adversarial testers; <checks>"`.
      Any finding → `set-status.sh Txx "in progress" --qa "round N rejected by <tester>: <findings, one line>"`; the
      implementer fixes and moves it back to `testing`. The QA lines become issue comments.
    - After 3 rejected rounds the task stays `in progress` with `--qa "escalated to the owner: ..."` and the integrator
      asks the owner.
    - The integrator merges a task branch only when the task is `done`.

    **Proposed flow rules** (2026-10-17, **pending the owner's confirmation**). They answer the owner's question for
    more Kanban and flow ideas; the owner has not confirmed them yet, so they are not owner decisions and may be
    re-litigated. Until the owner decides, they are the working rules: the board tooling already enforces their gates.
    When the owner confirms (or changes) them, record the date here and drop this paragraph. Details and commands:
    `tools/board/README.md`.
    - **Needs owner.** A board column "Needs owner" (status `needs owner`, label `needs owner`) for a task that waits
      for a decision only the owner can make: `set-status.sh Txx "needs owner" --question "..."`. The issue comment
      mentions @m0nclous and comes from `github-actions[bot]`, so the owner is notified (GitHub never notifies anyone of
      their own comments, and every other board write uses the owner's token). Whoever receives the answer records it
      with `--answer "..."` and moves the task back to `in progress` (WIP permitting) or `todo`. Do not guess past an
      open owner question.
    - **Stop the line.** When the `tests` workflow fails on a push to the integration branch, the board workflow opens
      "[CI] The integration branch is red" (label `stop-the-line`) and closes it when the branch is green again. While
      it is open nothing moves to `done`: testers and the integrator check for it via MCP before approving or merging,
      and the next work is making the branch green (fix forward or revert). Pushes that change only `TASKS.md` and
      `planning/screenshots/` run no tests; the last run's verdict stands for them.
    - **One pull request per task.** Task code goes to `wip/Txx-<slug>` on `origin` with a draft PR to the integration
      branch via MCP (`Closes #<issue>`), never straight to the integration branch: only `set-status.sh` commits and
      `integrator:` commits go there directly. Pull requests run a fast CI subset (static, the stable leg
      PHP 8.3 / L13 / phpredis, the lowest leg PHP 8.2 / L11 / predis, Grafana assets on 13.2.4); the full matrix runs
      on the integration branch after the merge. All GitHub writes act as the owner's account, so testers post
      **COMMENT** reviews that start with `QA verdict: APPROVED` or `QA verdict: CHANGES REQUESTED` (GitHub refuses
      APPROVE and REQUEST_CHANGES on a PR of its own author). The integrator merges via MCP (merge commit), or locally
      with `--no-ff` and a push, only when the task is `done`, the PR checks are green and no `stop-the-line` issue is
      open.
    - **Dependencies and WIP limits.** `set-status.sh` first brings the main checkout up to date with origin (fetch and
      fast-forward), so its gates see merged PRs and other sessions' moves; it refuses `in progress`, `testing` and
      `done` while a dependency (heading tail `(depends on ...)` or the phase's `(all depend on ...)`) is not `done`,
      and a task entering In Progress at 2 tasks or Testing at 3 (`BOARD_WIP_IN_PROGRESS`, `BOARD_WIP_TESTING`). A QA
      rejection (`testing` -> `in progress` with `--qa "round N rejected by ..."`) always returns to In Progress.
      `--force --reason "why"` overrides; the commit records what was forced and why; use it rarely. The board labels
      todo tasks `ready` or `blocked` (with one comment naming the missing dependencies): take ready tasks only.
    - **Fields and report.** The board carries Phase, Area (`tools/board/areas.tsv`; add a line for every new task, the
      sync warns otherwise), QA rounds, Started and Finished. The pinned issue "[Board] Flow report" shows WIP against
      the limits, the CI state, open owner questions and per-task QA rounds, start, finish and cycle days with the
      rejection rate. QA lines say `round N approved by ...` or `round N rejected by ...`: the board counts each
      distinct round once (rejected when any line of it rejects).
    - **Screenshots.** Evidence images go in with `set-status.sh ... --attach <png>` (at most 1 MiB each; committed to
      `planning/screenshots/<Txx>/` and linked from the QA comment by commit, so the links outlive T15's deletion of
      `planning/`). Visual tasks (dashboard: T13, T15, T17, any change of `src/Grafana/**` or `resources/grafana/**`)
      attach Grafana 12.4.13 and 13.2.4 screenshots with every verdict; a visual verdict without both is a finding.

## Git and environment

- The integration branch is `claude/hopeful-hawking-njeu3v` on `origin` = `https://github.com/m0nclous/horizon-observability-wip`
  (private working repo; the owner will move it later). Task code goes to a task branch `wip/Txx-<slug>` pushed to
  `origin`, with a draft PR to the integration branch (decision 14, one pull request per task); only `set-status.sh`
  commits and `integrator:` commits go to the integration branch directly. Push only to `origin`, never to the
  `sushi-market` remote (`https://github.com/sushi-market/horizon-observability`), until the owner says so. The old
  base branch there is `master` (commit `3b260c9 init`); there is no `main`.
- Commit after every completed task (or meaningful step) and push the task branch to `origin`. Commit messages in English.
- Local prerequisites: PHP 8.3+ with `redis`, `pcntl`, `posix`, `pdo_sqlite`; Composer; Docker for Redis, Prometheus and
  Grafana; Node 20+ for screenshots.
  - Redis before T11 lands: `docker run -d --name ho-redis -p 6379:6379 redis:7.4-alpine redis-server --save "" --appendonly no`.
  - Screenshots: `npm i --no-save playwright@1.56.1 && npx playwright install chromium`.
  - Images: `grafana/grafana:12.4.13`, `grafana/grafana:13.2.4`, `quay.io/prometheus/prometheus:v3.15.0`.
- Lowest-version legs locally (CI does this once T11 lands; otherwise write "lowest legs: deferred to CI" in the task note):
  `cp -r . /tmp/ho-leg && cd /tmp/ho-leg && rm -rf vendor composer.lock && composer remove --dev --no-update larastan/larastan rector/rector driftingly/rector-laravel laravel/pint ergebnis/composer-normalize phpstan/extension-installer phpstan/phpstan-deprecation-rules && composer require --no-update "laravel/framework:^11.23" && composer require --dev --no-update "orchestra/testbench:^9.5" "pestphp/pest:^3.8" && composer update --prefer-lowest --prefer-dist -W && vendor/bin/pest --exclude-group=cluster --exclude-group=e2e`.
  Repeat with `^12.0` / testbench `^10.0` and `^13.0` / testbench `^11.0` (Pest `^4.1`, PHP 8.3+).
- `composer install` resolves Laravel 13 / testbench 11 / Pest 4 on PHP 8.3. CI covers the other legs.
- Cloud-sandbox-only workarounds (not needed locally): GitHub zipballs returned 403, so installs used
  `--prefer-install=source`, and `phpstan/phpstan` came from a global path repository; Docker Hub was rate-limited
  (Prometheus image from `quay.io/prometheus/prometheus`).
- Grafana screenshots with Playwright need `locale: 'en-US'` and `timezoneId: 'UTC'` (see `planning/reference/screenshot-playwright.mjs`).
- After `composer dump-autoload`, re-run `composer build` (testbench's purge-skeleton deletes the workbench SQLite DB).

## How to resume

1. `git clone https://github.com/m0nclous/horizon-observability-wip horizon-observability && cd horizon-observability &&
   git switch claude/hopeful-hawking-njeu3v` (or `git pull` in an existing checkout), `composer install`,
   `vendor/bin/pest` (should be green), read TASKS.md.
2. If a task is `in progress` but no session is working on it (no commit touching it in the last 24 h, or the owner says
   so), treat it as `todo` and resume it first; its "Progress" note says what is already done.
   Otherwise take the first ready task (`todo` with every dependency `done`; `tools/board/board.sh list` shows
   `[ready]`) and mark it with `tools/board/set-status.sh Txx "in progress"`. If the WIP limit refuses, help finish
   or test a task that is already in progress or testing.
3. Implement against its inputs; meet every acceptance item; run `vendor/bin/pest`, `vendor/bin/phpstan`, `vendor/bin/pint --test`.
4. Commit with a one-line progress note, push the task branch, open the draft PR and move the task to `testing` with
   `set-status.sh` (decision 14); it becomes `done` only through the QA gate.
5. Independent tasks of the same phase may run in parallel (separate agents/worktrees): they own disjoint files.

## Working alone or in parallel

- **Alone:** you are the integrator (Arch Q9 rule 2) and may edit the shared files; commit shared-file changes separately
  with the subject prefix `integrator:`. Task code still goes through its task branch and draft PR (decision 14).
- **Parallel:** one worktree per task: `git worktree add ../ho-Txx -b wip/Txx-<slug> claude/hopeful-hawking-njeu3v`,
  pushed to `origin` with a draft PR to the integration branch. Only the integrator edits TASKS.md (beyond
  `set-status.sh`) and the shared files, and merges a task's PR via MCP (merge commit), or locally with `--no-ff` and a
  push, only when the task is `done`, the PR checks are green and no `stop-the-line` issue is open (decision 14).
- **Phase review:** when a phase is done, a fresh agent reviews `git diff <first commit of the phase>^..HEAD` against each
  task's acceptance and Arch Appendix H (e.g. `/code-review high`), fixes the findings, and adds
  "Review: <date>, <n> findings fixed, <commit>" under the phase heading in TASKS.md.
