#!/usr/bin/env bash
#
# Change the status of one task in planning/TASKS.md on the integration branch, optionally record a QA verdict, an
# owner question or answer and screenshots, then commit and push, so the board workflow moves the card. Safe to call
# from any worktree and from parallel agents: it works in the main checkout under a lock, brings it up to date with
# origin first (the gates read the state on origin), and commits only planning/TASKS.md (and the screenshots).
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
#   --force --reason "why"
#                        override the dependency, WIP and branch gates; the commit records what was forced and why
#   --trailer "Key: value"
#                        append a trailer line to the commit message (e.g. your session's attribution); repeatable
#   --no-push            work offline: no fetch, no push, the gates read the local state; the commit stays local
#   --dry-run            print the change and the checks, change nothing (it still fetches, unless --no-push)
#
# Gates (--force overrides): starting work (todo -> in progress or testing; needs owner -> in progress or testing
# when the task had not started before its question) and done need every dependency done, while moves that keep,
# continue or return work (a note, in progress -> testing, a QA rejection, an answer that resumes it, reopening) do
# not; a task enters in progress or testing only below the WIP limit of that column (a QA rejection, testing ->
# in progress with a --qa text that rejects, always passes); needs owner needs --question; the main checkout must be
# on the integration branch.
# Refused without override: a merge, rebase, cherry-pick or revert in progress, unmerged paths, a detached HEAD,
# uncommitted changes of planning/TASKS.md, local commits that are not status changes (the push would publish
# them), and local commits that origin does not have while origin moved too.
# A failure after the first change (a commit that fails: signing, a hook, no identity; a push that fails) undoes
# the attempt: HEAD, TASKS.md and the screenshots go back to where they were.
#
# Needs bash 4+, git, python3, flock (util-linux; on macOS `brew install flock`), od and cmp.
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
#   BOARD_MAIN                path of the main checkout (default: the first entry of `git worktree list`)
#   BOARD_INTEGRATION_BRANCH  the branch whose TASKS.md drives the board (default: claude/hopeful-hawking-njeu3v)
#   TASKS_FILE                path of the task list relative to the checkout (default: planning/TASKS.md)
#   BOARD_WIP_IN_PROGRESS     WIP limit of In Progress (default 2)
#   BOARD_WIP_TESTING         WIP limit of Testing (default 3)
#   BOARD_TRAILERS            trailer lines for the commit message (newline-separated), like --trailer
#   BOARD_ATTACH_MAX_KB       size limit of one screenshot (default 1024)
#   BOARD_WAIT                seconds to wait for a merge, rebase or TASKS.md edit in the main checkout (default 300)
#   BOARD_PUSH_TRIES          attempts when the push loses a race with another push (default 10, random back-off)
#   BOARD_REPO                owner/repo of the screenshot links (default: from the origin URL)
#   BOARD_NOW                 "YYYY-MM-DD HH:MM" UTC stamp to use instead of the clock (tests)

set -euo pipefail

HERE="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source-path=SCRIPTDIR source=lib.sh
source "$HERE/lib.sh"

USAGE='usage: set-status.sh <Txx> <todo|in progress|testing|needs owner|done> [--qa "text"] [--question "text"] [--answer "text"] [--attach file.png]... [--force --reason "why"] [--trailer "Key: value"]... [--no-push] [--dry-run]'

die() {
    echo "set-status: $*" >&2
    exit 1
}

if [[ "${1:-}" == "-h" || "${1:-}" == "--help" ]]; then
    sed -n '3,/^$/p' "$0" | sed 's/^# \{0,1\}//'
    exit 0
fi
[[ $# -ge 2 ]] || die "$USAGE"
for tool in git python3 flock od cmp; do
    command -v "$tool" >/dev/null ||
        die "$tool is required (set-status.sh needs git, python3, flock, od and cmp; on macOS: brew install flock)"
done

ID="$1"
STATUS="$2"
shift 2
QA=""
QUESTION=""
ANSWER=""
REASON=""
ATTACH=()
TRAILERS=()
PUSH=1
FORCE=0
DRY_RUN=0
while [[ $# -gt 0 ]]; do
    case "$1" in
        --qa | --question | --answer | --reason | --trailer)
            [[ -n "${2:-}" ]] || die "$1 needs a text"
            case "$1" in
                --qa) QA="$2" ;;
                --question) QUESTION="$2" ;;
                --answer) ANSWER="$2" ;;
                --reason) REASON="$2" ;;
                --trailer) TRAILERS+=("$2") ;;
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
REASON="${REASON//$'\n'/ }"
if [[ "$FORCE" == 1 && -z "$REASON" ]]; then
    die "--force needs --reason \"why\" (the commit records it)"
