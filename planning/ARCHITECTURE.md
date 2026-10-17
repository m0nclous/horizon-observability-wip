# Horizon Observability: final architecture

> **Amended by ADDENDUM.md R1–R4 (2026-10-17); where they differ the addendum wins.** The amended passages below cite the addendum section they come from (for example "Addendum R4.3" or "Addendum E′").

- **Package:** `sushi-market/horizon-observability`
- **Namespace:** `DF\HorizonObservability`
- **License:** MIT
- **Replaces:** `sushi-market/horizon-prometheus-grafana` (namespace `SushiMarket\HorizonPrometheusGrafana`), the pilot extracted from "office"
- **Status:** final design for the refactor, dated 2026-10-16

This document is the single source of truth for the implementation agents. It merges three competing designs ("horizon-native", "ecosystem", "operability") and applies the findings of two reviews ("owner-dx" and "correctness-ops"). Each question Q1 to Q9 is answered as Decision, Rationale, Rejected alternatives and Risks. The appendices hold the artefacts to implement verbatim:
- the config file;
- the metric catalog with HELP texts;
- the frozen interfaces;
- the Lua contracts;
- the Redis key schema;
- the compatibility matrix;
- the office migration;
- the bug → test map.

Words used with a fixed meaning:
- **base path**: the URL prefix of the package endpoints.
- **storage**: the package's own Redis keys.
- **reader**: a short-timeout Redis client used only on the scrape and job-list paths.
- **legacy stack**: Laravel < 12.11 or Horizon < 5.49, where queue payloads carry no `delay`.

---

## 0. Summary

### 0.1 The owner's two ideas: verdicts

| Owner's idea | Verdict | What ships | Why |
|---|---|---|---|
| The endpoint lives relative to Horizon's path and domain, default `/horizon/metrics` | **The Horizon-relative path is accepted, with one change. The exact path `/horizon/metrics` is rejected. Inheriting Horizon's domain is rejected; a domain is opt-in only.** | `GET /{horizon.path}/observability/metrics` and `GET /{horizon.path}/observability/jobs/{list}`. The default is `/horizon/observability/...` and it follows a custom `horizon.path`. An optional `domain` key exists, and a `path` key overrides the whole base (office may keep `metrics/horizon`). | `/horizon/metrics` is a real URL of Horizon's own UI. The SPA declares the client route `/metrics` (redirecting to `/metrics/jobs`) in history mode, and the sidebar renders `<router-link to="/metrics">`, i.e. `href="/horizon/metrics"` (`resources/js/routes.js:55-56`, `layout.blade.php:70`, Horizon 5.50.1). A package route at that URL must beat Horizon's catch-all to work at all, so it replaces Horizon's Metrics page whenever that page is opened in a new tab, bookmarked, typed or shared. An in-app reload lands on `/metrics/jobs` and is not affected. The route probe proves the shadowing in both provider orders, with cached and uncached routes. The segment `observability` is used by neither the Horizon SPA (dashboard, monitoring, metrics, jobs, failed, batches) nor its API (`/api/*`). The domain is not inherited because Prometheus cannot set `Host` (it is a reserved header in `prometheus/common`), and pod or service targets are addresses, not Horizon's public host. A host-bound route would return 404 to every scrape. |
| Keep the package config inside `config/horizon.php` under an `observability` key | **Rejected.** The hybrid ("read `horizon.observability` if present, else the own file") is rejected too. | An own file, `config/horizon-observability.php`. The tight binding to Horizon comes from *derived defaults*: the base path from `horizon.path`; Horizon's `horizon` Redis connection and prefix for storage; the topology from `horizon.environments` and `horizon.env ?? app.env`; the wait thresholds from `horizon.waits`; and the Horizon URL from `app.url`, `horizon.domain` and `horizon.path`. | The idea works technically: `mergeConfigFrom($file, 'horizon.observability')` merges defaults under a dot key, and config caching behaves the same. It loses on what matters. `vendor:publish` cannot add a block to another package's published file. Horizon's documented re-publish (`vendor:publish --tag=horizon-config --force`) silently deletes the block. Shallow merging is equally shallow in both places, so there is no technical gain. `config:show horizon-observability`, `about` and the README table only work well for an own file. A future Horizon key `observability` would collide. A hybrid has two sources of truth and undefined precedence. Section Q3 has the full comparison. |

### 0.2 Decisions at a glance

| Q | Decision |
|---|---|
| Q1 | Own Prometheus text-format 0.0.4 renderer; no promphp, no spatie. A `Metric` enum is the single catalog (name, type, HELP, labels, collector). Histograms are real histograms. Each collector runs in isolation: all of its families or none, except for explicitly reported partial failures. A scrape time budget comes from `X-Prometheus-Scrape-Timeout-Seconds`. Self-metrics report collector success and duration, plus `build_info`, config problems and dropped attempts. Replicas: **one logical scrape target per app** is the contract. Every rendered dashboard and alert query also dedupes with `max by (job, <family labels>)` as defence in depth. An opt-in "duplicate exporters" rule exists. There is no leader election. |
| Q2 | Routes are `/{horizon.path}/observability/metrics` (name `horizon-observability.metrics`) and `/{horizon.path}/observability/jobs/{list}` (name `horizon-observability.jobs`, `list ∈ pending, completed, failed, delayed`), plus `/{horizon.path}/observability/live` (name `horizon-observability.live`) when `live.enabled` (Addendum R4.3). They are registered from an *application booting callback*, which beats Horizon's catch-all in either provider order, with and without `route:cache`. They are outside the `web` and `horizon` groups. Auth uses a bearer token list (comma-separated, for rotation), an optional separate API token for Grafana, and an optional IP allow-list (ANDed with the token), or a `HorizonObservability::auth()` callback. With nothing configured, the endpoints are open only in `local`. No default throttle. The endpoints stay reachable in maintenance mode (opt-out). |
| Q3 | Own config file with about 25 keys and env prefix `HORIZON_OBSERVABILITY_`. Dashboard and alert render parameters are command options, not config. Validation normalises and reports: an invalid non-security value falls back to its default and is reported through `check` (exit 1), `about`, a self-metric and one throttled log line. Security values fail closed. Nothing throws at boot or answers 5xx because of config. |
| Q4 | Default namespace `ho`, giving names like `ho_queue_jobs_total` (Addendum R1). Office sets `HORIZON_OBSERVABILITY_NAMESPACE=office` and keeps 25 of 29 family names and label sets byte-identical. Exactly four families change: `horizon_current_processes` is dropped; `horizon_info` gains `api_path`; `queue_oldest_pending_job_age` becomes `queue_oldest_pending_job_age_seconds` because its meaning changes; `queue_batch_progress` loses `size` and `created`. English sentinels: `(unscanned)`, `(unknown)`, `(other)`; `unnamed` stays. `job_class` is the normalised `displayName`, capped at 500 classes with automatic 30-day pruning. |
| Q5 | Port the Python generator to PHP (`src/Grafana`, `@internal`) behind `php artisan horizon-observability:grafana [--check]`. The port must first reproduce today's `queues.json` and `horizon.yml` exactly (legacy golden profile). Intentional changes then land as reviewed steps. Install facts are baked in at render time: prefix, uid, title `Queues`, tags, rule uids and thresholds. Runtime facts are hidden variables: datasources, `prometheus_job` scope, `api_path`, `horizon_url`, `tts_url`, `busy_window` and `scrape_interval` (Addendum R3.3). Alert rules are rendered to a Grafana provisioning **JSON** file. `datasourceUid` uses Grafana's env interpolation (`${HORIZON_OBSERVABILITY_PROMETHEUS_UID}`), because the PromQL in `model` is never interpolated. |
| Q6 | Every confirmed bug gets a mechanical fix (table in Q6). Worker path: no Redis call at `JobProcessing`; one `EVAL` per attempt on a private short-timeout client behind a per-process circuit breaker; a `WeakMap` state machine; only `RedisJob` attempts that passed `JobProcessing` are recorded. Event shims cover Laravel 11.23, 12 and 13. Storage keys are hash-tagged, with no pipelines and `INFO` read inside Lua. Redis Cluster is supported wherever Horizon supports it. Batch SQL is bounded. Job-class discovery is cached at deploy time. |
| Q7 | Plain `ServiceProvider`. Everything is `final` and `@internal` except a small public surface: the config, metrics, HTTP, commands and dashboard contracts, plus two static hooks. There are no public interfaces in v0.x. The Horizon menu link is appended to `horizon.middleware` from the booting callback, which works on Horizon 5.22 through 5.50. The load generator lives in the workbench only. Seven commands ship: `install`, `check`, `grafana`, `cache`, `clear`, `flush`, `stream` (Addendum R4.6). |
| Q8 | `php ^8.2`, `laravel/framework ^11.23\|^12.0\|^13.0`, `laravel/horizon ^5.29.1` (raised from `^5.22` in the phase B integration, see the Horizon floor row), and nothing else at runtime. Testbench `^9.5\|^10.0\|^11.0` with workbench; Pest `^3.8\|^4.1`; Larastan level 9; Pint; Rector. The CI matrix covers PHP × Laravel, prefer-lowest at each floor, phpredis and predis, and Redis 6.2, 7.4, 8, Valkey and a single-node cluster leg. Static, grafana, dist and coverage run as separate jobs; e2e screenshots run weekly. A docker stack provides Redis, Prometheus, Grafana with plugins and provisioning, and an optional containerised app. |
| Q9 | WP0 (foundation and frozen contracts; one agent who stays the integrator) lands first. WP8A (Grafana golden port) starts right after WP0a. WP1 to WP7 and WP9 then run in parallel with disjoint file ownership. WP8B and WP10 follow, and WP11 integrates, documents and tags `v0.1.0`. The addendum adds WP-L1 and WP-L2 (Live) and WP8C and WP8D after WP8B (Addendum Q9′). |

### 0.3 How the three designs were merged

| Topic | horizon-native | ecosystem | operability | **Final** | Reason |
|---|---|---|---|---|---|
| Endpoint path | `/horizon/observability/*` | `/horizon/metrics`, `{list}-jobs` | same as ecosystem | **`/horizon/observability/metrics`, `/jobs/{list}`** | SPA collision (0.1). Ecosystem's "302 for browsers" workaround can never fire, because `ForceJson` rewrites `Accept` before the auth middleware runs. |
| Replicas | query dedupe by default | one target + dedupe | one target, leader lease; claims the alerts are invariant | **One target (contract) + dedupe (defence) + opt-in duplicates rule; no leader mode** | The failure rule `…/clamp_min(sum(total),100)` is *not* invariant under N copies. Dedupe undercounts `increase()` when every pod rotates inside the window. A lease adds an `EVAL` per scrape plus a second topology to document. |
| Prefix in dashboard | `${metric_prefix}` variable | baked | baked | **Baked** | Readable PromQL in Explore and the panel editor. Alert rules must be rendered anyway. |
| Generator | keep Python | PHP port | PHP port | **PHP port with legacy golden** | One toolchain; renders from the app's own config; Python 3.11 cannot even run today's script. |
| Alert file | YAML stub | JSON | own YAML emitter | **JSON** | Grafana parses `.json` provisioning files with the same YAML decoder and env interpolation (verified, `config_reader.go`), so no emitter and no quoting traps. |
| Dashboard title | `Queues` | `Queues` | `Horizon Queues` | **`Queues`** | Keep the look. |
| Horizon floor | `^5.22` | `^5.45` | `^5.22` | **`^5.29.1`** (was `^5.22`) | `horizon.middleware` works on every version; `pushMiddlewareToGroup('horizon')` would need 5.45. Raised in the phase B integration (2026-10-17) as the Q8 risk row allows: 5.22.0–5.29.0 declare `RedisQueue::pop($queue = null)`, a fatal error with Laravel ≥ 11.27.0, whose `pop($queue = null, $index = 0)` they extend; 5.29.1 is the first compatible release and still supports Laravel 11.23. |
| Config size | ~15 flat keys | ~70 keys | ~80 keys | **~25 keys; dashboard parameters are command options** | Documentation and SemVer surface. |
| Invalid config | 503 for the endpoint | 500 | normalise and report | **Normalise and report; fail closed for security values** | A typo must never silence all monitoring. |
| Default throttle | none | 240/min | 120/min | **None (opt-in recipe)** | Infinity runs in the Grafana backend, so all viewers share one key. A 429 means empty tables during an incident. |
| Tokens | single token | list | list + api_token + IPs | **List + optional api_token + optional IP allow-list (AND)** | Zero-downtime rotation and a separate blast radius for Grafana. |
| `horizon_current_processes` | drop | keep + `connection` | keep + `connection` | **Drop** | Identical to `sum by (queue)(horizon_supervisor_processes)`. The `QUEUE_BUSY` rewrite is specified in Q5. |
| Oldest-age name | keep | `_wait_seconds` | `_age_seconds` | **`queue_oldest_pending_job_age_seconds`** | The meaning changes (C8), and `_seconds` is the conventional unit suffix. |
| `connection` on supervisor process gauges | no | yes | yes | **No** | `supervisor` already implies the connection; keeps office continuity. |
| Unnamed batch kind | `unnamed` | `unnamed` | `(unnamed)` | **`unnamed`** | Avoids a needless value break. |
| Closure job names | raw | `Closure` | `Closure (file)` | **`Closure (file)`**, also applied to job-list filters | Stable across edits, and the file stays visible. |
| Stale job classes | automatic prune in Lua | hourly prune | manual `prune` command | **Automatic prune in Lua + lazy `HDEL` at read** | The cap never fills up silently. |
| Closed-batch window | 24 h | 24 h | 1 h | **1 h (internal constant)** | The dashboard reads per-batch series with `last_over_time(…[$__range])`, so 1 h is enough and active series drop about 24×. |
| Sync/deferred/background exclusion | `RedisJob` rule | `SyncJob` + allow-list | allow-list + `SyncJob` | **`RedisJob` that passed `JobProcessing`** | Exact, and needs no config. |
| In-flight attempt map | `spl_object_id` | `WeakMap` | `WeakMap` | **`WeakMap`** | Object ids are reused after garbage collection. |
| Worker-path timeouts | README advice | `check` warns | private 1 s client | **Private client (forced `persistent=false`)** | Bounds C11 deterministically, and never shares a phpredis persistent socket with the app. |
| Horizon repositories on the scrape path | container | container | re-instantiated with a private manager | **Container-bound** | Re-instantiation depends on constructor internals that change between 5.22 and 5.50. |
| C20 timeout path | separate connection | `purge()` of the shared connection | separate private client | **Separate private client; never purge** | `purge()` would break Horizon's own pipeline under `killOnTimeout=false`. |
| Redis Cluster | "Horizon does not support it" | where Horizon supports it | queue side unsupported | **Where Horizon supports it (Horizon ≥ 5.49 on Laravel ≥ 13.20); storage on any cluster** | Verified in Horizon 5.49/5.50 `Horizon::use()` and `hasHashTag()`. |
| Reset-on-restart toggle | – | – | toggle | **No toggle** | Resetting counters to 0 is always safe for `rate()` and `increase()`. |
| `ready_at` legacy fallback | separate pull | folded into the record `EVAL` | folded into the record `EVAL` | **Folded into the record `EVAL`; no config key** | One round trip per attempt. |
| Version detection | `InstalledVersions` | `InstalledVersions::satisfies` | `::satisfies` | **`InstalledVersions::getVersion()` + `version_compare`** | `composer/semver` is not a runtime dependency of `laravel/framework` (verified), so `satisfies()` may fatal. |
| `optimizes()` floor | "absent on L11" | 11.30 | 11.28 | **11.27.1**, guarded with `method_exists` | Verified upstream: absent in 11.27.0, present in 11.27.1. |
| Larastan level | 8 | 9 | 9 | **9** | Forces narrowing of `mixed` Redis replies, which is where the predis/phpredis shape bugs lived. |
| Public extension contracts | none | 4 + model | 4 + model | **None in v0.x (two hooks only)** | Small SemVer surface; contracts are additive later. |
| Commands | grafana, cache, clear | + install, check, flush | + install, check, prune | **install, check, grafana, cache, clear, flush** (+ `stream`, Addendum R4.6) | `check` gates deploys. Data deletion stays out of `optimize:clear`. |
| Releases | 0.x | 1.0.0-rc | 1.0.0-beta | **0.x, then 1.0.0 after office production** | Office installs need no stability flags. |

### 0.4 Facts this design relies on

"Verified" means checked against upstream sources, either by this document or by the reviews. File and line references are to the cited versions.

**Laravel**

| Fact | Evidence |
|---|---|
| `JobAttempted` first exists in 11.23.0. 11.23 to 12.x expose `public $exceptionOccurred` (bool); 13.0 exposes `public $exception` (`?Throwable`). | Raw `Events/JobAttempted.php` at v11.23.0, v12.0.0 and v13.0.0 (verified here) |
| `JobTimedOut::$timeout` exists from 13.25.0. `Worker::$killOnTimeout` exists from 13.33.0; when it is false, the SIGALRM handler fires `JobTimedOut` and then throws, so `JobAttempted` follows for the same attempt. | Review bisect; v13.37.0 `Worker.php:351-356,658` |
| `SyncQueue` fires `JobAttempted` from 12.45.0. `DeferredQueue` and `BackgroundQueue` appear in 12.45.0 and extend `SyncQueue` (so they produce `SyncJob`). | Review bisect |
| The payload keys `delay` and `createdAt` exist from 12.11.0. Horizon passes the delay into the payload from 5.49.0. | `Queue.php` v12.11.0; Horizon v5.49.0 `RedisQueue::later` |
| `ServiceProvider::optimizes()` exists from **11.27.1** and is absent in 11.27.0. | Verified here |
| `PreventRequestsDuringMaintenance::except()` is static in 11.23.0. | Verified here |
| `Application::boot()` fires booting callbacks before any provider's `boot()`. | v11.23.0 `Application.php:1097-1101` |
| The router returns the first registered match; only fallback routes are moved last. `CompiledUrlMatcherDumper` keeps a static route static unless an *earlier* dynamic route matches it. | `AbstractRouteCollection.php:79-91`; Symfony dumper `:164-202` |
| Laravel's phpredis `evalsha()` runs `SCRIPT LOAD` on every call. | `PhpRedisConnection.php:430-435` |
| `composer/semver` is not required by `laravel/framework`; only `composer-runtime-api` is. | `vendor/laravel/framework/composer.json`, verified here |

**Horizon**

| Fact | Evidence |
|---|---|
| Horizon supports Laravel 11 from 5.22.0, Laravel 12 from 5.30.3 and Laravel 13 from 5.44.0. 5.22.0 requires `illuminate/* ^8.17\|…\|^11.0`. | Packagist; verified here |
| Laravel 11.27.0 adds `$index` to `Illuminate\Queue\RedisQueue::pop($queue = null, $index = 0)` (11.26.0 has `pop($queue = null)`). Horizon's `RedisQueue::pop($queue = null)` up to 5.29.0 is therefore a fatal declaration error on Laravel ≥ 11.27.0; 5.29.1 declares `pop($queue = null, $index = 0)` and requires `illuminate/* ^9.21\|^10.0\|^11.0`. Hence the floor `^5.29.1`. | upstream tags v11.26.0/v11.27.0 and Horizon v5.29.0/v5.29.1 `src/RedisQueue.php`, checked 2026-10-17; reproduced on the L11 prefer-lowest leg by T04 and T09 |
| 5.22 registers its routes in `boot()` with `'middleware' => config('horizon.middleware', 'web')` and skips them when routes are cached. | v5.22.0 `HorizonServiceProvider.php:52-62`, verified here |
| From 5.45 the `horizon` group is defined in `boot()` as `[SentinelMiddleware::class.':horizon', ...config('horizon.middleware', ['web'])]`. The spread means the config value must be an array. | v5.50.1 `HorizonServiceProvider.php:22-26`, verified here |
| The SPA owns `/metrics`: `routes.js:55-56`, `layout.blade.php:70`, history mode. The catch-all is `Route::get('/{view?}')->where('view', '(.*)')`. | v5.50.1 |
| From 5.49 Horizon reads `database.redis.clusters.<use>`, writes `clusters.horizon`, and hash-tags its prefix when `Connection::hasHashTag` exists. That method is absent in Laravel 12.70 and 13.0 and present in 13.20. | `Horizon.php:109-177` at 5.49.0 and 5.50.0, verified here; framework per review |
| A Horizon retry keeps the old payload, sets `attempts=0` and `retry_of`, and `JobPayload::prepare` overwrites `pushedAt`. | Horizon v5.49.0 `RetryFailedJob`, `JobPayload` |
| The sidebar markers `class="col-2 sidebar"` and the first `</li>` after them are identical in 5.22.0 and 5.50.1. | `layout.blade.php:51`, verified here |

**Grafana and Prometheus**

| Fact | Evidence |
|---|---|
| Alert provisioning accepts `.yaml`, `.yml` and `.json`, and **parses all of them with `yaml.Unmarshal`**. `uid`, `title`, `folder`, `condition`, `for` and `datasourceUid` are env-interpolated `StringValue`s. `annotations` and the query `model` are taken **raw** (`Annotations.Raw`, `json.Marshal(Model.Raw)`). | grafana main `provisioning/alerting/config_reader.go:59-77`, `rules_types.go:150,203`, verified here |
| Infinity uses `jsonData.auth_method: bearerToken` and `secureJsonData.bearerToken`. `allowedHosts` is only required when no base URL is set; we set it anyway. | `pkg/models/settings.go:181-189` |
| Prometheus reserves the `Host` header and sends `X-Prometheus-Scrape-Timeout-Seconds`. | `prometheus/common config/headers.go` |
| promphp 2.15.1 writes HELP unescaped and formats floats with a `(string)` cast (`precision=14`). | `RenderTextFormat.php:27`; local probe |
| The generator output with `python3 -I … --prefix office` is byte-identical to the committed `queues.json` on Python 3.12 and 3.13. Python 3.11 fails with a SyntaxError. The dashboard has 65 targets and about 100.8 KB of PromQL, and uses `le` only inside `by (le)`. | Audit `planning/audit/dashboard-contract.md` |

**Tooling**

| Fact | Evidence |
|---|---|
| testbench 9.5.x needs Laravel `^11.11` (9.6 needs `^11.31`); 10.0.0 needs `^12.0.1`; 11.0.0 needs `^13.0` and PHP 8.3. testbench-core 9.5.0 supports `discovers.config` and `defineCacheRoutes()`. | Packagist; GitHub raw, verified here |
| Pest 3 needs PHP ^8.2 and PHPUnit ^11.5; Pest 4 needs PHP ^8.3 and PHPUnit 12. pest-plugin-laravel 4.x needs Laravel ≥ 11.45.2. Larastan 3.0–3.1 allow `^11.15`; 3.3.1+ need `^11.44.2`; `^13` arrived in 3.9.3. Pint ≥ 1.31 and rector-laravel ≥ 2.6 need PHP 8.3. | Packagist (reviews) |

---

## Q1. Exposition

### Decision

#### 1.1 Renderer

The package has its own renderer, `Metrics\TextFormat`, for Prometheus text format 0.0.4. There is no promphp dependency.

- **Headers:** `Content-Type: text/plain; version=0.0.4; charset=utf-8` and `Cache-Control: no-store`. HEAD is supported.
- **Escaping:** HELP escapes `\` → `\\` and newline → `\n`. Label values escape `\`, `"` and newline. HELP texts are ASCII and never contain the namespace.
- **Sample values:**
  - integers, and integral floats with absolute value below 2^53, are printed as integers;
  - other floats go through `var_export()`, which is the shortest round-trip form under the default `serialize_precision=-1`. A `(string)` cast is never used;
  - special values print as `+Inf`, `-Inf` and `NaN`.
- **`le` values** keep today's spelling: an integral bound prints without a decimal (`1`, `60`), others print in shortest form (`0.05`, `2.5`), and the last bucket is `+Inf`. Buckets are emitted in ascending order, then `_sum` and `_count` for each series.
- **Order:**
  - families appear in `Metric` declaration order, with self-metrics last;
  - inside a family, samples are sorted by label values, which keeps golden files stable;
  - empty families are omitted;
  - the output ends with one newline.

#### 1.2 Family model

- `enum Metric: string` is the **single catalog**, with one case per family; Appendix B has the full list. Each case defines:
  - its value, the family name without the namespace;
  - `type(): MetricType` (`counter`, `gauge` or `histogram`);
  - `help(): string`;
  - `labels(): list<string>`;
  - `collector(): string`, the owning collector id;
  - `since(): string`.

  Collectors, the README metric table, the Grafana renderer (labels for dedupe and filters) and the contract tests all read this enum.
- `MetricFamily::of(Metric $metric)` is a per-scrape builder:
  - `set(array<string,string> $labels, int|float $value)` for counters and gauges;
  - `observe(array<string,string> $labels, array<string,int> $cumulativeBuckets, float $sum)` for histograms. The buckets are keyed by `le`, ascending and non-decreasing, and must include `+Inf`. `_count` is the `+Inf` bucket by construction.

  Validation runs on every call:
  - the label keys must equal `labels()` as a set;
  - values pass through `Labels::sanitize()`, which scrubs invalid UTF-8, strips control characters including `\x1f`, and cuts at 200 bytes with a `...` suffix;
  - a duplicate label set throws `InvalidMetric`, so collectors aggregate first;
  - `le` is reserved for histograms.

  Any violation throws `InvalidMetric` (a `LogicException`), which the exporter treats as a failure of that collector.
- **Real histograms.** `queue_job_duration_seconds` and `queue_job_wait_seconds` render as `# TYPE <ns>_queue_job_duration_seconds histogram` with `_bucket{…,le}`, `_sum` and `_count`. The series names are unchanged, so the dashboard queries are unchanged. Storage stays non-cumulative per bucket. `HistogramSnapshot` folds stored bounds that are no longer configured into the next configured bound, or `+Inf`, so changing `buckets` never corrupts history. Counter family names keep the `_total` suffix (`queue_jobs_total`).
- **Reserved label names.** No family may use `job`, `instance`, `le` (except histograms) or any `__*` name. A catalog unit test asserts this.
- **Namespace.** The exporter, and only the exporter, prefixes every family with `<namespace>_`; an empty namespace adds no prefix.

#### 1.3 Exporter, isolation and budget

`Metrics\Exporter::scrape(Request $request): string` runs on every request. The controller builds one `ScrapeContext` per request. Nothing is resolved at boot and there is no static memo.

```php
$deadline = min(self::BUDGET /* 8.0 s */, max(1.0, (float) $request->header('X-Prometheus-Scrape-Timeout-Seconds', '9') - 0.5));
foreach (self::ORDER as $id) {                       // horizon, queues, attempts, job_classes, batches
    if (! $collectorEnabled($id)) continue;           // disabled / auto-disabled: no families, no self-metric
    if ($ctx->deadlineExceeded()) { $status[$id] = [0, 0.0]; $reporter->warnOnce("budget:$id", ...); continue; }
    $t = hrtime(true);
    try {
        $families = [...$collector->collect($ctx)];   // fully materialised
        $ok = $ctx->takeFailures() === [] ? 1 : 0;     // explicit partial failures keep data but mark 0
        $out[] = $families;
    } catch (Throwable $e) {                           // incl. ErrorException and InvalidMetric
        $ctx->takeFailures(); $ok = 0;
        $reporter->report("collector:$id", $e);        // throttled; never report()/Sentry
    }
    $status[$id] = [$ok, (hrtime(true) - $t) / 1e9];
}
```

