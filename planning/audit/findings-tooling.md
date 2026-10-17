# Tooling and CI findings

Audit of the legacy pilot (now in `legacy/`), 2026-10-16. File:line references point to the legacy layout (`src/...` = `legacy/src/...`).

## Summary

I audited the dev, test and release infrastructure without modifying the repository. To check the proposal I resolved the dependencies with composer, installed the current code into a throwaway workbench and ran it on PHP 8.3.6, Laravel 12.70 and Horizon 5.50.1, with a real redis-server and a real Horizon master started via `vendor/bin/testbench horizon`. Prometheus 3.15.0 and Grafana 13.2.4 ran in containers, and Playwright took screenshots of the dashboard.

The proposed stack works end to end. /metrics returns 200, the JSON job lists respond, Prometheus scrapes the workbench, and Grafana provisions the "Queues" dashboard, the 4 alert rules and both plugins. The Infinity tables are filled from the workbench.

**Main blocker: PHP 8.3 + Laravel 13 cannot be installed.** spatie/laravel-prometheus 1.5 and later (the only versions that support Laravel 13) require PHP ^8.4. On PHP 8.3 composer can pick at most 1.4.0, which supports Laravel 11/12 only. The fix is either to exclude that combination or to drop the hard dependency.

**Problems the new harness found right away:**
- With the predis client the package records no attempt metrics at all.
- /metrics returns 500 when the job_batches table is missing.
- On Laravel 12, Pest 4 cannot test below Laravel 12.25.
- Larastan error counts on the current src/: 22 at level 5, 68 at level 8, 210 at level 10.

The full proposal is in notes: exact current versions, composer.json, testbench.yaml and workbench layout, docker-compose with provisioning, how to take screenshots, test cases per class, the CI matrix and the repo hygiene files.


## F1 [high] PHP 8.3 + Laravel 13 is unresolvable while spatie/laravel-prometheus is a hard dependency

- **Location:** composer.json:8-12
- **Detail:** spatie/laravel-prometheus 1.5.0-1.7.1 (the only versions allowing illuminate ^13) require php ^8.4; on PHP 8.3 the newest installable is 1.4.0 (illuminate ^11|^12). Verified with composer dry-run: PHP 8.3 + illuminate ^13 -> 'Your requirements could not be resolved' (Problem 1 names spatie/laravel-prometheus). PHP 8.3+L12 resolves to spatie 1.4.0, PHP 8.4/8.5 (platform override) + L12/L13 resolve to 1.7.1. The API used by the package (Prometheus::addGauge/addCounter, ->value()/->inc() with closures, HorizonStatusCollector, AllowIps) is unchanged 1.3.0..1.7.1 (git diff), and 1.7.1 sources lint clean on PHP 8.3, so the 8.4 floor is upstream policy, not syntax.
- **Recommendation:** Decide before writing CI: either (a) keep spatie and document 'Laravel 13 requires PHP 8.4+', with the matrix excluding php 8.3 x L13; or (b) make spatie optional and render the registry with promphp/prometheus_client_php ^2.14 (PHP ^8.2) directly via the package's own /metrics route or a collector bridge. Option (b) also removes the 'prometheus.cache must be null' trap and AllowIps coupling. Whichever is chosen, constrain to '^1.3' (prefer-lowest picks 1.3.0) and test lowest+highest.

## F2 [high] predis client: attempt recording always fails (no queue_jobs_total, histograms, min/max)

- **Location:** src/QueueJobMetricsStorage.php:120-126
- **Detail:** Inside redis()->pipeline(fn ($pipe) ...) the raw client is used, and eval is called with the phpredis signature eval(script, array args, numKeys). With REDIS_CLIENT=predis this throws 'Unsupported operand types: array + int'. The exception is swallowed in QueueJobMetricsSubscriber::finish() (only a log warning), so the whole recordAttempt pipeline is lost. Verified: the same Pest smoke test passes with phpredis and fails with predis; laravel.log shows 'не удалось записать метрики джоба ... Unsupported operand types'. Other code explicitly claims predis support (QueueJobMetricsStorage.php:437 comment, HorizonJobsRepository.php:443).
- **Recommendation:** Run the Redis-backed test suite under both clients (CI matrix leg REDIS_CLIENT=predis plus a local composer script). Move all Redis access behind one small gateway class that normalises eval/pipeline/hmget/info shapes, so the client difference is isolated and tested once. Require predis/predis ^2.0|^3.0 in require-dev and list ext-redis/predis in suggest.

## F3 [high] /metrics returns 500 when the batches table or DB is unavailable, contradicting the never-break-/metrics guarantee

