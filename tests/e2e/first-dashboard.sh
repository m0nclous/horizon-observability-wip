#!/usr/bin/env bash
# Time to first dashboard. From a fresh `composer create-project laravel/laravel` with
# Horizon, the README quick start alone must reach the dashboard with non-empty Overview tiles within BUDGET_SECONDS:
#
#   composer require sushi-market/horizon-observability
#   php artisan horizon-observability:install --docker --demo
#   then the next steps install prints: php artisan serve --host=0.0.0.0 --port=8000, php artisan horizon,
#   docker compose -f docker-compose.observability.yml up -d, and open http://localhost:3000/d/horizon-observability
#
# The budget counts from `composer require` to the tiles (creating the Laravel app is not part of the quick start;
# the total is printed too). The package is this checkout's dist archive (`git archive`, export-ignore applied), so
# the run also proves that the archive is complete. The browser check is tests/e2e/first-dashboard.mjs.
#
# Usage: tests/e2e/first-dashboard.sh [--grafana 12|13] [--keep]
# Environment:
#   BUDGET_SECONDS   300                 time allowed from composer require to the tiles
#   WORK_DIR         a new temp dir      where the app is created (deleted afterwards unless --keep)
#   PACKAGE_REF      HEAD                the commit of this checkout to install; PACKAGE_PATH installs a directory instead
#   APP_PORT, GRAFANA_PORT, PROMETHEUS_PORT   8000, 3000, 9090
#   PLAYWRIGHT_DIR   this checkout       the directory whose node_modules holds playwright
#   COMPOSER_NO_DEV  0                   1 installs the Laravel app without its dev packages
#   E2E_COMPOSE_FILE                     an extra compose file for the stack, e.g. a sandbox CA for Grafana's plugin
#                                        downloads (never needed on a normal machine or in CI)
# Needs PHP 8.3+ with the redis extension, Composer, Docker with compose, Redis on 127.0.0.1:6379 (the app's default)
# and Node 20+ with Playwright (npm i --no-save playwright@1.56.1 && npx playwright install chromium).
set -euo pipefail

root=$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)
grafana=13
keep=0

while [ $# -gt 0 ]; do
  case "$1" in
    --grafana) grafana=$2; shift 2 ;;
    --grafana=*) grafana=${1#*=}; shift ;;
    --keep) keep=1; shift ;;
    *) echo "unknown option: $1" >&2; exit 2 ;;
  esac
done

budget=${BUDGET_SECONDS:-300}
work=${WORK_DIR:-$(mktemp -d)}
app_port=${APP_PORT:-8000}
export GRAFANA_PORT=${GRAFANA_PORT:-3000}
export PROMETHEUS_PORT=${PROMETHEUS_PORT:-9090}
playwright=${PLAYWRIGHT_DIR:-$root}
app="$work/app"
logs="$work/logs"
pids=()
compose=(docker compose -f docker-compose.observability.yml)

if [ -n "${E2E_COMPOSE_FILE:-}" ]; then
  compose+=(-f "$E2E_COMPOSE_FILE")
fi

mkdir -p "$logs"

log() { printf '\n==> [%ss] %s\n' "$(( $(date +%s) - started ))" "$*"; }

cleanup() {
  status=$?
  set +e

  if [ "$status" -ne 0 ] && [ -d "$app" ]; then
    echo "--- serve log";   tail -n 40 "$logs/serve.log" 2>/dev/null
    echo "--- horizon log"; tail -n 40 "$logs/horizon.log" 2>/dev/null
    echo "--- laravel log"; tail -n 40 "$app/storage/logs/laravel.log" 2>/dev/null
    if [ -f "$app/docker-compose.observability.yml" ]; then
      (cd "$app" && "${compose[@]}" ps)
      (cd "$app" && "${compose[@]}" logs --tail 60 grafana prometheus)
      curl -s "http://localhost:$PROMETHEUS_PORT/api/v1/targets" | head -c 2000; echo
    fi
  fi

  for pid in "${pids[@]}"; do kill "$pid" 2>/dev/null; done
  wait 2>/dev/null

  if [ -f "$app/docker-compose.observability.yml" ]; then
    (cd "$app" && "${compose[@]}" down -v --remove-orphans >/dev/null 2>&1)
  fi

  if [ "$keep" -eq 0 ] && [ -z "${WORK_DIR:-}" ]; then
    rm -rf "$work"
  else
    echo "kept $work"
  fi

  exit "$status"
}
trap cleanup EXIT

