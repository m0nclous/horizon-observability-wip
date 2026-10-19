# Contributing

Thank you for helping. This file covers the development setup, the checks every change must pass and how CI runs
them.

- Bugs and feature requests: open an issue from its template. The bug report asks for your versions and the output of
  `php artisan horizon-observability:check --json`.
- Questions: [Discussions](https://github.com/sushi-market/horizon-observability/discussions).
- Security problems: never in a public issue, see [SECURITY.md](SECURITY.md).
- Everyone who takes part follows the [code of conduct](CODE_OF_CONDUCT.md).

## Requirements

- PHP 8.2 or newer with the extensions `redis` (phpredis), `pcntl`, `posix`, `pdo_sqlite` and `mbstring`. There is no
  committed `composer.lock`, so `composer install` resolves the newest supported Laravel for your PHP: 13 on PHP 8.3
  and newer, 12 on PHP 8.2.
- Composer 2.
- Docker with Compose v2, for the development stack (Redis, Prometheus, Grafana) and for the private Redis servers
  some tests start.
- Node 20 or newer with Playwright 1.56.1 (`npm i --no-save playwright@1.56.1 && npx playwright install chromium`),
  only for the screenshot and performance tools in `bin/` and the end-to-end checks.
- Optional: `promtool` from Prometheus 3.15.0, for the tests that lint or evaluate PromQL (see
  [Test groups](#test-groups)).

## Setup

```sh
git clone https://github.com/sushi-market/horizon-observability.git
cd horizon-observability
composer install
composer build
```

The package is developed inside an [Orchestra Testbench](https://packages.tools/testbench) workbench: a Laravel app
defined by `testbench.yaml` and `workbench/` (a Horizon topology with three supervisors, sample job classes and the
load generator). `composer build` creates and migrates its SQLite database. Composer's `post-autoload-dump` hook purges
the skeleton, which deletes that database, so run `composer build` again after every `composer install`, `update` or
`dump-autoload`.

## Development loop

With PHP on the host:

```sh
docker compose up -d     # Redis, Prometheus (scrapes host.docker.internal:8000 every 10 s) and Grafana
composer serve           # composer build, then the workbench on 0.0.0.0:8000
composer horizon         # Horizon of the workbench
composer load            # workbench:simulate-load --continuous: a production-like job stream until Ctrl+C
```

- Grafana: http://localhost:3000/d/horizon-observability (anonymous viewers; `admin`/`admin` logs in). It provisions
  the committed render in `resources/grafana` read-only, so the stack shows what users install; change the dashboard
  in `src/Grafana` (see [below](#changing-the-dashboard-and-the-alert-rules)).
- Horizon: http://localhost:8000/horizon, with the "Grafana" link in its sidebar.
- The exposition:
  `curl -H 'Authorization: Bearer workbench-token-0123456789abcdef' http://localhost:8000/horizon/observability/metrics`
  (the workbench token of `testbench.yaml`, which the dev Prometheus reads from `docker/prometheus/token`).
- `vendor/bin/testbench workbench:simulate-load --help` lists the load options: a one-shot burst by default,
  `--continuous` for a stream (`--duration` ends it), and `--queue`, `--scale` and `--seed` for both.

Variations, all from the header of `docker-compose.yml`:

- Everything in containers (the app image runs PHP 8.4, `APP_PHP_VERSION` picks 8.2 to 8.5):
  `PROMETHEUS_CONFIG=prometheus.app.yml APP_URL_FROM_GRAFANA=http://app:8000 docker compose --profile app up -d`.
- Grafana 12: `GRAFANA_IMAGE=grafana/grafana:12.4.13 docker compose up -d`. Both 12.4.13 and 13.2.4 are supported and
  tested.
- Valkey: `REDIS_IMAGE=valkey/valkey:8-alpine`.
- Live Overview in stream mode: `docker/grafana/bootstrap-live.sh` (creates the Grafana service account and writes
  `docker/grafana/live-token`), then `docker compose --profile app up -d stream`. The `stream` service has its own
  profile, so `--profile app` alone leaves it out, and it waits for the token file.
- Several stacks side by side: set `COMPOSE_PROJECT_NAME` and the host ports `REDIS_PORT`, `PROMETHEUS_PORT`,
  `GRAFANA_PORT`, `APP_PORT` and `PERF_PROMETHEUS_PORT`. Every port listens on 127.0.0.1 only (Redis has no password
  and Grafana allows anonymous access); `BIND_ADDRESS=0.0.0.0` publishes them on every interface.

**Redis on another port with PHP on the host.** `testbench.yaml` points the workbench at 127.0.0.1:6379. A
`REDIS_PORT` in your shell reaches the artisan commands (`composer horizon`, `composer load`), but `composer serve`
only sees it when PHP's `variables_order` contains `E`. Add that one line through an extra ini directory; the leading
`:` keeps PHP's default scan directory, and with it your extensions:

```sh
mkdir -p build/php && echo 'variables_order = "EGPCS"' > build/php/env.ini
PHP_INI_SCAN_DIR=":$PWD/build/php" REDIS_PORT=16379 composer serve
```

The app image of the containerised stack sets the same line.

### Performance profile

`docker/perf/README.md` describes the repeatable dashboard measurement: synthetic history backfilled into a separate
Prometheus (`docker/perf/backfill.sh`, compose profile `perf`), a continuation exporter
(`vendor/bin/testbench workbench:perf-exporter`, port 8391), a Playwright capture of real dashboard refreshes, a
PromQL replay and a report against the budget in `docker/perf/budget.json` (`node bin/perf.mjs capture|replay|report`).

## Checks

Every change passes these before it is pushed, with a Redis on 127.0.0.1:6379 (`docker compose up -d redis` is
enough):

```sh
vendor/bin/pest                         # phpredis
REDIS_CLIENT=predis vendor/bin/pest     # predis
vendor/bin/phpstan analyse --memory-limit=1G
vendor/bin/pint --test
vendor/bin/rector --dry-run
```

The Composer scripts wrap the same tools:

| Script | Runs |
|---|---|
| `composer test` | `test:lint`, `test:types`, then Pest without the `cluster` and `e2e` groups |
| `composer test:lint` | Pint and Rector in check mode |
| `composer lint` | Pint and Rector, applying their changes |
| `composer test:types` | PHPStan (Larastan, level 9) on `src`, `config`, `routes` and `workbench/app` |
| `composer test:unit` | Pest without the `redis`, `cluster` and `e2e` groups (no Redis needed) |
| `composer test:redis` | Pest, `redis` group only |
| `composer test:predis` | Pest, `redis` group only, on predis |
| `composer test:grafana` | `horizon-observability:grafana --check`: the committed Grafana assets equal the render |

CI also runs `composer validate --strict` and `composer normalize --dry-run`.

The tests take the Redis address and client from `REDIS_HOST`, `REDIS_PORT` and `REDIS_CLIENT` (defaults in
`phpunit.xml.dist`: 127.0.0.1, 6379, phpredis). Every key a test process writes starts with `hot_<pid>:` and is
removed with `SCAN` and `DEL`, never `FLUSHDB`, so a shared development Redis is safe. A full run opens thousands of
connections; for several runs at once on Linux, give Redis host networking instead of a published port (Docker's
proxy runs out of ephemeral ports at about three parallel runs), and use one checkout per run: runs in the same
checkout collide on Testbench's route cache.

### Test groups

| Group | What | Needs |
|---|---|---|
| `redis` | Every test that talks to Redis: most integration and feature tests | Redis or Valkey at `REDIS_HOST`:`REDIS_PORT` |
| `truth` | The exporter truth suite (also in `redis`): deterministic scenarios through real workers and a real `testbench horizon`, compared with an independent model, see [tests/Truth/README.md](tests/Truth/README.md) | Redis; for the restart tests a private Redis (below) |
| `cluster` | Package storage, queues, live data, `check` and `flush` on a Redis Cluster | A cluster node at `REDIS_CLUSTER_HOST`:`REDIS_CLUSTER_PORT` (default 127.0.0.1:7000) holding all slots; skipped without one |
| `grafana` | The renderer in `src/Grafana`: PromQL, alert rules, live panels, layout and performance contracts | `PROMTOOL` for the tests that evaluate PromQL with `promtool test rules`; skipped without it |
| `e2e` | Checks against a running stack | `FIRST_DASHBOARD_GRAFANA_URL` (a running `install --docker` stack), or `GRAFANA_URL` and `APP_URL` (plus `GRAFANA_AUTH`, default `admin:admin`) as in the CI e2e job; skipped without them |

- **Private Redis.** Tests that restart Redis or change its configuration start their own server: a `redis-server`
  binary on the `PATH`, or Docker with the image in `TRUTH_REDIS_IMAGE` (default `redis:7.4-alpine`). They are skipped
  locally without either and fail on CI (`CI` set). They never touch the shared 127.0.0.1:6379.
- **promtool.** `PROMTOOL` is a command, for example
  `PROMTOOL="docker run --rm -i --entrypoint promtool quay.io/prometheus/prometheus:v3.15.0"`, or `promtool` on the
  `PATH`. Without it the golden exposition lint and the PromQL evaluation tests are skipped.
- **A Redis Cluster node** as CI starts it:

  ```sh
  docker run -d --name ho-cluster -p 127.0.0.1:7000:7000 -p 127.0.0.1:17000:17000 redis:7.4-alpine \
    redis-server --port 7000 --cluster-enabled yes --cluster-announce-ip 127.0.0.1 --save '' --appendonly no
  until docker exec ho-cluster redis-cli -p 7000 ping | grep -q PONG; do sleep 1; done
  docker exec ho-cluster redis-cli -p 7000 CLUSTER ADDSLOTSRANGE 0 16383
  until docker exec ho-cluster redis-cli -p 7000 CLUSTER INFO | grep -q cluster_state:ok; do sleep 1; done
  vendor/bin/pest --group=cluster && REDIS_CLIENT=predis vendor/bin/pest --group=cluster
  ```

- **Time to first dashboard.** `tests/e2e/first-dashboard.sh [--grafana 12|13]` follows the README quick start on a
  fresh Laravel app, installing this checkout's `git archive` (`PACKAGE_REF`, default `HEAD`), and fails when the
  dashboard does not show data within 5 minutes. See its header for the environment it needs. Leave `PACKAGE_PATH`
  unset for a checkout: it copies the checkout's git-ignored `vendor/` into the app. The app uses the Redis on
  127.0.0.1:6379 with the key prefixes of a new Laravel app and deletes nothing, so a second run starts with the
  queues and counters of the first: run it against an empty Redis when the tile values matter.

Expected skips on a typical machine: the `cluster` tests, the `e2e` tests, the promtool tests without `PROMTOOL` and
a few tests for other Laravel or Horizon versions. CI runs all of them.

**Honest numbers.** Every exported value and every number on the dashboard must equal the ground truth. A change to a
collector, a Lua script, `src/Grafana` or a dependency keeps the truth suite green. A new metric family needs its row
in the coverage table of `tests/Truth/README.md`; `tests/Truth/CoverageTest.php` fails otherwise.

### Lowest versions

The CI matrix also tests the oldest supported versions. To reproduce such a leg, work in a copy of the checkout (the
commands rewrite `composer.json`), for example the Laravel 11 leg, which CI runs on PHP 8.2:

```sh
composer remove --dev --no-update larastan/larastan rector/rector driftingly/rector-laravel laravel/pint \
  ergebnis/composer-normalize phpstan/extension-installer phpstan/phpstan-deprecation-rules
composer require --no-update "laravel/framework:^11.23"
composer require --dev --no-update "orchestra/testbench:^9.5" "pestphp/pest:^3.0" "predis/predis:^2.0.2"
COMPOSER_POLICY_ADVISORIES_BLOCK=0 composer update --prefer-lowest --prefer-dist
vendor/bin/pest --exclude-group=cluster --exclude-group=e2e
```

`COMPOSER_POLICY_ADVISORIES_BLOCK=0` lets Composer 2.10 install the old releases that have security advisories (CI
lifts only that policy). The other legs are in the `include` list of `.github/workflows/tests.yml`.

## Changing the dashboard and the alert rules

The dashboard and the alert rules are rendered by PHP (`src/Grafana`, command `horizon-observability:grafana`).
Never edit the JSON in `resources/grafana` by hand. After a change in `src/Grafana`, render the committed files again
(Testbench resolves relative paths against its skeleton, so pass absolute ones):

```sh
vendor/bin/testbench horizon-observability:grafana "$PWD/resources/grafana" --defaults --recording-rules
vendor/bin/testbench horizon-observability:grafana "$PWD/tests/Fixtures/grafana/off" --defaults --live=off
vendor/bin/testbench horizon-observability:grafana "$PWD/tests/Fixtures/grafana/stream" --defaults --live=stream
composer test:grafana && vendor/bin/pest --group=grafana
```

The Grafana of the dev stack picks up the new dashboard within 10 seconds, and the alert rules on a restart. The panel layout is pinned in
`tests/Fixtures/grafana/default/layout-projection.json`. A visual change comes with screenshots before and after from
`node bin/screenshot.mjs` on Grafana 12 and 13; a change that can affect performance comes with a
`node bin/perf.mjs report` against `docker/perf/budget.json`.

## Conventions

- Everything is English: code, comments, log lines, HELP texts, label values and docs. The PHP and Lua files in
  `src`, `config`, `routes`, `resources/lua` and `resources/views` are ASCII only.
- `declare(strict_types=1)` in every PHP file, and every package class is `final`. Pint and the architecture tests
  (`tests/Architecture/ArchTest.php`) enforce these and more: `env()` only in `config/`, no `Redis` facade in `src`,
  collectors independent of `Http`, no debugging calls.
- The runtime dependencies are PHP, Laravel and Horizon only. Open an issue before you add one.
- Classes marked `@internal` may change in any release. The public contract is the configuration (keys and
  `HORIZON_OBSERVABILITY_*` variables), the endpoints and their JSON, the metric names, labels and label values, the
  dashboard uid and variables, the `HorizonObservability` hooks, and the commands and their options. Renaming or
  removing any of them, or changing a path default or a JSON field, is a breaking change: in 0.x a minor release with an
  entry in `UPGRADE.md`, from 1.0 a major one. Deprecate in a minor and remove in the next major.

## Commits and pull requests

- Commit subjects are English, imperative and without a type prefix, for example
  `Count released attempts per queue`. The body says why, and what you measured when it matters. One logical change
  per commit; keep pure formatting out of behaviour changes.
- Open pull requests against `master` and fill in the template: tests for the change, an entry in the unreleased
  section at the top of `CHANGELOG.md` ([Keep a Changelog](https://keepachangelog.com/en/1.1.0/)), breaking changes
  also in `UPGRADE.md`.
- User-facing behaviour is documented in `README.md`.

## How CI is organised

`.github/workflows/tests.yml` runs on pushes to `master`, on pull requests, by hand and every Monday:

| Job | Checks |
|---|---|
| `tests` | Pest without `cluster` and `e2e` on PHP 8.2 to 8.5 and Laravel 11, 12 and 13 (13 from PHP 8.3, 11 up to PHP 8.4), plus legs for the lowest versions (each checks that it resolved its floor, e.g. Laravel 11.23.0 with Horizon 5.29.1 and predis 2.0.2), predis 2 and 3, Redis 6.2, 7.4 and 8 and Valkey 7.2 and 8. The static tools are removed so the resolution stays honest. |
| `cluster` | The `cluster` group on a one-node Redis Cluster, with phpredis and predis |
| `static` | `composer validate --strict`, `composer normalize --dry-run`, Pint, Rector, PHPStan |
| `coverage` | Pest with pcov and a minimum of 85 % coverage, with `PROMTOOL` set |
| `grafana` | On Grafana 12.4.13 and 13.2.4: `composer test:grafana`, the `grafana` group with `PROMTOOL`, `promtool check rules` on every rendered expression, and a provisioning smoke test of the dev stack (the dashboard, 4 alert rules, both plugins, the Prometheus datasource health, no provisioning errors) |
| `dist` | `git archive HEAD` contains only `src/`, `config/`, `routes/`, `resources/`, `composer.json`, `LICENSE`, `README.md`, `CHANGELOG.md` and `UPGRADE.md` |
| `first-dashboard` | Weekly and by hand: `tests/e2e/first-dashboard.sh` on Grafana 12 and 13 |
| `e2e` | Weekly and by hand: two app replicas, Horizon and ten minutes of simulated load, screenshots, the `e2e` group, the replica dedupe check and the performance report (the last two do not fail the job yet, [#37]) |

`.github/workflows/release.yml` runs on a `vX.Y.Z` tag: it requires a non-empty `CHANGELOG.md` section for the version,
runs `composer validate --strict` and `composer test:grafana`, and creates the GitHub release from that section.
Packagist updates through its webhook. Dependabot updates the GitHub Actions weekly.

The dist archive is what Composer installs: everything for development (`.github`, `bin`, `docker`, `tests`,
`workbench`, the tool configurations and these community files) is `export-ignore`d in `.gitattributes`. Check it with
`git archive HEAD | tar -t`.

<!-- The issue links of this file, in one place: change the repository here when it moves. -->
[#37]: https://github.com/m0nclous/horizon-observability-wip/issues/37
