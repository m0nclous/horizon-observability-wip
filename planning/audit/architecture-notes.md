I changed nothing in the repository. Upstream sources used for verification are in /tmp/claude-0/-home-user-horizon-observability/cc6491ff-4b68-5ae7-bac7-d93fef57187c/scratchpad/audit/ (folders deps, q-1200, q-12, q-13, hz, hzv, http12, fw12, c1200). Other files in that folder (dl, gen_*.json) are not mine.

## Contracts the refactor must keep (the dashboard relies on them)
- **Metric families and labels:**
  - Horizon: `horizon_status`; `horizon_current_processes{queue}`; `horizon_supervisor_processes{supervisor,queue}`; `horizon_supervisor_min_processes{supervisor}`; `horizon_supervisor_max_processes{supervisor}`; `horizon_supervisor_info{supervisor,balance,connection}`; `horizon_wait_threshold_seconds{connection,queue}`; `horizon_info{url,tts_url}`.
  - Queue state: `queue_pending_jobs`, `queue_delayed_jobs`, `queue_delayed_due_jobs`, `queue_oldest_pending_job_age` (all `{connection,queue}`); `queue_jobs_pending`, `queue_jobs_reserved` (both `{connection,queue,job_class}`).
  - Attempts: `queue_jobs_total{...,status}`; `queue_job_duration_seconds_{bucket,sum,count}`; `queue_job_wait_seconds_*{connection,queue}`; `queue_job_duration_{min,max}_seconds`; `queue_job_classes{job_class}`.
  - Batches: `queue_batches_running{batch}`; `queue_batch_jobs{batch,state}`; `queue_batch_oldest_age_seconds`; `queue_batch_job_counts{batch,name,state}`; `queue_batch_created_timestamp_seconds{batch,name}`; `queue_batch_closed_timestamp_seconds{batch,name}`; `queue_batch_progress{batch,name,status,size,created,id}`; `queue_batch_job_classes{batch,queue,job_class}`; `queue_batch_info{batch,queue,connection}`.
- **Fixed values:**
  - `status` is one of completed, released, retried, failed, timed_out.
  - Batch status is one of Running, Finished, Failures, Cancelled; batch job state is one of total, pending, failed.
  - The supervisor label keeps the "name after the last colon" rule (the dashboard runs label_replace `supervisor-(.+)` on it).
  - Real histogram families render the same `_bucket`/`_sum`/`_count` series, so dashboard queries do not change.
- **HTTP:**
  - Routes: `GET <prefix>/{pending,completed,failed,delayed}-jobs?limit&from&to` (milliseconds) plus repeated `var-supervisor`, `var-batch_kind`, `var-queue`, `var-job_class`; `$__all` means no filter.
  - Body is wrapped in `{data:[...]}` with fields name, queue, horizon_path, queued_at, completed_at, failed_at, available_at, attempts, runtime, exception.
  - Default prefix stays `metrics/horizon`.
- **Prefix:** the dashboard prefix comes from the generator's `--prefix`; the shipped JSON must be generated for the package's default namespace. This is an open decision for the dashboard owner: a configurable namespace, default `laravel`, avoids collisions with spatie's `app_*`.

## Version floors (verified)
- **PHP:** ^8.3.
- **Laravel:** ^12.11|^13.0. 12.11 puts `delay` in the payload; 13 renamed `JobAttempted::$exceptionOccurred` to `$exception` and added `JobTimedOut::$timeout`.
- **Horizon:** ^5.49 for payload delay; the `horizon` middleware group exists from at least 5.45.
- **promphp:** ^2.x (needs php ^8.2).
- **spatie/laravel-prometheus** (optional bridge): 1.3/1.4 on PHP 8.3, 1.5+ needs PHP 8.4.
- **Laravel 12.0.0 already has** the `#[Config]`, `#[Log]`, `#[Cache]` and `#[Tag]` attributes and `ServiceProvider::optimizes()`. `#[Singleton]`, `#[Scoped]`, `#[Bind]` and `#[Give]` came in later 12.x minors.

