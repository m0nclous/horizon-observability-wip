#!/usr/bin/env bash
#
# Replica clocks probe with the real exporter: one scrape target in front of two exporter replicas
# whose clocks are 15 s apart (replica A on the real time, B 15 s ahead; lb.py alternates A, B, ...), a worker whose
# clock is 120 s ahead, 3 attempts of a class no series exists for (truth: 3). Runs the same timeline against two
# checkouts, each with its own key prefix, export loops, load balancer and Prometheus v3.15.0 (scrape interval 5 s):
# BEFORE (e.g. c288694, the hold on the worker's and the answering replica's clocks) and AFTER (this checkout, the
# hold on the storage Redis' clock). The attempts complete at t = 10 s; at t = 260 s the probe prints the samples of
# the last 250 s, increase() and resets() over them.
#
# Usage: tests/Truth/probes/replica-clocks/run-real.sh <before checkout> <after checkout> [results file]
# Needs Docker (host network) and PHP with the redis extension. Starts its own Redis 7.4 on 127.0.0.1:16391 and uses
# ports 18192/18193 (load balancers) and 19192/19193 (Prometheus).

set -euo pipefail

BEFORE="$(cd "$1" && pwd)"
AFTER="$(cd "$2" && pwd)"
RESULTS="${3:-/dev/stdout}"
HERE="$(cd "$(dirname "$0")" && pwd)"
WORK="$(mktemp -d)"
IMAGE=quay.io/prometheus/prometheus:v3.15.0
REDIS_PORT=16391
PIDS=()

cleanup() {
    for pid in "${PIDS[@]}"; do kill "$pid" 2>/dev/null || true; done
    docker rm -f t16-replica-clocks-before t16-replica-clocks-after t16-replica-clocks-redis >/dev/null 2>&1 || true
    rm -rf "$WORK"
}
trap cleanup EXIT

docker run -d --name t16-replica-clocks-redis --network host redis:7.4-alpine \
    redis-server --bind 127.0.0.1 --port "$REDIS_PORT" --save '' --appendonly no >/dev/null
until docker exec t16-replica-clocks-redis redis-cli -p "$REDIS_PORT" ping >/dev/null 2>&1; do sleep 0.2; done

env_side() {
    echo REDIS_HOST=127.0.0.1 REDIS_PORT="$REDIS_PORT" REDIS_PREFIX="t16clocks-$1:" HORIZON_PREFIX="t16clocks-$1:horizon:" \
        CACHE_STORE=array QUEUE_FAILED_DRIVER=null
}

start_side() {
    local side=$1 checkout=$2 port=$3 prom=$4
    mkdir -p "$WORK/$side"
    env $(env_side "$side") php "$HERE/export-loop.php" "$checkout" "$WORK/$side/A" 300 0 &
    PIDS+=($!)
    env $(env_side "$side") php "$HERE/export-loop.php" "$checkout" "$WORK/$side/B" 300 15 &
    PIDS+=($!)
    python3 -I "$HERE/lb.py" "$WORK/$side" "$port" > "$WORK/$side.log" &
    PIDS+=($!)
    cat > "$WORK/$side.yml" <<YML
global: {scrape_interval: 5s, scrape_timeout: 4s}
scrape_configs:
  - job_name: ho
    static_configs: [{targets: ['127.0.0.1:$port']}]
YML
    docker run -d --name "t16-replica-clocks-$side" --network host -v "$WORK/$side.yml:/etc/prometheus/prometheus.yml:ro" "$IMAGE" \
        --config.file=/etc/prometheus/prometheus.yml --web.listen-address="127.0.0.1:$prom" >/dev/null
}

start_side before "$BEFORE" 18193 19193
start_side after "$AFTER" 18192 19192
T0=$(date +%s)
until_t() { while [ $(( $(date +%s) - T0 )) -lt "$1" ]; do sleep 1; done; }

until_t 10
for side in before after; do
    checkout=$([ $side = before ] && echo "$BEFORE" || echo "$AFTER")
    (cd "$checkout" && env $(env_side "$side") php "$HERE/work.php" "$checkout" 3 120 >/dev/null)
done
until_t 262

AT=$((T0 + 260))
X='ho_queue_jobs_total{job_class=~".+CompletingJob",status="completed"}'

query() {
    curl -s -G "127.0.0.1:$1/api/v1/query" --data-urlencode "query=$2" --data-urlencode "time=$AT" \
        | python3 -I -c 'import json, sys; r = json.load(sys.stdin)["data"]["result"]; print(" ".join(v[1] for v in r[0]["values"]) if r and "values" in r[0] else (r[0]["value"][1] if r else "none"))'
}

{
    echo "replica clocks probe, real exporter, Prometheus v3.15.0, scrape 5 s, replicas A (real time) and B (+15 s) behind one target,"
    echo "a worker 120 s ahead, 3 attempts of a new class at t = 10 s; window [t = 10 s, t = 260 s], truth: 3"
    for side in before after; do
        prom=$([ $side = before ] && echo 19193 || echo 19192)
        echo "$side samples:  $(query "$prom" "${X}[250s]")"
        echo "$side increase: $(query "$prom" "sum(increase(${X}[250s]))")"
        echo "$side resets:   $(query "$prom" "sum(resets(${X}[250s]))")"
    done
    for side in before after; do
        echo "$side requests (t replica value): $(awk -v t0="$T0" '{printf "%.0f %s %s;", $1 - t0, $2, $3}' "$WORK/$side.log")"
    done
} > "$RESULTS"