started=$(date +%s)

# The package as the dist archive ships it.
package="$work/package"

if [ -n "${PACKAGE_PATH:-}" ]; then
  package=$PACKAGE_PATH
else
  mkdir -p "$package"
  git -C "$root" archive --format=tar "${PACKAGE_REF:-HEAD}" | tar -x -C "$package"
fi

log "Prerequisite: a fresh Laravel application with Horizon"
create=(composer create-project laravel/laravel "$app" --no-interaction --prefer-dist --no-progress --quiet)

if [ "${COMPOSER_NO_DEV:-0}" = 1 ]; then
  create+=(--no-dev)          # smaller installs where only source clones are possible; require honours the variable
fi

"${create[@]}"
cd "$app"
composer require laravel/horizon --no-interaction --no-progress --quiet
php artisan horizon:install --no-interaction

# Until the package is on Packagist: the archive as a path repository, with the version Packagist would give it.
composer config repositories.horizon-observability \
  "{\"type\": \"path\", \"url\": \"$package\", \"options\": {\"symlink\": false, \"versions\": {\"sushi-market/horizon-observability\": \"0.1.0\"}}}"

quickstart=$(date +%s)
log "Quick start 1: composer require sushi-market/horizon-observability"
composer require sushi-market/horizon-observability --no-interaction --no-progress

log "Quick start 2: php artisan horizon-observability:install --docker --demo"
extra=()

if [ "$app_port" != 8000 ]; then
  extra=(--app-url="http://host.docker.internal:$app_port")     # only when port 8000 is taken locally
fi

php artisan horizon-observability:install --docker --demo --grafana="$grafana" --no-interaction "${extra[@]}" | tee "$logs/install.log"

log "Next steps printed by install: serve, horizon, docker compose up"
grep -q 'php artisan serve --host=0.0.0.0' "$logs/install.log"
grep -q 'docker compose -f docker-compose.observability.yml up -d' "$logs/install.log"
grep -q 'Open http://localhost:3000/d/horizon-observability' "$logs/install.log"
php artisan serve --host=0.0.0.0 --port="$app_port" > "$logs/serve.log" 2>&1 &
pids+=($!)
php artisan horizon > "$logs/horizon.log" 2>&1 &
pids+=($!)
"${compose[@]}" up -d

log "Quick start 3: open http://localhost:$GRAFANA_PORT/d/horizon-observability"
remaining=$(( budget - ($(date +%s) - quickstart) ))

if [ "$remaining" -le 0 ]; then
  echo "the budget of $budget s ran out before Grafana started" >&2
  exit 1
fi

PLAYWRIGHT_DIR=$playwright node "$root/tests/e2e/first-dashboard.mjs" \
  --grafana "http://localhost:$GRAFANA_PORT" --timeout "$remaining" --screenshot "$logs/first-dashboard.png"

elapsed=$(( $(date +%s) - quickstart ))
total=$(( $(date +%s) - started ))
echo
echo "time to first dashboard: $elapsed s (budget $budget s; $total s with creating the Laravel app), Grafana $grafana"

if [ "$elapsed" -gt "$budget" ]; then
  echo "over budget" >&2
  exit 1
fi

# A second install changes nothing (the SQLite database changes with every job, so it does not count).
files() {
  find . \( -path ./vendor -o -path ./storage -o -path ./node_modules \) -prune -o -type f ! -name '*.sqlite' -print0 \
    | sort -z | xargs -0 sha1sum
}
before=$(files)
php artisan horizon-observability:install --docker --grafana="$grafana" --no-interaction "${extra[@]}" > "$logs/install-again.log"
after=$(files)

if [ "$before" != "$after" ]; then
  echo "a second install changed files:" >&2
  diff <(echo "$before") <(echo "$after") >&2 || true
  exit 1
fi

echo "a second install changed nothing"
