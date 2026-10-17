#!/usr/bin/env bash
#
# Mirror planning/TASKS.md onto GitHub issues and a GitHub Projects (v2) board, stop the line while CI is red on the
# integration branch, and keep a flow report.
#
# planning/TASKS.md stays the source of truth.
# - `sync` keeps one issue "[Txx] Title" per "### Txx Title — `status`" heading, labelled task, "phase: <phase>", the
#   status label (in progress, testing, needs owner) and, for todo tasks, ready (every dependency done) or blocked; the
#   issue is closed while the task is done. Every "QA:", "Owner question:" and "Owner answer:" line becomes one comment
#   (questions mention the owner and are posted with BOARD_NOTIFY_TOKEN, so that the owner is notified), and a blocked
#   task gets one "Blocked by ..." comment, edited when the missing dependencies change. On the board, the card sits
#   in the Status column of the task and carries the fields Phase, Area (tools/board/areas.tsv), QA rounds, Started
#   and Finished (from the "board: Txx -> ..." commits); missing fields are created, a field that cannot be written
#   (another type) is skipped with a warning.
# - `report` rewrites the body of the issue "[Board] Flow report": CI state, WIP per column against the limits, and
#   per task status, area, QA rounds, start, finish and cycle time, plus the QA rejection rate.
# - `ci` reads a completed run of the tests workflow (CI_* variables): red opens "[CI] The integration branch is red"
#   (label stop-the-line) or comments on it once per run; green closes it. A run of an older commit decides only when
#   the commits after it change nothing but the task list and the screenshots (which the tests workflow ignores).
#
# Usage:
#   tools/board/board.sh sync [--dry-run]
#   tools/board/board.sh report [--dry-run]
#   tools/board/board.sh ci [--dry-run]
#   tools/board/board.sh list
#
# --dry-run prints every write instead of making it; without GH_TOKEN it also skips every read (offline).
#
# Environment:
#   BOARD_REPO              owner/repo of the issues (default: the current gh repository)
#   BOARD_OWNER             owner of the project board (user or organization); empty = issues only, no board
#   BOARD_NUMBER            project number from the board URL (.../projects/<number>); empty = issues only, no board
#   BOARD_BRANCH            branch used for links to TASKS.md (default: the current git branch)
#   BOARD_MENTION           login that owner questions mention (default: the owner of BOARD_REPO)
#   BOARD_NOTIFY_TOKEN      token for the owner-question comments (GitHub notifies nobody of their own comments, so
#                           the workflow passes github-actions' GITHUB_TOKEN); empty = GH_TOKEN
#   BOARD_AREAS_FILE        task -> area map (default: tools/board/areas.tsv next to this script)
#   BOARD_WIP_IN_PROGRESS   WIP limit of In Progress for the report (default 2, as in set-status.sh)
#   BOARD_WIP_TESTING       WIP limit of Testing for the report (default 3, as in set-status.sh)
#   TASKS_FILE              path of the task list (default: planning/TASKS.md)
#   GH_TOKEN                token for gh (classic: repo, project, read:org, read:discussion)
#   CI_CONCLUSION, CI_RUN_ID, CI_RUN_ATTEMPT, CI_RUN_URL, CI_HEAD_SHA
#                           the completed tests run, for `ci` (set by .github/workflows/board.yml)

set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source-path=SCRIPTDIR source=lib.sh
source "$HERE/lib.sh"

TASKS_FILE="${TASKS_FILE:-planning/TASKS.md}"
AREAS_FILE="${BOARD_AREAS_FILE:-$HERE/areas.tsv}"
WIP_IN_PROGRESS="${BOARD_WIP_IN_PROGRESS:-2}"
WIP_TESTING="${BOARD_WIP_TESTING:-3}"

TASK_LABEL="task"
READY_LABEL="ready"
BLOCKED_LABEL="blocked"
CI_LABEL="stop-the-line"
CI_TITLE="[CI] The integration branch is red"
CI_MARKER="<!-- board:stop-the-line -->"
REPORT_LABEL="report"
REPORT_TITLE="[Board] Flow report"
REPORT_MARKER="<!-- board:report -->"
BLOCKED_MARKER="<!-- blocked:"
# Paths that the tests workflow ignores on push (.github/workflows/tests.yml, paths-ignore): commits that change only
# these leave the verdict of the previous tests run standing.
BOARD_ONLY_PATHS=("$TASKS_FILE" "$(dirname "$TASKS_FILE")/screenshots")

STATUS_ORDER=("todo" "in progress" "testing" "needs owner" "done")
# Status in TASKS.md => Status option on the board.
declare -A COLUMN=([todo]="Todo" [in progress]="In Progress" [testing]="Testing" [needs owner]="Needs owner" [done]="Done")
# Status in TASKS.md => issue label (statuses without a label have none).
declare -A STATUS_LABEL=([in progress]="in progress" [testing]="testing" [needs owner]="needs owner")
declare -A LABEL_COLOR=(
    [task]="0e8a16" [in progress]="fbca04" [testing]="1d76db" [needs owner]="d93f0b"
    [ready]="c2e0c6" [blocked]="e99695" [stop-the-line]="b60205" [report]="5319e7"
)
declare -A LABEL_TEXT=(
    [task]="Mirrors a task of planning/TASKS.md"
    [in progress]="Task status: in progress"
    [testing]="Task status: testing"
    [needs owner]="Task status: needs owner (waits for an answer of the owner)"
    [ready]="Todo task whose dependencies are all done"
    [blocked]="Todo task waiting for dependencies (listed in a comment)"
    [stop-the-line]="The integration branch is red: nothing moves to done"
    [report]="Flow report of the board"
)

