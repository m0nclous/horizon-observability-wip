#!/bin/bash
# SessionStart hook for Claude Code cloud sessions: installs the Composer dependencies and starts the services the
# test suite and the dev stack need (Redis on 127.0.0.1:6379, the Docker daemon). Idempotent and non-interactive.
set -euo pipefail

if [ "${CLAUDE_CODE_REMOTE:-}" != "true" ]; then
    exit 0
fi

cd "${CLAUDE_PROJECT_DIR:-$(pwd)}"

log() {
    echo "session-start: $*" >&2
}

export COMPOSER_ALLOW_SUPERUSER=1
export COMPOSER_NO_INTERACTION=1
if [ -n "${CLAUDE_ENV_FILE:-}" ]; then
    {
        echo 'export COMPOSER_ALLOW_SUPERUSER=1'
        echo 'export COMPOSER_NO_INTERACTION=1'
    } >>"$CLAUDE_ENV_FILE"
fi

# A stale or invalid GitHub token in the environment makes Composer fail with "Could not authenticate against
# github.com"; anonymous downloads (or the source fallback below) work.
composer_run() {
    env -u GH_TOKEN -u GITHUB_TOKEN composer "$@"
}

# phpstan/phpstan ships only as a dist archive. When GitHub archives are refused (HTTP 403 in some sandboxes), provide
# it from a shallow clone through a global path repository, then install everything else from source.
phpstan_path_repository() {
    local version dir
    version="$(composer_run show --locked --format=json phpstan/phpstan 2>/dev/null | python3 -c 'import json,sys; print(json.load(sys.stdin)["versions"][0])' 2>/dev/null || true)"
    if [ -z "$version" ]; then
        version="$(composer_run show --all --format=json phpstan/phpstan 2>/dev/null | python3 -c 'import json,sys; print(json.load(sys.stdin)["versions"][0])' 2>/dev/null || true)"
    fi
    [ -n "$version" ] || return 0
    dir="${HOME}/.cache/horizon-observability/phpstan-${version}"
    if [ ! -d "$dir" ]; then
        mkdir -p "$(dirname "$dir")"
        git clone -q --depth 1 --branch "$version" https://github.com/phpstan/phpstan "$dir"
        rm -rf "$dir/.git"
    fi
    composer_run config -g "repositories.phpstan-${version//./-}" \
        "{\"type\":\"path\",\"url\":\"${dir}\",\"options\":{\"versions\":{\"phpstan/phpstan\":\"${version}\"},\"symlink\":false}}"
}

install_dependencies() {
    if [ -f vendor/autoload.php ] && composer_run install --dry-run 2>&1 | grep -q 'Nothing to install'; then
        log "Composer dependencies are up to date"
        return 0
    fi

    log "installing Composer dependencies"
    if ! composer_run install --prefer-dist --no-progress; then
        log "dist downloads failed; installing from source"
        phpstan_path_repository
        composer_run install --prefer-install=source --no-progress
    fi
    # Composer falls back to source for packages whose archive it cannot download; those keep a .git folder, which
    # is not needed after the install and can cost gigabytes.
    find vendor -mindepth 2 -maxdepth 3 -type d -name .git -prune -exec rm -rf {} +
}

start_redis() {
    if command -v redis-cli >/dev/null 2>&1 && redis-cli -p 6379 ping >/dev/null 2>&1; then
        log "Redis is running on 6379"
        return 0
    fi
    if command -v redis-server >/dev/null 2>&1; then
        redis-server --port 6379 --save '' --appendonly no --daemonize yes >/dev/null
        log "started Redis on 6379"
    else
        log "redis-server is not installed; the redis test group needs Redis on 127.0.0.1:6379"
    fi
}

start_docker() {
    command -v dockerd >/dev/null 2>&1 || return 0
    if docker info >/dev/null 2>&1; then
        return 0
    fi
    (dockerd >/tmp/dockerd.log 2>&1 &)
    for _ in $(seq 1 20); do
        docker info >/dev/null 2>&1 && break
        sleep 1
    done
    if docker info >/dev/null 2>&1; then
        log "started the Docker daemon"
    else
        log "the Docker daemon did not start (see /tmp/dockerd.log); the dev stack and e2e checks need it"
    fi
}

# The testbench skeleton log grows with every test run and can fill a small disk.
trim_testbench_log() {
    local log=vendor/orchestra/testbench-core/laravel/storage/logs/laravel.log
    if [ -f "$log" ] && [ "$(wc -c <"$log")" -gt $((50 * 1024 * 1024)) ]; then
        : >"$log"
        log "truncated the testbench log"
    fi
}

install_dependencies
trim_testbench_log
start_redis
start_docker
