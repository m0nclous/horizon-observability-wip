DEV / TEST / RELEASE INFRASTRUCTURE PROPOSAL for df/horizon-observability (root namespace DF\HorizonObservability). Facts current as of 2026-10-16. Everything marked "verified" was executed in /tmp/claude-0/-home-user-horizon-observability/cc6491ff-4b68-5ae7-bac7-d93fef57187c/scratchpad/audit/ ; repository untouched (git status clean).

====================================================================
A. WHAT WAS VERIFIED
====================================================================
- composer dry-run resolutions for PHP 8.3 (real) and 8.4/8.5 (config.platform override) x Laravel 12/13, plus prefer-lowest.
- Real install (PHP 8.3.6, laravel/framework 12.70.0, horizon 5.50.1, spatie/laravel-prometheus 1.4.0, testbench 10.12.0 / testbench-core 10.16.0 / workbench 10.3.0, pest 4.7.8, phpunit 12.5.33, predis 3.7.0, laravel-data 4.23.0) of the CURRENT src into a throwaway workbench.
- `vendor/bin/testbench serve --host=0.0.0.0 --port=8089` + `vendor/bin/testbench horizon`: the master spawns `php artisan horizon:supervisor/horizon:work` inside vendor/orchestra/testbench-core/laravel, and that skeleton artisan re-reads testbench.yaml through TESTBENCH_WORKING_PATH. Supervisors from the workbench config ran and processed jobs.
- `testbench queue-metrics:load-test` (+ --continuous) -> /metrics 200 (first scrape 0.29 s, then 0.03 s), all dashboard metric families present except office_queue_job_classes (app_path, F5) and batch_oldest_age (no running batch at that instant); JSON endpoints OK; invalid limit -> 422 JSON.
- Prometheus 3.15.0 container scraped 127.0.0.1:8089/metrics (health up, 42 ms). Grafana 13.2.4 container: GF_PLUGINS_PREINSTALL_SYNC installed Infinity 4.1.1 + Business Text 6.3.0; file provisioning loaded dashboard uid "horizon" and the 4 alert rules (folder Horizon) with HPG_PROMETHEUS_DATASOURCE_UID=prometheus; state history to Prometheus env vars accepted.
- Playwright (preinstalled chromium, node module /opt/node-tools/node_modules/playwright 1.56.1) screenshots: overview, workload, Details·Jobs incl. Infinity tables with data.
- Pest smoke test (dispatch -> in-process `queue:work --once` -> scrape -> JSON list): passes with phpredis, fails with predis (F2).
- Larastan levels 5..10 on src (F11).
- Generator determinism: `python3 -I resources/grafana/generate_queues_dashboard.py out.json` == committed queues.json byte-for-byte (Python 3.13).
NOT verified: runtime on Laravel 13 (composer refuses PHP 8.3+L13 and disk space was too tight for a patched install); docker-compose in bridge mode (I used --network host); image-renderer container (Docker Hub rate-limited here).

====================================================================
B. CURRENT VERSIONS (packagist p2 metadata, 2026-10-16)
====================================================================
| package | latest | requires | notes |
| laravel/framework | 13.37.0 (2026-10-16) | php ^8.3 | 13.0.0 released 2026-03-17; 12.70.0 (2026-10-13) php ^8.2 |
| laravel/horizon | 5.50.1 (2026-10-13) | php ^8.0, illuminate ^9.21..^13 | L12 since 5.31.0, L13 since 5.44.0; ^5.40 fine |
| spatie/laravel-prometheus | 1.7.1 (2026-09-28) | php ^8.4, illuminate ^12/^13 | 1.4.0: php ^8.3, ^11/^12; 1.3.0: php ^8.3, ^10-^12 |
| promphp/prometheus_client_php | 2.15.1 (2026-05-28) | php ^8.2 | 2.14.1 php ^7.4/^8.0; spatie needs ^2.7.1 |
| spatie/laravel-data | 4.23.0 (2026-05-08) | php ^8.1 | L12 since 4.14.0, L13 since 4.20.0 |
| spatie/laravel-package-tools | 1.93.3 (2026-09-28) | php ^8.1 | L13 since 1.93.0; lowest picked for L12: 1.92.0 |
| orchestra/testbench | 11.3.0 (L ^13.32) / 10.12.0 (L ^12.69.2) | php ^8.3 / ^8.2 | 10.0.0 -> L ^12.0.1; 11.0.0 -> L ^13.0.0 |
| orchestra/workbench | 11.3.0 / 10.3.0 | | testbench-core 11.6.0 / 10.16.0 |
| pestphp/pest | 5.3.2 (php ^8.4, phpunit ^13.4.2) | | 4.7.8: php ^8.3, phpunit ^12.5.33 |
| pestphp/pest-plugin-laravel | 5.0.1 (php ^8.4, L ^13.23) | | 4.1.0: php ^8.3, L ^11.45.2/^12.52/^13.0; 4.0.0: L ^12.25 |
| phpunit/phpunit | 13.4.3 (php >=8.4.1) | | 12.5.40 php >=8.3 |
| larastan/larastan | 3.13.0 (2026-10-07) | php ^8.2, phpstan ^2.3 | phpstan 2.3.1 |
| laravel/pint | 1.32.1 | php ^8.3 | |
| rector/rector | 2.7.0 | phpstan ^2.3 | driftingly/rector-laravel 2.6.2 (php >=8.3, rector ^2.6.4) |
| predis/predis | 3.7.0 | | |
| ergebnis/composer-normalize | 2.54.0 | php up to ~8.6 | |
PHP: 8.5.11, 8.4.26, 8.3.35 (8.3 is security-only until end of 2027); 8.6 not released.
Grafana 13.2.4, Prometheus 3.15.0 (local images). Plugins: yesoreyeram-infinity-datasource 4.1.1; marcusolsson-dynamictext-panel 6.3.0 (Grafana >=12.3.0); grafana-image-renderer plugin 4.1.5 is marked deprecated, standalone service image grafana/grafana-image-renderer v5.12.7 (2026-10-13).
GitHub Actions tags: actions/checkout v7.0.1, shivammathur/setup-php v2, actions/cache v6.1.0, ramsey/composer-install 4.0.0, actions/upload-artifact v7.0.2, stefanzweifel/git-auto-commit-action v7.2.0, dependabot/fetch-metadata v3.1.0.

