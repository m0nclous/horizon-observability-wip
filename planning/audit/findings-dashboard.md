# Dashboard/generator findings

Audit of the legacy pilot (now in `legacy/`), 2026-10-16. File:line references point to the legacy layout (`src/...` = `legacy/src/...`).

## Summary

Audit of how the Grafana resources (queues.json, generate_queues_dashboard.py, alerting/horizon.yml) depend on the PHP side, with a plan to make the dashboard project-agnostic without changing its look or behaviour.

What I checked:
- The committed queues.json is byte-identical to what the generator produces with its defaults (Python 3.12 and 3.13). Under Python 3.11 the generator does not run at all: it hits a SyntaxError at line 1032.
- With `--prefix acme`, exactly 73 lines change, all of them `office_(queue|horizon)_` names. The `office` tag, dashboard uid `horizon`, the `horizon-.*` alert-rule selector and the `GRAFANA_ALERTS` name stay hardcoded.
- Every metric the dashboard and the alert rules read has a PHP producer, apart from GRAFANA_ALERTS, which Grafana writes itself through state-history remote write.

Main problems found:
1. Prefix handling is unsafe. Metric names are written as `office_` and swapped with a regex on the finished JSON, with no validation: an empty prefix gives `_queue_…`, `My-App` gives invalid PromQL. The alert YAML has no generator, so its prefix is edited by hand. Leftover `office` text sits in the dashboard tags, an alert summary and a PHP help text.
2. Three package metric names, plus `horizon_current_processes`/`horizon_status`, are exactly the same (names and labels) as metrics from spatie's own optional collectors in the shared namespace. If an app also enables spatie's queue collectors, they silently overwrite each other with different meanings.
3. The JSON API path `/metrics/horizon/...` is hardcoded in the dashboard, while the PHP route prefix is configurable. Changing the config empties the four job tables with no error.
4. Identity and threshold constants are kept separately in the dashboard and in the alert YAML, with nothing tying them together: dashboard uid, rule uid prefix, panel ids, evaluation interval vs the 90s alerts window, and the failure and wait ratios.
5. No query is scoped to a project or environment (no `job`/`instance` matcher anywhere). The hidden datasource variables cannot be overridden (hidden, and skipped in the URL), so a Grafana with several Prometheus or Infinity sources always uses the default one.

Smaller issues: unused contract parts (`size`/`created` labels on batch progress, which also cause series churn; `released` status; wait histogram `_sum`/`_count`; JSON `id` fields), Russian label values reaching the UI, dead generator code, fragile DOM hacks in the alert-sound JS, and no `__requires` block in the dashboard JSON.

Recommendation: port the generator to a PHP artisan renderer that writes the dashboard and the alert rules from one set of constants. Protect the "same look and behaviour" requirement with a golden test that compares the PHP output against today's queues.json. Move the project-specific values into hidden Grafana variables, so the committed JSON works in any project without regenerating.


## F1 [high] Package metric names collide with spatie/laravel-prometheus' own collectors in the shared namespace

- **Location:** src/Prometheus/QueueStateCollector.php:27-69; src/Prometheus/HorizonWorkloadCollector.php:21-25; src/HorizonPrometheusGrafanaServiceProvider.php:72-82 (vs spatie src/Collectors/Queue/QueuePendingJobsCollector.php:26-28, QueueDelayedJobsCollector.php:26-28, QueueOldestPendingJobCollector.php:27-29, Collectors/Horizon/CurrentProcessesPerQueueCollector.php:14-16, resources/stubs/PrometheusServiceProvider.php.stub)
- **Detail:** Three package metrics, queue_pending_jobs, queue_delayed_jobs and queue_oldest_pending_job_age with labels [connection, queue], are exactly the same as spatie's optional queue collectors. horizon_current_processes{queue} is the same as spatie's CurrentProcessesPerQueueCollector. horizon_status comes from spatie's HorizonStatusCollector, which the package registers itself. All of them use the same prometheus.default_namespace (spatie Gauge.php:33). spatie's published stub offers registerQueueCollectors()/registerHorizonCollectors(), so a spatie user can easily turn them on. When that happens, promphp's getOrRegisterGauge returns the same gauge and both collectors set() the same label sets: the last one written wins, silently. spatie's pendingSize leaves out delayed jobs whose time has come, and its oldest age is computed differently, so the Pending tile colour, the Oldest wait column and the horizon-long-wait alert would show spatie's numbers without any error. If someone later changes the label sets, it becomes an exception and /metrics returns 500.
- **Recommendation:** Give the package its own metric namespace: call ->namespace(config('horizon-observability.metrics.namespace')) on every Gauge/Counter (spatie Gauge::namespace() exists; defaulting to prometheus.default_namespace is fine). Make names unambiguous, e.g. a horizon_observability_ or hob_ sub-prefix, or at least queue_* -> horizon_queue_*. Ship the package's own horizon_status instead of registering spatie's HorizonStatusCollector, which has no error guard (see the ServiceProvider:31 comment). The dashboard and alert renderer read the same config value. Add a test that registers spatie's stub collectors next to the package and checks the values are not overwritten.

