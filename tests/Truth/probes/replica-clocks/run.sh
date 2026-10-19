#!/usr/bin/env bash
#
# Replica clocks probe: one scrape target, two exporter replicas whose clocks differ by 15 s, a new series with 3
# attempts (truth: 3). Prometheus v3.15.0 scrapes every 5 s; at t = 150 s the probe prints the samples of the last
# 145 s, increase() and resets().
#
# Usage: tests/Truth/probes/replica-clocks/run.sh [results file]   (Docker with host network; ports 18191, 19191)

set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
RESULTS="${1:-/dev/stdout}"
WORK="$(mktemp -d)"
PID=''

cleanup() {
    [ -n "$PID" ] && kill "$PID" 2>/dev/null || true
    docker rm -f t16-replica-clocks-prom >/dev/null 2>&1 || true
    rm -rf "$WORK"
}
trap cleanup EXIT

cat > "$WORK/prometheus.yml" <<YML
global: {scrape_interval: 5s, scrape_timeout: 4s}
scrape_configs:
  - job_name: ho
    static_configs: [{targets: ['127.0.0.1:18191']}]
YML

T0=$(python3 -c 'import time; print(time.time() + 5)')
python3 -I "$HERE/lb_exporter.py" "$T0" 18191 > "$WORK/requests.log" & PID=$!
docker run -d --name t16-replica-clocks-prom --network host -v "$WORK/prometheus.yml:/etc/prometheus/prometheus.yml:ro" \
    quay.io/prometheus/prometheus:v3.15.0 --config.file=/etc/prometheus/prometheus.yml --web.listen-address=127.0.0.1:19191 >/dev/null

until python3 -c "import time,sys; sys.exit(0 if time.time() - $T0 >= 152 else 1)"; do sleep 2; done
AT=$(python3 -c "print($T0 + 150)")

query() {
    curl -s -G 127.0.0.1:19191/api/v1/query --data-urlencode "query=$1" --data-urlencode "time=$AT" \
        | python3 -I -c 'import json, sys; r = json.load(sys.stdin)["data"]["result"]; print(" ".join(v[1] for v in r[0]["values"]) if r and "values" in r[0] else (r[0]["value"][1] if r else "none"))'
}

{
    echo "requests (t replica value): $(tr '\n' ';' < "$WORK/requests.log")"
    echo "samples, last 145 s:       $(query 'ho_queue_jobs_total[145s]')"
    echo "increase(...[145s]):       $(query 'increase(ho_queue_jobs_total[145s])')   (truth: 3)"
    echo "resets(...[145s]):         $(query 'resets(ho_queue_jobs_total[145s])')"
} > "$RESULTS"