Resolution results (composer):
- PHP 8.3 + L12 stable: framework 12.70.0, horizon 5.50.1, testbench 10.12.0, pest 4.7.8, phpunit 12.5.33, promphp 2.15.1, spatie-prom 1.4.0, larastan 3.13.0.
- PHP 8.3 + L13: UNRESOLVABLE (F1).
- PHP 8.4 / 8.5 + L12 / L13: OK (spatie-prom 1.7.1, testbench 10.12.0 / 11.3.0, pest 4.7.8).
- prefer-lowest PHP 8.3 + L12: framework 12.25.0, horizon 5.40.0, testbench 10.2.0 (core 10.5.0, workbench 10.0.4), pest 4.0.0, pest-plugin-laravel 4.0.0, phpunit 12.3.5, larastan 3.1.0, phpstan 2.3.0, predis 2.0.0, promphp 2.7.1, package-tools 1.92.0, spatie-prom 1.3.0.

====================================================================
C. PROPOSED composer.json (variant: keep spatie; see F1 for the alternative)
====================================================================
{
    "name": "df/horizon-observability",
    "description": "Prometheus metrics, Grafana dashboard and alerts for Laravel Horizon and Redis queues",
    "license": "MIT",
    "type": "library",
    "keywords": ["laravel", "horizon", "queue", "redis", "prometheus", "grafana", "observability", "metrics", "monitoring"],
    "authors": [{"name": "...", "email": "...", "role": "Developer"}],
    "homepage": "https://github.com/<vendor>/horizon-observability",
    "support": {"issues": "https://github.com/<vendor>/horizon-observability/issues", "source": "https://github.com/<vendor>/horizon-observability"},
    "require": {
        "php": "^8.3",
        "laravel/framework": "^12.25|^13.0",
        "laravel/horizon": "^5.40",
        "spatie/laravel-package-tools": "^1.92",
        "spatie/laravel-prometheus": "^1.3"
    },
    "require-dev": {
        "driftingly/rector-laravel": "^2.6",
        "ergebnis/composer-normalize": "^2.54",
        "larastan/larastan": "^3.13",
        "laravel/pint": "^1.32",
        "orchestra/testbench": "^10.2|^11.0",
        "pestphp/pest": "^4.0",
        "pestphp/pest-plugin-laravel": "^4.0",
        "phpstan/extension-installer": "^1.4",
        "phpstan/phpstan-deprecation-rules": "^2.0",
        "predis/predis": "^2.0|^3.0",
        "rector/rector": "^2.7"
    },
    "suggest": {
        "ext-redis": "phpredis client (recommended); predis/predis is supported as well"
    },
    "autoload": {"psr-4": {"DF\\HorizonObservability\\": "src/"}},
    "autoload-dev": {"psr-4": {
        "DF\\HorizonObservability\\Tests\\": "tests/",
        "Workbench\\App\\": "workbench/app/",
        "Workbench\\Database\\Factories\\": "workbench/database/factories/",
        "Workbench\\Database\\Seeders\\": "workbench/database/seeders/"
    }},
    "config": {
        "allow-plugins": {"ergebnis/composer-normalize": true, "pestphp/pest-plugin": true, "phpstan/extension-installer": true},
        "sort-packages": true
    },
    "extra": {"laravel": {"providers": ["DF\\HorizonObservability\\HorizonObservabilityServiceProvider"]}},
    "minimum-stability": "stable",
    "prefer-stable": true,
    "scripts": {
        "post-autoload-dump": ["@clear", "@prepare"],
        "clear": "@php vendor/bin/testbench package:purge-skeleton --ansi",
        "prepare": "@php vendor/bin/testbench package:discover --ansi",
        "build": "@php vendor/bin/testbench workbench:build --ansi",
        "serve": ["Composer\\Config::disableProcessTimeout", "@build", "@php vendor/bin/testbench serve --host=0.0.0.0 --port=8000 --ansi"],
        "horizon": ["Composer\\Config::disableProcessTimeout", "@php vendor/bin/testbench horizon --ansi"],
        "lint": ["@php vendor/bin/pint --parallel --ansi", "@php vendor/bin/rector --ansi"],
        "test:lint": ["@php vendor/bin/pint --test --ansi", "@php vendor/bin/rector --dry-run --ansi"],
        "test:types": "@php vendor/bin/phpstan analyse --memory-limit=1G --ansi",
        "test:unit": "@php vendor/bin/pest --colors=always",
        "test:predis": "REDIS_CLIENT=predis vendor/bin/pest --colors=always",
        "test:grafana": ["python3 -I resources/grafana/generate_queues_dashboard.py build/queues.json", "diff -u resources/grafana/dashboards/queues.json build/queues.json"],
        "test": ["@test:lint", "@test:types", "@test:unit"]
    }
}
Notes: no "version" field (tags only). ext-pcntl/ext-posix come via Horizon. promphp is transitive. If the refactor renders histograms through promphp directly, add "promphp/prometheus_client_php": "^2.14". Drop spatie/laravel-data (F10); if it stays, use "^4.14" (L12) / "^4.20" (L13). Do not commit composer.lock (library). If spatie becomes optional (F1-b), move it to "suggest" plus require-dev and remove the php 8.3 x L13 exclusion.