- **Order.** Collectors run in order of importance, so an exhausted budget drops the least valuable data first: `horizon`, `queues`, `attempts`, `job_classes`, `batches`.
- **Partial failure.** `ScrapeContext::reportFailure(string $source, Throwable $e)` lets a collector keep what it did read but report `success=0`. Two cases use it: the `horizon` collector when one Horizon repository read fails, and the `queues` collector when one queue's Lua fails or the budget runs out between queues.
- **Shared reads.** `ScrapeContext::remember($key, $resolver)` memoises shared sources (topology, supervisor snapshots). It caches the exception too, so a failing source is read once per scrape.
- **Status code.** The response is **200** whenever the request is authorised, even if every collector failed or the config has problems. `up` therefore means "reachable and authorised"; data health is carried by the self-metrics. There is no 5xx for config errors (Q3).
- **Bounded reads.** Every scrape-path Redis read of queue keys and package keys uses a reader client with `redis.scrape_timeout` (2.0 s). Horizon repositories are container-bound and use the app's own connection settings; `check` warns when the `horizon` connection has no `read_timeout`.

#### 1.4 Self-metrics

All self-metrics carry the namespace prefix and the family prefix `exporter_` (Addendum R1), so `{__name__=~"ho_exporter_.+"}` lists all package health series.

| Family | Type | Labels | Meaning |
|---|---|---|---|
| `exporter_collector_success` | gauge | `collector` | 1 or 0 for each enabled collector (`horizon`, `queues`, `attempts`, `job_classes`, `batches`) |
| `exporter_collector_duration_seconds` | gauge | `collector` | wall time of each collector |
| `exporter_build_info` | gauge (1) | `version`, `php`, `laravel`, `horizon`, `redis_client` | what is running |
| `exporter_config_problems` | gauge | – | number of normalised config problems (Q3) |
| `exporter_attempts_dropped_total` | counter | – | attempts not recorded because the worker-side breaker was open. Global, read from storage `meta`, owned by the `attempts` collector. |
| `exporter_live_push_timestamp_seconds` | gauge | – | Unix time of the last successful Grafana Live push by any stream leader; absent until the first push. Read from storage `meta`, owned by the `attempts` collector (Addendum R4.11). |
| `exporter_live_push_failures_total` | counter | – | failed Grafana Live pushes. Read from storage `meta`, owned by the `attempts` collector (Addendum R4.11). |

Total scrape time is Prometheus' own `scrape_duration_seconds`.

#### 1.5 Multiple replicas

Every business series is global: it is read from shared Redis or the DB, so every replica returns identical values.

1. **The contract: one logical scrape target per application environment.** That is a static target on the Service DNS name, the load balancer or the host. Whichever replica answers, the series is continuous, because the `instance` label is the target address and every replica reads the same counters. The package endpoint is separate from per-pod app metrics (spatie and others keep their own `/metrics`), so this costs nothing. `install` prints this scrape config, and the README explains why.
2. **Defence in depth in every rendered query.** The PHP PromQL builder (Q5) wraps every package selector as `max by (job, <family labels>) (…)` before any outer aggregation. Range functions are wrapped from outside:

   ```
   max by (job, connection, queue, job_class, status) (increase(<ns>_queue_jobs_total{…}[15m]))
   ```

   Histogram bucket selectors include `le` in the list. Identical copies collapse to one series per Prometheus `job`.

   Limitation, documented: under per-pod discovery `increase()` is computed per pod series. When every pod is replaced inside the window, `max` picks the largest partial value and undercounts. Gauges are exact.
3. **The alert rules** use the same form, which also fixes the failure-ratio rule. Its `clamp_min(sum(total), 100)` floor is not invariant under N copies, contrary to operability's claim.
4. **Opt-in health rules** (`--health-rules`, Q5):
   - `<uid>-duplicates`: `count by (job) (<ns>_horizon_status) > 1` for 10m, which detects per-pod scraping;
   - `<uid>-collector`: `min by (job, collector) (<ns>_exporter_collector_success) < 1` for 15m.

   Auto-disabled collectors emit no success series, so they never false-fire.

### Rationale

- **Own renderer.** Text format 0.0.4 is about 60 lines of logic. Owning it removes a dependency and its array-shaped, semi-internal `MetricFamilySamples` API, and it fixes two real promphp defects (unescaped HELP, and precision loss: `1.2345678901235E+17` instead of `1.2345678901234568E+17`). Rendering absolute values with no registry removes the whole bug class behind C3.
- **One catalog** makes the dashboard contract testable and the README generatable.
- **Isolation in one place, the exporter,** makes the C1 and C5 shapes impossible: no collector code can produce a 5xx. The budget stops PHP-FPM workers piling up behind a slow Redis, because Prometheus gives up after `scrape_timeout` while FPM keeps running.
- **Replicas.** Query-side dedupe is stateless, needs no coordination and is correct for gauges. The one-target contract is what makes counters exact. Each one covers the other's gap.

### Rejected alternatives

