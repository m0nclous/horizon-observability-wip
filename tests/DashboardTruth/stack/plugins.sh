#!/usr/bin/env bash
# Fills the Grafana plugin cache of the dashboard truth stack once (design section 12): Infinity 4.1.1 (linux-amd64)
# and Business Text 6.3.0, the versions the render requires (`__requires`). Both Grafanas mount the directory
# read-only with GF_PLUGINS_PREINSTALL_DISABLED=true, so no Grafana downloads anything at start (design X2).
#
#   DASHBOARD_TRUTH_PLUGINS=<dir> tests/DashboardTruth/stack/plugins.sh
#
# Idempotent: a plugin whose plugin.json carries the pinned version is kept. CI caches the directory
# (actions/cache, key grafana-plugins-<versions>).
set -euo pipefail

dir=${DASHBOARD_TRUTH_PLUGINS:?DASHBOARD_TRUTH_PLUGINS must name the plugin cache directory}
mkdir -p "$dir"

plugins=(
  "yesoreyeram-infinity-datasource 4.1.1 https://grafana.com/api/plugins/yesoreyeram-infinity-datasource/versions/4.1.1/download?os=linux&arch=amd64"
  "marcusolsson-dynamictext-panel 6.3.0 https://grafana.com/api/plugins/marcusolsson-dynamictext-panel/versions/6.3.0/download"
)

for entry in "${plugins[@]}"; do
  read -r id version url <<<"$entry"
  manifest="$dir/$id/plugin.json"

  if [ -f "$manifest" ] && grep -q "\"version\": *\"$version\"" "$manifest"; then
    continue
  fi

  work=$(mktemp -d)
  trap 'rm -rf "$work"' EXIT
  echo "Downloading the Grafana plugin $id $version" >&2

  for attempt in 1 2 3; do
    if curl -fsSL --retry 3 --max-time 300 -o "$work/plugin.zip" "$url"; then
      break
    fi
    if [ "$attempt" = 3 ]; then
      echo "Could not download $id $version from $url" >&2
      exit 1
    fi
    sleep 5
  done

  unzip -q "$work/plugin.zip" -d "$work/unpacked"

  if [ ! -f "$work/unpacked/$id/plugin.json" ]; then
    echo "The archive of $id $version has no $id/plugin.json" >&2
    exit 1
  fi

  rm -rf "${dir:?}/$id"
  mv "$work/unpacked/$id" "$dir/$id"
  rm -rf "$work"
  trap - EXIT
done

# Grafana runs as uid 472 and reads the directory only
chmod -R a+rX "$dir"
