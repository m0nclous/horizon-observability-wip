Suggested strategy for the refactor:

1. **Rename.** Do it in one commit: composer `<vendor>/horizon-observability` (vendor to confirm, e.g. df), `DF\HorizonObservability`, config/view/tag `horizon-observability`, env `HORIZON_OBSERVABILITY_*`, MIT license, php ^8.3, laravel ^12. Keep the config key in one constant.

2. **Own the metric naming.**
   - A package `metrics.namespace` (null falls back to spatie's default_namespace) passed explicitly to every addGauge/addCounter.
   - Names unique against spatie's built-in collectors (F03).
   - The package's own non-throwing horizon_status.
   - English help texts and label sentinels.

3. **One source of truth for Grafana.** Turn the Python script into a parameterised generator that emits both queues.json and the alert YAML from one settings block: prefix (or a `metric_prefix` variable), uid, title, tags, scope label (job/instance), API prefix, scrape interval, thresholds, alert uid prefix, folder, org, TTS mode, datasource regexes. Pass app facts the dashboard cannot know (Horizon URL, API path, optionally the TTS URL) as `horizon_info` labels, the pattern the dashboard already uses for horizon_url.

   The committed JSON is byte-identical to the current generator output (checked in the scratchpad), so the generator is trustworthy. Add a golden or snapshot test: with office-equivalent flags the output must equal today's JSON apart from the identity fields. That satisfies "the dashboard must keep its look and behaviour". Also add tests that the Infinity URLs and filter variable names match the registered routes and GetHorizonJobsRequest.

4. **Runtime guards and toggles:**
   - Assert `prometheus.cache === null`.
   - Derive the route prefix and middleware from spatie's `urls`/`middleware` config.
   - Auto-detect batches (driver and table); job-class paths come from config.
   - Opt-in Horizon menu link, guarded by a Testbench test against Horizon's real layout.
   - Per-collector toggles; throttled English logging.
   - Hash-tagged Redis key prefix.

5. **Move dev tooling to the workbench.** The load-test command and job go to the Testbench workbench, together with a docker-compose (Redis, Prometheus, Grafana with the Infinity and Business Text plugins) for screenshots and metric checks.

6. **Docs and migration.** Rewrite the docs in English. Move office's migration notes to UPGRADE.md and drop the internal checklists. Office keeps its current series by setting namespace=office and the old label sentinels; tell office which series will be renamed.

Checks run in the scratchpad (nothing written in the repo):
- The generator was run with the default, an empty and the 'app' prefix: `--prefix ""` yields `_queue_*` names, and the 'office' tag survives any prefix.
- spatie defaults were read from upstream raw files: urls.default='prometheus', default_namespace='app', cache=null.
- spatie metric names were read from its Queue/Horizon collectors and its install stub, which together establish the collisions in F03.

Line numbers in the findings are real file lines.