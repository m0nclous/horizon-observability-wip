# Office-specific couplings (F01-F29)

Audit of the legacy pilot (now in `legacy/`), 2026-10-16. File:line references point to the legacy layout (`src/...` = `legacy/src/...`).

## Summary

I read every file in the repository, nothing was modified, and the only scratch files are under /tmp/claude-0/-home-user-horizon-observability/cc6491ff-4b68-5ae7-bac7-d93fef57187c/scratchpad/audit/. Hardcoded "office" assumptions fall into four groups.

1. **Package identity.** The old package name, the SushiMarket namespace and the HPG_ env prefix are in composer.json, all 33 PHP files, the config file name and keys, the view namespace, the alert YAML and the docs. composer.json also declares the license as proprietary.

2. **Metric prefix.** office_ is baked into the Grafana assets: 751 times in queues.json, 7 times in horizon.yml, plus the generator's default. The PHP side silently inherits the app-wide prometheus.default_namespace, which is "app" in a fresh spatie install. On top of that, four of the package's metric names (queue_pending_jobs, queue_delayed_jobs, queue_oldest_pending_job_age, horizon_current_processes) are the same as spatie's own optional collectors. Apps that enable those collectors get silently overwritten values.

3. **Dashboard and alerts are tied to office's deployment:**
   - The JSON-list URLs /metrics/horizon/* are hardcoded, while the route prefix they must match is configurable.
   - No query filters by job or instance, so several apps scraped by one Prometheus get merged.
   - The dashboard uid "horizon" and the "office" tag are fixed.
   - The alert rules file is written by hand, so its thresholds, rule-uid prefix and panel ids are duplicates that can drift from the dashboard.
   - It assumes a 15s scrape interval, the Piper TTS protocol and hidden "first datasource of this type" variables.

4. **Runtime assumptions:**
   - spatie's prometheus.cache must be null, but nothing checks it.
   - The metrics endpoint is assumed to be /metrics, while spatie's default is /prometheus.
   - The job-list routes always use AllowIps instead of following prometheus.middleware.
   - Job classes are found only under app_path().
   - The package edits Horizon's config (horizon.middleware) and parses Horizon's HTML.
   - Batches are read straight from the database table.
   - Redis keys use the generic prometheus:queue:* prefix, and the multi-key Lua scripts would fail on Redis Cluster.

Russian text reaches end users through the job_class label values "(не просмотрено)" and "(неизвестно)", the metric help texts, the log messages, the load-test output and the docs. The docs also contain office-only facts: REDIS_QUEUE_DB=3, config/trustedproxy.php, PROMETHEUS_ALLOWED_IPS, office job names, app:8080 and job_name office.


## F01 [high] The old package name, SushiMarket namespace, HPG_ env prefix and a proprietary license are everywhere

- **Location:** composer.json:2,3,5,6,8,16,22; src/**/*.php:5 (namespace line in all 33 files) plus every `use SushiMarket\...`; src/HorizonPrometheusGrafanaServiceProvider.php:34,36,53-57; config/horizon-prometheus-grafana.php (file name) :8,19,22,32,45-47,52; src/QueueJobMetricsSubscriber.php:69; src/QueueJobMetricsStorage.php:418,473; src/RedisQueueStateReader.php:164; src/Prometheus/HorizonInfoCollector.php:17,28; src/Http/Middleware/AddGrafanaLinkToHorizon.php:13,27,43; resources/views/grafana_link.blade.php:1; resources/grafana/alerting/horizon.yml:5,23,54,87,121; resources/grafana/generate_queues_dashboard.py:1935; README.md:1,27,31-39,46,50-58,105,388; docs/extraction.md:19-22,79-83; docs/cleanup.md:48
- **Detail:** The package is named sushi-market/horizon-prometheus-grafana, its PSR-4 root is SushiMarket\\HorizonPrometheusGrafana, and the provider class is HorizonPrometheusGrafanaServiceProvider. The same old name is reused as the config file, the config key, the publish tag (horizon-prometheus-grafana-config) and the view namespace (horizon-prometheus-grafana::).

The config key is written out as a string literal in 6 files instead of reusing the provider's CONFIG constant, so renaming is error-prone.

The env vars use the HPG_ prefix: HPG_ENABLED, HPG_REDIS_CONNECTION, HPG_PENDING_SCAN_LIMIT, HPG_GRAFANA_URL and HPG_TTS_URL. The Grafana-side env var in the alert provisioning file is HPG_PROMETHEUS_DATASOURCE_UID.

composer.json has `license: proprietary`, a hardcoded `version: 1.0.0` that will conflict with git tags, a Russian description and `php: ^8.2`. A proprietary license plus a hardcoded version blocks Packagist and public use.
- **Recommendation:** Rename in one pass:
- composer name `<vendor>/horizon-observability` (e.g. df/horizon-observability; confirm the vendor), PSR-4 root `DF\\HorizonObservability\\`, provider `HorizonObservabilityServiceProvider`.
- Config file and key `horizon-observability`, publish tag `horizon-observability-config`, view namespace `horizon-observability`.
- Env prefix `HORIZON_OBSERVABILITY_*`; on the Grafana side use something like HORIZON_OBSERVABILITY_PROMETHEUS_DS_UID.
- Put the config key in one class constant (or a `Config` value object) used everywhere.
- composer.json: `license: MIT`, remove `version`, `php: ^8.3`, `laravel/framework: ^12.0`, English description and keywords.

