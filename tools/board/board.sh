#!/usr/bin/env bash
#
# Mirror planning/TASKS.md onto GitHub issues and a GitHub Projects (v2) board.
#
# planning/TASKS.md stays the source of truth: every "### Txx Title — `status`" heading becomes one issue titled
# "[Txx] Title". The issue is labelled with its phase, labelled "in progress" while it is in progress, closed when it
# is done, and placed in the matching Status column of the board (Todo, In Progress, Done).
#
# Usage:
#   tools/board/board.sh sync [--dry-run]
#   tools/board/board.sh list
#
# Environment:
#   BOARD_REPO     owner/repo of the issues (default: the current gh repository)
#   BOARD_OWNER    owner of the project board (user or organization); empty = issues only, no board
#   BOARD_NUMBER   project number from the board URL (.../projects/<number>); empty = issues only, no board
#   BOARD_BRANCH   branch used for links to TASKS.md (default: the current git branch)
#   TASKS_FILE     path of the task list (default: planning/TASKS.md)
#   GH_TOKEN       token for gh (scopes: repo or Issues read/write, and project read/write for the board)

set -euo pipefail

TASKS_FILE="${TASKS_FILE:-planning/TASKS.md}"
TASK_LABEL="task"
PROGRESS_LABEL="in progress"

# Status in TASKS.md => Status option on the board (the default options of the Board template).
declare -A COLUMN=([todo]="Todo" [in progress]="In Progress" [done]="Done")

usage() {
    sed -n '9,11p' "$0" | sed 's/^# \{0,3\}//'
    exit "${1:-0}"
}

die() {
    echo "board: $*" >&2
    exit 1
}

# Prints one tab-separated line per task: id, title, status, phase.
parse_tasks() {
    [[ -f "$TASKS_FILE" ]] || die "task list not found: $TASKS_FILE"

    awk '
        /^## Phase / {
            phase = $3
            sub(/:$/, "", phase)
            next
        }
        /^### T[0-9]+ / {
            if (match($0, /`(todo|in progress|done)`/) == 0) {
                next
            }
            status = substr($0, RSTART + 1, RLENGTH - 2)
            line = substr($0, 5)
            id = line
            sub(/ .*/, "", id)
            title = substr(line, length(id) + 2)
            sub(/ — .*/, "", title)
            printf "%s\t%s\t%s\t%s\n", id, title, status, phase
        }
    ' "$TASKS_FILE"
}

cmd_list() {
    parse_tasks | while IFS=$'\t' read -r id title status phase; do
        printf '%-4s %-12s %-8s %s\n' "$id" "$status" "phase:$phase" "$title"
    done
}

run() {
    if [[ "$DRY_RUN" == 1 ]]; then
        printf 'dry-run:'
        printf ' %q' "$@"
        printf '\n'
    else
        "$@"
    fi
}

ensure_label() {
    local name="$1" color="$2" description="$3"
    run gh label create "$name" --repo "$REPO" --color "$color" --description "$description" --force >/dev/null
}

# Prints "number<TAB>state<TAB>url<TAB>title" of the issue titled "[id] ...", or nothing.
find_issue() {
    local id="$1"
    gh issue list --repo "$REPO" --state all --label "$TASK_LABEL" --limit 500 \
        --json number,state,url,title \
        --jq ".[] | select(.title | startswith(\"[$id] \")) | [.number, .state, .url, .title] | @tsv" | head -n 1
}

# gh cannot tell the owner type of a user-owned board without the read:org scope ("unknown owner type"); "@me"
# addresses the token owner's boards directly.
normalize_owner() {
    if [[ "$BOARD_OWNER" != "@me" && "$(gh api user --jq .login 2>/dev/null || true)" == "$BOARD_OWNER" ]]; then
        BOARD_OWNER="@me"
    fi
}

load_board() {
    normalize_owner
    PROJECT_ID="$(gh project view "$BOARD_NUMBER" --owner "$BOARD_OWNER" --format json --jq .id)"
    local fields
    fields="$(gh project field-list "$BOARD_NUMBER" --owner "$BOARD_OWNER" --format json)"
    STATUS_FIELD_ID="$(jq -r '.fields[] | select(.name == "Status") | .id' <<<"$fields")"
    [[ -n "$STATUS_FIELD_ID" ]] || die "the board has no Status field"

    declare -gA OPTION_ID=()
    local key name
    for key in "${!COLUMN[@]}"; do
        name="${COLUMN[$key]}"
        OPTION_ID[$key]="$(jq -r --arg n "$name" '.fields[] | select(.name == "Status") | .options[] | select(.name == $n) | .id' <<<"$fields")"
        [[ -n "${OPTION_ID[$key]}" ]] || die "the board's Status field has no option \"$name\""
    done
}

