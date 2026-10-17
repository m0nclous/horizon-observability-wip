#!/usr/bin/env bash
#
# Change the status of one task in planning/TASKS.md on the integration branch, optionally record a QA verdict, then
# commit and push, so the board workflow moves the card. Safe to call from any worktree and from parallel agents: it
# works in the main checkout under a lock and commits only planning/TASKS.md.
#
# Usage:
#   tools/board/set-status.sh <Txx> <todo|in progress|testing|done> [--qa "verdict text"] [--no-push]
#
# Examples:
#   tools/board/set-status.sh T12 "in progress"
#   tools/board/set-status.sh T12 testing
#   tools/board/set-status.sh T12 "in progress" --qa "round 1 rejected by the spec tester: 3 findings (...)"
#   tools/board/set-status.sh T12 done --qa "round 2 approved by the spec tester and the adversarial tester"
#
# Environment:
#   BOARD_MAIN     path of the main checkout (default: the first entry of `git worktree list`)
#   TASKS_FILE     path of the task list relative to the checkout (default: planning/TASKS.md)

set -euo pipefail

die() {
    echo "set-status: $*" >&2
    exit 1
}

[[ $# -ge 2 ]] || die "usage: set-status.sh <Txx> <todo|in progress|testing|done> [--qa \"text\"] [--no-push]"

ID="$1"
STATUS="$2"
shift 2
QA=""
PUSH=1
while [[ $# -gt 0 ]]; do
    case "$1" in
        --qa)
            QA="${2:-}"
            [[ -n "$QA" ]] || die "--qa needs a text"
            shift 2
            ;;
        --no-push)
            PUSH=0
            shift
            ;;
        *)
            die "unknown option: $1"
            ;;
    esac
done

[[ "$ID" =~ ^T[0-9]+$ ]] || die "invalid task id: $ID"
case "$STATUS" in
    todo | "in progress" | testing | done) ;;
    *) die "invalid status: $STATUS" ;;
esac

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

grep -qE "^### $ID " "$TASKS_FILE" || die "task $ID not found in $TASKS_FILE"

STAMP="$(date -u +%Y-%m-%d\ %H:%M) UTC"
ID="$ID" STATUS="$STATUS" QA="$QA" STAMP="$STAMP" python3 -I - "$TASKS_FILE" <<'PY'
import os, re, sys
path = sys.argv[1]
task, status, qa, stamp = os.environ["ID"], os.environ["STATUS"], os.environ["QA"], os.environ["STAMP"]
lines = open(path, encoding="utf-8").read().split("\n")
pattern = re.compile(r"^(### " + re.escape(task) + r" .*? — )`(todo|in progress|testing|done)`")
for i, line in enumerate(lines):
    match = pattern.match(line)
    if match:
        lines[i] = pattern.sub(lambda m: m.group(1) + "`" + status + "`", line, count=1)
        if qa:
            lines.insert(i + 1, "QA: " + stamp + ": " + qa.replace("\n", " "))
        break
else:
    sys.exit("set-status: heading of " + task + " not found")
open(path, "w", encoding="utf-8").write("\n".join(lines))
PY

if git diff --quiet -- "$TASKS_FILE"; then
    echo "set-status: $ID is already $STATUS"
    exit 0
fi

SUBJECT="board: $ID -> $STATUS"
[[ -z "$QA" ]] || SUBJECT="$SUBJECT (QA)"
git commit -q -m "$SUBJECT

${QA:+QA: $QA

}Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_016iQqPzUbzkWUQnPmE7dxcs" -- "$TASKS_FILE"
echo "set-status: $ID -> $STATUS ($(git rev-parse --short HEAD))"

if [[ "$PUSH" == 1 ]]; then
    git push -q origin HEAD || die "push failed; the status is committed locally, push later"
fi