## F02 [high] The office_ prefix is baked into the dashboard and alerts, while the PHP side inherits the app-wide spatie namespace (default 'app')

- **Location:** resources/grafana/dashboards/queues.json (751 occurrences of office_); resources/grafana/generate_queues_dashboard.py:3,5,17,24,26,41,70,86,110-140,168-189,207,282-283,294-295,520-553,664-669,680-695,806,915-916,1018-1073,1146-1158,1275-1277,1333-1344,1717-1778,1923-1946,1996-1997; resources/grafana/alerting/horizon.yml:26,42,57,91,92,125,126; src/Prometheus/QueueStateCollector.php:50; README.md:7,44,76,80,133,150,158,166-218,282-283,296; docs/cleanup.md:11-48; docs/backlog.md:13; docs/research.md:34,52
- **Detail:** No collector passes a namespace, so metric names get the app-global `prometheus.default_namespace`. In spatie's shipped config this is 'app' (checked against upstream config/prometheus.php). The committed dashboard and alerts assume `office_`, so in any other project the imported dashboard shows "No data". The 'No queue metrics' alert fires forever and the other alerts stay silent.

The generator default is `--prefix office` (py:17). It works by writing `office_` as a sentinel and regex-replacing it at the end (py:207, 1996-1997). I re-ran it into the scratchpad:
- The committed JSON is byte-identical to the generator output, so the generator is a reliable source.
- `--prefix ""` emits broken names like `_queue_pending_jobs`.
- `--prefix app` still leaves the `office` tag.

The alert YAML has no generator at all; docs/extraction.md:68 tracks this as open item 1. A help text also hardcodes `office_queue_delayed_due_jobs` (QueueStateCollector.php:50).
- **Recommendation:** PHP side:
- Add package config `metrics.namespace` (env HORIZON_OBSERVABILITY_METRICS_NAMESPACE; null means fall back to `prometheus.default_namespace`).
- Pass it explicitly as the `$namespace` argument of every `Prometheus::addGauge`/`addCounter` call; spatie supports this.

Generator:
- Replace the `office_` sentinel with an explicit placeholder token.
- Default `--prefix` to `app` and support an empty prefix without a leading underscore.
- Emit tags from flags.
- Generate horizon.yml with the same prefix.

Better still, keep one JSON for any prefix: add a hidden constant/textbox variable `metric_prefix` and write selectors as `${metric_prefix}_queue_pending_jobs` (including the `__name__=~` regex at py:664). Grafana interpolates variables into metric names.

## F03 [high] Four metric names collide with spatie's own Horizon/Queue collectors, and the package re-registers HorizonStatusCollector

- **Location:** src/Prometheus/QueueStateCollector.php:28,48,61; src/Prometheus/HorizonWorkloadCollector.php:22; src/HorizonPrometheusGrafanaServiceProvider.php:10,30-32,73; docs/cleanup.md:40
- **Detail:** These package metrics use the same name, namespace and label set as optional spatie collectors:
- `queue_pending_jobs{connection,queue}` = spatie QueuePendingJobsCollector
- `queue_delayed_jobs{connection,queue}` = QueueDelayedJobsCollector
- `queue_oldest_pending_job_age{connection,queue}` = QueueOldestPendingJobCollector
- `horizon_current_processes{queue}` = CurrentProcessesPerQueueCollector

spatie's install stub (resources/stubs/PrometheusServiceProvider.php.stub) offers `registerHorizonCollectors()` and `registerQueueCollectors()`. Office deleted those calls (docs/cleanup.md:40); a typical project keeps them. promphp's getOrRegister then returns the same gauge to both collectors, and their samples silently overwrite each other even though they mean different things: the package's pending includes delayed-due jobs, spatie's does not. The dashboard numbers become silently wrong.

The package also unconditionally registers spatie's HorizonStatusCollector (provider:73). The app may register it too. Per the provider's own docblock (:30-32), this collector turns the app's whole shared /metrics into a 500 when Horizon's Redis is down.
- **Recommendation:** Give package metrics names nobody else uses: either a dedicated sub-namespace (e.g. `<ns>_horizon_observability_*`) or renamed metrics (e.g. `queue_ready_jobs` for the pending+due gauge). The dashboard's look does not depend on metric names, so this is safe.

Replace the spatie HorizonStatusCollector with the package's own `horizon_status` gauge read through ScrapeCache, so it never throws. Alternatively make it a toggle, `collectors.horizon_status`.

Document any remaining overlap with spatie's collectors.

## F04 [high] Unchecked spatie config contracts: cache must be null, the endpoint is assumed to be /metrics, job lists hardcode AllowIps

- **Location:** config/horizon-prometheus-grafana.php:8-11,35-40; src/Prometheus/QueueJobsCollector.php:14-19; src/HorizonPrometheusGrafanaServiceProvider.php:94-101; README.md:7-8,43,111-116; docs/extraction.md:35-36,71
- **Detail:** **cache.** QueueJobsCollector publishes absolute Redis values with `inc()`, which is only correct when `prometheus.cache` is null. If the app configures a cache store, counters grow on every scrape. Nothing checks this; it is only mentioned in comments.

**URL.** The docs and config comments assume the metrics endpoint is `/metrics` ("close the whole /metrics prefix in nginx"), and the route prefix defaults to `metrics/horizon`. spatie's default is `urls.default = 'prometheus'`, i.e. `/prometheus`, so the co-location and the nginx advice only hold for office's customisation.

