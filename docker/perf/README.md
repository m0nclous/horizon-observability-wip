# Dashboard performance profile

The repeatable measurement method of ADDENDUM R2.2: synthetic history in a separate Prometheus, a continuation
exporter, a Playwright capture of real dashboard refreshes, a PromQL replay and a budget report. Development only;
`docker/` is export-ignored.

| File | Use |
|---|---|
| `workbench/app/Perf/SyntheticModel.php` | The model: every value is a pure function of time (port of the T01 probe `synth.py`). Profiles `medium` (4 supervisors, 12 queues, 160 job classes, about 5,300 series) and `large` (10, 40, 500); schemas `catalog` (package names, prefix `ho_`) and `legacy` (pilot names, prefix `office_`). |
| `vendor/bin/testbench workbench:perf-data` | Writes the history as OpenMetrics chunks, one per 2-hour block window, with the `job`/`instance` labels Prometheus adds. |
| `vendor/bin/testbench workbench:perf-exporter` | Serves the same model on `:8391/metrics` without `job`/`instance` (Prometheus would rename them to `exported_*`). |
| `docker/perf/backfill.sh` | Writes, converts (`promtool tsdb create-blocks-from openmetrics`) and deletes one chunk at a time into the `perf-prometheus` volume. |
| `docker/perf/prometheus.yml` | The perf Prometheus: scrapes the exporter at 10 s as job `perf-horizon`, instance `perf-exporter`. |
| `docker/perf/prometheus.reference.yml` | The R2.1 reference stack: 15 s, job `office-horizon`, instance `app:8080`. |
| `bin/perf.mjs` | `capture`, `replay`, `report` (see its header). |
| `docker/perf/budget.json` | The R2.3 budget per range; `report --budget` exits 1 on a violation. |
| `docker/perf/reference-legacy.json` | The R2.1 legacy row; `report --expect … --tolerance 0.25` checks a reproduction. |

## Measure a dashboard (S = 10 s)

```sh
docker/perf/backfill.sh --profile=medium --hours=26                         # about 2 minutes, at most 0.7 GB on disk
php vendor/bin/testbench workbench:perf-exporter --profile=medium &         # start right away (< 5 minutes of gap)
PROMETHEUS_URL=http://perf-prometheus:9090 docker compose up -d grafana     # Grafana on the perf data
npm i --no-save playwright@1.56.1 && npx playwright install chromium
node bin/perf.mjs capture --url "http://localhost:3000/d/horizon-observability?orgId=1&from=now-1h&to=now&kiosk" --seconds 70 --out build/perf/1h.json
node bin/perf.mjs replay build/perf/1h.json --prometheus http://localhost:9091
node bin/perf.mjs report build/perf/1h.json --budget docker/perf/budget.json
```

Repeat the capture with `from=now-6h` for the 6-hour budget. Captures need at least four refresh cycles (cycle 0 is
the first load; the report uses cycles 2-4), so `--seconds` must cover four refresh intervals after the first load.

## Reproduce the R2.1 legacy row

The pilot dashboard (uid `horizon`, the legacy golden that T13 deleted; `git show` restores it) on the probe's history: legacy names,
15-second steps, the probe's job and instance labels, Grafana 13.2.4 with the datasource `timeInterval` at 15 s.

```sh
docker compose --profile perf down -v
PERF_PROMETHEUS_CONFIG=prometheus.reference.yml docker/perf/backfill.sh --schema=legacy --step=15 --hours=26 --job=office-horizon --instance=app:8080
php vendor/bin/testbench workbench:perf-exporter --schema=legacy &
mkdir -p build/perf/legacy-dashboard && git show 55dd179:tests/Fixtures/grafana/legacy/queues.json > build/perf/legacy-dashboard/queues.json
GRAFANA_DASHBOARDS=./build/perf/legacy-dashboard PROMETHEUS_URL=http://perf-prometheus:9090 SCRAPE_INTERVAL=15s GRAFANA_IMAGE=grafana/grafana:13.2.4 docker compose up -d grafana
node bin/perf.mjs capture --url "http://localhost:3000/d/horizon?orgId=1&from=now-1h&to=now&kiosk" --seconds 70 --out build/perf/legacy-1h.json
node bin/perf.mjs replay build/perf/legacy-1h.json --prometheus http://localhost:9091
node bin/perf.mjs report build/perf/legacy-1h.json --expect docker/perf/reference-legacy.json
```

Wall times depend on the hardware: the reference stack had 4 vCPU and 15 GB RAM. Request counts, interpolated KB and
samples are deterministic.
