## 1. Contract table (Grafana resources ↔ PHP)

Prefix `P` = `prometheus.default_namespace`, slugified by spatie (Gauge.php:33). Empty value means no prefix (promphp Collector.php:45). Today `P` = `office_`. Panel ids come from queues.json: #1 Horizon tile, #3 Supervisor Processes, #2 Pending, #8 Completed, #9 Delayed, #4 Failed, #21 Alerts, #23 alert-sound (Business Text), #5 Queues per supervisor (repeat on supervisor_title), #17 Batches, #7 Pending by job (repeat on queue), #14 Completed by job, #6 Jobs, #19 Failed Jobs, #18 Completed Jobs, #20 Pending Jobs, #22 Delayed Jobs, #16 batch list (repeat on batch_title).

### Prometheus metrics
| Metric | Labels (values) | Producer | Consumed by | Notes |
|---|---|---|---|---|
| P horizon_status | none; values 1 running, 0 paused, -1 no master (dashboard adds -2 = no metrics, gen:318,814) | spatie HorizonStatusCollector, registered in ServiceProvider.php:73 | #1,#2,#3,#4,#5,#8,#14; alerts horizon-no-metrics, horizon-down | spatie's collector has no error guard |
| P horizon_current_processes | queue | Prometheus/HorizonWorkloadCollector.php:21-25 | #3, #5 (D) | same name as spatie's CurrentProcessesPerQueueCollector; no connection; 'a,b,c' when balance=false |
| P horizon_supervisor_processes | supervisor, queue | HorizonSupervisorsCollector.php:32-36 | #3,#5,#7; vars supervisor, queue, supervisor_title | |
| P horizon_supervisor_info | supervisor, balance (auto, simple, off), connection | HorizonSupervisorsCollector.php:38-42,127-131 | var supervisor_title (gen:1742-1744) | |
| P horizon_supervisor_min_processes | supervisor | HorizonSupervisorsCollector.php:26,44-50 | var supervisor_title | |
| P horizon_supervisor_max_processes | supervisor | HorizonSupervisorsCollector.php:27,44-50 | #3,#5,#7 (E); supervisor_title | |
| P horizon_wait_threshold_seconds | connection, queue | HorizonWaitsCollector.php:22-26 (horizon.waits, default 60, 0 = not emitted) | #2 (C), #5 (F,K); alert horizon-long-wait | connection unused |
| P horizon_info | url, tts_url | HorizonInfoCollector.php:24-28 | vars horizon_url, tts_url (gen:1923,1940) feeding the dashboard link, Infinity job links, #16 batch links, #23 TTS | |
| P queue_pending_jobs | connection, queue | QueueStateCollector.php:27-33 | #2,#5,#7; var queue; QUEUES helper (gen:24) | collides with spatie QueuePendingJobsCollector |
| P queue_jobs_pending | connection, queue, job_class (plus "(не просмотрено)", "(неизвестно)") | QueueStateCollector.php:35-39; RedisQueueStateReader.php:26-27 | #2,#5,#6,#7; var job_class | non-zero only |
| P queue_jobs_reserved | connection, queue, job_class | QueueStateCollector.php:41-45 | #3,#5,#6,#17; var job_class | |
| P queue_delayed_jobs | connection, queue | QueueStateCollector.php:47-52 | #5 (G), #9 | collides with spatie |
| P queue_delayed_due_jobs | connection, queue | QueueStateCollector.php:54-58 | #5 (G), #9 | |
| P queue_oldest_pending_job_age | connection, queue | QueueStateCollector.php:60-69 | #2,#5; alert horizon-long-wait | collides with spatie |
| P queue_job_classes | job_class | QueueJobClassesCollector.php:19-26 (app_path scan) | #6; var job_class | |
| P queue_jobs_total | connection, queue, job_class, status (completed, released, retried, failed, timed_out) | QueueJobsCollector.php:31-35, only when `enabled` (ServiceProvider:84-86); zero-filled in QueueJobMetricsStorage.php:228-247 | #4,#5,#6,#8,#14,#17; var job_class; alert horizon-failures | `released` never consumed |
| P queue_job_duration_seconds_{bucket,sum,count} | connection, queue, job_class [, le] | QueueJobsCollector.php:49-54,72-93 | #6 (F, G) | |
| P queue_job_duration_{min,max}_seconds | connection, queue, job_class | QueueJobsCollector.php:37-47 | #6 (I, J, G) | |
| P queue_job_wait_seconds_{bucket,sum,count} | connection, queue [, le] | QueueJobsCollector.php:56-61 | #5 (K), _bucket only | _sum and _count unused |
| P queue_batches_running | batch | QueueBatchesCollector.php:36-40 | #17 (A) | |
| P queue_batch_jobs | batch, state (total, pending, failed) | QueueBatchesCollector.php:42-50 | #17 (B, C) | |
| P queue_batch_oldest_age_seconds | batch | QueueBatchesCollector.php:52-58 | #17 (F) | |
| P queue_batch_job_counts | batch, name, state | QueueBatchesCollector.php:60-69 | #16 (B, C, D) | |
| P queue_batch_created_timestamp_seconds | batch, name | QueueBatchesCollector.php:71-75 | #16, #17; vars batch_kind, batch_title | |
| P queue_batch_closed_timestamp_seconds | batch, name | QueueBatchesCollector.php:77-83 | #16, #17; vars batch_kind, batch_title | |
| P queue_batch_job_classes | batch, queue, job_class | QueueBatchesCollector.php:85-90 (links from QueueJobMetricsSubscriber.php:258-290) | #17; vars queue, job_class (gen:41) | |
| P queue_batch_info | batch, queue, connection (comma-joined) | QueueBatchesCollector.php:92-96 | var batch_title | |
| P queue_batch_progress | batch, name, status (Running, Finished, Failures, Cancelled), size, created, id | QueueBatchesCollector.php:98-105,275-300 | #16 (A: status, id; link `${horizon_url}/batches/<id>`) | size, created never consumed |
| GRAFANA_ALERTS | alertname, alertstate (firing, pending), grafana_rule_uid=~"horizon-.*" | Grafana state history (remote write), not PHP | #21, #23 | name is configurable in Grafana (prometheus_metric_name) |