fi
[[ -z "$REASON" || "$FORCE" == 1 ]] || die "--reason goes with --force"
while IFS= read -r line; do
    [[ -z "$line" ]] || TRAILERS+=("$line")
done <<<"${BOARD_TRAILERS:-}"
for line in "${TRAILERS[@]}"; do
    [[ "$line" =~ ^[A-Za-z][A-Za-z0-9-]*:\ [^[:cntrl:]]+$ ]] || die "invalid trailer (want \"Key: value\"): $line"
done

# Screenshots: resolved before the cd into the main checkout, checked before taking the lock.
MAX_KB="${BOARD_ATTACH_MAX_KB:-1024}"
[[ "$MAX_KB" =~ ^[0-9]+$ ]] || die "invalid BOARD_ATTACH_MAX_KB: $MAX_KB"
SOURCES=()
for file in "${ATTACH[@]}"; do
    [[ -f "$file" ]] || die "screenshot not found: $file"
    [[ "$file" == *.png || "$file" == *.PNG ]] || die "screenshots must be PNG files: $file"
    [[ "$(head -c 8 "$file" | od -An -tx1 | tr -d ' \n')" == "89504e470d0a1a0a" ]] || die "not a PNG file: $file"
    size="$(wc -c <"$file" | tr -d ' ')"
    [[ "$size" -le $((MAX_KB * 1024)) ]] ||
        die "$file is $size bytes, more than the limit of $MAX_KB KiB (BOARD_ATTACH_MAX_KB); crop or compress it"
    SOURCES+=("$(cd "$(dirname "$file")" && pwd)/$(basename "$file")")