## F2 [high] office_ prefix is hardcoded everywhere and rewritten by an unvalidated regex; alert rules and the tag are never rewritten

- **Location:** resources/grafana/generate_queues_dashboard.py:17,24-26,41,110-112,179-189,207,1815,1996-1997; resources/grafana/alerting/horizon.yml:26,42,57,91-92,125-126; src/Prometheus/QueueStateCollector.php:50; README.md:44
- **Detail:** All PromQL in the generator is written with the literal office_, and --prefix is applied by re.sub over the final JSON text (line 1997). The value is not validated. I ran it in the scratchpad: --prefix '' gives `_queue_pending_jobs` (43 times), but with an empty namespace promphp produces `queue_pending_jobs` with no leading underscore (prometheus_client_php Collector.php:45). --prefix My-App gives invalid PromQL, while spatie slugifies the namespace with Str::slug('_')->lower() (Gauge.php:33), so `My App` becomes `my_app`. --prefix office_ gives `office__…`. The tag stays `office` for any prefix (line 1815). The alert YAML has no generator, so its prefix is edited by hand (README:44), and its summary text names office_horizon_status (yml:42). A PHP help text also mentions office_queue_delayed_due_jobs (QueueStateCollector.php:50).
- **Recommendation:** Build metric names with a function, e.g. metric('queue_pending_jobs'), from one normalized prefix: slug('_') + lower + trailing '_', or '' for an empty namespace. Use the same rule as spatie and validate it against [a-zA-Z_:][a-zA-Z0-9_:]*. In the JSON, use a hidden variable (constant, or a textbox with hide: 2) named metric_prefix, with value `office_` or ``, written as ${metric_prefix}queue_pending_jobs. Grafana interpolates it in panel queries, variable queries and label regexes, so one committed JSON fits every project, while the renderer still bakes in the default from config. Render the alert rules from the same constant. Drop the `office` tag, or set it from the app name. Remove prefixes from PHP help texts.

## F3 [medium] JSON API path hardcoded in the dashboard while the PHP route prefix is configurable

- **Location:** resources/grafana/generate_queues_dashboard.py:1428,1444,1457,1465,1473; config/horizon-prometheus-grafana.php:62-68; src/HorizonPrometheusGrafanaServiceProvider.php:92-101
- **Detail:** The Infinity targets call `/metrics/horizon/{failed,completed,pending,delayed}-jobs?limit=500&from=${__from}&to=${__to}&${supervisor:queryparam}&…`. PHP registers the routes under config routes.prefix (default metrics/horizon). Change the prefix and the four tables silently show the `—` placeholder row. The default also bakes in an office assumption: spatie serves metrics at /prometheus by default (spatie config prometheus.php `urls.default => 'prometheus'`), so the config comment and README ('next to /metrics, close the /metrics prefix in nginx', config:62-63, README:7,237) hold only for office's custom spatie URL.
- **Recommendation:** Add a hidden dashboard variable api_path, defaulted by the renderer from routes.prefix, and use `${api_path}/failed-jobs`. Infinity interpolates variables in URLs. Derive the default route prefix from spatie's urls.default (e.g. `<urls.default>/horizon`) or use a neutral `horizon-observability/api`, and update the docs. Add a contract test that resolves the dashboard's Infinity paths against registered routes.

## F4 [medium] Dashboard and alert rules duplicate identity and threshold constants with nothing keeping them in sync

- **Location:** resources/grafana/generate_queues_dashboard.py:862,877,917,1508-1515,1987; resources/grafana/alerting/horizon.yml:7-17,43-44,48,75-76,81,102,109-110,115,126,136,143-144; config/horizon-prometheus-grafana.php:70-74
- **Detail:** Values that must stay equal live in different files:
- Dashboard uid `horizon`: generator 1987; yml __dashboardUid__ 43/75/109/143; the HPG_GRAFANA_URL example /d/horizon/queues.
- Rule uid prefix: the Alerts tile and the sound read GRAFANA_ALERTS{grafana_rule_uid=~"horizon-.*"} (generator 1514), while the uids are typed by hand in yml 17/48/81/115.
- Panel ids: yml __panelId__ "1","2","4" must match the generator's stat() ids.
- Evaluation interval: ALERTS_WINDOW=90s (generator 1508) relies on `interval: 1m` (yml 13); at 2m the tile would flicker to 0.
- Thresholds: failure 5% of max(attempts,100) (yml 126/136 vs generator 862/877), long wait > 2x the limit (yml 102 vs generator 917).

