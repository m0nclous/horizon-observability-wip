#!/usr/bin/env bash
#
# Change the status of one task in planning/TASKS.md on the integration branch, optionally record a QA verdict, an
# owner question or answer and screenshots, then commit and push, so the board workflow moves the card. Safe to call
# from any worktree and from parallel agents: it works in the main checkout under a lock and commits only
# planning/TASKS.md (and the attached screenshots).
#
# Usage:
#   tools/board/set-status.sh <Txx> <todo|in progress|testing|needs owner|done> [options]
#
# Options:
#   --qa "text"          add "QA: <UTC stamp>: text" under the heading (a tester's verdict: "round N approved by ..."
#                        or "round N rejected by ...: findings")
#   --question "text"    add "Owner question: <UTC stamp>: text" (required for needs owner)
#   --answer "text"      add "Owner answer: <UTC stamp>: text"
#   --attach file.png    copy a screenshot to planning/screenshots/<Txx>/, commit it and link it from the QA text
#                        (or from the question without --qa); repeatable
#   --force              ignore the dependency and WIP gates (the commit message records what was forced)
#   --no-push            keep the commit local
#   --dry-run            print the change and the checks, change nothing
#
# Gates (--force overrides): in progress, testing and done need every dependency done; a task enters in progress or
# testing only below the WIP limit of that column (a QA rejection, testing -> in progress, always passes); needs owner
# needs --question.
#
# Examples:
#   tools/board/set-status.sh T12 "in progress"
#   tools/board/set-status.sh T12 testing
#   tools/board/set-status.sh T12 "in progress" --qa "round 1 rejected by the spec tester: 3 findings (...)"
#   tools/board/set-status.sh T12 "needs owner" --question "Should the stream command also push on Grafana 11?"
#   tools/board/set-status.sh T12 "in progress" --answer "No, Grafana 12.3 is the floor (decision 4)"
#   tools/board/set-status.sh T13 done --qa "round 2 approved by the spec and adversarial testers" \
#       --attach build/screenshots/dashboard-12.4.13.png --attach build/screenshots/dashboard-13.2.4.png
#
# Environment:
#   BOARD_MAIN              path of the main checkout (default: the first entry of `git worktree list`)
#   TASKS_FILE              path of the task list relative to the checkout (default: planning/TASKS.md)
#   BOARD_WIP_IN_PROGRESS   WIP limit of In Progress (default 2)
#   BOARD_WIP_TESTING       WIP limit of Testing (default 3)
#   BOARD_REPO, BOARD_BRANCH  owner/repo and branch of the screenshot links (default: origin and the main checkout's branch)
#   BOARD_NOW               "YYYY-MM-DD HH:MM" UTC stamp to use instead of the clock (tests)

set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source-path=SCRIPTDIR source=lib.sh
source "$HERE/lib.sh"

USAGE='usage: set-status.sh <Txx> <todo|in progress|testing|needs owner|done> [--qa "text"] [--question "text"] [--answer "text"] [--attach file.png]... [--force] [--no-push] [--dry-run]'

die() {
    echo "set-status: $*" >&2
    exit 1
}

if [[ "${1:-}" == "-h" || "${1:-}" == "--help" ]]; then
    sed -n '3,/^$/p' "$0" | sed 's/^# \{0,1\}//'
    exit 0
fi
[[ $# -ge 2 ]] || die "$USAGE"

ID="$1"
STATUS="$2"
shift 2
QA=""
QUESTION=""
ANSWER=""
ATTACH=()
PUSH=1
FORCE=0
DRY_RUN=0
while [[ $# -gt 0 ]]; do
    case "$1" in
        --qa | --question | --answer)
            [[ -n "${2:-}" ]] || die "$1 needs a text"
            case "$1" in
                --qa) QA="$2" ;;
                --question) QUESTION="$2" ;;
                --answer) ANSWER="$2" ;;
            esac
            shift 2
            ;;
        --attach)
            [[ -n "${2:-}" ]] || die "--attach needs a file"
            ATTACH+=("$2")
            shift 2
            ;;
        --force)
            FORCE=1
            shift
            ;;
        --no-push)
            PUSH=0
            shift
            ;;
        --dry-run)
            DRY_RUN=1
            shift
            ;;
        *)
            die "unknown option: $1"
            ;;
    esac
done

[[ "$ID" =~ ^T[0-9]+$ ]] || die "invalid task id: $ID"
[[ "|$BOARD_STATUSES|" == *"|$STATUS|"* ]] || die "invalid status: $STATUS"
QA="${QA//$'\n'/ }"
QUESTION="${QUESTION//$'\n'/ }"
ANSWER="${ANSWER//$'\n'/ }"

# Screenshots: resolved before the cd into the main checkout, checked before taking the lock.
SOURCES=()
for file in "${ATTACH[@]}"; do
    [[ -f "$file" ]] || die "screenshot not found: $file"
    [[ "$file" == *.png || "$file" == *.PNG ]] || die "screenshots must be PNG files: $file"
    [[ "$(head -c 8 "$file" | od -An -tx1 | tr -d ' \n')" == "89504e470d0a1a0a" ]] || die "not a PNG file: $file"
    SOURCES+=("$(cd "$(dirname "$file")" && pwd)/$(basename "$file")")