done
if [[ ${#SOURCES[@]} -gt 0 && -z "$QA" && -z "$QUESTION" ]]; then
    die "--attach needs --qa or --question (the screenshots are linked from that text)"
fi

# The main checkout is the first worktree; its path may contain spaces.
MAIN="${BOARD_MAIN:-$(git worktree list --porcelain | awk '/^worktree /{sub(/^worktree /, ""); print; exit}')}"
TASKS_FILE="${TASKS_FILE:-planning/TASKS.md}"
INTEGRATION="${BOARD_INTEGRATION_BRANCH:-claude/hopeful-hawking-njeu3v}"
WAIT="${BOARD_WAIT:-300}"
[[ "$WAIT" =~ ^[0-9]+$ ]] || die "invalid BOARD_WAIT: $WAIT"
TRIES="${BOARD_PUSH_TRIES:-10}"
[[ "$TRIES" =~ ^[1-9][0-9]*$ ]] || die "invalid BOARD_PUSH_TRIES: $TRIES"
[[ -f "$MAIN/$TASKS_FILE" ]] || die "task list not found: $MAIN/$TASKS_FILE"

cd "$MAIN"
GIT_DIR="$(git rev-parse --absolute-git-dir)"

# Why the main checkout cannot take a status commit now, or nothing.
checkout_problem() {
    if [[ -e "$GIT_DIR/MERGE_HEAD" ]]; then
        echo "a merge is in progress in $MAIN"
    elif [[ -e "$GIT_DIR/rebase-merge" || -e "$GIT_DIR/rebase-apply" || -e "$GIT_DIR/REBASE_HEAD" ]]; then
        echo "a rebase is in progress in $MAIN"
    elif [[ -e "$GIT_DIR/CHERRY_PICK_HEAD" ]]; then
        echo "a cherry-pick is in progress in $MAIN"
    elif [[ -e "$GIT_DIR/REVERT_HEAD" ]]; then
        echo "a revert is in progress in $MAIN"
    elif [[ -e "$GIT_DIR/sequencer" ]]; then
        echo "a cherry-pick or revert sequence is in progress in $MAIN"
    elif [[ -n "$(git ls-files -u)" ]]; then
        echo "$MAIN has unmerged paths"
    elif ! git symbolic-ref -q HEAD >/dev/null; then
        echo "HEAD is detached in $MAIN (not on a branch)"
    elif ! git diff --quiet HEAD -- "$TASKS_FILE" || ! git diff --cached --quiet -- "$TASKS_FILE"; then
        echo "$TASKS_FILE has uncommitted changes in $MAIN (commit or stash them)"
    fi
}

# Waits (without the lock, so that other callers are not blocked) for a merge, rebase or edit to finish.
for ((waited = 0; waited < WAIT; waited++)); do
    [[ -n "$(checkout_problem)" ]] || break
    sleep 1
done

exec 9>"$(git rev-parse --path-format=absolute --git-common-dir)/board-status.lock"
flock -w 120 9 || die "timed out waiting for the board lock"

PROBLEM="$(checkout_problem)"
[[ -z "$PROBLEM" ]] || die "$PROBLEM; try again later"

FORCED=()
refuse() {
    if [[ "$FORCE" == 1 ]]; then
        echo "set-status: --force: $1" >&2
        FORCED+=("$1")
    else
        die "$1; --force --reason \"why\" overrides"
    fi
}

BRANCH="$(git symbolic-ref --short HEAD)"
if [[ "$BRANCH" != "$INTEGRATION" ]]; then
    refuse "$MAIN is on $BRANCH, not on the integration branch $INTEGRATION (BOARD_INTEGRATION_BRANCH): the board would not see the change"
fi
BRANCH_FORCED=("${FORCED[@]}")
UPSTREAM="refs/remotes/origin/$BRANCH"

# The task list the gates read, and the history they read it with: the main checkout's, or origin's in a dry run of
# a checkout that is behind.
SOURCE_FILE="$TASKS_FILE"
HISTORY_REF="HEAD"
TEMP_FILES=()
SHOT_PATHS=()
# Set while an attempt has changed the main checkout (screenshots copied, TASKS.md edited, commits made) and the
# result has not reached origin: any exit then undoes the attempt, so that a failure (a commit refused by signing or
# a hook, a push that fails, an interrupt) never leaves the shared main checkout dirty for every later call.
UNDO_BASE=""
# shellcheck disable=SC2317,SC2329 # called by the EXIT trap
on_exit() {
    local rc=$?
    if [[ -n "$UNDO_BASE" ]]; then
        if undo_to "$UNDO_BASE" 2>/dev/null; then
            echo "set-status: undid this attempt: HEAD, $TASKS_FILE and the screenshots are back at ${UNDO_BASE:0:7}; nothing changed" >&2
        else
            echo "set-status: could not undo this attempt in $MAIN; check \`git status\` there (HEAD was ${UNDO_BASE:0:7})" >&2
        fi
        UNDO_BASE=""
    fi
    rm -f "${TEMP_FILES[@]}"
    return "$rc"
}
trap on_exit EXIT
trap 'exit 130' INT
trap 'exit 143' TERM

# The local commits that origin does not have and that set-status.sh did not write, one "<sha> <subject>" per line:
# anything but a one-parent "board: Txx -> <status>" or "board: Txx screenshots" commit that touches only the task
# list and the screenshots. The push would publish them (an integrator's local merge under test, for example).
foreign_commits() {
    local sha parents subject path ok
    local re='^board: T[0-9]+ (-> (todo|in progress|testing|needs owner|done)( \(.*\))?|screenshots)$'
    local shots
    shots="$(dirname "$TASKS_FILE")/screenshots/"
    while IFS=$'\t' read -r sha parents subject; do
        [[ -n "$sha" ]] || continue
        ok=1
        [[ "$parents" != *" "* && "$subject" =~ $re ]] || ok=0
        if [[ "$ok" == 1 ]]; then
            while IFS= read -r path; do
                [[ -z "$path" || "$path" == "$TASKS_FILE" || "$path" == "$shots"* ]] || ok=0
            done < <(git diff-tree --no-commit-id --name-only -r "$sha")
        fi
        [[ "$ok" == 1 ]] || echo "${sha:0:7} $subject"
    done < <(git log --format='%H%x09%P%x09%s' "$UPSTREAM..HEAD")
}

# Brings the main checkout up to date with origin: fetch, then fast-forward. Local commits that origin does not have
# are kept (the push publishes them) unless origin moved too; then the caller must integrate them first.
sync_with_origin() {
    local error behind ahead
    error="$(git fetch -q origin "+refs/heads/$BRANCH:$UPSTREAM" 2>&1)" ||
        die "cannot fetch $BRANCH from origin (${error//$'\n'/ }); --no-push works offline"
    behind="$(git rev-list --count "HEAD..$UPSTREAM")"
    ahead="$(git rev-list --count "$UPSTREAM..HEAD")"
    if [[ "$ahead" -gt 0 ]]; then
        local foreign
        foreign="$(foreign_commits)"
        if [[ -n "$foreign" ]]; then
            die "$MAIN has $ahead local commit(s) that origin/$BRANCH does not have, and not only status changes (${foreign//$'\n'/; }): the push of a status change would publish them. Push them yourself when they are ready (an integrator merges in a separate worktree, or pushes the merge first), or move them to a task branch, then try again"
        fi
    fi
    [[ "$behind" -gt 0 ]] || return 0
    if [[ "$ahead" -gt 0 ]]; then
        die "$MAIN has $ahead commit(s) that origin/$BRANCH does not have, and origin has $behind new one(s); integrate them first (git pull --rebase, or push them), then try again"
    fi
    if [[ "$DRY_RUN" == 1 ]]; then
        echo "set-status: dry run: $MAIN is $behind commit(s) behind origin/$BRANCH; a real run fast-forwards it first and the checks below read origin's $TASKS_FILE"
        SOURCE_FILE="$(mktemp)"
        TEMP_FILES+=("$SOURCE_FILE")
        HISTORY_REF="$UPSTREAM"
        git show "$UPSTREAM:$TASKS_FILE" >"$SOURCE_FILE"
        return 0
    fi
    error="$(git merge -q --ff-only "$UPSTREAM" 2>&1)" ||
        die "cannot fast-forward $MAIN to origin/$BRANCH (${error//$'\n'/ }); update it by hand, then try again"
    echo "set-status: fast-forwarded $MAIN to origin/$BRANCH ($behind new commit(s))" >&2
}

# Whether the task was at work before it went to needs owner: the last "board: Txx -> ..." commit of the task that
# does not move it to needs owner moved it to in progress, testing or done (not to todo, and not none at all).
resumes_after_question() {
    local subject re="^board: $ID -> (todo|in progress|testing|needs owner|done)( \\(.*\\))?\$"
    while IFS= read -r subject; do
        [[ "$subject" =~ $re ]] || continue
        case "${BASH_REMATCH[1]}" in
            "needs owner") ;;
            todo) return 1 ;;
            *) return 0 ;;
        esac
    done < <(git log --format=%s "$HISTORY_REF" -- "$TASKS_FILE" 2>/dev/null)
    return 1
}