In a Grafana that hosts more than one project, the uids collide: provisioning overwrites them, and the Alerts tile and voice count the other project's rules. orgId 1, folder `Horizon` and group `Queues` are also hardcoded. The alert uses increase() (extrapolated) while the tile uses exact attempts_delta, so their numbers can differ slightly.
- **Recommendation:** Generate the alert rules from the same code and constants as the dashboard: uid, rule uid prefix, folder, orgId, interval, panel ids taken from the panel objects, thresholds and windows. Expose them as renderer options or config, e.g. grafana.dashboard_uid (default horizon-observability), grafana.alert_rule_uid_prefix, grafana.folder. Make the GRAFANA_ALERTS selector a hidden variable (alert_rule_uid_prefix) so the committed JSON stays generic. Build HPG_GRAFANA_URL's successor from the base Grafana URL plus the configured uid.

## F5 [medium] No project or environment scoping in any query

- **Location:** resources/grafana/generate_queues_dashboard.py:192-207 (filtered), 24, 1917-1951 (horizon_url/tts_url variables)
- **Detail:** I grepped queues.json: no `job` or `instance` matcher appears anywhere. If one Prometheus scrapes two apps that share a namespace (e.g. the spatie default `app`), or staging and prod of the same app, every panel, filter and alert merges them. horizon_url and tts_url are single-value query variables over group by (url)(last_over_time(…horizon_info[7d])), so with several apps they quietly take the first value.
- **Recommendation:** Add an optional hidden query variable `job` (label_values(${metric_prefix}horizon_status, job), includeAll, allValue `.+`, URL-syncable). The selector builder injects it into every package metric selector, but not into GRAFANA_ALERTS, which is scoped by rule uid prefix. Hidden means the look does not change, and a renderer flag can make it visible. Alert rules can get the same matcher through the renderer.

## F6 [medium] Datasources picked only by type and cannot be overridden

- **Location:** resources/grafana/generate_queues_dashboard.py:21,1381,1952-1980
- **Detail:** `datasource` (type prometheus) and `app_api` (type yesoreyeram-infinity-datasource) are hide: 2 + skipUrlSync: true + regex ''. In a Grafana with several Prometheus or Infinity datasources, which is common in shared Grafanas, the default or first one is always used, with no UI or URL way to choose. The Alerts tile and sound also assume GRAFANA_ALERTS lives in the same Prometheus as the app metrics (it goes to prometheus_target_datasource_uid). The alert rules work the other way, by explicit uid from an env var, which is not consistent.
- **Recommendation:** Keep the variables hidden so the look is unchanged, but drop skipUrlSync so ?var-datasource=<uid>&var-app_api=<uid> work. Let the renderer set `regex` or a current default from options (--prometheus-datasource, --infinity-datasource). Optionally add a separate hidden alerts_datasource variable for GRAFANA_ALERTS. Ship provisioning templates (datasources.yaml with Prometheus and Infinity plus allowedHosts) so the default case works without configuration.

## F7 [medium] Alerting YAML depends on a Grafana-process env var with the old prefix

- **Location:** resources/grafana/alerting/horizon.yml:5,23,54,87,121
- **Detail:** The only env dependency is `${HPG_PROMETHEUS_DATASOURCE_UID}`, which Grafana itself interpolates at provisioning time. It is not an app env var, and it carries the old HPG_ (horizon-prometheus-grafana) prefix. On a UI import (Alerting → Import) it is not substituted. I checked Grafana source (pkg/services/provisioning/alerting/rules_types.go): uid, title, condition, datasourceUid, for and labels are interpolated (values.StringValue/StringMapValue), while annotations and model are read via .Raw. So the current `{{ $labels.queue }}`/`$values.A.Value` annotations are safe, but any rule `labels:` or title containing `$` would be expanded as env vars unless written `$$`.
- **Recommendation:** Rename the variable to the new prefix, e.g. HORIZON_OBSERVABILITY_PROMETHEUS_DS_UID, or better, let the renderer write the uid directly (--datasource-uid), and keep the env-var template only for provisioning setups. Note in the docs that templated labels must use `$$`. Ship rules as both a rendered file and a provisioning template.

## F8 [medium] Generator is a 2000-line flat script with heavy duplication, regex post-processing and no check mode