**Access.** `routes.middleware` is hardcoded to `[AllowIps::class]` instead of following `prometheus.middleware`. If an app protects /prometheus with basic auth or other middleware, the job-list endpoints stay open; AllowIps with an empty `allowed_ips` allows everyone. Those endpoints expose class names and exception messages.

The docs' `PROMETHEUS_ALLOWED_IPS` env var is office's own wiring: spatie's config has no env() for `allowed_ips`.
- **Recommendation:** - In boot(), if `config('prometheus.cache') !== null`, throw in debug and log once otherwise, or skip QueueJobsCollector.
- Default `routes.prefix` to `trim(config('prometheus.urls.default', 'prometheus'), '/') . '/horizon'`.
- Default `routes.middleware` to `config('prometheus.middleware', [AllowIps::class])`.
- Add a `routes.enabled` toggle.
- Rewrite the access section of the docs generically, without PROMETHEUS_ALLOWED_IPS.

## F05 [high] Infinity URLs in the dashboard hardcode /metrics/horizon/*, but the route prefix is configurable

- **Location:** resources/grafana/generate_queues_dashboard.py:1376,1428,1444,1457,1465,1473; resources/grafana/dashboards/queues.json:3310,3508,3684,3876; config/horizon-prometheus-grafana.php:39; src/HorizonPrometheusGrafanaServiceProvider.php:95-100; src/DTO/GetHorizonJobsDTO.php:32; src/Http/Requests/GetHorizonJobsRequest.php:40
- **Detail:** The four tables (Failed, Completed, Pending and Delayed Jobs) call `/metrics/horizon/{failed,completed,pending,delayed}-jobs?limit=500...` as relative URLs on an Infinity datasource whose Base URL must be the app. The PHP routes take their prefix from config (`routes.prefix`). If an app changes the prefix, or follows the spatie URL as proposed in F04, all four tables break silently.

`limit=500` is duplicated between the generator (py:1428) and the DTO default (DTO:32); the request caps it at 1000 (Request:40).

The routes have no names, and no test ties the dashboard to the routes.
- **Recommendation:** Send the API base path through Prometheus, the same way `horizon_url` already reaches the dashboard: add an `api_path` label to `horizon_info` and a hidden variable `${api_path}` used in the Infinity URLs. Alternatively add a generator flag `--api-prefix`.

Name the routes (e.g. `horizon-observability.jobs.failed`). Add a test that parses the generated JSON and asserts that every Infinity URL matches a registered route.

## F06 [high] No dashboard query or alert filters by Prometheus job, instance or environment

- **Location:** resources/grafana/generate_queues_dashboard.py:192-207 (filtered() injects only queue/job_class), 1917-1951; resources/grafana/dashboards/queues.json:4552-4576 (0 job/instance selectors in the whole file); resources/grafana/alerting/horizon.yml:26,42,57,91-92,125-126; README.md:68,76-83,85
- **Detail:** Every selector is unscoped, e.g. `office_queue_pending_jobs`, `count(office_horizon_status)` and `group by (url) (last_over_time(office_horizon_info[7d]))`. That was fine for office, which has one app per Prometheus.

In a shared Prometheus, other projects collide: several apps that keep spatie's default 'app' namespace, or staging and prod of the same app. Their queues, supervisors and batches are merged and their counters summed. The `horizon_url` and `tts_url` variables pick an arbitrary app. The 'No queue metrics' alert never fires while any other app is scraped.

README:76-83 only addresses duplicates across instances of the same app, by relabel-drop.
- **Recommendation:** Add a scope variable, e.g. `job` from `label_values(<prefix>_horizon_status, job)` (single-select; a generator flag could choose the label: job, instance or namespace). Have `filtered()` inject `job=~"$job"` into every package selector; the function already rewrites selectors, so the change is mechanical.

In the alerts, aggregate `by (job, queue)` and put `{{ $labels.job }}` in the summaries. Scope the `horizon_url` and `tts_url` variable queries the same way.

## F07 [high] Russian placeholder values in the job_class label reach the dashboard UI

- **Location:** src/RedisQueueStateReader.php:26-27,198-212,217-226; src/Prometheus/QueueStateCollector.php:37; config/horizon-prometheus-grafana.php:29; README.md:189
- **Detail:** `UNSCANNED = '(не просмотрено)'` ("not scanned") and `UNKNOWN = '(неизвестно)'` ("unknown") are exported as `job_class` values of `queue_jobs_pending` and `queue_jobs_reserved`. They show up as rows in the Jobs table, as series in the 'Pending by job: $queue' legend and tooltip, and as options in the Job filter. Non-Russian users see Cyrillic in an otherwise English dashboard.

The generator never references these literals, so changing them does not affect the dashboard JSON.
- **Recommendation:** Use English sentinels, e.g. `(unscanned)` and `(unknown)`, as public constants; optionally allow overriding them via config `labels.unscanned` / `labels.unknown`.

Add an UPGRADE note: office will see the old series end and new series start under the new value.

## F08 [medium] All metric help texts are Russian, and one hardcodes the office_ prefix

