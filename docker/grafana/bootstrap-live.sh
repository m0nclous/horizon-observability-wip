#!/bin/sh
# Creates the Grafana service account for "php artisan horizon-observability:stream" (ADDENDUM R4.9) and writes its
# token to docker/grafana/live-token (gitignored), which the compose "stream" service reads.
#
#   docker/grafana/bootstrap-live.sh            Grafana on http://localhost:${GRAFANA_PORT:-3000}, admin:admin
#   GRAFANA_URL=http://grafana.test GRAFANA_AUTH=admin:secret docker/grafana/bootstrap-live.sh
#
# Role Editor: the minimum for live:push on Grafana 13 (12.4.13 accepts any role). Idempotent: an existing account is
# reused (its role set to Editor), and a token file that still authenticates is kept; otherwise the old token of
# that name is deleted and a new one is written. Needs curl and php (for JSON).
set -eu

GRAFANA_URL=${GRAFANA_URL:-http://localhost:${GRAFANA_PORT:-3000}}
GRAFANA_AUTH=${GRAFANA_AUTH:-admin:admin}
NAME=${LIVE_SERVICE_ACCOUNT:-horizon-observability-live}
TOKEN_FILE=${LIVE_TOKEN_FILE:-$(cd "$(dirname "$0")" && pwd)/live-token}

api() { # method path [json body]
    if [ $# -ge 3 ]; then
        curl -fsS -u "$GRAFANA_AUTH" -X "$1" -H 'Content-Type: application/json' -d "$3" "$GRAFANA_URL$2"
    else
        curl -fsS -u "$GRAFANA_AUTH" -X "$1" "$GRAFANA_URL$2"
    fi
}

json() { # php expression over $j (the decoded stdin), printed
    php -r '$j = json_decode(stream_get_contents(STDIN), true); echo '"$1"';'
}

tries=0
until curl -fsS "$GRAFANA_URL/api/health" >/dev/null 2>&1; do
    tries=$((tries + 1))
    if [ "$tries" -ge 90 ]; then
        echo "Grafana is not reachable at $GRAFANA_URL" >&2
        exit 1
    fi
    sleep 2
done

id=$(api GET "/api/serviceaccounts/search?perpage=100&query=$NAME" \
    | json "implode(PHP_EOL, array_column(array_filter(\$j['serviceAccounts'] ?? [], fn (\$a) => (\$a['name'] ?? null) === '$NAME'), 'id'))" \
    | head -n 1)

if [ -z "$id" ]; then
    id=$(api POST /api/serviceaccounts "{\"name\":\"$NAME\",\"role\":\"Editor\"}" | json "\$j['id']")
    echo "Created the service account $NAME (id $id, role Editor)."
else
    role=$(api GET "/api/serviceaccounts/$id" | json "\$j['role'] ?? ''")
    if [ "$role" != "Editor" ]; then
        api PATCH "/api/serviceaccounts/$id" '{"role":"Editor"}' >/dev/null
        echo "Set the role of the service account $NAME (id $id) to Editor."
    fi
fi

# A token authenticates when its permissions include an Editor action: with anonymous access on, Grafana answers an
# invalid token as the anonymous Viewer instead of 401.
valid=$( { [ -s "$TOKEN_FILE" ] && curl -fsS -H "Authorization: Bearer $(cat "$TOKEN_FILE")" "$GRAFANA_URL/api/access-control/user/permissions" 2>/dev/null; } \
    | json "isset(\$j['dashboards:create']) ? 'yes' : 'no'" 2>/dev/null || echo no)

if [ "$valid" = yes ]; then
    echo "Kept the valid token in $TOKEN_FILE."
    exit 0
fi

for token_id in $(api GET "/api/serviceaccounts/$id/tokens" | json "implode(PHP_EOL, array_column(array_filter(\$j, fn (\$t) => (\$t['name'] ?? null) === '$NAME'), 'id'))"); do
    api DELETE "/api/serviceaccounts/$id/tokens/$token_id" >/dev/null
done

key=$(api POST "/api/serviceaccounts/$id/tokens" "{\"name\":\"$NAME\"}" | json "\$j['key']")
umask 077
printf '%s\n' "$key" > "$TOKEN_FILE"
echo "Wrote a new token of $NAME to $TOKEN_FILE."