## Dependency verdicts
- **spatie/laravel-prometheus: replace.** Use promphp `MetricFamilySamples` plus `RenderTextFormat` and the package's own endpoint. Keep an optional bridge through spatie's `actions.render_collectors`.
- **spatie/laravel-data: drop.** One DTO, no-op mapper, heavy dependency tree.
- **spatie/laravel-package-tools: adopt.** php ^8.1, illuminate 10–13, already a transitive dependency of spatie/laravel-prometheus. It gives `hasConfigFile`, `hasViews`, `hasRoute`, `hasCommands` and `hasInstallCommand`. Custom wiring stays in `packageRegistered`/`packageBooted`.

## Target tree (`src/`, namespace `DF\HorizonObservability`)
- `HorizonObservabilityServiceProvider` — PackageServiceProvider; wiring only.
- `HorizonObservability` — static hooks: `resolveBatchKindUsing()`, `discoverJobClassesUsing()`, `collector()`, `flushState()`.
- **Config\\**
  - `ObservabilityConfig` — readonly, typed and validated snapshot of the config file; singleton.
- **Contracts\\**
  - `Collector` — `collect(ScrapeContext): iterable<MetricFamily>`.
  - `BatchKindResolver` — `kind(?string $name): string`.
  - `JobClassDiscoverer` — `discover(): list<class-string>`.
  - `AttemptStore` — record and read attempt metrics.
- **Metrics\\**
  - `MetricType` — enum counter/gauge/histogram.
  - `Sample` — readonly value plus labels.
  - `MetricFamily` — readonly name, type, help, labelNames, samples.
  - `HistogramSnapshot` — non-cumulative storage to cumulative buckets/sum/count.
  - `ScrapeContext` — per-scrape memo, now, reporter.
  - `CollectorRegistry` — enabled collector classes from config plus hooks.
  - `Exporter` — runs collectors with per-collector isolation and timing; emits `collector_success` and `collector_duration_seconds`.
  - `TextRenderer` — MetricFamily list to exposition text via promphp.
- **Collectors\\**
  - `HorizonStatusCollector` — `horizon_status`, guarded.
  - `HorizonSupervisorsCollector` — `horizon_supervisor_*` and `horizon_current_processes` from one SupervisorSnapshot list.
  - `HorizonWaitThresholdsCollector` — `horizon_wait_threshold_seconds`.
  - `HorizonInfoCollector` — `horizon_info{url,tts_url}`.
  - `QueueStateCollector` — pending/delayed/due/oldest/per-class gauges.
  - `JobAttemptsCollector` — `queue_jobs_total`, real histograms, min/max.
  - `JobClassesCollector` — `queue_job_classes`.
  - `BatchesCollector` — `queue_batch_*`.
- **Horizon\\** (the only code that knows Horizon internals)
  - `HorizonTopology` — queues by connection for the current environment; was HorizonQueues; memoized.
  - `SupervisorSnapshot` and `SupervisorSnapshotReader` — normalized supervisors from SupervisorRepository.
  - `HorizonUrl` — Horizon base URL with config override.
  - `HorizonJobStore` — pending/completed/failed lists from Horizon Redis; was HorizonJobsRepository.
- **Queue\\**
  - `QueueRef` — readonly connection plus queue.
  - `QueueSnapshot` — readonly; was QueueState.
  - `RedisQueueInspector` — one Lua call per queue; was RedisQueueStateReader.
  - `DelayedJobStore` — not-yet-due delayed jobs.
  - `PayloadInspector` — shared payload field extraction.
- **Attempts\\**
  - `AttemptStatus` — enum.
  - `Attempt` — readonly record of one attempt.
  - `AttemptOutcome` — event to status mapping, Laravel 12/13 shim.
  - `AttemptTracker` — event subscriber, singleton, `flush()`.
  - `RedisAttemptStore` — one Lua call per attempt.
  - `CounterResetGuard` — Redis run_id check.
- **Batches\\**
  - `BatchStatus` — enum.
  - `RegexBatchKindResolver` — default resolver.
  - `BatchSnapshot` and `BatchKindSummary` — readonly.
  - `BatchStatsReader` — bounded, driver-aware.
  - `BatchJobClassIndex` — ZSET of kind↔queue↔class links with TTL.
- **Discovery\\**
  - `FilesystemJobClassDiscoverer` — paths plus PSR-4 map.
  - `JobClassCache` — `bootstrap/cache` file.