- **Location:** src/Prometheus/QueueStateCollector.php:29,37,43,49-50,56,62-63; src/Prometheus/QueueJobsCollector.php:33,39,45,51,58,84,90; src/Prometheus/QueueBatchesCollector.php:38,44,54,62-63,73,79,87-88,94,100-101; src/Prometheus/HorizonSupervisorsCollector.php:26-27,34,40; src/Prometheus/HorizonWorkloadCollector.php:23; src/Prometheus/HorizonWaitsCollector.php:24; src/Prometheus/HorizonInfoCollector.php:26; src/Prometheus/QueueJobClassesCollector.php:21
- **Detail:** Every `# HELP` line served on the metrics endpoint is in Russian. These texts also appear in Grafana's metric browser and in other tools such as Mimir and Thanos. QueueStateCollector.php:49-50 names the sibling metric with the office prefix: '(office_queue_delayed_due_jobs)'.
- **Recommendation:** Write English help texts. Refer to sibling metrics without the namespace (e.g. 'see queue_delayed_due_jobs').

Keep the metric definitions (name, help, labels) in one registry class so the README metric table can be generated from it, or tested against it.

## F09 [medium] Russian log and exception messages with the legacy 'QueueMetrics:' tag, logged on every scrape

- **Location:** src/Prometheus/ScrapeCache.php:37; src/Prometheus/QueueJobsCollector.php:107; src/RedisQueueStateReader.php:142,171,237; src/QueueJobMetricsSubscriber.php:101,168,245,285; src/Jobs/QueueLoadTestJob.php:87,94
- **Detail:** 'QueueMetrics:' is the name of the module this code came from in office (app/Services/QueueMetrics, see docs/extraction.md:79). Messages such as 'не удалось собрать метрики' ("failed to collect metrics") and 'Lua-скрипт вернул ошибку' ("the Lua script returned an error") land in host apps' logs and Sentry.

ScrapeCache logs a warning on every scrape for every failing source, i.e. every 15 s for a missing table or wrong namespace (see F10 and F13). There is no option for log channel, level or throttling.
- **Recommendation:** - English messages with a stable prefix (`[horizon-observability]`) or a `package` key in the log context.
- Config `logging.channel` (null means the default channel).
- Throttle repeated scrape failures per source (e.g. one warning per source per 10 minutes via the cache).
- Exceptions in English.

## F10 [medium] Job-class discovery only scans app_path() under the app namespace

- **Location:** src/QueuedJobClasses.php:13-23,43,46,58-73; src/Prometheus/QueueJobClassesCollector.php:21-26; README.md:205; docs/extraction.md:46,70
- **Detail:** `Finder::create()->in(app_path())->contains('ShouldQueue')` maps file paths to `app()->getNamespace()`. Problems in other projects:
- Queueable classes outside app/ (modules/, domain/, src/, other packages) are missing from `queue_job_classes`, so the Jobs table's never-run rows and the Job filter are incomplete.
- `getNamespace()` throws RuntimeException when composer.json does not map app_path. ScrapeCache swallows it, so the metric is empty and a warning is logged every scrape.
- The text pre-filter misses jobs that only extend a base class implementing ShouldQueue.
- `class_exists()` autoloads every candidate file inside the web request that serves the metrics endpoint.
- **Recommendation:** Add config:
- `job_classes.enabled`
- `job_classes.paths`: a path => namespace map, defaulting to `[app_path() => app()->getNamespace()]`
- `job_classes.include` and `job_classes.exclude` (explicit class lists or patterns)

Make the text pre-filter optional and rely on reflection. Offer `php artisan horizon-observability:cache-job-classes` to build the list at deploy time.

## F11 [medium] The package always edits Horizon's middleware config and injects the menu link by parsing Horizon's HTML

- **Location:** src/HorizonPrometheusGrafanaServiceProvider.php:46-48; src/Http/Middleware/AddGrafanaLinkToHorizon.php:12-18,21-22,27,34-47; resources/views/grafana_link.blade.php:1-9; docs/extraction.md:41,60
- **Detail:** `config()->push('horizon.middleware', AddGrafanaLinkToHorizon::class)` runs in register() unconditionally, even when `grafana_url` is empty. Every Horizon request then passes through it, including the JSON API.

The link is spliced in after the first `</li>` following `class="col-2 sidebar"`, and the view relies on Horizon's Bootstrap classes (`nav-item nav-link d-flex align-items-center`). Any Horizon UI change removes the link silently.

The label 'Grafana' is hardcoded, and the blade comment names the old namespace.
- **Recommendation:** Push the middleware only when a URL is configured and `horizon_link.enabled` is true. Add `horizon_link.label`. Keep the markers as class constants.

Add a Testbench test that renders the installed Horizon layout and asserts the link is present, so a markup change fails CI instead of silently dropping the link. Make the view publishable.

## F12 [medium] The Horizon URL is guessed from app.url + horizon.domain/path, and the TTS URL travels from app config

- **Location:** src/Prometheus/HorizonInfoCollector.php:10-19,24-28,31-41; config/horizon-prometheus-grafana.php:49-52; resources/grafana/generate_queues_dashboard.py:1917-1951; resources/grafana/dashboards/queues.json:4552-4576; README.md:169
- **Detail:** The dashboard's 'Horizon' link and the batch and job links (`${horizon_url}/batches/...`, `/jobs/...`) are built from app.url, with a fallback scheme of 'https', plus `horizon.domain` and `horizon.path`. This breaks when app.url is an internal address (e.g. a container URL, as office's Prometheus target app:8080 suggests), or when Horizon is served under another host or path prefix.

`tts_url` is a browser-side concern for Grafana, yet it must be configured in the Laravel app and is exported as a Prometheus label.
- **Recommendation:** Add config `horizon_url` (env HORIZON_OBSERVABILITY_HORIZON_URL) that overrides the derivation, which stays as the fallback.

