# Handoff: rewrite of the Horizon metrics pilot into `sushi-market/horizon-observability`

This folder lets any agent (cloud or local) resume the rewrite at any point without the original chat.
Read this file first, then [TASKS.md](TASKS.md), then the sections of [ARCHITECTURE.md](ARCHITECTURE.md)
that the next task points to. Everything here is export-ignored and will be deleted before the first release (task T15).

## Where things are

| Path | What |
|---|---|
| `planning/TASKS.md` | Ordered task list with status, scope, inputs, owned files and acceptance criteria. **The source of truth for progress.** |
| `planning/ARCHITECTURE.md` | Approved final architecture (Q1–Q9 + appendices A–H: config file, metric catalog, frozen interfaces, Lua contracts, Redis keys, compat matrix, office migration, bug→test map). |
| `planning/ADDENDUM.md` | Approved additions R1–R4 (namespace `ho`, scrape interval, dashboard performance, Live Overview). **May be missing** if the cloud session ended before it was written: then task T01 recreates it from the R1–R4 spec in TASKS.md. Where it conflicts with ARCHITECTURE.md, the addendum wins. |
| `planning/audit/` | Audit of the legacy pilot: findings (`findings-*.md`: couplings F01–F29, design A01–A28, bugs C1–C26 with verdicts, dashboard, tooling), the dashboard contract (`dashboard-contract.md`), verified tool versions and dev recipes (`tooling-notes.md`). Paths like `/tmp/claude-0/...` in these files point to the cloud sandbox and no longer exist. |
| `planning/baseline/` | Screenshots of the **current** dashboard (Grafana 13.2.4, workbench data). The rewrite must keep this look; compare final screenshots against them. |
| `planning/reference/` | Audit-time helpers: Playwright screenshot script, Prometheus/Grafana provisioning used to render the baseline. |
| `legacy/` | The pilot code (old namespace `SushiMarket\HorizonPrometheusGrafana`), its docs, Python dashboard generator and composer.json. Reference only: not autoloaded, excluded from tools. Deleted in T15. |
| `tests/Fixtures/grafana/legacy/` | Golden files: `queues.json` (dashboard), `horizon.yml` (alert rules), `alert-sound.js` (panel #23 script). The PHP port of the generator must first reproduce them (T10). |

## Owner decisions (do not re-litigate)

1. Composer package `sushi-market/horizon-observability`, root namespace `DF\HorizonObservability`, license MIT.
2. Everything public is **English only**: code, comments, HELP texts, label values, logs, README, docs.
3. Supported versions: PHP `^8.2`; Laravel `^11.23|^12.0|^13.0` (11.23 is the first with `JobAttempted`);
   Horizon `^5.22`. No other runtime dependencies (no spatie/laravel-prometheus, no spatie/laravel-data, no promphp).
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
10. Execution mode: full plan with parallel agents and independent reviews per phase.
11. Releases: `0.x` until office runs the package in production, then `1.0.0`.

## Git and environment

- Work on branch `claude/hopeful-hawking-njeu3v`. Remote `origin` = `https://github.com/m0nclous/horizon-observability-wip`
  (private working repo; the owner will move it later). Remote `sushi-market` = the org repo, no push access from the cloud.
- Commit after every completed task (or meaningful step) and push to `origin`. Commit messages in English.
- Local prerequisites: PHP 8.3+ with `redis`, `pcntl`, `posix`, `pdo_sqlite`; Composer; Redis on 127.0.0.1:6379 (or
  `docker compose up -d redis` once T11 lands); Docker for Prometheus/Grafana; Node + Playwright Chromium for screenshots.
- `composer install` resolves Laravel 13 / testbench 11 / Pest 4 on PHP 8.3. CI covers the other legs.
- Cloud-sandbox-only workarounds (not needed locally): GitHub zipballs returned 403, so installs used
  `--prefer-install=source`, and `phpstan/phpstan` came from a global path repository; Docker Hub was rate-limited
  (Prometheus image from `quay.io/prometheus/prometheus`).
- Grafana screenshots with Playwright need `locale: 'en-US'` and `timezoneId: 'UTC'` (see `planning/reference/screenshot-playwright.mjs`).
- After `composer dump-autoload`, re-run `composer build` (testbench's purge-skeleton deletes the workbench SQLite DB).

## How to resume

1. `git pull`, `composer install`, `vendor/bin/pest` (should be green), read TASKS.md.
2. Take the first task whose status is `todo` and whose dependencies are `done`. Mark it `in progress` in TASKS.md, commit.
3. Implement against its inputs; meet every acceptance item; run `vendor/bin/pest`, `vendor/bin/phpstan`, `vendor/bin/pint --test`.
4. Mark it `done` with a one-line note (date, commit), commit and push.
5. Independent tasks of the same phase may run in parallel (separate agents/worktrees): they own disjoint files.