====================================================================
D. WORKBENCH (testbench) — verified shape
====================================================================
testbench.yaml:
laravel: '@testbench'
providers:
  - Workbench\App\Providers\WorkbenchServiceProvider
migrations:
  - workbench/database/migrations
env:
  - APP_ENV=local                      # Horizon's default gate allows 'local'
  - APP_KEY=base64:<generate>
  - APP_URL=http://localhost:8000      # browser-facing: becomes horizon_info{url} -> dashboard 'Horizon' link
  - DB_CONNECTION=sqlite               # job_batches/failed_jobs come from testbench default migrations (verified)
  - QUEUE_CONNECTION=redis
  - REDIS_CLIENT=phpredis
  - REDIS_HOST=127.0.0.1
  - REDIS_PORT=6379
  - CACHE_STORE=array                  # keep Redis keyspace = queues + metrics + horizon only
  - SESSION_DRIVER=array
  - HORIZON_OBSERVABILITY_GRAFANA_URL=http://localhost:3000/d/horizon/queues
workbench:
  start: /horizon
  install: true
  health: false
  discovers: {web: true, api: false, commands: true, components: false, views: false, config: true}
  build: [create-sqlite-db, db-wipe, migrate-fresh]
  assets: []
  sync: []

`discovers.config: true` makes testbench load workbench/config/*.php (LoadConfigurationWithWorkbench). That is cleaner than config() calls in the provider, and Horizon child processes get the same config because they reboot through the same testbench.yaml.

workbench/
  app/Providers/WorkbenchServiceProvider.php   Horizon::auth(fn () => true); register demo commands
  app/Jobs/                                     DemoJob (outcome+duration, displayName for synthetic task names; replaces src/Jobs/QueueLoadTestJob),
                                                plus plain classes for discovery tests: SendInvoiceJob, ResizeImageJob, AbstractBaseJob (abstract, must be skipped),
                                                NamedJob (has displayName(), skipped)
  app/Listeners/SyncCrmListener.php             ShouldQueue listener
  app/Notifications/OrderShipped.php            ShouldQueue notification
  app/Mail/WeeklyReport.php                     ShouldQueue mailable
  app/Events/QueuedEvent.php                    ShouldQueue without handle() (must be skipped)
  app/Console/Commands/GenerateLoadCommand.php  moved from src/Console/QueueLoadTestCommand (dev-only), try/catch per tick
  config/horizon.php        environments.local: supervisor-default [default] max 3; supervisor-slow [slow, slow_reports] max 2; waits {redis:default 10, redis:slow 30, redis:slow_reports 30}
  config/prometheus.php     urls.default='metrics', default_namespace=<dashboard prefix>, cache=null, allowed_ips=[]
  config/horizon-observability.php  job discovery paths => [workbench_path('app')] (needs F5 refactor)
  database/migrations/      (empty; default testbench migrations already provide jobs, job_batches, failed_jobs)
  routes/web.php            GET /demo/dispatch?count=&queue= for manual bursts

Daily loop:
  docker compose up -d                  # redis, prometheus, grafana
  composer build                        # sqlite + migrations (re-run after every composer dump-autoload: purge-skeleton deletes the DB, F13)
  composer serve                        # 0.0.0.0:8000 (must bind 0.0.0.0 so containers reach it)
  composer horizon                      # after PHP changes: vendor/bin/testbench horizon:terminate
  vendor/bin/testbench demo:load --continuous
  open http://localhost:3000/d/horizon/queues
Gotchas seen: the first /metrics scrape on a fresh Redis wipes the attempt counters (scrape once before generating load). Horizon's 'Uptime' tile counts only the time Horizon ran inside the selected window.

====================================================================
E. LOCAL STACK (docker-compose.yml, dev-only, export-ignore)
====================================================================
name: horizon-observability
services:
  redis:
    image: redis:7.4-alpine                # or valkey/valkey:8-alpine; mirror.gcr.io/library/redis if Docker Hub limits
    ports: ["6379:6379"]
    command: ["redis-server", "--save", "", "--appendonly", "no"]
  prometheus:
    image: quay.io/prometheus/prometheus:v3.15.0
    command: [--config.file=/etc/prometheus/prometheus.yml, --web.enable-remote-write-receiver, --web.enable-admin-api, --storage.tsdb.retention.time=7d]
    extra_hosts: ["host.docker.internal:host-gateway"]
    volumes: [./docker/prometheus/prometheus.yml:/etc/prometheus/prometheus.yml:ro, prometheus-data:/prometheus]
    ports: ["9090:9090"]
  grafana:
    image: grafana/grafana:13.2.4
    depends_on: [prometheus]
    extra_hosts: ["host.docker.internal:host-gateway"]
    environment:
      GF_SECURITY_ADMIN_PASSWORD: admin
      GF_AUTH_ANONYMOUS_ENABLED: "true"            # Grafana 13: viewer only
      GF_PLUGINS_PREINSTALL_SYNC: yesoreyeram-infinity-datasource@4.1.1,marcusolsson-dynamictext-panel@6.3.0
      GF_DASHBOARDS_DEFAULT_HOME_DASHBOARD_PATH: /etc/grafana/dashboards/queues.json
      GF_UNIFIED_ALERTING_STATE_HISTORY_ENABLED: "true"
      GF_UNIFIED_ALERTING_STATE_HISTORY_BACKEND: multiple
      GF_UNIFIED_ALERTING_STATE_HISTORY_PRIMARY: annotations
      GF_UNIFIED_ALERTING_STATE_HISTORY_SECONDARIES: prometheus
      GF_UNIFIED_ALERTING_STATE_HISTORY_PROMETHEUS_TARGET_DATASOURCE_UID: prometheus
      HPG_PROMETHEUS_DATASOURCE_UID: prometheus    # rename with the package (e.g. HORIZON_OBSERVABILITY_PROMETHEUS_UID) in horizon.yml too
      # profile screenshots: GF_RENDERING_SERVER_URL: http://renderer:8081/render, GF_RENDERING_CALLBACK_URL: http://grafana:3000/
    volumes:
      - ./docker/grafana/provisioning:/etc/grafana/provisioning:ro    # datasources/, dashboards/, plugins/.gitkeep
      - ./resources/grafana/alerting:/etc/grafana/provisioning/alerting:ro
      - ./resources/grafana/dashboards:/etc/grafana/dashboards:ro
      - grafana-data:/var/lib/grafana
    ports: ["3000:3000"]
  renderer:
    image: grafana/grafana-image-renderer:v5.12.7
    profiles: [screenshots]
volumes: {prometheus-data: {}, grafana-data: {}}

docker/prometheus/prometheus.yml (scrape_interval must stay <= 15s, dashboard BUSY_WINDOW):
global: {scrape_interval: 15s, scrape_timeout: 10s, evaluation_interval: 15s}
scrape_configs:
  - job_name: workbench
    metrics_path: /metrics
    static_configs: [{targets: ['host.docker.internal:8000']}]

docker/grafana/provisioning/datasources/datasources.yml (verified with 127.0.0.1 URLs in host mode):
apiVersion: 1
datasources:
  - {name: Prometheus, uid: prometheus, type: prometheus, access: proxy, url: http://prometheus:9090, isDefault: true,
     jsonData: {timeInterval: 15s, prometheusType: Prometheus, prometheusVersion: 3.15.0}}
  - {name: App API, uid: app-api, type: yesoreyeram-infinity-datasource, access: proxy, url: http://host.docker.internal:8000,
     jsonData: {allowedHosts: ['http://host.docker.internal:8000']}}
docker/grafana/provisioning/dashboards/dashboards.yml: provider type file, path /etc/grafana/dashboards, updateIntervalSeconds 10, allowUiUpdates true.
The Infinity URL is server-side and seen from the container (host.docker.internal), while horizon_info{url} is opened by the browser (APP_URL=localhost). Keep them different on purpose.

Screenshots: prefer Playwright. It was verified, and it can expand the collapsed "Details · Jobs/Batches" rows, which the /render API cannot. Script bin/screenshot.mjs (export-ignore):
  launch chromium; newPage({locale: 'en-US', timezoneId: 'UTC', viewport: {width: 1920, height: 3600}})   # without locale Grafana fails: "Invalid language tag: en-US@posix"
  goto http://localhost:3000/d/horizon/queues?orgId=1&from=now-15m&to=now&kiosk[&theme=light]
  click getByText('Details · Jobs', {exact: true}) and 'Details · Batches'; wait networkidle + 8 s; screenshot.
  (getByRole('button', {name}) does not match Grafana 13 row headers.) Run the load generator at least 15 min first, or backfill Prometheus with promtool tsdb create-blocks-from openmetrics for deterministic images (not verified). The renderer service is an option for single panels: /render/d-solo/horizon/queues?panelId=N&width=..&height=...
Reference copies: /tmp/claude-0/-home-user-horizon-observability/cc6491ff-4b68-5ae7-bac7-d93fef57187c/scratchpad/audit/shot2.mjs, /tmp/claude-0/-home-user-horizon-observability/cc6491ff-4b68-5ae7-bac7-d93fef57187c/scratchpad/audit/stack/ (prometheus.yml + provisioning), screenshots /tmp/claude-0/-home-user-horizon-observability/cc6491ff-4b68-5ae7-bac7-d93fef57187c/scratchpad/audit/dashboard.png and /tmp/claude-0/-home-user-horizon-observability/cc6491ff-4b68-5ae7-bac7-d93fef57187c/scratchpad/audit/dashboard-full.png.

====================================================================
F. TEST STRATEGY
====================================================================
Layers (Pest 4, Orchestra TestCase with WithWorkbench):
- tests/Unit: no Redis/no app. Pure logic, extracted from private methods during the refactor: BatchKind, JobAttemptStatus contract, ExceptionSummary (from HorizonJobsService::exceptionSummary), LabelCodec/HistogramMath (encode/decode/bucketFor/cumulative from QueueJobMetricsStorage), DisplayName/byClass (RedisQueueStateReader), BatchStatus (QueueBatchesCollector::batch), HorizonUrl (HorizonInfoCollector::url), Grafana query-string parser (GetHorizonJobsRequest::grafanaVariables), Exposition parser for tests.
- tests/Integration (group redis): real Redis plus Laravel app: storage, Lua reader, repositories, subscriber with an in-process worker, collectors via scrape.
- tests/Feature: HTTP: /metrics, /metrics/horizon/*, middleware, Horizon page link, provider wiring.
- tests/Architecture: arch()->preset()->php(); src has no dd/dump/ray; declare(strict_types=1) everywhere; src never uses Workbench or App namespaces; DF\HorizonObservability\Http only depends on contracts.
- tests/Grafana: asset contracts (below).

Real Redis: local `docker compose up -d redis` or `redis-server --port 6379 --save '' --appendonly no`. CI uses a services: container. TestCase reads REDIS_HOST/PORT/CLIENT from env and uses a dedicated prefix (database.redis.options.prefix = 'ho_test_<ParallelTesting token>:'). It flushes the db for 'default' and 'horizon' in setUp. Run the suite once per client (phpredis plus predis): CI matrix plus `composer test:predis`. No --parallel until the prefix/db is per process. Unit tests must not need Redis.

TestCase (verified pattern): getPackageProviders = [Spatie\Prometheus\PrometheusServiceProvider, Laravel\Horizon\HorizonServiceProvider, DF\HorizonObservability\HorizonObservabilityServiceProvider] (+ LaravelDataServiceProvider if kept: F10). defineEnvironment sets prometheus.urls.default=metrics, default_namespace=test, horizon.environments.testing={...}. defineDatabaseMigrations loads Orchestra\Testbench\default_migration_path() (that is where job_batches comes from; loadLaravelMigrations() does not create it, verified).

Support helpers:
- scrape(): app()->forgetScopedInstances(); GET /metrics; parse text into [metric => [labelsKey => value]] (F4).
- primeCounters(): one scrape (stores run_id) before recording attempts (F4).
- HorizonState::master(name, status='running'|'paused') writes HMSET {horizon prefix}master:<name> name/environment/pid/status/supervisors plus ZADD masters now <name>. HorizonState::supervisor(name 'host:supervisor-x', processes ['redis:default' => 3], options [...balance, connection, minProcesses, maxProcesses]) writes HMSET supervisor:<name> name/master/pid/status/processes(json)/options(json) plus ZADD supervisors now <name>. This is the same format RedisSupervisorRepository/RedisMasterSupervisorRepository::update() produce (they expire hashes after 30/15 s, so freeze time or write right before scraping). Alternative for pure collector logic: bind fake SupervisorRepository/WorkloadRepository/MasterSupervisorRepository in the container.
- QueuePayloads: build raw payloads (uuid, displayName, pushedAt as JSON string, createdAt, attempts, delay) and RPUSH queues:<q>, ZADD queues:<q>:reserved / :delayed with scores, to drive the Lua reader without workers. Include a cjson-reordered payload with displayName last and an escaped namespace.
- Horizon job lists: seed through the real Horizon JobRepository API (pushed/reserved/completed/failed with Laravel\Horizon\JobPayload), or dispatch onto the redis connection (HorizonServiceProvider swaps in Horizon's RedisQueue, so JobPushed -> pending_jobs) and process with the worker helper.
- work(queue, n): Artisan::call('queue:work', ['connection' => 'redis', '--queue' => q, '--once' => true, '--stop-when-empty' => true]). Verified in-process; it fires JobProcessing/JobAttempted for the subscriber. JobTimedOut cannot be produced in-process (SIGALRM kills the worker), so dispatch the event manually with a real RedisJob instance.
- Time: after the refactor to now()/Clock, use $this->travelTo(); Lua scripts already receive time via ARGV.
- Reset static memos per test (QueuedJobClasses cache, subscriber batch memos) via explicit flush() methods.

Concrete cases per class:
BatchKind::of: 'campaign_launch_15_2026-10-15' -> 'campaign_launch'; uuid inside name removed; null/''/'123' -> 'unnamed'; repeated separators collapse to '_'; trims ' _.:-'; Cyrillic preserved.
JobAttemptStatus: values exactly completed/released/retried/failed/timed_out (dashboard and alert contract).
HorizonQueues::byConnection: horizon.env overrides app.env; wildcard env patterns, first match wins; no match -> []; defaults merged (ProvisioningPlan); maxProcesses<=0 skipped; missing 'queue' -> connection default queue; 'a, b' string split and trimmed; dedupe across supervisors; grouped by connection.
QueuedJobClasses: finds jobs with handle(), queued listeners, notifications and mailables; skips abstract, displayName(), ShouldQueue without handle; sorted; memoized; works for configured non-app paths (F5).
QueueJobMetricsStorage (both clients): recordAttempt writes jobs_total field JSON [conn,queue,job,status]; duration bucket non-cumulative; wait only when not null; min/max per 60 s window with TTL 180; jobsTotal zero-fills missing statuses of known jobs; histogram cumulative with +Inf and count == +Inf bucket; changing duration_buckets keeps totals consistent; garbage fields (wrong arity, non-JSON, non-string labels) skipped without breaking; rememberReadyAt TTL = delay+86400 and pullReadyAt deletes and returns int|null; batchJobClasses prunes >7 days (seed old score); resetCountersAfterRedisRestart: no meta -> deletes and true, same run_id -> false, changed run_id -> true, INFO failure -> false (mock connection).
QueueJobMetricsSubscriber: disabled -> no listeners; JobQueued with delay on redis stores ready_at, on database/sync driver or delay 0 does not; batch job -> one link per hour (memo), memo reset beyond 1000; worker flows -> completed, released (release()), retried (exception, tries>1), failed (fail() / last try), MaxAttemptsExceeded -> failed with no duration sample, timed_out (manual JobTimedOut) clamped to $job->timeout(); dispatchSync ignored; wait seconds only on attempt 1, uses pushedAt/createdAt + payload delay, uses ready_at when wait >= 1 s; storage exceptions logged once and never break job processing (Log::spy).
RedisQueueStateReader: pending/reserved/delayed/delayedDue counts; oldestReadyAt = min(head pushedAt, earliest due score); empty -> null; pending_scan_limit remainder -> '(не просмотрено)' (pin the label value or rename to English and update the dashboard); limit 0 -> everything unscanned; missing displayName -> '(неизвестно)'; escaped backslashes decoded; reserved > 1000 -> ZCARD; non-RedisQueue connection skipped; Lua failure -> one queue skipped plus one warning per read; non-empty redis key prefix.
ScrapeCache: memoizes per source; exception -> default plus one warning; scoped instance reset per request.
Collectors (assert parsed scrape): spatie HorizonStatusCollector -1/0/1 (no master / any paused / running); HorizonWorkloadCollector queue labels incl. comma-joined balance=false pools; HorizonSupervisorsCollector name without master prefix, processes summed across masters, min/max = max across masters, balance false/unknown -> 'off', connection label; HorizonInfoCollector app.url+path, horizon.domain with scheme/port from app.url, trailing slashes, tts_url trimmed; HorizonWaitsCollector default 60, explicit, 0 excluded; QueueStateCollector pending = pending+delayedDue, per-class series, age 0 when empty, omitted when unknown; QueueJobClassesCollector lists discovered classes; QueueBatchesCollector statuses Running/Finished/Failures/Cancelled, allowFailures with pending==failed treated as closed, 24 h window, 200-per-kind cap, progress rounding, created label in app.timezone, job_classes/info from links, missing table -> 200 without batch series (F3); QueueJobsCollector registered only when enabled, _bucket/_sum/_count naming with le, reset runs before the first read of a scrape, behaviour with prometheus.cache set (document or guard).
HTTP: GetHorizonJobsRequest repeated var-x params, $__all -> null, urlencoded backslashes, unknown vars ignored, from/to/limit validation -> 422 JSON; HorizonJobsService supervisor -> queues via SupervisorRepository, batch kinds -> queue/job filters, empty intersection -> empty list; HorizonJobsRepository newest first, period filter, expired hashes skipped, SCAN_LIMIT, exception first line <= 1000 bytes, phpredis/predis hmget shapes; DelayedJobsRepository strictly future, cross-queue ordering, class filter, limit, attempts, queued_at null without pushedAt; resources ms conversion, runtime rounding, horizon_path per type, unescaped JSON (F9); routes use config prefix and middleware, AllowIps 403 JSON.
AddGrafanaLinkToHorizon: real GET /horizon (local env): no URL -> untouched; URL -> item right after the first </li> inside 'col-2 sidebar'; JSON API untouched; missing markup -> untouched. The weekly CI on the latest Horizon catches markup drift.
Service provider: config merged and publishable; horizon.middleware contains the link middleware after register(); commands only in console; subscriber registered.
Load generator (if kept, in workbench): refuses in production; unknown queue fails; Bus::fake/Queue::fake counts per scenario; DemoJob outcomes.
Grafana assets: (1) generator output == committed JSON; (2) --prefix X leaves no 'office' (fails today: tag at generate_queues_dashboard.py:1815); (3) every metric referenced in queues.json + horizon.yml is present in a seeded full scrape (today's set: 30 names; the workbench lacked queue_job_classes, F5); (4) alert annotations __dashboardUid__=horizon and __panelId__ exist; (5) Infinity URL prefix == default routes.prefix; (6) YAML parses, rule uids keep the 'horizon-' prefix that the Alerts tile filters on (generator ALERTS selector).

Static analysis: Larastan level 8 on src+tests after the refactor (current src: L5 22 / L6 30 / L7 63 / L8 68 / L9 161 / L10 210); phpstan-deprecation-rules; no baseline for new code. Pint preset laravel with declare_strict_types. Rector: withPhpSets(php83), prepared sets deadCode/codeQuality/typeDeclarations/earlyReturn, rector-laravel set up to Laravel 12 (check the exact constant name in rector-laravel 2.6). Coverage: pcov on one CI leg, pest --coverage --min=85.

====================================================================
G. CI (.github/workflows/tests.yml)
====================================================================
name: tests
on: {push: {branches: [main]}, pull_request: {}, schedule: [{cron: '23 5 * * 1'}], workflow_dispatch: {}}
permissions: {contents: read}
concurrency: {group: tests-${{ github.ref }}, cancel-in-progress: true}
jobs:
  pest:
    name: PHP ${{ matrix.php }} / L${{ matrix.laravel }} / ${{ matrix.stability }} / ${{ matrix.client }} / ${{ matrix.redis }}
    runs-on: ubuntu-24.04
    timeout-minutes: 15
    strategy:
      fail-fast: false
      matrix:
        php: ['8.3', '8.4', '8.5']
        laravel: ['12.*', '13.*']
        stability: [prefer-stable]
        client: [phpredis]
        redis: ['redis:7.4-alpine']
        exclude:
          - {php: '8.3', laravel: '13.*'}          # F1 (drop if spatie becomes optional)
        include:
          - {laravel: '12.*', testbench: '10.*'}
          - {laravel: '13.*', testbench: '11.*'}
          - {php: '8.3', laravel: '12.*', testbench: '10.*', stability: prefer-lowest, client: phpredis, redis: 'redis:6.2-alpine'}
          - {php: '8.4', laravel: '12.*', testbench: '10.*', stability: prefer-stable, client: predis, redis: 'redis:7.4-alpine'}
          - {php: '8.5', laravel: '13.*', testbench: '11.*', stability: prefer-stable, client: predis, redis: 'valkey/valkey:8-alpine'}
    services:
      redis:
        image: ${{ matrix.redis }}
        ports: ['6379:6379']
        options: --health-cmd "redis-cli ping" --health-interval 5s --health-timeout 3s --health-retries 10
    env: {REDIS_CLIENT: '${{ matrix.client }}', REDIS_HOST: 127.0.0.1, REDIS_PORT: 6379}
    steps:
      - uses: actions/checkout@v7
      - uses: shivammathur/setup-php@v2
        with: {php-version: '${{ matrix.php }}', extensions: 'redis, pcntl, posix, pdo_sqlite, mbstring, intl', coverage: none}
      - run: |
          composer require "laravel/framework:${{ matrix.laravel }}" --no-interaction --no-update
          composer require --dev "orchestra/testbench:${{ matrix.testbench }}" --no-interaction --no-update
          composer update --${{ matrix.stability }} --prefer-dist --no-interaction --no-progress
      - run: composer show laravel/framework laravel/horizon spatie/laravel-prometheus orchestra/testbench
      - run: vendor/bin/pest --ci
  static: (php 8.4, L13) composer validate --strict; composer normalize --dry-run; pint --test; rector --dry-run; phpstan --error-format=github
  coverage: (php 8.4, L13, pcov) pest --coverage --min=85
  grafana: checkout; python3 generator diff; --prefix check; docker compose up -d prometheus grafana; poll /api/health; curl -u admin:admin /api/dashboards/uid/horizon; jq length of /api/v1/provisioning/alert-rules == 4; fail on provisioning level=error lines (needs provisioning/plugins/.gitkeep, F8)
  dist: git archive HEAD | tar -t | sort compared against an allowlist (src/, config/, resources/, composer.json, LICENSE, README.md, CHANGELOG.md)
  e2e (schedule + workflow_dispatch only): redis service; composer build; serve and horizon in background; docker compose up -d prometheus grafana; demo:load for ~10 min; npx playwright@1.56.1 install chromium; node bin/screenshot.mjs; actions/upload-artifact@v7 dashboard PNGs (visual regression for the dashboard that must keep its look)
The weekly cron catches new Horizon/Laravel/spatie releases (sidebar markup, ProvisioningPlan internals, Redis structures listed in docs/extraction.md "Внутренности Horizon").
Also: .github/dependabot.yml (github-actions weekly; composer optional); optional release.yml on release:published -> update CHANGELOG (changelog-updater + git-auto-commit-action@v7). Packagist via GitHub webhook; SemVer tags vX.Y.Z; first public tag v1.0.0 (or 0.x while the metric names and labels are settling, since they are a public contract with dashboards and alerts).

====================================================================
H. CI-RELEVANT ENVIRONMENT NOTES (this sandbox, for the implementing agent)
====================================================================
- api.github.com zipballs return 403 (rate limit; GH_TOKEN is invalid). `composer install --prefer-install=source` works through the git proxy for everything except dist-only phpstan/phpstan. Workaround used: `git clone --depth 1 --branch 2.3.1 https://github.com/phpstan/phpstan` plus a path repository with "version": "2.3.1". Strip vendor/*/.git afterwards (6.1 GB -> 1.1 GB).
- Disk is tight and other agents share scratchpad/audit (q-13, hz, hzv, vendorprobe, composer-home belong to them).
- Docker Hub is rate-limited: use the local /usr/bin/redis-server. Grafana in a container needs HTTPS_PROXY=http://127.0.0.1:37347 plus the CA bundle mounted (/root/.ccr/ca-bundle.crt -> SSL_CERT_FILE) and --network host to install plugins.
- Another agent's container 'optimistic_carson' (grafana) is running; avoid host port 3000 clashes. I used Grafana 3300, Prometheus 9390, app 8089, Redis 16379/16380, all stopped and removed afterwards.