On the dashboard, make `tts_url` (and optionally `horizon_url`) a variable that defaults to the metric value but can be overridden by provisioning or the URL.

Optionally export package version and build info labels in the same metric.

## F13 [medium] The batch collector assumes the database batch driver, a migrated table and regular pruning

- **Location:** src/Prometheus/QueueBatchesCollector.php:14-28,31-32,226-269 (query 233-242),281-285; src/QueueJobMetricsSubscriber.php:272; docs/extraction.md:42; docs/cleanup.md:24
- **Detail:** It queries `DB::connection(config('queue.batching.database'))->table(config('queue.batching.table'))` directly. With the dynamodb batching driver, or without the job_batches migration, this throws on every scrape and logs every time (see F09).

The WHERE clause ORs on `finished_at` and `cancelled_at`, which have no index in Laravel's default migration. That means a full scan of job_batches on every scrape for apps that do not prune.

The windows are hardcoded: CLOSED_WINDOW_SECONDS=86400, matching the `queue:prune-batches` default of 24h that office presumably schedules, and RECENT_PER_KIND=200.

`Bus::findBatch()` runs on every batch JobQueued event (Subscriber:272). Office's custom table name `job_batches_office` appears in the docs.
- **Recommendation:** Add config `batches`:
- `enabled`: auto-detect `queue.batching.driver === 'database'` plus `Schema::hasTable`, cached
- `window_seconds`
- `recent_per_kind`

Where possible use `Illuminate\Bus\BatchRepository`. Document scheduling `queue:prune-batches` and an optional index on (finished_at, cancelled_at). Remove `job_batches_office` from the docs.

## F14 [low] queue_batch_progress has timezone-dependent and unused labels (created, size)

- **Location:** src/Prometheus/QueueBatchesCollector.php:102-104,297-298; resources/grafana/generate_queues_dashboard.py:1060-1065; README.md:218
- **Detail:** `created` is formatted in app.timezone as 'Y-m-d H:i:s T', while README:218 claims it is UTC. `size` is total_jobs, which changes while a batch is still being filled, so the series churns.

The dashboard never reads either label: it aggregates by (batch, name, status, id) and takes Created from `queue_batch_created_timestamp_seconds`. The JSON has 0 references to them.
- **Recommendation:** Drop the `created` and `size` labels, or, if you keep `created`, emit it as ISO-8601 UTC. The dashboard's look is unaffected.

## F15 [medium] Generic Redis key prefix prometheus:queue:* and multi-key Lua that fails on Redis Cluster

- **Location:** src/QueueJobMetricsStorage.php:35-41,68-81,85-92,120-126,212-217; src/RedisQueueStateReader.php:158-167; config/horizon-prometheus-grafana.php:21-22; README.md:120-130,222-231
- **Detail:** Storage keys such as `prometheus:queue:jobs_total` are generic. They can collide with other apps on a shared Redis database without REDIS_PREFIX, or with other Prometheus tooling.

The EXTREMES and RESET scripts and the queue-state script pass several keys with no common hash tag, which raises CROSSSLOT on Redis Cluster (e.g. ElastiCache in cluster mode). Office runs standalone Redis.

README:222 also refers to a config key that no longer exists, `queue_metrics.redis_connection` (left over from office).
- **Recommendation:** Add config `storage.prefix`, defaulting to `{horizon-observability}:`. The hash tag keeps all package keys in one slot.

Document that on Cluster the queue names must carry hash tags so a queue, its `:reserved` and its `:delayed` keys share a slot. Fix the stale config key in the README.

## F16 [medium] Queues are identified by name only; the connection is dropped

- **Location:** resources/grafana/generate_queues_dashboard.py:24,110-119,282-283 (and every `by (queue)` aggregation); resources/grafana/alerting/horizon.yml:91-92,125-126; src/Prometheus/HorizonSupervisorsCollector.php:114-117; src/HorizonJobs/HorizonJobsService.php:152-155; src/Prometheus/HorizonWorkloadCollector.php:20-24; README.md:157
- **Detail:** Collectors strip 'connection:' from the queue label, and the dashboard and alerts group `by (queue)`. Office has a single connection, `redis`. An app with the same queue name on two connections (e.g. `redis:default` and `redis-long:default`) gets its queue rows, utilisation and alerts merged.
- **Recommendation:** Either document the single-connection assumption, or carry the connection through: group `by (connection, queue)` wherever the metric has the label, and show 'connection:queue' only when more than one connection exists (a generator flag, e.g. `--per-connection`). This keeps the current look for single-connection apps.

## F17 [medium] Thresholds and lookback windows are hardcoded in the generator and duplicated by hand in the alerts

- **Location:** resources/grafana/generate_queues_dashboard.py:74,94 (1d), 131-132,148-150 (70/90 %), 284-285,296-297,917 (1x/2x wait), 441-442,596-597 (0.9/0.99 success), 751 (2x overload), 805 (uptime 99), 862,877 (min 100 attempts, 1%/5%), 1046 (topk 20), 1508-1510 (90s, 15m), 1571 (5 min sound repeat), 1718-1757 (1d), 1923-1946 (7d), 168-175 (dead STATUS_EXPR 300/60/0.5), 402 (stale comment); resources/grafana/alerting/horizon.yml:102,125-126,136,40/71/105/139 (for 5m)
- **Detail:** Each threshold is a literal at its own place in the generator. Some are repeated in horizon.yml (2x wait at yml:102, 5% at yml:136, min 100 attempts at yml:126, 15m at yml:125-126), so they can drift apart; office's own values are documented in docs/backlog.md:20.