# Loads the task list and applies the gates; sets CURRENT.
check_gates() {
    FORCED=("${BRANCH_FORCED[@]}")
    board_load_tasks "$SOURCE_FILE" || die "cannot read $TASKS_FILE"
    [[ -n "${TASK_STATUS[$ID]:-}" ]] || die "task $ID not found in $TASKS_FILE (a heading \"### $ID Title — \`status\`\")"
    CURRENT="${TASK_STATUS[$ID]}"

    if [[ "$STATUS" == "needs owner" && -z "$QUESTION" && "$CURRENT" != "needs owner" ]]; then
        refuse "needs owner needs --question \"...\" (the owner sees it as an issue comment)"
    fi

    # Dependencies gate the moves that start or finish work: todo -> in progress or testing, needs owner -> in
    # progress or testing for a task that had not started before its question, and anything -> done. A dependency
    # that is reopened later must not lock its dependants in place: notes, in progress -> testing, QA rejections,
    # answers that resume work and reopened tasks pass.
    local missing limit others gate=0
    if [[ "$STATUS" != "$CURRENT" ]]; then
        case "$STATUS" in
            done) gate=1 ;;
            "in progress" | testing)
                case "$CURRENT" in
                    todo) gate=1 ;;
                    "needs owner") resumes_after_question || gate=1 ;;
                esac
                ;;
        esac
    fi
    if [[ "$gate" == 1 ]]; then
        missing="$(board_missing_deps "$ID")"
        if [[ -n "$missing" ]]; then
            refuse "$ID depends on $(board_describe_ids "$missing"), not done yet"
        fi
    fi

    case "$STATUS" in
        "in progress" | testing)
            if [[ "$STATUS" == "in progress" ]]; then
                limit="${BOARD_WIP_IN_PROGRESS:-2}"
            else
                limit="${BOARD_WIP_TESTING:-3}"
            fi
            [[ "$limit" =~ ^[0-9]+$ ]] || die "invalid WIP limit for $STATUS: $limit"
            others="$(board_ids_with_status "$STATUS" "$ID")"
            read -ra OTHER_IDS <<<"$others"
            if [[ "$CURRENT" != "$STATUS" && ${#OTHER_IDS[@]} -ge "$limit" ]]; then
                if [[ "$CURRENT" == "testing" && "$STATUS" == "in progress" && "$(board_qa_verdict "$QA")" == "rejected" ]]; then
                    echo "set-status: note: In Progress goes over its WIP limit $limit (${others// /, } and $ID): a QA rejection always returns" >&2
                else
                    refuse "the WIP limit of $STATUS is $limit and it holds ${others// /, }; finish or hand over a task first (a QA rejection returns with --qa \"round N rejected by ...\")"
                fi
            fi
            ;;
    esac
}

STAMP="${BOARD_NOW:-$(date -u '+%Y-%m-%d %H:%M')}"
[[ "$STAMP" =~ ^[0-9]{4}-[0-9]{2}-[0-9]{2}\ [0-9]{2}:[0-9]{2}$ ]] || die "invalid BOARD_NOW: $STAMP"
STAMP="$STAMP UTC"

# Screenshots: planning/screenshots/<Txx>/<YYYYMMDD-HHMM>-<name>.png. They are committed first, on their own, and
# linked by that commit's SHA, so that the links outlive the deletion of planning/ (T15).
SHOT_PATHS=()
REPO=""
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
    done
fi

# The QA, question and answer lines, with the screenshot links of commit $1.
build_insert() {
    local sha="$1" links="" i name
    QA_TEXT="$QA"
    QUESTION_TEXT="$QUESTION"
    for i in "${!SHOT_PATHS[@]}"; do
        name="$(basename "${SHOT_PATHS[$i]}" .png)"
        name="${name#"$PREFIX"-}"
        links+=" ![$ID $name](https://github.com/$REPO/blob/$sha/${SHOT_PATHS[$i]}?raw=true)"
    done
    if [[ -n "$links" && -n "$QA_TEXT" ]]; then
        QA_TEXT+="$links"
    elif [[ -n "$links" ]]; then
        QUESTION_TEXT+="$links"
    fi
    # Right under the heading, top to bottom (newest first, so the question precedes its answer when read upwards).
    INSERT=""
    [[ -z "$QA_TEXT" ]] || INSERT+="QA: $STAMP: $QA_TEXT"$'\n'
    [[ -z "$ANSWER" ]] || INSERT+="Owner answer: $STAMP: $ANSWER"$'\n'
    [[ -z "$QUESTION_TEXT" ]] || INSERT+="Owner question: $STAMP: $QUESTION_TEXT"$'\n'
}

edit_tasks() { # edit_tasks <file>
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

TRAILER_TEXT=""
if [[ ${#TRAILERS[@]} -gt 0 ]]; then
    TRAILER_TEXT=$'\n'"$(printf '%s\n' "${TRAILERS[@]}")"
fi

# Undoes the commits of this attempt: HEAD, TASKS.md and the new screenshots back to $1; other staged or unstaged
# changes of the main checkout stay as they are.
undo_to() {
    git reset -q --soft "$1"
    git reset -q "$1" -- "$TASKS_FILE" "${SHOT_PATHS[@]}" >/dev/null 2>&1 || true
    git checkout -q "$1" -- "$TASKS_FILE"
    local path
    for path in "${SHOT_PATHS[@]}"; do
        git cat-file -e "$1:$path" 2>/dev/null || rm -f -- "$path"
    done
}

# One attempt: sync, gates, commits, push. Sets RETRY=1 when origin moved during the attempt (the caller retries).
# Called as a plain command, so that errexit stays in force inside it.
attempt() {
    [[ "$PUSH" == 0 ]] || sync_with_origin
    check_gates

    if [[ "$STATUS" == "$CURRENT" && -z "$QA$QUESTION$ANSWER" ]]; then
        echo "set-status: $ID is already $STATUS"
        exit 0
    fi

    if [[ "$DRY_RUN" == 1 ]]; then
        local preview i
        build_insert "<screenshot commit>"
        preview="$(mktemp)"
        TEMP_FILES+=("$preview")
        cp "$SOURCE_FILE" "$preview"
        edit_tasks "$preview"
        echo "set-status: dry run: $ID $CURRENT -> $STATUS"
        diff -u --label "a/$TASKS_FILE" --label "b/$TASKS_FILE" "$SOURCE_FILE" "$preview" || true
        for i in "${!SOURCES[@]}"; do
            echo "set-status: dry run: copy ${SOURCES[$i]} -> ${SHOT_PATHS[$i]}"
        done
        for reason in "${FORCED[@]}"; do
            echo "set-status: dry run: forced: $reason"
        done
        echo "set-status: dry run: commit \"$SUBJECT\"$([[ "$PUSH" == 1 ]] && echo " and push")"
        exit 0
    fi

    local base shots_sha body i error
    base="$(git rev-parse HEAD)"
    # From the first change on, any exit undoes this attempt (on_exit), until the result is on origin.
    UNDO_BASE="$base"
    shots_sha="$base"
    if [[ ${#SOURCES[@]} -gt 0 ]]; then
        for i in "${!SOURCES[@]}"; do
            mkdir -p "$(dirname "${SHOT_PATHS[$i]}")"
            cp "${SOURCES[$i]}" "${SHOT_PATHS[$i]}"
        done
        git add -- "${SHOT_PATHS[@]}"
        if ! git diff --cached --quiet -- "${SHOT_PATHS[@]}"; then
            commit_paths "board: $ID screenshots

Screenshots: ${SHOT_PATHS[*]}
$TRAILER_TEXT" "${SHOT_PATHS[@]}"
            shots_sha="$(git rev-parse HEAD)"
        fi
    fi

    build_insert "$shots_sha"
    edit_tasks "$TASKS_FILE"

    body=""
    [[ -z "$QA_TEXT" ]] || body+="QA: $QA_TEXT"$'\n'
    [[ -z "$QUESTION_TEXT" ]] || body+="Owner question: $QUESTION_TEXT"$'\n'
    [[ -z "$ANSWER" ]] || body+="Owner answer: $ANSWER"$'\n'
    [[ ${#SHOT_PATHS[@]} -eq 0 ]] || body+="Screenshots: ${SHOT_PATHS[*]}"$'\n'
    for reason in "${FORCED[@]}"; do
        body+="Forced: $reason"$'\n'
    done
    [[ -z "$REASON" ]] || body+="Forced because: $REASON"$'\n'
    commit_paths "$SUBJECT
${body:+
$body}$TRAILER_TEXT" "$TASKS_FILE"
    echo "set-status: $ID -> $STATUS ($(git rev-parse --short HEAD))"

    if [[ "$PUSH" == 0 ]]; then
        UNDO_BASE=""
        return 0
    fi
    if error="$(git push -q origin "HEAD:refs/heads/$BRANCH" 2>&1)"; then
        UNDO_BASE=""
        return 0
    fi
    # A push that failed: landed anyway (a lost reply), rejected because origin moved (retry), or another failure.
    if git fetch -q origin "+refs/heads/$BRANCH:$UPSTREAM" 2>/dev/null; then
        if [[ "$(git rev-parse "$UPSTREAM")" == "$(git rev-parse HEAD)" ]]; then
            UNDO_BASE=""
            return 0
        fi
        undo_to "$base"
        UNDO_BASE=""
        if ! git merge-base --is-ancestor "$UPSTREAM" HEAD; then
            echo "set-status: origin/$BRANCH moved while committing; the commit was undone, trying again" >&2
            RETRY=1
            return 0
        fi
    else
        undo_to "$base"
        UNDO_BASE=""
    fi
    die "push failed (${error//$'\n'/ }); the commit was undone, nothing changed"
}

# commit_paths <message> <path>...: commits only these paths, or exits 1 with git's reason (signing, a hook, no
# identity); on_exit then undoes the attempt.
commit_paths() {
    local message="$1" error
    shift
    error="$(git commit -q -m "$message" -- "$@" 2>&1)" || die "cannot commit (${error//$'\n'/ })"
}

# A random pause after a lost push race (0.1 s up to 0.5 s per lost round, at most 3 s), so that sessions that keep
# colliding drift apart.
backoff() {
    awk -v round="$1" -v r="$RANDOM" 'BEGIN { m = (round < 6 ? round : 6) * 0.5; printf "%.2f\n", 0.1 + m * r / 32767 }'
}

for ((try = 1; try <= TRIES; try++)); do
    RETRY=0
    attempt
    [[ "$RETRY" == 1 ]] || exit 0
    echo "set-status: attempt $try of $TRIES lost the race with another push" >&2
    [[ "$try" == "$TRIES" ]] || sleep "$(backoff "$try")"
done
die "origin/$BRANCH kept moving; nothing changed after $TRIES attempts (BOARD_PUSH_TRIES), try again"