====================================================================
I. REPO HYGIENE FILES
====================================================================
.gitattributes:
  * text=auto eol=lf
  *.png binary
  /.github export-ignore
  /art export-ignore
  /bin export-ignore
  /docker export-ignore
  /docs export-ignore               (move user docs into README; keep internal research/backlog out of dist)
  /tests export-ignore
  /workbench export-ignore
  /.editorconfig export-ignore
  /.gitattributes export-ignore
  /.gitignore export-ignore
  /CONTRIBUTING.md export-ignore
  /docker-compose.yml export-ignore
  /phpstan.neon.dist export-ignore
  /phpunit.xml.dist export-ignore
  /pint.json export-ignore
  /rector.php export-ignore
  /testbench.yaml export-ignore
  /resources/grafana/generate_queues_dashboard.py export-ignore   (only if a PHP prefix command replaces it; otherwise ship it)
.editorconfig: root=true; [*] utf-8, lf, insert_final_newline, trim_trailing_whitespace, indent_style space, indent_size 4; [*.md] trim_trailing_whitespace=false; [*.{json,yml,yaml,neon}] indent_size 2 (queues.json is generated with indent=2; horizon.yml currently uses 4, so pick one and reformat).
.gitignore: /vendor/, /composer.lock, /.phpunit.cache/, /build/, /coverage/, .phpunit.result.cache, /.idea/, /.vscode/, .DS_Store, .env, /node_modules/, /screenshots/.
LICENSE: MIT (composer.json:6 says proprietary today).
CHANGELOG.md: Keep a Changelog 1.1.0 + SemVer; 'Unreleased' plus 1.0.0. Note that pre-extraction history lives in office (#4190, #4213, see docs/extraction.md:79).
CONTRIBUTING.md: prerequisites (PHP 8.3+, ext-redis or predis, Docker, Python 3 for the generator), setup (composer install, docker compose up -d, composer build/serve/horizon, demo:load), `composer test`, `composer test:predis`, dashboard edits only via the generator plus `composer test:grafana`, screenshots via bin/screenshot.mjs, commit/PR conventions.
SECURITY.md: private reporting via GitHub Security Advisories; supported versions table. Deployment warning: /metrics and /metrics/horizon/* expose job class names and exception messages and run Lua on Redis, so restrict them at ingress (README.md:113-118 content).
CODE_OF_CONDUCT.md: Contributor Covenant 2.1.
.github/ISSUE_TEMPLATE/bug_report.yml: fields for PHP, Laravel, Horizon, this package, spatie/laravel-prometheus, Redis client (phpredis/predis) and server version (Redis/Valkey), Prometheus and Grafana versions, plugin versions, the relevant /metrics excerpt, logs. feature_request.yml; config.yml (blank_issues_enabled: false; links to Discussions and Security). PULL_REQUEST_TEMPLATE.md (tests, CHANGELOG, dashboard regenerated, screenshots when visuals change). FUNDING.yml optional.
README: English, badges (tests workflow, packagist version, downloads, PHP/Laravel support, license), screenshot from /art, compatibility table (PHP 8.3 = Laravel 12 only while F1 stands; Grafana >=12.3; Prometheus >=2.33 with remote-write receiver for the Alerts tile).

Scratch reference (not authoritative, old namespace): /tmp/claude-0/-home-user-horizon-observability/cc6491ff-4b68-5ae7-bac7-d93fef57187c/scratchpad/audit/pkg/ (composer.json, testbench.yaml, workbench/, tests/TestCase.php, tests/Feature/SmokeTest.php, phpunit.xml.dist, phpstan.neon.dist; vendor removed to free disk).