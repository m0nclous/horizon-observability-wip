# Upgrade guide

## From the pilot `sushi-market/horizon-prometheus-grafana` to 0.1

This guide moves an application from the internal pilot (`sushi-market/horizon-prometheus-grafana`, namespace
`SushiMarket\HorizonPrometheusGrafana`, metrics on spatie/laravel-prometheus' `/metrics` with the `office_` prefix) to
`sushi-market/horizon-observability` 0.1 (namespace `DF\HorizonObservability`). The examples use the pilot's office
setup: the metric prefix `office`, the dashboard uid `horizon` and an app reachable from Prometheus and Grafana as
`app:8080`. Replace those values with yours.

**What stays the same** with `HORIZON_OBSERVABILITY_NAMESPACE=office` and a render with `--uid=horizon`:

- 25 of the pilot's 29 metric families keep their names and label names (`office_queue_jobs_total`,
  `office_horizon_status`, ...); the four that change, and the changes in meaning, are listed in
  [Metric changes](#8-metric-changes).
- The dashboard keeps its uid, title and URL (`/d/horizon/queues`), so links, bookmarks and the Horizon "Grafana" link
  keep working.
- The alert rules keep their uids (`horizon-no-metrics`, `horizon-down`, `horizon-long-wait`, `horizon-failures`), titles,
  folder (`Horizon`) and group (`Queues`), so the `GRAFANA_ALERTS` history, the Alerts tile and the spoken alert names
  continue.
- The job lists keep their JSON fields, and the Grafana plugins are the same (Infinity, Business Text).

**What changes:** the package has its own endpoint, config file, environment variables, authentication and Redis keys;
Prometheus scrapes it with a new scrape job; the dashboard and the alert rules are rendered by an artisan command
instead of being copied from the package. The Prometheus series history breaks once (the `job` label changes, the
counters start again); see [One-time effects](#7-one-time-effects-announce-them).

### Order of the switch

1. [Prometheus](#5-prometheus): add the new scrape job. Its target is down until the app is deployed, which is harmless.
2. [The application](#1-replace-the-package): swap the packages, set the environment, deploy, restart Horizon.
3. [Grafana](#6-grafana): replace the dashboard and the alert rules, give the Infinity datasource the token.
4. [Verify](#9-verification-checklist).
5. A day later, [delete the pilot's Redis keys](#10-clean-up-the-pilots-redis-keys). Until then a
   [rollback](#rollback) loses nothing but the time in between.

Do not run both packages side by side: the pilot's dashboard and alert rules have no `job` scope, so they would add up
the `office_*` series of both scrape jobs.

### Before you start

- PHP `^8.2`, Laravel `^11.23 || ^12.0 || ^13.0` and Horizon `^5.29.1` (the pilot needed Laravel 12 and Horizon 5.40).
- Grafana 12.3 or later (tested on 12.4.13 and 13.2.4) with the plugins the pilot already used:
  `yesoreyeram-infinity-datasource` 4.1.1 or later and `marcusolsson-dynamictext-panel` 6.3.0 or later.
- As for the pilot: Prometheus runs with `--web.enable-remote-write-receiver` and Grafana writes its alert state history
  to Prometheus (`[unified_alerting.state_history]`), otherwise the Alerts tile stays at 0 and the dashboard stays silent.
- If Redis runs with ACLs, the app's Redis users need `+@read +@write +@scripting +time +info` on the package's keys
  (Horizon's `horizon` connection by default) and on the queues: the package runs `EVAL`, with `TIME` and `INFO` inside
  its scripts.
- Generate a token of at least 16 characters, for example `php -r 'echo bin2hex(random_bytes(20)), PHP_EOL;'`. The app,
  Prometheus and Grafana's Infinity datasource share it (or give Grafana its own, see
  [Routes and access](#3-routes-and-access)).

## 1. Replace the package

```bash
composer remove sushi-market/horizon-prometheus-grafana
composer remove spatie/laravel-data        # only if nothing else in the app uses it
composer require sushi-market/horizon-observability:^0.1
```

- `^0.1` stays on 0.1.x. In 0.x a breaking change is a minor release with an entry in this file.
- Until the package is on Packagist, add its GitHub repository to `composer.json` first:
  `"repositories": [{"type": "vcs", "url": "https://github.com/sushi-market/horizon-observability"}]`.
- Remove the pilot's own repository entry from `composer.json` (the path repository
  `{"type": "path", "url": "packages/horizon-prometheus-grafana"}`, or its `vcs` entry), delete
  `packages/horizon-prometheus-grafana`, and drop `COPY packages ./packages` from the `Dockerfile` when nothing else
  lives in `packages/`.
- Delete `config/horizon-prometheus-grafana.php` if it was published (tag `horizon-prometheus-grafana-config`).
- Keep `spatie/laravel-prometheus` if the app exports its own metrics on `/metrics`; the package does not use it, and its
  `'cache' => null` requirement no longer concerns the package. Remove any spatie Horizon or queue collector the app
  registers for the queues (`HorizonStatusCollector` and the like, e.g. in `app/Providers/PrometheusServiceProvider.php`):
  it would export `office_horizon_*` names on the app's `/metrics` next to the package's.
- The pilot's `queue-metrics:load-test` command and its job are gone. On non-production environments
  `php artisan horizon-observability:demo` dispatches a short burst of sample jobs; it refuses to run in production.
- Rebuild the caches on every server (`php artisan optimize:clear`, then `php artisan optimize`): a cached config of the
  pilot still lists its Horizon middleware class, which no longer exists.

## 2. Configuration and environment

The package reads `config/horizon-observability.php` (publish it with `php artisan vendor:publish
--tag=horizon-observability-config` only if you change a key without an environment variable). Use the same
environment for the web app and for Horizon.

| Pilot | 0.1 | Notes |
|---|---|---|
| `prometheus.default_namespace` (spatie) = `office` | `HORIZON_OBSERVABILITY_NAMESPACE=office` | Default `ho`. Required for continuity: every metric name, the dashboard and the alert rules depend on it. |
| `HPG_ENABLED=false` | `'collectors' => ['attempts' => false]` in the published config | No environment variable. `HORIZON_OBSERVABILITY_ENABLED` is the package's master switch (no routes, no listeners, no Horizon link), not the same thing. |
| `HPG_REDIS_CONNECTION` (default `default`) | `HORIZON_OBSERVABILITY_REDIS_CONNECTION` (default: Horizon's `horizon` connection) | Set it only if the package's keys must live elsewhere. |
| `HPG_PENDING_SCAN_LIMIT` (default 1000) | `HORIZON_OBSERVABILITY_QUEUE_SCAN_LIMIT` (default 1000) | `0`: no per-class breakdown (every pending job is `(unscanned)`), but the head job is still read for the oldest wait. |
| `HPG_GRAFANA_URL` | `HORIZON_OBSERVABILITY_GRAFANA_URL` | Same value: the dashboard URL stays `https://<grafana>/d/horizon/queues`. |
| `HPG_TTS_URL` | `HORIZON_OBSERVABILITY_TTS_URL` | Also accepts `browser` (the browser's speech synthesis, no server). |
| `HPG_PROMETHEUS_DATASOURCE_UID` (Grafana's environment) | `HORIZON_OBSERVABILITY_PROMETHEUS_UID` (Grafana's environment) | Read by the alert rules at provisioning; or render with `--datasource-uid=<uid>`. |
| `duration_buckets` | `buckets` | Same default. |
| `routes.prefix` = `metrics/horizon` | `HORIZON_OBSERVABILITY_PATH` | See [Routes and access](#3-routes-and-access). |
| `routes.middleware` = `[AllowIps::class]` (`PROMETHEUS_ALLOWED_IPS`) | `HORIZON_OBSERVABILITY_TOKEN`, `HORIZON_OBSERVABILITY_API_TOKEN`, `HORIZON_OBSERVABILITY_ALLOWED_IPS` | `PROMETHEUS_ALLOWED_IPS` keeps protecting the app's own `/metrics` only. `middleware` adds route middleware after authorisation, e.g. `['throttle:600,1']`. |

Remove every `HPG_*` variable from `.env`, `.env.example`, the deploy secrets and the Grafana service's environment.
The new variables:

```dotenv
HORIZON_OBSERVABILITY_NAMESPACE=office
HORIZON_OBSERVABILITY_TOKEN=<the token>
HORIZON_OBSERVABILITY_GRAFANA_URL=https://grafana.example.com/d/horizon/queues   # was HPG_GRAFANA_URL
HORIZON_OBSERVABILITY_TTS_URL=https://tts.example.com                            # was HPG_TTS_URL
# optional:
# HORIZON_OBSERVABILITY_PATH=metrics/horizon
# HORIZON_OBSERVABILITY_API_TOKEN=<a second token for Grafana>
# HORIZON_OBSERVABILITY_ALLOWED_IPS=10.0.0.5,10.201.0.0/16
```

Everything else has a default; the README describes the remaining keys (job class paths, the class cap, the exception
mode of the failed-jobs list, the live settings, the log channel).

## 3. Routes and access

| Pilot | 0.1 (default) | 0.1 with `HORIZON_OBSERVABILITY_PATH=metrics/horizon` |
|---|---|---|
| `GET /metrics` (spatie, shared with the app's metrics) | `GET /horizon/observability/metrics` | `GET /metrics/horizon/metrics` |
| `GET /metrics/horizon/{pending,completed,failed,delayed}-jobs` | `GET /horizon/observability/jobs/{pending,completed,failed,delayed}` | `GET /metrics/horizon/jobs/{pending,completed,failed,delayed}` |
| – | `GET /horizon/observability/live` (Live Overview) | `GET /metrics/horizon/live` |

- The default base path is `<horizon.path>/observability`. Keep the pilot's prefix with `HORIZON_OBSERVABILITY_PATH`
  when the nginx or ingress rule that closes `/metrics` to the outside should keep covering the endpoints; with the
  default, close `/horizon/observability` there if Horizon is reachable from outside. The dashboard reads the path from
  `office_horizon_info{api_path}`, so neither choice changes the render.
- **Access is closed by default.** Without a token (or IPs, or a `HorizonObservability::auth()` callback) the endpoints
  answer only in the `local` environment and return 403 everywhere else; the pilot was open while
  `PROMETHEUS_ALLOWED_IPS` was empty. Tokens come only from `Authorization: Bearer <token>`.
- `HORIZON_OBSERVABILITY_TOKEN` is accepted on every endpoint. When `HORIZON_OBSERVABILITY_API_TOKEN` is set, the job
  lists and `/live` accept only that one, so Grafana and Prometheus hold different secrets.
- `HORIZON_OBSERVABILITY_ALLOWED_IPS` is optional; with a token as well, both must pass. It is checked against
  `$request->ip()` like the pilot's `AllowIps`, so list Prometheus and Grafana as the app sees them (behind a proxy, the
  `X-Forwarded-For` address) and make sure TrustProxies trusts only your proxies.
- The routes run outside the `web` group (no session, no CSRF) and stay reachable during `php artisan down`.

## 4. Deploy

- `php artisan optimize` caches the config and the routes as before and now also the job classes
  (`horizon-observability:cache`, on Laravel 11.27.1 and later).
- Restart Horizon (`php artisan horizon:terminate`, the process manager starts it again): until then the workers run the
  pilot's code and keep recording into the pilot's keys.
- Add `php artisan horizon-observability:check` to the pipeline after the deploy. It exits 1 on errors (for example a
  production app without a token: every request would get 403) and prints one fix line per problem; `--strict` also
  fails on warnings, `--json` prints a machine-readable report.
- If `check` suggests a `read_timeout` for the Horizon connection while the redis queue connection sets `block_for`, keep
  `read_timeout` above `block_for` (known issue of 0.1, [#28]: the suggested 2 s makes longer blocking pops fail).

## 5. Prometheus

Add a scrape job for the package endpoint and keep the app's existing `office` job for its own metrics on `/metrics`:

```yaml
scrape_configs:
  - job_name: office-horizon               # the dashboard's prometheus_job; render with --job=office-horizon
    metrics_path: /horizon/observability/metrics   # /metrics/horizon/metrics with HORIZON_OBSERVABILITY_PATH=metrics/horizon
    scrape_interval: 10s                   # recommended; 5s to 60s are supported (the pilot used 15s)
    scrape_timeout: 8s
    authorization:
      type: Bearer
      credentials_file: /etc/prometheus/secrets/horizon-observability-token   # HORIZON_OBSERVABILITY_TOKEN
    static_configs:
      - targets: ['app:8080']              # ONE logical target: every replica reads the same Redis
```

- One logical target, as for the pilot: a Service, load balancer or one host. The dashboard and the rules deduplicate
  replicas, but every extra target repeats the Redis reads of a scrape.
- The relabel rules that dropped `office_(queue|horizon)_.*` from extra app targets are no longer needed: those names
  are not on `/metrics` any more.
- Do not reuse `job_name: office` for the new job: two scrape configs with the same `job` and `instance` collide on `up`.
- **Recording rules.** The pilot shipped none and the dashboard reads none. `php artisan horizon-observability:grafana
  --uid=horizon --job=office-horizon --recording-rules` also writes optional ones (attempts and durations per queue, e.g.
  `office:queue_jobs:sum_by_queue_status`) to `grafana/prometheus/horizon-observability.rules.yml` for `rule_files`. If
  you keep recording rules of your own on `office_*` families, give them the new `job` and the
  [metric changes](#8-metric-changes).

## 6. Grafana

### Render the dashboard and the alert rules

The package no longer ships office's files: render them from the app's config and commit the output in the
application repository.

```bash
HORIZON_OBSERVABILITY_NAMESPACE=office php artisan horizon-observability:grafana --uid=horizon --job=office-horizon
```

- It writes `grafana/dashboards/horizon-observability.json` and `grafana/alerting/horizon-observability.json` (the path
  argument defaults to `grafana`, relative to the app root). The metric prefix comes from
  `HORIZON_OBSERVABILITY_NAMESPACE`: without it the files query `ho_*` and stay empty. Run it with the config not cached.
- The defaults match the pilot: `--title=Queues`, `--folder=Horizon`, the Grafana default Prometheus datasource and the
  first Infinity datasource. `--prometheus-datasource=<name regex>` and `--infinity-datasource=<name regex>` pick others.
- `--scrape-interval` (default `10s`) must equal the scrape job's interval.
- `--live` chooses the Live Overview: `poll` (default) reads the app's `/live` endpoint at every dashboard refresh (5 s);
  `off` reads every tile from Prometheus and keeps the pilot's Overview exactly; `stream` updates the live tiles
  (Supervisor Processes, Pending, Delayed) every second from Grafana Live and needs
  `php artisan horizon-observability:stream` running (see the README).
- Run the same command with `--check` in CI: it exits 1 when the committed files differ from the render, e.g. after a
  package update. Render again after every update of the package.

### Replace the provisioned files

- Dashboard: provision `grafana/dashboards/horizon-observability.json` in place of the pilot's `queues.json` (in the
  office compose file: the Grafana mount of `./packages/horizon-prometheus-grafana/resources/grafana/dashboards` becomes
  `./grafana/dashboards`). Remove `queues.json`: both files carry the uid `horizon`. Point a home dashboard setting such as
  `GF_DASHBOARDS_DEFAULT_HOME_DASHBOARD_PATH` at the new file name.
- Alert rules: provision `grafana/alerting/horizon-observability.json` in place of `horizon.yml` (the
  `…/resources/grafana/alerting` mount becomes `./grafana/alerting`). Never keep both: they define the same rule uids.
  Grafana updates the rules in place; it reads alert rules again on a restart or
  `POST /api/admin/provisioning/alerting/reload`.
- Grafana's environment: `HORIZON_OBSERVABILITY_PROMETHEUS_UID=<uid of the Prometheus datasource>` replaces
  `HPG_PROMETHEUS_DATASOURCE_UID`. If you import the rules by hand instead of provisioning them, render with
  `--datasource-uid=<uid>` instead.
- The plugins and the alert state history settings stay as they are.

### Give the Infinity datasource the token

The job lists and the live tiles now need the bearer token. Keep the existing Infinity datasource (its name and uid do
not matter; the dashboard finds it by type) and add the authentication:

```yaml
apiVersion: 1
datasources:
  - name: App API
    type: yesoreyeram-infinity-datasource
    access: proxy
    url: http://app:8080                   # the app as Grafana reaches it
    jsonData:
      auth_method: bearerToken
      allowedHosts: ['http://app:8080']
      customHealthCheckEnabled: true
      customHealthCheckUrl: http://app:8080/horizon/observability/jobs/pending?limit=1
    secureJsonData:
      bearerToken: ${HORIZON_OBSERVABILITY_API_TOKEN}   # Grafana's environment: the app's API_TOKEN, or its TOKEN
```

In the UI: the datasource's Authentication is "Bearer Token", and Allowed hosts holds the app URL. The panels append
`${api_path}/jobs/<list>` and `${api_path}/live` to the datasource URL.

## 7. One-time effects (announce them)

- **The series history breaks once.** The package's series carry `job="office-horizon"` instead of `job="office"`, and
  the counters start again on new keys. The new dashboard (pinned to `office-horizon`) starts empty at the switch: a
  range across it shows only what happened after it, e.g. "Today" counts the attempts since the switch. The pilot's
  series stay in Prometheus under `job="office"` for its retention; read them in Explore. They end with the deploy.
- **First attempts show up late.** Every attempt series is new after the switch, and a new series reads 0 for its first
  90 seconds, then all its attempts at once, so that Prometheus and the dashboard count the first attempts. Right after
  the switch, and later for every new class or queue, counts appear up to 90 s late.
- **Batch links rebuild.** The Batch filter and `office_queue_batch_job_classes` / `office_queue_batch_info` know the
  queues and classes of a batch kind from jobs queued after the switch; the pilot's links are not read. The set is
  complete again after 7 days.
- **Waits of jobs delayed before the switch.** With Laravel 12.11+ and Horizon 5.49+ (`check` shows "Payload delay:
  native") they are exact. On older stacks the pilot's `ready_at` keys are not read, so the first wait of such a job
  includes its delay.
- **Alerts.** The rules now group by the Prometheus job as well (one alert per job, or per job and queue) and name it in
  their summaries. An alert that fires during the switch can resolve and fire again after its 5-minute `for`. The
  Alerts tile and the sound watch exactly the four rule uids above (plus `horizon-live` with `--live=stream`), no
  longer every `horizon-*` uid.
- **The dashboard with `--live=poll`** (the default): the Overview row is called "Live Overview" and shows the app's
  current values, and the dashboard refreshes every 5 s instead of 15 s. Accepted differences from the pilot's look: the
  Supervisor Processes tile has no per-supervisor sparkline, and the Completed caption "RPM: n" counts the last complete
  minute instead of the 5-minute average. `--live=off` keeps the pilot's look.
- Keep the time between the deploy and the Grafana switch short: the pilot's dashboard loses its job tables and every
  panel that reads `office_horizon_current_processes` or `office_queue_oldest_pending_job_age`.

## 8. Metric changes

Update your own queries, alerts and recording rules on `office_*` families:

| Pilot | 0.1 | What to do |
|---|---|---|
| every family, `job="office"` | `job="office-horizon"` | Update `job` matchers. |
| `office_horizon_current_processes{queue}` | removed | Use `sum by (queue) (office_horizon_supervisor_processes)`. |
| `office_queue_oldest_pending_job_age` | `office_queue_oldest_pending_job_age_seconds` | Rename. New meaning: seconds the oldest ready first-attempt job has waited since it became available (`max(pushedAt, createdAt + delay)`), or since the earliest due delayed job became due; 0 when nothing waits. Retried and released jobs no longer count, and a job dispatched with a delay counts from when it became due. The series is omitted when jobs are ready but none in the scanned head is a first attempt and nothing is due. |
| `office_horizon_info{url, tts_url}` | `office_horizon_info{url, api_path, tts_url}` | `api_path` is the endpoints' base path. |
| `office_queue_batch_progress{batch, name, status, size, created, id}` | without `size` and `created` | Drop them from queries. |
| `job_class="(не просмотрено)"`, `"(неизвестно)"` | `(unscanned)`, `(unknown)` | New `(other)`: classes beyond `max_job_classes` (500) in the attempt families, beyond the top 100 per queue in `office_queue_jobs_pending` / `_reserved`, batch kinds beyond 100. `Closure (routes/web.php:42)` becomes `Closure (routes/web.php)`. |
| `office_queue_job_duration_seconds_*`, `office_queue_job_wait_seconds_*` | `# TYPE histogram` | Nothing: the series are the same, only the metadata was wrong (three counter families). |
| `office_queue_job_duration_min_seconds`, `_max_seconds` | the last 150 s | Was the current and the previous minute. `min_over_time` / `max_over_time` over a range can include attempts up to 150 s before its start. |
| `office_queue_jobs_total` and the two histograms | a new series reads 0 for 90 s | See [One-time effects](#7-one-time-effects-announce-them). |
| – | `office_horizon_supervisor_queues{supervisor, queue, pool}` | New: the queues of each supervisor, for `balance => false` pools. |
| – | `office_exporter_*` | New self-metrics: `collector_success`, `collector_duration_seconds`, `build_info`, `config_problems`, `attempts_dropped_total`, `live_push_timestamp_seconds`, `live_push_failures_total`. |

## 9. Verification checklist

1. `php artisan horizon-observability:check` exits 0 on a production server, and shows:
   - Configuration: Namespace `office_* metrics`, Problems `none`;
   - Access: Mode `token` (or `token + IPs`, or `metrics: token; api: api_token`), not `locked`;
   - Routes: Precedence `/horizon/observability/{metrics,jobs/*,live} answer the package routes` (or `/metrics/horizon/…`);
   - Storage: the connection is reachable; Horizon: Status `running (…)`, Grafana link `active: https://…/d/horizon/queues`;
   - Batches: `database: table job_batches_office on connection …` (the app's `queue.batching.table`);
   - Job classes: Cache file `written …`; Collectors: every collector with its families and samples, no error.
2. `curl -s -H "Authorization: Bearer $TOKEN" http://app:8080/horizon/observability/metrics | grep exporter_collector_success`
   (or `/metrics/horizon/metrics`) prints `1` for every collector; the same request without the header answers 401 (send both from an allowed address
   if you set IPs).
3. In Prometheus: `up{job="office-horizon"}` is 1, `scrape_duration_seconds{job="office-horizon"}` is well below 8 s, and
   `count by (job) ({__name__=~"office_(queue|horizon)_.+"})` returns `office-horizon` only.
4. In Grafana: `/d/horizon/queues` shows data in the Overview tiles; the Failed, Completed, Pending and Delayed Jobs
   tables have rows (the Infinity datasource's health check passes); the Horizon link opens this environment's Horizon.
5. Alerting lists the four rules in the folder Horizon with their old uids (Normal unless something is wrong); the
   Alerts tile shows the history from before the switch.
6. Horizon's sidebar shows the "Grafana" link after "Dashboard".
7. A job that runs now appears on the Completed tile and in the Completed Jobs table. Right after the switch, the
   Prometheus-based numbers of a class's first attempts can take up to 90 s more.

## 10. Clean up the pilot's Redis keys

After a day, when a rollback is no longer needed, delete the pilot's keys. They live on the pilot's
`HPG_REDIS_CONNECTION` (default `default`, database `REDIS_DB`, 0 by default) under the app's Redis prefix:

```bash
php artisan tinker --execute='echo config("database.redis.options.prefix"), PHP_EOL;'   # the <REDIS_PREFIX>
# -n 0: the database number (REDIS_DB) of the pilot's connection
redis-cli -n 0 --scan --pattern '<REDIS_PREFIX>prometheus:queue:*' | xargs -r redis-cli -n 0 del
```

Write the prefix out in full and do not start the pattern with `*`: on a shared Redis `*prometheus:queue:*` also matches
other applications' keys. Add `-h`, `-p` and `-a` as your Redis needs. The package never reads these keys.

## Rollback

Before the [cleanup](#10-clean-up-the-pilots-redis-keys) the pilot's counters are still in Redis, so a rollback only
misses the attempts of the time in between:

1. Optionally delete the new keys first: `php artisan horizon-observability:flush --force`.
2. Revert the application's switch commit (`composer.json`, `composer.lock`, `packages/`, the `Dockerfile`, the config
   file) and its environment (the `HPG_*` variables back, the `HORIZON_OBSERVABILITY_*` ones out), deploy, rebuild the
   caches (`php artisan optimize:clear`, then `php artisan optimize`) and run `php artisan horizon:terminate`. The
   `office_*` metrics are back on `/metrics` under `job="office"`.
3. Prometheus: remove the `office-horizon` job.
4. Grafana: provision the pilot's `queues.json` and `horizon.yml` again in place of the rendered files, and restore
   `HPG_PROMETHEUS_DATASOURCE_UID` and the home dashboard path. The rule uids are the same, so the history continues. The
   Infinity datasource can keep its bearer token: the pilot's endpoints ignore it.

<!-- The issue links of this file, in one place: change the repository here when it moves. -->
[#28]: https://github.com/m0nclous/horizon-observability-wip/issues/28