The 1d and 7d lookbacks appear 86 times in the JSON. STATUS_EXPR is defined but never used, with its own 300 s / 60 s / 0.5 thresholds and a stale comment at :402.
- **Recommendation:** Move all tunables into one settings block (a dict or YAML/JSON file passed with `--config`) that both outputs read: dashboard JSON and alert YAML. Keep the current values as defaults so the dashboard looks the same. Delete STATUS_EXPR.

Optionally expose the wait multipliers as hidden constant variables in Grafana.

## F18 [medium] The dashboard assumes a 15 s scrape interval

- **Location:** resources/grafana/generate_queues_dashboard.py:102-109 (BUSY_WINDOW), 1811-1813 (refresh 15s), 1984; resources/grafana/dashboards/queues.json:4370 and 12 occurrences of [15s]; README.md:84-85; docs/backlog.md:18
- **Detail:** Utilization (the table bar and the Supervisor Processes tile colour) averages over `[15s]`. With a 30 s or 60 s scrape interval, which is common, the window can be empty and the column shows '—'. The docs tell users to edit BUSY_WINDOW in the Python code. The refresh interval of 15 s is tuned to office's scrape interval.
- **Recommendation:** Add a generator flag `--scrape-interval`: BUSY_WINDOW = max(15s, 2×interval) and default refresh = interval. Alternatively derive the window from `$__rate_interval` or an interval variable. Document it as a setting, not a code edit.

## F19 [medium] Dashboard uid 'horizon', title 'Queues' and the 'office' tag are fixed, and other files depend on them

- **Location:** resources/grafana/generate_queues_dashboard.py:1815,1982,1986-1987; resources/grafana/dashboards/queues.json:4372-4375,4597-4598,4612-4613; resources/grafana/alerting/horizon.yml:43,44,75,76,109,110,143,144; config/horizon-prometheus-grafana.php:44-47; README.md:3,57; docs/cleanup.md:3
- **Detail:** uid 'horizon' is generic and clashes with other Horizon dashboards; two apps cannot get two copies in one Grafana. The 'office' tag is written regardless of `--prefix`.

The alerts link to the dashboard through `__dashboardUid__: horizon` and `__panelId__` values '1', '2' and '4', i.e. internal generator panel ids.

The config example points the Horizon menu link at `http://localhost:3000/d/horizon/queues` and mentions office's GRAFANA_PORT docker variable (config:46).
- **Recommendation:** Add generator flags `--uid`, `--title` and `--tags`, defaulting to e.g. 'horizon-observability', 'Horizon Queues' and ['horizon', 'queues'], without 'office'. Generate the alert annotations from the same uid, looking panel ids up by panel title. Use a neutral example URL in the config.

## F20 [high] The alert rules file is hand-written for office's Grafana and coupled to the dashboard by a uid regex

- **Location:** resources/grafana/alerting/horizon.yml:1-6,10-13,17,23,26,42-44,48,54,57,75-76,81,87,91-92,102,109-110,115,121,125-126,136,143-144; resources/grafana/generate_queues_dashboard.py:1501-1515; resources/grafana/dashboards/queues.json:86,1013,1025,1037; README.md:87,94-104,278-289
- **Detail:** What is hardcoded in horizon.yml:
- the `office_` prefix (7 places)
- `${HPG_PROMETHEUS_DATASOURCE_UID}` (4 places)
- orgId 1, folder 'Horizon', group 'Queues', interval 1m; the dashboard's ALERTS_WINDOW of 90s (py:1508) silently assumes the 1m interval
- rule uids `horizon-*`
- dashboard uid and panel ids
- thresholds copied from the generator (F17)

The dashboard's Alerts tile and sound count `GRAFANA_ALERTS{grafana_rule_uid=~"horizon-.*"}`. Any other rule in the Grafana whose uid starts with 'horizon-' is counted too.

There is no job scoping (F06). The Alerts tile and sound also depend on Grafana alert state history being written to Prometheus via remote write.
- **Recommendation:** Generate horizon.yml from the same generator, with the same prefix, scope variable, thresholds, dashboard uid and panel ids.

Add flags `--alert-uid-prefix` (default e.g. `hzobs-`), `--alert-folder`, `--org-id`, `--alert-interval` (and derive ALERTS_WINDOW from it), and the datasource-uid env var name.

In the dashboard, select GRAFANA_ALERTS by the exact list of generated rule uids (`grafana_rule_uid=~"a|b|c|d"`) instead of a prefix regex.

## F21 [medium] Hidden datasource variables silently pick the default or first Prometheus and Infinity datasource

- **Location:** resources/grafana/generate_queues_dashboard.py:21,1381,1952-1980; resources/grafana/dashboards/queues.json:4381-4389,4584-4590; README.md:91,93
- **Detail:** `datasource` (type prometheus) and `app_api` (type yesoreyeram-infinity-datasource) are both `hide: 2`. Office has one of each. Grafanas with several Prometheus datasources (prod/staging, Mimir) or several Infinity datasources get the default one, with no way to switch in the UI.
- **Recommendation:** Make both variables visible, or add generator flags `--prometheus-ds-regex` and `--infinity-ds-regex` (the `regex` field of datasource variables). Optionally emit the `__inputs` and `__requires` blocks so Grafana's import dialog asks for the datasources.