# Board fields besides Status, created when missing.
FIELDS=("Phase" "Area" "QA rounds" "Started" "Finished")
declare -A FIELD_KIND=([Status]="single" [Phase]="single" [Area]="single" [QA rounds]="number" [Started]="date" [Finished]="date")
declare -A DATA_TYPE=([single]="SINGLE_SELECT" [number]="NUMBER" [date]="DATE")
# The type that `gh project field-list` reports for each kind (it does not report the data type of a plain field).
declare -A FIELD_TYPE_OF=([single]="ProjectV2SingleSelectField" [number]="ProjectV2Field" [date]="ProjectV2Field")

# gh's --jq prints raw strings; @tsv would escape backslashes and tabs. Fields are separated by US (\u001f), lists
# inside a field by RS (\u001e).
US=$'\x1f'
RS=$'\x1e'


usage() {
    sed -n '/^# Usage:/,/^# --dry-run/p' "$0" | sed 's/^# \{0,1\}//'
    exit "${1:-0}"
}

die() {
    echo "board: $*" >&2
    exit 1
}

warn() {
    echo "board: warning: $*" >&2
}

# Runs a write, or prints it in a dry run (to the original stdout: callers silence the command's own output).
run() {
    if [[ "$DRY_RUN" == 1 ]]; then
        {
            printf 'dry-run:'
            printf ' %q' "$@"
            printf '\n'
        } >&3
    else
        "$@"
    fi
}

offline() {
    [[ "$DRY_RUN" == 1 && -z "${GH_TOKEN:-}" ]]
}

note_hash() {
    if command -v sha1sum >/dev/null; then
        printf '%s' "$1" | sha1sum | cut -c1-12
    else
        printf '%s' "$1" | shasum -a 1 | cut -c1-12
    fi
}

setup_repo() {
    if offline; then
        REPO="${BOARD_REPO:-owner/repo}"
    else
        command -v gh >/dev/null || die "gh is not installed"
        REPO="${BOARD_REPO:-$(gh repo view --json nameWithOwner --jq .nameWithOwner)}"
    fi
    BRANCH="${BOARD_BRANCH:-$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo master)}"
    TASKS_URL="https://github.com/$REPO/blob/$BRANCH/$TASKS_FILE"
    MENTION="${BOARD_MENTION:-${REPO%%/*}}"
    BOARD_OWNER="${BOARD_OWNER:-}"
    BOARD_NUMBER="${BOARD_NUMBER:-}"
}

# --- Local state: tasks, areas, QA rounds, history ---------------------------------------------------------------