- **Location:** src/Prometheus/QueueBatchesCollector.php:178, src/Prometheus/QueueBatchesCollector.php:198, src/Prometheus/ScrapeCache.php:42
- **Detail:** ScrapeCache::remember() returns [] when the source throws, but perKind()/perBatch() index stats()['kinds'] and stats()['batches'] without a fallback, so ErrorException 'Undefined array key "kinds"' bubbles up and /metrics answers 500. Verified in testbench: without a job_batches table (an app that never ran make:queue-batches-table), the first GET /metrics failed. The service provider docblock (HorizonPrometheusGrafanaServiceProvider.php:30-32) promises the opposite. HorizonSupervisorsCollector guards the same pattern with '?? []' (lines 102/118/132).
- **Recommendation:** Add feature tests per collector for 'source throws -> 200, series absent, exactly one warning logged' (missing table, Redis down via a bogus connection, Horizon repository throwing). Make ScrapeCache typed per source (return a default value object instead of []).

## F4 [medium] Test-isolation hazards baked into the runtime design

- **Location:** src/HorizonPrometheusGrafanaServiceProvider.php:44, src/QueueJobMetricsStorage.php:203-218, src/QueuedJobClasses.php:34, src/QueueJobMetricsSubscriber.php:54-61
- **Detail:** (1) ScrapeCache is a scoped singleton. In HTTP tests, and in tests that run queue:work in-process and then scrape, it survives between requests, so the second scrape returns stale data. Verified: jobs_total was missing until forgetScopedInstances() was called. (2) The first scrape on a fresh Redis wipes the counters (no run_id stored yet). Attempts recorded before that first scrape disappear (verified, log 'сменился run_id Redis'). (3) Static memos (QueuedJobClasses::$classes, subscriber $batchKinds/$batchJobClassesWrittenAt) leak between tests. (4) time() is called directly (QueueJobMetricsStorage.php:119,143,156,166,270; QueueBatchesCollector.php:57,229; QueueStateCollector.php:123; DelayedJobsRepository.php:68; subscriber 277/282), so Carbon travelTo() cannot freeze time.
- **Recommendation:** Test harness: a scrape() helper that calls app()->forgetScopedInstances() and parses the exposition text. A 'primeRunId()' step (or first scrape) in setUp. flushdb on the default and horizon connections in setUp. Refactor: replace time() with now()->getTimestamp() or an injected Psr ClockInterface, and add reset hooks for static memos (or move them to scoped/Octane-aware services).

## F5 [medium] Job class discovery is hard-wired to app_path(), so the workbench can never export queue_job_classes

- **Location:** src/QueuedJobClasses.php:43
- **Detail:** Under testbench app_path() is vendor/orchestra/testbench-core/laravel/app and app()->getNamespace() is the skeleton namespace. Verified: a full workbench scrape exported every dashboard metric family except office_queue_job_classes (and batch_oldest_age_seconds only while no batch was running). As a result the dashboard's 'never executed' job rows and the Job filter cannot be exercised in dev or tests. This is the same coupling as docs/extraction.md item 3.
- **Recommendation:** Make discovery paths configurable (default app_path(); the workbench sets workbench_path('app')). Resolve class names from composer PSR-4 maps instead of app()->getNamespace(). Add a contract test that every metric name referenced in queues.json and horizon.yml appears in a seeded full scrape.

## F6 [medium] Dev/test stack must override spatie defaults and dashboard-hardcoded paths; generator and alerts still leak 'office'

- **Location:** resources/grafana/dashboards/queues.json:3310,3508,3684,3876; config/horizon-prometheus-grafana.php:39; resources/grafana/generate_queues_dashboard.py:1815; resources/grafana/alerting/horizon.yml:26,57,91-92,125-126
- **Detail:** spatie defaults are urls.default='prometheus' and default_namespace='app', while the committed dashboard expects office_ metric names. Infinity panels call absolute '/metrics/horizon/*-jobs' URLs that ignore the configurable routes.prefix. The workbench only matched the dashboard after setting prometheus.urls.default=metrics and default_namespace=office. Running the generator with --prefix acme still leaves one 'office' (the dashboard tag, verified), and the alert rules hardcode office_ with no generator. The generator is otherwise deterministic: its output is byte-identical to the committed queues.json (verified).
- **Recommendation:** Workbench config must set the prometheus url and namespace explicitly. Add CI checks: (1) the generator output equals the committed JSON; (2) the --prefix output contains no 'office'; (3) the Infinity URL prefix equals the config default routes.prefix; (4) alert __dashboardUid__ and __panelId__ exist in the dashboard. Ship a PHP command (or vendor:publish plus rewrite) that applies the prefix to both the dashboard and the alerts, so users do not need Python.

## F7 [medium] Real Laravel 12 floor is set by test tooling, not by composer.json