- **promphp `RenderTextFormat` with `MetricFamilySamples`:** HELP is not escaped, floats lose precision, the API is untyped, and we would use under 5 % of the library.
- **An optional spatie bridge:** excluded by the owner, and it would bring back the shared registry and cache bugs (C3, C17).
- **OpenMetrics output:** it needs content negotiation, `# EOF` and stricter `_total`/`_created` rules, and Prometheus 2 and 3 ingest 0.0.4 natively. It can be added later as a minor feature.
- **Leader election through a Redis lease:** one extra `EVAL` per scrape per replica, up to `leader_ttl` with no data after a leader dies, series churn on handoff, and a second topology to document.
- **`honor_labels` with a fixed `instance`:** several targets write the same series, which causes duplicate and out-of-order samples.
- **Relabel-dropping the series on N−1 targets** (today's README advice): pays the Redis and SQL cost N times and is easy to get wrong.
- **Sorting families by name:** catalog order is just as deterministic and groups related families.

### Risks

| Risk | Mitigation |
|---|---|
| Renderer edge cases | Unit tests (escaping, `1e17` precision, `NaN`/`±Inf`, `le` spelling); a golden exposition; a parser round-trip; `promtool check metrics` in CI on a seeded full scrape. |
| `up` stays 1 while a collector fails | Self-metrics plus the opt-in `<uid>-collector` rule. "No queue metrics" still fires when `horizon_status` disappears. |
| Dedupe grows the PromQL by about 10–15 % | Evaluation cost is negligible next to the existing expressions of up to 7 KB. The builder emits the wrappers, not a regex (Q5). |
| Users run per-pod discovery anyway | Dedupe keeps gauges exact and counters approximately right; the duplicates rule and the README explain the fix. |

---

## Q2. HTTP surface

### Decision

#### 2.1 Routes

| Method | Default path | Name | Route middleware, in order | Response |
|---|---|---|---|---|
| GET, HEAD | `/horizon/observability/metrics` | `horizon-observability.metrics` | `Authorize:metrics`, then `...config('horizon-observability.middleware')` | text 0.0.4 |
| GET, HEAD | `/horizon/observability/jobs/{list}`, with `whereIn('list', ['pending','completed','failed','delayed'])` | `horizon-observability.jobs` | `ForceJson`, `Authorize:api`, then `...middleware` | `{"data":[…]}` JSON (`JSON_UNESCAPED_SLASHES\|JSON_UNESCAPED_UNICODE`), `Cache-Control: no-store` |
| GET, HEAD | `/horizon/observability/live`, registered only when `live.enabled` (Addendum R4.3) | `horizon-observability.live` | `ForceJson`, `Authorize:api`, then `...middleware` | live snapshot JSON, schema v1 (Addendum R4.4), `Cache-Control: no-store` |

The live endpoint takes the same `var-*` filters as the job lists, parsed by the shared `JobListQuery::filtersFromRequest()` (Addendum R4.3, C′), and answers 200 with `errors[]` on partial failures.

**Base path.** `Http\RoutePath::base()` resolves it:
1. If `horizon-observability.path` is non-empty, use it, trimmed of `/`. Office may set `metrics/horizon`.
2. Otherwise take `trim(config('horizon.path'), '/')`, use `horizon` if that is empty, and append `/observability`. Examples:
   - `horizon` → `horizon/observability`;
   - `admin/horizon` → `admin/horizon/observability`;
   - `''` or `/` (Horizon served at the root of its own domain) → `horizon/observability`, so the package never registers a bare `/observability` or `/metrics`.

`RoutePath::apiPath()` returns `'/'.base` and is exported as `horizon_info{api_path}`.

**Domain.** The routes have no domain constraint unless `horizon-observability.domain` is set. `horizon.domain` is never inherited.

**Groups.** The routes are in neither `web` nor Horizon's `horizon` group: no session, no cookies, no CSRF, no Sentinel. Only the application's global middleware applies.

**Job-list request contract.** It is unchanged except for the path.
- `limit`: integer 1–1000, default 500.
- `from` and `to`: Unix milliseconds, optional. `delayed` ignores them.
- Repeated `var-supervisor`, `var-batch_kind`, `var-queue`, `var-job_class` in Grafana `${var:queryparam}` form. A missing parameter or the value `$__all` means no filter.
- Unknown `var-*` parameters are ignored. `JobListQuery::VARIABLES = ['supervisor', 'batch_kind', 'queue', 'job_class']` pins the names, and a contract test compares them with the dashboard.
- Invalid input returns 422 JSON.

**Job-list response contract.** The field names and units are unchanged from today. Times are epoch ms; `runtime` is seconds rounded to 3 decimals.

| List | Fields |
|---|---|
| pending | `id`, `name`, `queue`, `queued_at`, `horizon_path` (`jobs/pending/<id>`) |
| completed | `id`, `name`, `queue`, `queued_at`, `completed_at`, `runtime`, `horizon_path` (`jobs/completed/<id>`) |
| failed | `id`, `name`, `queue`, `attempts`, `failed_at`, `runtime` (nullable), `exception` (nullable; depends on the `exceptions` mode), `horizon_path` (`failed/<id>`) |
| delayed | `id`, `name`, `queue`, `attempts`, `queued_at` (nullable), `available_at` |

#### 2.2 Registration that Horizon's catch-all never shadows

```php
// HorizonObservabilityServiceProvider::register()
$this->mergeConfigFrom(__DIR__.'/../config/horizon-observability.php', 'horizon-observability');
// ... singletons (Q7) ...
$this->app->isBooted()
    ? $this->beforeProvidersBoot(late: true)
    : $this->app->booting(fn () => $this->beforeProvidersBoot());   // APP-level booting callback

private function beforeProvidersBoot(bool $late = false): void
{
    $config = $this->app['config'];
    if (! filter_var($config->get('horizon-observability.enabled', true), FILTER_VALIDATE_BOOL)) {
        return;                                                       // inert: no routes, no menu link
    }
    $this->appendHorizonMenuMiddleware($config);                      // Q7; Horizon reads it in its boot()
    if ($this->app instanceof CachesRoutes && $this->app->routesAreCached()) {
        return;                                                       // our routes are inside the cache
    }
    if ($late || Route::has('horizon.index')) {
        $this->app->make(FailureReporter::class)->warnOnce('routes.late',
            'horizon-observability: provider registered after boot; Horizon\'s catch-all route may shadow the endpoints.');
    }
    Route::group([
        'prefix' => RoutePath::base($config),
        'domain' => $config->get('horizon-observability.domain') ?: null,
    ], __DIR__.'/../routes/horizon-observability.php');
}
```

```php
// routes/horizon-observability.php
$extra = array_values(array_filter((array) config('horizon-observability.middleware', []), 'is_string'));

Route::get('metrics', MetricsController::class)
    ->middleware([Authorize::class.':metrics', ...$extra])
    ->name('horizon-observability.metrics');

Route::get('jobs/{list}', JobListController::class)
    ->whereIn('list', JobList::values())
    ->middleware([ForceJson::class, Authorize::class.':api', ...$extra])
    ->name('horizon-observability.jobs');

if (filter_var(config('horizon-observability.live.enabled', true), FILTER_VALIDATE_BOOL)) {   // Addendum R4.3
    Route::get('live', LiveController::class)
        ->middleware([ForceJson::class, Authorize::class.':api', ...$extra])
        ->name('horizon-observability.live');
}
```

The configured middleware is attached **per route, after `Authorize`**, not as group middleware (group middleware would run before route middleware). This fixes the contradiction in horizon-native. Our two classes are not in the kernel's `$middlewarePriority` list, so `SortedMiddleware` never moves priority middleware such as `throttle` in front of them. A feature test asserts that an unauthenticated request causes no cache write.

**Proof that the package's routes always win:**

1. **Fixed registration order.** All `register()` methods run first. Then `Application::boot()` fires `$bootingCallbacks`, *then* walks the providers' `boot()`. Horizon adds its routes, catch-all last, inside `HorizonServiceProvider::boot()` (5.22 through 5.50). Our routes are therefore earlier in the `RouteCollection` whatever the provider order: alphabetical package discovery (`laravel/horizon` sorts before `sushi-market/…`), a manual list, or testbench. `horizon.path` is final by then, because Horizon's `mergeConfigFrom` ran in its `register()`. App routes (`withRouting` or RouteServiceProvider) load in a `booted` callback, later still.
2. **Uncached matching.** `AbstractRouteCollection::matchAgainstRoutes()` walks routes in insertion order and returns the first non-fallback match. Horizon's catch-all is a normal route, not `->fallback()`. The URI keys differ (`horizon/observability/metrics` against `horizon/{view?}`), so nothing is overwritten.
3. **Cached matching (`route:cache`).**
   - `route:cache` boots a fresh application (booting callbacks included) and dumps `RouteCollection::compile()`, which keeps insertion order.
   - `CompiledUrlMatcherDumper::groupStaticRoutes()` demotes a static route only when a dynamic route declared **earlier** matches it. Ours are earlier, so `…/metrics` stays in the static table, which is consulted before any regex. The dynamic `jobs/{list}` route precedes the catch-all in the ordered regex list.
   - At runtime both Horizon and we skip registration (`routesAreCached()`), so the cache alone decides.
4. **Probe evidence.** The horizon-native probe (`planning/reference/probes/route_order.php`; testbench 10, L12.70, Horizon 5.50.1, both provider orders, uncached and `compile()`d) gave:
   - `/horizon/observability/metrics` → ours;
   - `/horizon/observability/jobs/failed` → ours;
   - `/horizon/observability/jobs/nope` → `horizon.index`;
   - `/horizon/metrics` and `/horizon/metrics/jobs` → `horizon.index`;
   - a route added in `boot()` or `booted()` while Horizon boots first → `horizon.index`, so it loses (negative control).
5. **Enforced by tests and at runtime.** `tests/Feature/Provider/RoutePrecedenceTest` (which includes `/live`, Addendum Q9′) checks both provider orders, uncached, `defineCacheRoutes()` and a `CompiledRouteCollection` built with `compile()`, plus `horizon.path` variants and the negative control. `horizon-observability:check` runs the same match against the live router and fails on a shadowed route.
6. **Edge cases.**
   - A provider registered after boot (`App::register()` at runtime) registers immediately, which is too late. It is detected and logged once.
   - App-level catch-alls (`/{any}`) load after us.
   - With `horizon.domain` set, our host-less routes still precede Horizon's host-bound catch-all.

#### 2.3 Authentication and authorisation

`Http\AccessPolicy::decide(Request $request, 'metrics'|'api'): AccessDecision` is used by `Http\Middleware\Authorize` and reported by `about` and `check`.

1. **Callback.** If the app called `HorizonObservability::auth(fn (Request $r): bool => …)`, the callback alone decides, exactly like `Horizon::auth()`. Call it in the app's `HorizonServiceProvider::boot()`. Use it for mTLS headers, VPN headers or a custom gate.
2. **Built-in policy** otherwise:
   - **Tokens.**
     - `token` (env `HORIZON_OBSERVABILITY_TOKEN`) is a comma-separated list for rotation and is accepted on both endpoints.
     - `api_token` (env `HORIZON_OBSERVABILITY_API_TOKEN`), when set, is the token list for the job lists only, so Grafana can hold a different secret from Prometheus. When unset, the job lists accept `token`.
     - Tokens are read from `Authorization: Bearer …` only, never from the query string, and compared with `hash_equals` against every configured token. Tokens shorter than 16 characters are ignored and reported as a config problem (fail closed).
   - **IP allow-list.** `allowed_ips` (env `HORIZON_OBSERVABILITY_ALLOWED_IPS`, comma-separated IPs or CIDRs) is checked with `IpUtils::checkIp($request->ip(), …)`. It depends on correct TrustProxies; `check` warns when IPs are the only control.
   - **Both configured:** both must pass.
   - **Neither configured:** allowed only when `app()->environment('local')`, mirroring Horizon's default gate. Everywhere else the endpoints are **locked (403)**; `about` and `check` say so and name the env var.
3. **Responses.**
   - A token is configured and the request has none or a wrong one: **401** with `WWW-Authenticate: Bearer realm="horizon-observability"`.
   - IP mismatch, locked, local-only, or the callback returned false: **403**.
   - Bodies are `Unauthorized.` or `Forbidden.` as plain text on `/metrics`, and `{"message":"Unauthorized."}` on the job lists, because `ForceJson` runs first. The reason never appears in the body; it goes to one throttled log line per reason.
4. **No user and no session.** Horizon's `viewHorizon` gate, `Horizon::check()` and the `horizon` group are not used. They need a session user (`$request->user()`), Sentinel returns 401 to non-browser clients on 5.45+, and `web` would start a session on every scrape.

#### 2.4 Throttling and maintenance

- **No default throttle.**
  - `Authorize` runs before any Redis or DB work, so an unauthenticated flood costs one string comparison.
  - Authenticated work is bounded: `limit` ≤ 1000, internal scan caps, an empty resolved filter returns immediately (C13), and the scrape budget applies.
  - A default limit keyed by token or IP would be shared by every Grafana viewer, since Infinity queries come from the Grafana server, and would blank the tables during incidents.
  - Recipe: `'middleware' => ['throttle:600,1']`. It runs after `Authorize`.
- **Maintenance mode.** When `available_during_maintenance` is true (the default), `boot()` calls `PreventRequestsDuringMaintenance::except(["$base/metrics", "$base/jobs/pending", "$base/jobs/completed", "$base/jobs/failed", "$base/jobs/delayed", "$base/live"])` (`$base/live` from Addendum R4.3; the four lists are named instead of `$base/jobs/*` since the phase B integration, so an unknown list name answers 503 rather than reaching Horizon's catch-all), so queue visibility survives `php artisan down`. `HorizonObservability::flushState()` resets it in tests.

#### 2.5 What clients send

**Prometheus:**

```yaml
- job_name: myapp-horizon                 # becomes the dashboard's prometheus_job scope
  metrics_path: /horizon/observability/metrics
  scrape_interval: 10s                    # recommended; supported 5s-60s (Addendum R3.2)
  scrape_timeout: 8s                      # <= interval; exporter budget = min(8 s, timeout - 0.5 s)
  authorization: { type: Bearer, credentials_file: /etc/prometheus/secrets/horizon-observability-token }
  static_configs: [{ targets: ['myapp.internal:8080'] }]   # ONE logical target (Service / LB / host)
```

**Grafana Infinity.** The shipped example is `resources/grafana/provisioning/datasources/horizon-observability.yaml`. Calls are server-side, so there is no CORS and the token never reaches browsers.

```yaml
apiVersion: 1
datasources:
  - name: Horizon Observability API
    uid: horizon-observability-api
    type: yesoreyeram-infinity-datasource
    access: proxy
    url: ${HORIZON_OBSERVABILITY_APP_URL}          # internal base URL of the app, reachable from Grafana
    jsonData:
      auth_method: bearerToken
      allowedHosts: ['${HORIZON_OBSERVABILITY_APP_URL}']
      customHealthCheckEnabled: true
      customHealthCheckUrl: ${HORIZON_OBSERVABILITY_APP_URL}/horizon/observability/jobs/pending?limit=1
    secureJsonData:
      bearerToken: ${HORIZON_OBSERVABILITY_API_TOKEN}
```

The panels request `${api_path}/jobs/<list>?limit=500&from=${__from}&to=${__to}&${supervisor:queryparam}&${batch_kind:queryparam}&${queue:queryparam}&${job_class:queryparam}` with the backend parser. The relative URL is appended to the datasource URL.

### Rationale

- **The Horizon-relative path keeps the owner's intent.** "Everything Horizon is under `/horizon`" is discoverable. It follows a custom `horizon.path`. Ingress rules that already protect `/horizon*` cover the endpoints as defence in depth. The only real obstacle, shadowing by the catch-all, is removed deterministically by the registration phase.
- **`/observability/…` instead of `/metrics`** avoids the one URL Horizon owns.
- **Bearer tokens** are the one mechanism both Prometheus (`authorization`) and Infinity (`bearerToken`) support natively. They need no user model, session or DB lookup, and lists allow rotation.
- **The callback with an open-in-`local` default** is Horizon's own idiom applied to machine clients.

### Rejected alternatives

- **`/horizon/metrics` (owner's suggestion):** shadows Horizon's Metrics page; proved above.
- **`/horizon/metrics/{list}-jobs` with a browser 302:** the redirect can never fire, because `ForceJson` rewrites `Accept` before auth sees it.
- **Inheriting `horizon.domain`:** breaks pod and service scraping. It stays available as the opt-in `domain`.
- **A standalone default prefix (`/metrics/horizon`):** technically safe, but it loses the Horizon-relative discoverability. Office can still choose it.
- **Registering routes in `boot()`, `booted()` or with package-tools' `hasRoute()`:** order-dependent and shadowed (negative control).
- **`Route::fallback`, rewriting Horizon's `where('view', …)`, or reordering the `RouteCollection`:** they mutate another package's routes or rely on internals, and break with `route:cache`.
- **The `horizon` group, `viewHorizon`, Sanctum or Passport:** user and session based, or a DB hit per scrape.
- **Query-string tokens:** they leak into access logs and Grafana URLs.
- **spatie `AllowIps`:** open when the list is empty, which was C4.
- **A default throttle:** see 2.4.

### Risks

| Risk | Mitigation |
|---|---|
| A future Horizon adds an `observability` screen or route | Very unlikely. The weekly CI on the latest Horizon runs the route-precedence and SPA-path tests; `check` verifies in production. |
| SSO proxies protecting `/horizon/*` also block Prometheus | README: scrape the internal address, or add an exception for `/horizon/observability/`. |
| IP allow-lists behind `TrustProxies('*')` are spoofable | Tokens are recommended; `check` warns about IP-only setups. |
| `except()` is global static state | Opt-out key; reset in `flushState()`. |
| Staging accidentally runs with `APP_ENV=local` and an open endpoint | `about` and `check` print "open (local, no token)" prominently. |
| Token leakage | Never logged; `#[\SensitiveParameter]` on the methods that receive it; `hash_equals`. |

---

## Q3. Configuration

### Decision

- **One own file,** `config/horizon-observability.php`, config key `horizon-observability`, publish tag `horizon-observability-config`, env prefix `HORIZON_OBSERVABILITY_`. Appendix A has the complete file. The top-level keys are:
  - `enabled`, `namespace`
  - HTTP: `path`, `domain`, `token`, `api_token`, `allowed_ips`, `middleware`, `available_during_maintenance`, `exceptions`
  - `redis.connection`, `redis.worker_timeout`, `redis.scrape_timeout`
  - `collectors.{horizon,queues,attempts,job_classes,batches}`
  - `buckets`, `queue_scan_limit`, `max_job_classes`, `job_class_paths`
  - `horizon_url`, `grafana.url`, `grafana.tts_url`
  - `live.enabled`, `live.cache_ttl`, `live.stream.{grafana_url,token,id,interval}` (Addendum Appendix A′)
  - `log_channel`
- **Derived defaults, resolved at runtime and never written into the file,** so `config:cache` does not freeze build-stage paths. The file keeps `null` for these:
  - `path` null → `{horizon.path}/observability`;
  - `redis.connection` null → `horizon` (Horizon's own connection and prefix). The current pilot defaulted to `default`; office may keep it explicitly;
  - `job_class_paths` null → `[app_path()]`;
  - `horizon_url` null → `app.url` scheme, host and port, with the host replaced by `horizon.domain` if set, plus `/horizon.path`;
  - the topology is always Horizon's (`horizon.environments` for `horizon.env ?? app.env`) and the wait thresholds always come from `horizon.waits`.
- **The package reads Horizon's config and never writes it,** except for appending its own middleware class to `horizon.middleware`, which is Horizon's documented extension point (Q7).
- **Grafana render parameters** (uid, title, scope job, datasource uid, folder, scrape interval, health rules) are options of `horizon-observability:grafana`, not config: they concern Grafana, not the running app. Only the namespace and the base path, which the app owns, come from config.

**Environment variables (complete list):**

| Env var | Key | Default |
|---|---|---|
| `HORIZON_OBSERVABILITY_ENABLED` | `enabled` | `true` |
| `HORIZON_OBSERVABILITY_NAMESPACE` | `namespace` | `ho` |
| `HORIZON_OBSERVABILITY_PATH` | `path` | null (derived) |
| `HORIZON_OBSERVABILITY_DOMAIN` | `domain` | null (any host) |
| `HORIZON_OBSERVABILITY_TOKEN` | `token` | null |
| `HORIZON_OBSERVABILITY_API_TOKEN` | `api_token` | null (same as `token`) |
| `HORIZON_OBSERVABILITY_ALLOWED_IPS` | `allowed_ips` | null |
| `HORIZON_OBSERVABILITY_EXCEPTIONS` | `exceptions` | `full` |
| `HORIZON_OBSERVABILITY_REDIS_CONNECTION` | `redis.connection` | null (`horizon`) |
| `HORIZON_OBSERVABILITY_QUEUE_SCAN_LIMIT` | `queue_scan_limit` | `1000` |
| `HORIZON_OBSERVABILITY_HORIZON_URL` | `horizon_url` | null (derived) |
| `HORIZON_OBSERVABILITY_GRAFANA_URL` | `grafana.url` | null (no menu link) |
| `HORIZON_OBSERVABILITY_TTS_URL` | `grafana.tts_url` | null (tone only) |
| `HORIZON_OBSERVABILITY_LOG_CHANNEL` | `log_channel` | null (default channel) |
| `HORIZON_OBSERVABILITY_LIVE_ENABLED` | `live.enabled` | `true` |
| `HORIZON_OBSERVABILITY_LIVE_CACHE_TTL` | `live.cache_ttl` | `1.0` |
| `HORIZON_OBSERVABILITY_LIVE_GRAFANA_URL` | `live.stream.grafana_url` | null (stream command refuses to start) |
| `HORIZON_OBSERVABILITY_LIVE_GRAFANA_TOKEN` | `live.stream.token` | null (stream command refuses to start) |
| `HORIZON_OBSERVABILITY_LIVE_STREAM_ID` | `live.stream.id` | `horizon_observability` |
| `HORIZON_OBSERVABILITY_LIVE_STREAM_INTERVAL` | `live.stream.interval` | `1.0` |

These are used only in Grafana provisioning, never by PHP: `HORIZON_OBSERVABILITY_PROMETHEUS_UID`, `HORIZON_OBSERVABILITY_APP_URL`, `HORIZON_OBSERVABILITY_API_TOKEN`.

**Internal constants (deliberately not config; they can be promoted later without a break):**

| Area | Constants |
|---|---|
| Scrape | budget 8 s |
| Attempts | extremes in 15 s buckets, 10 buckets merged on read (150 s), key TTL 300 s (Addendum R3.4); job-class idle prune 30 days |
| Queue scan | per-queue class top-N 100; reserved scan limit 1000; queue scan byte cap 16 MB |
| Batches | running window 7 days; closed window 1 h; 200 per kind; 100 kinds and 2000 batches per scrape; SQL `LIMIT` 5000; batch-link TTL 7 days with per-process hourly dedupe (memo of 1000) |
| Job lists | scan 10 000 entries in chunks of 500; detail calls of 100 keys; exception text cap 1000 bytes |
| Breaker and logs | breaker cooldown 30 s; log throttle 300 s |
| Discovery and labels | runtime discovery cache 1 h; label value cap 200 bytes |
| Live (Addendum A′) | ring buckets 5 s, TTL 180 s, window 60 s; snapshot head scan 20 items and 1 MB per queue, stale copy `max(2 s, 10 × cache_ttl)`, build lock 2 s, follower wait 500 ms; stream leader TTL 5 s, push timeout 2 s (connect 1 s), backoff 1, 2, 4, 8, 15 s (60 s for 401/403) |

**Validation: normalise and report, never throw.**
- `Config\Config::fromRepository(Repository $config): Config` builds one immutable typed snapshot, lazily on first use, as a container singleton. It collects `ConfigProblem(path, message)` entries:

  | Key | Rule; on violation |
  |---|---|
  | `namespace` | must match `^[A-Za-z_][A-Za-z0-9_]*$` or be empty; a trailing `_` is stripped; invalid → `ho` + problem |
  | `path` | must not contain `{`, `..` or whitespace; invalid → derived default + problem |
  | `token`, `api_token` | split on commas and trimmed; entries under 16 characters dropped + problem (fail closed) |
  | `allowed_ips` | each entry must parse as an IP or CIDR; invalid entries dropped + problem (fail closed: the list stays non-empty when at least one was given) |
  | `middleware` | non-strings dropped + problem |
  | `exceptions` | `full`, `class` or `none`; invalid → `full` + problem |
  | `redis.connection` | must exist in `database.redis.<name>` **or** `database.redis.clusters.<name>`, because Horizon ≥ 5.49 writes `clusters.horizon` on clusters; a missing one is reported (writes will then fail through the breaker) |
  | `redis.*_timeout` | in (0, 30]; otherwise default + problem |
  | `collectors.*` | `horizon`, `queues`, `attempts`, `job_classes` are booleans; `batches` is true, false or null (auto); unknown keys + problem |
  | `buckets` | non-empty, finite, positive; sorted and deduplicated + problem if it was not |
  | `queue_scan_limit` | ≥ 0 |
  | `max_job_classes` | 1–10 000 |
  | `job_class_paths` | existing directories; missing ones dropped + problem |
  | `horizon_url`, `grafana.url` | http(s) URL or null |
  | `grafana.tts_url` | null, `browser` or an http(s) URL |
  | `live.enabled` | boolean (Addendum A′) |
  | `live.cache_ttl` | in [0, 10]; otherwise 1.0 + problem |
  | `live.stream.grafana_url` | comma-separated http(s) URLs; invalid entries dropped + problem |
  | `live.stream.token` | non-empty string or null; never printed by `about` or `check` (only "set" or "missing") |
  | `live.stream.id` | `^[A-Za-z0-9_-]{1,64}$`; otherwise the default + problem (Grafana does not validate it) |
  | `live.stream.interval` | in [0.5, 10]; otherwise 1.0 + problem |
- **Problems surface** through:
  - `php artisan horizon-observability:check`, which exits 1 and is meant for deploy pipelines;
  - the `about` section;
  - the `exporter_config_problems` self-metric;
  - one throttled log line.
- **`boot()` and the booting callback** read only `enabled`, `collectors.*`, `grafana.url`, `path`, `domain`, `middleware`, `available_during_maintenance` and `live.enabled` (route registration and the maintenance exception, Addendum A′), with safe casts and no validation, so a monitoring typo can never break the host app.
- **Only `Config\Config`, the provider, `Http\RoutePath` and `Horizon\*`** (which reads Horizon's own keys) call `config()`. An architecture test enforces this.

**Evaluation of the owner's idea (`config/horizon.php` → `'observability' => [...]`):**

| Criterion | Nested in `horizon.php` | Own file |
|---|---|---|
| Merge of defaults | `mergeConfigFrom($f, 'horizon.observability')` works and is shallow: a user's partial `observability.redis` array replaces our defaults for that subtree. | Equally shallow, and handled the same way by the typed `Config` with per-key defaults. **No difference.** |
| Install | `vendor:publish` cannot write a key into an existing published file. Users would copy a snippet by hand, and editing `horizon.php` from an installer means editing user PHP. | `vendor:publish --tag=horizon-observability-config`, offered by `install`. |
| Upgrades | Horizon's re-publish (`--tag=horizon-config --force`) silently drops the block. | Independent. |
| Users who never published `horizon.php` | Must publish Horizon's file just to configure us. | Unaffected. |
| Discoverability | Next to Horizon's options: a real plus. | `config:show horizon-observability`, the `about` section, the `check` output and the README table. |
| Config caching | Identical. | Identical. |
| Ownership | Squats in a first-party namespace; a future Horizon `observability` key collides. | Clean. |
| Hybrid ("nested if present, else own file") | Two sources of truth, a precedence rule in every support answer, double tests. | – |

### Rationale

- The idiomatic Laravel package experience is "publish my config, read the comments, set env vars". The tight coupling the owner wants is delivered by deriving from Horizon's config, not by co-owning Horizon's file.
- Normalise-and-report keeps monitoring alive on typos while making them impossible to miss in CI and `about`. Security values fail closed.
- Keeping the key count small (about 25) keeps the SemVer and documentation surface small. Tunables nobody has asked for stay internal constants.

### Rejected alternatives

- Nesting in `horizon.php`, and the hybrid (see the table).
- Grouping every concern in sub-arrays (the audit's 12-group, ~70-key shape): a larger frozen surface and more shallow-merge traps.
- Dashboard thresholds and uids in app config: they concern Grafana, so they are render options.
- Fail-fast validation at boot, or 5xx on invalid config: an add-on would crash requests or silence all metrics.
- Silent coercion without reporting: it hides mistakes.

### Risks

- **Users look for settings in `horizon.php`.** The README, `install` and `about` point to the file.
- **Docs and code drift.** A test asserts that every key in the config file appears in the README table and vice versa.
- **Lists in env vars** (tokens, IPs) are parsed in `Config`, not in the config file, so `config:cache` stays plain data.

---

## Q4. Metric naming

### Decision

**Namespace.**
- `namespace` (env `HORIZON_OBSERVABILITY_NAMESPACE`) defaults to **`ho`** (Addendum R1). The final name is `<namespace>_<family>`, or the bare family when the namespace is empty.
- `ho` is short and reads naturally (`ho_horizon_status`, `ho_queue_jobs_total`), avoids spatie's default `app_` (same family names, different semantics) and avoids generic bare `queue_*` names in a shared Prometheus.

**Office continuity (chosen).**
- Clean public names and office continuity do not conflict once the prefix is configurable and the family names stay stable. Office sets `HORIZON_OBSERVABILITY_NAMESPACE=office`.
- Of the 29 legacy families (histograms counted once), **25 keep byte-identical names and label sets**. Exactly four change, each for a reason: one is dropped, one is renamed for a changed meaning, and two change their label sets.
- Continuity means names, queries, recording rules, dashboard uid, alert uids and `GRAFANA_ALERTS` history (Q5, Appendix G). Prometheus series history still breaks once, because office must scrape a new target, so the `job` label changes, and because the counters restart on new storage keys.

**Old → new.** Old names carry office's `office_` prefix; new names carry `<ns>_`.

| Legacy family | New family | Type | Labels | Change |
|---|---|---|---|---|
| `horizon_status` (spatie collector, unguarded) | `horizon_status` | gauge | – | Own collector inside the isolated `horizon` collector. Values unchanged: 1 running, 0 at least one master paused, −1 no master. |
| `horizon_current_processes{queue}` | **dropped** | – | – | Identical to `sum by (queue) (horizon_supervisor_processes)`, because `RedisWorkloadRepository::processes()` sums the supervisors' `processes` maps. Dropping it also removes `WorkloadRepository::get()`. The dashboard rewrite is specified in Q5. |
| `horizon_supervisor_processes` | same | gauge | `supervisor`, `queue` | – (balance=false pools keep Horizon's comma-joined `queue`) |
| `horizon_supervisor_min_processes` | same | gauge | `supervisor` | – |
| `horizon_supervisor_max_processes` | same | gauge | `supervisor` | – |
| `horizon_supervisor_info` | same | gauge (1) | `supervisor`, `balance`, `connection` | – |
| `horizon_wait_threshold_seconds` | same | gauge | `connection`, `queue` | – (default 60 s; 0 is not emitted, as today) |
| `horizon_info{url,tts_url}` | `horizon_info` | gauge (1) | `url`, `api_path`, `tts_url` | **+`api_path`**, the job-list base path. The dashboard reads it with a 7-day lookback. |
| `queue_pending_jobs` | same | gauge | `connection`, `queue` | – (includes due delayed jobs) |
| `queue_delayed_jobs` | same | gauge | `connection`, `queue` | – |
| `queue_delayed_due_jobs` | same | gauge | `connection`, `queue` | – |
| `queue_oldest_pending_job_age` | **`queue_oldest_pending_job_age_seconds`** | gauge | `connection`, `queue` | **Renamed**: it is now measured from readiness of first-attempt jobs (C8), and the unit suffix is added. |
| `queue_jobs_pending` | same | gauge | `connection`, `queue`, `job_class` | English sentinels; top-100 per queue plus `(other)` |
| `queue_jobs_reserved` | same | gauge | `connection`, `queue`, `job_class` | same as above |
| `queue_job_classes` | same | gauge (1) | `job_class` | – (cached discovery) |
| `queue_jobs_total` | same | counter | `connection`, `queue`, `job_class`, `status` | – (zero-filled for all 5 statuses of every known tuple, as today) |
| `queue_job_duration_seconds_{bucket,sum,count}` (3 fake counter families) | `queue_job_duration_seconds` | **histogram** | `connection`, `queue`, `job_class` (+`le`) | Correct `TYPE`; series unchanged |
| `queue_job_wait_seconds_*` | `queue_job_wait_seconds` | **histogram** | `connection`, `queue` (+`le`) | Correct `TYPE`; series unchanged |
| `queue_job_duration_min_seconds` | same | gauge | `connection`, `queue`, `job_class` | – |
| `queue_job_duration_max_seconds` | same | gauge | `connection`, `queue`, `job_class` | – |
| `queue_batches_running` | same | gauge | `batch` | caps |
| `queue_batch_jobs` | same | gauge | `batch`, `state` | caps |
| `queue_batch_oldest_age_seconds` | same | gauge | `batch` | bounded by the 7-day running window |
| `queue_batch_job_counts` | same | gauge | `batch`, `name`, `state` | caps |
| `queue_batch_created_timestamp_seconds` | same | gauge | `batch`, `name` | caps |
| `queue_batch_closed_timestamp_seconds` | same | gauge | `batch`, `name` | caps; closed window 1 h |
| `queue_batch_job_classes` | same | gauge (1) | `batch`, `queue`, `job_class` | – |
| `queue_batch_info` | same | gauge (1) | `batch`, `queue`, `connection` | – (`connection` stays comma-joined) |
| `queue_batch_progress{batch,name,status,size,created,id}` | `queue_batch_progress` | gauge (percent) | `batch`, `name`, `status`, `id` | **`size` and `created` dropped.** They were never read, they churn, and `created` depended on the timezone. |
| – | `exporter_*` (7 families, Q1.4; Addendum R1) | – | – | new self-metrics |

The HELP texts for every family are in Appendix B. They are English, ASCII-only and contain no namespace.

**Fixed label values (contract, SemVer-covered):**

| Label | Values |
|---|---|
| `status` (attempts, `Attempts\AttemptStatus`) | `completed`, `released`, `retried`, `failed`, `timed_out`. `released` stays: it is a real outcome and keeps the totals additive. |
| `status` (batches, `Batches\BatchStatus`) | `Running`, `Finished`, `Failures`, `Cancelled` (capitalised; the dashboard displays them) |
| `state` (batches) | `total`, `pending`, `failed` |
| `balance` | `auto`, `simple`, `off` (`false` or anything unknown becomes `off`) |
| `supervisor` | the name after the last `:`, with the master prefix stripped. The dashboard's `label_replace(…, "supervisor-(.+)")` display rule leaves non-matching names unchanged. |
| `queue` | the logical queue name; balance=false supervisor families keep Horizon's pooled `a,b` |
| Sentinels (constants on `Metrics\Labels`) | `(unscanned)` replaces `(не просмотрено)` and marks pending jobs beyond the scan limit; `(unknown)` replaces `(неизвестно)` and marks an empty or missing `displayName`; `(other)` marks cardinality overflow; `unnamed` (unchanged) is a batch without a name |

No label value is empty, because Prometheus treats an empty value as an absent label. The one exception is `horizon_info{tts_url}`, which is absent when no TTS is configured ("tone only").

**`job_class` source.** The value is the payload `displayName` (Horizon's `name`), normalised by `Attempts\JobClassLabel::normalize()`:
- trim whitespace;
- `Closure (routes/web.php:42)` → `Closure (routes/web.php)`, because line numbers churn on every edit;
- empty → `(unknown)`;
- then `Labels::sanitize()`.

`displayName` is the only identity that matches Horizon's UI and job hashes. `data.commandName` would collapse queued listeners, notifications and mailables into `CallQueuedListener`, `SendQueuedNotifications` and `SendQueuedMailable`. The same normaliser applies to attempts, queue-state class counts (normalised in PHP and summed), batch links and the job-list `job_class` filter, which normalises the stored Horizon `name` before comparing. Values therefore match everywhere.

**Cardinality guards:**
1. **Attempts.** A last-seen ZSET (`job_classes`) is maintained atomically inside the record Lua.
   - A known class has its score refreshed.
   - A new class is admitted while the set has fewer than `max_job_classes` (default **500**) entries. When the set is full, entries idle for more than 30 days are first pruned (`ZREMRANGEBYSCORE`). If there is still no room, the attempt is recorded as `job_class="(other)"`.
   - The attempts collector drops fields whose class is no longer in the ZSET and lazily `HDEL`s them, at most 1000 per scrape, so gone classes stop being exported.
   - A throttled warning is logged the first time overflow happens.
2. **Queue state.** The top 100 classes per queue by count are kept; the rest are folded into `(other)`.
3. **Batches.**
   - At most 100 kinds per scrape (most recently active first); overflow kinds become `(other)` in the per-kind families.
   - Per-batch families cover at most 200 batches per kind and 2000 per scrape, only for exported kinds.
   - The closed window is 1 h. That is safe because every per-batch panel reads `last_over_time(…[$__range])`.
   - `size` and `created` are dropped.
   - The default `BatchKind` strips UUIDs, digit runs, and hex tokens of at least 6 characters that contain a digit (`export-ab3f9e` → `export`), collapses separators, trims ` _.:-` and turns an empty result into `unnamed`. `HorizonObservability::resolveBatchKindUsing(fn (?string $name, string $id): string)` replaces it.

**`connection` label.** Wherever it appears, it is the Laravel **queue connection** name (e.g. `redis`), never the Redis connection name:
- attempts use `$job->getConnectionName()`;
- queue state and waits use Horizon's `connection:queue` topology and `horizon.waits` keys;
- supervisor info uses the supervisor's `connection` option.

`queue_job_classes` (classes have no queue) and `queue_batch_job_classes` have no `connection`. `queue_batch_info.connection` is comma-joined. The dashboard groups by `queue` (single-connection assumption, documented).

**Dead series and labels:**
- **Dropped:** `horizon_current_processes`, `queue_batch_progress{size,created}`, spatie's unguarded `HorizonStatusCollector`, the spatie registration path, and the dead generator code (`STATUS_EXPR`, `batches_table`).
- **Kept although the dashboard does not chart them:** `status="released"`, the wait histogram's `_sum`/`_count` (part of the histogram type) and the `id` field in JSON rows.

**SemVer contract** (README "Stability" section):
- Covered: family name, type, label names, the documented label value sets and units.
- Not covered: HELP texts, sample order, self-metric duration values.
- Adding a family is minor. Adding, removing or renaming a label or family, or changing semantics, is major (in 0.x: minor, with an UPGRADE entry).

### Rationale

- **Keeping the family names** gives office continuity with one env var, and nothing argues for renaming: with an own endpoint and job scoping, the spatie collision (C17) cannot recur.
- **The one rename marks a real semantic change,** so old and new data are never silently compared in one range or alert.
- **Dropping a redundant family** removes Redis work per scrape.
- **Not adding `connection`** to the supervisor gauges keeps office's series identity, because `supervisor` already determines the connection (`horizon_supervisor_info`).

### Rejected alternatives

- **Namespace `''`:** generic names. **`app`:** collides with spatie semantics. **`horizon`:** gives `horizon_horizon_status`, or a restructuring that breaks continuity. **`horizon_observability_` as the business prefix:** long names, and continuity becomes impossible. **`laravel`** (the earlier default): overruled by the owner (Addendum R1).
- **Keeping `queue_oldest_pending_job_age` with the new meaning:** a silent redefinition of history.
- **`queue_oldest_pending_job_wait_seconds`:** awkward, and `_age_seconds` is the conventional fix.
- **Moving `tts_url` out of `horizon_info`:** it would turn a runtime setting into a render-time one, so a TTS change would need re-provisioning.
- **`(unnamed)`:** a needless value break.
- **`job_class` from `commandName`:** collapses wrappers.
- **Manual-only pruning:** the cap silently fills up.
- **Relabel-drop in Prometheus for cardinality:** the cost is paid in Redis and PHP before the drop.

### Risks

| Risk | Mitigation |
|---|---|
| Apps with more than 500 live job names see `(other)` | Config key, a throttled overflow warning, and the cap usage shown in `check`. |
| Office's ad-hoc queries on `office_horizon_current_processes` or `office_queue_oldest_pending_job_age` | Listed in UPGRADE with replacements. |
| Batch names may contain PII | Documented in SECURITY.md; the kind resolver hook can hash or strip them. |

---

## Q5. Dashboard and alerts

### Decision

#### 5.1 One PHP renderer for both assets

- The Python generator is ported to PHP under `src/Grafana/**` (all `@internal`; the output files are the contract, not the classes) and exposed as:

  ```
  php artisan horizon-observability:grafana [path=grafana]
      {--defaults}                  ignore app config; render with package defaults (used for the committed assets)
      {--check}                     render in memory; exit 1 and print a diff summary if files differ
      {--uid=horizon-observability} dashboard uid, [A-Za-z0-9_-]{1,29}; rule uids append <= 11 chars (<= 40 total)
      {--title=Queues}
      {--job=}                      default selection of the hidden prometheus_job variable and the alert scope; empty = all jobs (.+)
      {--datasource-uid=}           literal Prometheus uid for alert rules; default: ${HORIZON_OBSERVABILITY_PROMETHEUS_UID}
      {--folder=Horizon}
      {--scrape-interval=10s}       S: default of the scrape_interval variable; busy_window = max(15s, 2×S), LOOKBACK = max(10m, 10×S),
                                    refresh = S in off and stream modes (Addendum R3.3)
      {--live=poll}                 Live Overview: off | poll | stream (Addendum R4.1)
      {--live-poll-interval=5s}     poll: dashboard refresh (>= Grafana min_refresh_interval)
      {--live-stream-id=}           stream: Grafana Live stream id; default config('horizon-observability.live.stream.id')
      {--prometheus-datasource=}    optional name regex for the hidden datasource variable
      {--infinity-datasource=}      optional name regex for the hidden app_api variable
      {--health-rules}              add the two opt-in health rules (Q1.5)
      {--recording-rules}           opt-in recording rules (Addendum R2.4 O9); the default render never depends on them
  ```
- It writes `{path}/dashboards/horizon-observability.json` and `{path}/alerting/horizon-observability.json` (plus `{path}/prometheus/horizon-observability.rules.yml` with `--recording-rules`). `path` is relative to `base_path()`. The prefix comes from `config('horizon-observability.namespace')` and is unaffected by `--defaults`.
- **Committed assets.** `resources/grafana/dashboards/horizon-observability.json` and `resources/grafana/alerting/horizon-observability.json` are the `--defaults` render: prefix `ho_`, uid `horizon-observability`, job `.+`, live mode `poll` (Addendum R4.1), datasource placeholder. CI runs `testbench horizon-observability:grafana resources/grafana --defaults --check`.
- **JSON printer.** `Grafana\Json` prints 2-space indentation, `JSON_UNESCAPED_SLASHES|JSON_UNESCAPED_UNICODE|JSON_PRESERVE_ZERO_FRACTION` and a trailing newline. Objects and lists stay distinct (an empty object is `{}`). This matches Python's `json.dump(indent=2, ensure_ascii=False)`, so byte equality with the legacy file is a target, not just decoded equality.

#### 5.2 Runtime variables versus baked values

The look is unchanged: same panels, layout, colours, thresholds and mappings, and the same visible variables. Every new variable is hidden.

| Concern | Mechanism | Default |
|---|---|---|
| Metric prefix | **Baked** into the PromQL (`ho_queue_…`) | `ho_` |
| Dashboard identity | Baked: uid, title, tags | `horizon-observability`, **`Queues`**, `[horizon, queue]` (the `office` tag is gone) |
| Prometheus datasource | Hidden `datasource` variable of type prometheus (as today), with an optional rendered regex | Grafana default |
| Infinity datasource | Hidden `app_api` variable of type Infinity (as today), with an optional regex | first Infinity datasource |
| **Scope** | New hidden query variable `prometheus_job` = `label_values(<ns>_horizon_status, job)`, `includeAll`, `allValue: .+`, URL-overridable (`?var-prometheus_job=`). Every package selector, including the `supervisor`, `queue`, `job_class`, `batch_kind`, `*_title`, `horizon_url`, `tts_url` and `api_path` variable queries, gets `job=~"$prometheus_job"`. | `--job` or All |
| **API base path** | New hidden variable `api_path` = `query_result(group by (api_path) (last_over_time(<ns>_horizon_info{job=~"$prometheus_job"}[7d])))` with a capture regex, `skipUrlSync`. Infinity URLs become `${api_path}/jobs/<list>`. | – |
| Horizon URL, TTS URL | Existing hidden variables from `horizon_info{url}` and `horizon_info{tts_url}`, now scoped by job | – |
| **Scrape interval** | New hidden textbox `scrape_interval` (URL-overridable as `?var-scrape_interval=30s`); every Prometheus panel gets `"interval": "$scrape_interval"` (Addendum R3.3) | `--scrape-interval` (10s) |
| **Busy window** | New hidden textbox `busy_window` replaces the literal `BUSY_WINDOW` | `max(15s, 2 × --scrape-interval)` (20s at 10s; Addendum R3.3) |
| Refresh | Baked | `--scrape-interval` (10s) in off and stream modes; `--live-poll-interval` (5s) in poll mode (Addendum R2.5) |
| Thresholds shared with the alerts | Renderer constants: wait factor 2, failure ratio 0.05, minimum 100 attempts, window 15m, `for: 5m`. All other tile thresholds are unchanged constants. | as today |
| Replica dedupe | Builder, always on after the gate in 5.6 | on |
| Alerts tile and sound selector | Baked exact list: `GRAFANA_ALERTS{grafana_rule_uid=~"<uid>-(no-metrics\|down\|long-wait\|failures)"}` replaces `horizon-.*`. With `--live=stream` the list also contains `live` (Addendum R4.11). Health rules are never in it. | – |
| `__requires` | Grafana ≥ 12.3.0, `prometheus`, `yesoreyeram-infinity-datasource` ≥ 4.1.1, `marcusolsson-dynamictext-panel` ≥ 6.3.0, stat, table, timeseries | – |

Addendum R2.4 also changes the variable refresh policy (O3: only `batch_kind` and `batch_title` refresh on time-range change) and adds the hidden shared queue source panel #24 (O5, with an explicit `maxDataPoints`).

**The `horizon_current_processes` replacement (owner-dx flaw):**
- Target D: `sum by (queue) (horizon_current_processes)` → `sum by (queue) (horizon_supervisor_processes)`.
- `QUEUE_BUSY` denominator:

  ```
  max by (queue) (count_over_time(horizon_current_processes{queue=~".+"}[W]))
  →
  max by (queue) (count_over_time(<ns>_horizon_supervisor_processes{job=~"$prometheus_job", queue=~".+"}[$busy_window]))
  ```

  Both series derive from the supervisors' `processes` maps and use the same pooled `queue` label, and the denominator is a per-queue *sample count*, so the result is identical.
- `QUEUE_METRICS` and `JOB_METRICS` sets: remove `horizon_current_processes`; rename `queue_oldest_pending_job_age` to `…_seconds` everywhere (`OLDEST_AGE`, `WAIT_RATIO`, `WORST_AGE`).

#### 5.3 Alert rules

The rules are emitted by `AlertRulesBuilder` from the same constants and panel registry as the dashboard:
- folder `--folder` (Horizon), group `Queues`, interval `1m`, `orgId: 1`;
- `__dashboardUid__` = the dashboard uid;
- `__panelId__` looked up by panel title in the builder, not hard-coded;
- `datasourceUid` = `${HORIZON_OBSERVABILITY_PROMETHEUS_UID}` (interpolated by Grafana at provisioning) or the literal `--datasource-uid`;
- rule titles, which TTS speaks, are unchanged.

`<ns>` is the rendered prefix and `J` the rendered job regex (`.+`, or the escaped `--job`):

| uid | Title | Expression (refId A) | Condition | noData | for |
|---|---|---|---|---|---|
| `<uid>-no-metrics` | No queue metrics | `count(max by (job) (<ns>_horizon_status{job=~"J"}))` | `< 1` | Alerting | 5m |
| `<uid>-down` | Horizon is down | `min by (job) (max by (job) (<ns>_horizon_status{job=~"J"}))` | `< 1` | OK | 5m |
| `<uid>-long-wait` | Queue wait over 2x limit | `max by (job, queue) (<ns>_queue_oldest_pending_job_age_seconds{job=~"J"}) / on (job, queue) max by (job, queue) (<ns>_horizon_wait_threshold_seconds{job=~"J"})` | `> 2` | OK | 5m |
| `<uid>-failures` | High failure rate | `sum by (job, queue) (max by (job, connection, queue, job_class, status) (increase(<ns>_queue_jobs_total{job=~"J", status=~"failed\|timed_out"}[15m]))) / clamp_min(sum by (job, queue) (max by (job, connection, queue, job_class, status) (increase(<ns>_queue_jobs_total{job=~"J", status=~"completed\|failed\|timed_out"}[15m]))), 100)` | `>= 0.05` | OK | 5m |
| `<uid>-live` *(`--live=stream` only, Addendum R4.11)* | Live overview stalled | `max by (job) (timestamp(<ns>_exporter_live_push_timestamp_seconds{job=~"J"}) - <ns>_exporter_live_push_timestamp_seconds{job=~"J"})` | `> 30` | OK | 1m |
| `<uid>-collector` *(opt-in)* | Metrics collector failing | `min by (job, collector) (<ns>_exporter_collector_success{job=~"J"})` | `< 1` | OK | 15m |
| `<uid>-duplicates` *(opt-in)* | Duplicate metrics exporters | `count by (job) (<ns>_horizon_status{job=~"J"})` | `> 1` | OK | 10m |

- The summaries keep today's English templates and add `{{ $labels.job }}`, e.g. `Oldest pending job in {{ $labels.queue }} ({{ $labels.job }}) waits {{ printf "%.1f" $values.A.Value }}x the queue wait limit`.
- Annotations are taken raw by Grafana, so the templates are safe.
- Titles, uids and labels are env-interpolated, so they must never contain `$`; the builder asserts this.
- With All jobs, "No queue metrics" fires only when no job reports at all. The README says to pin `--job` for per-app absence alerts.
- **Office continuity:** rendering with `--uid=horizon` produces exactly the old rule uids `horizon-no-metrics`, `horizon-down`, `horizon-long-wait` and `horizon-failures`, so `GRAFANA_ALERTS` history and bookmarks continue.

#### 5.4 Sound and TTS

The behaviour is kept: an IEC 60601-1-8-style tone, then the firing rule titles are spoken.
- The Business Text JS moves to `resources/grafana/js/alert-sound.js`, with English comments and the global renamed to `window.__horizonObservabilityAlertSound`. It is embedded into panel #23 `afterRender` at render time.
- `grafana.tts_url` is exported as `horizon_info{tts_url}`, so it stays a runtime value:
  - absent: tone only (today's behaviour without TTS);
  - `browser`: Web Speech API (`speechSynthesis`, `en-US`), no server needed;
  - an http(s) URL: Piper `POST {url}/synthesize` with `{"text": …}`, returning WAV, CORS required (unchanged).
- The alert window stays 90 s (1.5 × the 1m rule interval).
- Both the Alerts tile and the sound need Grafana state history written to Prometheus: Prometheus `--web.enable-remote-write-receiver` plus `GF_UNIFIED_ALERTING_STATE_HISTORY_*`, shipped as `resources/grafana/provisioning/grafana.ini.example`. The metric name `GRAFANA_ALERTS` is Grafana's default and is documented, not configurable.

#### 5.5 Provisioning examples (shipped and mounted by the dev stack, so they are tested)

- `resources/grafana/provisioning/datasources/horizon-observability.yaml`: the Infinity datasource with bearer auth (Q2.5).
- `resources/grafana/provisioning/dashboards/horizon-observability.yaml`: a file provider with path `/etc/grafana/dashboards/horizon-observability`.
- `resources/grafana/provisioning/grafana.ini.example`: `[unified_alerting.state_history]` with `enabled = true`, `backend = multiple`, `primary = annotations`, `secondaries = prometheus` and `prometheus_target_datasource_uid`.
- Alert rules: mount `resources/grafana/alerting/` (or the user's render) into `provisioning/alerting/`.

#### 5.6 Migration steps (WP8A, then WP8B)

**Steps M0 to M2 (WP8A, golden port):**

| Step | Work | Gate |
|---|---|---|
| **M0** (done in WP0a) | Freeze `tests/Fixtures/grafana/legacy/queues.json` (today's committed file, byte-identical to `generate_queues_dashboard.py --prefix office`), `legacy/horizon.yml` and `legacy/alert-sound.js` (the verbatim `afterRender` string). The Python script moves to `legacy/grafana/` for reference. | – |
| **M1** | Port faithfully. Python helpers become PHP static methods (`Promql::filtered()`, `attempts_delta`/`attempts_per_step`, `filter_is_all`, `by_job_selection`, panels, variables). `LegacyProfile` sets prefix `office_`, uid `horizon`, title `Queues`, tags `[horizon, queue, office]`, Infinity paths `/metrics/horizon/{list}-jobs`, the `horizon-.*` alert selector, a literal `15s` busy window, no job scope and no dedupe, and reads the JS from the fixture. | `GoldenPortTest`: `Json::encode(render(LegacyProfile))` is **byte-identical** to the legacy file (minimum gate: `json_decode(…, false)` equality with key order preserved). |
| **M2** | Port the alert YAML into `AlertRulesBuilder` with the same profile. | `LegacyAlertsTest`: the parsed legacy `horizon.yml` (dev `symfony/yaml`) equals the builder output. |

**Steps 1 to 12 (WP8B):** each step is a separate reviewed commit. After each one, the default golden `tests/Fixtures/grafana/default/*` is updated, the contract tests and `promtool check rules` run, and a Playwright screenshot diff is attached to the PR. The layout-projection test must stay green throughout: panel id, type, title, gridPos, repeat, collapsed, options without the JS, fieldConfig and transformations must equal the legacy projection.

1. Extract the English JS file with `tts_url=browser` support. Only panel #23 `afterRender` changes.
2. Introduce the prefix token with default `ho_`, the identity uid `horizon-observability`, title `Queues`, tags `[horizon, queue]`.
3. Metric changes: `_age_seconds`, and the `horizon_current_processes` replacement (5.2).
4. The `prometheus_job` variable and the job matcher in `filtered()`, applied to every package selector and variable query.
5. The `api_path` variable; Infinity URLs become `${api_path}/jobs/<list>`.
6. The `busy_window` variable and the refresh derivation.
7. The exact rule-uid selector; uid-derived rule ids; JSON alert output; the datasource env placeholder.
8. `__requires`.
9. Delete the dead code (`STATUS_EXPR`, `batches_table`). Output unchanged.
10. **Dedupe.** Replace inline selector strings with builder calls `Promql::sel(Metric $m, array $matchers)` and `Promql::range(string $fn, Metric $m, string $window, array $matchers)`. Both emit `max by (job, <Metric::labels()> [, le]) (…)`. A test tokenises every expression and asserts that every `<ns>_<family>` occurrence sits inside such a wrapper with the catalog's label list. The step is gated by the 2-replica e2e test: panel results with two scraped replicas equal a single-target run.
11. The opt-in health rules.
12. **R3 windows** (Addendum R3.3, Q9′ WP8B): the `scrape_interval` variable, per-panel `"interval": "$scrape_interval"`, the `busy_window` formula `max(15s, 2×S)`, `LOOKBACK = max(10m, 10×S)`, the `--scrape-interval` default `10s` and the refresh derivation.

Then: commit the default render to `resources/grafana/`, delete `LegacyProfile` and the legacy goldens, and enable the arch test "no `office` in `src/` or `resources/`". WP8C (dashboard performance, Addendum R2.4) and WP8D (live panels, Addendum R4.8) follow WP8B, in that order (Addendum Q9′).

#### 5.7 Grafana contract tests (`tests/Grafana`)

1. The committed assets equal the `--defaults` render (`--check` semantics), and `--check` is idempotent.
2. **Metric contract:** every `<ns>_<name>` in the dashboard and the rules maps to a `Metric` case, after stripping `_bucket`, `_sum` and `_count`. Every label used in matchers, `by (…)`, `on (…)` and `label_values()` is in `Metric::labels() ∪ {job, le}`, plus the sentinel label `__batch_scope__` (Addendum R2.4 O2). `timestamp(sel) - sel` inside `max by (job)` is accepted (rule `<uid>-live`).
3. **Infinity contract:** every Infinity URL is `${api_path}/jobs/(pending|completed|failed|delayed)` and resolves to the `horizon-observability.jobs` route, or `${api_path}/live` with `LiveSnapshot` fields as columns (Addendum R4.8). Its query parameters equal `JobListQuery::VARIABLES`, and the columns it reads are a subset of the `JobRows` fields for that list.
4. **Alert contract:** 4 rules by default, 5 with `--live=stream` (plus 2 with `--health-rules`); uids ≤ 40 characters and prefixed with the dashboard uid; `__panelId__` panels exist; the selector lists exactly the core uids (4, or 5 with `--live=stream`); no `$` in titles, uids or labels.
5. **Syntax:** every expression of both assets, with the macros substituted (`$__range` → `1h`, `$__interval` → `1m`, `${__from:date:seconds}` → `0`, variables → `.*`), is written to a rules file and checked with `promtool check rules` (CI `grafana` job).
6. **Live scrape:** a seeded full workbench scrape (WP11) has samples for every referenced family and label.
7. **ASCII:** the output is ASCII except the existing glyphs (· U+00B7, — U+2014, ✓, ⚠ U+26A0 + U+FE0E, ◷, ⧖). In `src/` those glyphs are written as `\u{…}` escapes.

### Rationale

- **One toolchain.** Users render a dashboard and rules that match their own config (namespace, base path) with one artisan command and a `--check` for their infra CI. The dashboard and the rules come from shared constants, so they cannot drift (F17, F20).
- **The golden step proves "same look and behaviour"** before any intentional change, and the layout projection keeps proving it afterwards.
- **Variables only where the runtime decides** (which app, which datasource, which path, which URL) keep one render usable for many apps in one Grafana, while the PromQL stays readable.
- **Alert rules must be rendered** because Grafana never interpolates `model.expr` or annotations (verified), so baking the prefix costs nothing extra.

### Rejected alternatives

- **Keeping Python (with or without a PHP stub renderer):** two toolchains, Python 3.12 needed by contributors and CI, a regex-based dedupe that is the largest regression risk, and the alert YAML still hand-maintained.
- **`${metric_prefix}` as a dashboard variable:** about 750 interpolations, unreadable queries, and the alert rules need rendering anyway.
- **`__inputs` import placeholders:** they only work for UI import, not file provisioning.
- **Env interpolation of expressions:** Grafana does not interpolate them.
- **A YAML emitter or `symfony/yaml` at runtime:** JSON is accepted on the same code path.
- **A visible job picker or datasource picker:** it changes the toolbar look. Users can unhide them or use `--job` or `?var-prometheus_job=`.
- **Title "Horizon Queues":** changes the look.
- **Recording rules for `attempts_delta` as a dependency of the default render:** they would make the dashboard depend on extra Prometheus config. Addendum R2.4 O9 overrides this in part: recording rules are opt-in (`--recording-rules`), and the default render never depends on them.

### Risks

| Risk | Mitigation |
|---|---|
| Port fidelity (about 2000 lines, mostly data) | Byte or decoded golden equality before any change; a panel-level diff helper for readable failures. |
| The dedupe rewrite touches about 100 KB of PromQL | Builder-emitted wrappers, the wrapper-coverage test, `promtool`, the 2-replica e2e test and screenshot diffs. |
| Grafana or plugin drift | `__requires`; the CI provisioning smoke test on pinned versions; the weekly job on the latest versions. |
| `api_path` is ambiguous when several apps share Grafana and the scope is All | Documented: pin `--job` per dashboard copy (different `--uid`). |
| `src/Grafana` ships maintainer code to production apps | It is `@internal`, has no runtime cost unless the command runs, and is about 100 KB on disk. |

---

## Q6. Runtime correctness

### 6.1 One fix per confirmed bug

| ID | Fix |
|---|---|
| C1 | `BatchesCollector` runs isolated in the exporter. `BatchReader` returns typed `BatchSnapshot` lists, never a shape-changing `[]` fallback. Auto mode (`collectors.batches = null`) runs only when `queue.batching.driver` is `database` (or absent) **and** the table exists. Detection is memoised per process and in the cache for 10 minutes. DynamoDB or a missing table → the collector is not run, which is not a failure. A real failure gives 200 with `collector_success{collector="batches"}=0`. |
| C2 | No raw pipeline and no raw-client `eval`. One `Illuminate\Redis\Connections\Connection::eval($script, $numKeys, ...$keysAndArgs)` per attempt; Laravel normalises the argument order for phpredis and predis. `Redis\Lua` maps phpredis's `false` return to a typed exception carrying `getLastError()`. Every Redis test runs on both clients. |
| C3 | No registry and no persistent storage of rendered values. Absolute values are read and rendered on each request. |
| C4 | `Authorize` is fail-closed: token or IP or callback; open only in `local`; 401/403, JSON on the job lists. Bounded scans; an empty filter returns early; the `exceptions` mode is `full` (short first line), `class` or `none`. |
| C5 | Own guarded `horizon_status` inside the isolated `horizon` collector, with partial reporting per source. `WorkloadRepository` is no longer used. 2 s reader clients and the scrape budget apply. With Redis down: 200 with `collector_success=0`. |
| C6 | `AttemptTracker` records only `Illuminate\Queue\Jobs\RedisJob` instances (what Horizon pops) that have an in-flight state from `JobProcessing`. `JobAttempted` or `JobTimedOut` without state is ignored, so `SyncJob` (sync, deferred and background) never touches Redis, including the 12.45+ sync `JobAttempted`. `JobQueued` listeners skip connections whose driver is not `redis`. |
| C7 | Own endpoint, the one-target contract, `max by (job, …)` dedupe in every rendered query and rule, and the opt-in duplicates rule (Q1.5). |
| C8 | The queue Lua computes readiness `max(pushedAt, createdAt + delay)` for the **first payload with `attempts == 0`** inside the scan window (retried or released heads are skipped). It also takes the earliest due `:delayed` score. Age = `now − min(those two)`, published as `queue_oldest_pending_job_age_seconds`. Empty queue → 0. Non-empty but no fresh job and nothing due in the window → the series is omitted (unknown), as today for unknown. |
| C9 | Label normalisation and sanitisation; the 500-class ZSET cap with `(other)`, automatic 30-day prune and lazy `HDEL`; top-100 per queue; batch caps; `size` and `created` dropped; hex-token stripping; the kind hook (Q4). |
| C10 | All package keys share the hash tag `{horizon-observability}`. Every script touches only its declared `KEYS`, there is no `pipeline()` on the storage path, and `INFO` runs inside Lua on the slot's node. Cluster stance in 6.5. |
| C11 | Private storage client with `redis.worker_timeout` (1.0 s) for connect, read and read-write, `persistent=false`, serializer and compression removed. A per-process circuit breaker skips writes for 30 s after a failure and counts them. No Redis call at `JobProcessing`. One warning per opening; one info line on recovery with the dropped count. Worst case: ≤ 1 s once per 30 s per worker. |
| C13 | An empty resolved filter (a supervisor that is not running, a batch kind without links) → `{"data":[]}` with **zero** Redis commands. Rows are fetched with `HMGET` of light fields only, never payloads. `attempts` and the exception first line come from one Lua call per 100 rows of the final page only. Delayed rows are extracted in Lua. `now` is captured once per request. |
| C14 | `SupervisorSnapshot::$queues` is split from `options.queue` (comma list, trimmed), so balance=false pools match the single-queue `queue` field of Horizon job hashes. The metrics keep Horizon's pooled label. |
| C15 | Two bounded queries on `queue.batching.database`/`table` via the query builder, each `ORDER BY created_at DESC LIMIT 5000` (6.7). |
| C16 | No config mutation in `register()`. The menu middleware is appended to `horizon.middleware` in the booting callback (all providers registered, before Horizon's `boot()`), idempotently, written back as an array, and only when `grafana.url` is set. |
| C17 | Own endpoint and namespace, no spatie collectors, `horizon_current_processes` dropped. |
| C18 | The `ready_at` side channel is active only on a legacy stack (`Support\Versions::payloadCarriesDelay()` is false). The worker reads and deletes it **inside** the record `EVAL`, so there is no extra round trip. Modern stack: no keys and no listener. |
| C19 | Readiness `max(pushedAt, createdAt + delay)`: a retried job has a fresh `pushedAt` later than `createdAt + delay`, so the stale delay is ignored. |
| C20 | (a) `WeakMap<Job, AttemptState>` with a `finished` flag. A late `JobTimedOut` after `JobAttempted`, and a `JobAttempted` after `JobTimedOut` (13.33+ `killOnTimeout=false`), both find `finished` and are ignored. (b) Writes go only through the private storage client: a different client object from any connection the job uses. The package never calls `pipeline()`, `exec()` or `purge()` on shared connections. |
| C22 | Discovery runs at deploy time (`horizon-observability:cache`, hooked into `optimize` via `optimizes()` when `method_exists`, i.e. Laravel ≥ 11.27.1) into `bootstrap/cache/horizon-observability.php`. The runtime fallback is cached in the default cache store for 1 h, keyed by a hash of the paths. The token prefilter never includes files (6.9). |
| C24 | English HELP texts, sentinels, logs (prefix `horizon-observability:`) and docs. An architecture test asserts ASCII-only `src/`, `resources/lua`, `resources/views` and exposition metadata. |
| C25 | The load generator lives in `workbench/` (autoload-dev, export-ignored). Nothing is shipped. |
| A01 | The `JobQueued` delay (int, `DateTimeInterface` or `DateInterval`) is normalised with `InteractsWithTime::secondsUntil` semantics **inside** the guarded block of the legacy `ready_at` listener. A regression test dispatches `->delay(now()->addMinute())`. |
| A05 | The `AttemptOutcome` shim (6.2). |

The refuted findings need no fix, but get cheap improvements:
- **C12:** byte cap on the queue scan.
- **C21:** a missing `meta` key only initialises `run_id` and no longer wipes the counters.
- **C23:** `now()` (Carbon) everywhere, and time is passed to Lua through `ARGV`, so `travelTo()` works.
- **C26:** no change.

### 6.2 Laravel 11.23 / 12 / 13 shim

```php
/** @internal */
final class AttemptOutcome
{
    public static function of(JobAttempted $event): AttemptStatus
    {
        $job = $event->job;

        return match (true) {
            $job->hasFailed() => AttemptStatus::Failed,
            $job->isReleased() && self::threw($event) => AttemptStatus::Retried,
            $job->isReleased() => AttemptStatus::Released,
            default => AttemptStatus::Completed,
        };
    }

    /** 11.23–12.x: bool $exceptionOccurred; 13.x: ?Throwable $exception. Both are declared public properties. */
    public static function threw(object $event): bool
    {
        return property_exists($event, 'exception')
            ? $event->exception !== null
            : (bool) ($event->exceptionOccurred ?? false);
    }

    /** 13.25+: JobTimedOut::$timeout (includes the supervisor --timeout); earlier: the job's own timeout. */
    public static function timeoutSeconds(object $event, Job $job): ?float
    {
        $timeout = property_exists($event, 'timeout') ? $event->timeout : null;
        $timeout ??= $job->timeout();

        return is_numeric($timeout) && $timeout > 0 ? (float) $timeout : null;
    }
}
```

**`AttemptTracker` state machine.** It is a singleton subscriber with listeners as first-class callables. Every listener body is `try { … } catch (Throwable $e) { $reporter->report('tracker', $e); }`, including the shim's property reads; nothing is ever rethrown into the worker or the dispatcher.

| Event | Action |
|---|---|
| `JobProcessing` | Only when the job is a `RedisJob` and `collectors.attempts` is on. Store `AttemptState(startedNs: hrtime(true), startedAt: now()->getPreciseTimestamp(3)/1000, readyAt: $job->attempts() === 1 ? PayloadClock::readyAt($job->payload()) : null, uuid, legacyKey)`. No Redis call. |
| `JobExceptionOccurred` with `MaxAttemptsExceededException` | `state->noDuration = true` (recorded as `failed` without a duration sample, as today). |
| `JobAttempted` | No state, or `finished` → ignore. Otherwise set `finished`, compute `status = AttemptOutcome::of()` and the duration from `hrtime`, then record. |
| `JobTimedOut` | No state, or `finished` → ignore. Otherwise set `finished`, record `timed_out` with duration `min(elapsed, timeoutSeconds())`. |

Tests build real events of the installed Laravel version plus `stdClass` fakes with the 11.23/12 and 13 property shapes (with and without `timeout`), so every branch runs on every CI leg. The event sequences tested:
- success, `release()`, exception with retry, final failure, max attempts exceeded;
- timeout;
- timeout followed by `JobAttempted`;
- `JobAttempted` followed by a late `JobTimedOut`;
- a nested `dispatchSync` inside a Redis job.

### 6.3 Readiness and wait (`Queues\PayloadClock`, shared by the PHP and Lua sides)

```php
public static function readyAt(array $payload): ?float
{
    $pushed  = is_numeric($payload['pushedAt'] ?? null) ? (float) $payload['pushedAt'] : null;   // Horizon: JSON string, µs precision
    $created = is_int($payload['createdAt'] ?? null) ? $payload['createdAt'] : null;             // Laravel >= 12.11
    $delay   = is_int($payload['delay'] ?? null) ? $payload['delay'] : null;                     // >= 12.11 AND Horizon >= 5.49; else null/absent
    $base    = $pushed ?? ($created !== null ? (float) $created : null);

    return $base === null ? null
        : ($created !== null && $delay !== null ? max($base, (float) ($created + $delay)) : $base);
}
```

- The wait is recorded for the first attempt only: `max(0, startedAt − max(readyAt, ready_at key))`. The `ready_at` key is read only on a legacy stack.
- The queue Lua applies the same formula through string extraction of the top-level keys `pushedAt`, `createdAt`, `delay` and `attempts`.
- `tests/Contract/ReadinessParityTest` (WP3) runs every payload in `tests/Fixtures/payloads.php` (WP0) through both sides and asserts equal results. The fixtures cover cjson-reordered keys, escaped names, `delay: null`, string `pushedAt`, a Horizon retry and `queue:retry`.

### 6.4 predis and phpredis

- **Storage paths** use only `eval`, `hgetall`, `zrange` (score form through Laravel-normalised options) and single-key commands. `INFO` runs inside Lua, because the client reply shapes differ.
- **Horizon job lists** use `HMGET` and handle both reply shapes (phpredis associative, predis list) in one helper.
- **Private clients** remove `serializer` and `compression` options, so stored values are raw and deterministic regardless of the app's igbinary or lz4 settings.
- **CI** runs every `redis`-group test under phpredis and predis (2.x on the lowest leg, 3.x on stable legs).

### 6.5 Keys, prefixing, hash tags and Cluster stance

- **Keys.** All package keys are `{horizon-observability}:v1:<name>` (Appendix E). `v1` versions the storage schema.
- **Hash fields** join label values with `\x1f`, which `Labels::sanitize()` strips from values, so the separator cannot occur inside a value.
- **Prefixing.** Laravel's connection prefix (on the default `horizon` connection that is `horizon.prefix`, e.g. `laravel_horizon:`) is applied by both clients to `KEYS[]`; the audit verified this with prefixes. Lua never builds key names, and every key arrives in `KEYS[]`. The slot is decided by the first `{…}` of the final key: ours, or Horizon's `{laravel_horizon:}` on a cluster. Either way every package key shares one slot. `check` warns if an app prefix contains braces that would split them.
- **Cluster stance:**
  - **Package storage:** cluster-safe by construction on any Redis Cluster connection (single slot, no pipelines, `INFO` in Lua).
  - **Horizon and queue reads:** supported where Horizon itself supports a cluster, i.e. Horizon ≥ 5.49 on Laravel ≥ 13.20 (`Connection::hasHashTag`). The queue Lua needs hash-tagged queue names (`{default}`), which Laravel's `RedisQueue` already requires on a cluster. Elsewhere the readers detect `PhpRedisClusterConnection` or `PredisClusterConnection` and fail their collector with "unsupported topology", logged once, instead of a `CROSSSLOT` error on every scrape.
  - **Tests:** CI has a single-node cluster-mode leg (`cluster-enabled yes` plus `CLUSTER ADDSLOTSRANGE 0 16383`, Redis ≥ 7.0), which reproduces `CROSSSLOT` faithfully.

### 6.6 Private clients and the circuit breaker

- **`Redis\RedisClients`** is a container singleton holding its own `Illuminate\Redis\RedisManager`, built lazily on first use from a filtered copy of `config('database.redis')`. Global config is never mutated, so ordering does not matter. It provides:
  - `storage()`: a clone of `redis.connection` (`horizon` by default). `timeout`, `read_timeout` and `read_write_timeout` are set to `min(existing, redis.worker_timeout)`, `persistent` is forced to false (the app's persistent socket is never shared), and serializer and compression are removed;
  - `reader(string $name)`: the same for any connection (including `clusters.*`), with `redis.scrape_timeout`. It is used for queue state, delayed jobs and Horizon job lists, and for the package keys on the scrape, job-list and live paths (the attempt counters, the batch links; phase B review);
  - `manager()`: the private manager, used by tests for `enableEvents()`/`listen()`. Private clients bypass the app's `Redis::listen()`.
- **Horizon repositories** (`MasterSupervisorRepository`, `SupervisorRepository`, `JobRepository` metadata) stay container-bound and are never re-instantiated.
- **`Redis\CircuitBreaker`** is one instance per process, shared by `AttemptStore`, `BatchLinks` and `LegacyReadyAt`:
  - `attempt(string $operation, Closure $write): bool` returns false when the write was skipped or failed;
  - it opens on any connection or timeout error, `RedisException`, predis `ConnectionException` or a Lua failure;
  - while open (30 s), writes are skipped and counted;
  - after the cooldown the next write probes. On success the breaker closes, and the dropped count is added to `meta.dropped` inside the same record `EVAL` and logged once at info level ("recovered after Xs, N attempts dropped"). On failure it re-opens;
  - under PHP-FPM it is per request, which bounds the cost to one timeout per request.
- **Read paths** do not use the breaker; they fail visibly through `collector_success`.

### 6.7 Batches

- **Detection:** described under C1. `collectors.batches = true` with a missing table counts as a collector failure, on purpose. `false` turns the collector off.
- **Query 1, open batches:**

  ```sql
  select id, name, total_jobs, pending_jobs, failed_jobs, created_at, finished_at, cancelled_at
  from <table>
  where finished_at is null and cancelled_at is null and created_at >= :running_since
  order by created_at desc limit 5000
  ```

  `running_since` is now − 7 d, so lost batches age out instead of growing the oldest age forever.
- **Query 2, recently closed batches:** `where finished_at >= :closed_since or cancelled_at >= :closed_since order by created_at desc limit 5000`, with `closed_since` = now − 1 h.
- **Classification in PHP**, as today:
  - `cancelled_at` → `Cancelled`;
  - `finished_at`, or `total > 0 && pending == failed` (allowFailures batches never get `finished_at`) → `Failures` if failed > 0, else `Finished`;
  - otherwise `Running`.

  A closed-without-`finished_at` batch closes at `created_at` and is exported only while `created_at >= closed_since`. Progress is `round((total − pending) / total × 100)`. Times are Unix seconds (no timezone labels).
- **README:** recommends indexes on `job_batches(created_at)`, `(finished_at)` and `(cancelled_at)`, and scheduling `queue:prune-batches`.
- **Batch links.** `BatchLinks::onQueued` writes, for batched jobs on `redis` connections:
  - the batch name comes from `Bus::findBatch()`, memoised per batch id (1000 entries);
  - the member is `kind\x1fconnection\x1fqueue\x1fjob_class`, written with `ZADD` (score now) at most once per hour per link per process, through the breaker;
  - the collector prunes links older than 7 days once per scrape (`ZREMRANGEBYSCORE`);
  - the links feed `queue_batch_job_classes`, `queue_batch_info` and the `batch_kind` job-list filter.

### 6.8 Ready-at legacy fallback

- **Mode.** `Support\Versions::payloadCarriesDelay()` is computed once per process:

  ```php
  version_compare(Application::VERSION, '12.11.0', '>=')
      && ($h = InstalledVersions::isInstalled('laravel/horizon') ? InstalledVersions::getVersion('laravel/horizon') : null) !== null
      && preg_match('/^\d+\.\d+/', $h) === 1
      && version_compare($h, '5.49.0.0', '>=')
  ```

  `getVersion()` returns normalised versions such as `5.50.1.0`. A dev branch or an unknown version means legacy mode, which is safe: the side channel only refines readiness.
- **Legacy mode:**
  - `LegacyReadyAt::onQueued(JobQueued)` handles `redis` drivers with a delay > 0 and a non-empty id. It normalises the delay inside `try`, then runs `SET {…}:v1:ready_at:<uuid> <now+delay> EX <delay+86400>` through the breaker;
  - the worker passes `ready_at:<uuid>` as an extra `KEYS` entry to `record_attempt.lua`, which does `GET` + `DEL` and uses `max(readyAt, ready_at)`.
- **Modern stack:** the listener is not registered and no keys exist.
- **Limitation (documented):** on a legacy stack the queue-state head age of jobs delayed at dispatch still counts from dispatch, because the payload has no `delay`. Due delayed scores are exact either way. `check` prints the capability matrix.

### 6.9 Job-class discovery

1. `job_class_paths`, or `[app_path()]` by default, is scanned for `*.php` files.
2. **Token prefilter** (`PhpToken::tokenize`):
   - read the declared `namespace` and `class`;
   - skip files without exactly one class declaration and files with top-level `function` declarations;
   - skip files whose class name differs from the file basename (PSR-4).
3. **Autoload and reflection.** `class_exists($fqcn)` runs (autoload only; the package never `include`s files), then reflection keeps classes that:
   - implement `ShouldQueue`, inherited included;
   - are not abstract;
   - have no own `displayName()`;
   - have `handle()` or are a `Notification` or `Mailable`.

   The result is sorted.
4. **Caching.** `Discovery\JobClassCache` prefers `bootstrap/cache/horizon-observability.php`, written by `horizon-observability:cache` and deleted by `:clear`, then the runtime cache (1 h), then live discovery.

### 6.10 Counter reset guard

`reset_guard.lua` runs once per scrape before the counters are read:
- it reads `run_id` from `INFO server` inside Lua;
- missing `meta` → store `run_id` only, without deleting anything;
- the same `run_id` → no-op;
- a different `run_id` → `DEL` the counter and histogram keys and set `reset_at`.

There is no toggle: a reset to 0 is always handled correctly by `rate()` and `increase()`. Only the absolute values are lost, and those are meaningless across a restore anyway.

### 6.11 Bounded cost

| Path | Worst-case work |
|---|---|
| Job start | 0 Redis calls |
| Job end | 1 `EVAL` (about 10–14 commands inside, plus 2 for the live ring buffer when `live.enabled`, Addendum R4.5), O(1) |
| Dispatch | Modern stack: at most 1 `ZADD` per (kind, connection, queue, class) per hour per process, for batched jobs only, plus 1 `ZREMRANGEBYSCORE` prune on the first link write of each hour per process (phase B). Legacy stack: +1 `SET` per delayed dispatch. Both through the breaker. |
| Scrape `horizon` | the Horizon master and supervisor repository reads |
| Scrape `queues` | 1 `EVAL` per queue: `LLEN`, `LRANGE` ≤ `queue_scan_limit` (and ≤ 16 MB), `ZRANGE` reserved ≤ 1000 and ≤ 16 MB (`ZCARD` beyond; the byte cap since the T05 review), `ZCARD`/`ZCOUNT` delayed, `ZRANGEBYSCORE … LIMIT 0 1` |
| Scrape `attempts` | 1 guard `EVAL` + 5 `HGETALL` (counters, histograms) + 1 `extremes_read` `EVAL` (20 `HGETALL` inside, Addendum R3.4) + 1 `HGETALL meta` + 1 `ZRANGE job_classes`, i.e. 9 commands, + lazy `HDEL`s of at most 1000 fields of classes no longer in the class set, bounded by `max_job_classes` × queues (measured in T04; the scrape never prunes the class set, only `record_attempt` does when it is full) |
| Scrape `job_classes` | a cache file read |
| Scrape `batches` | 2 SQL queries with `LIMIT 5000` (plus 1 table check per 10 minutes in auto mode), 1 `ZRANGE` of at most the 10 000 most recently seen links and 1 `ZREMRANGEBYSCORE` prune |
| Job-list request | Horizon lists: ≤ 20 `ZRANGEBYSCORE` calls (10 000 ids / 500; only the first read is cut to the page, later reads take whole chunks), pipelined light `HMGET`s, ≤ 10 `job_details` `EVAL`s (100 hashes each, final page only). Delayed list: ≤ 20 `delayed_jobs` `EVAL`s per queue, each ≤ 500 entries and ≤ 8 MB of payload (T06 review). An empty filter: 0 commands (C13). |
| Live snapshot (Addendum R4.2) | cache hit: 1 `MGET`; build: Q + 5 round trips (Q = queues in scope), at most 1 build per `cache_ttl` per scope across all replicas |

### Rationale

- Each fix removes a mechanism instead of guarding it: no registry (C3), no shape-changing fallbacks (C1), no pipelines (C2, C10, C20b), no version-blind side channel (C18), and no per-scrape filesystem scan (C22).
- The shims read properties by name, so one build runs on 11.23 through 13.x.

### Rejected alternatives

- **Raising the floors to Laravel 12.11 and Horizon 5.49** to drop the side channel: it contradicts the owner's `^11.23`.
- **A connection allow-list in config:** the `RedisJob` rule is exact.
- **`EVALSHA`:** Laravel reloads the script on every call. **`ZADD GT/LT` or `GETDEL`:** still several calls, and not uniform across predis command classes.
- **Re-instantiating Horizon repositories on private clients:** fragile constructor coupling.
- **`purge()` or reconnecting a shared connection on the timeout path:** it breaks interrupted code that keeps running under `killOnTimeout=false`.
- **Tracking head "first seen" state across scrapes for C8:** stateful and racy across replicas.
- **A cache-backed breaker under FPM:** it would need Redis to know that Redis is down.

### Risks

| Risk | Mitigation |
|---|---|
| String extraction of payload fields in Lua depends on the payload shape | Parity fixtures (6.3); top-level keys only; the escaped quotes inside `data.command` cannot match. |
| Queues holding only re-queued jobs report no age | Pending counts and the failure alerts still show the problem; documented. |
| Laravel 11 is past security support by 2026-10 | Kept for 0.x/1.x; dropping it in 2.0 removes the legacy `ready_at` path and the `optimizes` guard. |
| Cluster coverage | The single-node cluster CI leg; the Horizon side is supported only where Horizon is. |

---

## Q7. Package structure

### Decision

**Provider:** a plain `Illuminate\Support\ServiceProvider`. `spatie/laravel-package-tools` (1.93: php ^8.1, illuminate ^10–^13, so compatible) is rejected because:
- its `hasRoute()` registers in `boot()`, which is the shadowed position;
- the ordering-critical wiring (the booting callback and the `horizon.middleware` append) would be hand-written anyway;
- `about`, `optimizes` and the maintenance exception are not covered;
- what it would save, the config publish, views and commands, is about 10 lines.

Horizon itself uses a plain provider.

**`src/` tree.** **PUBLIC** marks SemVer-covered code; everything else is `final` and `@internal`.

```
src/
  HorizonObservability.php                PUBLIC static hooks: auth(?Closure), resolveBatchKindUsing(?Closure);
                                          @internal: authCallback(), batchKindResolver(), flushState()
  HorizonObservabilityServiceProvider.php PUBLIC (class name only) wiring
  Config/
    Config.php                            readonly typed snapshot + problems; fromRepository(); container singleton
    ConfigProblem.php                     readonly (path, message)
    ExceptionMode.php                     enum full|class|none (values are public config values)
  Metrics/
    Metric.php                            enum catalog of every family (name, type, help, labels, collector, since)
    MetricType.php                        enum counter|gauge|histogram
    MetricFamily.php                      per-scrape builder: set()/observe() with validation
    Sample.php                            readonly (suffix, labels, value)
    InvalidMetric.php                     LogicException
    Labels.php                            sentinels UNSCANNED/UNKNOWN/OTHER/UNNAMED; sanitize(); topN()
    ScrapeContext.php                     now(), deadline, remember() (caches exceptions), reportFailure(), takeFailures()
    Exporter.php                          ordered, isolated, budgeted collector run; self-metrics; namespace prefix
    TextFormat.php                        text format 0.0.4 renderer (escaping, numbers, le, order)
    HistogramSnapshot.php                 non-cumulative storage -> cumulative buckets for configured bounds
  Collectors/
    Collector.php                         interface name(): string; collect(ScrapeContext): iterable<MetricFamily>
    HorizonCollector.php                  horizon_status, horizon_supervisor_*, horizon_wait_threshold_seconds, horizon_info
    QueueCollector.php                    queue_pending/delayed/delayed_due/oldest_age_seconds, queue_jobs_pending/reserved
    AttemptsCollector.php                 queue_jobs_total, duration/wait histograms, min/max, exporter_attempts_dropped_total,
                                          exporter_live_push_* (Addendum B′)
    JobClassesCollector.php               queue_job_classes
    BatchesCollector.php                  queue_batch*
  Horizon/
    Topology.php                          connection => queues for the effective environment (ProvisioningPlan rules)
    Supervisors.php                       repositories -> SupervisorSnapshot list; status() -1/0/1
    SupervisorSnapshot.php                readonly name, master, connection, balance, min/max, processes, queues (split)
    HorizonUrl.php                        browser URL of Horizon (override or app.url + horizon.domain + horizon.path)
    JobStore.php                          pending/completed/failed rows from Horizon Redis (chunked, light fields, Lua details)
  Queues/
    PayloadClock.php                      readyAt()/isFirstAttempt() (PHP twin of the Lua formula)
    QueueInspector.php                    one queue_state.lua call per queue -> QueueSnapshot
    QueueSnapshot.php                     readonly counts, readiness, per-class maps
    DelayedJobStore.php                   not-yet-due delayed rows (delayed_jobs.lua)
  Attempts/
    AttemptStatus.php                     enum completed|released|retried|failed|timed_out (values PUBLIC)
    AttemptOutcome.php                    11.23/12/13 event shim
    AttemptState.php                      in-flight state (start, readyAt, uuid, flags)
    AttemptTracker.php                    event subscriber; WeakMap<Job, AttemptState>; RedisJob only
    AttemptStore.php                      record_attempt.lua write (breaker); reads/decode for the collector; prune
    CounterResetGuard.php                 reset_guard.lua
    LegacyReadyAt.php                     JobQueued listener, legacy stacks only
    JobClassLabel.php                     displayName normalisation
  Batches/
    BatchStatus.php                       enum Running|Finished|Failures|Cancelled (values PUBLIC)
    BatchKind.php                         default kind normaliser; dispatches to the hook
    BatchReader.php                       driver/table detection + bounded SQL -> BatchSnapshot[]
    BatchSnapshot.php                     readonly batch row incl. status, progress, closedAt
    BatchLink.php                         readonly kind, connection, queue, jobClass
    BatchLinks.php                        JobQueued listener (write, memo, breaker) + all()/forKinds() (read, prune)
  Discovery/
    JobClassFinder.php                    token prefilter + reflection over job_class_paths
    JobClassCache.php                     bootstrap/cache file -> runtime cache -> finder
  Redis/
    RedisClients.php                      private RedisManager: storage(), reader($name), manager(), isCluster()
    Keys.php                              hash-tagged, versioned key names
    Lua.php                               loads resources/lua/*.lua once per process; eval via Connection::eval; typed errors
    CircuitBreaker.php                    per-process breaker (30 s), dropped counter
  Http/
    RoutePath.php                         base(Repository), apiPath()
    AccessPolicy.php                      token/api_token/IP/callback/local decision; mode() for about/check
    AccessDecision.php                    enum Allowed|Unauthenticated|Forbidden
    MetricsController.php                 GET {base}/metrics
    JobListController.php                 GET {base}/jobs/{list}
    JobList.php                           enum pending|completed|failed|delayed; values(); horizonPath()
    JobListQuery.php                      readonly parsed filters; fromRequest(); filtersFromRequest() (shared with /live); VARIABLES; 422 on invalid
    JobListService.php                    supervisor/batch-kind -> queue/class sets; empty -> short-circuit
    JobRows.php                           row formatting per list (ms, runtime rounding, horizon_path)
    ExceptionSummary.php                  "ShortClass: message" first line by ExceptionMode, <= 1000 bytes, mb_scrub
    Middleware/Authorize.php              AccessPolicy -> 401/403 (text or JSON)
    Middleware/ForceJson.php              Accept: application/json
    Middleware/InjectGrafanaLink.php      sidebar link in horizon.index HTML responses
    LiveController.php                    GET {base}/live (Addendum R4.3)
  Live/                                   (Addendum R4, Q7′)
    LiveScope.php                         scope from the shared var-* filters / full(); cacheKey()
    LiveSnapshot.php                      typed schema v1; toArray(); tiles()
    Captions.php                          caption/level/colour rules shared with src/Grafana
    LiveSnapshotBuilder.php               Horizon + queue_state live mode + ring -> snapshot
    LiveSnapshotCache.php                 stale-while-revalidate cache; freshness by Redis key expiry; Redis lock
    AttemptRing.php                       ring key/ttl for the writer; 60 s window reader
    Stream/
      LiveStreamer.php                    leader, build, encode, push, backoff, meta updates
      LeaderLock.php                      SET NX PX + lock.lua
      LineProtocol.php                    tiles + delayed measurements; escaping; unavailable frame
      GrafanaLivePusher.php               Http client POST /api/live/push/{id}
      PushResult.php
  Grafana/                                (@internal; ported generator)
    DashboardOptions.php                  uid, title, job, scrape interval, regexes, prefix, api defaults
    DashboardBuilder.php                  panels, variables, layout, links
    AlertRulesBuilder.php                 rule groups from the shared constants and panel registry
    Promql.php                            sel()/range() with prefix, job scope, filters, dedupe; filtered()
    Variables.php, PanelRegistry.php, Thresholds.php
    Panels/{Tiles,Queues,Jobs,JobLists,Batches,AlertSound,Common}.php
    Json.php                              deterministic 2-space printer
    LegacyProfile.php                     TEMPORARY (WP8A), deleted at the end of WP8B
    Live/{LiveMode,LivePanels}.php        (WP8D, Addendum R4.8) live panel rendering
    Perf/StaticBudget.php                 (WP8C, Addendum R2.2) request count / interpolated size / [1d] checks
  Console/
    InstallCommand.php                    PUBLIC horizon-observability:install
    CheckCommand.php                      PUBLIC horizon-observability:check
    GrafanaCommand.php                    PUBLIC horizon-observability:grafana
    CacheCommand.php                      PUBLIC horizon-observability:cache
    ClearCommand.php                      PUBLIC horizon-observability:clear
    FlushCommand.php                      PUBLIC horizon-observability:flush
    StreamCommand.php                     PUBLIC horizon-observability:stream (Addendum R4.6)
  Support/
    FailureReporter.php                   throttled logging (in-process + Cache::add), channel-aware, exception object
    Versions.php                          Laravel/Horizon/package versions; payloadCarriesDelay(); supportsOptimize()
    Diagnostics.php                       data for about and check
config/horizon-observability.php
routes/horizon-observability.php
resources/lua/{record_attempt,reset_guard,queue_state,job_details,delayed_jobs,extremes_read,ring_read,lock}.lua
resources/views/menu-link.blade.php
resources/grafana/dashboards/horizon-observability.json
resources/grafana/alerting/horizon-observability.json
resources/grafana/js/alert-sound.js
resources/grafana/provisioning/{datasources/horizon-observability.yaml,dashboards/horizon-observability.yaml,grafana.ini.example}
```

**Public API (SemVer):**
- config keys and env vars (Q3);
- the metric contract (Q4);
- the HTTP paths, route names, query parameters and JSON fields (Q2);
- the seven command names and their options (including `horizon-observability:stream`, Addendum R4.6);
- `HorizonObservability::auth()` and `::resolveBatchKindUsing()`;
- the `AttemptStatus`, `BatchStatus` and `ExceptionMode` values;
- the dashboard default uid, its variable names (`prometheus_job`, `api_path`, `busy_window`, `scrape_interval`, `datasource`, `app_api`, `supervisor`, `batch_kind`, `queue`, `job_class`) and the alert uid suffixes (including `-live`);
- the view name `horizon-observability::menu-link`;
- from the addendum (Q7′): the route `horizon-observability.live` and its query parameters, the live snapshot JSON schema v1 (R4.4), the line-protocol measurements and fields (R4.7), the `live.*` config keys and env vars, the render options `--live`, `--live-poll-interval` and `--live-stream-id`, and the Grafana Live channel names `stream/<id>/tiles` and `stream/<id>/delayed`.

There are **no public interfaces in v0.x**: custom collectors, discoverers and stores are out of scope, and each can be added later without a break.

**Service provider responsibilities:**

`register()`:
1. `mergeConfigFrom(...)`.
2. Singletons (lazy closures, nothing resolved): `Config`, `RedisClients`, `CircuitBreaker`, `FailureReporter`, `Versions`, `Lua`, `Keys`, `Topology`, `AttemptTracker`, `AttemptStore`, `BatchLinks`, `JobClassCache`.
3. The application booting callback from Q2.2 (menu middleware and routes).

`boot()`:
1. `loadViewsFrom(__DIR__.'/../resources/views', 'horizon-observability')`.
2. Console only:
   - `publishes()` for `config/horizon-observability.php` (tag `horizon-observability-config`) and `resources/grafana/**` (tag `horizon-observability-grafana`);
   - `commands([...7])`;
   - `if (method_exists($this, 'optimizes')) $this->optimizes(optimize: 'horizon-observability:cache', clear: 'horizon-observability:clear', key: 'horizon-observability');`
3. `AboutCommand::add('Horizon Observability', fn () => $this->app->make(Diagnostics::class)->about())`. It shows:
   - endpoint URLs and access mode;
   - namespace;
   - storage connection and client, with cluster flags;
   - collectors (enabled, auto, off);
   - payload-delay mode (native or legacy);
   - batching driver;
   - job-class cache state;
   - config problems.
4. When `enabled`:
   - `available_during_maintenance` → `PreventRequestsDuringMaintenance::except(...)`;
   - `collectors.attempts` → `Event::subscribe(AttemptTracker::class)`, plus, on a legacy stack only, `Event::listen(JobQueued::class, [LegacyReadyAt::class, 'onQueued'])`;
   - `collectors.batches !== false` → `Event::listen(JobQueued::class, [BatchLinks::class, 'onQueued'])`.
5. Nothing in `register()` or `boot()` touches Redis or the DB, and no collector is resolved.

**Horizon menu link:**

```php
private function appendHorizonMenuMiddleware(Repository $config): void
{
    if (blank($config->get('horizon-observability.grafana.url')) || ! class_exists(\Laravel\Horizon\Horizon::class)) {
        return;
    }
    $middleware = array_values(Arr::wrap($config->get('horizon.middleware', ['web'])));   // tolerate the string 'web'
    if (! in_array(InjectGrafanaLink::class, $middleware, true)) {
        $middleware[] = InjectGrafanaLink::class;
    }
    $config->set('horizon.middleware', $middleware);   // always an array: Horizon >= 5.45 spreads it
}
```

- **Why it works everywhere.**
  - Horizon 5.22–5.44 pass `config('horizon.middleware')` directly as route middleware in `boot()`.
  - 5.45+ build the `horizon` group in `boot()`, unconditionally, even with cached routes.
  - The booting callback runs after every `register()` and before Horizon's `boot()`, so `web` is never lost (C16).
  - The append is idempotent under `config:cache`, and `SortedMiddleware` deduplicates anyway.
  - On Horizon < 5.45 with `route:cache`, re-cache the routes after changing the Grafana URL (documented).
- **`InjectGrafanaLink`** acts only when all of these hold:
  - the request is GET and the route name is `horizon.index`;
  - the response is an `Illuminate\Http\Response` with status 200 and `text/html` content.

  It renders `view('horizon-observability::menu-link', ['url' => …])` (label "Grafana", Blade-escaped) right after the first `</li>` following `class="col-2 sidebar"`. Both markers are class constants. Missing markers leave the response untouched, and everything runs in `try`/`catch`. The middleware also no-ops if the URL is blank at request time.
- `Route::pushMiddlewareToGroup('horizon', …)` is rejected: it exists only on Horizon ≥ 5.45 and would force the floor up.

**Load-test command and job.** They move to the workbench and are not shipped:
- `workbench/app/Console/Commands/SimulateLoadCommand.php` (`workbench:simulate-load {--continuous} {--minutes=} {--burst=}`);
- `workbench/app/Simulation/{SimulatedJob,Outcome,TaskProfile,SimulatedFailure}.php`.

They read their queues from the workbench Horizon config, use `Random\Randomizer` and the `Sleep` facade (fakeable), produce English output, refuse non-`redis` connections, and catch errors per tick.

**Commands shipped:**

| Command | Purpose |
|---|---|
| `horizon-observability:install {--token=} {--no-env} {--force}` | Publishes the config (asks, or `--force`). Generates a 40-character token and appends `HORIZON_OBSERVABILITY_TOKEN=` to `.env`, plus a placeholder to `.env.example`, unless present or `--no-env`. Prints the effective endpoint URLs, the Prometheus scrape snippet (one target, bearer, 10 s), the Infinity datasource snippet, the Grafana service-account commands for stream mode (role Editor) and the `grafana` render command for each live mode (Addendum Q9′ WP10). Then runs `check`. Idempotent; supports `--no-interaction`. |
| `horizon-observability:check {--json} {--strict} {--live-push-test}` | Reports:<ul><li>config problems;</li><li>the live block (enabled; stream URL, token set or missing, stream id; `--live-push-test` pushes one `tiles` frame to stream id `<id>_check` and reports the HTTP status with the Addendum R4.10 hints for 401 and 403);</li><li>route precedence (live router match of both routes);</li><li>access mode (locked outside local is an error);</li><li>storage and reader connections (ping, latency, client, timeouts, persistent flag, cluster, braces in the prefix);</li><li>the `horizon` connection `read_timeout`;</li><li>Horizon version and the `horizon` group;</li><li>capabilities (payload delay, `JobTimedOut::$timeout`, `optimizes`);</li><li>batching driver and table;</li><li>job-class cache age and cap usage;</li><li>a scrape dry run per collector (success, duration).</li></ul>Exits 1 on errors, and on warnings with `--strict`. |
| `horizon-observability:grafana` | Renders the dashboard and the alerts (Q5). |
| `horizon-observability:cache` / `:clear` | The job-class discovery cache, hooked into `optimize` and `optimize:clear`. |
| `horizon-observability:flush {--force}` | Deletes every package storage key (counters, histograms, extremes, `job_classes`, `batch_links`, `meta`, `ready_at:*` and `live:*` via `SCAN` with the prefix). Asks for confirmation; in production it needs `--force`. Never hooked into `optimize:clear`. |
| `horizon-observability:stream {--once} {--max-time=3600} {--memory=128}` | Pushes the live snapshot to Grafana Live (`/api/live/push/<id>`) every `live.stream.interval` under a Redis leader lock; runs as a long-lived process (supervisord, systemd or a Deployment) on any number of replicas (Addendum R4.6). |

### Rationale

- A small, explicit public surface (hooks, config, metrics, HTTP, commands, dashboard names) is a SemVer promise maintainers can keep. Everything else stays refactorable.
- Feature slices map one-to-one to work packages.
- `install` and `check` are real DX wins over `about` alone: deploy pipelines need an exit code.

### Rejected alternatives

- **package-tools:** see above.
- **Public `Collector`, `BatchKindResolver`, `JobClassDiscoverer` and `AttemptStore` contracts:** premature freeze; the hooks and config cover the real needs.
- **A Facade:** static hooks match `Horizon::auth()`.
- **A copy of `horizon::layout`:** it would drift from Horizon.
- **Shipping the load generator behind a flag:** it is still runtime code in production (C25).
- **`prune`, and `clear --all` deleting metric data:** data deletion must never ride on `optimize:clear`.

### Risks

- **Horizon sidebar markup changes** make the link silently disappear, which is fail-safe. The CI test against the real layout runs on the lowest and latest Horizon, plus the weekly run.
- **About 70 small classes:** offset by `final`, `@internal` and the architecture tests.

---

## Q8. Tooling and tests

### Decision

#### 8.1 `composer.json`

```json
{
  "name": "sushi-market/horizon-observability",
  "description": "Prometheus metrics, JSON job lists, a Grafana dashboard and alert rules for Laravel Horizon and its Redis queues.",
  "license": "MIT",
  "type": "library",
  "keywords": ["laravel", "horizon", "queue", "redis", "prometheus", "grafana", "metrics", "monitoring", "observability"],
  "homepage": "https://github.com/sushi-market/horizon-observability",
  "support": {
    "issues": "https://github.com/sushi-market/horizon-observability/issues",
    "source": "https://github.com/sushi-market/horizon-observability"
  },
  "require": {
    "php": "^8.2",
    "laravel/framework": "^11.23 || ^12.0 || ^13.0",
    "laravel/horizon": "^5.29.1"
  },
  "require-dev": {
    "driftingly/rector-laravel": "^2.1",
    "ergebnis/composer-normalize": "^2.45",
    "larastan/larastan": "^3.1",
    "laravel/pint": "^1.18",
    "orchestra/testbench": "^9.5|^10.0|^11.0",
    "pestphp/pest": "^3.8|^4.1",
    "phpstan/extension-installer": "^1.4",
    "phpstan/phpstan-deprecation-rules": "^2.0",
    "predis/predis": "^2.0|^3.0",
    "rector/rector": "^2.0",
    "symfony/yaml": "^7.0|^8.0"
  },
  "suggest": {
    "ext-redis": "Recommended Redis client (phpredis).",
    "predis/predis": "Pure-PHP Redis client, supported as an alternative (^2.0|^3.0)."
  },
  "autoload": { "psr-4": { "DF\\HorizonObservability\\": "src/" } },
  "autoload-dev": {
    "psr-4": {
      "DF\\HorizonObservability\\Tests\\": "tests/",
      "Workbench\\App\\": "workbench/app/",
      "Workbench\\Database\\Factories\\": "workbench/database/factories/",
      "Workbench\\Database\\Seeders\\": "workbench/database/seeders/"
    }
  },
  "config": {
    "allow-plugins": { "ergebnis/composer-normalize": true, "pestphp/pest-plugin": true, "phpstan/extension-installer": true },
    "sort-packages": true
  },
  "extra": { "laravel": { "providers": ["DF\\HorizonObservability\\HorizonObservabilityServiceProvider"] } },
  "minimum-stability": "stable",
  "prefer-stable": true,
  "scripts": {
    "post-autoload-dump": ["@clear", "@prepare"],
    "clear": "@php vendor/bin/testbench package:purge-skeleton --ansi",
    "prepare": "@php vendor/bin/testbench package:discover --ansi",
    "build": "@php vendor/bin/testbench workbench:build --ansi",
    "serve": ["Composer\\Config::disableProcessTimeout", "@build", "@php vendor/bin/testbench serve --host=0.0.0.0 --port=8000 --ansi"],
    "horizon": ["Composer\\Config::disableProcessTimeout", "@php vendor/bin/testbench horizon --ansi"],
    "load": ["Composer\\Config::disableProcessTimeout", "@php vendor/bin/testbench workbench:simulate-load --continuous --ansi"],
    "lint": ["@php vendor/bin/pint --parallel --ansi", "@php vendor/bin/rector --ansi"],
    "test:lint": ["@php vendor/bin/pint --test --ansi", "@php vendor/bin/rector --dry-run --ansi"],
    "test:types": "@php vendor/bin/phpstan analyse --memory-limit=1G --ansi",
    "test:unit": "@php vendor/bin/pest --exclude-group=redis --exclude-group=cluster --exclude-group=e2e",
    "test:redis": "@php vendor/bin/pest --group=redis",
    "test:predis": "REDIS_CLIENT=predis vendor/bin/pest --group=redis",
    "test:grafana": "@php vendor/bin/testbench horizon-observability:grafana resources/grafana --defaults --check --ansi",
    "test": ["@test:lint", "@test:types", "@php vendor/bin/pest --exclude-group=cluster --exclude-group=e2e"]
  }
}
```

- There is no `version` field and no committed `composer.lock`. `ext-pcntl` and `ext-posix` come via Horizon.
- There are no runtime dependencies besides the framework and Horizon: no promphp, no spatie and no package-tools.
- `symfony/yaml` is dev-only, for parsing the legacy `horizon.yml` in M2 tests.
- pest-plugin-laravel is not used: the Orchestra `TestCase` gives `$this->get()`, and 4.x would need Laravel ≥ 11.45.2.

**Version bands per Laravel line** (packagist, 2026-10-16). The prefer-lowest legs are the executable proof.

| Laravel | PHP | Horizon (lowest) | Testbench | Pest / PHPUnit | Larastan (static job only) |
|---|---|---|---|---|---|
| 11.23+ | 8.2–8.4 | 5.29.1 (5.22.0 before the phase B floor raise) | 9.5.x (lowest leg; testbench-core 9.6.3) … 9.18 | Pest 3.8+ / PHPUnit 11.5; Pest 4 on PHP ≥ 8.3 | 3.1+ |
| 12.x | 8.2–8.5 | 5.30.3 | 10.0.0 (needs 12.0.1, so the lowest leg resolves 12.0.1) … 10.12 | Pest 3.8+ on 8.2; Pest 4 on ≥ 8.3 | 3.1+ |
| 13.x | 8.3–8.5 | 5.44.0 | 11.0.0 … 11.3 | Pest 4.1+ / PHPUnit 12 | 3.9.3+ |

Pest 5 (PHP ≥ 8.4) is excluded until every leg can run it.

#### 8.2 Workbench

`testbench.yaml` is owned by WP0 and final:

```yaml
# No `laravel: '@testbench'`: testbench-core 9.x has no such alias (phase B integration); the default skeleton is the same.
providers:
  - Workbench\App\Providers\WorkbenchServiceProvider
env:
  - APP_ENV=local
  - APP_KEY=base64:<generated once in WP0>
  - APP_URL=http://localhost:8000              # browser-facing: horizon_info{url}
  - DB_CONNECTION=sqlite                       # job_batches/failed_jobs from testbench default migrations
  - QUEUE_CONNECTION=redis
  - REDIS_CLIENT=phpredis
  - REDIS_HOST=127.0.0.1
  - REDIS_PORT=6379
  - CACHE_STORE=array
  - SESSION_DRIVER=array
  - HORIZON_OBSERVABILITY_TOKEN=workbench-token-0123456789abcdef
  - HORIZON_OBSERVABILITY_GRAFANA_URL=http://localhost:3000/d/horizon-observability
  - HORIZON_OBSERVABILITY_TTS_URL=browser
workbench:
  start: /horizon
  install: true
  health: false
  discovers: { web: true, api: false, commands: true, components: false, views: false, config: true }
  build: [create-sqlite-db, db-wipe, migrate-fresh]
```

```
workbench/
  app/Providers/WorkbenchServiceProvider.php    (WP0) Horizon::auth(fn () => true)
  app/Discovery/**                              (WP6) discovery fixtures: plain job, inherited ShouldQueue, abstract base,
                                                own displayName(), queued listener, notification, mailable,
                                                ShouldQueue event without handle(), helpers file with functions
  app/Simulation/{SimulatedJob,Outcome,TaskProfile,SimulatedFailure}.php   (WP9)
  app/Console/Commands/SimulateLoadCommand.php  (WP9) workbench:simulate-load
  config/horizon.php                            (WP0) local+testing: supervisor-default [default] max 3 (auto);
                                                supervisor-slow [slow, reports] max 2; supervisor-pool [mail, sms] balance=false;
                                                waits {redis:default 10, redis:slow 30}
  config/horizon-observability.php              (WP0) job_class_paths => [workbench_path('app/Discovery'), workbench_path('app/Simulation')]
  routes/web.php                                (WP9) GET /demo/dispatch?count=&queue=
```

Horizon child processes re-read `testbench.yaml` through `TESTBENCH_WORKING_PATH` (audit-verified). After `composer dump-autoload`, run `composer build` again, because `purge-skeleton` deletes the SQLite DB.

#### 8.3 Docker dev stack (export-ignored)

```yaml
name: horizon-observability
services:
  redis:
    image: ${REDIS_IMAGE:-redis:7.4-alpine}         # valkey/valkey:8-alpine via REDIS_IMAGE
    command: [redis-server, --save, '', --appendonly, 'no']
    ports: ['${REDIS_PORT:-6379}:6379']
  prometheus:
    image: quay.io/prometheus/prometheus:v3.15.0
    command: [--config.file=/etc/prometheus/prometheus.yml, --web.enable-remote-write-receiver, --storage.tsdb.retention.time=7d]
    extra_hosts: ['host.docker.internal:host-gateway']
    volumes:
      - ./docker/prometheus/${PROMETHEUS_CONFIG:-prometheus.yml}:/etc/prometheus/prometheus.yml:ro
      - ./docker/prometheus/token:/etc/prometheus/token:ro
      - prometheus-data:/prometheus
    ports: ['9090:9090']
  grafana:
    image: ${GRAFANA_IMAGE:-grafana/grafana:13.2.4}   # grafana/grafana:12.4.13 via GRAFANA_IMAGE
    depends_on: [prometheus]
    extra_hosts: ['host.docker.internal:host-gateway']
    environment:
      GF_SECURITY_ADMIN_PASSWORD: admin
      GF_AUTH_ANONYMOUS_ENABLED: 'true'
      GF_PLUGINS_PREINSTALL_SYNC: yesoreyeram-infinity-datasource@4.1.1,marcusolsson-dynamictext-panel@6.3.0
      GF_UNIFIED_ALERTING_STATE_HISTORY_ENABLED: 'true'
      GF_UNIFIED_ALERTING_STATE_HISTORY_BACKEND: multiple
      GF_UNIFIED_ALERTING_STATE_HISTORY_PRIMARY: annotations
      GF_UNIFIED_ALERTING_STATE_HISTORY_SECONDARIES: prometheus
      GF_UNIFIED_ALERTING_STATE_HISTORY_PROMETHEUS_TARGET_DATASOURCE_UID: prometheus
      HORIZON_OBSERVABILITY_PROMETHEUS_UID: prometheus
      HORIZON_OBSERVABILITY_APP_URL: ${APP_URL_FROM_GRAFANA:-http://host.docker.internal:8000}
      HORIZON_OBSERVABILITY_API_TOKEN: workbench-token-0123456789abcdef
    volumes:
      - ./docker/grafana/provisioning/datasources/prometheus.yaml:/etc/grafana/provisioning/datasources/prometheus.yaml:ro
      - ./resources/grafana/provisioning/datasources/horizon-observability.yaml:/etc/grafana/provisioning/datasources/horizon-observability.yaml:ro
      - ./resources/grafana/provisioning/dashboards:/etc/grafana/provisioning/dashboards:ro
      - ./docker/grafana/provisioning/plugins:/etc/grafana/provisioning/plugins:ro     # .gitkeep: avoids a provisioning error
      - ./resources/grafana/alerting:/etc/grafana/provisioning/alerting:ro
      - ./resources/grafana/dashboards:/etc/grafana/dashboards/horizon-observability:ro
      - grafana-data:/var/lib/grafana
    ports: ['3000:3000']
  app:        { profiles: [app], build: docker/app, command: sh -c "composer build && vendor/bin/testbench serve --host=0.0.0.0 --port=8000", environment: { REDIS_HOST: redis }, volumes: ['.:/app', 'vendor:/app/vendor'], ports: ['8000:8000'] }
  horizon:    { profiles: [app], build: docker/app, command: vendor/bin/testbench horizon, environment: { REDIS_HOST: redis }, volumes: ['.:/app', 'vendor:/app/vendor'] }
  simulate:   { profiles: [app], build: docker/app, command: vendor/bin/testbench workbench:simulate-load --continuous, environment: { REDIS_HOST: redis }, volumes: ['.:/app', 'vendor:/app/vendor'] }
  renderer:   { profiles: [screenshots], image: 'grafana/grafana-image-renderer:v5.12.7' }
volumes: { prometheus-data: {}, grafana-data: {}, vendor: {} }
```

- **`docker/app/Dockerfile`:** `php:8.4-cli`, `pecl install redis`, `docker-php-ext-install pcntl pdo_sqlite`, Composer.
- **WP9 must verify** that a process environment variable (`REDIS_HOST=redis`) overrides the `testbench.yaml` `env` entry. If it does not, the app image writes a workbench `.env` override.
- **Prometheus configs:**

  | File | Target | Use |
  |---|---|---|
  | `docker/prometheus/prometheus.yml` | `host.docker.internal:8000` | host PHP |
  | `prometheus.app.yml` | `app:8000` | `PROMETHEUS_CONFIG=prometheus.app.yml APP_URL_FROM_GRAFANA=http://app:8000` |
  | `prometheus.replicas.yml` | `:8000` and `:8001` | dedupe e2e |

  All use `scrape_interval: 10s`, `scrape_timeout: 8s` (Addendum R3.2), `metrics_path: /horizon/observability/metrics` and `authorization: {type: Bearer, credentials_file: /etc/prometheus/token}`. The token file holds the dev token only.
- **`docker/grafana/provisioning/datasources/prometheus.yaml`:** uid `prometheus`, `url: http://prometheus:9090`, `isDefault: true`, `jsonData.timeInterval: 10s` (plus the Addendum R2.5 settings: `httpMethod: POST`, `prometheusType`, `prometheusVersion`, `incrementalQuerying`).
- **Addendum additions** (R2.2, R4.9, Q9′ WP9): `GF_DASHBOARDS_MIN_REFRESH_INTERVAL=1s` on the dev Grafana; `docker/grafana/bootstrap-live.sh` creates the Editor service account and token and writes it to `docker/grafana/live-token` (gitignored); a `stream` service (profile `app`) runs `testbench horizon-observability:stream` with that token; a `perf` profile (synthetic backfill with `promtool`, continuation exporter, `bin/perf.mjs`, `docker/perf/{budget.json,prometheus.yml}`).
- **Daily loop:**
  1. `docker compose up -d`
  2. `composer build && composer serve` (binds 0.0.0.0)
  3. `composer horizon`
  4. `composer load`
  5. open `http://localhost:3000/d/horizon-observability`

  Fully containerised: `PROMETHEUS_CONFIG=prometheus.app.yml APP_URL_FROM_GRAFANA=http://app:8000 docker compose --profile app up -d`.
- **`bin/screenshot.mjs`** (Playwright): `locale: 'en-US'` (otherwise Grafana fails with "Invalid language tag"), `timezoneId: 'UTC'`, viewport 1920×3600, kiosk mode. It expands "Details · Jobs" and "Details · Batches" with `getByText(…, {exact: true})`, waits for network idle plus 8 s, and writes `build/screenshots/*.png`. Run the simulation for at least 15 minutes first.

#### 8.4 Test suite layout (Pest; `tests/TestCase.php` extends `Orchestra\Testbench\TestCase` with `WithWorkbench`)

```
tests/
  Pest.php  TestCase.php                         (WP0) env-driven REDIS_HOST/PORT/CLIENT; per-run key prefix 'hot_<pid>:';
                                                 FLUSHDB of the test DB in setUp for group redis; flushState() in tearDown
  Support/   (WP0)
    Exposition.php        text -> [family => [labelsKey => value]] + HELP/TYPE
    HorizonState.php      master/supervisor hashes exactly like Redis{Master,}SupervisorRepository::update()
    QueuePayloads.php     raw payload builders + RPUSH/ZADD helpers
    RunsWorker.php        Artisan queue:work --once --stop-when-empty in-process
    RedisCommands.php     command counter on the app manager AND RedisClients::manager() (enableEvents/listen)
    FakeEvents.php        11.23/12 and 13 event shapes (stdClass)
  Fixtures/  payloads.php (WP0), exposition/*.txt (WP1), grafana/{legacy,default}/* (WP8), per-WP subfolders
  Unit/ Integration/ Feature/ Grafana/ Contract/ Architecture/
```

The groups are `redis` (real Redis, both clients), `cluster`, `grafana` and `e2e`. Unit tests never need Redis. Tests are not run with `--parallel`, because isolation is one key prefix per process.

**Key cases:**

**Unit**
- `TextFormat`: escaping; `1e17` precision; `NaN`/`±Inf`; integral floats; `le` spelling; family and sample order; empty namespace; empty families omitted.
- `MetricFamily`: label-set mismatch, duplicate set, bad histogram shape, reserved names.
- `HistogramSnapshot`: changed buckets, `count == +Inf`.
- `Exporter` (fake collectors): a throwing collector is isolated; a partial failure keeps its data with `success=0`; the budget skips the rest in order; disabled collectors are absent; self-metrics are last.
- `Config`: every rule of Q3; derived defaults; `horizon.path` `''` → `horizon/observability`; a cluster connection is accepted.
- `AccessPolicy`: the full decision table.
- `AttemptOutcome`: both shapes, with and without `timeout`.
- `PayloadClock`, `JobClassLabel`, `BatchKind` (`campaign_launch_15_2026-10-15` → `campaign_launch`; UUIDs; hex tokens; `null`, `''` and `'123'` → `unnamed`; Cyrillic preserved; the hook).
- `BatchSnapshot` status matrix; `ExceptionSummary` in all modes (`@anonymous`, broken UTF-8, ` in file:line` removal); `JobListQuery` (repeated `var-*`, `$__all`, url-encoded backslashes, unknown vars, 422s).
- `HorizonUrl` (domain, port, scheme, trailing slashes, override), `Labels::topN`/`sanitize`, `Keys` (all keys in one CRC16 slot), `Versions` (faked versions, dev branch → legacy), `CircuitBreaker` (faked clock), `Grafana\Json`.

**Integration** (group `redis`, phpredis and predis, with a non-empty key prefix)
- **`record_attempt.lua`:** statuses, buckets, sum, extremes and TTL, the cap → `(other)`, the 30-day prune, a legacy `ready_at` `GET`+`DEL`, the dropped counter.
- **Reads:** zero-fill, cumulative buckets, stale-field `HDEL`, a bucket change. Reset guard: missing meta, same run id, changed run id, `INFO` failure as a no-op.
- **`queue_state.lua`:** counts; byte cap; `(unscanned)`/`(unknown)`; retried or released head skipped; delay-aware readiness; due delayed jobs; reserved > 1000 → `ZCARD`; limit 0; Lua error → partial failure; non-Redis connection skipped.
- **`JobStore`** (seeded through Horizon's `JobRepository`): newest first; period filter; expired hashes; balance=false supervisor filter; empty filter → **0 commands**; exception and attempts via Lua.
- **Other stores:** `DelayedJobStore`; `BatchReader` on SQLite (statuses, allowFailures, the 7-day and 1-hour bounds, `LIMIT`, DynamoDB driver, missing table); `BatchLinks` (memo, prune, breaker).
- **Discovery:** the fixture matrix (functions file never included; inherited `ShouldQueue` found); cache file and runtime cache.
- **`AttemptTracker` with a real in-process worker:** completed, released, retried, failed, max attempts (no duration). A manual `JobTimedOut` with a real `RedisJob` (capped duration). `JobTimedOut`→`JobAttempted` and `JobAttempted`→`JobTimedOut` are each counted once.
- **Exclusions and dispatch paths:** sync, deferred (12.45+) and background dispatches leave no keys; a database connection is not tracked; `->delay(now()->addMinute())` does not throw (A01).
- **Unreachable storage** (`127.0.0.1:1`, `worker_timeout 0.2`): one timeout per cooldown; the job still succeeds; one log line.

**Feature**
- **Provider:** route precedence (both orders, uncached, cached, compiled, negative control); `horizon.middleware` keeps `web` in both orders (C16); `enabled=false` is inert (no routes, no listeners, no link).
- **Metrics endpoint:** content type; promtool-clean fixture; Redis down → 200 with self-metrics; the deadline header honoured.
- **Access:** the auth matrix (local open, non-local locked 403, token valid/invalid/missing 401 with `WWW-Authenticate`, rotated list, `api_token` separation, IP-only, IP+token, callback); JSON error bodies on job lists; no cache write when unauthenticated; maintenance mode.
- **Job lists:** JSON snapshots per list; 422; the `limit` cap.
- **Menu link:** present right after "Dashboard" in the real `GET /horizon` HTML; absent without a URL; Horizon JSON API untouched.
- **Commands and about:** `about`; `install --no-interaction`; `check` exit codes and `--json`; `cache`/`clear` and the `optimize` hook (asserts `method_exists === version_compare(App::VERSION, '11.27.1', '>=')`); `flush`.

**Grafana:** the Q5.7 list, plus `GoldenPortTest` and `LegacyAlertsTest` (temporary) and `LayoutProjectionTest`.

**Contract:**
- `ReadinessParityTest`;
- `LiveScrapeContractTest` (WP11): a seeded full scrape contains every family and label referenced by both assets;
- `ConfigDocsTest`: every config key is in the README;
- `MetricDocsTest`: the README metric table equals the `Metric` enum.

**Architecture** (Pest `arch()`):
- `declare(strict_types=1)` everywhere;
- no `dd`, `dump`, `ray` or `var_dump`;
- `src` never uses `Workbench\`, `Tests\` or `App\`;
- all classes `final`;
- `config()` only in the allowed classes (Q3); `env()` only in `config/`;
- `Facades\Redis` never in `src` (only `RedisClients` and container-bound repositories);
- `Collectors` do not depend on `Http`;
- ASCII-only `src/`, `resources/lua` and `resources/views`;
- after WP8B: no `office` in `src/` or `resources/`.

#### 8.5 CI (`.github/workflows/tests.yml`)

The workflow runs on push to `master` and `claude/**`, pull requests, `workflow_dispatch` and `schedule: '23 5 * * 1'`. It sets `permissions: contents: read` and cancels concurrent runs per ref.

```yaml
tests:
  strategy:
    fail-fast: false
    matrix:
      php: ['8.2', '8.3', '8.4', '8.5']
      laravel: ['11.*', '12.*', '13.*']
      stability: [prefer-stable]
      client: [phpredis]
      redis: ['redis:7.4-alpine']
      exclude:
        - { php: '8.2', laravel: '13.*' }        # Laravel 13 needs PHP 8.3
        - { php: '8.5', laravel: '11.*' }        # Laravel 11 not targeted on 8.5
      include:
        - { laravel: '11.*', testbench: '^9.5' }
        - { laravel: '12.*', testbench: '^10.0' }
        - { laravel: '13.*', testbench: '^11.0' }
        - { php: '8.2', laravel: '^11.23', testbench: '^9.5',  stability: prefer-lowest, client: predis,   predis: '^2.0', redis: 'redis:6.2-alpine' }
        - { php: '8.2', laravel: '^12.0',  testbench: '^10.0', stability: prefer-lowest, client: phpredis, redis: 'redis:6.2-alpine' }
        - { php: '8.3', laravel: '^13.0',  testbench: '^11.0', stability: prefer-lowest, client: phpredis, redis: 'valkey/valkey:7.2-alpine' }
        - { php: '8.4', laravel: '12.*',   testbench: '^10.0', stability: prefer-stable, client: predis,   predis: '^3.0', redis: 'redis:7.4-alpine' }
        - { php: '8.5', laravel: '13.*',   testbench: '^11.0', stability: prefer-stable, client: predis,   predis: '^3.0', redis: 'valkey/valkey:8-alpine' }
        - { php: '8.4', laravel: '13.*',   testbench: '^11.0', stability: prefer-stable, client: phpredis, redis: 'redis:8-alpine' }
  services:
    redis: { image: '${{ matrix.redis }}', ports: ['6379:6379'], options: '--health-cmd "redis-cli ping" --health-interval 5s --health-retries 10' }
  env: { REDIS_CLIENT: '${{ matrix.client }}', REDIS_HOST: 127.0.0.1, REDIS_PORT: 6379 }
  steps:
    - actions/checkout@v7
    - shivammathur/setup-php@v2 with extensions: redis, pcntl, posix, pdo_sqlite, mbstring; coverage: none
    - composer remove --dev --no-update larastan/larastan rector/rector driftingly/rector-laravel laravel/pint ergebnis/composer-normalize phpstan/extension-installer phpstan/phpstan-deprecation-rules
    - composer require --no-update "laravel/framework:${{ matrix.laravel }}"
    - composer require --dev --no-update "orchestra/testbench:${{ matrix.testbench }}" ${{ matrix.predis && format('"predis/predis:{0}"', matrix.predis) || '' }}
    - composer update --${{ matrix.stability }} --prefer-dist --no-interaction --no-progress
    - composer show laravel/framework laravel/horizon orchestra/testbench pestphp/pest predis/predis
    - vendor/bin/pest --ci --exclude-group=cluster --exclude-group=e2e
```

The matrix gives 10 base legs plus 6 targeted legs. The prefer-lowest legs resolve:
- Laravel 11.39.1 with Horizon 5.29.1, testbench 9.5.0 (core 9.6.3) and Pest 3.8 (Pest 3.8 needs collision 8.7, which conflicts with Laravel < 11.39.1, so 11.23–11.39.0 are covered by the shims' tests and upstream checks only; measured in phase B);
- Laravel 12.0.1 with Horizon 5.30.3;
- Laravel 13.0.0 with Horizon 5.44.0.

Removing the static tooling is an optimisation: Composer would otherwise pick old versions anyway. It keeps resolution fast and honest.

**Other jobs.** The `grafana` and `e2e` jobs run with `strategy.matrix.grafana: [grafana/grafana:12.4.13, grafana/grafana:13.2.4]`, passed to compose as `GRAFANA_IMAGE` (Infinity 4.1.1 needs ≥ 12.2.5, Business Text 6.3.0 needs ≥ 12.3.0).

| Job | What it does |
|---|---|
| `cluster` | PHP 8.4, Laravel 13.* stable (≥ 13.20). GitHub services cannot override `command`, so a step runs `docker run -d -p 7000:7000 redis:7.4-alpine redis-server --port 7000 --cluster-enabled yes --save '' --appendonly no`, then `redis-cli -p 7000 CLUSTER ADDSLOTSRANGE 0 16383`. Then `pest --group=cluster` once with `REDIS_CLIENT=phpredis` and once with `predis`. |
| `static` | PHP 8.4, Laravel 13: `composer validate --strict`, `composer normalize --dry-run`, `pint --test`, `rector --dry-run`, `phpstan analyse --error-format=github` (level 9). |
| `coverage` | PHP 8.4, Laravel 13, pcov: `pest --coverage --min=85 --exclude-group=cluster --exclude-group=e2e`. |
| `grafana` | `composer test:grafana`. `pest --group=grafana` writes `build/grafana-rules.yml` (all expressions, macros substituted) and `build/scrape.txt` (seeded exposition). `docker run --rm --entrypoint promtool -v $PWD/build:/w quay.io/prometheus/prometheus:v3.15.0 check rules /w/grafana-rules.yml` and `… check metrics < build/scrape.txt`. Provisioning smoke: `docker compose up -d prometheus grafana`, poll `/api/health`, `GET /api/dashboards/uid/horizon-observability`, assert that `/api/v1/provisioning/alert-rules` has 4 rules, and fail on any provisioning `level=error` log line. |
| `dist` | `git archive HEAD \| tar -t \| sort` must equal the allowlist: `src/`, `config/`, `routes/`, `resources/`, `composer.json`, `LICENSE`, `README.md`, `CHANGELOG.md`, `UPGRADE.md`. |
| `e2e` | Schedule and dispatch only. Redis service; `composer build`; two `testbench serve` processes (`:8000`, `:8001`) and Horizon in the background; `docker compose up -d prometheus grafana` with `prometheus.replicas.yml`; then with `prometheus.yml`. `workbench:simulate-load` for about 10 minutes. It compares panel query results between the two topologies (the dedupe gate), runs `node bin/screenshot.mjs` and uploads the PNGs (visual regression of the look). Addendum: also the `docker/grafana/live-probe.json` checks (WP-L2), the `bin/perf.mjs` report on the medium profile (R2.2; non-blocking until WP11) and screenshots for `--live=poll` and `--live=stream` (Q9′ WP11). |

The weekly cron runs the matrix at prefer-stable, which picks up new Laravel, Horizon and Grafana releases: sidebar markup, SPA routes, `ProvisioningPlan`, Horizon Redis structures and route precedence.

#### 8.6 Static analysis and style

- **Larastan level 9** on `src`, `config` and `routes`, with no baseline, `phpstan-deprecation-rules` and `checkMissingCallableSignature: true`. Tests are not analysed (Pest closures are noisy); they are covered by arch tests and Rector. Level 10 may come in a later minor.
- **Pint:** `laravel` preset plus `declare_strict_types`, `final_class` and `ordered_imports`.
- **Rector:**
  - `withPhpSets(php82: true)` (the floor);
  - the prepared sets deadCode, codeQuality, typeDeclarations and earlyReturn;
  - the rector-laravel set for Laravel 11 (`LaravelLevelSetList::UP_TO_LARAVEL_110`; WP0 confirms the constant name in the installed version).

  `legacy/` is excluded from all tools until WP11 deletes it.

#### 8.7 Release process

- **Versions.** SemVer tags `vX.Y.Z` on `master`, no `version` field, Packagist through the GitHub webhook.
- **0.x while office pilots.** `v0.1.0` comes after WP11. `v1.0.0` freezes the public contract (Q7) after office has run it in production for two weeks.
- **What counts as breaking:** renaming or removing a config key, env var, metric, label or label value; changing a path default or a JSON field; renaming a dashboard variable or uid; renaming a command or option. In 0.x a break is a minor bump with an UPGRADE entry; from 1.0 it is a major bump.
- **Deprecation policy:** deprecate in a minor, remove in the next major.
- **`CHANGELOG.md`** follows Keep a Changelog 1.1.0. PRs add to `Unreleased`; the integrator resolves merge order. The intro references the pre-extraction history in office.
- **`release.yml`** runs on tag push. It verifies that the CHANGELOG has the version section, runs `composer validate` and `test:grafana`, and creates the GitHub Release from that section. Dependabot covers GitHub Actions weekly.

#### 8.8 Repo hygiene

- **`.gitattributes`:**
  - `* text=auto eol=lf`, `*.png binary`;
  - export-ignore for `/.github /art /bin /docker /docs /legacy /tests /tools /workbench /.editorconfig /.gitattributes /.gitignore /CONTRIBUTING.md /CODE_OF_CONDUCT.md /SECURITY.md /docker-compose.yml /phpstan.neon.dist /phpunit.xml.dist /pint.json /rector.php /testbench.yaml`.
- **`.editorconfig`:** UTF-8, LF, final newline, 4 spaces; 2 spaces for json, yml, yaml and neon; `*.md` keeps trailing whitespace.
- **`.gitignore`:** `/vendor /composer.lock /build /coverage /.phpunit.cache /node_modules /screenshots /.idea /.vscode .DS_Store .env .phpunit.result.cache`.
- **`LICENSE`** (MIT).
- **`README.md`** (English):
  - badges, screenshot, compatibility table (PHP 8.2–8.5; Laravel 11.23+/12/13; Horizon 5.29.1+; Grafana ≥ 12.3; Prometheus with the remote-write receiver for the Alerts tile);
  - a two-minute install; the secure-by-default note; endpoints;
  - Prometheus setup (one target); Grafana setup (Infinity bearer, state history, `grafana` command);
  - the configuration table; the metric reference generated from `Metric`;
  - dashboard variables; sound and speech;
  - cost and topology notes; cluster stance; troubleshooting (`check`);
  - stability policy, security, upgrading.
- **`UPGRADE.md`** (Appendix G), **`CHANGELOG.md`**.
- **`CONTRIBUTING.md`:** PHP 8.2+, ext-redis or predis, Docker, Node for screenshots; the dev loop; `composer test`, `test:predis`, `test:grafana`; dashboard edits only through `src/Grafana` plus golden updates and screenshots.
- **`SECURITY.md`:** GitHub private advisories; supported versions. The endpoints expose job class names, exception summaries and batch names, so set a token; `exceptions=class|none`.
- **`CODE_OF_CONDUCT.md`** (Contributor Covenant 2.1).
- **`.github/`:**
  - `ISSUE_TEMPLATE/{bug_report.yml,feature_request.yml,config.yml}`, where the bug report asks for the PHP, Laravel, Horizon, package, Redis client and server, Prometheus, Grafana and plugin versions, plus an exposition excerpt and `check --json`;
  - `PULL_REQUEST_TEMPLATE.md` (tests, CHANGELOG, `test:grafana`, screenshots for visual changes);
  - `dependabot.yml`.
- **`docs/`:** the Russian internal notes are deleted in WP11, after their useful content has moved into the README and UPGRADE.

### Rationale

- The matrix tests every shim at its oldest point (11.23, in practice 11.39.1 with Horizon 5.29.1, see 8.5; 12.0.1; 13.0), every branching client and server combination (phpredis/predis; Redis 6.2/7.4/8; Valkey 7.2/8; cluster) and every supported PHP × Laravel pair, without a full cartesian product. That is about 17 test jobs.
- Golden and contract tests turn "keep the look and behaviour" into executable checks. The docker stack makes screenshots reproducible against the workbench.

### Rejected alternatives

- **The full cartesian matrix (60+ legs):** slow, with no extra branch coverage.
- **PHPUnit-only:** the owner chose Pest.
- **pest-plugin-laravel:** an unnecessary floor.
- **Larastan 8:** weaker exactly where the Redis reply-shape bugs live. **Larastan max:** facade generics noise.
- **Committing `composer.lock`:** wrong for a library.
- **`--parallel` tests:** per-process Redis isolation adds complexity for little gain.

### Risks

| Risk | Mitigation |
|---|---|
| The prefer-lowest legs (L11.23 + Horizon 5.22 + Pest 3.8) expose an incompatibility | Raise a *test-only* floor (e.g. testbench `^9.5.2`), or a patch-level runtime floor (e.g. `^5.24`) recorded in the CHANGELOG; no design change. Happened in phase B: Horizon `^5.29.1` (fatal `pop()` signature on Laravel ≥ 11.27) and the dev floor predis `^2.0.2` (Laravel 11's own; 2.0.0 raises PHP 8.2 deprecations). |
| Docker Hub rate limits | quay.io for Prometheus; `mirror.gcr.io` for Redis and Valkey. |
| Pest 3 and 4 API differences | Use only the common API. |
| Testbench 9.x workbench gaps | `discovers.config` is verified on 9.5.0. Fallback: `config()` calls in `WorkbenchServiceProvider::register()`. |

---

## Q9. Implementation plan

**Addendum Q9′** adds WP-L1 (live snapshot and endpoint), WP-L2 (stream command), WP8C (dashboard performance) and WP8D (live panels), changes WP0b, WP1, WP2, WP3, WP8B, WP9, WP10 and WP11, and maps all packages to the task ids of `planning/TASKS.md` (T12 = WP-L1 + WP-L2; T13 = WP8B + WP8C + WP8D). Its critical path is WP0a → WP8A → WP8B → WP8C → WP8D → WP11.

### Rules for all work packages

1. **Exclusive ownership.** Every file has exactly one owning WP. Stubs created by WP0 become the property of the WP that implements them.
2. **One integrator.** The WP0 agent stays as integrator and is the only one who may edit the **shared files**:
   - `composer.json`, the config file, the route file and the provider;
   - `Config/*`, `Metrics/Metric.php`, `Redis/Keys.php`;
   - `testbench.yaml`, `workbench/config/*`, `workbench/app/Providers/*`;
   - `phpunit.xml.dist`, `phpstan.neon.dist`, `pint.json`, `rector.php`;
   - `tests/Pest.php`, `tests/TestCase.php`, `tests/Support/*`, `tests/Fixtures/payloads.php`;
   - `.gitattributes`, `.gitignore`, `.editorconfig`, `CHANGELOG.md`.

   Other WPs request changes through small PRs reviewed by the integrator.
3. **Fixtures per WP.** Each WP adds its own fixtures only under `tests/Fixtures/<area>/` and its tests only under its own `tests/<Layer>/<Area>/` directories.
4. **Exit criteria.** Every WP ends green on `pest` (its groups, on phpredis **and** predis where `redis` applies), `phpstan` level 9 for its paths and `pint --test`. Every confirmed bug it owns has a named test (Appendix H).
5. **Reference code.** The old code lives in `legacy/` (outside autoload, excluded from tools, export-ignored) for reference, and is deleted in WP11.

### WP0: Foundation and frozen contracts (blocking; 1 agent, then integrator)

**WP0a: bootstrap** (first hours; it unblocks WP8A):
- Move to `legacy/`:
  - `src/` → `legacy/src/`;
  - `config/` → `legacy/config/`;
  - `resources/views/` → `legacy/views/`;
  - `resources/grafana/generate_queues_dashboard.py` → `legacy/grafana/`;
  - `docs/` → `legacy/docs/`;
  - `README.md` → `legacy/README.md`.
- Freeze the goldens:
  - `resources/grafana/dashboards/queues.json` → `tests/Fixtures/grafana/legacy/queues.json`;
  - `resources/grafana/alerting/horizon.yml` → `tests/Fixtures/grafana/legacy/horizon.yml`;
  - extract the panel #23 `afterRender` string to `tests/Fixtures/grafana/legacy/alert-sound.js`.
- New `composer.json` (8.1), `LICENSE`, `.gitattributes`, `.gitignore`, `.editorconfig`, the tool configs, `tests/Pest.php` and a minimal `tests/TestCase.php`, a stub `README.md` and `CHANGELOG.md` (Unreleased).
- **Acceptance:** `composer validate --strict`; `composer install` works on PHP 8.2 and 8.4; `vendor/bin/pest` runs (no tests yet).

**WP0b: contracts and wiring:**
- **Implemented in full:**
  - `config/horizon-observability.php` (Appendix A); `routes/horizon-observability.php`; the complete `HorizonObservabilityServiceProvider` (Q2.2, Q7); `HorizonObservability`;
  - `Config/*`;
  - `Metrics/{Metric,MetricType,MetricFamily,Sample,InvalidMetric,Labels,ScrapeContext}` (the full catalog with the Appendix B HELP texts);
  - `Collectors/Collector`;
  - `Redis/{Keys,Lua,RedisClients,CircuitBreaker}`;
  - `Support/{FailureReporter,Versions}`;
  - `Attempts/{AttemptStatus,JobClassLabel}`, `Batches/{BatchStatus,BatchSnapshot,BatchLink}`, `Queues/PayloadClock`;
  - `Horizon/{Topology,Supervisors,SupervisorSnapshot}`, which WP3 and WP4 both use;
  - `Http/{RoutePath,JobList,JobListQuery,AccessDecision}`.
- **Safe stubs** with final signatures (Appendix C) for every other class in the Q7 tree. Collectors return `[]`. `Authorize` always denies (403). Listeners are no-ops. `Diagnostics` returns the basics. Stubs never throw on the boot path.
- **Workbench:** `testbench.yaml` (final), `workbench/config/*`, `workbench/app/Providers/WorkbenchServiceProvider.php`.
- **Tests:** `tests/Support/*`, `tests/Fixtures/payloads.php`, and unit tests for everything implemented. WP0b also owns `tests/Feature/Provider/{RoutePrecedenceTest,HorizonMiddlewareTest,InertTest}`, because they only need the wiring: matching works against stub controllers.
- **Acceptance:**
  - `composer validate --strict`;
  - the app boots on the three prefer-lowest legs and on stable, in both provider orders;
  - `route:list` shows the three named routes (`live` only when `live.enabled`); the precedence and C16 tests are green;
  - `enabled=false` is inert;
  - the `Config` unit tests are green; the `Metric` catalog test equals the Q4 and Appendix B tables;
  - the architecture tests are green; phpstan level 9 is clean on WP0 files.

### Phase B (parallel after WP0b; WP8A already running since WP0a)

| WP | Owns | Depends on | Acceptance |
|---|---|---|---|
| **WP1 Exposition and access** | `Metrics/{TextFormat,Exporter}`, `Http/{MetricsController,AccessPolicy}`, `Http/Middleware/{Authorize,ForceJson}`, `tests/Unit/Metrics/*`, `tests/Unit/Http/AccessPolicyTest`, `tests/Feature/Metrics/*`, `tests/Feature/Access/*`, `tests/Fixtures/exposition/*` | WP0 model, `Config`, `RoutePath` | Golden exposition; promtool-clean fixture; isolation, partial-failure and budget cases; the auth matrix incl. rotation, `api_token`, IPs and callback; 401/403 bodies (text or JSON); no cache write when unauthenticated; maintenance exception (C3, C4, C5-endpoint). |
| **WP2 Attempts** | `Attempts/{AttemptOutcome,AttemptState,AttemptTracker,AttemptStore,CounterResetGuard,LegacyReadyAt}`, `Metrics/HistogramSnapshot`, `Collectors/AttemptsCollector`, `resources/lua/{record_attempt,reset_guard}.lua`, `tests/{Unit,Integration}/Attempts/*` | `Keys`, `Lua`, `RedisClients`, `CircuitBreaker`, `PayloadClock`, `JobClassLabel`, `Versions`, `Metric` | Every 6.2 event sequence; sync, deferred and background leave no keys; the breaker bound; cap, prune and lazy `HDEL`; reset guard; legacy `ready_at` on and off; real-worker flows; both clients (C2, C6, C11, C18, C19, C20, A01, A05). |
| **WP3 Horizon and queue state** | `Collectors/{HorizonCollector,QueueCollector}`, `Horizon/HorizonUrl`, `Queues/{QueueInspector,QueueSnapshot}`, `resources/lua/queue_state.lua`, `tests/{Unit,Integration}/{Horizon,Queues}/*`, `tests/Contract/ReadinessParityTest` | `Topology`, `Supervisors`, `RedisClients`, `PayloadClock` | Status 1/0/−1; balance=false pools; sentinels and `(other)`; C8 heads (delayed, retried, released); byte cap; partial failure per queue; `horizon_info{url,api_path,tts_url}`; unsupported cluster topology reported; parity test green (C5, C8, C14-metrics, C17). |
| **WP4 Job lists** | `Http/{JobListController,JobListService,JobRows,ExceptionSummary}`, `Horizon/JobStore`, `Queues/DelayedJobStore`, `resources/lua/{job_details,delayed_jobs}.lua`, `tests/Feature/JobLists/*`, `tests/Integration/JobStore/*` | `Supervisors`, `Topology`, the `BatchLinks::forKinds()` signature (WP5 stub), `JobListQuery` | JSON snapshots per list equal today's fields and units; empty filter → 0 Redis commands (C13); balance=false filter (C14); exception modes; 422. |
| **WP5 Batches** | `Batches/{BatchKind,BatchReader,BatchLinks}`, `Collectors/BatchesCollector`, `tests/{Unit,Integration}/Batches/*` | `BatchSnapshot`, `BatchStatus`, `BatchLink`, `Keys`, `CircuitBreaker` | Two bounded queries (query-log assertion); status matrix incl. allowFailures; 7 d and 1 h windows; caps and `(other)`; DynamoDB and missing-table detection; hook; links memo and prune (C1, C9-batches, C15). |
| **WP6 Discovery** | `Discovery/*`, `Collectors/JobClassesCollector`, `Console/{CacheCommand,ClearCommand}`, `workbench/app/Discovery/**`, `tests/Integration/Discovery/*`, `tests/Feature/Console/CacheCommandTest` | `Config`, `Versions` | Fixture matrix; the functions file is never included; inherited `ShouldQueue`; cache file, runtime cache; the `optimize` hook iff Laravel ≥ 11.27.1 (C22). |
| **WP7 Horizon menu link** | `Http/Middleware/InjectGrafanaLink`, `resources/views/menu-link.blade.php`, `tests/Feature/HorizonMenuLinkTest` | provider wiring (WP0) | Link right after "Dashboard" in the real `GET /horizon` on every matrix leg (Horizon 5.22 through latest); absent without a URL; JSON untouched; missing markers → untouched (C16 rendering side). |
| **WP8A Grafana golden port** (starts after WP0a) | `src/Grafana/**` incl. `LegacyProfile`, `tests/Grafana/{GoldenPortTest,LegacyAlertsTest,LayoutProjectionTest}` | nothing at runtime (`Metric` is used only from WP8B on) | M1: byte-identical (minimum: decoded-equal) legacy dashboard; M2: alert rules equal the parsed legacy YAML. |
| **WP9 Dev stack, CI, simulation** | `docker-compose.yml`, `docker/**`, `.github/**`, `bin/screenshot.mjs`, `workbench/app/Simulation/**`, `workbench/app/Console/Commands/SimulateLoadCommand.php`, `workbench/routes/web.php` | WP0 (testbench.yaml); `legacy/src/Console/QueueLoadTestCommand.php` as reference | All workflows run; red is allowed until phase B lands. `docker compose up` provisions Grafana 12.4.13 and 13.2.4 without errors; the Prometheus target is up with bearer auth; `composer serve` + `horizon` + `load` produce a scrape Prometheus accepts; the screenshot script runs (C25). |

### Phase C (sequential tail)

| WP | Owns | Depends on | Acceptance |
|---|---|---|---|
| **WP8B Grafana parametrisation** | `src/Grafana/**` (continues WP8A; sequential, so no overlap), `Console/GrafanaCommand`, `resources/grafana/**`, `tests/Grafana/*` (contract tests), `tests/Fixtures/grafana/default/*` | WP8A, `Metric`, `RoutePath`, `JobListQuery::VARIABLES`, `JobRows` field lists (WP4) | The Q5.6 steps 1–12, each its own commit with a screenshot; `--check` green; the Q5.7 contract tests green; `promtool check rules` clean; `LegacyProfile` and the legacy goldens deleted at the end. |
| **WP10 Operations commands** | `Console/{InstallCommand,CheckCommand,FlushCommand}`, `Support/Diagnostics`, `tests/Feature/Console/*` (except WP6's), `tests/Feature/AboutTest` | WP1–WP7 (develops against the stubs from day 1) | `install --no-interaction` in the workbench produces a working token, the snippets and a passing `check`. `check` catches each seeded problem (locked auth, shadowed route, missing table, no timeout, persistent client, config problem, cluster topology) with the right exit codes and `--json`. `flush` deletes exactly the package keys. |
| **WP11 Integration, docs, release** | `README.md`, `UPGRADE.md`, `CONTRIBUTING.md`, `SECURITY.md`, `CODE_OF_CONDUCT.md`, `art/`, `tests/Contract/{LiveScrapeContractTest,ConfigDocsTest,MetricDocsTest}`, deletion of `legacy/` and `planning/` | everything | Full matrix green incl. cluster, grafana and dist. The weekly e2e is green, including the 2-replica dedupe equality. Screenshots are compared side by side with `planning/baseline/dashboard-*.png`, with no look change. Every bug id in Appendix H maps to a passing named test. Office dry run: namespace `office`, `--uid=horizon`, with data. Tag `v0.1.0`. |

### Critical path and schedule

```
WP0a ─┬─> WP0b ─┬─> WP1 ┐
      │         ├─> WP2 ┤
      │         ├─> WP3 ┤
      │         ├─> WP4 ┼─> WP10 ─┐
      │         ├─> WP5 ┤          ├─> WP11
      │         ├─> WP6 ┤          │
      │         ├─> WP7 ┘          │
      │         └─> WP9 ───────────┤
      └─> WP8A ───────> WP8B ──────┘
```

| When | Work |
|---|---|
| Day 0 | WP0a in the morning; WP0b, and WP8A from the afternoon |
| Days 1–4 | WP1–WP7 and WP9 in parallel; WP8A continues |
| Days 4–6 | WP8B and WP10 |
| Days 6–7 | WP11 |

The longest chain is WP0a → WP8A → WP8B → WP11. WP8A therefore starts first among the non-foundation packages.

---

## Appendix A. `config/horizon-observability.php`

```php
<?php

declare(strict_types=1);

return [

    /*
    | Master switch. false = no routes, no queue event listeners, no Horizon menu link.
    */
    'enabled' => env('HORIZON_OBSERVABILITY_ENABLED', true),

    /*
    | Metric name prefix: "<namespace>_<family>", e.g. ho_queue_jobs_total; self-metrics ho_exporter_*. '' = no prefix.
    */
    'namespace' => env('HORIZON_OBSERVABILITY_NAMESPACE', 'ho'),

    /*
    | Base path of the endpoints. null = "<horizon.path>/observability" (default "/horizon/observability"):
    |   GET {path}/metrics       Prometheus text exposition
    |   GET {path}/jobs/{list}   JSON job lists for Grafana: pending, completed, failed, delayed
    |   GET {path}/live          live snapshot JSON for Grafana (when live.enabled)
    */
    'path' => env('HORIZON_OBSERVABILITY_PATH'),

    /*
    | Host the endpoints answer on. null = any host (Prometheus usually scrapes an internal address).
    | Horizon's own domain is deliberately not inherited.
    */
    'domain' => env('HORIZON_OBSERVABILITY_DOMAIN'),

    /*
    | Access. Bearer tokens (comma-separated for rotation, each >= 16 characters) for both endpoints.
    | api_token: optional separate tokens for the job lists (Grafana); null = same as token.
    | allowed_ips: optional IPs/CIDRs (comma-separated); combined with tokens, both must pass.
    | With none of them (and no HorizonObservability::auth() callback) the endpoints answer only
    | in the "local" environment, like Horizon's default gate; everywhere else they return 403.
    */
    'token' => env('HORIZON_OBSERVABILITY_TOKEN'),
    'api_token' => env('HORIZON_OBSERVABILITY_API_TOKEN'),
    'allowed_ips' => env('HORIZON_OBSERVABILITY_ALLOWED_IPS'),

    /*
    | Extra route middleware, run after authorization, e.g. ['throttle:600,1'].
    */
    'middleware' => [],

    /*
    | Keep the endpoints reachable during "php artisan down".
    */
    'available_during_maintenance' => true,

    /*
    | Exception text in the failed-jobs list: full (short class + message, first line), class, none.
    */
    'exceptions' => env('HORIZON_OBSERVABILITY_EXCEPTIONS', 'full'),

    'redis' => [
        // Connection for the package's own keys. null = Horizon's "horizon" connection (keys carry horizon.prefix).
        'connection' => env('HORIZON_OBSERVABILITY_REDIS_CONNECTION'),
        // Seconds. Private clients: worker-side writes, and scrape/job-list reads.
        'worker_timeout' => 1.0,
        'scrape_timeout' => 2.0,
    ],

    /*
    | Collectors. Disabling one also stops the work behind it ('attempts' stops recording in workers).
    | batches: null = auto (database batching driver and existing table), true = required, false = off.
    */
    'collectors' => [
        'horizon' => true,       // horizon_status, horizon_supervisor_*, horizon_wait_threshold_seconds, horizon_info
        'queues' => true,        // queue_pending_jobs, queue_delayed_*, queue_oldest_pending_job_age_seconds, queue_jobs_pending|reserved
        'attempts' => true,      // queue_jobs_total, queue_job_duration_*, queue_job_wait_seconds
        'job_classes' => true,   // queue_job_classes
        'batches' => null,       // queue_batch*
    ],

    /*
    | Histogram buckets (seconds) for queue_job_duration_seconds and queue_job_wait_seconds.
    | Safe to change: buckets are stored non-cumulatively.
    */
    'buckets' => [0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10, 30, 60, 120, 300, 600, 1800, 3600],

    /*
    | Jobs inspected from the head of each queue per scrape for the per-class breakdown and the oldest age.
    | The rest is reported as job_class="(unscanned)". 0 = head only. Redis is blocked while a queue is scanned.
    */
    'queue_scan_limit' => (int) env('HORIZON_OBSERVABILITY_QUEUE_SCAN_LIMIT', 1000),

    /*
    | Upper bound of distinct job_class values in attempt metrics; further classes become "(other)".
    | Classes idle for 30 days are pruned automatically.
    */
    'max_job_classes' => 500,

    /*
    | Directories scanned for queueable classes (queue_job_classes). null = [app_path()].
    | Cached by "php artisan horizon-observability:cache" (runs with "php artisan optimize").
    */
    'job_class_paths' => null,

    /*
    | Public Horizon URL used for dashboard links. null = app.url + horizon.domain + horizon.path.
    */
    'horizon_url' => env('HORIZON_OBSERVABILITY_HORIZON_URL'),

    'grafana' => [
        // Dashboard URL. When set, Horizon's sidebar gets a "Grafana" link right after "Dashboard".
        'url' => env('HORIZON_OBSERVABILITY_GRAFANA_URL'),
        // Spoken alerts: null = tone only, 'browser' = Web Speech API, or a Piper HTTP server base URL
        // (POST {url}/synthesize, must allow CORS from Grafana's origin).
        'tts_url' => env('HORIZON_OBSERVABILITY_TTS_URL'),
    ],

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

    /*
    | Log channel for the package's (throttled) warnings. null = default channel.
    */
    'log_channel' => env('HORIZON_OBSERVABILITY_LOG_CHANNEL'),

];
```

## Appendix B. Metric catalog (`Metrics\Metric`), in declaration order

| Case | Family (without namespace) | Type | Labels | Collector | HELP |
|---|---|---|---|---|---|
| HorizonStatus | `horizon_status` | gauge | – | horizon | Horizon status: 1 running, 0 paused (at least one master supervisor paused), -1 no master supervisor. |
| SupervisorProcesses | `horizon_supervisor_processes` | gauge | supervisor, queue | horizon | Worker processes of a Horizon supervisor per queue; balance=false supervisors report their pooled queue list. |
| SupervisorMinProcesses | `horizon_supervisor_min_processes` | gauge | supervisor | horizon | Configured minimum worker processes of a Horizon supervisor. |
| SupervisorMaxProcesses | `horizon_supervisor_max_processes` | gauge | supervisor | horizon | Configured maximum worker processes of a Horizon supervisor. |
| SupervisorInfo | `horizon_supervisor_info` | gauge | supervisor, balance, connection | horizon | Horizon supervisor settings; always 1. |
| WaitThreshold | `horizon_wait_threshold_seconds` | gauge | connection, queue | horizon | Long-wait threshold of a queue from horizon.waits, seconds. |
| HorizonInfo | `horizon_info` | gauge | url, api_path, tts_url | horizon | Links for the Grafana dashboard: Horizon URL, job-list API path and text-to-speech URL; always 1. |
| QueuePendingJobs | `queue_pending_jobs` | gauge | connection, queue | queues | Jobs ready to run and waiting for a worker, including delayed jobs that are already due. |
| QueueDelayedJobs | `queue_delayed_jobs` | gauge | connection, queue | queues | Delayed jobs (dispatched or released with a delay), including due ones; see queue_delayed_due_jobs. |
| QueueDelayedDueJobs | `queue_delayed_due_jobs` | gauge | connection, queue | queues | Delayed jobs whose delay has expired but that have not been moved to the queue yet. |
| QueueOldestPendingJobAge | `queue_oldest_pending_job_age_seconds` | gauge | connection, queue | queues | Seconds the oldest ready first-attempt job has waited since it became available; 0 when nothing is waiting. |
| QueueJobsPending | `queue_jobs_pending` | gauge | connection, queue, job_class | queues | Ready jobs per job class in the scanned head of the queue; (unscanned) counts the rest. |
| QueueJobsReserved | `queue_jobs_reserved` | gauge | connection, queue, job_class | queues | Jobs currently reserved by workers per job class. |
| JobsTotal | `queue_jobs_total` | counter | connection, queue, job_class, status | attempts | Finished job attempts by outcome: completed, released, retried, failed, timed_out. |
| JobDuration | `queue_job_duration_seconds` | histogram | connection, queue, job_class | attempts | Duration of job attempts, seconds. |
| JobWait | `queue_job_wait_seconds` | histogram | connection, queue | attempts | Seconds a job waited in the queue before its first attempt started. |
| JobDurationMin | `queue_job_duration_min_seconds` | gauge | connection, queue, job_class | attempts | Shortest job attempt during the last 150 seconds, seconds. |
| JobDurationMax | `queue_job_duration_max_seconds` | gauge | connection, queue, job_class | attempts | Longest job attempt during the last 150 seconds, seconds. |
| AttemptsDropped | `exporter_attempts_dropped_total` | counter | – | attempts | Job attempts not recorded because the metrics Redis was unavailable. |
| LivePushTimestamp | `exporter_live_push_timestamp_seconds` | gauge | – | attempts | Time of the last successful Grafana Live push of the live overview, Unix seconds. |
| LivePushFailures | `exporter_live_push_failures_total` | counter | – | attempts | Failed Grafana Live pushes of the live overview. |
| JobClasses | `queue_job_classes` | gauge | job_class | job_classes | Queueable job classes found in the application code; always 1. |
| BatchesRunning | `queue_batches_running` | gauge | batch | batches | Running job batches per batch kind. |
| BatchJobs | `queue_batch_jobs` | gauge | batch, state | batches | Jobs of running batches per batch kind and state: total, pending, failed. |
| BatchOldestAge | `queue_batch_oldest_age_seconds` | gauge | batch | batches | Age of the oldest running batch of a kind, seconds. |
| BatchJobCounts | `queue_batch_job_counts` | gauge | batch, name, state | batches | Jobs of a recent batch per state: total, pending, failed. |
| BatchCreated | `queue_batch_created_timestamp_seconds` | gauge | batch, name | batches | Creation time of a recent batch, Unix seconds. |
| BatchClosed | `queue_batch_closed_timestamp_seconds` | gauge | batch, name | batches | Finish or cancel time of a recent batch, Unix seconds. |
| BatchJobClasses | `queue_batch_job_classes` | gauge | batch, queue, job_class | batches | Queues and job classes seen in batches of a kind during the last 7 days; always 1. |
| BatchInfo | `queue_batch_info` | gauge | batch, queue, connection | batches | Queues and connections of a batch kind; always 1. |
| BatchProgress | `queue_batch_progress` | gauge | batch, name, status, id | batches | Progress of a recent batch, percent of processed jobs. |
| CollectorSuccess | `exporter_collector_success` | gauge | collector | self | Whether the last run of a metrics collector succeeded: 1 or 0. |
| CollectorDuration | `exporter_collector_duration_seconds` | gauge | collector | self | Duration of the last run of a metrics collector, seconds. |
| BuildInfo | `exporter_build_info` | gauge | version, php, laravel, horizon, redis_client | self | Versions of the package, PHP, Laravel, Horizon and the Redis client; always 1. |
| ConfigProblems | `exporter_config_problems` | gauge | – | self | Number of invalid configuration values replaced by defaults; see php artisan horizon-observability:check. |

`since()` is `0.1.0` for every case. The self-metric names, the two live cases and the min/max HELP texts come from Addendum R1, R3.4 and Appendix B′. Example names with the default namespace: `ho_queue_jobs_total`, `ho_exporter_collector_success`.

## Appendix C. Frozen interfaces (land in WP0b)

```php
namespace DF\HorizonObservability;

final class HorizonObservability
{
    /** @param (\Closure(\Illuminate\Http\Request): bool)|null $callback */
    public static function auth(?\Closure $callback): void;
    /** @param (\Closure(?string $name, string $id): string)|null $callback */
    public static function resolveBatchKindUsing(?\Closure $callback): void;
    /** @internal */ public static function authCallback(): ?\Closure;
    /** @internal */ public static function batchKindResolver(): ?\Closure;
    /** @internal */ public static function flushState(): void;   // hooks, PreventRequestsDuringMaintenance::flushState(), memos
}

namespace DF\HorizonObservability\Config;

final readonly class ConfigProblem { public function __construct(public string $path, public string $message) {} }
enum ExceptionMode: string { case Full = 'full'; case ClassOnly = 'class'; case None = 'none'; }
final readonly class Config
{
    /**
     * @param list<string> $tokens  @param list<string> $apiTokens  @param list<string> $allowedIps  @param list<string> $middleware
     * @param array{horizon: bool, queues: bool, attempts: bool, job_classes: bool, batches: bool|null} $collectors
     * @param list<float> $buckets  @param list<string> $jobClassPaths  @param list<ConfigProblem> $problems
     * @param list<string> $liveGrafanaUrls   (the six live properties: Addendum A′, C′)
     */
    public function __construct(
        public bool $enabled, public string $namespace, public ?string $path, public ?string $domain,
        public array $tokens, public array $apiTokens, public array $allowedIps, public array $middleware,
        public bool $availableDuringMaintenance, public ExceptionMode $exceptions,
        public string $redisConnection, public float $workerTimeout, public float $scrapeTimeout,
        public array $collectors, public array $buckets, public int $queueScanLimit, public int $maxJobClasses,
        public array $jobClassPaths, public ?string $horizonUrl, public ?string $grafanaUrl, public ?string $ttsUrl,
        public bool $liveEnabled, public float $liveCacheTtl, public array $liveGrafanaUrls, public ?string $liveToken,
        public string $liveStreamId, public float $liveStreamInterval,
        public ?string $logChannel, public array $problems,
    ) {}
    public static function fromRepository(\Illuminate\Contracts\Config\Repository $config): self;
    public function prefix(): string;                        // '' or "<namespace>_"
}

namespace DF\HorizonObservability\Metrics;

enum MetricType: string { case Counter = 'counter'; case Gauge = 'gauge'; case Histogram = 'histogram'; }
enum Metric: string
{
    // cases exactly as Appendix B (value = family name without namespace)
    public function type(): MetricType;
    public function help(): string;
    /** @return list<string> */ public function labels(): array;
    public function collector(): string;                     // horizon|queues|attempts|job_classes|batches|self
    public function since(): string;
}
final readonly class Sample { /** @param array<string,string> $labels */ public function __construct(public string $suffix, public array $labels, public int|float $value) {} }
final class MetricFamily
{
    public static function of(Metric $metric): self;
    /** @param array<string,string> $labels */ public function set(array $labels, int|float $value): self;
    /** @param array<string,string> $labels @param array<string,int> $cumulativeBuckets le => count, ascending, '+Inf' last */
    public function observe(array $labels, array $cumulativeBuckets, float $sum): self;
    public function metric(): Metric;
    /** @return list<Sample> */ public function samples(): array;
    public function isEmpty(): bool;
}
final class Labels
{
    public const UNSCANNED = '(unscanned)'; public const UNKNOWN = '(unknown)'; public const OTHER = '(other)'; public const UNNAMED = 'unnamed';
    public static function sanitize(string $value): string;
    /** @param array<string,int> $counts @return array<string,int> */ public static function topN(array $counts, int $n): array;
}
final class ScrapeContext
{
    public function __construct(\Carbon\CarbonImmutable $now, float $budgetSeconds);
    public function now(): \Carbon\CarbonImmutable;
    public function remaining(): float;
    public function deadlineExceeded(): bool;
    /** @template T @param \Closure(): T $resolve @return T */ public function remember(string $key, \Closure $resolve): mixed;
    public function reportFailure(string $source, \Throwable $e): void;
    /** @internal @return list<array{source: string, exception: \Throwable}> */ public function takeFailures(): array;
}
final class Exporter { public function scrape(\Illuminate\Http\Request $request): string; }
final class TextFormat { /** @param iterable<MetricFamily> $families */ public function render(iterable $families, string $prefix): string; }
final class HistogramSnapshot
{
    /** @param array<string,int> $stored le => non-cumulative count @param list<float> $bounds @return array<string,int> */
    public static function cumulative(array $stored, array $bounds): array;
}

namespace DF\HorizonObservability\Collectors;

/** @internal */
interface Collector
{
    public function name(): string;                          // horizon|queues|attempts|job_classes|batches
    /** @return iterable<\DF\HorizonObservability\Metrics\MetricFamily> */
    public function collect(\DF\HorizonObservability\Metrics\ScrapeContext $context): iterable;
}

namespace DF\HorizonObservability\Redis;

final class Keys
{
    public function __construct(string $root = '{horizon-observability}:v1:');
    public function attempts(): string; public function durationBuckets(): string; public function durationSum(): string;
    public function waitBuckets(): string; public function waitSum(): string;
    /** @param int $bucket unix div 15 (Addendum R3.4) @param 'min'|'max' $kind */ public function extremes(int $bucket, string $kind): string;
    public function jobClasses(): string; public function meta(): string; public function batchLinks(): string;
    public function readyAt(string $uuid): string; public function readyAtPattern(): string;
    // Addendum C′:
    public function ring(int $bucket): string;                                         // live:ring:<unix div 5>
    public function liveSnapshot(string $hash): string; public function liveSnapshotStale(string $hash): string;
    public function liveSnapshotLock(string $hash): string; public function liveLeader(): string;
    public function liveDelayedWindow(): string; public function livePattern(): string; // live:* for flush
    /** @return list<string> */ public function counterKeys(): array;   // keys deleted by the reset guard
    /** @return list<string> */ public function all(): array;           // fixed keys for flush (ready_at via pattern)
}
final class RedisClients
{
    public function storage(): \Illuminate\Redis\Connections\Connection;
    public function reader(string $connection): \Illuminate\Redis\Connections\Connection;
    public function manager(): \Illuminate\Redis\RedisManager;
    public function isCluster(string $connection): bool;
}
final class Lua
{
    /** @param list<string> $keys @param list<string|int|float> $args */
    public function eval(\Illuminate\Redis\Connections\Connection $connection, string $script, array $keys, array $args): mixed;  // throws RedisScriptFailed
}
final class CircuitBreaker
{
    /** @param \Closure(): void $write */ public function attempt(string $operation, \Closure $write): bool;
    public function isOpen(): bool;
    public function takeDropped(): int;
}

namespace DF\HorizonObservability\Support;

final class FailureReporter
{
    public function report(string $key, \Throwable $e, array $context = []): void;     // throttled 300 s per key
    public function warnOnce(string $key, string $message, array $context = []): void;
}
final class Versions
{
    public function laravel(): string; public function horizon(): ?string; public function package(): ?string;
    public function redisClient(): string; public function payloadCarriesDelay(): bool; public function supportsOptimize(): bool;
}

namespace DF\HorizonObservability\Horizon;

final class Topology
{
    /** @return array<string, list<string>> queue connection => queues */ public function queuesByConnection(): array;
    public function environment(): string;
}
final readonly class SupervisorSnapshot
{
    /** @param array<string,int> $processes queue label => processes @param list<string> $queues split queue names */
    public function __construct(public string $name, public string $master, public string $connection, public string $balance,
                                public int $minProcesses, public int $maxProcesses, public array $processes, public array $queues) {}
}
final class Supervisors
{
    /** @return list<SupervisorSnapshot> */ public function all(): array;   // memoised via ScrapeContext by callers
    public function status(): int;                                          // 1, 0, -1
}

namespace DF\HorizonObservability\Queues;

final class PayloadClock
{
    /** @param array<string,mixed> $payload */ public static function readyAt(array $payload): ?float;
    /** @param array<string,mixed> $payload */ public static function isFirstAttempt(array $payload): bool;
}

namespace DF\HorizonObservability\Attempts;

enum AttemptStatus: string { case Completed = 'completed'; case Released = 'released'; case Retried = 'retried'; case Failed = 'failed'; case TimedOut = 'timed_out'; }
final class JobClassLabel { public static function normalize(?string $displayName): string; }

namespace DF\HorizonObservability\Batches;

enum BatchStatus: string { case Running = 'Running'; case Finished = 'Finished'; case Failures = 'Failures'; case Cancelled = 'Cancelled'; }
final readonly class BatchLink { public function __construct(public string $kind, public string $connection, public string $queue, public string $jobClass) {} }
final readonly class BatchSnapshot
{
    public function __construct(public string $id, public string $name, public string $kind, public BatchStatus $status,
                                public int $total, public int $pending, public int $failed, public int $progress,
                                public int $createdAt, public ?int $closedAt) {}
}
final class BatchLinks
{
    public function onQueued(\Illuminate\Queue\Events\JobQueued $event): void;
    /** @return list<BatchLink> */ public function all(): array;
    /** @param list<string> $kinds @return array{queues: list<string>, jobs: list<string>} */ public function forKinds(array $kinds): array;
}

namespace DF\HorizonObservability\Http;

final class RoutePath
{
    public static function base(\Illuminate\Contracts\Config\Repository $config): string;   // without slashes
    public static function apiPath(\Illuminate\Contracts\Config\Repository $config): string; // '/'.base
}
enum JobList: string
{
    case Pending = 'pending'; case Completed = 'completed'; case Failed = 'failed'; case Delayed = 'delayed';
    /** @return list<string> */ public static function values(): array;
}
enum AccessDecision { case Allowed; case Unauthenticated; case Forbidden; }
final readonly class JobListQuery
{
    public const VARIABLES = ['supervisor', 'batch_kind', 'queue', 'job_class'];
    /** @param list<string>|null $supervisors (null = no filter) ... */
    public function __construct(public JobList $list, public int $limit, public ?int $fromMs, public ?int $toMs,
                                public ?array $supervisors, public ?array $batchKinds, public ?array $queues, public ?array $jobClasses) {}
    public static function fromRequest(\Illuminate\Http\Request $request, JobList $list): self;   // ValidationException -> 422
    /** Shared with /live and called by fromRequest(), so both endpoints parse var-* identically (Addendum C′). 422 on invalid.
     * @return array{supervisors: list<string>|null, batchKinds: list<string>|null, queues: list<string>|null, jobClasses: list<string>|null} */
    public static function filtersFromRequest(\Illuminate\Http\Request $request): array;
}

// Live classes (Addendum C′; LiveScope, LiveSnapshot and Captions are implemented in WP0b, the rest are stubs there)
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

/** Typed snapshot, schema v1 (Addendum R4.4). Frozen in WP0b; toArray() is the public JSON. */
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
final class LiveSnapshotCache   { public function get(LiveScope $scope): LiveSnapshot; }      // Addendum R4.2 algorithm
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
     * tiles rows stamped $timeNs + k ms in Addendum R4.7 order; null values encoded as -1.0.
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

Each Lua file starts with a header documenting its `KEYS` and `ARGV` (Appendix D), and that header is frozen with the file's first commit.

## Appendix D. Lua script contracts

All keys are passed in `KEYS[]`; scripts never build key names. Time is passed in `ARGV` as Unix seconds (float).

**`record_attempt.lua`** (WP2)
- **KEYS:** `attempts`, `duration:buckets`, `duration:sum`, `wait:buckets`, `wait:sum`, `extremes:<unix div 15>:min`, `extremes:<unix div 15>:max`, `job_classes`, `meta`, `live:ring:<unix div 5>` (always passed; Addendum D′), optional `ready_at:<uuid>`. PHP derives both bucket keys from the same `now` it passes in `ARGV`.
- **ARGV:** connection, queue, job_class, status, duration or `''`, duration bucket `le` or `''`, startedAt or `''`, readyAt or `''`, wait bounds (comma-separated, formatted by PHP), extremes TTL (300), max_job_classes, class idle TTL (2592000), ring_ttl (180, or 0 = disabled; Addendum D′), now, dropped count, `(other)`.
- **Steps:**
  1. Class admission: refresh `ZSCORE`; otherwise `ZCARD` < max → `ZADD`; otherwise prune `ZREMRANGEBYSCORE -inf (now-ttl` and retry; otherwise use the overflow label.
  2. `HINCRBY attempts <c␟q␟j␟status> 1`.
  3. If there is a duration: `HINCRBY` its bucket, `HINCRBYFLOAT` the sum, compare-and-set min/max, `EXPIRE` both extremes keys.
  4. If `startedAt` is present: `ready = max(readyAt, GET ready_at)` then `DEL ready_at`; `wait = max(0, startedAt - ready)`; bucket via the bounds; `HINCRBY`/`HINCRBYFLOAT`. A passed `ready_at` key is deleted even without `startedAt` (phase B).
  5. If dropped > 0: `HINCRBY meta dropped N`.
  6. If `ring_ttl > 0` (Addendum R4.5): `HINCRBY ring <c␟q␟status> 1` and `EXPIRE ring ring_ttl`.
- **Returns:** the effective job_class.

**`extremes_read.lua`** (WP2, Addendum D′)
- **KEYS:** the 10 min keys (current 15-second bucket and the 9 previous ones), then the 10 max keys.
- **Returns:** `{min_pairs…, '\x1e', max_pairs…}` with `field, value` pairs merged by `min` and `max`, the values as stored (no float formatting). Missing keys are skipped.

**`reset_guard.lua`** (WP2)
- **KEYS:** `meta`, then the counter keys (`attempts`, the duration and wait hashes).
- **ARGV:** now.
- **Behaviour:** reads `run_id` from `redis.call('INFO','server')`.
  - missing meta → `HSET meta run_id`, return 0;
  - equal → return 0;
  - different → `DEL` the counter keys, `HDEL meta dropped live_push_failures` (the counter fields of meta, so a restore does not double-count them; phase B), `HSET meta run_id reset_at`, return 1;
  - `INFO` fails (e.g. denied by an ACL) or has no `run_id` → no-op, return 0.

**`queue_state.lua`** (WP3)
- **KEYS:** `queues:<q>`, `queues:<q>:reserved`, `queues:<q>:delayed`.
- **ARGV:** now, scan_limit, reserved_scan_limit (1000), max_bytes (16777216), mode (`full`, or `live` for the live snapshot; Addendum D′).
- **Returns:** `{ pending_len, reserved_total, delayed_total, delayed_due, earliest_due_score|false, first_fresh_ready_at|false, scanned, pending_pairs{name,count…}, reserved_pairs{name,count…} }`.
- **`live` mode:** the readiness scan stops at `min(scan_limit, 20)` items and at `max_bytes` (1 MB from the builder); `pending_pairs` is empty; `reserved_pairs` only when `reserved_scan_limit > 0` (a Job or Batch filter is active).
- **Field extraction:** plain `string.find` (no patterns, because cjson reorders keys after a retry) of the top-level `"displayName":"`, `"attempts":`, `"pushedAt":` (string or number), `"createdAt":` and `"delay":`. `delay:null` means no delay. A key that occurs more than once in a payload (a string job's data, a payload hook) is read from the payload decoded with cjson instead (phase B).
- **Reply details (phase B):** `first_fresh_ready_at` is a `%.17g` string (PHP reads the exact double); `displayName` in the pairs is the raw JSON string content (PHP decodes it before `JobClassLabel::normalize()`); the reserved scan stops at `reserved_scan_limit` members and at 16 MB (the payload that reaches the cap is included); `scan_limit` 0 = head only.

**`job_details.lua`** (WP4)
- **KEYS:** up to 100 Horizon job hashes.
- **ARGV:** max_exception_bytes (1000; **0 = the exception is not read**, for `exceptions = none`).
- **Returns:** per key, `{attempts from payload or false, first exception line cut to the byte cap or false}`.
- `"attempts":` is looked for in the first and the last 4 KB of the payload before the whole payload (phase B).

**`delayed_jobs.lua`** (WP4)
- **KEYS:** `queues:<q>:delayed`, or `queues:{<q>}:delayed` on a cluster connection with first-class cluster queues (the key `QueueInspector::queueKey()` builds; phase B).
- **ARGV:** now, offset, count (≤ 500), max_bytes (optional byte budget of the call, 8 MB from `DelayedJobStore`; missing or 0 = none; the script always reads at least one entry and stops after the one that reaches the budget).
- **Returns:** `{read, more, row, row, …}`: `read` = zset entries consumed (the next call starts at `offset + read`), `more` = 0 when no further not-yet-due entry exists; rows `{uuid, displayName, attempts, pushedAt|false, score}` for not-yet-due entries whose payload is a JSON object, ascending by score, `uuid` and `displayName` as raw JSON string content (phase B).
- Entries are fetched 25 at a time inside the script; each key is looked for in the first and the last 4 KB of a payload before the whole payload (phase B).

**`ring_read.lua`** (WP-L1, Addendum D′)
- **KEYS:** the 12 complete 5-second bucket keys before the current one.
- **Returns:** a flat list of `field, sum`.

**`lock.lua`** (WP-L1, Addendum D′; WP-L2's `LeaderLock` reuses it)
- **KEYS:** the lock key.
- **ARGV:** op (`renew` or `release`), token, ttl_ms.
- **Behaviour:** `renew` runs `PEXPIRE` only if the value equals token; `release` runs `DEL` only if the value equals token.
- **Returns:** 1 or 0.

## Appendix E. Redis key schema

All keys sit under `{horizon-observability}:v1:` on the storage connection, which adds its own connection prefix (e.g. `laravel_horizon:`).

| Key | Type | Fields or members | TTL |
|---|---|---|---|
| `attempts` | hash | `conn␟queue␟class␟status` → count | – |
| `duration:buckets` | hash | `conn␟queue␟class␟le` → non-cumulative count | – |
| `duration:sum` | hash | `conn␟queue␟class` → float | – |
| `wait:buckets` | hash | `conn␟queue␟le` → count | – |
| `wait:sum` | hash | `conn␟queue` → float | – |
| `extremes:<unix div 15>:min`, `…:max` | hash | `conn␟queue␟class` → seconds (15-second buckets, Addendum R3.4) | 300 s |
| `job_classes` | zset | class → last seen | idle members pruned after 30 d |
| `meta` | hash | `run_id`, `reset_at`, `dropped`, `live_push_at` (Unix float), `live_push_failures` | – |
| `batch_links` | zset | `kind␟conn␟queue␟class` → last seen | members pruned after 7 d |
| `ready_at:<uuid>` | string | readiness, Unix seconds | delay + 86400 s (legacy stacks only) |
| `live:ring:<unix div 5>` | hash | `conn␟queue␟status` → count (attempt ring buffer, Addendum R4.5) | 180 s |
| `live:snapshot:<sha1(scope)>` | string | fresh snapshot JSON (schema v1); presence means fresh | `cache_ttl` (1 s) |
| `live:snapshot-stale:<sha1(scope)>` | string | the same JSON, served while another request rebuilds | `max(2 s, 10 × cache_ttl)` |
| `live:snapshot-lock:<sha1(scope)>` | string | lock token | 2 s |
| `live:leader` | string | `host:pid:rand` of the stream leader (leader lock) | 5 s, renewed every tick and before every push |
| `live:window:delayed` | list | `"<unix_ms>:<value>"`, newest first, trimmed to 179 | 1 h |

`␟` is `\x1f`. Old pilot keys (`prometheus:queue:*`) are never read. The `live:*` keys come from Addendum E′; `horizon-observability:flush` deletes them with `SCAN` on the prefix.

## Appendix F. Compatibility behaviour matrix

| Capability | 11.23–11.27.0 | 11.27.1–12.10 | 12.11–12.44 | 12.45+ | 13.0–13.24 | 13.25–13.32 | 13.33+ |
|---|---|---|---|---|---|---|---|
| `JobAttempted` exception property | `exceptionOccurred` | `exceptionOccurred` | `exceptionOccurred` | `exceptionOccurred` | `exception` | `exception` | `exception` |
| `JobTimedOut::$timeout` | – | – | – | – | – | yes | yes |
| `JobAttempted` after `JobTimedOut` possible | – | – | – | – | – | – | yes (`killOnTimeout=false`) |
| `SyncQueue` fires `JobAttempted` (ignored by the `RedisJob` rule) | – | – | – | yes | yes | yes | yes |
| Payload `createdAt`/`delay` | – | – | yes (`delay` needs Horizon ≥ 5.49) | yes | yes | yes | yes |
| `ready_at` fallback | on | on | on unless Horizon ≥ 5.49 | same | same | same | same |
| `optimizes()` hook | manual deploy step | yes | yes | yes | yes | yes | yes |
| Horizon-side Redis Cluster (needs Horizon ≥ 5.49) | – | – | – | – | 13.20–13.24 only | yes | yes |

## Appendix G. Office migration (input for UPGRADE.md)

1. **Packages.** Run `composer remove sushi-market/horizon-prometheus-grafana`, and remove `spatie/laravel-data` if only the package used it. Then `composer require sushi-market/horizon-observability:^0.1`. spatie/laravel-prometheus stays for the app's own metrics. Remove the package's old provider and any spatie `registerHorizonCollectors()`/`registerQueueCollectors()` calls added for it. The relabel-drop rules for `office_(queue|horizon)_.*` on the app's scrape job are no longer needed.
2. **Environment:**

   | Old | New |
   |---|---|
   | `HPG_ENABLED=false` | `collectors.attempts => false` in the published config |
   | `HPG_REDIS_CONNECTION` | `HORIZON_OBSERVABILITY_REDIS_CONNECTION` (old default `default`; new default `horizon`) |
   | `HPG_PENDING_SCAN_LIMIT` | `HORIZON_OBSERVABILITY_QUEUE_SCAN_LIMIT` |
   | `HPG_GRAFANA_URL` | `HORIZON_OBSERVABILITY_GRAFANA_URL` |
   | `HPG_TTS_URL` | `HORIZON_OBSERVABILITY_TTS_URL` |
   | `HPG_PROMETHEUS_DATASOURCE_UID` (Grafana) | `HORIZON_OBSERVABILITY_PROMETHEUS_UID` |
   | `routes.prefix = metrics/horizon` | optional `HORIZON_OBSERVABILITY_PATH=metrics/horizon` (metrics at `/metrics/horizon/metrics`, lists at `/metrics/horizon/jobs/{list}`); otherwise `/horizon/observability/*` |

   Add `HORIZON_OBSERVABILITY_NAMESPACE=office` and `HORIZON_OBSERVABILITY_TOKEN` (and optionally `HORIZON_OBSERVABILITY_API_TOKEN`).
3. **Prometheus.** Add a new scrape job (e.g. `office-horizon`) with a single logical target and the bearer token (Q2.5).
4. **Grafana:**
   - render with `php artisan horizon-observability:grafana --uid=horizon --job=office-horizon`. This keeps the dashboard uid, links, bookmarks and the alert uids `horizon-no-metrics`, `horizon-down`, `horizon-long-wait`, `horizon-failures`, so `GRAFANA_ALERTS` history and the sound continue;
   - replace the provisioned `queues.json` and `horizon.yml` with the rendered files;
   - switch the Infinity datasource to `auth_method: bearerToken` plus `allowedHosts`.
5. **Deploy.** Add `php artisan horizon-observability:check` to the pipeline. `php artisan optimize` now also caches job classes.
6. **One-time effects** (announce them):
   - **Series history breaks once:** the `job` label changes with the new scrape job, and the counters restart on new keys, which `increase()` handles. Keeping the old `job` value is not recommended: two scrape configs producing the same `job` and `instance` collide on `up`.
   - **Changed series:**
     - `office_horizon_current_processes` is gone (use `sum by (queue) (office_horizon_supervisor_processes)`);
     - `office_queue_oldest_pending_job_age` → `office_queue_oldest_pending_job_age_seconds`, with new semantics;
     - `office_horizon_info` gains `api_path`;
     - `office_queue_batch_progress` loses `size` and `created`;
     - the sentinel `job_class` values are now `(unscanned)` and `(unknown)`;
     - histogram `TYPE` metadata is corrected (series unchanged);
     - self-metrics are `office_exporter_*` (Addendum R1).
7. **Cleanup.** After a day, delete the old keys: `redis-cli --scan --pattern '*prometheus:queue:*' | xargs -r redis-cli del` (run on the old `HPG_REDIS_CONNECTION` database).

## Appendix H. Bug → fix → test map (WP11 exit gate)

| Id | Owning WP | Named test |
|---|---|---|
| C1 | WP5, WP1 | `Feature/Metrics/FailureIsolationTest` "missing batches table keeps 200"; `Integration/Batches/BatchReaderTest` "dynamodb driver skipped" |
| C2 | WP2 | `Integration/Attempts/RecordAttemptTest` (runs on predis and phpredis) |
| C3 | WP1 | `Feature/Metrics/MetricsEndpointTest` "counters are absolute across scrapes" |
| C4 | WP1, WP4 | `Feature/Access/AuthorizationTest` (matrix); `Feature/JobLists/ExceptionModeTest` |
| C5 | WP1, WP3 | `Feature/Metrics/FailureIsolationTest` "redis down keeps 200 with collector_success=0" |
| C6 | WP2 | `Integration/Attempts/ExclusionTest` "sync, deferred and background leave no keys" |
| C7 | WP8B, WP9 | `Grafana/DedupeCoverageTest`; e2e replica equality |
| C8 | WP3 | `Integration/Queues/QueueStateTest` "delayed, retried and released heads" |
| C9 | WP2, WP3, WP5 | `Integration/Attempts/CardinalityTest`; `Unit/Metrics/LabelsTest`; `Integration/Batches/CapsTest` |
| C10 | WP2 | `group(cluster)` `Integration/Attempts/ClusterStorageTest`; `Unit/Redis/KeysTest` "single slot" |
| C11 | WP2 | `Integration/Attempts/BreakerTest` "unreachable storage costs one timeout per cooldown" |
| C13 | WP4 | `Integration/JobStore/EmptyFilterTest` "zero redis commands" |
| C14 | WP4 | `Integration/JobStore/SupervisorFilterTest` "balance=false pool" |
| C15 | WP5 | `Integration/Batches/BoundedQueryTest` (query log) |
| C16 | WP0, WP7 | `Feature/Provider/HorizonMiddlewareTest` (both orders); `Feature/HorizonMenuLinkTest` |
| C17 | WP0, WP3 | `Unit/Metrics/MetricCatalogTest`; `Feature/Metrics/MetricsEndpointTest` "no spatie families" |
| C18 | WP2 | `Integration/Attempts/LegacyReadyAtTest` "no keys on a modern stack" |
| C19 | WP2, WP3 | `Unit/Queues/PayloadClockTest` "horizon retry"; `Contract/ReadinessParityTest` |
| C20 | WP2 | `Integration/Attempts/TimeoutTest` (both event orders, private client) |
| C22 | WP6 | `Integration/Discovery/JobClassFinderTest` "functions file never included"; `Feature/Console/CacheCommandTest` |
| C24 | WP0 | `Architecture/ArchTest` "ascii only" |
| C25 | WP9 | `dist` job allowlist; `Architecture/ArchTest` "src never uses Workbench" |
| A01 | WP2 | `Integration/Attempts/DispatchTest` "carbon delay does not throw" |
| A05 | WP2 | `Unit/Attempts/AttemptOutcomeTest` (11.23/12 and 13 shapes) |