- **Redis\\**
  - `Keys` — prefix, hash tag, schema version.
  - `LuaScript` — EVALSHA with NOSCRIPT fallback; loads `resources/lua/*.lua`.
- **Http\\**
  - `MetricsController` — exposition.
  - `JobListController` — invokable, `{list}` bound to enum.
  - `JobListType` — enum.
  - `JobListRequest` — Grafana queryparam parsing.
  - `JobListQuery` — readonly filters.
  - `JobListService` — supervisor/batch-kind to queues/classes.
  - `ExceptionSummary` — short exception text.
  - `Data\\{PendingJob,CompletedJob,FailedJob,DelayedJob}` — JsonSerializable rows.
  - `Middleware\\{ForceJson, AuthorizeScrape, InjectGrafanaMenuLink}`.
- **Integrations\\Spatie\\**
  - `RenderCollectorsAction` — opt-in bridge.
- **Console\\**
  - `InstallCommand`, `CacheJobClassesCommand`, `ClearJobClassesCommand`.
  - `CheckCommand` — reports config, batching driver, Redis client, Horizon version, `prometheus.cache` in bridge mode.
- **Support\\**
  - `FailureReporter` — throttled, channel-aware logging.
- **workbench/** (autoload-dev): `SimulateLoadCommand`, `SimulatedJob`, `Outcome` enum, `TaskProfile`, Horizon/queue config for Docker Prometheus/Grafana.

## Proposed `config/horizon-observability.php` (env prefix `HORIZON_OBSERVABILITY_`)
```php
'enabled' => true,
'namespace' => 'laravel',
'log_channel' => null,
'http' => [
    'enabled' => true,
    'prefix' => 'metrics/horizon',
    'middleware' => [AuthorizeScrape::class],
    'token' => null,
    'allowed_ips' => [],
],
'collectors' => [/* class => bool, all true */],
'spatie_bridge' => false,
'storage' => [
    'redis_connection' => 'default',
    'prefix' => '{horizon-observability}:v1:',
],
'attempts' => [
    'enabled' => true,
    'connections' => null, // null = connections from Horizon supervisors
    'buckets' => [0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10, 30, 60, 120, 300, 600, 1800, 3600],
    'extremes_window' => 60,
],
'queues' => [
    'pending_scan_limit' => 1000,
    'reserved_scan_limit' => 1000,
],
'job_classes' => [
    'paths' => [app_path()],
    'extra' => [],
],
'batches' => [
    'closed_window' => 86400,
    'recent_per_kind' => 200,
    'running_max_age' => 604800,
    'job_classes_ttl' => 604800,
    'job_classes_refresh' => 3600,
    'label_timezone' => 'UTC',
],
'job_lists' => [
    'max_limit' => 1000,
    'scan_limit' => 10000,
    'chunk' => 500,
    'exception_max_bytes' => 1000,
],
'dashboard' => [
    'horizon_url' => null, // null = derived
    'tts_url' => null,
],
'horizon_menu' => [
    'grafana_url' => null,
    'label' => 'Grafana',
],
```

## Service provider responsibilities
**`register()` / `packageRegistered`:**
- Merge config.
- Singletons: `ObservabilityConfig`, `AttemptTracker`, `CollectorRegistry`, `HorizonTopology`.
- Bind contracts: `AttemptStore` → `RedisAttemptStore`; `BatchKindResolver` (default or closure hook); `JobClassDiscoverer` (cached filesystem discoverer).
- Do not touch other packages' config.

**`boot()` / `packageBooted`:**
- Publish config and the Grafana resources.
- `loadViewsFrom` with namespace `horizon-observability`.
- When `http.enabled`, load `routes/http.php` (route-cache aware, named routes): the metrics endpoint at the prefix root plus `{list}-jobs`.
- When `attempts.enabled`, `Event::subscribe(AttemptTracker::class)`.
- When `grafana_url` is set and Horizon is present: `$this->app->booted(fn () => Route::pushMiddlewareToGroup('horizon', InjectGrafanaMenuLink::class))`.
- Register commands and `optimizes(optimize: 'horizon-observability:cache', clear: 'horizon-observability:clear')`.
- `AboutCommand::add('Horizon Observability', ...)`.
- If `spatie_bridge` is on and spatie is installed, register the bridge.
- No collector resolution at boot.