Consumed but not produced by PHP: only GRAFANA_ALERTS.
Produced but not consumed: queue_job_wait_seconds_sum/_count; status="released"; queue_batch_progress{size, created}; the connection label of queue metrics as a grouping key.

### JSON endpoints (Infinity, backend parser, JSONata root_selector)
| Endpoint (dashboard path, hardcoded) | Request | Response `{"data":[…]}` (JsonResource.php:13-17) | Producer | Consumed fields |
|---|---|---|---|---|
| /metrics/horizon/failed-jobs (gen:1444) | limit=500 (validated 1..1000), from and to in ms (${__from}/${__to}), var-supervisor, var-batch_kind, var-queue, var-job_class (repeated; `$__all` = no filter) — GetHorizonJobsRequest.php:17-78 | id, name, queue, attempts, failed_at (ms), runtime (s, nullable), exception (nullable), horizon_path=`failed/<id>` | HorizonJobsController.php:33; HorizonJobsService.php:50-54,108-125; HorizonJobsRepository.php:100-119; HorizonFailedJobResource.php | name (as short class), queue, exception, attempts, runtime, failed_at, horizon_path |
| /metrics/horizon/completed-jobs (gen:1457) | same | id, name, queue, queued_at, completed_at, runtime, horizon_path=`jobs/completed/<id>` | HorizonCompletedJobResource.php | queued_at, completed_at, runtime, name, queue, horizon_path |
| /metrics/horizon/pending-jobs (gen:1465) | same | id, name, queue, queued_at, horizon_path=`jobs/pending/<id>` | HorizonPendingJobResource.php | queued_at, name, queue, horizon_path |
| /metrics/horizon/delayed-jobs (gen:1473; from/to ignored) | same | id, name, queue, attempts, queued_at (nullable), available_at; no horizon_path | DelayedJobsRepository.php; HorizonDelayedJobResource.php | attempts, queued_at, available_at, name, queue (own root_selector, gen:1483) |
PHP route: config routes.prefix (config:66) and middleware AllowIps + ForceJsonResponse (ServiceProvider:94-101). `id` is never consumed.