done
if [[ ${#SOURCES[@]} -gt 0 && -z "$QA" && -z "$QUESTION" ]]; then
    die "--attach needs --qa or --question (the screenshots are linked from that text)"
fi

MAIN="${BOARD_MAIN:-$(git worktree list --porcelain | awk '/^worktree /{print $2; exit}')}"
TASKS_FILE="${TASKS_FILE:-planning/TASKS.md}"
[[ -f "$MAIN/$TASKS_FILE" ]] || die "task list not found: $MAIN/$TASKS_FILE"

exec 9>"$(git -C "$MAIN" rev-parse --path-format=absolute --git-common-dir)/board-status.lock"
flock -w 120 9 || die "timed out waiting for the board lock"

cd "$MAIN"
GIT_DIR="$(git rev-parse --git-dir)"
for _ in $(seq 1 60); do
    [[ -e "$GIT_DIR/MERGE_HEAD" || -e "$GIT_DIR/rebase-merge" || -e "$GIT_DIR/rebase-apply" ]] || break
    sleep 5
done
[[ ! -e "$GIT_DIR/MERGE_HEAD" ]] || die "a merge is in progress in $MAIN; try again later"

board_load_tasks "$TASKS_FILE" || die "cannot read $TASKS_FILE"
[[ -n "${TASK_STATUS[$ID]:-}" ]] || die "task $ID not found in $TASKS_FILE (a heading \"### $ID Title — \`status\`\")"
CURRENT="${TASK_STATUS[$ID]}"

# Gates.
FORCED=()
refuse() {
    if [[ "$FORCE" == 1 ]]; then
        echo "set-status: --force: $1" >&2
        FORCED+=("$1")
    else
        die "$1; --force overrides"
    fi
}

if [[ "$STATUS" == "needs owner" && -z "$QUESTION" && "$CURRENT" != "needs owner" ]]; then
    refuse "needs owner needs --question \"...\" (the owner sees it as an issue comment)"
fi

case "$STATUS" in
    "in progress" | testing | done)
        MISSING="$(board_missing_deps "$ID")"
        if [[ -n "$MISSING" ]]; then
            refuse "$ID depends on $(board_describe_ids "$MISSING"), not done yet"
        fi
        ;;
esac

case "$STATUS" in
    "in progress" | testing)
        if [[ "$STATUS" == "in progress" ]]; then
            LIMIT="${BOARD_WIP_IN_PROGRESS:-2}"
        else
            LIMIT="${BOARD_WIP_TESTING:-3}"
        fi
        [[ "$LIMIT" =~ ^[0-9]+$ ]] || die "invalid WIP limit for $STATUS: $LIMIT"
        OTHERS="$(board_ids_with_status "$STATUS" "$ID")"
        read -ra OTHER_IDS <<<"$OTHERS"
        if [[ "$CURRENT" != "$STATUS" && ${#OTHER_IDS[@]} -ge "$LIMIT" ]]; then
            if [[ "$CURRENT" == "testing" && "$STATUS" == "in progress" ]]; then
                echo "set-status: note: In Progress goes over its WIP limit $LIMIT (${OTHERS// /, } and $ID): a QA rejection always returns" >&2
            else
                refuse "the WIP limit of $STATUS is $LIMIT and it holds ${OTHERS// /, }; finish or hand over a task first"
            fi
        fi
        ;;
esac

STAMP="${BOARD_NOW:-$(date -u '+%Y-%m-%d %H:%M')}"
[[ "$STAMP" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}\ [0-9]{2}:[0-9]{2}$ ]] || die "invalid BOARD_NOW: $STAMP"
STAMP="$STAMP UTC"

# Screenshots: planning/screenshots/<Txx>/<YYYYMMDD-HHMM>-<name>.png, linked with blob URLs on the integration branch.
SHOT_PATHS=()
SHOT_LINKS=""
if [[ ${#SOURCES[@]} -gt 0 ]]; then
    REPO="${BOARD_REPO:-}"
    if [[ -z "$REPO" ]]; then
        REPO="$(git remote get-url origin 2>/dev/null || true)"
        REPO="${REPO%.git}"
        REPO="${REPO#https://github.com/}"
        REPO="${REPO#git@github.com:}"
        REPO="${REPO#ssh://git@github.com/}"
        [[ "$REPO" =~ ^[A-Za-z0-9_.-]+/[A-Za-z0-9_.-]+$ ]] || die "cannot derive owner/repo from origin; set BOARD_REPO"
    fi
    BRANCH="${BOARD_BRANCH:-$(git rev-parse --abbrev-ref HEAD)}"
    PREFIX="$(tr -d ' :-' <<<"${STAMP% UTC}")"
    PREFIX="${PREFIX:0:8}-${PREFIX:8:4}"
    SHOT_DIR="$(dirname "$TASKS_FILE")/screenshots/$ID"
    for source in "${SOURCES[@]}"; do
        name="$(basename "$source")"
        name="${name%.*}"
        name="$(LC_ALL=C tr -c 'A-Za-z0-9._-' '-' <<<"$name" | sed 's/-*$//')"
        target="$SHOT_DIR/$PREFIX-$name.png"
        for previous in "${SHOT_PATHS[@]}"; do
            [[ "$previous" != "$target" ]] || die "two screenshots map to $target; rename one"
        done
        if [[ -e "$target" ]] && ! cmp -s "$source" "$target"; then
            die "$target already exists with other content"
        fi
        SHOT_PATHS+=("$target")
        SHOT_LINKS+=" ![$ID $name](https://github.com/$REPO/blob/$BRANCH/$target?raw=true)"
    done
    if [[ -n "$QA" ]]; then
        QA="$QA$SHOT_LINKS"
    else
        QUESTION="$QUESTION$SHOT_LINKS"
    fi
fi

# Lines right under the heading, top to bottom (newest first, so the question precedes its answer when read upwards).
INSERT=""
[[ -z "$QA" ]] || INSERT+="QA: $STAMP: $QA"$'\n'
[[ -z "$ANSWER" ]] || INSERT+="Owner answer: $STAMP: $ANSWER"$'\n'
[[ -z "$QUESTION" ]] || INSERT+="Owner question: $STAMP: $QUESTION"$'\n'

edit_tasks() {
    ID="$ID" STATUS="$STATUS" INSERT="$INSERT" python3 -I - "$1" <<'PY'
import os, re, sys
path = sys.argv[1]
task, status = os.environ["ID"], os.environ["STATUS"]
insert = [line for line in os.environ["INSERT"].split("\n") if line]
lines = open(path, encoding="utf-8").read().split("\n")
pattern = re.compile(r"^(### " + re.escape(task) + r" .*? — )`(todo|in progress|testing|needs owner|done)`")
for i, line in enumerate(lines):
    match = pattern.match(line)
    if match:
        lines[i] = pattern.sub(lambda m: m.group(1) + "`" + status + "`", line, count=1)
        lines[i + 1:i + 1] = insert
        break
else:
    sys.exit("set-status: heading of " + task + " not found")
open(path, "w", encoding="utf-8").write("\n".join(lines))
PY
}

TAGS=()
[[ -z "$QA" ]] || TAGS+=("QA")
[[ -z "$QUESTION" ]] || TAGS+=("question")
[[ -z "$ANSWER" ]] || TAGS+=("answer")
SUBJECT="board: $ID -> $STATUS"
if [[ ${#TAGS[@]} -gt 0 ]]; then
    SUBJECT="$SUBJECT ($(printf '%s, ' "${TAGS[@]}" | sed 's/, $//'))"
fi

if [[ "$DRY_RUN" == 1 ]]; then
    PREVIEW="$(mktemp)"
    trap 'rm -f "$PREVIEW"' EXIT
    cp "$TASKS_FILE" "$PREVIEW"
    edit_tasks "$PREVIEW"
    echo "set-status: dry run: $ID $CURRENT -> $STATUS"
    diff -u --label "a/$TASKS_FILE" --label "b/$TASKS_FILE" "$TASKS_FILE" "$PREVIEW" || true
    for i in "${!SOURCES[@]}"; do
        echo "set-status: dry run: copy ${SOURCES[$i]} -> ${SHOT_PATHS[$i]}"
    done
    echo "set-status: dry run: commit \"$SUBJECT\"$([[ "$PUSH" == 1 ]] && echo " and push")"
    exit 0
fi

edit_tasks "$TASKS_FILE"
for i in "${!SOURCES[@]}"; do
    mkdir -p "$(dirname "${SHOT_PATHS[$i]}")"
    cp "${SOURCES[$i]}" "${SHOT_PATHS[$i]}"
done
if [[ ${#SHOT_PATHS[@]} -gt 0 ]]; then
    git add -- "${SHOT_PATHS[@]}"
fi

if git diff --quiet -- "$TASKS_FILE" && { [[ ${#SHOT_PATHS[@]} -eq 0 ]] || git diff --cached --quiet -- "${SHOT_PATHS[@]}"; }; then
    echo "set-status: $ID is already $STATUS"
    exit 0
fi

BODY=""
[[ -z "$QA" ]] || BODY+="QA: $QA"$'\n'
[[ -z "$QUESTION" ]] || BODY+="Owner question: $QUESTION"$'\n'
[[ -z "$ANSWER" ]] || BODY+="Owner answer: $ANSWER"$'\n'
[[ ${#SHOT_PATHS[@]} -eq 0 ]] || BODY+="Screenshots: ${SHOT_PATHS[*]}"$'\n'
for reason in "${FORCED[@]}"; do
    BODY+="Forced: $reason"$'\n'
done

git commit -q -m "$SUBJECT

${BODY:+$BODY
}Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_016iQqPzUbzkWUQnPmE7dxcs" -- "$TASKS_FILE" "${SHOT_PATHS[@]}"
echo "set-status: $ID -> $STATUS ($(git rev-parse --short HEAD))"

if [[ "$PUSH" == 1 ]]; then
    git push -q origin HEAD || die "push failed; the status is committed locally, push later"
fi
