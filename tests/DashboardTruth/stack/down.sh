#!/usr/bin/env bash
# Stops the dashboard truth stack of one slot and removes its containers and volumes (only this compose project).
#
#   COMPOSE_PROJECT_NAME=ho-s30 stack/down.sh
set -euo pipefail

here=$(cd "$(dirname "$0")" && pwd)
: "${COMPOSE_PROJECT_NAME:?}"

# The interpolated variables must be set for compose to parse the file; their values do not matter here
export PROMETHEUS_PORT=${PROMETHEUS_PORT:-0} GRAFANA_PORT=${GRAFANA_PORT:-0} GRAFANA12_PORT=${GRAFANA12_PORT:-0} APP_PORT=${APP_PORT:-0}
export DASHBOARD_TRUTH_TOKEN=${DASHBOARD_TRUTH_TOKEN:-unused} DASHBOARD_TRUTH_PLUGINS=${DASHBOARD_TRUTH_PLUGINS:-/nonexistent}

docker compose -f "$here/compose.yml" --profile g12 --profile g13 down -v --remove-orphans --timeout 5 >&2