## F22 [medium] Sound and speech are tied to the Piper TTS API and English; the panel JS in the JSON has Russian comments; plugin and Grafana versions are implicit

- **Location:** resources/grafana/generate_queues_dashboard.py:1562-1703 (1599,1601,1607,1651-1655,1702),1814; resources/grafana/dashboards/queues.json:62,96,4371; config/horizon-prometheus-grafana.php:49-52; src/Prometheus/HorizonInfoCollector.php:17-18,26-28; README.md:58,86,92,105-106,289
- **Detail:** Speech requires a Piper HTTP server: `POST {tts_url}/synthesize` with `{text}` returning WAV. The phrase rewriting ('2x' becomes 'twice the') is English-only.

The Business Text panel (`marcusolsson-dynamictext-panel`) carries about 100 lines of JS with Russian comments, embedded verbatim in the dashboard JSON (queues.json:62), so users see them when they edit the panel. It uses the global `window.__horizonAlertSound`.

The dashboard has no `__requires`, so Grafana's import does not warn about the missing Business Text and Infinity plugins. The required versions (Infinity 4.1.1, Business Text 6.3.0, schemaVersion 42, i.e. a recent Grafana; Prometheus 2.33+) are only mentioned in the README, as office's pinned versions.
- **Recommendation:** Keep the behaviour, but:
- Add a speech-provider option: piper (current), webspeech (browser `speechSynthesis`, no server), none.
- Translate the JS comments to English and namespace the global.
- Emit `__requires` with the plugin ids, minimum versions and minimum Grafana version.
- Document versions as minimums, not as office's pins.

## F23 [medium] Generator CLI defaults, the office_ sentinel, dead code and office-specific comments

- **Location:** resources/grafana/generate_queues_dashboard.py:1-7,15-18,168-175,207,402,456,693,832,1093-1182,1493,1996-1997
- **Detail:** The usage text and argparse help are in Russian, and the default is `--prefix office`. The `office_` sentinel is used both inside `filtered()` (py:207) and in the final replace (py:1996-1997), which causes the broken empty-prefix output and the leftover tag (F02).

Dead code: STATUS_EXPR (168-175) and the full `batches_table` panel (1093-1182) are built but never placed (1493).

Comments describe office's setup:
- 'у slow и slow_reports он общий' ("slow and slow_reports share it", 693)
- 'horizon:snapshot, у нас раз в 5 минут' ("we run horizon:snapshot every 5 minutes", 832)

All comments are in Russian.
- **Recommendation:** Rewrite the CLI in English with proper flags (prefix, uid, title, tags, scope label, api prefix, scrape interval, TTS mode, output paths for the dashboard and the alerts). Use a neutral placeholder token. Remove the dead code and translate the comments.

Add a snapshot test: generating with office-equivalent flags must reproduce today's JSON apart from the identity fields. That proves the dashboard's look is unchanged.

## F24 [medium] The load-test command and job hardcode office-like queues and job names and ship in every consuming app

- **Location:** src/Console/QueueLoadTestCommand.php:13-23,31-56,63-67,73-98,120-124,136-144,148,163,228,236-251,285-360,332-335; src/Jobs/QueueLoadTestJob.php:13-30,87,94; src/HorizonPrometheusGrafanaServiceProvider.php:65-67; README.md:302-326; docs/cleanup.md:48
- **Detail:** The scenario keys are office's queues: default, slow and slow_reports. The burst always targets 'default' (:332-335). Task names come from office's domain (TestSendSmsJob, TestOrderSyncJob, TestPaymentCheckJob, TestGeocodeJob, TestFinanceReportJob...).

The command name `queue-metrics:load-test` is the old internal name, all output is Russian, and the only guard is `isProduction()`. The command is registered in every app's console and the job class sits in production autoload, although the docs call both temporary ('remove after QA').
- **Recommendation:** Move the command and job to the Testbench workbench (autoload-dev) as a dev tool for screenshots and metrics testing.

If it stays public: rename it to `horizon-observability:load-test`, map scenario tiers to the user's queues (`--queues=fast:default,slow:reports`), use neutral task names and English output, and register it only when `config('horizon-observability.load_test.enabled')` is true.

## F25 [medium] Grafana variable names are the HTTP API contract of the job-list endpoints

- **Location:** src/Http/Requests/GetHorizonJobsRequest.php:10-25,44-55,65-78; resources/grafana/generate_queues_dashboard.py:1382,1768; README.md:237
- **Detail:** The endpoints accept only Grafana `queryparam` syntax: `var-supervisor`, `var-batch_kind`, `var-queue` and `var-job_class`, repeated per value, with `$__all` meaning no filter. The names must match the dashboard's variable names (FILTER_ORDER).

Renaming a variable in the dashboard silently drops that filter: the parameter is ignored and unfiltered data comes back. Other API clients must mimic Grafana's syntax.
- **Recommendation:** Share the variable to field map in one place: a PHP constant, plus a generator test that asserts the same names.

Accept neutral parameters too (`supervisor[]=`, `queue[]=`...) next to the `var-` form. Have the endpoint answer 422 on unknown `var-*` parameters, or warn, instead of silently ignoring them.

## F26 [low] Horizon internals: Redis key names, UI routes, provisioning-plan logic and the 'horizon' Redis connection