### Variables, datasources, plugins, URLs
- Visible query variables (Prometheus `${datasource}`): supervisor (allValue .*), batch_kind (allValue .*), queue (All = list), job_class (All = list; text = short class via regex named groups).
- Hidden: supervisor_title and batch_title (repeat drivers, text = table title); horizon_url and tts_url (refresh on load, skipUrlSync, [7d] lookback); datasource (type datasource, query prometheus); app_api (type datasource, query yesoreyeram-infinity-datasource).
- Built-ins used: ${__from}, ${__to}, ${__from:date:seconds}, ${__to:date:seconds}, $__range, $__interval, $__interval_ms, ${__dashboard.uid}, ${var:text}, ${var:queryparam}.
- Datasource refs: {"type":"prometheus","uid":"${datasource}"} on every Prometheus panel, target and variable; {"type":"yesoreyeram-infinity-datasource","uid":"${app_api}"} on #18-#20 and #22; {"type":"grafana","uid":"-- Grafana --"} for annotations. Alert rules use datasourceUid ${HPG_PROMETHEUS_DATASOURCE_UID} (Grafana env) and __expr__.
- Plugins: Infinity (README pins 4.1.1), Business Text marcusolsson-dynamictext-panel 6.3.0 (#23, afterRender JS). Core panels: stat, table, timeseries, row. No `__requires` block.
- External URLs:
  - ${horizon_url} (toolbar link), `${horizon_url}/<horizon_path>` (job links, gen:1388), `${horizon_url}/batches/<id>` (gen:1236).
  - `${tts_url}/synthesize`: browser POST of JSON {"text"}, expects WAV; matches Piper http_server; CORS required (gen:1651).
  - Reset link `/d/${__dashboard.uid}?var-…=%24__all` (gen:1802).
  - Horizon menu link HPG_GRAFANA_URL (config:74, AddGrafanaLinkToHorizon.php:27), which implicitly couples to uid `horizon`.
- Dashboard identity: uid horizon, title Queues, tags [horizon, queue, office], schemaVersion 42, refresh 15s.
- Alert rules: orgId 1, folder Horizon, group Queues, interval 1m; uids horizon-no-metrics, horizon-down, horizon-long-wait, horizon-failures; __dashboardUid__ horizon; __panelId__ 1, 2, 4; rule titles are what the voice says.

## 2. Generator CLI and diff
- CLI: `generate_queues_dashboard.py [output] [--prefix office]`. The default output is `dashboards/queues.json` next to the script, which overwrites the committed file; there is no check mode.
- Ran with `python3 -I` into /tmp/claude-0/-home-user-horizon-observability/cc6491ff-4b68-5ae7-bac7-d93fef57187c/scratchpad/audit/queues.office.json: byte-identical to the committed queues.json (244,422 bytes, 4,615 lines). Same result with python3.12 (/tmp/claude-0/-home-user-horizon-observability/cc6491ff-4b68-5ae7-bac7-d93fef57187c/scratchpad/audit/queues.py312.json). python3.11 fails with a SyntaxError at line 1032.
- `--prefix acme` (/tmp/claude-0/-home-user-horizon-observability/cc6491ff-4b68-5ae7-bac7-d93fef57187c/scratchpad/audit/queues.acme.json): 73 lines differ, all office_(queue|horizon)_ → acme_. The `office` tag, uid, GRAFANA_ALERTS and `horizon-.*` stay.
- `--prefix ''` gives `_queue_…` (/tmp/claude-0/-home-user-horizon-observability/cc6491ff-4b68-5ae7-bac7-d93fef57187c/scratchpad/audit/queues.empty.json); `My-App` gives invalid PromQL (/tmp/claude-0/-home-user-horizon-observability/cc6491ff-4b68-5ae7-bac7-d93fef57187c/scratchpad/audit/queues.bad.json); `office_` gives `office__` (/tmp/claude-0/-home-user-horizon-observability/cc6491ff-4b68-5ae7-bac7-d93fef57187c/scratchpad/audit/queues.office2.json).
- Size: 65 targets with about 100.8 KB of PromQL. The largest single expression is 7.2 KB (Failed tile A). office_queue_jobs_total appears 320 times because attempts_delta/attempts_per_step are expanded inline.
- The repository was not modified (git status clean).

## 3. How the project-specific parts are wired today, and what to change
- **Prefix**: literal office_ in the Python code, rewritten by regex on the output (gen:1996-1997); the alert YAML is edited by hand. Change: one normalized prefix (spatie slug rules) used by a metric() builder, plus a hidden `metric_prefix` variable in the JSON. Ideally the package also gets its own metric namespace (F1).
- **Prometheus datasource**: a hidden `datasource` variable picked by type. Change: keep it hidden, allow URL override, and let the renderer set the default.
- **Infinity "App API"**: office provisions it (`.docker/grafana/provisioning`, url http://app:8080, docs/extraction.md:411); the dashboard picks it by type through `app_api`. Change: same as datasource, plus ship a provisioning template with url and allowedHosts, and an `api_path` variable fed from routes.prefix.
- **horizon_url / tts_url**: queried from P horizon_info{url,tts_url}, which the app builds from app.url, horizon.domain, horizon.path and the tts_url config. horizon_url can stay. Consider making tts_url a dashboard variable.
- **Alert sound and speech**: hidden Business Text panel #23 queries `last_over_time(GRAFANA_ALERTS{grafana_rule_uid=~"horizon-.*",alertstate="firing"}[90s])`, plays an IEC 60601-1-8 style tone, then POSTs to `${tts_url}/synthesize`. It needs Grafana state history writing to Prometheus (`--web.enable-remote-write-receiver`). Change: make the rule uid prefix, the alerts metric name and the window variables or renderer options, and move the JS to its own file.
- **Alert rules**: hand-written YAML; the only env dependency is ${HPG_PROMETHEUS_DATASOURCE_UID} (Grafana env, interpolated at provisioning; annotations are not interpolated, labels are). Change: render them with the dashboard from shared constants (prefix, uids, folder, orgId, interval, panel ids, thresholds) via `php artisan horizon-observability:grafana --datasource-uid=… --uid=… --folder=…`. Keep a provisioning template that uses a renamed env var.
- **Optional `job` scoping variable** (F5). Also make BUSY_WINDOW (15s, gen:109) a hidden variable so scrape intervals other than 15 s work without regenerating.

Grafana interpolates `${var}` in panel queries, variable queries and Infinity URLs; the brace form is required (`${metric_prefix}queue_…`). Existing `$1` in label_replace stays as it is. So the committed JSON can work everywhere, while the renderer only bakes in defaults. Alert rules cannot use dashboard variables and must be rendered.

## 4. Generator: Python or PHP?
Recommendation: port it to PHP inside the package, e.g. src/Grafana/{Dashboard, Panels/*, PromQL/Metric, Layout, AlertRules} plus an artisan command that writes dashboards/queues.json and alerting/rules.yml, has `--check`, and reads defaults from config.

Why:
- One toolchain for a Laravel package: no Python 3.12 requirement in CI or Docker.
- Defaults come straight from the app config (namespace, routes.prefix, thresholds), so the prefix is never entered by hand.
- One source of truth for the dashboard and the alert rules.
- It can be tested with PHPUnit/Pest in testbench.

How to keep the look and behaviour unchanged during the port:
1. Freeze the current queues.json as a golden fixture.
2. Assert that the PHP output for (prefix office_, uid horizon) json_decodes to an equal array. Compare semantically, not byte for byte: PHP pretty-print uses 4 spaces and escapes slashes unless JSON_UNESCAPED_SLASHES is set. Key order is preserved on both sides.
3. Only then delete the Python script.
4. After that, intentional changes (variables instead of literals, new uid default) update the golden file in reviewed commits.

Add a contract test: in testbench with Redis, seed queues, jobs and batches, scrape the metrics route, extract every metric name and label referenced by the dashboard and the rules from the rendered JSON/YAML, and assert each exists in the exposition. Likewise check the Infinity paths against registered routes and the JSON fields against the resources.

Ship `__requires`, provisioning templates (datasources, dashboards provider, alerting, a grafana.ini state-history snippet) and a workbench docker-compose (Prometheus with remote-write receiver, Grafana with plugins via GF_PLUGINS_PREINSTALL_SYNC, Piper behind a CORS proxy) for the screenshots and metric checks the user wants.

Optional optimizations that keep the look: reuse query results between panels with the Grafana `-- Dashboard --` datasource (the Completed tile and Completed by job compute the same attempts_per_step); offer optional Prometheus recording rules for attempts_delta.