- **Location:** resources/grafana/generate_queues_dashboard.py:15-18,192-207,242,447,622,736,982-989,1494-1495,1572-1680,1704-1707,1997
- **Detail:** - Argparse runs at import (lines 15-18), so the module cannot be imported or unit-tested. It has 151 module-level names, and panel dicts are mutated after construction (by_title patching, panels.insert).
- Layout is set and then overwritten in several places: pending_chart y is 11 → 12 → 20 → +1 (lines 736, 988, 1494, 1705), and jobs_table y is 20 → 22 → 38.
- Selectors are patched by regex: `filtered()` matches `office_[a-z_]+` and breaks if a label value contains `}`. The final JSON text is rewritten by regex too (line 1997).
- The alert-sound JS is a Python string with a textual .replace("REPEAT_MINUTES") (line 1680), so it cannot be linted or tested.
- Repeated blocks: the red-from-1 threshold block 8x, table field defaults 6x, the convertFieldType 'Markdown only renders strings' block 4x, the 'undefined' placeholder mapping 3x, the Success rate override 2x; the at()/selector helpers are copied in attempts_delta and attempts_per_step.
- A layout invariant is checked with assert (line 985), which python -O removes.
- The default output overwrites the committed file; there is no --check/--stdout option and no CI guard that the committed JSON matches the generator.
- Comments are in Russian, which suits a public package less.
- **Recommendation:** Port it to a typed builder (see notes): a PromQL selector/metric builder instead of regexes, panel factory helpers (tableDefaults(), redFromOne(), successRateColumn(), markdownStringField()), a single layout pass computing gridPos, JS in resources/grafana/alert-sound.js loaded and parameterized via a JSON config object, and a --check mode used in CI and tests.

## F9 [low] Generator needs Python >= 3.12 but the docs do not say so

- **Location:** resources/grafana/generate_queues_dashboard.py:1031-1032,1038,1241-1242; README.md:296
- **Detail:** Several f-strings contain backslashes inside the expression part, e.g. f'…{BATCH_LAST.format(f"office_queue_batch_job_counts{{state=\"{state}\"}}")}'. This is allowed only since PEP 701. Verified: /usr/bin/python3.11 fails with 'SyntaxError: f-string expression part cannot include a backslash' at line 1032, while 3.12 and 3.13 produce byte-identical output. Debian 12 / Ubuntu 22.04 ship python3.11 or 3.10. The README just says `python3`.
- **Recommendation:** Remove the Python dependency by porting to PHP (see notes). If Python stays, document >=3.12 (or a shebang/uv script header), and move the quoted matchers out of the f-string expressions.

## F10 [low] Produced but never consumed: unused labels, series and fields

- **Location:** src/Prometheus/QueueBatchesCollector.php:98-105,296-298; src/Prometheus/QueueJobsCollector.php:56-61; src/JobAttemptStatus.php:21; src/Http/Resources/Horizon*JobResource.php (id); resources/grafana/generate_queues_dashboard.py:1060-1065
- **Detail:** - queue_batch_progress labels `size` and `created` are never read: the dashboard uses `max by (batch, name, status, id)` (generator 1061) and gets Created from queue_batch_created_timestamp_seconds with dateTimeAsLocal. `size` follows total_jobs, which grows while Laravel adds jobs to a batch, so it creates new series. `created` is formatted with app.timezone, a needless config coupling (extraction.md:433).
- queue_job_wait_seconds_sum/_count are never queried (only _bucket).
- queue_jobs_total{status="released"} is never consumed; released is excluded on purpose.
- The JSON `id` field of all four list endpoints is unused (the dashboard uses horizon_path, and delayed has neither).
- The `connection` label of every queue_* metric is never grouped on.
- **Recommendation:** Drop the `size` and `created` labels; dashboard behaviour does not change because they are aggregated away. Keep _sum/_count (standard histogram shape) and `released` (documented and useful for ad-hoc queries), but list them in the contract docs as 'not used by the dashboard'. Keep `id` or remove it, but document it. See F11 for `connection`.

## F11 [low] PHP keys queues by (connection, queue) but the dashboard keys by queue only

- **Location:** resources/grafana/generate_queues_dashboard.py:24 (QUEUES = group by (queue)), 110-119, 462-490; src/Prometheus/QueueStateCollector.php:22-23; src/Prometheus/HorizonWorkloadCollector.php:14-15,33-36
- **Detail:** Every queue_* series carries connection, but all dashboard joins and aggregations are `by (queue)`/`on (queue)`. The same queue name on two connections (e.g. redis and redis-long-running both with `default`) merges into one row. horizon_current_processes has no connection label at all, and for balance=false supervisors its queue label is 'a,b,c' (README:158), so it never joins with queue_* and Processes/Utilization show 0 or unknown for those queues.
- **Recommendation:** Document it as a known limit, or have the builder group by (connection, queue) where it matters (QUEUES, the Queues table rows), showing connection only when more than one exists. Optionally emit horizon_current_processes per real queue (and with connection) by splitting balance=false pools in PHP.

