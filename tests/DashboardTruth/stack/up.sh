#!/usr/bin/env bash
# Starts the dashboard truth stack (compose.yml) of one slot and waits until it serves (design section 12):
# Prometheus ready, every selected Grafana healthy with both plugins and both provisioned datasources.
#
#   COMPOSE_PROJECT_NAME=ho-s30 PROMETHEUS_PORT=19030 GRAFANA_PORT=13030 GRAFANA12_PORT=13530 APP_PORT=18030 \
#   DASHBOARD_TRUTH_TOKEN=... DASHBOARD_TRUTH_PLUGINS=<dir> DASHBOARD_TRUTH_GRAFANA="12 13" stack/up.sh
#
# bin/run calls it with the slot environment. Only this compose project is touched.
set -euo pipefail

here=$(cd "$(dirname "$0")" && pwd)
: "${COMPOSE_PROJECT_NAME:?}" "${PROMETHEUS_PORT:?}" "${GRAFANA_PORT:?}" "${GRAFANA12_PORT:?}" "${APP_PORT:?}"
: "${DASHBOARD_TRUTH_TOKEN:?}" "${DASHBOARD_TRUTH_PLUGINS:?}"
versions=${DASHBOARD_TRUTH_GRAFANA:-12 13}

"$here/plugins.sh"

profiles=()
for version in $versions; do
  profiles+=(--profile "g$version")
done

docker compose -f "$here/compose.yml" "${profiles[@]}" up -d --quiet-pull --remove-orphans >&2

wait_for() { # description url [seconds]
  local deadline=$((SECONDS + ${3:-120}))
  until curl -fsS -o /dev/null "$2" 2>/dev/null; do
    if [ "$SECONDS" -ge "$deadline" ]; then
      echo "Timed out waiting for $1 at $2" >&2
      docker compose -f "$here/compose.yml" "${profiles[@]}" logs --tail=80 >&2 || true
      exit 1
    fi
    sleep 0.5
  done
}

wait_for Prometheus "http://127.0.0.1:$PROMETHEUS_PORT/-/ready"

for version in $versions; do
  port=$GRAFANA_PORT
  [ "$version" = 12 ] && port=$GRAFANA12_PORT
  wait_for "Grafana $version" "http://127.0.0.1:$port/api/health"

  # Health is answered before provisioning ends: wait for both datasources and both plugins
  deadline=$((SECONDS + 120))
  until [ "$(curl -fsS -u admin:admin "http://127.0.0.1:$port/api/datasources" 2>/dev/null | grep -o '"uid":"\(prometheus\|horizon-observability-api\)"' | sort -u | wc -l)" = 2 ] \
    && curl -fsS -u admin:admin "http://127.0.0.1:$port/api/plugins/yesoreyeram-infinity-datasource/settings" >/dev/null 2>&1 \
    && curl -fsS -u admin:admin "http://127.0.0.1:$port/api/plugins/marcusolsson-dynamictext-panel/settings" >/dev/null 2>&1; do
    if [ "$SECONDS" -ge "$deadline" ]; then
      echo "Grafana $version on $port did not provision its datasources and plugins" >&2
      docker compose -f "$here/compose.yml" "${profiles[@]}" logs --tail=80 >&2 || true
      exit 1
    fi
    sleep 0.5
  done
done
