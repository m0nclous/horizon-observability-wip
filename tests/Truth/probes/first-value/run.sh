#!/usr/bin/env bash
#
# First-value probe (T16): how many attempts of a brand-new job class does Prometheus count?
#
# Runs the same timeline against two checkouts of the package, each with its own key prefix, exporter and
# Prometheus v3.15.0 (scrape interval 5 s): BEFORE (a checkout without the T16 fix, e.g. the integration branch
# before the merge) and AFTER (this checkout). At t=20 s three CompletingJob attempts of a class no series exists for
# complete, at t=50 s two more; at t=150 s the probe evaluates over the window [t=10 s, t=150 s] (truth: 5):
# - increase(): Prometheus' own extrapolated increase;
# - delta(): the dashboard's exact difference (T13 Queries\Attempts::delta(): the last value before the window,
#   else the first value inside it).
#
# Usage: tests/Truth/probes/first-value/run.sh <before checkout> <after checkout> [results file]
# Needs Docker (host network), Redis on 127.0.0.1:6379, PHP with the redis extension. Uses ports 18181/18281 and
# 19181/19281 and the prefixes t16probe-before: and t16probe-after: (deleted before and after).

set -euo pipefail

BEFORE="$(cd "$1" && pwd)"
AFTER="$(cd "$2" && pwd)"
RESULTS="${3:-/dev/stdout}"
HERE="$(cd "$(dirname "$0")" && pwd)"
WORK="$(mktemp -d)"
IMAGE=quay.io/prometheus/prometheus:v3.15.0
PIDS=()

cleanup() {
    for pid in "${PIDS[@]}"; do kill "$pid" 2>/dev/null || true; done
    docker rm -f t16-probe-before t16-probe-after >/dev/null 2>&1 || true
    for side in before after; do
        redis-cli --scan --pattern "t16probe-$side:*" | xargs -r -d '\n' redis-cli del >/dev/null
    done
    rm -rf "$WORK"
}
trap cleanup EXIT

for side in before after; do
    redis-cli --scan --pattern "t16probe-$side:*" | xargs -r -d '\n' redis-cli del >/dev/null
done

start_side() {
    local side=$1 checkout=$2 port=$3 prom=$4
    mkdir -p "$WORK/$side"
    REDIS_PREFIX="t16probe-$side:" HORIZON_PREFIX="t16probe-$side:horizon:" CACHE_STORE=array \
        php "$HERE/export-loop.php" "$checkout" "$WORK/$side/metrics" 400 &
    PIDS+=($!)
    python3 -I -m http.server "$port" --bind 127.0.0.1 --directory "$WORK/$side" >/dev/null 2>&1 &
    PIDS+=($!)
    cat > "$WORK/$side.yml" <<YML
global: {scrape_interval: 5s, scrape_timeout: 4s}
scrape_configs:
  - job_name: ho
    metrics_path: /metrics
    # A static file server sends no Prometheus content type, which Prometheus 3 otherwise refuses.
    fallback_scrape_protocol: PrometheusText0.0.4
    static_configs: [{targets: ['127.0.0.1:$port']}]
YML
    docker run -d --name "t16-probe-$side" --network host -v "$WORK/$side.yml:/etc/prometheus/prometheus.yml:ro" "$IMAGE" \
        --config.file=/etc/prometheus/prometheus.yml --web.listen-address="127.0.0.1:$prom" >/dev/null
}

work_side() {
    local side=$1 checkout=$2 count=$3
    (cd "$checkout" && REDIS_PREFIX="t16probe-$side:" HORIZON_PREFIX="t16probe-$side:horizon:" CACHE_STORE=array QUEUE_FAILED_DRIVER=null \
        php "$HERE/dispatch.php" "$checkout" "$count" >/dev/null &&
        REDIS_PREFIX="t16probe-$side:" HORIZON_PREFIX="t16probe-$side:horizon:" CACHE_STORE=array QUEUE_FAILED_DRIVER=null \
        php vendor/bin/testbench queue:work redis --queue=default --stop-when-empty --sleep=0 >/dev/null)
}

start_side before "$BEFORE" 18281 19281
start_side after "$AFTER" 18181 19181
T0=$(date +%s)
until_t() { while [ $(( $(date +%s) - T0 )) -lt "$1" ]; do sleep 1; done; }

until_t 20
work_side before "$BEFORE" 3
work_side after "$AFTER" 3
until_t 50
work_side before "$BEFORE" 2
work_side after "$AFTER" 2
until_t 152

AT=$((T0 + 150))
X='ho_queue_jobs_total{job_class=~".+CompletingJob",status="completed"}'
NOW="sum(max by (job, connection, queue, job_class, status) ($X))"
START="(sum(max by (job, connection, queue, job_class, status) (last_over_time(${X}[10m] offset 140s))) or sum(max by (job, connection, queue, job_class, status) (min_over_time(${X}[140s]))))"
DELTA="((($NOW - $START) >= 0) or $NOW)"

{
    echo "first-value probe, Prometheus v3.15.0, scrape 5 s, window [t=10 s, t=150 s], truth: 5 completed attempts"
    for side in before after; do
        prom=$([ $side = before ] && echo 19281 || echo 19181)
        for name in up samples increase delta; do
            case $name in
                up) query='sum(up)' ;;
                samples) query="sum(count_over_time(${X}[140s]))" ;;
                increase) query="sum(increase(${X}[140s]))" ;;
                delta) query="$DELTA" ;;
            esac
            value=$(curl -s --get "http://127.0.0.1:$prom/api/v1/query" --data-urlencode "query=$query" --data-urlencode "time=$AT" |
                python3 -I -c 'import json,sys; r=json.load(sys.stdin)["data"]["result"]; print(r[0]["value"][1] if r else "none")')
            echo "$side $name: $value"
        done
    done
} > "$RESULTS"
