#!/usr/bin/env bash
#
# Scripted first-value probe (T16): fake_exporter.py serves ho_queue_jobs_total as a function of time, so the same
# timeline can be shown with and without the exporter's 75 s hold of new series, and with targets that appear late.
# - target A (job "ho") from t=0; target B (job "ho", a replica on the same storage) and C (job "late", a scrape job
#   added later) from t=90 s;
# - OldJob: 100 completed before the probe, 102 from t=60 s (truth in the window: 2);
# - NewJob: born at t=30 s with 3 completed, 5 from t=60 s (truth: 5); mode "hold" exports it as 0 until t=105 s.
# At t=150 s query.py evaluates over [t=10 s, t=150 s]: Prometheus' increase(), the dashboard's delta() (T13
# Queries\Attempts) and "count-first", the dashboard-only alternative that counts a new series' first value.
#
# Usage: tests/Truth/probes/first-value/run-scripted.sh [results file]   (Docker with host network; ports
# 18091-18096, 19091-19092)

set -euo pipefail

HERE="$(cd "$(dirname "$0")" && pwd)"
RESULTS="${1:-/dev/stdout}"
WORK="$(mktemp -d)"
PIDS=()

cleanup() {
    for pid in "${PIDS[@]}"; do kill "$pid" 2>/dev/null || true; done
    docker rm -f t16-ev-prom-baseline t16-ev-prom-hold >/dev/null 2>&1 || true
    rm -rf "$WORK"
}
trap cleanup EXIT

T0=$(python3 -c 'import time; print(time.time() + 10)')

for mode in baseline hold; do
    if [ $mode = baseline ]; then a=18091; b=18092; c=18093; p=19091; else a=18094; b=18095; c=18096; p=19092; fi
    cat > "$WORK/prom-$mode.yml" <<YML
global: {scrape_interval: 5s, scrape_timeout: 4s}
scrape_configs:
  - job_name: ho
    static_configs: [{targets: ['127.0.0.1:$a', '127.0.0.1:$b']}]
  - job_name: late
    static_configs: [{targets: ['127.0.0.1:$c']}]
YML
    python3 -I "$HERE/fake_exporter.py" $mode "$T0" $a 0 & PIDS+=($!)
    python3 -I "$HERE/fake_exporter.py" $mode "$T0" $b 90 & PIDS+=($!)
    python3 -I "$HERE/fake_exporter.py" $mode "$T0" $c 90 & PIDS+=($!)
    docker run -d --name t16-ev-prom-$mode --network host -v "$WORK/prom-$mode.yml:/etc/prometheus/prometheus.yml:ro" \
        quay.io/prometheus/prometheus:v3.15.0 --config.file=/etc/prometheus/prometheus.yml --web.listen-address=127.0.0.1:$p >/dev/null
done

until python3 -c "import time,sys; sys.exit(0 if time.time() - $T0 >= 152 else 1)"; do sleep 2; done
AT=$(python3 -c "print($T0 + 150)")

{
    for p in 19091 19092; do
        echo "== Prometheus $p ($([ $p = 19091 ] && echo baseline || echo hold))"
        python3 -I "$HERE/query.py" $p "$AT"
    done
} > "$RESULTS"