- **Location:** composer.json:9
- **Detail:** prefer-lowest on PHP 8.3 (testbench ^10.0, pest ^4.0, pest-plugin-laravel ^4.0) resolves laravel/framework 12.25.0, because pest-plugin-laravel 4.0.0 requires ^12.25. testbench 10.12 (latest) requires ^12.69.2, testbench 11.x requires ^13.0. With ^12.0 declared, 12.0-12.24 would be claimed but never tested. Note: illuminate/* split packages are not enough (FormRequest, Foundation Queueable, Mailable are used), so laravel/framework must stay the dependency.
- **Recommendation:** Declare 'laravel/framework': '^12.25|^13.0' (or accept and document the untested range). Use 'orchestra/testbench': '^10.2|^11.0' and 'pestphp/pest(-plugin-laravel)': '^4.0' so prefer-lowest really hits 12.25. Pin Pest to ^4 because Pest 5 requires PHP 8.4.

## F8 [medium] Minimum Grafana version is undocumented; Grafana 13 changes anonymous access

- **Location:** README.md:94, docs/backlog.md:102
- **Detail:** Business Text 6.3.0 (the pinned version that renders the alert-sound panel) requires Grafana >=12.3.0. Infinity 4.1.1 requires >=12.2.5 (or the 11.6.11/12.0.10/12.1.7 patch lines). Grafana 13.2.4 logs 'auth.anonymous.org_role is deprecated, only viewer role is supported', so anonymous access is viewer-only (enough for screenshots, but API calls need admin basic auth). Grafana also logs level=error 'Failed to read plugin provisioning files ... provisioning/plugins' when that dir is absent, which would trip a naive 'no provisioning errors' CI grep.
- **Recommendation:** Document Grafana >=12.3 (tested 13.2.x), Prometheus >=2.33 (tested 3.15), Infinity 4.1.1 and Business Text 6.3.0. Pin image tags in docker-compose (grafana/grafana:13.2.4, quay.io/prometheus/prometheus:v3.15.0). Ship docker/grafana/provisioning/plugins/.gitkeep.

## F9 [low] Collection JSON responses are not encoded as documented (slashes and Cyrillic escaped)

- **Location:** src/Http/Resources/JsonResource.php:17-22
- **Detail:** withResponse() only applies to single resources; Controller returns ::collection() (AnonymousResourceCollection), so output contains 'jobs\/pending\/...' and 'Те...' (verified via curl). Grafana parses either form, so this is cosmetic, but the class docblock claims otherwise.
- **Recommendation:** Add a feature test asserting raw response body (unescaped slashes/unicode) and fix with a custom ResourceCollection or response macro.

## F10 [low] spatie/laravel-data is a heavy dependency for one DTO and needs explicit provider registration in tests

- **Location:** src/DTO/GetHorizonJobsDTO.php:14-15, composer.json:11
- **Detail:** In a TestCase without LaravelDataServiceProvider, GET /metrics/horizon/* returned 500 'Trying to access array offset on null' (laravel-data CreationContextFactory.php:48), verified. Laravel 12 support starts at laravel-data 4.14.0 and Laravel 13 at 4.20.0, which adds another constraint to the matrix.
- **Recommendation:** Replace with a readonly DTO built from FormRequest::validated(); otherwise require '^4.20' (or ^4.14 for L12) and register the provider in TestCase::getPackageProviders.

## F11 [low] Static analysis baseline on current src

- **Location:** src/QueueJobMetricsStorage.php:106-215, src/RedisQueueStateReader.php:158-161, src/Repositories/HorizonJobsRepository.php:156-202
- **Detail:** Larastan 3.13 / PHPStan 2.3.1 error counts on src: level 5: 22, 6: 30, 7: 63, 8: 68, 9: 161, 10: 210. Most low-level errors come from Laravel Redis magic calls being resolved against phpredis stubs (pipeline/eval/set signatures, 'object $pipe'), untyped stdClass rows (QueueBatchesCollector), and Collection generics.
- **Recommendation:** After the refactor target level 8 for src and tests (typed Redis gateway, typed row DTOs). Add phpstan-deprecation-rules and avoid a baseline for new code. Levels 9/10 only once config is read through typed value objects instead of config() mixed.

## F12 [low] Repo lacks every public-package hygiene artifact

- **Location:** composer.json:5-6, .gitignore:1-2
- **Detail:** "version": "1.0.0" conflicts with tag-based versions on Packagist. The license is 'proprietary'. .gitignore only lists .idea. There is no LICENSE, CHANGELOG, CONTRIBUTING, SECURITY, CODE_OF_CONDUCT, .gitattributes (so tests, workbench, docker and the generator would ship in dist), .editorconfig, .github/ (CI, issue templates, dependabot), phpunit/phpstan/pint/rector config, or tests. Docs are in Russian and contain office-specific internals (docs/extraction.md, docs/cleanup.md, docs/research.md).
- **Recommendation:** See notes section I for exact file set and contents; verify dist content in CI with git archive.

## F13 [low] Workbench/dev-loop footguns observed

- **Location:** src/Console/QueueLoadTestCommand.php:148, src/Console/QueueLoadTestCommand.php:290
- **Detail:** (1) The standard testbench script post-autoload-dump -> package:purge-skeleton deletes the skeleton sqlite DB under a running 'testbench serve'. After that the batch collector logs 'Database file ... does not exist' and the load generator died with 'attempt to write a readonly database' (verified). (2) The continuous load loop has no try/catch, so one failing dispatch kills the generator. (3) The production guard relies only on isProduction(). (4) Horizon children do not pick up code changes until horizon:terminate.
- **Recommendation:** Move the load generator plus its job into workbench/ (dev-only, export-ignored), with try/catch per tick. Document 'composer build' after dump-autoload and 'testbench horizon:terminate' after code changes (or use horizon:listen).
