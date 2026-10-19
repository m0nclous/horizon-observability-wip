#!/usr/bin/env bash
#
# Pooled-queues probe: can the dashboard's queue table (#5) find the supervisor and the processes of a queue
# that a balance=false supervisor works? `metrics` is the exposition of the workbench topology: supervisor-pool
# works mail and sms in one pool, so horizon_supervisor_processes carries queue="mail,sms" (Horizon's pool label).
# Prometheus v3.15.0 scrapes it; the probe evaluates the dashboard's row and column expressions (as they were when the
# probe ran) on horizon_supervisor_processes and the same expressions on horizon_supervisor_queues, the queue map the
# exporter added for this case.
#
# Usage: tests/Truth/probes/pooled-queues/run.sh [results file]   (Docker with host network; ports 18381, 19381)

set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
RESULTS="${1:-/dev/stdout}"
WORK="$(mktemp -d)"
PIDS=()

cleanup() {
    for pid in "${PIDS[@]}"; do kill "$pid" 2>/dev/null || true; done
    docker rm -f t16-probe-pooled >/dev/null 2>&1 || true
    rm -rf "$WORK"
}
trap cleanup EXIT

cp "$HERE/metrics" "$WORK/metrics"
python3 -I -m http.server 18381 --bind 127.0.0.1 --directory "$WORK" >/dev/null 2>&1 &
PIDS+=($!)
cat > "$WORK/prometheus.yml" <<YML
global: {scrape_interval: 5s}
scrape_configs:
  - job_name: ho
    metrics_path: /metrics
    # A static file server sends no Prometheus content type, which Prometheus 3 otherwise refuses.
    fallback_scrape_protocol: PrometheusText0.0.4
    static_configs: [{targets: ['127.0.0.1:18381']}]
YML
docker run -d --name t16-probe-pooled --network host -v "$WORK/prometheus.yml:/etc/prometheus/prometheus.yml:ro" \
    quay.io/prometheus/prometheus:v3.15.0 --config.file=/etc/prometheus/prometheus.yml --web.listen-address=127.0.0.1:19381 >/dev/null
sleep 15

query() {
    curl -s --get http://127.0.0.1:19381/api/v1/query --data-urlencode "query=$1" |
        python3 -I -c 'import json,sys; r=json.load(sys.stdin)["data"]["result"]; print(", ".join(sorted("%s=%s" % (",".join(v for k, v in sorted(x["metric"].items()) if k in ("queue", "supervisor")), x["value"][1]) for x in r)) or "no rows")'
}

{
    echo "pooled-queues probe, Prometheus v3.15.0; the pool supervisor-pool works mail and sms"
    echo "rows of #5 (supervisor, queue) with pending jobs:"
    echo "  dashboard on horizon_supervisor_processes: $(query 'group by (queue, supervisor) (last_over_time(ho_horizon_supervisor_processes[1d])) * on (queue) group_left () sum by (queue) (ho_queue_pending_jobs)')"
    echo "  on horizon_supervisor_queues:              $(query 'group by (queue, supervisor) (last_over_time(ho_horizon_supervisor_queues[1d])) * on (queue) group_left () sum by (queue) (ho_queue_pending_jobs)')"
    echo "processes per queue (#5 Processes):"
    echo "  dashboard sum by (queue):                  $(query 'sum by (queue) (ho_horizon_supervisor_processes)')"
    echo "  via the pool label:                        $(query 'sum by (queue) (ho_horizon_supervisor_queues * on (supervisor, pool) group_left () label_replace(ho_horizon_supervisor_processes, "pool", "$1", "queue", "(.*)"))')"
    echo "busy workers per supervisor (utilisation numerator):"
    echo "  dashboard on horizon_supervisor_processes: $(query 'sum by (supervisor) (group by (supervisor, queue) (ho_horizon_supervisor_processes) * on (queue) group_left () sum by (queue) (ho_queue_jobs_reserved))')"
    echo "  on horizon_supervisor_queues:              $(query 'sum by (supervisor) (group by (supervisor, queue) (ho_horizon_supervisor_queues) * on (queue) group_left () sum by (queue) (ho_queue_jobs_reserved))')"
} > "$RESULTS"