## F12 [low] Russian label values and help texts reach the public contract

- **Location:** src/RedisQueueStateReader.php:26-27; all ->helpText(...) calls in src/Prometheus/*.php
- **Detail:** job_class="(не просмотрено)" and "(неизвестно)" show up as Jobs-table rows and in the Job filter for any user. All metric HELP texts are in Russian.
- **Recommendation:** Use English sentinel values, e.g. `(unscanned)` and `(unknown)`, and define them as constants shared with docs and tests. Write HELP texts in English.

## F13 [low] Dead code in the generator

- **Location:** resources/grafana/generate_queues_dashboard.py:168-175 (STATUS_EXPR), 1030-1039 (batch_jobs_in_range, batch_closed), 1093-1182 (batches_table)
- **Detail:** An AST scan shows STATUS_EXPR and batches_table are never read; batch_jobs_in_range and batch_closed are used only by batches_table. STATUS_EXPR also hardcodes stale fixed 300/60 s thresholds that horizon.waits has replaced. The comments at 456 and 1493 confirm they are 'not shown yet'.
- **Recommendation:** Delete them during the port; if the ideas are wanted, keep them in docs/backlog.md.

## F14 [low] Alert sound panel relies on Grafana DOM internals and global state

- **Location:** resources/grafana/generate_queues_dashboard.py:1572-1708
- **Detail:** - The JS finds `.react-grid-item`, hides itself and moves the whole grid with a negative marginTop (lines 1575-1582); Grafana's new layouts or schema v2 can break this.
- It keeps state in window.__horizonAlertSound (line 1607), shared across dashboards in one SPA session.
- Speech uses the `alertname` column; execErrState: Error creates DatasourceError instances that keep grafana_rule_uid, so they would probably be spoken as 'DatasourceError' (not verified).
- The TTS path /synthesize and body {text} match Piper's piper.http_server API (checked in the Piper docs), but Piper sends no CORS headers, so a reverse proxy is required and not shipped.
- The panel's query goes through target()/filtered(); it works only because GRAFANA_ALERTS has no office_ prefix.
- **Recommendation:** Keep the behaviour but move the JS to its own file with a small config object (repeatMinutes, ttsPath, stateKey derived from the dashboard uid). Fall back to the `rulename` label when alertname is DatasourceError/DatasourceNoData. Ship a CORS proxy snippet (nginx/Caddy) and a Piper service in the workbench docker-compose. Pin and test against the supported Grafana major in CI screenshots.

## F15 [low] GRAFANA_ALERTS name hardcoded; state-history requirements not encoded in the dashboard

- **Location:** resources/grafana/generate_queues_dashboard.py:1513-1520; README.md:95-104
- **Detail:** Grafana's Prometheus state-history backend lets you rename the metric (`prometheus_metric_name`, default GRAFANA_ALERTS), but the dashboard hardcodes the name. The README config uses backend=multiple with primary=annotations, while Grafana docs show primary=loki for multiple. Neither the minimum Grafana version for this feature nor schemaVersion 42 (Grafana 12.x) is stated anywhere. The JSON has no `__requires` block, so Grafana import does not warn about missing Infinity or Business Text plugins.
- **Recommendation:** Make the alerts metric a hidden variable (alerts_metric). Add `__requires` (grafana >= tested version, yesoreyeram-infinity-datasource, marcusolsson-dynamictext-panel); this is metadata only, so the look does not change. Verify the state-history config in the workbench Grafana and document the tested versions.

## F16 [low] tts_url is carried as an app metric label

- **Location:** src/Prometheus/HorizonInfoCollector.php:24-28; config/horizon-prometheus-grafana.php:76-79; resources/grafana/generate_queues_dashboard.py:1935-1950
- **Detail:** The browser-side TTS endpoint, a Grafana viewer concern, is published by the app as horizon_info{tts_url}. Changing it means an app config change and redeploy, and an internal URL ends up in metrics. horizon_url belongs in app telemetry; tts_url less so.
- **Recommendation:** Keep horizon_info{url}. For tts_url, prefer a hidden textbox/constant variable that the renderer fills from config or a flag, and keep the metric label only as an optional fallback for backward compatibility. Either way, record the choice in the contract docs.