load_tasks() {
    board_load_tasks "$TASKS_FILE" || exit 1
    [[ ${#TASK_IDS[@]} -gt 0 ]] || die "no tasks found in $TASKS_FILE"
    board_load_areas "$AREAS_FILE" || exit 1

    PHASES=()
    local id no_area=()
    for id in "${TASK_IDS[@]}"; do
        [[ -z "${TASK_PHASE[$id]}" || " ${PHASES[*]} " == *" ${TASK_PHASE[$id]} "* ]] || PHASES+=("${TASK_PHASE[$id]}")
        [[ -n "${TASK_AREA[$id]:-}" ]] || no_area+=("$id")
    done
    if [[ ${#no_area[@]} -gt 0 && -f "$AREAS_FILE" ]]; then
        warn "no area for ${no_area[*]} in $AREAS_FILE; add a line \"Txx<TAB>area\" for every task (the Area field stays empty)"
    fi

    # A QA round is a distinct "round N" of a task's QA lines with a verdict (rejected wins), see lib.sh.
    board_count_rounds
}

# Started = the first "board: Txx -> in progress" commit, Finished = the last "board: Txx -> done" commit (author
# dates, UTC). Started is shown unless the task is todo, Finished only while it is done.
load_history() {
    declare -gA STARTED_AT=() STARTED_ON=() FINISHED_AT=() FINISHED_ON=()
    local out at on subject id to
    if ! out="$(TZ=UTC git log --reverse --format='%at%x09%ad%x09%s' --date=format-local:%Y-%m-%d -- "$TASKS_FILE" 2>/dev/null)"; then
        warn "no git history for $TASKS_FILE; Started and Finished stay empty"
        return 0
    fi
    if [[ "$(git rev-parse --is-shallow-repository 2>/dev/null || echo false)" == "true" ]]; then
        warn "shallow clone: Started and Finished may miss older commits (check out with fetch-depth 0)"
    fi
    local re='^board: (T[0-9]+) -> (todo|in progress|testing|needs owner|done)( \(.*\))?$'
    while IFS=$'\t' read -r at on subject; do
        [[ "$subject" =~ $re ]] || continue
        id="${BASH_REMATCH[1]}"
        to="${BASH_REMATCH[2]}"
        if [[ "$to" == "in progress" && -z "${STARTED_AT[$id]:-}" ]]; then
            STARTED_AT[$id]="$at"
            STARTED_ON[$id]="$on"
        elif [[ "$to" == "done" ]]; then
            FINISHED_AT[$id]="$at"
            FINISHED_ON[$id]="$on"
        fi
    done <<<"$out"
    for id in "${TASK_IDS[@]}"; do
        if [[ "${TASK_STATUS[$id]}" == "todo" ]]; then
            unset "STARTED_AT[$id]" "STARTED_ON[$id]"
        fi
        if [[ "${TASK_STATUS[$id]}" != "done" ]]; then
            unset "FINISHED_AT[$id]" "FINISHED_ON[$id]"
        fi
    done
}

# Cycle days of a done task with a recorded start (one decimal), or nothing.
cycle_days() {
    local id="$1"
    [[ -n "${STARTED_AT[$id]:-}" && -n "${FINISHED_AT[$id]:-}" ]] || return 0
    awk -v a="${STARTED_AT[$id]}" -v b="${FINISHED_AT[$id]}" 'BEGIN { printf "%.1f\n", (b - a) / 86400 }'
}

# --- Labels and issues ---------------------------------------------------------------------------------------------

load_labels() {
    declare -gA LABEL_HAVE=()
    offline && return 0
    local out name color description
    out="$(gh label list --repo "$REPO" --limit 1000 --json name,color,description \
        --jq '.[] | [.name, (.color | ascii_downcase), (.description // "")] | map(gsub("[\n\u001f]"; " ")) | join("\u001f")')"
    while IFS="$US" read -r name color description; do
        [[ -z "$name" ]] || LABEL_HAVE[$name]="$color $description"
    done <<<"$out"
}

# ensure_label <name> [<color> <description>]: creates or updates the label unless it already matches.
ensure_label() {
    local name="$1" color="${2:-${LABEL_COLOR[$1]}}" description="${3:-${LABEL_TEXT[$1]}}"
    [[ "${LABEL_HAVE[$name]:-}" != "$color $description" ]] || return 0
    echo "label   $name"
    run gh label create "$name" --repo "$REPO" --color "$color" --description "$description" --force >/dev/null
    LABEL_HAVE[$name]="$color $description"
}

# Loads the task issues ("[Txx] ..." with the task label) into ISSUE_NUMBER, ISSUE_STATE, ISSUE_URL, ISSUE_TITLE and
# ISSUE_LABELS (RS-separated names). The newest issue of a task wins.
load_issues() {
    declare -gA ISSUE_NUMBER=() ISSUE_STATE=() ISSUE_URL=() ISSUE_TITLE=() ISSUE_LABELS=()
    offline && return 0
    local out number state url title labels id re='^\[(T[0-9]+)\] '
    out="$(gh issue list --repo "$REPO" --state all --label "$TASK_LABEL" --limit 1000 --json number,state,url,title,labels \
        --jq '.[] | [(.number | tostring), .state, .url, .title, ([.labels[].name] | join("\u001e"))] | map(gsub("[\n\u001f]"; " ")) | join("\u001f")')"
    while IFS="$US" read -r number state url title labels; do
        [[ "$title" =~ $re ]] || continue
        id="${BASH_REMATCH[1]}"
        [[ -z "${ISSUE_NUMBER[$id]:-}" ]] || continue
        ISSUE_NUMBER[$id]="$number"
        ISSUE_STATE[$id]="$state"
        ISSUE_URL[$id]="$url"
        ISSUE_TITLE[$id]="$title"
        ISSUE_LABELS[$id]="$labels"
    done <<<"$out"
}

contains() {
    local needle="$1" item
    shift
    for item in "$@"; do
        [[ "$item" != "$needle" ]] || return 0
    done
    return 1
}

# Labels that the sync owns: it adds them when they apply and removes them when they do not.
managed_label() {
    case "$1" in
        "phase: "* | "$READY_LABEL" | "$BLOCKED_LABEL" | "in progress" | "testing" | "needs owner") return 0 ;;
        *) return 1 ;;
    esac
}

# The comments of an issue as a JSON array of {url, body}.
comments_of() {
    gh issue view "$1" --repo "$REPO" --json comments --jq '[.comments[] | {url, body}]'
}

# Open stop-the-line issues, oldest first.
open_ci_issues() {
    offline && return 0
    gh issue list --repo "$REPO" --state open --label "$CI_LABEL" --limit 100 --json number --jq '.[].number' | sort -n
}

# --- Board ----------------------------------------------------------------------------------------------------------

# gh cannot tell the owner type of a user-owned board without the read:org scope ("unknown owner type"); "@me"
# addresses the token owner's boards directly.
normalize_owner() {
    if [[ "$BOARD_OWNER" != "@me" && "$(gh api user --jq .login 2>/dev/null || true)" == "$BOARD_OWNER" ]]; then
        BOARD_OWNER="@me"
    fi
}

load_fields() {
    declare -gA FIELD_ID=() FIELD_TYPE=() OPTION_ID=()
    local json name id type option
    json="$(gh project field-list "$BOARD_NUMBER" --owner "$BOARD_OWNER" --limit 100 --format json)"
    while IFS="$US" read -r name id type; do
        [[ -z "$name" ]] || {
            FIELD_ID[$name]="$id"
            FIELD_TYPE[$name]="$type"
        }
    done <<<"$(jq -r '.fields[] | [.name, .id, .type] | map(gsub("[\n\u001f]"; " ")) | join("\u001f")' <<<"$json")"
    while IFS="$US" read -r name option id; do
        [[ -z "$name" ]] || OPTION_ID[$name|$option]="$id"
    done <<<"$(jq -r '.fields[] | .name as $field | (.options // [])[] | [$field, .name, .id] | map(gsub("[\n\u001f]"; " ")) | join("\u001f")' <<<"$json")"
    [[ -n "${FIELD_ID[Status]:-}" ]] || die "the board has no Status field"
    [[ "${FIELD_TYPE[Status]}" == "ProjectV2SingleSelectField" ]] || die "the board's Status field is not a single select field"
}

ensure_fields() {
    local name created=0 args
    for name in "${FIELDS[@]}"; do
        [[ -z "${FIELD_ID[$name]:-}" ]] || continue
        [[ "$name" != "Phase" || ${#PHASES[@]} -gt 0 ]] || continue
        args=(project field-create "$BOARD_NUMBER" --owner "$BOARD_OWNER" --name "$name" --data-type "${DATA_TYPE[${FIELD_KIND[$name]}]}")
        case "$name" in
            Phase) args+=(--single-select-options "$(printf '%s,' "${PHASES[@]}" | sed 's/,$//')") ;;
            Area) args+=(--single-select-options "${BOARD_AREAS// /,}") ;;
        esac
        echo "field   create $name"
        run gh "${args[@]}" >/dev/null
        created=1
    done
    if [[ "$created" == 1 && "$DRY_RUN" != 1 ]]; then
        load_fields
    fi

    # A field of the same name but another type (made by hand) is left alone: one field must not stop the sync.
    declare -gA FIELD_SKIP=()
    for name in "${FIELDS[@]}"; do
        [[ -n "${FIELD_ID[$name]:-}" ]] || continue
        if [[ "${FIELD_TYPE[$name]}" != "${FIELD_TYPE_OF[${FIELD_KIND[$name]}]}" ]]; then
            warn "the board's field \"$name\" is a ${FIELD_TYPE[$name]}, not a ${DATA_TYPE[${FIELD_KIND[$name]}]} field; the sync leaves it alone (delete or rename it, and the next sync creates it)"
            FIELD_SKIP[$name]=1
        fi
    done

    # Single-select options cannot be added from gh: name the missing ones once.
    local status phase area id
    for status in "${STATUS_ORDER[@]}"; do
        [[ -n "${OPTION_ID[Status|${COLUMN[$status]}]:-}" ]] ||
            warn "the board's Status field has no option \"${COLUMN[$status]}\"; add it in the board settings"
    done
    if [[ -n "${FIELD_ID[Phase]:-}" && -z "${FIELD_SKIP[Phase]:-}" ]]; then
        for phase in "${PHASES[@]}"; do
            [[ -n "${OPTION_ID[Phase|$phase]:-}" ]] ||
                warn "the board's Phase field has no option \"$phase\"; add it in the board settings"
        done
    fi
    if [[ -n "${FIELD_ID[Area]:-}" && -z "${FIELD_SKIP[Area]:-}" ]]; then
        for area in $(for id in "${TASK_IDS[@]}"; do echo "${TASK_AREA[$id]:-}"; done | sort -u); do
            [[ -n "${OPTION_ID[Area|$area]:-}" ]] ||
                warn "the board's Area field has no option \"$area\"; add it in the board settings"
        done
    fi
}

# Loads the board items into ITEM_ID (by content URL) and ITEM_VALUE ("<url>|<lowercase field name>").
load_items() {
    declare -gA ITEM_ID=() ITEM_VALUE=()
    local json url id key value
    json="$(gh project item-list "$BOARD_NUMBER" --owner "$BOARD_OWNER" --limit 1000 --format json)"
    while IFS="$US" read -r url id; do
        [[ -z "$url" ]] || ITEM_ID[$url]="$id"
    done <<<"$(jq -r '.items[] | select(.content.url != null) | [.content.url, .id] | join("\u001f")' <<<"$json")"
    while IFS="$US" read -r url key value; do
        [[ -z "$url" ]] || ITEM_VALUE[$url|$key]="$value"
    done <<<"$(jq -r '.items[] | select(.content.url != null) | .content.url as $url | to_entries[]
        | select(.value | type == "string" or type == "number")
        | [$url, (.key | ascii_downcase), (.value | tostring)] | map(gsub("[\n\u001f]"; " ")) | join("\u001f")' <<<"$json")"
}

load_board() {
    declare -gA FIELD_ID=() FIELD_TYPE=() FIELD_SKIP=() OPTION_ID=() ITEM_ID=() ITEM_VALUE=()
    [[ -n "$BOARD_NUMBER" ]] || return 0
    [[ -n "$BOARD_OWNER" ]] || die "BOARD_NUMBER needs BOARD_OWNER"
    offline && return 0
    normalize_owner
    PROJECT_ID="$(gh project view "$BOARD_NUMBER" --owner "$BOARD_OWNER" --format json --jq .id)"
    load_fields
    ensure_fields
    load_items
}

# set_field <id> <item> <url> <field> <value>: sets (or clears, for an empty value) one field unless it already holds it.
set_field() {
    local id="$1" item="$2" url="$3" name="$4" value="$5" key option="" args field error
    key="${name,,}"
    [[ -z "${FIELD_SKIP[$name]:-}" ]] || return 0
    [[ "${ITEM_VALUE[$url|$key]:-}" != "$value" ]] || return 0
    field="${FIELD_ID[$name]:-}"
    if [[ -n "$value" && -n "$field" && "${FIELD_KIND[$name]}" == "single" ]]; then
        option="${OPTION_ID[$name|$value]:-}"
        if [[ -z "$option" ]]; then
            # Named once by ensure_fields; the card keeps its value.
            if [[ "$name" == "Status" ]]; then
                warn "$url stays in its current column (no \"$value\" option)"
            fi
            return 0
        fi
    fi
    if [[ "$DRY_RUN" == 1 ]]; then
        if [[ -n "$value" ]]; then
            echo "dry-run: set $name of $url to \"$value\""
        else
            echo "dry-run: clear $name of $url"
        fi
        return 0
    fi
    [[ -n "$field" ]] || return 0
    args=(project item-edit --id "$item" --project-id "$PROJECT_ID" --field-id "$field")
    if [[ -z "$value" ]]; then
        args+=(--clear)
    else
        case "${FIELD_KIND[$name]}" in
            single) args+=(--single-select-option-id "$option") ;;
            number) args+=(--number "$value") ;;
            date) args+=(--date "$value") ;;
        esac
    fi
    echo "field   $id $name = ${value:-(empty)}"
    if ! error="$(gh "${args[@]}" 2>&1 >/dev/null)"; then
        # Named once, then skipped for the rest of the run, so that one field cannot stop the status sync.
        warn "could not write the board field \"$name\" (${error//$'\n'/ }); the sync skips it for this run"
        FIELD_SKIP[$name]=1
        return 0
    fi
    ITEM_VALUE[$url|$key]="$value"
}

place_on_board() {
    local id="$1" url="$2" status="$3" item
    item="${ITEM_ID[$url]:-}"
    if [[ -z "$item" ]]; then
        echo "board   $id add $url"
        if [[ "$DRY_RUN" == 1 ]]; then
            run gh project item-add "$BOARD_NUMBER" --owner "$BOARD_OWNER" --url "$url"
            item="<new>"
        else
            item="$(gh project item-add "$BOARD_NUMBER" --owner "$BOARD_OWNER" --url "$url" --format json --jq .id)"
            ITEM_ID[$url]="$item"
        fi
    fi
    set_field "$id" "$item" "$url" "Status" "${COLUMN[$status]}"
    set_field "$id" "$item" "$url" "Phase" "${TASK_PHASE[$id]}"
    set_field "$id" "$item" "$url" "Area" "${TASK_AREA[$id]:-}"
    set_field "$id" "$item" "$url" "QA rounds" "${QA_ROUNDS[$id]}"
    set_field "$id" "$item" "$url" "Started" "${STARTED_ON[$id]:-}"
    set_field "$id" "$item" "$url" "Finished" "${FINISHED_ON[$id]:-}"
}

# --- sync -----------------------------------------------------------------------------------------------------------

task_body() {
    local id="$1"
    cat <<EOF
Mirrors task **$id** of [\`planning/TASKS.md\`]($TASKS_URL) (scope, dependencies, acceptance and QA verdicts live there).

Status, labels, QA and owner comments and the board card are synced from that file by \`tools/board/board.sh\`; change the file (\`tools/board/set-status.sh\`), not this issue.
EOF
}

# Posts every note of the task that the issue does not have yet (hidden markers make it idempotent), and keeps the
# task's one "Blocked by" comment current: posted for a blocked todo task, edited when the missing dependencies
# change, turned into "Ready" when they are all done. Owner questions go out with BOARD_NOTIFY_TOKEN when it is set:
# GitHub does not notify the owner of comments made with the owner's own token.
sync_comments() {
    local id="$1" number="$2" fresh="$3" missing="$4" post_blocked="$5" had_blocked="$6"
    local notes="${TASK_NOTES[$id]:-}" comments="[]" existing kind text marker body url current
    [[ -n "$notes" || -n "$missing" || "$had_blocked" == 1 ]] || return 0
    if [[ "$fresh" == 0 ]] && ! offline; then
        comments="$(comments_of "$number")"
    fi
    existing="$(jq -r '.[].body' <<<"$comments")"

    while IFS=$'\t' read -r kind text; do
        [[ -n "$kind" ]] || continue
        case "$kind" in
            qa)
                marker="<!-- qa:$(note_hash "$text") -->"
                body="**QA:** $text"
                ;;
            question)
                marker="<!-- owner-question:$(note_hash "$text") -->"
                body="**Owner question** for @$MENTION: $text"
                ;;
            answer)
                marker="<!-- owner-answer:$(note_hash "$text") -->"
                body="**Owner answer:** $text"
                ;;
        esac
        [[ "$existing" != *"$marker"* ]] || continue
        echo "comment $id: $kind: $text"
        if [[ "$kind" == "question" && -n "${BOARD_NOTIFY_TOKEN:-}" ]] &&
            GH_TOKEN="$BOARD_NOTIFY_TOKEN" run gh issue comment "$number" --repo "$REPO" --body "$body"$'\n\n'"$marker" >/dev/null; then
            continue
        elif [[ "$kind" == "question" && -n "${BOARD_NOTIFY_TOKEN:-}" ]]; then
            warn "could not post the owner question of $id with BOARD_NOTIFY_TOKEN; posted with GH_TOKEN, so the owner is not notified"
        fi
        run gh issue comment "$number" --repo "$REPO" --body "$body"$'\n\n'"$marker" >/dev/null
    done <<<"$notes"

    url="$(jq -r --arg marker "$BLOCKED_MARKER" '[.[] | select(.body | contains($marker))] | last | .url // empty' <<<"$comments")"
    if [[ -n "$missing" ]]; then
        marker="$BLOCKED_MARKER${missing// /,} -->"
        body="**Blocked** by ${missing// /, }: this task becomes ready when they are done."
    elif [[ -n "$url" ]]; then
        marker="$BLOCKED_MARKER -->"
        body="**Ready:** every dependency is done."
    else
        return 0
    fi
    if [[ -n "$url" ]]; then
        current="$(jq -r --arg url "$url" '.[] | select(.url == $url) | .body' <<<"$comments")"
        [[ "$current" != *"$marker"* ]] || return 0
        if [[ -n "$missing" ]]; then
            echo "comment $id: edit: blocked by $missing"
        else
            echo "comment $id: edit: ready"
        fi
        run gh api --method PATCH "repos/$REPO/issues/comments/${url##*#issuecomment-}" -f "body=$body"$'\n\n'"$marker" >/dev/null
    elif [[ "$post_blocked" == 1 ]]; then
        echo "comment $id: blocked by $missing"
        run gh issue comment "$number" --repo "$REPO" --body "$body"$'\n\n'"$marker" >/dev/null
    fi
}

sync_task() {
    local id="$1"
    local title="${TASK_TITLE[$id]}" status="${TASK_STATUS[$id]}" phase="${TASK_PHASE[$id]}"
    local full="[$id] $title" number state url fresh=0 missing label args had_blocked=0
    local -a want=("$TASK_LABEL") have=()
    [[ -z "$phase" ]] || want+=("phase: $phase")
    [[ -z "${STATUS_LABEL[$status]:-}" ]] || want+=("${STATUS_LABEL[$status]}")
    missing="$(board_missing_deps "$id")"
    if [[ "$status" == "todo" ]]; then
        if [[ -n "$missing" ]]; then
            want+=("$BLOCKED_LABEL")
        else
            want+=("$READY_LABEL")
        fi
    fi

    number="${ISSUE_NUMBER[$id]:-}"
    if [[ -z "$number" ]]; then
        echo "create  $full ($status)"
        args=(--repo "$REPO" --title "$full" --body "$(task_body "$id")")
        for label in "${want[@]}"; do
            args+=(--label "$label")
        done
        if [[ "$DRY_RUN" == 1 ]]; then
            run gh issue create "${args[@]}"
            url="https://github.com/$REPO/issues/<new>"
        else
            url="$(gh issue create "${args[@]}")"
        fi
        number="${url##*/}"
        state="OPEN"
        fresh=1
    else
        state="${ISSUE_STATE[$id]}"
        url="${ISSUE_URL[$id]}"
        echo "update  $full ($status) #$number"
        args=()
        [[ "${ISSUE_TITLE[$id]}" == "$full" ]] || args+=(--title "$full")
        IFS="$RS" read -ra have <<<"${ISSUE_LABELS[$id]}"
        ! contains "$BLOCKED_LABEL" "${have[@]}" || had_blocked=1
        for label in "${want[@]}"; do
            if ! contains "$label" "${have[@]}"; then
                args+=(--add-label "$label")
            fi
        done
        for label in "${have[@]}"; do
            if managed_label "$label" && ! contains "$label" "${want[@]}"; then
                args+=(--remove-label "$label")
            fi
        done
        if [[ ${#args[@]} -gt 0 ]]; then
            run gh issue edit "$number" --repo "$REPO" "${args[@]}" >/dev/null
        fi
    fi

    if [[ "$status" == "done" && "$state" == "OPEN" ]]; then
        run gh issue close "$number" --repo "$REPO" --reason completed >/dev/null
    elif [[ "$status" != "done" && "$state" == "CLOSED" ]]; then
        run gh issue reopen "$number" --repo "$REPO" >/dev/null
    fi

    sync_comments "$id" "$number" "$fresh" "$missing" "$([[ "$status" == "todo" ]] && echo 1 || echo 0)" "$had_blocked"

    if [[ -n "$BOARD_NUMBER" ]]; then
        place_on_board "$id" "$url" "$status"
    fi
}

cmd_sync() {
    setup_repo
    load_tasks
    load_history
    load_labels

    local label phase id
    for label in "$TASK_LABEL" "in progress" "testing" "needs owner" "$READY_LABEL" "$BLOCKED_LABEL"; do
        ensure_label "$label"
    done
    for phase in "${PHASES[@]}"; do
        ensure_label "phase: $phase" "c5def5" "Phase $phase of planning/TASKS.md"
    done

    load_issues
    load_board
    for id in "${TASK_IDS[@]}"; do
        sync_task "$id"
    done
}

# --- report ---------------------------------------------------------------------------------------------------------

build_report() {
    local ci="$1" id status column count limit cell title missing note
    local ready=0 blocked=0 rounds=0 rejected=0 finished=0 cycles=""
    declare -A per=()
    for id in "${TASK_IDS[@]}"; do
        status="${TASK_STATUS[$id]}"
        per[$status]=$((${per[$status]:-0} + 1))
        if [[ "$status" == "todo" ]]; then
            if [[ -n "$(board_missing_deps "$id")" ]]; then
                blocked=$((blocked + 1))
            else
                ready=$((ready + 1))
            fi
        fi
        rounds=$((rounds + QA_ROUNDS[$id]))
        rejected=$((rejected + QA_REJECTED[$id]))
        if [[ -n "$(cycle_days "$id")" ]]; then
            finished=$((finished + 1))
            cycles+="$(cycle_days "$id") "
        fi
    done

    echo "$REPORT_MARKER"
    echo "# Flow report"
    echo
    echo "Rewritten from [\`$TASKS_FILE\`]($TASKS_URL) by \`tools/board/board.sh report\` after every board sync and CI run; edits here are overwritten."
    echo
    echo "## Integration branch"
    echo
    case "$ci" in
        unknown) echo "Unknown (offline dry run)." ;;
        "") echo "Green: no open \`$CI_LABEL\` issue." ;;
        *)
            note=""
            for id in $ci; do
                note+="${note:+, }#$id"
            done
            echo "**Red:** $note (stop the line). Nothing moves to \`done\` until the \`tests\` workflow is green again."
            ;;
    esac
    echo
    echo "## WIP"
    echo
    echo "| Column | Tasks | WIP limit |"
    echo "|---|---|---|"
    for status in "${STATUS_ORDER[@]}"; do
        column="${COLUMN[$status]}"
        count="${per[$status]:-0}"
        limit=""
        case "$status" in
            "in progress") limit="$WIP_IN_PROGRESS" ;;
            testing) limit="$WIP_TESTING" ;;
        esac
        cell="$count"
        [[ "$status" != "todo" ]] || cell="$count ($ready ready, $blocked blocked)"
        [[ -z "$limit" || "$count" -le "$limit" ]] || cell="$count (over the limit)"
        echo "| $column | $cell | $limit |"
    done
    echo
    echo "## Waiting for the owner"
    echo
    local waiting=0 kind text question
    for id in "${TASK_IDS[@]}"; do
        [[ "${TASK_STATUS[$id]}" == "needs owner" ]] || continue
        question=""
        while IFS=$'\t' read -r kind text; do
            [[ "$kind" != "question" ]] || question="$text"
        done <<<"${TASK_NOTES[$id]:-}"
        note="$id"
        [[ -z "${ISSUE_NUMBER[$id]:-}" ]] || note="#${ISSUE_NUMBER[$id]} $id"
        echo "- $note ${TASK_TITLE[$id]}: ${question:-(no question recorded)}"
        waiting=$((waiting + 1))
    done
    [[ "$waiting" -gt 0 ]] || echo "Nothing."
    echo
    echo "## Tasks"
    echo
    echo "| Task | Status | Area | QA rounds | Started | Finished | Cycle days |"
    echo "|---|---|---|---|---|---|---|"
    for id in "${TASK_IDS[@]}"; do
        status="${TASK_STATUS[$id]}"
        title="${TASK_TITLE[$id]//|/\\|}"
        cell="$status"
        if [[ "$status" == "todo" ]]; then
            missing="$(board_missing_deps "$id")"
            if [[ -n "$missing" ]]; then
                cell="todo, blocked by ${missing// /, }"
            else
                cell="todo, ready"
            fi
        fi
        note="$id"
        [[ -z "${ISSUE_NUMBER[$id]:-}" ]] || note="#${ISSUE_NUMBER[$id]} $id"
        echo "| $note $title | $cell | ${TASK_AREA[$id]:-} | ${QA_ROUNDS[$id]} | ${STARTED_ON[$id]:-} | ${FINISHED_ON[$id]:-} | $(cycle_days "$id") |"
    done
    echo
    echo "## Quality"
    echo
    if [[ "$rounds" -gt 0 ]]; then
        echo "- QA rounds: $rounds ($((rounds - rejected)) approved, $rejected rejected), rejection rate $(((rejected * 100 + rounds / 2) / rounds)) %."
    else
        echo "- QA rounds: none yet."
    fi
    if [[ "$finished" -gt 0 ]]; then
        echo "- Cycle time (first \`in progress\` to the last \`done\`): average $(awk -v list="$cycles" 'BEGIN { n = split(list, c, " "); for (i = 1; i <= n; i++) s += c[i]; printf "%.1f", s / n }') days over $finished finished $([[ "$finished" == 1 ]] && echo task || echo tasks)."
    else
        echo "- Cycle time: no finished task with a recorded start yet."
    fi
}

cmd_report() {
    setup_repo
    load_tasks
    load_history
    load_labels
    ensure_label "$REPORT_LABEL"
    load_issues

    local ci body found="" number="" state="" pinned="" current=""
    if offline; then
        ci="unknown"
    else
        ci="$(open_ci_issues | tr '\n' ' ' | sed 's/ $//')"
        found="$(gh issue list --repo "$REPO" --state all --label "$REPORT_LABEL" --limit 100 --json number,state,title,isPinned \
            --jq "[.[] | select(.title == \"$REPORT_TITLE\")] | sort_by(.number) | .[0] // empty | [.number, .state, .isPinned] | @tsv")"
    fi
    body="$(build_report "$ci")"

    if [[ -z "$found" ]]; then
        echo "report  create \"$REPORT_TITLE\""
        if [[ "$DRY_RUN" == 1 ]]; then
            run gh issue create --repo "$REPO" --title "$REPORT_TITLE" --body "$body" --label "$REPORT_LABEL"
            number="<new>"
        else
            number="$(gh issue create --repo "$REPO" --title "$REPORT_TITLE" --body "$body" --label "$REPORT_LABEL")"
            number="${number##*/}"
        fi
        run gh issue pin "$number" --repo "$REPO" >/dev/null || warn "could not pin #$number (at most 3 issues can be pinned)"
        return 0
    fi

    IFS=$'\t' read -r number state pinned <<<"$found"
    current="$(gh issue view "$number" --repo "$REPO" --json body --jq .body)"
    if [[ "$current" != "$body" ]]; then
        echo "report  update #$number"
        run gh issue edit "$number" --repo "$REPO" --body "$body" >/dev/null
    else
        echo "report  #$number is up to date"
    fi
    if [[ "$state" == "CLOSED" ]]; then
        run gh issue reopen "$number" --repo "$REPO" >/dev/null
    fi
    # A pin that failed before (at most 3 pinned issues) is tried again on every run.
    if [[ "$pinned" != "true" ]]; then
        echo "report  pin #$number"
        run gh issue pin "$number" --repo "$REPO" >/dev/null || warn "could not pin #$number (at most 3 issues can be pinned)"
    fi
}

# --- ci -------------------------------------------------------------------------------------------------------------

cmd_ci() {
    setup_repo
    CI_CONCLUSION="${CI_CONCLUSION:-}"
    CI_RUN_ID="${CI_RUN_ID:-}"
    CI_RUN_ATTEMPT="${CI_RUN_ATTEMPT:-1}"
    CI_HEAD_SHA="${CI_HEAD_SHA:-}"
    [[ -n "$CI_CONCLUSION" && "$CI_RUN_ID" =~ ^[0-9]+$ ]] || die "ci needs CI_CONCLUSION and a numeric CI_RUN_ID"
    CI_RUN_URL="${CI_RUN_URL:-https://github.com/$REPO/actions/runs/$CI_RUN_ID}"

    # The run of the branch head decides. A run of an older commit decides too when the commits after it change only
    # the paths that the tests workflow ignores on push (status changes, screenshots): they get no run of their own.
    # Otherwise (code changed since, or the commit is not in the branch's history) the newer run decides.
    local head pathspec=(":/") path
    if [[ -n "$CI_HEAD_SHA" ]]; then
        head="$(git rev-parse HEAD 2>/dev/null || true)"
        if [[ -n "$head" && "$head" != "$CI_HEAD_SHA" ]]; then
            if ! git cat-file -e "$CI_HEAD_SHA^{commit}" 2>/dev/null || ! git merge-base --is-ancestor "$CI_HEAD_SHA" "$head"; then
                echo "ci      ignoring tests run $CI_RUN_ID of ${CI_HEAD_SHA:0:7}: not in the history of the branch head ${head:0:7}"
                return 0
            fi
            for path in "${BOARD_ONLY_PATHS[@]}"; do
                pathspec+=(":(top,exclude)$path")
            done
            if ! git diff --quiet "$CI_HEAD_SHA" "$head" -- "${pathspec[@]}"; then
                echo "ci      ignoring tests run $CI_RUN_ID of ${CI_HEAD_SHA:0:7}: the branch head ${head:0:7} changed code since, its run decides"
                return 0
            fi
            echo "ci      tests run $CI_RUN_ID of ${CI_HEAD_SHA:0:7} decides: only ${BOARD_ONLY_PATHS[*]} changed up to the branch head ${head:0:7}"
        fi
    fi

    case "$CI_CONCLUSION" in
        failure | timed_out | startup_failure) ci_red ;;
        success) ci_green ;;
        *) echo "ci      tests run $CI_RUN_ID concluded \"$CI_CONCLUSION\": the line stays as it is" ;;
    esac
}

ci_where() {
    printf '[tests run %s](%s) (attempt %s)' "$CI_RUN_ID" "$CI_RUN_URL" "$CI_RUN_ATTEMPT"
    [[ -z "$CI_HEAD_SHA" ]] || printf " at \`%s\`" "${CI_HEAD_SHA:0:7}"
    printf " on \`%s\`" "$BRANCH"
}

ci_red() {
    load_labels
    ensure_label "$CI_LABEL"
    local open number fresh=0 existing="" jobs="" marker body url
    open="$(open_ci_issues)"
    open="${open%%$'\n'*}"
    if [[ -z "$open" ]]; then
        echo "ci      stop the line: open \"$CI_TITLE\""
        body="$CI_MARKER
The \`tests\` workflow is red on the integration branch \`$BRANCH\` since $(ci_where).

**Stop the line.** While this issue is open nothing moves to \`done\`: testers do not post \`QA verdict: APPROVED\`, nobody runs \`set-status.sh <Txx> done\` and the integrator merges no task branch. Make the branch green first (fix forward or revert the breaking merge). The board workflow comments every further red run here and closes this issue when the \`tests\` workflow is green again."
        if [[ "$DRY_RUN" == 1 ]]; then
            run gh issue create --repo "$REPO" --title "$CI_TITLE" --body "$body" --label "$CI_LABEL"
            number="<new>"
        else
            url="$(gh issue create --repo "$REPO" --title "$CI_TITLE" --body "$body" --label "$CI_LABEL")"
            number="${url##*/}"
        fi
        fresh=1
    else
        number="$open"
    fi

    marker="<!-- ci-run:$CI_RUN_ID-$CI_RUN_ATTEMPT -->"
    if [[ "$fresh" == 0 ]] && ! offline; then
        existing="$(comments_of "$number")"
    fi
    if [[ "$existing" == *"$marker"* ]]; then
        echo "ci      tests run $CI_RUN_ID (attempt $CI_RUN_ATTEMPT) is already on #$number"
        return 0
    fi
    # The comment matters more than the job names: a failed lookup only loses the list.
    if ! offline && ! jobs="$(gh run view "$CI_RUN_ID" --repo "$REPO" --attempt "$CI_RUN_ATTEMPT" --json jobs \
        --jq '.jobs[] | select(.conclusion == "failure" or .conclusion == "timed_out" or .conclusion == "cancelled" or .conclusion == "startup_failure") | .name')"; then
        warn "could not list the jobs of tests run $CI_RUN_ID"
        jobs=""
    fi
    body="**Red:** $(ci_where)."
    if [[ -n "$jobs" ]]; then
        body+=$'\n\n'"Failed jobs:"$'\n'"- ${jobs//$'\n'/$'\n'- }"
    else
        body+=$'\n\n'"No failed job was listed; see the run."
    fi
    echo "ci      comment tests run $CI_RUN_ID on #$number"
    run gh issue comment "$number" --repo "$REPO" --body "$body"$'\n\n'"$marker" >/dev/null
}

ci_green() {
    local open number
    open="$(open_ci_issues)"
    if [[ -z "$open" ]]; then
        echo "ci      the line is clear"
        return 0
    fi
    for number in $open; do
        echo "ci      green again: close #$number"
        run gh issue close "$number" --repo "$REPO" --reason completed \
            --comment "**Green again:** $(ci_where). The line moves again."$'\n\n'"<!-- ci-green:$CI_RUN_ID-$CI_RUN_ATTEMPT -->" >/dev/null
    done
}

# --- list -----------------------------------------------------------------------------------------------------------

cmd_list() {
    load_tasks
    local id status missing state
    for id in "${TASK_IDS[@]}"; do
        status="${TASK_STATUS[$id]}"
        state=""
        if [[ "$status" == "todo" ]]; then
            missing="$(board_missing_deps "$id")"
            state="${missing:+blocked by ${missing// /, }}"
            state="${state:-ready}"
        fi
        printf '%-4s %-12s %-8s %-11s %s%s\n' "$id" "$status" "phase:${TASK_PHASE[$id]:--}" "${TASK_AREA[$id]:--}" \
            "${TASK_TITLE[$id]}" "${state:+ [$state]}"
    done
}

exec 3>&1
DRY_RUN=0
COMMAND="${1:-}"
[[ $# -eq 0 ]] || shift
case "${1:-}" in
    --dry-run) DRY_RUN=1 ;;
    "") ;;
    *) usage 1 ;;
esac
case "$COMMAND" in
    sync) cmd_sync ;;
    report) cmd_report ;;
    ci) cmd_ci ;;
    list) cmd_list ;;
    -h | --help | help | "") usage ;;
    *) usage 1 ;;
esac
