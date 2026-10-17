#!/bin/sh
# Backfills the perf Prometheus with the synthetic history (ADDENDUM R2.2 step 1), one 2-hour chunk at a time, so
# the disk holds at most one chunk (about 0.6 GB at 10 s): write it, convert it with promtool into the perf-prometheus
# volume, delete it. Then restart perf-prometheus so it loads the blocks.
#
#   docker/perf/backfill.sh [workbench:perf-data options]
#   docker/perf/backfill.sh --profile=medium --hours=26                          (catalog schema, S = 10 s)
#   docker/perf/backfill.sh --schema=legacy --step=15 --job=office-horizon --instance=app:8080   (R2.1 reference)
#
# END (Unix seconds, default now) is fixed for all chunks. Start workbench:perf-exporter right afterwards with the
# same --profile, --schema and --namespace, so the history continues without a gap longer than 5 minutes.
set -eu

cd "$(dirname "$0")/../.."
END=${END:-$(date +%s)}
OUT=build/perf/om
mkdir -p "$OUT"

docker compose --profile perf stop perf-prometheus >/dev/null 2>&1 || true

for window in $(php vendor/bin/testbench workbench:perf-data --end="$END" "$@" --list); do
    file=$(php vendor/bin/testbench workbench:perf-data --end="$END" --out="$OUT" "$@" --window="$window")
    echo "converting $file"
    docker compose run --rm -T promtool tsdb create-blocks-from openmetrics "/perf/om/$(basename "$file")" /prometheus >/dev/null
    rm -f "$file"
done

docker compose --profile perf up -d perf-prometheus

exporter=''
for arg in "$@"; do
    case "$arg" in
        --profile=* | --schema=* | --namespace=*) exporter="$exporter $arg" ;;
    esac
done
echo "Backfilled up to $END. Now run: php vendor/bin/testbench workbench:perf-exporter$exporter"