- **Location:** src/Repositories/HorizonJobsRepository.php:56,77,102,139,198; src/Http/Resources/HorizonCompletedJobResource.php:24; src/Http/Resources/HorizonPendingJobResource.php:22; src/Http/Resources/HorizonFailedJobResource.php:28; resources/grafana/generate_queues_dashboard.py:1236,1388; src/HorizonQueues.php:13-27,29-32; src/Prometheus/HorizonSupervisorsCollector.php:112; docs/extraction.md:56-62
- **Detail:** The code relies on Horizon internals:
- Redis sorted sets `pending_jobs`, `completed_jobs` and `failed_jobs`, plus the per-job hashes, read directly via `Redis::connection('horizon')`. The alias is registered by Horizon::use().
- SPA routes `jobs/pending/{id}`, `jobs/completed/{id}`, `failed/{id}` and `batches/{id}`.
- `HorizonQueues` re-implements `ProvisioningPlan::deploy()`, picking the environment from `horizon.env ?? app.env`. A Horizon started with `--environment=` is therefore not seen.

None of this is office-specific, but nothing tests it either: any Horizon release may break it silently.
- **Recommendation:** Put these behind one HorizonAdapter class. Add a Testbench test matrix against the supported laravel/horizon versions, constrain composer accordingly, and use Horizon contracts (JobRepository, SupervisorRepository) wherever they suffice. Document the `--environment` limitation, or add config `horizon_environment`.

## F27 [low] The Supervisor Processes tile assumes supervisor names start with 'supervisor-'

- **Location:** resources/grafana/generate_queues_dashboard.py:328-336; resources/grafana/dashboards/queues.json (1 occurrence of supervisor-(.+)); README.md:173
- **Detail:** The tile legend strips a leading 'supervisor-' with `label_replace(..., "supervisor-(.+)")`. That is Horizon's default naming and office's convention. Supervisors named differently simply keep their full name, so the effect is cosmetic, but the README examples ('supervisor-default') read as a requirement.
- **Recommendation:** Add a generator flag `--supervisor-name-strip` (default 'supervisor-', empty disables it) and use neutral wording in the docs.

## F28 [high] README and docs are Russian and full of office-only facts that would mislead other projects

- **Location:** README.md:1,7-8,27-46,68,73,76-86,111-116,120-127,133,151,158,173,222,296,304-326,333,374,386-388; docs/extraction.md:1-84; docs/cleanup.md:1-48 (esp. 24,40,47); docs/backlog.md:1-21 (esp. 14,18,20); docs/research.md:20,34,52; config/horizon-prometheus-grafana.php:44-46
- **Detail:** Office-only facts presented as general instructions:
- `job_name: office` (README:68); target `<app>:8080` (73); relabel regex `office_(queue|horizon)_.*` (80); `scrape_duration_seconds{job="office"}` (85)
- `PROMETHEUS_ALLOWED_IPS` (8, 111): office's own env wiring
- `TRUSTED_PROXIES` with `config/trustedproxy.php` (113): does not exist in Laravel 11+
- `REDIS_QUEUE_DB` 'default 3' (126): not a Laravel default
- `DeleteClientReportFileJob` / '25 h' (151, 374); `GenerateFinReportJob` (333)
- 'all supervisors use balance => auto' (158)
- the stale config key `queue_metrics.redis_connection` (222)
- `docker compose exec app`, the 'monitoring' compose profile, `localhost:9090` (307-323)
- 'ККТ' (cash registers, 386) and other changelog entries about office

Installation is described as a path repository inside office (27-39). extraction.md, cleanup.md and backlog.md are office's internal migration notes and review checklists: commit 2db50c9a8, PR #4213/#4190, office's Dockerfile, docker-compose.override.yml, .docker/ provisioning, OrderCollector, `job_batches_office`, tests/Fixtures paths, office's queue thresholds.
- **Recommendation:** Write a new English README:
- install from Packagist
- a config table with the new env names
- Prometheus and Grafana setup with generic placeholders, plus the dashboard/alert generator flags
- metric reference generated from or tested against the code
- security notes

Move office-specific migration steps into UPGRADE.md, written for office as the first user. Delete or archive cleanup.md, backlog.md and extraction.md. Keep research.md only if translated and made neutral. Optionally keep a Russian translation under docs/ru.

## F29 [medium] No per-feature toggles: every scrape of the app's shared metrics endpoint does all the work

- **Location:** src/HorizonPrometheusGrafanaServiceProvider.php:63,65-67,70-87,89-102; config/horizon-prometheus-grafana.php:12-19; src/QueueJobMetricsSubscriber.php:69; README.md:76-83
- **Detail:** The only switch, `enabled`, gates attempt recording and QueueJobsCollector. Everything else always runs on every scrape of the host app's single shared registry:
- a Lua script per queue
- the job_batches query
- the app/ scan
- Horizon repository reads
- the job-list routes, the Horizon menu middleware and the load-test command

A consumer that only wants some features, or scrapes several instances (README:76-83), cannot turn the rest off. The README's metric_relabel workaround drops the series after the cost has already been paid.
- **Recommendation:** Add config:
- `collectors`: a map of booleans (horizon_status, horizon_workload, horizon_supervisors, horizon_waits, horizon_info, queue_state, queue_job_classes, queue_batches, queue_jobs)
- `routes.enabled`
- `horizon_link.enabled`
- `load_test.enabled`

Register only what is enabled, and document a lightweight profile for secondary instances.