place_on_board() {
    local url="$1" status="$2" item
    if [[ "$DRY_RUN" == 1 ]]; then
        run gh project item-add "$BOARD_NUMBER" --owner "$BOARD_OWNER" --url "$url"
        echo "dry-run: set Status of $url to \"${COLUMN[$status]}\""
        return
    fi
    item="$(gh project item-add "$BOARD_NUMBER" --owner "$BOARD_OWNER" --url "$url" --format json --jq .id)"
    gh project item-edit --id "$item" --project-id "$PROJECT_ID" \
        --field-id "$STATUS_FIELD_ID" --single-select-option-id "${OPTION_ID[$status]}" >/dev/null
}

sync_task() {
    local id="$1" title="$2" status="$3" phase="$4"
    local full="[$id] $title"
    local body="Mirrors task **$id** of [\`planning/TASKS.md\`]($TASKS_URL) (scope, dependencies and acceptance live there).

Status, labels and the board column are synced from that file by \`tools/board/board.sh\`; edit the file, not this issue."
    local found number state url current

    found=""
    [[ "$DRY_RUN" == 1 && -z "${GH_TOKEN:-}" ]] || found="$(find_issue "$id")"

    if [[ -z "$found" ]]; then
        echo "create  $full ($status)"
        if [[ "$DRY_RUN" == 1 ]]; then
            run gh issue create --repo "$REPO" --title "$full" --body "<body>" --label "$TASK_LABEL" --label "phase: $phase"
            url="https://github.com/$REPO/issues/<new>"
        else
            url="$(gh issue create --repo "$REPO" --title "$full" --body "$body" --label "$TASK_LABEL" --label "phase: $phase")"
        fi
        state="OPEN"
    else
        IFS=$'\t' read -r number state url current <<<"$found"
        echo "update  $full ($status) #$number"
        if [[ "$current" != "$full" ]]; then
            run gh issue edit "$number" --repo "$REPO" --title "$full" >/dev/null
        fi
        run gh issue edit "$number" --repo "$REPO" --add-label "phase: $phase" >/dev/null
    fi

    if [[ "$status" == "in progress" ]]; then
        run gh issue edit "$url" --repo "$REPO" --add-label "$PROGRESS_LABEL" >/dev/null
    elif [[ -n "$found" ]]; then
        run gh issue edit "$url" --repo "$REPO" --remove-label "$PROGRESS_LABEL" >/dev/null
    fi

    if [[ "$status" == "done" && "$state" == "OPEN" ]]; then
        run gh issue close "$url" --repo "$REPO" --reason completed >/dev/null
    elif [[ "$status" != "done" && "$state" == "CLOSED" ]]; then
        run gh issue reopen "$url" --repo "$REPO" >/dev/null
    fi

    if [[ -n "$BOARD_NUMBER" ]]; then
        place_on_board "$url" "$status"
    fi
}

cmd_sync() {
    if [[ "$DRY_RUN" == 1 && -z "${GH_TOKEN:-}" ]]; then
        REPO="${BOARD_REPO:-owner/repo}"
    else
        command -v gh >/dev/null || die "gh is not installed"
        REPO="${BOARD_REPO:-$(gh repo view --json nameWithOwner --jq .nameWithOwner)}"
    fi

    local branch="${BOARD_BRANCH:-$(git rev-parse --abbrev-ref HEAD 2>/dev/null || echo master)}"
    TASKS_URL="https://github.com/$REPO/blob/$branch/$TASKS_FILE"
    BOARD_OWNER="${BOARD_OWNER:-}"
    BOARD_NUMBER="${BOARD_NUMBER:-}"
    [[ -z "$BOARD_NUMBER" || -n "$BOARD_OWNER" ]] || die "BOARD_NUMBER needs BOARD_OWNER"

    local tasks
    tasks="$(parse_tasks)"
    [[ -n "$tasks" ]] || die "no tasks found in $TASKS_FILE"

    ensure_label "$TASK_LABEL" "0e8a16" "Mirrors a task of planning/TASKS.md"
    ensure_label "$PROGRESS_LABEL" "fbca04" "Being worked on"
    local phase
    for phase in $(cut -f4 <<<"$tasks" | sort -u); do
        ensure_label "phase: $phase" "c5def5" "Phase $phase of planning/TASKS.md"
    done

    if [[ -n "$BOARD_NUMBER" && "$DRY_RUN" != 1 ]]; then
        load_board
    fi

    while IFS=$'\t' read -r id title status phase; do
        sync_task "$id" "$title" "$status" "$phase"
    done <<<"$tasks"
}

DRY_RUN=0
case "${1:-}" in
    sync)
        shift
        [[ "${1:-}" == "--dry-run" ]] && DRY_RUN=1
        cmd_sync
        ;;
    list)
        cmd_list
        ;;
    -h | --help | help | "")
        usage
        ;;
    *)
        usage 1
        ;;
esac
