#!/usr/bin/env bash
#
# Offline tests of tools/board. board.sh and set-status.sh run against temporary git clones of a fixture task list
# (tests/fixtures/TASKS.md, history written by set-status.sh itself) and the stateful stub gh (tests/stub/gh), which
# logs every call and answers from tests/fixtures/state.json. No network, no GitHub API. Each check compares exact
# values: the rendered gh calls (tests/render.jq), the GitHub state after the calls (tests/summary.jq), file edits,
# commits and output, inline below or in tests/expected/.
#
# Usage:
#   tools/board/tests/run.sh [filter]     run the tests whose name contains <filter>
#   BOARD_TEST_UPDATE=1 tools/board/tests/run.sh
#                                         rewrite tests/expected/ from the actual results (review the diff!)
#
# Needs bash 5, git, jq, python3, flock.

set -euo pipefail

TESTS="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
BOARD="$(cd "$TESTS/.." && pwd)"
FIXTURES="$TESTS/fixtures"
EXPECTED="$TESTS/expected"
FILTER="${1:-}"
BRANCH="claude/integration"

for tool in git jq python3 flock; do
    command -v "$tool" >/dev/null || {
        echo "run.sh: $tool is required" >&2
        exit 2
    }
done

WORK="$(mktemp -d "${TMPDIR:-/tmp}/board-tests.XXXXXX")"
trap 'rm -rf "$WORK"' EXIT

# Isolation: the stub is the only gh, a real gh would aim at an unresolvable host, and git ignores the user's config.
export PATH="$TESTS/stub:$PATH"
[[ "$(command -v gh)" == "$TESTS/stub/gh" ]] || {
    echo "run.sh: the stub gh is not first on PATH" >&2
    exit 2
}
export GH_HOST="stub.invalid" GH_CONFIG_DIR="$WORK/gh-config" GH_TOKEN="stub-token" GH_PROMPT_DISABLED=1
export GIT_CONFIG_GLOBAL="$WORK/gitconfig" GIT_CONFIG_NOSYSTEM=1 TZ=UTC LC_ALL=C.UTF-8
git config --file "$GIT_CONFIG_GLOBAL" user.name "Board Tests"
git config --file "$GIT_CONFIG_GLOBAL" user.email "board-tests@example.invalid"
git config --file "$GIT_CONFIG_GLOBAL" commit.gpgsign false
git config --file "$GIT_CONFIG_GLOBAL" advice.detachedHead false
unset BOARD_BRANCH BOARD_MAIN BOARD_MENTION BOARD_NOW BOARD_WIP_IN_PROGRESS BOARD_WIP_TESTING TASKS_FILE GH_STUB_FAIL
unset BOARD_TRAILERS BOARD_NOTIFY_TOKEN BOARD_ATTACH_MAX_KB
unset CI_CONCLUSION CI_RUN_ID CI_RUN_ATTEMPT CI_RUN_URL CI_HEAD_SHA CI_EVENT BOARD_PUSH_TRIES
export BOARD_REPO="m0nclous/board-fixture" BOARD_OWNER="m0nclous" BOARD_NUMBER=5 BOARD_AREAS_FILE="$FIXTURES/areas.tsv"
# set-status.sh: the fixture's integration branch; no waiting for a merge or an edit (one case tests the wait).
export BOARD_INTEGRATION_BRANCH="$BRANCH" BOARD_WAIT=0

RESULTS="$WORK/results"
: >"$RESULTS"
CURRENT=""
CASES=()

# --- Helpers ------------------------------------------------------------------------------------------------------

check() { # check <what> <expected> <actual>
    if [[ "$2" == "$3" ]]; then
        echo ok >>"$RESULTS"
    else
        echo "not ok - $CURRENT: $1" | tee -a "$RESULTS"
        diff -u --label expected --label actual <(printf '%s\n' "$2") <(printf '%s\n' "$3") | sed 's/^/    /' || true
    fi
}

check_file() { # check_file <what> <file in tests/expected> <actual>
    if [[ "${BOARD_TEST_UPDATE:-}" == 1 ]]; then
        printf '%s\n' "$3" >"$EXPECTED/$2"
        echo "updated $EXPECTED/$2"
        return
    fi
    check "$1 ($2)" "$(cat "$EXPECTED/$2")" "$3"
}

check_contains() { # check_contains <what> <needle> <haystack>
    if [[ "$3" == *"$2"* ]]; then
        echo ok >>"$RESULTS"
    else
        echo "not ok - $CURRENT: $1" | tee -a "$RESULTS"
        printf '    missing: %s\n    in: %s\n' "$2" "${3//$'\n'/$'\n'        }"
    fi
}

check_status() { # check_status <what> <expected exit code> <actual exit code>
    check "$1 (exit code)" "$2" "$3"
}

# Each case runs in its own subshell with errexit: a command that fails unexpectedly aborts that case only.
run_cases() {
    local i name rc
    for i in "${!CASES[@]}"; do
        name="${CASES[$i]}"
        [[ -z "$FILTER" || "$name" == *"$FILTER"* ]] || continue
        CURRENT="$name"
        set +e
        (
            set -e
            "case_$((i + 1))"
        )
        rc=$?
        set -e
        if [[ "$rc" != 0 ]]; then
            echo "not ok - $name: aborted with exit code $rc" | tee -a "$RESULTS"
        fi
    done
}

# status "<YYYY-MM-DD HH:MM>" <set-status arguments>: set-status.sh in the world at a fixed clock.
status() {
    local when="$1"
    shift
    (
        cd "$REPO"
        BOARD_MAIN="$REPO" BOARD_NOW="$when" GIT_AUTHOR_DATE="${when/ /T}:00Z" GIT_COMMITTER_DATE="${when/ /T}:00Z" \
            "$BOARD/set-status.sh" "$@"
    )
}

board() {
    (cd "$REPO" && "$BOARD/board.sh" "$@")
}

calls() {
    [[ -s "$GH_STUB_LOG" ]] || return 0
    jq -r -f "$TESTS/render.jq" "$GH_STUB_LOG"
}

# The calls that change something on GitHub.
writes() {
    calls | grep -Ev '^gh (api user|repo view|label list|issue (list|view)|project (view|field-list|item-list)|run view)( |$)' || true
}

reset_log() {
    : >"$GH_STUB_LOG"
}

summary() {
    jq -r -f "$TESTS/summary.jq" "$GH_STUB_STATE"
}

state_edit() { # state_edit <jq filter>
    jq "$1" "$GH_STUB_STATE" >"$GH_STUB_STATE.new"
    mv "$GH_STUB_STATE.new" "$GH_STUB_STATE"
}

sha1() {
    if command -v sha1sum >/dev/null; then
        sha1sum | cut -c1-40
    else
        shasum -a 1 | cut -c1-40
    fi
}

# The comments of one issue in the summary.
comments_of_issue() {
    summary | awk -v issue="issue #$1 " 'index($0, issue) == 1 { on = 1; next } /^(issue|field|card|label) / { on = 0 } on'
}

tasks_sum() {
    git -C "$REPO" rev-parse HEAD
    sha1 <"$REPO/planning/TASKS.md"
}

# The template world: a bare origin and a clone of the fixture task list whose history set-status.sh writes.
build_template() {
    local template="$WORK/template"
    git init -q --bare "$template/origin.git"
    git init -q -b "$BRANCH" "$template/repo"
    mkdir -p "$template/repo/planning"
    cp "$FIXTURES/TASKS.md" "$template/repo/planning/TASKS.md"
    git -C "$template/repo" add planning/TASKS.md
    GIT_AUTHOR_DATE="2026-09-30T08:00:00Z" GIT_COMMITTER_DATE="2026-09-30T08:00:00Z" \
        git -C "$template/repo" commit -q -m "Add the task list"
    git -C "$template/repo" remote add origin "$template/origin.git"
    git -C "$template/repo" push -q origin HEAD

    REPO="$template/repo"
    {
        status "2026-10-01 09:00" T01 "in progress"
        status "2026-10-02 12:00" T01 testing
        status "2026-10-03 21:00" T01 "done" --qa "round 1 approved by the spec and adversarial testers"
        status "2026-10-09 10:00" T02 "in progress"
        status "2026-10-09 11:00" T03 "in progress"
        status "2026-10-09 15:00" T02 testing
        status "2026-10-10 08:00" T03 testing
        status "2026-10-10 09:00" T02 "in progress" --qa "round 1 rejected by the adversarial tester: the cache key ignores the scope"
        status "2026-10-10 10:00" T04 "in progress"
        status "2026-10-11 08:30" T04 "needs owner" --question "Keep the 1 s server cache for the live endpoint?"
    } >"$WORK/template-history.out" 2>&1
}

# A fresh copy of the template world with the fixture GitHub state.
new_world() {
    rm -rf "$WORK/world"
    cp -a "$WORK/template" "$WORK/world"
    REPO="$WORK/world/repo"
    git -C "$REPO" remote set-url origin "$WORK/world/origin.git"
    export GH_STUB_STATE="$WORK/world/state.json" GH_STUB_LOG="$WORK/world/gh.log"
    cp "$FIXTURES/state.json" "$GH_STUB_STATE"
    : >"$GH_STUB_LOG"
    OUT="$WORK/world/out"
    ERR="$WORK/world/err"
}

build_template

# --- set-status.sh: the history of the template ----------------------------------------------------------------------

CASES+=("history")
case_1() {
    new_world
    check_file "TASKS.md after the history" "TASKS.history.md" "$(cat "$REPO/planning/TASKS.md")"
    check "commit subjects" "board: T04 -> needs owner (question)
board: T04 -> in progress
board: T02 -> in progress (QA)
board: T03 -> testing
board: T02 -> testing
board: T03 -> in progress
board: T02 -> in progress
board: T01 -> done (QA)
board: T01 -> testing
board: T01 -> in progress
Add the task list" "$(git -C "$REPO" log --format=%s)"
    check "QA commit message (no trailers unless asked for)" "board: T02 -> in progress (QA)

QA: round 1 rejected by the adversarial tester: the cache key ignores the scope" "$(git -C "$REPO" log -1 --format=%B HEAD~2)"
    check "every commit touches only TASKS.md" "planning/TASKS.md" "$(git -C "$REPO" log --format= --name-only | sort -u | sed '/^$/d')"
    check "pushed to origin" "$(git -C "$REPO" rev-parse HEAD)" "$(git -C "$WORK/world/origin.git" rev-parse "$BRANCH")"
    check "author dates" "2026-10-11T08:30:00Z" "$(git -C "$REPO" log -1 --format=%aI | sed 's/+00:00$/Z/')"
}

# --- board.sh list -----------------------------------------------------------------------------------------------------

CASES+=("list")
case_2() {
    new_world
    check "list output" "T00  done         phase:0  process     Bootstrap
T01  done         phase:0  exporter    Shared contracts
T02  in progress  phase:B  exporter    Exposition
T03  testing      phase:B  attempts    Attempts
T04  needs owner  phase:B  queues      Queue state
T05  todo         phase:B  grafana     Grafana port [ready]
T06  todo         phase:C  live        Live backend [blocked by T02, T03, T04, T05]
T07  todo         phase:C  docs        Release [blocked by T02, T03, T04, T05, T06]" "$(board list)"
    check "list makes no gh call" "" "$(calls)"
    rc=0
    board bogus >"$OUT" 2>&1 || rc=$?
    check_status "unknown command" 1 "$rc"
    check_contains "unknown command prints the usage" "tools/board/board.sh sync [--dry-run]" "$(cat "$OUT")"
}

# --- board.sh sync ------------------------------------------------------------------------------------------------

CASES+=("sync offline dry run")
case_3() {
    new_world
    (cd "$REPO" && env -u GH_TOKEN "$BOARD/board.sh" sync --dry-run) >"$OUT" 2>"$ERR"
    check "no gh call at all" "" "$(calls)"
    check_file "output" "sync-offline.out" "$(cat "$OUT")"
    check "no warning" "" "$(cat "$ERR")"
}

CASES+=("sync first run")
case_4() {
    new_world
    board sync >"$OUT" 2>"$ERR"
    check_file "gh calls" "sync-first.calls" "$(calls)"
    check "warnings" "board: warning: the board's Status field has no option \"Needs owner\"; add it in the board settings
board: warning: https://github.com/m0nclous/board-fixture/issues/5 stays in its current column (no \"Needs owner\" option)" "$(cat "$ERR")"
    check_file "GitHub state" "state-first.txt" "$(summary)"
    check "task issue body" "Mirrors task **T03** of [\`planning/TASKS.md\`](https://github.com/m0nclous/board-fixture/blob/$BRANCH/planning/TASKS.md) (scope, dependencies, acceptance and QA verdicts live there).

Status, labels, QA and owner comments and the board card are synced from that file by \`tools/board/board.sh\`; change the file (\`tools/board/set-status.sh\`), not this issue." \
        "$(jq -r '.issues[] | select(.number == 4) | .body' "$GH_STUB_STATE")"
}

CASES+=("sync second run is idempotent")
case_5() {
    new_world
    board sync >/dev/null 2>&1
    reset_log
    board sync >"$OUT" 2>"$ERR"
    check_file "gh calls (reads only)" "sync-second.calls" "$(calls)"
    check "no write" "" "$(writes)"
}

CASES+=("sync dry run with a token")
case_6() {
    new_world
    board sync >/dev/null 2>&1
    status "2026-10-12 09:00" T05 "in progress" >/dev/null
    before="$(summary)"
    reset_log
    board sync --dry-run >"$OUT" 2>"$ERR"
    check "no write" "" "$(writes)"
    check "state unchanged" "$before" "$(summary)"
    check "planned writes" "dry-run: gh issue edit 6 --repo m0nclous/board-fixture --add-label in\\ progress --remove-label ready
dry-run: set Status of https://github.com/m0nclous/board-fixture/issues/6 to \"In Progress\"
dry-run: set Started of https://github.com/m0nclous/board-fixture/issues/6 to \"2026-10-12\"" "$(grep '^dry-run:' "$OUT")"
}

CASES+=("sync after status changes")
case_7() {
    new_world
    board sync >/dev/null 2>&1
    {
        status "2026-10-12 09:00" T05 "in progress"
        status "2026-10-12 10:00" T04 "needs owner" --answer "Yes, keep the 1 s cache"
        status "2026-10-12 11:00" T03 "done" --qa "round 1 approved by the spec and adversarial testers"
    } >/dev/null
    reset_log
    board sync >"$OUT" 2>"$ERR"
    check_file "gh writes" "sync-changes.calls" "$(writes)"
    check "T03 issue" "issue #4 CLOSED [T03] Attempts [task, phase: B]" "$(summary | grep '^issue #4 ')"
    check "T03 card" "card #4 Status=Done; Phase=B; Area=attempts; QA rounds=1; Started=2026-10-09; Finished=2026-10-12" "$(summary | grep '^card #4 ')"
    check "T05 card" "card #6 Status=In Progress; Phase=B; Area=grafana; QA rounds=0; Started=2026-10-12" "$(summary | grep '^card #6 ')"
    reset_log
    board sync >/dev/null 2>&1
    check "the next sync writes nothing" "" "$(writes)"
}

CASES+=("sync repairs stale labels, titles and states")
case_8() {
    new_world
    board sync >/dev/null 2>&1
    state_edit '(.issues[] | select(.number == 1) | .labels) += ["phase: X"]
        | (.issues[] | select(.number == 2) | .title) = "[T01] Old title"
        | (.issues[] | select(.number == 3) | .labels) += ["ready", "bug"]
        | (.issues[] | select(.number == 6) | .state) = "CLOSED"
        | (.issues[] | select(.number == 7) | .labels) -= ["blocked"]
        | (.labels) += [{"name": "phase: X", "color": "c5def5", "description": "Phase X"}]
        | (.labels[] | select(.name == "ready") | .color) = "ffffff"'
    reset_log
    board sync >"$OUT" 2>"$ERR"
    check "gh writes" "gh label create ready --repo m0nclous/board-fixture --color c2e0c6 --description 'Todo task whose dependencies are all done' --force
gh issue edit 1 --repo m0nclous/board-fixture --remove-label 'phase: X'
gh issue edit 2 --repo m0nclous/board-fixture --title '[T01] Shared contracts'
gh issue edit 3 --repo m0nclous/board-fixture --remove-label ready
gh issue reopen 6 --repo m0nclous/board-fixture
gh issue edit 7 --repo m0nclous/board-fixture --add-label blocked" "$(writes)"
    check "an unmanaged label stays" "issue #3 OPEN [T02] Exposition [task, phase: B, in progress, bug]" "$(summary | grep '^issue #3 ')"
}

CASES+=("sync blocked comments follow the missing dependencies")
case_9() {
    new_world
    board sync >/dev/null 2>&1
    check "T07 starts with one blocked comment" "  comment: **Blocked** by T02, T03, T04, T05, T06: this task becomes ready when they are done.\\n\\n<!-- blocked:T02,T03,T04,T05,T06 -->" \
        "$(comments_of_issue 8)"
    blocked_comment() { jq -r --argjson n "$1" '.issues[] | select(.number == $n) | .comments[] | select(.body | contains("<!-- blocked:")) | .id' "$GH_STUB_STATE"; }
    c7="$(blocked_comment 7)"
    c8="$(blocked_comment 8)"
    {
        status "2026-10-12 09:00" T02 testing
        status "2026-10-12 10:00" T02 "done" --qa "round 2 approved by the spec and adversarial testers"
        status "2026-10-12 11:00" T03 "done" --qa "round 1 approved by the spec and adversarial testers"
        status "2026-10-12 12:00" T04 "done" --answer "Yes, keep the 1 s cache" --qa "round 1 approved by the spec and adversarial testers"
        status "2026-10-12 13:00" T05 "done"
    } >/dev/null 2>&1
    reset_log
    board sync >"$OUT" 2>"$ERR"
    check "T06 turns ready" "issue #7 OPEN [T06] Live backend [task, phase: C, ready]" "$(summary | grep '^issue #7 ')"
    check "T07: the same comment, edited" "  comment: **Blocked** by T06: this task becomes ready when they are done.\\n\\n<!-- blocked:T06 -->" \
        "$(comments_of_issue 8)"
    check "T06: its comment says ready" "  comment: **Ready:** every dependency is done.\\n\\n<!-- blocked: -->" "$(comments_of_issue 7)"
    check "the comment writes" "gh api --method PATCH repos/m0nclous/board-fixture/issues/comments/$c7 -f 'body=**Ready:** every dependency is done.\\n\\n<!-- blocked: -->'
gh api --method PATCH repos/m0nclous/board-fixture/issues/comments/$c8 -f 'body=**Blocked** by T06: this task becomes ready when they are done.\\n\\n<!-- blocked:T06 -->'" \
        "$(writes | grep '^gh api ')"
    check "labels of T06 and T07" "gh issue edit 7 --repo m0nclous/board-fixture --add-label ready --remove-label blocked" "$(writes | grep '^gh issue edit [78] ')"
    reset_log
    board sync >/dev/null 2>&1
    check "the next sync writes nothing" "" "$(writes)"
    check "and reads no comments of the ready task any more" "" "$(calls | grep '^gh issue view 7 ' || true)"
}

CASES+=("sync without a board")
case_10() {
    new_world
    (cd "$REPO" && BOARD_NUMBER="" "$BOARD/board.sh" sync) >/dev/null 2>"$ERR"
    check "no project call" "" "$(calls | grep '^gh project' || true)"
    check "no warning" "" "$(cat "$ERR")"
}

CASES+=("sync board fields with existing fields and options")
case_11() {
    new_world
    state_edit '(.project.fields[] | select(.name == "Status") | .options) += [{"id": "st_owner", "name": "Needs owner"}]
        | .project.fields += [{"id": "PVTSSF_phase", "name": "Phase", "type": "ProjectV2SingleSelectField",
            "options": [{"id": "ph_0", "name": "0"}, {"id": "ph_b", "name": "B"}]}]'
    board sync >"$OUT" 2>"$ERR"
    check "field-create calls" "gh project field-create 5 --owner @me --name Area --data-type SINGLE_SELECT --single-select-options exporter,attempts,queues,http,batches,discovery,horizon-ui,grafana,live,ci,docs,process
gh project field-create 5 --owner @me --name 'QA rounds' --data-type NUMBER
gh project field-create 5 --owner @me --name Started --data-type DATE
gh project field-create 5 --owner @me --name Finished --data-type DATE" "$(calls | grep '^gh project field-create')"
    check "warnings" "board: warning: the board's Phase field has no option \"C\"; add it in the board settings" "$(cat "$ERR")"
    check "cards" "card #5 Status=Needs owner; Phase=B; Area=queues; QA rounds=0; Started=2026-10-10
card #7 Status=Todo; Area=live; QA rounds=0
card #8 Status=Todo; Area=docs; QA rounds=0" "$(summary | grep -E '^card #(5|7|8) ')"
}

# --- board.sh report ----------------------------------------------------------------------------------------------

CASES+=("report")
case_12() {
    new_world
    board sync >/dev/null 2>&1
    reset_log
    board report >"$OUT" 2>"$ERR"
    check_file "gh calls" "report-first.calls" "$(calls)"
    check_file "report body" "report.md" "$(jq -r '.issues[] | select(.title == "[Board] Flow report") | .body' "$GH_STUB_STATE")"
    check "report issue" "issue #9 OPEN [Board] Flow report [report] pinned" "$(summary | grep '^issue #9 ')"
    check "the report has no unparsed or missing-area warning" "" "$(cat "$ERR")"
    reset_log
    board report >"$OUT" 2>"$ERR"
    check "a second report writes nothing" "" "$(writes)"
    check "a second report says so" "report  #9 is up to date" "$(cat "$OUT")"

    (cd "$REPO" && CI_CONCLUSION=failure CI_RUN_ID=900 "$BOARD/board.sh" ci) >/dev/null
    state_edit '(.issues[] | select(.number == 9) | .state) = "CLOSED"'
    reset_log
    board report >"$OUT" 2>"$ERR"
    check "red CI: the body is rewritten and the issue reopened" "gh issue edit 9 --repo m0nclous/board-fixture --body '<report body>'
gh issue reopen 9 --repo m0nclous/board-fixture" "$(writes)"
    check "red CI section" "**Red:** #10 (stop the line). Nothing moves to \`done\` until the \`tests\` workflow is green again." \
        "$(jq -r '.issues[] | select(.number == 9) | .body' "$GH_STUB_STATE" | sed -n '/^## Integration branch/{n;n;p}')"
}

CASES+=("report pin failure")
case_13() {
    new_world
    rc=0
    (cd "$REPO" && GH_STUB_FAIL="issue pin" "$BOARD/board.sh" report) >"$OUT" 2>"$ERR" || rc=$?
    check_status "a failed pin is only a warning" 0 "$rc"
    check "warning" "gh stub: injected failure for: gh issue pin 1 --repo m0nclous/board-fixture
board: warning: could not pin #1 (at most 3 issues can be pinned)" "$(cat "$ERR")"
    reset_log
    (cd "$REPO" && GH_STUB_FAIL="issue pin" "$BOARD/board.sh" report) >"$OUT" 2>"$ERR"
    check "still failing: tried again, still a warning" "gh issue pin 1 --repo m0nclous/board-fixture" "$(writes)"
    reset_log
    board report >"$OUT" 2>"$ERR"
    check "the next run pins it" "gh issue pin 1 --repo m0nclous/board-fixture" "$(writes)"
    check "pinned" "issue #1 OPEN [Board] Flow report [report] pinned" "$(summary | grep '^issue #1 ')"
    reset_log
    board report >"$OUT" 2>"$ERR"
    check "then nothing" "" "$(writes)"
}

CASES+=("report offline dry run")
case_14() {
    new_world
    (cd "$REPO" && env -u GH_TOKEN "$BOARD/board.sh" report --dry-run) >"$OUT" 2>"$ERR"
    check "no gh call" "" "$(calls)"
    check_contains "planned create" "dry-run: gh issue create --repo m0nclous/board-fixture --title \\[Board\\]\\ Flow\\ report --body" "$(cat "$OUT")"
    check_contains "CI state unknown" "Unknown (offline dry run)." "$(cat "$OUT")"
}

# --- board.sh ci ----------------------------------------------------------------------------------------------------

# The hidden job record of a red comment (base64 JSON) as <record>; record_of decodes the one of a comment body.
norecord() {
    sed 's/<!-- ci-jobs:[A-Za-z0-9+\/=]* -->/<!-- ci-jobs:<record> -->/g'
}

record_of() { # record_of <issue number> <run id>: the decoded job record of that run's red comment
    jq -r --argjson n "$1" --arg run "<!-- ci-run:$2-" '.issues[] | select(.number == $n) | .comments[] | select(.body | contains($run))
        | .body | capture("<!-- ci-jobs:(?<r>[A-Za-z0-9+/=]+) -->").r | @base64d | fromjson | to_entries[] | "\(.key)=\(.value)"' "$GH_STUB_STATE"
}

ci() { # ci <conclusion> <run id> [<head sha>] [extra board.sh arguments]
    (
        cd "$REPO"
        CI_CONCLUSION="$1" CI_RUN_ID="$2" CI_RUN_ATTEMPT=1 CI_RUN_URL="https://github.com/$BOARD_REPO/actions/runs/$2" \
            CI_HEAD_SHA="${3:-}" "$BOARD/board.sh" ci "${@:4}"
    )
}

CASES+=("ci stop the line")
case_15() {
    new_world
    mkdir -p "$REPO/src"
    echo '<?php' >"$REPO/src/app.php"
    git -C "$REPO" add src/app.php
    git -C "$REPO" commit -q -m "Add code"
    git -C "$REPO" push -q origin HEAD
    head="$(git -C "$REPO" rev-parse HEAD)"
    short="${head:0:7}"
    ci failure 900 "$head" >"$OUT" 2>"$ERR"
    check_file "red: gh calls" "ci-red.calls" "$(calls | sed "s/$short/<head>/g" | norecord)"
    check "red: the comment records the conclusion of every job that ran" "PHP 8.2 / L^11.23 / prefer-lowest / predis / redis:6.2-alpine=failure
PHP 8.3 / L13.* / prefer-stable / phpredis / redis:7.4-alpine=success
Static analysis and style=success
Dist archive contents=failure" "$(record_of 1 900)"
    check "red: issue body" "<!-- board:stop-the-line -->
The \`tests\` workflow is red on the integration branch \`$BRANCH\` since [tests run 900](https://github.com/m0nclous/board-fixture/actions/runs/900) (attempt 1) at \`$short\` on \`$BRANCH\`.

**Stop the line.** While this issue is open nothing moves to \`done\`: testers do not post \`QA verdict: APPROVED\`, nobody runs \`set-status.sh <Txx> done\` and the integrator merges no task branch. Make the branch green first (fix forward or revert the breaking merge). The board workflow comments every further red run here and closes this issue when a green run has run every job that is still red." \
        "$(jq -r '.issues[] | select(.number == 1) | .body' "$GH_STUB_STATE")"

    reset_log
    ci failure 900 "$head" >"$OUT" 2>"$ERR"
    check "the same run again: reads only" "gh label list --repo m0nclous/board-fixture --limit 1000 --json name,color,description --jq '.[] | [.name, (.color | ascii_downcase), (.description // \"\")] | map(gsub(\"[\\n\\u001f]\"; \" \")) | join(\"\\u001f\")'
gh issue list --repo m0nclous/board-fixture --state open --label stop-the-line --limit 100 --json number --jq '.[].number'
gh issue view 1 --repo m0nclous/board-fixture --json comments --jq '[.comments[] | {url, body}]'" "$(calls)"
    check "the same run again: output" "ci      tests run 900 (attempt 1) is already on #1" "$(cat "$OUT")"

    reset_log
    ci failure 901 "$head" >"$OUT" 2>"$ERR"
    check "another red run: one comment" "gh issue comment 1 --repo m0nclous/board-fixture --body '**Red:** [tests run 901](https://github.com/m0nclous/board-fixture/actions/runs/901) (attempt 1) at \`<head>\` on \`$BRANCH\`.\\n\\nFailed jobs:\\n- Coverage (pcov, minimum 85 %)\\n\\n<!-- ci-run:901-1 -->\\n<!-- ci-jobs:<record> -->'" \
        "$(writes | sed "s/$short/<head>/g" | norecord)"

    reset_log
    older="$(git -C "$REPO" rev-parse HEAD~1)"
    ci success 902 "$older" >"$OUT" 2>"$ERR"
    check "green run of a commit before a code change: no call" "" "$(calls)"
    check "green run of a commit before a code change: output" "ci      ignoring tests run 902 of ${older:0:7}: the branch head $short changed code since, its run decides" "$(cat "$OUT")"

    ci success 905 "0123456789abcdef0123456789abcdef01234567" >"$OUT" 2>"$ERR"
    check "a run of a commit outside the history: no call" "" "$(calls)"
    check "a run of a commit outside the history: output" "ci      ignoring tests run 905 of 0123456: not in the history of the branch head $short" "$(cat "$OUT")"

    ci cancelled 903 "$head" >"$OUT" 2>"$ERR"
    check "cancelled run: no call" "" "$(calls)"
    check "cancelled run: output" "ci      tests run 903 concluded \"cancelled\": the line stays as it is" "$(cat "$OUT")"

    # A status change after the code commit gets no tests run (paths-ignore): the code commit's run still decides.
    status "2026-10-12 09:00" T05 "in progress" >/dev/null
    head2="$(git -C "$REPO" rev-parse HEAD)"
    ci success 902 "$head" >"$OUT" 2>"$ERR"
    check "green run of the code commit under board commits: close with a comment" "gh issue list --repo m0nclous/board-fixture --state open --label stop-the-line --limit 100 --json number --jq '.[].number'
gh run view 902 --repo m0nclous/board-fixture --attempt 1 --json jobs --jq '[.jobs[] | select((.conclusion // \"\") | IN(\"\", \"skipped\", \"neutral\") | not) | {name, conclusion}]'
gh issue view 1 --repo m0nclous/board-fixture --json comments --jq '[.comments[] | {url, body}]'
gh issue close 1 --repo m0nclous/board-fixture --reason completed --comment '**Green again:** [tests run 902](https://github.com/m0nclous/board-fixture/actions/runs/902) (attempt 1) at \`<head>\` on \`$BRANCH\`. The line moves again.\\n\\n<!-- ci-green:902-1 -->'" \
        "$(calls | sed "s/$short/<head>/g")"
    check_contains "it says why the older run decides" "ci      tests run 902 of $short decides: only planning/TASKS.md planning/screenshots changed up to the branch head ${head2:0:7}" "$(cat "$OUT")"

    reset_log
    ci success 902 "$head" >"$OUT" 2>"$ERR"
    check "green again: reads only" "gh issue list --repo m0nclous/board-fixture --state open --label stop-the-line --limit 100 --json number --jq '.[].number'" "$(calls)"
    check_contains "green again: output" "ci      the line is clear" "$(cat "$OUT")"
    check "final state" "label stop-the-line #b60205 The integration branch is red: nothing moves to done
issue #1 CLOSED [CI] The integration branch is red [stop-the-line]
  comment: **Red:** [tests run 900](https://github.com/m0nclous/board-fixture/actions/runs/900) (attempt 1) at \`<head>\` on \`$BRANCH\`.\\n\\nFailed jobs:\\n- PHP 8.2 / L^11.23 / prefer-lowest / predis / redis:6.2-alpine\\n- Dist archive contents\\n\\n<!-- ci-run:900-1 -->\\n<!-- ci-jobs:<record> -->
  comment: **Red:** [tests run 901](https://github.com/m0nclous/board-fixture/actions/runs/901) (attempt 1) at \`<head>\` on \`$BRANCH\`.\\n\\nFailed jobs:\\n- Coverage (pcov, minimum 85 %)\\n\\n<!-- ci-run:901-1 -->\\n<!-- ci-jobs:<record> -->
  comment: **Green again:** [tests run 902](https://github.com/m0nclous/board-fixture/actions/runs/902) (attempt 1) at \`<head>\` on \`$BRANCH\`. The line moves again.\\n\\n<!-- ci-green:902-1 -->" \
        "$(summary | grep -E '^(label stop-the-line|issue #1 |  comment)' | sed "s/$short/<head>/g" | norecord)"

    reset_log
    ci failure 904 "$head2" >"$OUT" 2>"$ERR"
    check "red after green opens a new issue" "issue #2 OPEN [CI] The integration branch is red [stop-the-line]" "$(summary | grep '^issue #2 ')"
}

CASES+=("ci offline dry run and bad input")
case_16() {
    new_world
    (cd "$REPO" && env -u GH_TOKEN CI_CONCLUSION=failure CI_RUN_ID=900 "$BOARD/board.sh" ci --dry-run) >"$OUT" 2>"$ERR"
    check "no gh call" "" "$(calls)"
    check_contains "planned create" "dry-run: gh issue create --repo m0nclous/board-fixture --title \\[CI\\]\\ The\\ integration\\ branch\\ is\\ red" "$(cat "$OUT")"
    check_contains "planned comment" "dry-run: gh issue comment \\<new\\> --repo m0nclous/board-fixture --body" "$(cat "$OUT")"
    rc=0
    (cd "$REPO" && CI_CONCLUSION=failure CI_RUN_ID=abc "$BOARD/board.sh" ci) >"$OUT" 2>"$ERR" || rc=$?
    check_status "a bad run id" 1 "$rc"
    check "a bad run id: message" "board: ci needs CI_CONCLUSION and a numeric CI_RUN_ID" "$(cat "$ERR")"
}

# --- set-status.sh gates --------------------------------------------------------------------------------------------

CASES+=("set-status dependencies")
case_17() {
    new_world
    before="$(tasks_sum)"
    rc=0
    status "2026-10-12 09:00" T06 "in progress" >"$OUT" 2>"$ERR" || rc=$?
    check_status "refused" 1 "$rc"
    check "message" "set-status: T06 depends on T02 (in progress), T03 (testing), T04 (needs owner), T05 (todo), not done yet; --force --reason \"why\" overrides" "$(cat "$ERR")"
    check "nothing changed" "$before" "$(tasks_sum)"
    rc=0
    status "2026-10-12 09:00" T07 "done" >"$OUT" 2>"$ERR" || rc=$?
    check_status "done is gated too" 1 "$rc"
    rc=0
    status "2026-10-12 09:00" T06 "in progress" --force >"$OUT" 2>"$ERR" || rc=$?
    check "--force without a reason" "1 set-status: --force needs --reason \"why\" (the commit records it)" "$rc $(cat "$ERR")"
    rc=0
    status "2026-10-12 09:00" T06 "in progress" --reason "x" >"$OUT" 2>"$ERR" || rc=$?
    check "--reason without --force" "1 set-status: --reason goes with --force" "$rc $(cat "$ERR")"
    check "nothing changed by the refusals" "$before" "$(tasks_sum)"

    status "2026-10-12 09:00" T06 "in progress" --force --reason "the owner asked for a spike" >"$OUT" 2>"$ERR"
    check "forced: warning" "set-status: --force: T06 depends on T02 (in progress), T03 (testing), T04 (needs owner), T05 (todo), not done yet" "$(cat "$ERR")"
    check "forced: heading" "### T06 Live backend — \`in progress\` (depends on T02–T04, T05)" "$(grep '^### T06 ' "$REPO/planning/TASKS.md")"
    check "forced: commit message records what and why" "board: T06 -> in progress

Forced: T06 depends on T02 (in progress), T03 (testing), T04 (needs owner), T05 (todo), not done yet
Forced because: the owner asked for a spike" "$(git -C "$REPO" log -1 --format=%B)"

    status "2026-10-12 10:00" T06 todo >/dev/null
    check "todo is never gated" "### T06 Live backend — \`todo\` (depends on T02–T04, T05)" "$(grep '^### T06 ' "$REPO/planning/TASKS.md")"
}

CASES+=("set-status WIP limits")
case_18() {
    new_world
    status "2026-10-12 09:00" T05 "in progress" >/dev/null
    before="$(tasks_sum)"
    rc=0
    status "2026-10-12 10:00" T04 "in progress" --answer "Yes" >"$OUT" 2>"$ERR" || rc=$?
    check_status "In Progress full" 1 "$rc"
    check "In Progress full: message" "set-status: the WIP limit of in progress is 2 and it holds T02, T05; finish or hand over a task first (a QA rejection returns with --qa \"round N rejected by ...\"); --force --reason \"why\" overrides" "$(cat "$ERR")"
    check "In Progress full: nothing changed" "$before" "$(tasks_sum)"

    status "2026-10-12 10:30" T03 "in progress" --qa "round 1 rejected by the spec tester: a missing test" >"$OUT" 2>"$ERR"
    check "a QA rejection returns over the limit" "set-status: note: In Progress goes over its WIP limit 2 (T02, T05 and T03): a QA rejection always returns" "$(cat "$ERR")"
    check "a QA rejection returns over the limit: heading" "### T03 Attempts — \`in progress\`" "$(grep '^### T03 ' "$REPO/planning/TASKS.md")"

    BOARD_WIP_IN_PROGRESS=4 status "2026-10-12 11:00" T04 "in progress" --answer "Yes" >"$OUT" 2>"$ERR"
    check "a higher limit lets it in" "### T04 Queue state — \`in progress\`" "$(grep '^### T04 ' "$REPO/planning/TASKS.md")"

    status "2026-10-12 12:00" T03 testing >/dev/null
    rc=0
    BOARD_WIP_TESTING=1 status "2026-10-12 13:00" T02 testing >"$OUT" 2>"$ERR" || rc=$?
    check_status "Testing full" 1 "$rc"
    check "Testing full: message" "set-status: the WIP limit of testing is 1 and it holds T03; finish or hand over a task first (a QA rejection returns with --qa \"round N rejected by ...\"); --force --reason \"why\" overrides" "$(cat "$ERR")"
    BOARD_WIP_TESTING=1 status "2026-10-12 13:00" T02 testing --force --reason "pairing on both" >"$OUT" 2>"$ERR"
    check "Testing full: forced" "set-status: --force: the WIP limit of testing is 1 and it holds T03; finish or hand over a task first (a QA rejection returns with --qa \"round N rejected by ...\")" "$(cat "$ERR")"
}

CASES+=("set-status needs owner and notes")
case_19() {
    new_world
    rc=0
    status "2026-10-12 09:00" T05 "needs owner" >"$OUT" 2>"$ERR" || rc=$?
    check_status "needs owner without a question" 1 "$rc"
    check "needs owner without a question: message" "set-status: needs owner needs --question \"...\" (the owner sees it as an issue comment); --force --reason \"why\" overrides" "$(cat "$ERR")"
    status "2026-10-12 09:00" T05 "needs owner" --question "Port the alert sound too?" >/dev/null
    status "2026-10-12 10:00" T05 "needs owner" --answer "Yes, as a file" >/dev/null
    status "2026-10-12 11:00" T05 todo --qa "round 1 approved by nobody, just a note" >/dev/null
    check "lines under the heading, newest first" "### T05 Grafana port — \`todo\` (depends only on T00; start first)
QA: 2026-10-12 11:00 UTC: round 1 approved by nobody, just a note
Owner answer: 2026-10-12 10:00 UTC: Yes, as a file
Owner question: 2026-10-12 09:00 UTC: Port the alert sound too?
Golden port." "$(sed -n '/^### T05 /,/^Golden port\./p' "$REPO/planning/TASKS.md")"
    check "subjects" "board: T05 -> todo (QA)
board: T05 -> needs owner (answer)
board: T05 -> needs owner (question)" "$(git -C "$REPO" log -3 --format=%s)"
    board sync >/dev/null 2>&1
    check "comments of T05, oldest first" "  comment: **Owner question** for @m0nclous: 2026-10-12 09:00 UTC: Port the alert sound too?\\n\\n<!-- owner-question:$(printf '%s' '2026-10-12 09:00 UTC: Port the alert sound too?' | sha1 | cut -c1-12) -->
  comment: **Owner answer:** 2026-10-12 10:00 UTC: Yes, as a file\\n\\n<!-- owner-answer:$(printf '%s' '2026-10-12 10:00 UTC: Yes, as a file' | sha1 | cut -c1-12) -->
  comment: **QA:** 2026-10-12 11:00 UTC: round 1 approved by nobody, just a note\\n\\n<!-- qa:$(printf '%s' '2026-10-12 11:00 UTC: round 1 approved by nobody, just a note' | sha1 | cut -c1-12) -->" \
        "$(comments_of_issue 6)"
    (cd "$REPO" && BOARD_MENTION=someone "$BOARD/board.sh" sync --dry-run) >"$OUT" 2>&1
    check "comments are posted once" "" "$(grep 'comment' "$OUT" || true)"
}

CASES+=("set-status input errors, no-op and dry run")
case_20() {
    new_world
    before="$(tasks_sum)"
    rc=0
    status "2026-10-12 09:00" T99 todo >"$OUT" 2>"$ERR" || rc=$?
    check "unknown task" "1 set-status: task T99 not found in planning/TASKS.md (a heading \"### T99 Title — \`status\`\")" "$rc $(cat "$ERR")"
    rc=0
    status "2026-10-12 09:00" T05 started >"$OUT" 2>"$ERR" || rc=$?
    check "invalid status" "1 set-status: invalid status: started" "$rc $(cat "$ERR")"
    rc=0
    status "2026-10-12 09:00" T05 todo --qa >"$OUT" 2>"$ERR" || rc=$?
    check "--qa without a text" "1 set-status: --qa needs a text" "$rc $(cat "$ERR")"
    rc=0
    status "2026-10-12 09:00" T05 todo --bogus >"$OUT" 2>"$ERR" || rc=$?
    check "unknown option" "1 set-status: unknown option: --bogus" "$rc $(cat "$ERR")"
    status "2026-10-12 09:00" T02 "in progress" >"$OUT" 2>"$ERR"
    check "already in that status" "set-status: T02 is already in progress" "$(cat "$OUT")"
    check "nothing changed" "$before" "$(tasks_sum)"

    status "2026-10-12 09:00" T05 "in progress" --qa "picked up" --dry-run >"$OUT" 2>"$ERR"
    check "dry run output" "set-status: dry run: T05 todo -> in progress
--- a/planning/TASKS.md
+++ b/planning/TASKS.md
@@ -24,7 +24,8 @@
 Owner question: 2026-10-11 08:30 UTC: Keep the 1 s server cache for the live endpoint?
 Queue inspector.

-### T05 Grafana port — \`todo\` (depends only on T00; start first)
+### T05 Grafana port — \`in progress\` (depends only on T00; start first)
+QA: 2026-10-12 09:00 UTC: picked up
 Golden port.

 ## Phase C: tail
set-status: dry run: commit \"board: T05 -> in progress (QA)\" and push" "$(sed 's/ *$//' "$OUT")"
    check "dry run changes nothing" "$before" "$(tasks_sum)"
    rc=0
    status "2026-10-12 09:00" T06 "in progress" --dry-run >"$OUT" 2>"$ERR" || rc=$?
    check "a dry run applies the gates" "1 set-status: T06 depends on T02 (in progress), T03 (testing), T04 (needs owner), T05 (todo), not done yet; --force --reason \"why\" overrides" "$rc $(cat "$ERR")"
}

# --- set-status.sh --attach -----------------------------------------------------------------------------------------

CASES+=("set-status screenshots")
case_21() {
    new_world
    cp "$FIXTURES/shot.png" "$WORK/world/grafana 13.png"
    status "2026-10-12 11:00" T03 "done" --qa "round 1 approved by the spec and adversarial testers" \
        --attach "$FIXTURES/shot.png" --attach "$WORK/world/grafana 13.png" >"$OUT" 2>"$ERR"
    shots="$(git -C "$REPO" rev-parse HEAD~1)"
    link="https://github.com/m0nclous/board-fixture/blob/$shots/planning/screenshots/T03"
    check "QA line with links to the screenshot commit" "QA: 2026-10-12 11:00 UTC: round 1 approved by the spec and adversarial testers ![T03 shot]($link/20261012-1100-shot.png?raw=true) ![T03 grafana-13]($link/20261012-1100-grafana-13.png?raw=true)" \
        "$(grep -A1 '^### T03 ' "$REPO/planning/TASKS.md" | tail -n 1)"
    check "two commits: the screenshots, then the status" "board: T03 -> done (QA)
board: T03 screenshots" "$(git -C "$REPO" log -2 --format=%s)"
    check "files in the screenshot commit" "planning/screenshots/T03/20261012-1100-grafana-13.png
planning/screenshots/T03/20261012-1100-shot.png" "$(git -C "$REPO" show --format= --name-only HEAD~1 | sort)"
    check "files in the status commit" "planning/TASKS.md" "$(git -C "$REPO" show --format= --name-only HEAD)"
    check "copies are identical" "same same" "$(cmp -s "$FIXTURES/shot.png" "$REPO/planning/screenshots/T03/20261012-1100-shot.png" && echo same) $(cmp -s "$FIXTURES/shot.png" "$REPO/planning/screenshots/T03/20261012-1100-grafana-13.png" && echo same)"
    check_contains "commit message lists them" "Screenshots: planning/screenshots/T03/20261012-1100-shot.png planning/screenshots/T03/20261012-1100-grafana-13.png" "$(git -C "$REPO" log -1 --format=%B)"
    check "working tree clean" "" "$(git -C "$REPO" status --porcelain)"
    check "both pushed" "$(git -C "$REPO" rev-parse HEAD)" "$(git -C "$WORK/world/origin.git" rev-parse "$BRANCH")"
    board sync >/dev/null 2>&1
    check_contains "the issue comment shows them" "**QA:** 2026-10-12 11:00 UTC: round 1 approved by the spec and adversarial testers ![T03 shot]($link/20261012-1100-shot.png?raw=true) ![T03 grafana-13]($link/20261012-1100-grafana-13.png?raw=true)" "$(comments_of_issue 4)"
    git -C "$REPO" rm -q -r planning/screenshots
    git -C "$REPO" commit -q -m "T15 deletes planning/"
    check "the linked commit keeps the files after planning/ is deleted" "ok" \
        "$(git -C "$REPO" cat-file -e "$shots:planning/screenshots/T03/20261012-1100-shot.png" && echo ok)"
    git -C "$REPO" reset -q --hard HEAD~1

    printf 'not a png' >"$WORK/world/fake.png"
    before="$(tasks_sum)"
    rc=0
    status "2026-10-12 12:00" T05 todo --qa "x" --attach "$WORK/world/fake.png" >"$OUT" 2>"$ERR" || rc=$?
    check "not a PNG" "1 set-status: not a PNG file: $WORK/world/fake.png" "$rc $(cat "$ERR")"
    rc=0
    status "2026-10-12 12:00" T05 todo --qa "x" --attach "$WORK/world/missing.png" >"$OUT" 2>"$ERR" || rc=$?
    check "missing file" "1 set-status: screenshot not found: $WORK/world/missing.png" "$rc $(cat "$ERR")"
    rc=0
    status "2026-10-12 12:00" T05 todo --attach "$FIXTURES/shot.png" >"$OUT" 2>"$ERR" || rc=$?
    check "no text to link from" "1 set-status: --attach needs --qa or --question (the screenshots are linked from that text)" "$rc $(cat "$ERR")"
    { cat "$FIXTURES/shot.png"; head -c 3000 /dev/zero; } >"$WORK/world/big.png"
    rc=0
    BOARD_ATTACH_MAX_KB=2 status "2026-10-12 12:00" T05 todo --qa "x" --attach "$WORK/world/big.png" >"$OUT" 2>"$ERR" || rc=$?
    check "too big" "1 set-status: $WORK/world/big.png is 3069 bytes, more than the limit of 2 KiB (BOARD_ATTACH_MAX_KB); crop or compress it" "$rc $(cat "$ERR")"
    printf 'other' | cat "$FIXTURES/shot.png" - >"$WORK/world/shot.png"
    rc=0
    status "2026-10-12 11:00" T03 "done" --qa "again" --attach "$WORK/world/shot.png" >"$OUT" 2>"$ERR" || rc=$?
    check "an existing name with other content" "1 set-status: planning/screenshots/T03/20261012-1100-shot.png already exists with other content" "$rc $(cat "$ERR")"
    check "refusals change nothing" "$before" "$(tasks_sum)"

    status "2026-10-12 12:00" T04 "needs owner" --question "Does this look right?" --attach "$FIXTURES/shot.png" >/dev/null
    check "linked from the question" "Owner question: 2026-10-12 12:00 UTC: Does this look right? ![T04 shot](https://github.com/m0nclous/board-fixture/blob/$(git -C "$REPO" rev-parse HEAD~1)/planning/screenshots/T04/20261012-1200-shot.png?raw=true)" \
        "$(grep -A1 '^### T04 ' "$REPO/planning/TASKS.md" | tail -n 1)"

    git -C "$REPO" remote set-url origin https://github.com/acme/demo.git
    (unset BOARD_REPO && status "2026-10-12 13:00" T05 todo --qa "look" --attach "$FIXTURES/shot.png" --no-push >/dev/null)
    check "owner/repo from origin" "QA: 2026-10-12 13:00 UTC: look ![T05 shot](https://github.com/acme/demo/blob/$(git -C "$REPO" rev-parse HEAD~1)/planning/screenshots/T05/20261012-1300-shot.png?raw=true)" \
        "$(grep -A1 '^### T05 ' "$REPO/planning/TASKS.md" | tail -n 1)"
}

# --- Adversarial QA, round 1: probes kept as regression tests --------------------------------------------------------

# A sleep that returns at once, for set-status's wait on a merge or rebase (60 x 5 s).
mkdir -p "$WORK/fastbin"
printf '#!/bin/sh\nexit 0\n' >"$WORK/fastbin/sleep"
chmod +x "$WORK/fastbin/sleep"

# edit_heading <sed expression> <commit subject>: edits the world's TASKS.md, commits and pushes it.
edit_heading() {
    sed -i "$1" "$REPO/planning/TASKS.md"
    git -C "$REPO" commit -q -am "$2"
    git -C "$REPO" push -q origin HEAD
}

# conflicting_side: a branch "side" from before the history whose T04 heading conflicts with it.
# shellcheck disable=SC2016 # literal backticks and dollars in the test data
conflicting_side() {
    git -C "$REPO" checkout -q -b side HEAD~4
    sed -i 's/^### T04 Queue state — `todo`/### T04 Queue state renamed — `todo`/' "$REPO/planning/TASKS.md"
    git -C "$REPO" commit -q -am "side edit of T04"
}

CASES+=("qa1 two set-status calls at once")
# shellcheck disable=SC2016 # literal backticks and dollars in the test data
case_22() {
    new_world
    commits="$(git -C "$REPO" rev-list --count HEAD)"
    status "2026-10-12 09:00" T05 "in progress" >"$WORK/world/a.out" 2>&1 &
    first=$!
    status "2026-10-12 09:00" T04 "in progress" --answer "Yes" >"$WORK/world/b.out" 2>&1 &
    second=$!
    rc_a=0
    wait "$first" || rc_a=$?
    rc_b=0
    wait "$second" || rc_b=$?
    check "one wins the last In Progress slot, the other is refused" "0 1" "$(printf '%s\n' "$rc_a" "$rc_b" | sort | tr '\n' ' ' | sed 's/ $//')"
    check_contains "the refusal names the full column" "the WIP limit of in progress is 2" "$(cat "$WORK/world/a.out" "$WORK/world/b.out")"
    check "exactly one new commit" "$((commits + 1))" "$(git -C "$REPO" rev-list --count HEAD)"
    check "In Progress holds 2" "2" "$(grep -c '^### T.* — `in progress`' "$REPO/planning/TASKS.md")"
    check "pushed" "$(git -C "$REPO" rev-parse HEAD)" "$(git -C "$WORK/world/origin.git" rev-parse "$BRANCH")"
    check "working tree clean" "" "$(git -C "$REPO" status --porcelain)"
}

CASES+=("qa1 sync recovers from a gh failure mid-run")
case_23() {
    new_world
    board sync >/dev/null 2>&1
    clean="$(summary)"
    new_world
    rc=0
    (cd "$REPO" && GH_STUB_FAIL="issue comment" "$BOARD/board.sh" sync) >"$OUT" 2>"$ERR" || rc=$?
    check_status "the failing run aborts" 1 "$rc"
    board sync >"$OUT" 2>"$ERR"
    check "the next run converges to a clean run's state" "$clean" "$(summary)"
    reset_log
    board sync >/dev/null 2>&1
    check "and then writes nothing" "" "$(writes)"
}

CASES+=("qa1 merge in progress")
case_24() {
    new_world
    conflicting_side
    git -C "$REPO" checkout -q "$BRANCH"
    git -C "$REPO" merge -q side >/dev/null 2>&1 || true
    before="$(tasks_sum)"
    rc=0
    PATH="$WORK/fastbin:$PATH" status "2026-10-12 09:00" T05 "in progress" >"$OUT" 2>"$ERR" || rc=$?
    check "refused" "1 set-status: a merge is in progress in $REPO; try again later" "$rc $(cat "$ERR")"
    check "nothing changed" "$before" "$(tasks_sum)"
}

CASES+=("qa1 titles with special characters")
# shellcheck disable=SC2016 # literal backticks and dollars in the test data
case_25() {
    new_world
    edit_heading 's/^### T05 Grafana port — /### T05 Grafana "port" '"'"'x'"'"' `tick` $(id) ${HOME} %s | * [ab] ?x ✓ — /' "Special title"
    board sync >/dev/null 2>"$ERR"
    check "issue title" "[T05] Grafana \"port\" 'x' \`tick\` \$(id) \${HOME} %s | * [ab] ?x ✓" "$(jq -r '.issues[] | select(.number == 6) | .title' "$GH_STUB_STATE")"
    reset_log
    board sync >/dev/null 2>&1
    check "the second sync writes nothing" "" "$(writes)"
    board report >/dev/null 2>&1
    check_contains "the report row escapes the pipe" "| #6 T05 Grafana \"port\" 'x' \`tick\` \$(id) \${HOME} %s \\| * [ab] ?x ✓ | todo, ready |" \
        "$(jq -r '.issues[] | select(.title == "[Board] Flow report") | .body' "$GH_STUB_STATE")"
    status "2026-10-12 09:00" T05 "in progress" --qa 'round 1 rejected by x: `a` $(id) "q" '"'"'s'"'"' \n | %s' >/dev/null
    check "a QA text with special characters" 'QA: 2026-10-12 09:00 UTC: round 1 rejected by x: `a` $(id) "q" '"'"'s'"'"' \n | %s' \
        "$(grep -A1 '^### T05 ' "$REPO/planning/TASKS.md" | tail -n 1)"
    board sync >/dev/null 2>&1
    check_contains "its comment" '**QA:** 2026-10-12 09:00 UTC: round 1 rejected by x: `a` $(id) "q" '"'"'s'"'"' \n | %s' "$(comments_of_issue 6)"
    reset_log
    board sync >/dev/null 2>&1
    check "posted once" "" "$(writes)"
}

CASES+=("qa1 dependency ranges")
case_26() {
    new_world
    edit_heading 's/(depends on T02–T04, T05)/(depends on T02-T04)/' "Hyphen range"
    check "a hyphen range" "T06  todo         phase:C  live        Live backend [blocked by T02, T03, T04]" "$(board list | grep '^T06 ')"
    edit_heading 's/(depends on T02-T04)/(depends only on T02–T03; T05 later)/' "Only, en dash, semicolon"
    check "depends only on, an en dash range, the clause ends at ;" "T06  todo         phase:C  live        Live backend [blocked by T02, T03]" "$(board list | grep '^T06 ')"
}

CASES+=("qa1 dates are UTC")
case_27() {
    new_world
    (cd "$REPO" && BOARD_MAIN="$REPO" BOARD_NOW="2026-10-13 04:30" GIT_AUTHOR_DATE="2026-10-12T23:30:00-05:00" \
        GIT_COMMITTER_DATE="2026-10-12T23:30:00-05:00" "$BOARD/set-status.sh" T05 "in progress") >/dev/null 2>&1
    (cd "$REPO" && TZ=America/Los_Angeles "$BOARD/board.sh" sync) >/dev/null 2>&1
    check "Started is the UTC day of the commit" "card #6 Status=In Progress; Phase=B; Area=grafana; QA rounds=0; Started=2026-10-13" "$(summary | grep '^card #6 ')"
}

# --- Adversarial QA, round 1: findings (fixed in round 2) ---------------------------------------------------------

CASES+=("qa1 finding: rebase or cherry-pick stopped on a conflict")
case_28() {
    new_world
    conflicting_side
    git -C "$REPO" rebase -q "$BRANCH" >/dev/null 2>&1 || true
    before="$(tasks_sum)"
    rc=0
    PATH="$WORK/fastbin:$PATH" status "2026-10-12 09:00" T05 "in progress" --no-push >"$OUT" 2>"$ERR" || rc=$?
    check_status "a stopped rebase: refused" 1 "$rc"
    check "a stopped rebase: message" "set-status: a rebase is in progress in $REPO; try again later" "$(cat "$ERR")"
    check "a stopped rebase: no commit, no edit (no conflict markers committed)" "$before" "$(tasks_sum)"

    new_world
    conflicting_side
    git -C "$REPO" checkout -q "$BRANCH"
    git -C "$REPO" cherry-pick side >/dev/null 2>&1 || true
    before="$(tasks_sum)"
    rc=0
    PATH="$WORK/fastbin:$PATH" status "2026-10-12 09:00" T05 "in progress" --no-push >"$OUT" 2>"$ERR" || rc=$?
    check_status "a stopped cherry-pick: refused" 1 "$rc"
    check "a stopped cherry-pick: message" "set-status: a cherry-pick is in progress in $REPO; try again later" "$(cat "$ERR")"
    check "a stopped cherry-pick: TASKS.md left as it was" "$before" "$(tasks_sum)"

    new_world
    git -C "$REPO" revert --no-commit HEAD >/dev/null 2>&1
    git -C "$REPO" revert --no-commit HEAD~1 >/dev/null 2>&1 || true
    rc=0
    status "2026-10-12 09:00" T05 "in progress" --no-push >"$OUT" 2>"$ERR" || rc=$?
    check "a revert in progress" "1 set-status: a revert is in progress in $REPO; try again later" "$rc $(cat "$ERR")"
    git -C "$REPO" revert --abort >/dev/null 2>&1 || git -C "$REPO" reset -q --hard
    git -C "$REPO" checkout -q --detach HEAD
    rc=0
    status "2026-10-12 09:00" T05 "in progress" --no-push >"$OUT" 2>"$ERR" || rc=$?
    check "a detached HEAD" "1 set-status: HEAD is detached in $REPO (not on a branch); try again later" "$rc $(cat "$ERR")"
}

CASES+=("qa1 finding: uncommitted TASKS.md edits stay out of the status commit")
case_29() {
    new_world
    sed -i 's/^Golden port\./Golden port. HALF-WRITTEN EDIT OF THE INTEGRATOR/' "$REPO/planning/TASKS.md"
    head="$(git -C "$REPO" rev-parse HEAD)"
    rc=0
    status "2026-10-12 09:00" T05 "in progress" --no-push >"$OUT" 2>"$ERR" || rc=$?
    check "refused" "1 set-status: planning/TASKS.md has uncommitted changes in $REPO (commit or stash them); try again later" "$rc $(cat "$ERR")"
    check "no commit" "$head" "$(git -C "$REPO" rev-parse HEAD)"
    check "the status commit does not carry the edit" "" "$(git -C "$REPO" show HEAD -- planning/TASKS.md | grep 'HALF-WRITTEN' || true)"
    check_contains "the edit is still in the working tree" "HALF-WRITTEN EDIT OF THE INTEGRATOR" "$(cat "$REPO/planning/TASKS.md")"
    # Staged, with the working tree back at HEAD: still an uncommitted edit.
    git -C "$REPO" add planning/TASKS.md
    git -C "$REPO" show HEAD:planning/TASKS.md >"$REPO/planning/TASKS.md"
    rc=0
    status "2026-10-12 09:00" T05 "in progress" --no-push >"$OUT" 2>"$ERR" || rc=$?
    check "a staged edit is refused too" "1 set-status: planning/TASKS.md has uncommitted changes in $REPO (commit or stash them); try again later" "$rc $(cat "$ERR")"
    git -C "$REPO" reset -q
    # Changes of other files (staged and unstaged) are not the board's business: they stay out of the commit.
    printf 'other\n' >"$REPO/notes.txt"
    git -C "$REPO" add notes.txt
    printf 'more\n' >>"$REPO/notes.txt"
    status "2026-10-12 09:00" T05 "in progress" >"$OUT" 2>"$ERR"
    check "other changes: not in the status commit" "planning/TASKS.md" "$(git -C "$REPO" show --format= --name-only HEAD)"
    check "other changes: still staged and modified" "AM notes.txt" "$(git -C "$REPO" status --porcelain --untracked-files=no)"
}

CASES+=("qa1 finding: the gates see the state on origin")
case_30() {
    new_world
    git clone -q -b "$BRANCH" "$WORK/world/origin.git" "$WORK/world/other"
    (cd "$WORK/world/other" && BOARD_MAIN="$WORK/world/other" BOARD_NOW="2026-10-12 09:00" "$BOARD/set-status.sh" T05 "in progress") >/dev/null 2>&1
    pushed="$(git -C "$WORK/world/origin.git" rev-parse "$BRANCH")"
    local_head="$(git -C "$REPO" rev-parse HEAD)"
    rc=0
    status "2026-10-12 09:05" T04 "in progress" --answer "Yes" >"$OUT" 2>"$ERR" || rc=$?
    check_status "In Progress is full on origin (T02, T05): refused" 1 "$rc"
    check_contains "refused by the WIP gate, not by the failed push" "the WIP limit of in progress is 2 and it holds T02, T05" "$(cat "$ERR")"
    check "origin unchanged" "$pushed" "$(git -C "$WORK/world/origin.git" rev-parse "$BRANCH")"
    # The fix fetches and fast-forwards the main checkout before the gates (QA asked for "fetch and fast-forward"):
    # its HEAD is origin's head afterwards, with no local commit of its own.
    check "no unpushable local commit left behind: fast-forwarded to origin" "$pushed" "$(git -C "$REPO" rev-parse HEAD)"
    check "the old head is an ancestor" "yes" "$(git -C "$REPO" merge-base --is-ancestor "$local_head" HEAD && echo yes)"
    check_contains "it says so" "set-status: fast-forwarded $REPO to origin/$BRANCH (1 new commit(s))" "$(cat "$ERR")"
}

CASES+=("qa1 finding: testing -> in progress without a QA line")
case_31() {
    new_world
    status "2026-10-12 09:00" T05 "in progress" >/dev/null
    rc=0
    status "2026-10-12 09:10" T03 "in progress" >"$OUT" 2>"$ERR" || rc=$?
    check_status "In Progress full (T02, T05) and no QA rejection recorded: refused" 1 "$rc"
    check_contains "refused by the WIP gate" "the WIP limit of in progress is 2 and it holds T02, T05" "$(cat "$ERR")"
    rc=0
    status "2026-10-12 09:10" T03 "in progress" --qa "round 1 approved by the spec tester" >"$OUT" 2>"$ERR" || rc=$?
    check_status "a QA line that does not reject: refused" 1 "$rc"
    status "2026-10-12 09:10" T03 "in progress" --qa "round 1 QA verdict: CHANGES REQUESTED by the spec tester" >"$OUT" 2>"$ERR"
    check "the PR wording rejects too" "### T03 Attempts — \`in progress\`" "$(grep '^### T03 ' "$REPO/planning/TASKS.md")"
}

CASES+=("qa1 finding: a backslash in a title")
case_32() {
    new_world
    edit_heading 's/^### T05 Grafana port — /### T05 Grafana port C:\\temp\\new — /' "Backslash title"
    board sync >/dev/null 2>&1
    check "the issue title" '[T05] Grafana port C:\temp\new' "$(jq -r '.issues[] | select(.number == 6) | .title' "$GH_STUB_STATE")"
    reset_log
    board sync >/dev/null 2>&1
    check "the second sync writes nothing" "" "$(writes)"
}

CASES+=("qa1 finding: a spaced en dash range")
case_33() {
    new_world
    edit_heading 's/(depends on T02–T04, T05)/(depends on T02 – T04)/' "Spaced en dash"
    check "T03 is a dependency" "T06  todo         phase:C  live        Live backend [blocked by T02, T03, T04]" "$(board list | grep '^T06 ')"
}

CASES+=("qa1 finding: a board field of another type")
case_34() {
    new_world
    state_edit '.project.fields += [{"id": "PVTF_qa", "name": "QA rounds", "type": "ProjectV2Field", "dataType": "TEXT"}]'
    rc=0
    board sync >"$OUT" 2>"$ERR" || rc=$?
    check_status "one bad field does not stop the sync" 0 "$rc"
    check "every task has its issue" "8" "$(jq '[.issues[] | select(.labels | index("task"))] | length' "$GH_STUB_STATE")"
    check_contains "named once" "board: warning: could not write the board field \"QA rounds\" (field QA rounds is not a number); the sync skips it for this run" "$(cat "$ERR")"
    check "warned once" "1" "$(grep -c 'QA rounds' "$ERR")"
    check "the other fields are set" "card #4 Status=Testing; Phase=B; Area=attempts; Started=2026-10-09" "$(summary | grep '^card #4 ')"

    new_world
    state_edit '.project.fields += [{"id": "PVTF_area", "name": "Area", "type": "ProjectV2Field", "dataType": "TEXT"}]'
    board sync >"$OUT" 2>"$ERR"
    check_contains "a field of another kind is left alone" "board: warning: the board's field \"Area\" is a ProjectV2Field, not a SINGLE_SELECT field; the sync leaves it alone (delete or rename it, and the next sync creates it)" "$(cat "$ERR")"
    check "no Area write" "" "$(calls | grep -- '--field-id PVTF_area' || true)"
}

# --- QA round 2: the fixes, case by case ------------------------------------------------------------------------------

# other_clone: a second clone of origin (another session's main checkout).
other_clone() {
    OTHER="$WORK/world/other"
    rm -rf "$OTHER"
    git clone -q -b "$BRANCH" "$WORK/world/origin.git" "$OTHER"
}

# other_push <sed expression> <subject>: edits TASKS.md in the other clone, commits and pushes it.
other_push() {
    git -C "$OTHER" pull -q --ff-only
    sed -i "$1" "$OTHER/planning/TASKS.md"
    git -C "$OTHER" commit -q -am "$2"
    git -C "$OTHER" push -q origin HEAD
}

CASES+=("qa2 set-status brings the main checkout up to date")
# shellcheck disable=SC2016 # literal backticks in the test data
case_35() {
    new_world
    other_clone
    # The integrator merges a PR on GitHub: a merge commit lands on origin, the main checkout does not have it.
    git -C "$OTHER" checkout -q -b wip/T05-grafana
    mkdir -p "$OTHER/src"
    echo '<?php' >"$OTHER/src/grafana.php"
    git -C "$OTHER" add src/grafana.php
    git -C "$OTHER" commit -q -m "Port the dashboard"
    git -C "$OTHER" checkout -q "$BRANCH"
    git -C "$OTHER" merge -q --no-ff -m "Merge pull request #12 from wip/T05-grafana" wip/T05-grafana
    git -C "$OTHER" push -q origin HEAD
    merged="$(git -C "$OTHER" rev-parse HEAD)"
    status "2026-10-12 09:00" T05 "in progress" >"$OUT" 2>"$ERR"
    check "the status change goes through" "set-status: T05 -> in progress ($(git -C "$REPO" rev-parse --short HEAD))" "$(cat "$OUT")"
    check "on top of the merge" "$merged" "$(git -C "$REPO" rev-parse HEAD~1)"
    check "pushed" "$(git -C "$REPO" rev-parse HEAD)" "$(git -C "$WORK/world/origin.git" rev-parse "$BRANCH")"
    check "the merged file is in the main checkout" "<?php" "$(cat "$REPO/src/grafana.php")"

    # A dry run of a stale checkout reads origin's task list and changes nothing.
    other_push 's/^### T03 Attempts — `testing`/### T03 Attempts — `done`/' "board: T03 -> done"
    before="$(tasks_sum)"
    status "2026-10-12 09:30" T06 "in progress" --dry-run >"$OUT" 2>"$ERR" || true
    check_contains "dry run: says it is behind" "set-status: dry run: $REPO is 1 commit(s) behind origin/$BRANCH; a real run fast-forwards it first" "$(cat "$OUT")"
    check "dry run: the gates read origin (T03 is done there)" "set-status: T06 depends on T02 (in progress), T04 (needs owner), T05 (in progress), not done yet; --force --reason \"why\" overrides" "$(cat "$ERR")"
    check "dry run: nothing changed" "$before" "$(tasks_sum)"

    # Local commits that origin does not have, while origin moved too: refused, nothing changed.
    status "2026-10-12 10:00" T05 testing --no-push >/dev/null
    before="$(tasks_sum)"
    rc=0
    status "2026-10-12 10:05" T04 todo >"$OUT" 2>"$ERR" || rc=$?
    check "diverged: refused" "1 set-status: $REPO has 1 commit(s) that origin/$BRANCH does not have, and origin has 1 new one(s); integrate them first (git pull --rebase, or push them), then try again" "$rc $(cat "$ERR")"
    check "diverged: nothing changed" "$before" "$(tasks_sum)"

    # Local commits on top of origin are pushed with the status change.
    git -C "$REPO" pull -q --rebase origin "$BRANCH"
    status "2026-10-12 10:10" T04 todo >/dev/null
    check "ahead: the earlier local commit goes out too" "$(git -C "$REPO" rev-parse HEAD)" "$(git -C "$WORK/world/origin.git" rev-parse "$BRANCH")"

    # No origin to fetch from.
    git -C "$REPO" remote set-url origin "$WORK/world/missing.git"
    rc=0
    status "2026-10-12 10:20" T04 "in progress" >"$OUT" 2>"$ERR" || rc=$?
    check_status "fetch failure: refused" 1 "$rc"
    check_contains "fetch failure: message" "set-status: cannot fetch $BRANCH from origin (" "$(cat "$ERR")"
    check_contains "fetch failure: the way out" "; --no-push works offline" "$(cat "$ERR")"
}

CASES+=("qa2 set-status push race and push failure")
# shellcheck disable=SC2016 # literal backticks in the test data
case_36() {
    new_world
    other_clone
    # A pre-push hook that lets the other session push first, once: the first push is rejected.
    hook="$REPO/.git/hooks/pre-push"
    cat >"$hook" <<EOF
#!/bin/sh
unset GIT_DIR GIT_WORK_TREE GIT_INDEX_FILE
[ -e "$WORK/world/raced" ] && exit 0
touch "$WORK/world/raced"
cd "$OTHER" && git pull -q --ff-only && mkdir -p src && echo x >src/race.txt && git add src/race.txt &&
    git commit -q -m "Another session's commit" && git push -q origin HEAD
EOF
    chmod +x "$hook"
    status "2026-10-12 09:00" T05 "in progress" >"$OUT" 2>"$ERR"
    check_contains "the first attempt lost the race" "set-status: origin/$BRANCH moved while committing; the commit was undone, trying again" "$(cat "$ERR")"
    check "the second attempt went through" "board: T05 -> in progress
Another session's commit" "$(git -C "$WORK/world/origin.git" log -2 --format=%s "$BRANCH")"
    check "the main checkout is origin's head" "$(git -C "$WORK/world/origin.git" rev-parse "$BRANCH")" "$(git -C "$REPO" rev-parse HEAD)"
    check "one status commit" "1" "$(git -C "$REPO" log --format=%s | grep -c '^board: T05 -> in progress$')"
    check "working tree clean" "" "$(git -C "$REPO" status --porcelain --untracked-files=no)"

    # The race fills In Progress: the retry runs the gates again and refuses.
    new_world
    other_clone
    rm -f "$WORK/world/raced"
    cat >"$hook" <<EOF
#!/bin/sh
unset GIT_DIR GIT_WORK_TREE GIT_INDEX_FILE
[ -e "$WORK/world/raced" ] && exit 0
touch "$WORK/world/raced"
cd "$OTHER" && git pull -q --ff-only &&
    sed -i 's/^### T05 Grafana port — \`todo\`/### T05 Grafana port — \`in progress\`/' planning/TASKS.md &&
    git commit -q -am "board: T05 -> in progress" && git push -q origin HEAD
EOF
    chmod +x "$hook"
    rc=0
    status "2026-10-12 09:00" T04 "in progress" --answer "Yes" >"$OUT" 2>"$ERR" || rc=$?
    check_status "the retry is refused by the WIP gate" 1 "$rc"
    check_contains "the retry is refused by the WIP gate: message" "the WIP limit of in progress is 2 and it holds T02, T05" "$(cat "$ERR")"
    check "origin has only the other session's commit" "board: T05 -> in progress" "$(git -C "$WORK/world/origin.git" log -1 --format=%s "$BRANCH")"
    check "no local commit left" "$(git -C "$WORK/world/origin.git" rev-parse "$BRANCH")" "$(git -C "$REPO" rev-parse HEAD)"
    check "TASKS.md as on origin" "" "$(git -C "$REPO" status --porcelain --untracked-files=no)"

    # A push that fails for another reason: the commits (status and screenshots) are undone.
    new_world
    printf '#!/bin/sh\necho "remote: protected branch" >&2\nexit 1\n' >"$hook"
    chmod +x "$hook"
    before="$(tasks_sum)"
    rc=0
    status "2026-10-12 09:00" T05 "in progress" --qa "round 1 approved by x" --attach "$FIXTURES/shot.png" >"$OUT" 2>"$ERR" || rc=$?
    check_status "push failure: exit code" 1 "$rc"
    check_contains "push failure: message" "the commit was undone, nothing changed" "$(cat "$ERR")"
    check "push failure: nothing changed" "$before" "$(tasks_sum)"
    check "push failure: no screenshot left behind" "" "$(git -C "$REPO" status --porcelain)"
}

CASES+=("qa2 set-status waits for a merge to finish")
case_37() {
    new_world
    conflicting_side
    git -C "$REPO" checkout -q "$BRANCH"
    git -C "$REPO" merge -q side >/dev/null 2>&1 || true
    (sleep 2 && git -C "$REPO" merge --abort) &
    aborter=$!
    rc=0
    BOARD_WAIT=30 status "2026-10-12 09:00" T05 "in progress" >"$OUT" 2>"$ERR" || rc=$?
    wait "$aborter"
    check "the status change goes through after the merge" "0 ### T05 Grafana port — \`in progress\` (depends only on T00; start first)" \
        "$rc $(grep '^### T05 ' "$REPO/planning/TASKS.md")"
}

CASES+=("qa2 set-status refuses another branch")
case_38() {
    new_world
    git -C "$REPO" checkout -q -b wip/T99-other
    before="$(tasks_sum)"
    rc=0
    status "2026-10-12 09:00" T05 "in progress" >"$OUT" 2>"$ERR" || rc=$?
    check "refused" "1 set-status: $REPO is on wip/T99-other, not on the integration branch $BRANCH (BOARD_INTEGRATION_BRANCH): the board would not see the change; --force --reason \"why\" overrides" "$rc $(cat "$ERR")"
    check "nothing changed" "$before" "$(tasks_sum)"
    status "2026-10-12 09:00" T05 "in progress" --force --reason "rehearsal on a scratch branch" --no-push >"$OUT" 2>"$ERR"
    check "forced: recorded" "board: T05 -> in progress

Forced: $REPO is on wip/T99-other, not on the integration branch $BRANCH (BOARD_INTEGRATION_BRANCH): the board would not see the change
Forced because: rehearsal on a scratch branch" "$(git -C "$REPO" log -1 --format=%B)"
    git -C "$REPO" checkout -q "$BRANCH"
    rc=0
    BOARD_INTEGRATION_BRANCH=wip/T99-other status "2026-10-12 09:00" T05 "in progress" --no-push >"$OUT" 2>"$ERR" || rc=$?
    check_contains "the variable names the integration branch" "is on $BRANCH, not on the integration branch wip/T99-other" "$rc $(cat "$ERR")"
}

CASES+=("qa2 set-status trailers")
case_39() {
    new_world
    BOARD_TRAILERS="Claude-Session: https://claude.ai/code/session_x" status "2026-10-12 09:00" T05 "in progress" \
        --trailer "Co-Authored-By: Some Agent <agent@example.invalid>" >/dev/null
    check "trailers end the message" "board: T05 -> in progress

Co-Authored-By: Some Agent <agent@example.invalid>
Claude-Session: https://claude.ai/code/session_x" "$(git -C "$REPO" log -1 --format=%B)"
    check "git reads them as trailers" "Co-Authored-By: Some Agent <agent@example.invalid>
Claude-Session: https://claude.ai/code/session_x" "$(git -C "$REPO" log -1 --format='%(trailers:only)' | sed '/^$/d')"
    status "2026-10-12 10:00" T05 testing --qa "round 1 approved by x" --attach "$FIXTURES/shot.png" --trailer "Co-Authored-By: A <a@b.invalid>" >/dev/null
    check "the screenshot commit carries them too" "Co-Authored-By: A <a@b.invalid>" "$(git -C "$REPO" log -1 --format='%(trailers:only)' HEAD~1 | sed '/^$/d')"
    rc=0
    status "2026-10-12 11:00" T05 todo --trailer "no colon here" >"$OUT" 2>"$ERR" || rc=$?
    check "an invalid trailer" "1 set-status: invalid trailer (want \"Key: value\"): no colon here" "$rc $(cat "$ERR")"
}

CASES+=("qa2 QA rounds")
case_40() {
    new_world
    {
        status "2026-10-12 09:00" T05 todo --qa "round 1 rejected by the spec tester: a missing test"
        status "2026-10-12 09:01" T05 todo --qa "round 1 approved by the adversarial tester"
        status "2026-10-12 09:02" T05 todo --qa "round 2 QA verdict: CHANGES REQUESTED (spec tester): the range parser"
        status "2026-10-12 09:03" T05 todo --qa "round 2 not approved by the adversarial tester"
        status "2026-10-12 09:04" T05 todo --qa "round 3 approved by the spec tester"
        status "2026-10-12 09:05" T05 todo --qa "round 3 approved by the adversarial tester"
        status "2026-10-12 09:06" T05 todo --qa "escalated to the owner: 3 rounds rejected before (a note, not a round)"
        status "2026-10-12 09:07" T05 todo --qa "unapproved notes and a disapproved idea, no round"
    } >/dev/null
    board sync >/dev/null 2>&1
    check "3 rounds, 2 rejected" "card #6 Status=Todo; Phase=B; Area=grafana; QA rounds=3" "$(summary | grep '^card #6 ')"
    board report >/dev/null 2>&1
    report="$(jq -r '.issues[] | select(.title == "[Board] Flow report") | .body' "$GH_STUB_STATE")"
    check_contains "report row" "| #6 T05 Grafana port | todo, ready | grafana | 3 |" "$report"
    check_contains "rejection rate over all tasks (T01 1 approved, T02 1 rejected)" "- QA rounds: 5 (2 approved, 3 rejected), rejection rate 60 %." "$report"
}

CASES+=("qa2 board fields are cleared")
case_41() {
    new_world
    status "2026-10-12 09:00" T03 "done" --qa "round 1 approved by the spec and adversarial testers" >/dev/null
    board sync >/dev/null 2>&1
    check "done: Finished set" "card #4 Status=Done; Phase=B; Area=attempts; QA rounds=1; Started=2026-10-09; Finished=2026-10-12" "$(summary | grep '^card #4 ')"
    {
        status "2026-10-13 09:00" T03 "in progress" --qa "round 2 rejected by the owner: reopened"
        status "2026-10-13 10:00" T04 todo --answer "Not now"
    } >/dev/null
    reset_log
    board sync >/dev/null 2>&1
    item() { jq -r --arg n "/issues/$1" '.project.items[] | select(.url | endswith($n)) | .id' "$GH_STUB_STATE"; }
    field() { jq -r --arg n "$1" '.project.fields[] | select(.name == $n) | .id' "$GH_STUB_STATE"; }
    check "the clear calls" "gh project item-edit --id $(item 4) --project-id PVT_board5 --field-id $(field Finished) --clear
gh project item-edit --id $(item 5) --project-id PVT_board5 --field-id $(field Started) --clear" "$(writes | grep -- '--clear')"
    check "reopened: Finished cleared, Started kept" "card #4 Status=In Progress; Phase=B; Area=attempts; QA rounds=2; Started=2026-10-09" "$(summary | grep '^card #4 ')"
    check "back to todo: Started cleared" "card #5 Status=Todo; Phase=B; Area=queues; QA rounds=0" "$(summary | grep '^card #5 ')"
    reset_log
    board sync >/dev/null 2>&1
    check "then nothing" "" "$(writes)"
}

CASES+=("qa2 owner questions notify the owner")
case_42() {
    new_world
    (cd "$REPO" && BOARD_NOTIFY_TOKEN=bot-token "$BOARD/board.sh" sync) >/dev/null 2>&1
    check_contains "the question comes from the bot (GitHub notifies the mention)" "  comment by github-actions[bot]: **Owner question** for @m0nclous: 2026-10-11 08:30 UTC: Keep the 1 s server cache for the live endpoint?" "$(comments_of_issue 5)"
    check_contains "other comments use the board token" "  comment: **QA:** 2026-10-10 09:00 UTC: round 1 rejected" "$(comments_of_issue 3)"
    reset_log
    (cd "$REPO" && BOARD_NOTIFY_TOKEN=bot-token "$BOARD/board.sh" sync) >/dev/null 2>&1
    check "posted once" "" "$(writes)"

    new_world
    rc=0
    (cd "$REPO" && BOARD_NOTIFY_TOKEN=readonly-token "$BOARD/board.sh" sync) >"$OUT" 2>"$ERR" || rc=$?
    check_status "a notify token without access does not stop the sync" 0 "$rc"
    check_contains "it says so" "board: warning: could not post the owner question of T04 with BOARD_NOTIFY_TOKEN; posted with GH_TOKEN, so the owner is not notified" "$(cat "$ERR")"
    check_contains "the question is there anyway" "  comment: **Owner question** for @m0nclous:" "$(comments_of_issue 5)"
}

CASES+=("qa2 a task without an area")
case_43() {
    new_world
    grep -v '^T07' "$FIXTURES/areas.tsv" >"$WORK/world/areas.tsv"
    (cd "$REPO" && BOARD_AREAS_FILE="$WORK/world/areas.tsv" "$BOARD/board.sh" list) >"$OUT" 2>"$ERR"
    check "warned" "board: warning: no area for T07 in $WORK/world/areas.tsv; add a line \"Txx<TAB>area\" for every task (the Area field stays empty)" "$(cat "$ERR")"
    # The real task list is not checked against the real map here: a TASKS.md change runs no tests (paths-ignore),
    # so a missing line would turn the next unrelated push red. The sync warns instead (an annotation of its run).
    rc=0
    (cd "$REPO" && BOARD_AREAS_FILE="$WORK/world/areas.tsv" "$BOARD/board.sh" sync) >"$OUT" 2>"$ERR" || rc=$?
    check_status "a task without an area does not stop the sync" 0 "$rc"
    check "its card has no Area" "card #8 Status=Todo; Phase=C; QA rounds=0" "$(summary | grep '^card #8 ')"
}

CASES+=("qa2 dependency parsing")
# shellcheck disable=SC2016 # literal backticks in the test data
case_44() {
    new_world
    cat >"$WORK/world/parse.md" <<'EOF'
## Phase B: packages (all depend on T00)

### T01 One — `done`
### T02 Two — `todo` (depends on t01)
### T03 Three — `todo` (depends on T01—T02)
### T04 Four — `todo` (depends on T01 through T03)
### T05 Five — with a dash — `todo` (depends on T01 – T02 and T04, the owner's go)
### Notes of the phase
QA: round 1 rejected by a stray line
## Appendix

### T06 Six — `todo`
QA: round 1 approved by the spec tester
EOF
    (cd "$REPO" && TASKS_FILE="$WORK/world/parse.md" "$BOARD/board.sh" list) >"$OUT" 2>"$ERR"
    check "list" "T01  done         phase:B  exporter    One
T02  todo         phase:B  exporter    Two [ready]
T03  todo         phase:B  attempts    Three [blocked by T02]
T04  todo         phase:B  queues      Four [blocked by T02, T03]
T05  todo         phase:B  grafana     Five — with a dash [blocked by T02, T04]
T06  todo         phase:-  live        Six [ready]" "$(cat "$OUT")"
    check "unparsed text is named" "board: warning: T05: unparsed text in \"depends on T01 – T02 and T04, the owner's go\": the owner's go" "$(cat "$ERR")"
    (
        cd "$REPO"
        # shellcheck source-path=SCRIPTDIR/.. source=lib.sh
        source "$BOARD/lib.sh"
        board_load_tasks "$WORK/world/parse.md" 2>/dev/null
        echo "T05 notes: ${TASK_NOTES[T05]:-none}; T06 notes: ${TASK_NOTES[T06]//$'\t'/ }"
    ) >"$OUT"
    check "a QA line under a non-task heading belongs to no task" "T05 notes: none; T06 notes: qa round 1 approved by the spec tester" "$(cat "$OUT")"
}

CASES+=("qa2 the tests workflow ignores what board.sh ci treats as board-only")
# shellcheck disable=SC2016 # a literal line of board.sh
case_45() {
    workflow="$BOARD/../../.github/workflows/tests.yml"
    check "push paths-ignore" "planning/TASKS.md
planning/screenshots/**" "$(awk '/^  push:/ { on = 1; next } on && /^  [a-z_]+:/ { exit } on && /paths-ignore:/ { list = 1; next } list && /^ *- / { sub(/^ *- /, ""); print; next } list { exit }' "$workflow")"
    check "pull requests run everything they change" "" "$(awk '/^  pull_request:/ { on = 1; next } on && /^  [a-z_]+:/ { exit } on && /paths/ { print }' "$workflow")"
    check_contains "board.sh ci excludes the same paths" 'BOARD_ONLY_PATHS=("$TASKS_FILE" "$(dirname "$TASKS_FILE")/screenshots")' "$(cat "$BOARD/board.sh")"
}

# --- Adversarial QA, round 2: probes kept as regression tests --------------------------------------------------------

CASES+=("adv2 probe: six set-status calls at once from two clones")
# shellcheck disable=SC2016 # literal backticks in the test data
case_46() {
    new_world
    other_clone
    base="$(git -C "$WORK/world/origin.git" rev-parse "$BRANCH")"
    # name dir task status [options]: one call in the background; its exit code goes to $name.rc.
    one_call() {
        local name="$1" dir="$2" rc=0
        shift 2
        (cd "$dir" && BOARD_MAIN="$dir" BOARD_WAIT=60 BOARD_NOW="2026-10-12 09:00" "$BOARD/set-status.sh" "$@") \
            >"$WORK/world/$name.out" 2>"$WORK/world/$name.err" || rc=$?
        echo "$rc" >"$WORK/world/$name.rc"
    }
    # In Progress holds T02 (limit 2): T05 races the QA rejection of T03 for the last slot unless T02 moves on first.
    one_call a1 "$REPO" T05 "in progress" &
    one_call b1 "$OTHER" T03 "in progress" --qa "round 1 rejected by the clone b tester: x" &
    one_call a2 "$REPO" T06 "needs owner" --question "A question from clone a?" &
    one_call b2 "$OTHER" T02 testing &
    one_call a3 "$REPO" T07 todo --qa "a note from clone a" &
    one_call b3 "$OTHER" T04 todo --answer "No, from clone b" &
    wait
    declare -A want=([a1]="T05|in progress|todo" [b1]="T03|in progress|testing" [a2]="T06|needs owner|todo"
        [b2]="T02|testing|in progress" [a3]="T07|todo|todo" [b3]="T04|todo|needs owner")
    declare -A subject=([a1]="board: T05 -> in progress" [b1]="board: T03 -> in progress (QA)"
        [a2]="board: T06 -> needs owner (question)" [b2]="board: T02 -> testing" [a3]="board: T07 -> todo (QA)"
        [b3]="board: T04 -> todo (answer)")
    git -C "$REPO" fetch -q origin
    tasks="$(git -C "$WORK/world/origin.git" show "$BRANCH:planning/TASKS.md")"
    log="$(git -C "$WORK/world/origin.git" log --format=%s "$base..$BRANCH")"
    successes=0
    bad=""
    for name in a1 a2 a3 b1 b2 b3; do
        rc="$(cat "$WORK/world/$name.rc")"
        IFS='|' read -r id to from <<<"${want[$name]}"
        heading="$(grep "^### $id " <<<"$tasks")"
        if [[ "$rc" == 0 ]]; then
            successes=$((successes + 1))
            [[ "$heading" == *" — \`$to\`"* ]] || bad+=" $name:heading"
            [[ "$(grep -cxF "${subject[$name]}" <<<"$log")" == 1 ]] || bad+=" $name:commit"
        else
            # Only T05 can lose (the WIP gate), or a call can give up after three lost push races: cleanly.
            grep -qE "the WIP limit of in progress is 2|kept moving; nothing changed" "$WORK/world/$name.err" || bad+=" $name:refusal"
            [[ "$name" == a1 ]] || grep -q "kept moving" "$WORK/world/$name.err" || bad+=" $name:refused"
            [[ "$heading" == *" — \`$from\`"* ]] || bad+=" $name:heading"
            [[ "$(grep -cxF "${subject[$name]}" <<<"$log" || true)" == 0 ]] || bad+=" $name:commit"
        fi
        ! grep -qE "index\.lock|Traceback|fatal:|CONFLICT" "$WORK/world/$name.err" || bad+=" $name:git-error"
    done
    for name in a1 a2 a3 b1 b2 b3; do echo "== $name rc=$(cat "$WORK/world/$name.rc")"; cat "$WORK/world/$name.out" "$WORK/world/$name.err"; done >"${ADV2_DEBUG:-/dev/null}"
    check "every call either landed once or was refused cleanly" "" "$bad"
    check "one origin commit per landed call" "$successes" "$(grep -c . <<<"$log")"
    landed="" expected=""
    for pair in "b1|round 1 rejected by the clone b tester" "a2|A question from clone a?" "a3|a note from clone a" "b3|No, from clone b"; do
        expected+="$(( $(cat "$WORK/world/${pair%%|*}.rc") == 0 ? 1 : 0 )) "
        landed+="$(grep -cF "${pair#*|}" <<<"$tasks" || true) "
    done
    check "the QA, question and answer lines of the landed calls, once each" "$expected" "$landed"
    check "In Progress within its limit (or over it only by the QA rejection)" "yes" \
        "$([[ "$(grep -c '^### T.* — `in progress`' <<<"$tasks")" -le 2 ]] && echo yes)"
    check "clone a: clean" "" "$(git -C "$REPO" status --porcelain --untracked-files=no)"
    check "clone b: clean" "" "$(git -C "$OTHER" status --porcelain --untracked-files=no)"
    printf '%s\n' "$tasks" >"$WORK/world/final.md"
    check "the task list still parses" "8" "$(cd "$REPO" && TASKS_FILE="$WORK/world/final.md" "$BOARD/board.sh" list 2>&1 | grep -c '^T0')"
}

CASES+=("adv2 probe: tokens and URL credentials stay out of the output")
case_47() {
    new_world
    local secret="ghp_ADV2SECRETTOKEN0000" notify="ghs_ADV2NOTIFYSECRET000"
    {
        (cd "$REPO" && GH_TOKEN="$secret" BOARD_NOTIFY_TOKEN="$notify" "$BOARD/board.sh" sync --dry-run)
        (cd "$REPO" && GH_TOKEN="$secret" BOARD_NOTIFY_TOKEN="$notify" GH_STUB_FAIL="project item-edit" "$BOARD/board.sh" sync)
        (cd "$REPO" && GH_TOKEN="$secret" BOARD_NOTIFY_TOKEN="$notify" "$BOARD/board.sh" report --dry-run)
        (cd "$REPO" && GH_TOKEN="$secret" CI_CONCLUSION=failure CI_RUN_ID=900 "$BOARD/board.sh" ci --dry-run)
    } >"$OUT" 2>"$ERR"
    check_contains "the dry runs printed their writes" "dry-run: gh issue comment" "$(cat "$OUT")"
    check_contains "the failing writes printed their warnings" "could not write the board field" "$(cat "$ERR")"
    check "board.sh prints no token" "" "$(grep -e "$secret" -e "$notify" "$OUT" "$ERR" || true)"

    # Credentials in the origin URL (a token remote): git's messages, which set-status passes on, drop them.
    git -C "$REPO" remote set-url origin "https://x-access-token:$secret@127.0.0.1:9/m0nclous/board-fixture.git"
    rc=0
    (cd "$REPO" && env -u HTTPS_PROXY -u https_proxy -u ALL_PROXY -u all_proxy GIT_TERMINAL_PROMPT=0 BOARD_MAIN="$REPO" \
        BOARD_NOW="2026-10-12 09:00" "$BOARD/set-status.sh" T05 "in progress") >"$OUT" 2>"$ERR" || rc=$?
    check_status "an unreachable token remote: refused" 1 "$rc"
    check_contains "it names the failed fetch" "set-status: cannot fetch $BRANCH from origin (" "$(cat "$ERR")"
    check "set-status prints no token" "" "$(grep -e "$secret" "$OUT" "$ERR" || true)"
    rc=0
    BOARD_REPO="" status "2026-10-12 09:00" T05 "in progress" --qa "round 1 approved by x" --attach "$FIXTURES/shot.png" \
        --no-push >"$OUT" 2>"$ERR" || rc=$?
    check "a link repo is not taken from a URL with credentials, and the URL is not printed" \
        "1 set-status: cannot derive owner/repo from origin; set BOARD_REPO" "$rc $(cat "$ERR")"
}

CASES+=("adv2 probe: a Phase field without the option of a phase")
case_48() {
    new_world
    state_edit '.project.fields += [{"id": "PVTSSF_phase", "name": "Phase", "type": "ProjectV2SingleSelectField", "options": [{"id": "ph0", "name": "0"}, {"id": "phB", "name": "B"}]}]'
    rc=0
    board sync >"$OUT" 2>"$ERR" || rc=$?
    check_status "the sync goes on" 0 "$rc"
    check_contains "the missing option is named" "board: warning: the board's Phase field has no option \"C\"; add it in the board settings" "$(cat "$ERR")"
    check "named once" "1" "$(grep -c 'no option "C"' "$ERR")"
    check "the Phase field is not created again" "" "$(calls | grep -- 'field-create .*--name Phase' || true)"
    check "a phase C card: Status and Area, no Phase" "card #7 Status=Todo; Area=live; QA rounds=0" "$(summary | grep '^card #7 ')"
    check "a phase B card: its Phase" "card #6 Status=Todo; Phase=B; Area=grafana; QA rounds=0" "$(summary | grep '^card #6 ')"
    reset_log
    board sync >/dev/null 2>&1
    check "the next sync writes nothing" "" "$(writes)"
}

CASES+=("adv2 probe: dependency ranges across a decade, reversed and without spaces")
# shellcheck disable=SC2016 # literal backticks in the test data
case_49() {
    new_world
    cat >"$WORK/world/parse.md" <<'EOF'
## Phase C: tail (all depend on T01; T05 only on T00)

### T00 Zero — `done`
### T01 One — `done`
### T02 Two — `todo` (depends on T08–T10)
### T03 Three — `todo` (depends on T04–T02)
### T04 Four — `todo` (depends on T01,T02&T03+T05/T06)
### T05 Five — `todo` (depends only on T00; it is on the critical path)
### T06 Six — `todo`
### T07 Seven — `todo` (depends on T03-T05, T06 and T07)
EOF
    (cd "$REPO" && TASKS_FILE="$WORK/world/parse.md" "$BOARD/board.sh" list) >"$OUT" 2>"$ERR"
    check "list" "T00  done         phase:C  process     Zero
T01  done         phase:C  exporter    One
T02  todo         phase:C  exporter    Two [blocked by T08, T09, T10]
T03  todo         phase:C  attempts    Three [blocked by T02, T04]
T04  todo         phase:C  queues      Four [blocked by T02, T03, T05, T06]
T05  todo         phase:C  grafana     Five [ready]
T06  todo         phase:C  live        Six [ready]
T07  todo         phase:C  docs        Seven [blocked by T03, T04, T05, T06]" "$(cat "$OUT")"
    check "a reversed range is named" "board: warning: T03: unparsed text in \"depends on T04–T02\": T04–T02" "$(cat "$ERR")"
}

CASES+=("adv2 probe: WIP counting")
case_50() {
    new_world
    # needs owner (T04) does not count: In Progress holds T02 only.
    status "2026-10-12 09:00" T05 "in progress" >/dev/null
    # shellcheck source-path=SCRIPTDIR/.. source=lib.sh
    check "In Progress is full" "T02 T05" "$(source "$BOARD/lib.sh" && board_load_tasks "$REPO/planning/TASKS.md" && board_ids_with_status "in progress")"
    rc=0
    status "2026-10-12 09:01" T05 "in progress" --qa "a note while In Progress is full" >"$OUT" 2>"$ERR" || rc=$?
    check "a note on a task that stays in its full column passes" "0" "$rc"
    rc=0
    BOARD_WIP_TESTING=1 status "2026-10-12 09:02" T03 testing --qa "round 1 approved by the spec tester" >"$OUT" 2>"$ERR" || rc=$?
    check "a verdict line in a full Testing column passes" "0" "$rc"
    status "2026-10-12 09:03" T05 todo >/dev/null
    rc=0
    status "2026-10-12 09:04" T04 "in progress" --answer "Yes" >"$OUT" 2>"$ERR" || rc=$?
    check "handing a task back frees its slot" "0" "$rc"
    rc=0
    BOARD_WIP_IN_PROGRESS=two status "2026-10-12 09:05" T05 "in progress" >"$OUT" 2>"$ERR" || rc=$?
    check "an invalid limit is refused" "1 set-status: invalid WIP limit for in progress: two" "$rc $(cat "$ERR")"
}

CASES+=("adv2 probe: the pull request subset of tests.yml (its legs equal full-matrix legs key by key)")
case_51() {
    if ! python3 -c 'import yaml' 2>/dev/null; then
        echo "skipped (no PyYAML)" >&2
        return 0
    fi
    OUT="$WORK/tests-yml.out"
    python3 -I - "$BOARD/../../.github/workflows/tests.yml" >"$OUT" <<'PY'
import itertools, sys, yaml


def expand(matrix):
    """GitHub's matrix expansion: product of the base keys, minus exclude, then include (docs: an include entry is
    added to every combination whose original values it does not overwrite, else it becomes a new combination)."""
    keys = [k for k in matrix if k not in ("include", "exclude")]
    combos = [dict(zip(keys, values)) for values in itertools.product(*(matrix[k] for k in keys))] if keys else []
    combos = [c for c in combos
              if not any(all(c.get(k) == v for k, v in e.items()) for e in matrix.get("exclude", []))]
    originals = [dict(c) for c in combos]
    for entry in matrix.get("include", []):
        added = False
        for combo, original in zip(combos, originals):
            # Only the product's combinations take include entries; a combination made by an include does not.
            if original is not None and all(original[k] == v for k, v in entry.items() if k in original):
                combo.update(entry)
                added = True
        if not added:
            combos.append(dict(entry))
            originals.append(None)
    return combos


wf = yaml.safe_load(open(sys.argv[1], encoding="utf-8"))
on = wf.get("on", wf.get(True))
jobs = wf["jobs"]
pr, full = jobs["tests-pr"], jobs["tests"]
print("push:", on["push"]["branches"], on["push"]["paths-ignore"])
print("pull_request filters:", on["pull_request"])
print("same steps, services, env and name:", all(pr[k] == full[k] for k in ("steps", "services", "env", "name")))
full_legs = expand(full["strategy"]["matrix"])
for leg in sorted(expand(pr["strategy"]["matrix"]), key=lambda leg: leg["php"]):
    print("pr leg:", " / ".join(str(leg[k]) for k in ("php", "laravel", "stability", "client", "redis")),
          "| a full-matrix leg with every key equal:", leg in full_legs)
for name in sorted(jobs):
    print("job", name, "if:", jobs[name].get("if", "(always)"))
print("grafana:", jobs["grafana"]["strategy"]["matrix"]["grafana"])
PY
    check "jobs, legs and filters" "push: ['master', 'claude/**'] ['planning/TASKS.md', 'planning/screenshots/**']
pull_request filters: None
same steps, services, env and name: True
pr leg: 8.2 / ^11.23 / prefer-lowest / predis / redis:6.2-alpine | a full-matrix leg with every key equal: True
pr leg: 8.3 / 13.* / prefer-stable / phpredis / redis:7.4-alpine | a full-matrix leg with every key equal: True
job cluster if: github.event_name != 'pull_request'
job coverage if: github.event_name != 'pull_request'
job dist if: github.event_name != 'pull_request'
job e2e if: github.event_name == 'schedule' || github.event_name == 'workflow_dispatch'
job grafana if: (always)
job static if: (always)
job tests if: github.event_name != 'pull_request'
job tests-pr if: github.event_name == 'pull_request'
grafana: \${{ fromJSON(github.event_name == 'pull_request' && '[\"grafana/grafana:13.2.4\"]' || '[\"grafana/grafana:12.4.13\", \"grafana/grafana:13.2.4\"]') }}" "$(cat "$OUT")"
}

CASES+=("adv2 finding: ci comments the same run again (gh escapes < and > in --jq JSON)")
case_52() {
    new_world
    mkdir -p "$REPO/src"
    echo '<?php' >"$REPO/src/app.php"
    git -C "$REPO" add src/app.php
    git -C "$REPO" commit -q -m "Add code"
    git -C "$REPO" push -q origin HEAD
    head="$(git -C "$REPO" rev-parse HEAD)"
    ci failure 900 "$head" >/dev/null 2>&1
    # Re-run of the failed jobs: attempt 2 is red again, attempt 3 is green.
    (cd "$REPO" && CI_CONCLUSION=failure CI_RUN_ID=900 CI_RUN_ATTEMPT=2 CI_HEAD_SHA="$head" "$BOARD/board.sh" ci) >/dev/null 2>&1
    (cd "$REPO" && CI_CONCLUSION=failure CI_RUN_ID=900 CI_RUN_ATTEMPT=2 CI_HEAD_SHA="$head" "$BOARD/board.sh" ci) >/dev/null 2>&1
    check "one comment per attempt" "2" "$(comments_of_issue 1 | grep -c '^  comment: \*\*Red:\*\*')"
    # A screenshot commit on top (paths-ignore) does not hide the green attempt.
    mkdir -p "$REPO/planning/screenshots/T05"
    cp "$FIXTURES/shot.png" "$REPO/planning/screenshots/T05/x.png"
    git -C "$REPO" add planning/screenshots
    git -C "$REPO" commit -q -m "board: T05 screenshots"
    (cd "$REPO" && CI_CONCLUSION=success CI_RUN_ID=900 CI_RUN_ATTEMPT=3 CI_HEAD_SHA="$head" "$BOARD/board.sh" ci) >"$OUT" 2>&1
    check "the green attempt closes the issue" "issue #1 CLOSED [CI] The integration branch is red [stop-the-line]" "$(summary | grep '^issue #1 ')"
    check_contains "under a screenshot commit" "decides: only planning/TASKS.md planning/screenshots changed" "$(cat "$OUT")"
    # A look-alike path is code: planning/screenshots-old/ is not excluded.
    mkdir -p "$REPO/planning/screenshots-old"
    echo x >"$REPO/planning/screenshots-old/x.txt"
    git -C "$REPO" add planning/screenshots-old
    git -C "$REPO" commit -q -m "Not a screenshot"
    (cd "$REPO" && CI_CONCLUSION=failure CI_RUN_ID=906 CI_HEAD_SHA="$head" "$BOARD/board.sh" ci) >"$OUT" 2>&1
    check_contains "a look-alike path counts as a change" "ignoring tests run 906" "$(cat "$OUT")"
}

# --- Adversarial QA, round 2: findings (these fail until fixed) ----------------------------------------------------

CASES+=("adv2 finding: the dependency gate blocks moves that do not start work")
# shellcheck disable=SC2016 # literal backticks in the test data
case_53() {
    new_world
    # T06 (depends on T02–T04, T05) is in testing; its dependency T05 was done and is reopened after a regression.
    edit_heading 's/^### T02 Exposition — `in progress`/### T02 Exposition — `done`/; s/^### T03 Attempts — `testing`/### T03 Attempts — `done`/; s/^### T04 Queue state — `needs owner`/### T04 Queue state — `done`/; s/^### T05 Grafana port — `todo`/### T05 Grafana port — `in progress`/; s/^### T06 Live backend — `todo`/### T06 Live backend — `testing`/' \
        "T02-T04 done, T05 reopened, T06 in testing"
    rc=0
    status "2026-10-12 09:00" T06 testing >"$OUT" 2>"$ERR" || rc=$?
    check "a no-op says so" "0 set-status: T06 is already testing" "$rc $(cat "$OUT" "$ERR")"
    rc=0
    status "2026-10-12 09:01" T06 testing --qa "round 1 approved by the spec tester" >"$OUT" 2>"$ERR" || rc=$?
    check "a tester records a verdict line on a task that stays in testing" "0" "$rc"
    rc=0
    status "2026-10-12 09:02" T06 "in progress" --qa "round 1 rejected by the adversarial tester: the snapshot leaks" >"$OUT" 2>"$ERR" || rc=$?
    check "a QA rejection returns to In Progress" "0 ### T06 Live backend — \`in progress\` (depends on T02–T04, T05)" \
        "$rc $(grep '^### T06 ' "$REPO/planning/TASKS.md")"
    rc=0
    status "2026-10-12 09:03" T06 "needs owner" --question "Wait for the T05 fix?" >"$OUT" 2>"$ERR" || rc=$?
    check_status "needs owner has no dependency gate" 0 "$rc"
    rc=0
    status "2026-10-12 09:04" T06 "in progress" --answer "No, go on" >"$OUT" 2>"$ERR" || rc=$?
    check "the owner's answer returns the task to In Progress" "0 ### T06 Live backend — \`in progress\` (depends on T02–T04, T05)" \
        "$rc $(grep '^### T06 ' "$REPO/planning/TASKS.md")"
    rc=0
    status "2026-10-12 09:05" T06 "done" --qa "round 2 approved by the spec and adversarial testers" >"$OUT" 2>"$ERR" || rc=$?
    check_status "done still needs every dependency done" 1 "$rc"
}

CASES+=("adv2 finding: a board field that cannot be created stops the whole sync")
case_54() {
    new_world
    # Every card can take its Status (the fixture board has no "Needs owner" option for T04's card otherwise).
    state_edit '(.project.fields[] | select(.name == "Status") | .options) += [{"id": "st_owner", "name": "Needs owner"}]'
    rc=0
    (cd "$REPO" && GH_STUB_FAIL="project field-create" "$BOARD/board.sh" sync) >"$OUT" 2>"$ERR" || rc=$?
    check_status "the sync goes on without the field" 0 "$rc"
    check "every task has its issue" "8" "$(jq '[.issues[] | select(.labels | index("task"))] | length' "$GH_STUB_STATE")"
    check "the cards still get their Status" "8" "$(summary | grep -c '^card #[0-9]* Status=')"
    check "each field that cannot be created is named once" "5" "$(grep -c '^board: warning: could not create the board field' "$ERR")"
    check_contains "the warning" "board: warning: could not create the board field \"Area\" (gh stub: injected failure for: gh project field-create 5 --owner @me --name Area" "$(cat "$ERR")"
    reset_log
    board sync >/dev/null 2>&1
    check "the next sync creates them" "5" "$(calls | grep -c '^gh project field-create')"

    # A board that cannot be read at all (the token's scope, a wrong number): the issues are synced first, then the
    # sync names the board and fails, so the run shows it.
    new_world
    rc=0
    (cd "$REPO" && GH_STUB_FAIL="project item-list" "$BOARD/board.sh" sync) >"$OUT" 2>"$ERR" || rc=$?
    check_status "an unreadable board fails the run" 1 "$rc"
    check "the issues, labels and comments are synced before" "8 issues, 5 comments" \
        "$(jq -r '"\([.issues[] | select(.labels | index("task"))] | length) issues, \([.issues[].comments[]] | length) comments"' "$GH_STUB_STATE")"
    check_contains "it says what is synced" "board: cannot read the items of board 5 of @me (gh's message above); the issues, labels and comments are synced, the board is not" "$(cat "$ERR")"
    board sync >/dev/null 2>&1
    check "the next sync places the cards" "8" "$(summary | grep -c '^card #')"

    # A card that cannot be added: a warning per card, the next sync adds it.
    new_world
    rc=0
    (cd "$REPO" && GH_STUB_FAIL="project item-add" "$BOARD/board.sh" sync) >"$OUT" 2>"$ERR" || rc=$?
    check_status "a card that cannot be added does not stop the sync" 0 "$rc"
    check "named per card" "8" "$(grep -c '^board: warning: could not add https://github.com/m0nclous/board-fixture/issues/[0-9]* to the board' "$ERR")"
    board sync >/dev/null 2>&1
    check "the next sync adds them" "8" "$(summary | grep -c '^card #')"
}

CASES+=("adv2 finding: a main checkout path with a space")
# shellcheck disable=SC2016 # literal backticks in the test data
case_55() {
    new_world
    spaced="$WORK/world/with space"
    mkdir -p "$spaced"
    git clone -q -b "$BRANCH" "$WORK/world/origin.git" "$spaced/main"
    git -C "$spaced/main" worktree add -q -b wip/T05-grafana "$spaced/T05" >/dev/null 2>&1
    rc=0
    (cd "$spaced/T05" && BOARD_NOW="2026-10-12 09:00" "$BOARD/set-status.sh" T05 "in progress") >"$OUT" 2>"$ERR" || rc=$?
    check "from a worktree, BOARD_MAIN unset" "0 ### T05 Grafana port — \`in progress\` (depends only on T00; start first)" \
        "$rc $(grep '^### T05 ' "$spaced/main/planning/TASKS.md")"
}

CASES+=("adv2 finding: a dependency clause or task heading the parser does not know is dropped without a warning")
# shellcheck disable=SC2016 # literal backticks in the test data
case_56() {
    new_world
    cat >"$WORK/world/parse.md" <<'EOF'
## Phase B: packages

### T00 Zero — `done`
### T01 One — `todo`
### T02 Two — `todo` (Depends on T01)
### T03 Three — `todo` (depend on T01)
### T04 Four (depends on T01) — `todo`
### T05 Five - `todo` (depends on T01)
### T06 Six — `Todo` (depends on T01)
### T07 Seven — `in-progress`
EOF
    (cd "$REPO" && TASKS_FILE="$WORK/world/parse.md" "$BOARD/board.sh" list) >"$OUT" 2>"$ERR"
    kept=""
    # T05-T07: a hyphen instead of the em dash, a capital or a typo in the status drop the whole task from the board.
    for id in T02 T03 T04 T05 T06 T07; do
        if grep -q "^$id .*blocked by T01" "$OUT" || grep -q "^board: warning: $id" "$ERR"; then
            kept+="$id kept "
        else
            kept+="$id dropped "
        fi
    done
    check "listed and blocked by T01, or warned about" "T02 kept T03 kept T04 kept T05 kept T06 kept T07 kept " "$kept"
}

CASES+=("adv2 finding: a failed commit leaves the shared main checkout dirty")
# shellcheck disable=SC2016 # literal backticks in the test data
case_57() {
    # Commit signing through a program that fails (this environment signs every commit with gpg.ssh.program);
    # a failing pre-commit hook or a missing identity end the same way.
    failing_signer() {
        : >"$WORK/world/key.pub"
        git -C "$REPO" config commit.gpgsign true
        git -C "$REPO" config gpg.format ssh
        git -C "$REPO" config user.signingkey "$WORK/world/key.pub"
        git -C "$REPO" config gpg.ssh.program false
    }
    new_world
    failing_signer
    before="$(tasks_sum)"
    rc=0
    status "2026-10-12 09:01" T03 "done" --qa "round 1 approved by x" --attach "$FIXTURES/shot.png" >"$OUT" 2>"$ERR" || rc=$?
    check "the screenshot commit fails: exit 1" "1" "$rc"
    check "the screenshot commit fails: the checkout is clean (no staged screenshot)" "" "$(git -C "$REPO" status --porcelain)"
    check "the screenshot commit fails: HEAD and TASKS.md unchanged" "$before" "$(tasks_sum)"
    check_contains "the screenshot commit fails: git's reason" "set-status: cannot commit (" "$(cat "$ERR")"
    check_contains "the screenshot commit fails: undone" "set-status: undid this attempt: HEAD, planning/TASKS.md and the screenshots are back at ${before:0:7}; nothing changed" "$(cat "$ERR")"

    new_world
    failing_signer
    before="$(tasks_sum)"
    rc=0
    status "2026-10-12 09:00" T05 "in progress" --qa "round 1 approved by x" >"$OUT" 2>"$ERR" || rc=$?
    check "the status commit fails: exit 1 and a message" "1 yes" "$rc $(grep -q '^set-status: ' "$ERR" && echo yes)"
    check "the status commit fails: HEAD and TASKS.md unchanged" "$before" "$(tasks_sum)"
    check "the status commit fails: the checkout is clean" "" "$(git -C "$REPO" status --porcelain)"
    # Signing works again: the next call (any agent's) must not find the checkout blocked by the failed one.
    git -C "$REPO" config commit.gpgsign false
    rc=0
    status "2026-10-12 09:05" T05 "in progress" >"$OUT" 2>"$ERR" || rc=$?
    check "the next call goes through" "0 ### T05 Grafana port — \`in progress\` (depends only on T00; start first)" \
        "$rc $(grep '^### T05 ' "$REPO/planning/TASKS.md")"
    check "and commits only its own change" "board: T05 -> in progress" "$(git -C "$REPO" log -1 --format=%s)"

    # The screenshot commit lands, then a hook refuses the status commit: both are undone.
    new_world
    printf '#!/bin/sh\ngrep -q "^board: T[0-9]* ->" "$1" && { echo "commit-msg: no status commits today" >&2; exit 1; }\nexit 0\n' >"$REPO/.git/hooks/commit-msg"
    chmod +x "$REPO/.git/hooks/commit-msg"
    before="$(tasks_sum)"
    rc=0
    status "2026-10-12 09:01" T03 "done" --qa "round 1 approved by x" --attach "$FIXTURES/shot.png" >"$OUT" 2>"$ERR" || rc=$?
    check "the status commit after the screenshot commit fails: exit 1, git's reason" "1 yes" \
        "$rc $(grep -q '^set-status: cannot commit (commit-msg: no status commits today)' "$ERR" && echo yes)"
    check "both undone: HEAD, TASKS.md, no screenshot" "$before " "$(tasks_sum) $(git -C "$REPO" status --porcelain)"
    check "origin unchanged" "$(git -C "$REPO" rev-parse HEAD)" "$(git -C "$WORK/world/origin.git" rev-parse "$BRANCH")"
}

CASES+=("adv2 finding: approvals worded with a negation count as rejections")
case_58() {
    new_world
    {
        status "2026-10-12 09:00" T05 todo --qa "round 1 approved by the spec and adversarial testers, no changes requested"
        status "2026-10-12 09:01" T03 testing --qa "round 2 QA verdict: APPROVED by the spec tester (nothing rejected, nothing open)"
        status "2026-10-12 09:02" T03 testing --qa "round 2 approved by the adversarial tester: the round 1 findings that were rejected are fixed"
    } >/dev/null
    board sync >/dev/null 2>&1
    check "T05: one approved round" "card #6 Status=Todo; Phase=B; Area=grafana; QA rounds=1" "$(summary | grep '^card #6 ')"
    board report >/dev/null 2>&1
    report="$(jq -r '.issues[] | select(.title == "[Board] Flow report") | .body' "$GH_STUB_STATE")"
    # T01 round 1 approved, T02 round 1 rejected (the history), T03 round 2 and T05 round 1 approved.
    check_contains "the rejection rate counts only the real rejection" "- QA rounds: 4 (3 approved, 1 rejected), rejection rate 25 %." "$report"
}

CASES+=("adv2 finding: set-status publishes another session's unpushed commits")
# shellcheck disable=SC2016 # literal backticks in the test data
case_59() {
    new_world
    # The integrator merges a task branch locally (--no-ff) and runs the gates before pushing it.
    git -C "$REPO" checkout -q -b wip/T05-grafana
    mkdir -p "$REPO/src"
    echo '<?php // not reviewed yet' >"$REPO/src/grafana.php"
    git -C "$REPO" add src/grafana.php
    git -C "$REPO" commit -q -m "Port the dashboard"
    git -C "$REPO" checkout -q "$BRANCH"
    git -C "$REPO" merge -q --no-ff -m "integrator: merge wip/T05-grafana" wip/T05-grafana
    local_merge="$(git -C "$REPO" rev-parse HEAD)"
    # Meanwhile another agent records a verdict on another task in the same main checkout.
    before="$(tasks_sum)"
    pushed="$(git -C "$WORK/world/origin.git" rev-parse "$BRANCH")"
    rc=0
    status "2026-10-12 09:00" T03 testing --qa "round 1 approved by the spec tester" >"$OUT" 2>"$ERR" || rc=$?
    check "the integrator's local merge is not pushed by someone else's status change" "no" \
        "$(git -C "$WORK/world/origin.git" merge-base --is-ancestor "$local_merge" "$BRANCH" 2>/dev/null && echo yes || echo no)"
    check_status "refused" 1 "$rc"
    check "refused: it names the commits and the way out" "set-status: $REPO has 2 local commit(s) that origin/$BRANCH does not have, and not only status changes (${local_merge:0:7} integrator: merge wip/T05-grafana; $(git -C "$REPO" rev-parse HEAD^2 | cut -c1-7) Port the dashboard): the push of a status change would publish them. Push them yourself when they are ready (an integrator merges in a separate worktree, or pushes the merge first), or move them to a task branch, then try again" "$(cat "$ERR")"
    check "refused: nothing changed" "$before $pushed" "$(tasks_sum) $(git -C "$WORK/world/origin.git" rev-parse "$BRANCH")"
    # The integrator pushes the merge when it is ready: status changes go through again.
    git -C "$REPO" push -q origin HEAD
    rc=0
    status "2026-10-12 09:05" T03 testing --qa "round 1 approved by the spec tester" >"$OUT" 2>"$ERR" || rc=$?
    check "after the integrator's push" "0 $(git -C "$REPO" rev-parse HEAD)" "$rc $(git -C "$WORK/world/origin.git" rev-parse "$BRANCH")"
    # A commit with a status subject that carries code is not a status change either.
    echo '<?php' >"$REPO/src/other.php"
    git -C "$REPO" add src/other.php
    git -C "$REPO" commit -q -m "board: T05 -> testing"
    rc=0
    status "2026-10-12 09:10" T04 todo --answer "Not now" >"$OUT" 2>"$ERR" || rc=$?
    check_contains "a status subject with code: refused" "not only status changes ($(git -C "$REPO" rev-parse HEAD | cut -c1-7) board: T05 -> testing)" "$rc $(cat "$ERR")"
    git -C "$REPO" reset -q --hard HEAD~1
    # set-status's own unpushed commits (--no-push) still go out with the next status change.
    status "2026-10-12 09:15" T04 todo --answer "Not now" --no-push >/dev/null
    status "2026-10-12 09:16" T05 "in progress" >/dev/null
    check "own commits go out" "board: T05 -> in progress
board: T04 -> todo (answer)" "$(git -C "$WORK/world/origin.git" log -2 --format=%s "$BRANCH")"
}

# --- QA round 3: the round 2 findings, fixed (cases 53-59 above), and the new rules ---------------------------------

# code_commit: a commit of code on top of the world's history, pushed; sets head and short.
code_commit() {
    mkdir -p "$REPO/src"
    echo '<?php' >"$REPO/src/app.php"
    git -C "$REPO" add src/app.php
    git -C "$REPO" commit -q -m "Add code"
    git -C "$REPO" push -q origin HEAD
    head="$(git -C "$REPO" rev-parse HEAD)"
    short="${head:0:7}"
}

CASES+=("qa3 stop the line: a green push run does not close what only a weekly or manual run runs")
case_60() {
    new_world
    code_commit
    # The weekly run (schedule) fails its e2e job on Grafana 12.4.13.
    CI_EVENT=schedule ci failure 910 "$head" >"$OUT" 2>"$ERR"
    check "the weekly run opens the issue" "issue #1 OPEN [CI] The integration branch is red [stop-the-line]" "$(summary | grep '^issue #1 ')"
    check_contains "the comment names the event and the job" "(attempt 1, schedule) at \`$short\` on \`$BRANCH\`.\\n\\nFailed jobs:\\n- End to end / grafana/grafana:12.4.13" "$(comments_of_issue 1)"
    # A push run is green, but it skips the e2e job: the line stays stopped, with one comment that says how to go on.
    waiting="$(printf '%s' "End to end / grafana/grafana:12.4.13" | sha1 | cut -c1-12)"
    reset_log
    CI_EVENT=push ci success 911 "$head" >"$OUT" 2>"$ERR"
    check "a green push run without the red job: one comment, no close" "gh issue comment 1 --repo m0nclous/board-fixture --body '**Green, but not every red job ran:** [tests run 911](https://github.com/m0nclous/board-fixture/actions/runs/911) (attempt 1, push) at \`<head>\` on \`$BRANCH\` is green, but it did not run End to end / grafana/grafana:12.4.13, which failed before. The line stays stopped until a run that runs them is green: run the \`tests\` workflow by hand on \`$BRANCH\` (Actions → tests → Run workflow, or MCP \`actions_run_trigger\`), or wait for the weekly run.\\n\\n<!-- ci-waiting:$waiting -->'" \
        "$(writes | sed "s/$short/<head>/g")"
    check "still open" "issue #1 OPEN [CI] The integration branch is red [stop-the-line]" "$(summary | grep '^issue #1 ')"
    reset_log
    CI_EVENT=push ci success 911 "$head" >"$OUT" 2>"$ERR"
    CI_EVENT=push ci success 914 "$head" >"$OUT" 2>"$ERR"
    check "the same run again and another such push run: no write" "" "$(writes)"
    check "they say what the line waits for" "ci      tests run 914 is green, but #1 still waits for a green run of: End to end / grafana/grafana:12.4.13" "$(cat "$OUT")"
    # A push run that fails another job adds it; a green push run that runs that job leaves only the e2e job open.
    CI_EVENT=push ci failure 913 "$head" >/dev/null 2>&1
    reset_log
    CI_EVENT=push ci success 914 "$head" >"$OUT" 2>"$ERR"
    check "the push job is green again, the e2e job still waits: no new comment" "" "$(writes)"
    # A manual run (or the next weekly run) runs the e2e job green: the line moves again.
    reset_log
    CI_EVENT=workflow_dispatch ci success 912 "$head" >"$OUT" 2>"$ERR"
    check "a manual run with the e2e job green closes the issue" "gh issue close 1 --repo m0nclous/board-fixture --reason completed --comment '**Green again:** [tests run 912](https://github.com/m0nclous/board-fixture/actions/runs/912) (attempt 1, workflow_dispatch) at \`<head>\` on \`$BRANCH\`. The line moves again.\\n\\n<!-- ci-green:912-1 -->'" \
        "$(writes | sed "s/$short/<head>/g")"
    check "the comments of the issue" "  comment: **Red:** [tests run 910](https://github.com/m0nclous/board-fixture/actions/runs/910) (attempt 1, schedule) at \`<head>\` on \`$BRANCH\`.\\n\\nFailed jobs:\\n- End to end / grafana/grafana:12.4.13\\n\\n<!-- ci-run:910-1 -->\\n<!-- ci-jobs:<record> -->
  comment: **Green, but not every red job ran:** [tests run 911](https://github.com/m0nclous/board-fixture/actions/runs/911) (attempt 1, push) at \`<head>\` on \`$BRANCH\` is green, but it did not run End to end / grafana/grafana:12.4.13, which failed before. The line stays stopped until a run that runs them is green: run the \`tests\` workflow by hand on \`$BRANCH\` (Actions → tests → Run workflow, or MCP \`actions_run_trigger\`), or wait for the weekly run.\\n\\n<!-- ci-waiting:$waiting -->
  comment: **Red:** [tests run 913](https://github.com/m0nclous/board-fixture/actions/runs/913) (attempt 1, push) at \`<head>\` on \`$BRANCH\`.\\n\\nFailed jobs:\\n- PHP 8.3 / L13.* / prefer-stable / phpredis / redis:7.4-alpine\\n\\n<!-- ci-run:913-1 -->\\n<!-- ci-jobs:<record> -->
  comment: **Green again:** [tests run 912](https://github.com/m0nclous/board-fixture/actions/runs/912) (attempt 1, workflow_dispatch) at \`<head>\` on \`$BRANCH\`. The line moves again.\\n\\n<!-- ci-green:912-1 -->" \
        "$(comments_of_issue 1 | sed "s/$short/<head>/g" | norecord)"
    check "skipped jobs are not recorded" "PHP 8.3 / L13.* / prefer-stable / phpredis / redis:7.4-alpine=failure
Static analysis and style=success" "$(record_of 1 913)"

    # A red comment written before the job records: a green run closes the issue (nothing is recorded as red).
    new_world
    code_commit
    CI_EVENT=schedule ci failure 910 "$head" >/dev/null 2>&1
    state_edit '(.issues[0].comments[0].body) |= sub("\n<!-- ci-jobs:[^ ]* -->"; "")'
    CI_EVENT=push ci success 911 "$head" >/dev/null 2>&1
    check "an old red comment without a record: a green run closes" "issue #1 CLOSED [CI] The integration branch is red [stop-the-line]" "$(summary | grep '^issue #1 ')"

    # A green run whose jobs cannot be listed: it closes the issue on its conclusion alone, and says so.
    new_world
    code_commit
    CI_EVENT=schedule ci failure 910 "$head" >/dev/null 2>&1
    rc=0
    (cd "$REPO" && GH_STUB_FAIL="run view" CI_CONCLUSION=success CI_RUN_ID=911 CI_HEAD_SHA="$head" "$BOARD/board.sh" ci) >"$OUT" 2>"$ERR" || rc=$?
    check "no job list: closed on the conclusion" "0 issue #1 CLOSED [CI] The integration branch is red [stop-the-line]" "$rc $(summary | grep '^issue #1 ')"
    check_contains "no job list: a warning" "board: warning: could not list the jobs of tests run 911; it closes the issue on its conclusion alone" "$(cat "$ERR")"
}

CASES+=("qa3 board.yml: the token, the refs and the runs that decide")
case_61() {
    if ! python3 -c 'import yaml' 2>/dev/null; then
        echo "skipped (no PyYAML)" >&2
        return 0
    fi
    OUT="$WORK/board-yml.out"
    python3 -I - "$BOARD/../../.github/workflows/board.yml" >"$OUT" <<'PY'
import sys, yaml

wf = yaml.safe_load(open(sys.argv[1], encoding="utf-8"))
print("workflow env:", sorted(wf.get("env", {})))
for name, job in wf["jobs"].items():
    print("job", name, "if:", " ".join(str(job.get("if", "(always)")).split()))
    for step in job["steps"]:
        what = step.get("uses") or step.get("run", "").strip()
        print("  step:", what, "| GH_TOKEN:", step.get("env", {}).get("GH_TOKEN", "-"), "| shell:", step.get("shell", "-"))
PY
    check "jobs, conditions and the token" "workflow env: ['BOARD_NUMBER', 'BOARD_OWNER', 'BOARD_REPO', 'BOARD_WIP_IN_PROGRESS', 'BOARD_WIP_TESTING']
job sync if: \${{ vars.BOARD_NUMBER != '' && (github.event_name == 'push' || github.event_name == 'workflow_dispatch') && github.ref_name == (vars.INTEGRATION_BRANCH || 'claude/hopeful-hawking-njeu3v') }}
  step: actions/checkout@v7 | GH_TOKEN: - | shell: -
  step: tools/board/board.sh sync 2>&1 | sed 's/^board: warning: /::warning title=board::/' | GH_TOKEN: \${{ secrets.PROJECT_TOKEN }} | shell: bash
job ci if: \${{ vars.BOARD_NUMBER != '' && github.event_name == 'workflow_run' && contains(fromJSON('[\"push\", \"schedule\", \"workflow_dispatch\"]'), github.event.workflow_run.event) && github.event.workflow_run.head_branch == (vars.INTEGRATION_BRANCH || 'claude/hopeful-hawking-njeu3v') }}
  step: actions/checkout@v7 | GH_TOKEN: - | shell: -
  step: tools/board/board.sh ci 2>&1 | sed 's/^board: warning: /::warning title=board::/' | GH_TOKEN: \${{ secrets.PROJECT_TOKEN }} | shell: bash
job report if: \${{ always() && (needs.sync.result == 'success' || needs.ci.result == 'success') }}
  step: actions/checkout@v7 | GH_TOKEN: - | shell: -
  step: tools/board/board.sh report 2>&1 | sed 's/^board: warning: /::warning title=board::/' | GH_TOKEN: \${{ secrets.PROJECT_TOKEN }} | shell: bash" "$(cat "$OUT")"
    # The sync step's own line, with a board.sh that warns and fails: the warning becomes an annotation, and the
    # step keeps board.sh's exit code (shell: bash runs the line with -eo pipefail).
    printf '#!/bin/sh\necho "board: warning: no area for T19" >&2\necho "board: cannot read the board" >&2\nexit 1\n' >"$WORK/fake-board.sh"
    chmod +x "$WORK/fake-board.sh"
    line="$(grep -m 1 'tools/board/board.sh sync 2>&1' "$BOARD/../../.github/workflows/board.yml" | sed 's/^ *//')"
    rc=0
    annotated="$(bash --noprofile --norc -eo pipefail -c "${line/tools\/board\/board.sh/$WORK/fake-board.sh}")" || rc=$?
    check "a warning becomes an annotation, the exit code stays" "1 ::warning title=board::no area for T19
board: cannot read the board" "$rc $annotated"
}

CASES+=("qa3 set-status retries a lost push race with a random back-off")
case_62() {
    new_world
    other_clone
    mkdir -p "$OTHER/src"
    # A pre-push hook that lets the other session push first, as long as $WORK/world/lose says so.
    hook="$REPO/.git/hooks/pre-push"
    cat >"$hook" <<EOF
#!/bin/sh
unset GIT_DIR GIT_WORK_TREE GIT_INDEX_FILE
left=\$(cat "$WORK/world/lose")
[ "\$left" -gt 0 ] || exit 0
echo \$((left - 1)) >"$WORK/world/lose"
cd "$OTHER" && git pull -q --ff-only && echo "\$left" >>src/race.txt && git add src/race.txt &&
    git commit -q -m "Another session's commit \$left" && git push -q origin HEAD
EOF
    chmod +x "$hook"
    echo 4 >"$WORK/world/lose"
    rc=0
    status "2026-10-12 09:00" T05 "in progress" >"$OUT" 2>"$ERR" || rc=$?
    check "four lost races, then the fifth attempt lands" "0 4" "$rc $(grep -c 'lost the race with another push' "$ERR")"
    check_contains "it counts the attempts" "set-status: attempt 4 of 10 lost the race with another push" "$(cat "$ERR")"
    check "on origin: the other session's four commits, then the status change" "board: T05 -> in progress
Another session's commit 1
Another session's commit 2
Another session's commit 3
Another session's commit 4" "$(git -C "$WORK/world/origin.git" log -5 --format=%s "$BRANCH")"
    check "one status commit, a clean checkout" "1 " "$(git -C "$REPO" log --format=%s | grep -c '^board: T05 -> in progress$') $(git -C "$REPO" status --porcelain --untracked-files=no)"

    echo 5 >"$WORK/world/lose"
    rc=0
    BOARD_PUSH_TRIES=3 status "2026-10-12 09:10" T04 todo --answer "Not now" >"$OUT" 2>"$ERR" || rc=$?
    check "BOARD_PUSH_TRIES=3: gives up cleanly" "1 set-status: origin/$BRANCH kept moving; nothing changed after 3 attempts (BOARD_PUSH_TRIES), try again" "$rc $(tail -n 1 "$ERR")"
    check "nothing of it on origin" "Another session's commit 3" "$(git -C "$WORK/world/origin.git" log -1 --format=%s "$BRANCH")"
    # The last lost round leaves the checkout one commit behind origin (the next call fast-forwards it), clean.
    git -C "$REPO" fetch -q origin
    check "the checkout trails origin, clean" "behind " "$(git -C "$REPO" merge-base --is-ancestor HEAD "origin/$BRANCH" && echo behind) $(git -C "$REPO" status --porcelain --untracked-files=no)"
    rc=0
    BOARD_PUSH_TRIES=0 status "2026-10-12 09:10" T04 todo >"$OUT" 2>"$ERR" || rc=$?
    check "an invalid number of attempts" "1 set-status: invalid BOARD_PUSH_TRIES: 0" "$rc $(cat "$ERR")"
}

CASES+=("qa3 set-status gates an answer only when it starts work")
# shellcheck disable=SC2016 # literal backticks in the test data
case_63() {
    new_world
    # T06 is blocked (T02-T05 are not done). A question before anyone started it, then the answer: still blocked.
    status "2026-10-12 09:00" T06 "needs owner" --question "Start with the snapshot?" >/dev/null
    rc=0
    status "2026-10-12 09:05" T06 "in progress" --answer "Yes" >"$OUT" 2>"$ERR" || rc=$?
    check "an answer cannot start a blocked task" "1 set-status: T06 depends on T02 (in progress), T03 (testing), T04 (needs owner), T05 (todo), not done yet; --force --reason \"why\" overrides" "$rc $(cat "$ERR")"
    # Started (forced, for a spike), then a question, then the answer: the task resumes.
    status "2026-10-12 09:10" T06 "in progress" --answer "Yes" --force --reason "a spike the owner asked for" >/dev/null 2>&1
    status "2026-10-12 09:20" T06 "needs owner" --question "Keep the spike?" >/dev/null
    BOARD_WIP_IN_PROGRESS=3 status "2026-10-12 09:30" T06 "in progress" --answer "Yes, go on" >"$OUT" 2>"$ERR"
    check "an answer that resumes started work passes" "### T06 Live backend — \`in progress\` (depends on T02–T04, T05)" "$(grep '^### T06 ' "$REPO/planning/TASKS.md")"
    # Handed back (todo), asked again, answered: that would start it again, so it is gated again.
    status "2026-10-12 09:40" T06 todo >/dev/null
    status "2026-10-12 09:50" T06 "needs owner" --question "Pick it up later?" >/dev/null
    rc=0
    BOARD_WIP_IN_PROGRESS=3 status "2026-10-12 10:00" T06 "in progress" --answer "Now" >"$OUT" 2>"$ERR" || rc=$?
    check_status "a task handed back to todo is gated again" 1 "$rc"
    # Moves inside the work are never gated by dependencies: testing, and a note on a task in testing.
    BOARD_WIP_IN_PROGRESS=3 status "2026-10-12 10:10" T06 "in progress" --answer "Now" --force --reason "go" >/dev/null 2>&1
    rc=0
    status "2026-10-12 10:20" T06 testing >"$OUT" 2>"$ERR" || rc=$?
    check "in progress -> testing is not gated by dependencies" "0 " "$rc $(cat "$ERR")"
}

CASES+=("qa3 parser warnings and a task outside a phase")
# shellcheck disable=SC2016 # literal backticks in the test data
case_64() {
    new_world
    cat >"$WORK/world/parse.md" <<'EOF'
## Phase B: packages (All Depend On T00)

### T00 Zero — `done`
### T01 One — `todo`
### T02 Two — `todo` (Depends on T01)
### T03 Three — `todo` (depend on T01)
### T04 Four (depends on T01) — `todo`
### T05 Five - `todo` (depends on T01)
### T06 Six — `Todo` (depends on T01)
### T07 Seven — `in-progress`
## T08 Eight — `todo`
### T09 Nine — `todo` (after T05 lands)
### T10 Ten — `todo` (Depends On T01 Through T02 AND T05)
### T11 Eleven — `todo` (depends on T01; T05 later)
EOF
    (cd "$REPO" && TASKS_FILE="$WORK/world/parse.md" "$BOARD/board.sh" list) >"$OUT" 2>"$ERR"
    check "list" "T00  done         phase:B  process     Zero
T01  todo         phase:B  exporter    One [ready]
T02  todo         phase:B  exporter    Two [blocked by T01]
T03  todo         phase:B  attempts    Three [ready]
T04  todo         phase:B  queues      Four (depends on T01) [ready]
T09  todo         phase:-  -           Nine [ready]
T10  todo         phase:-  -           Ten [blocked by T01, T02, T05]
T11  todo         phase:-  -           Eleven [blocked by T01]" "$(cat "$OUT")"
    known="todo, in progress, testing, needs owner, done"
    check "warnings" "board: warning: T03: \"(depend on T01)\" names a dependency without a \"(depends on ...)\" clause, so it is not read (the task keeps its phase's dependencies)
board: warning: T04: a dependency clause in the title is not read (put it after the status): Four (depends on T01)
board: warning: T05: not a task heading, so the board does not show it (want \"### Txx Title — \`status\`\" with an em dash and a status of $known): ### T05 Five - \`todo\` (depends on T01)
board: warning: T06: not a task heading, so the board does not show it (want \"### Txx Title — \`status\`\" with an em dash and a status of $known): ### T06 Six — \`Todo\` (depends on T01)
board: warning: T07: not a task heading, so the board does not show it (want \"### Txx Title — \`status\`\" with an em dash and a status of $known): ### T07 Seven — \`in-progress\`
board: warning: T08: not a task heading, so the board does not show it (want \"### Txx Title — \`status\`\" with an em dash and a status of $known): ## T08 Eight — \`todo\`
board: warning: T09: \"(after T05 lands)\" names a dependency without a \"(depends on ...)\" clause, so it is not read (the task keeps its phase's dependencies)
board: warning: no area for T09 T10 T11 in $FIXTURES/areas.tsv; add a line \"Txx<TAB>area\" for every task (the Area field stays empty)" "$(cat "$ERR")"
    # set-status explains why it cannot find a task whose heading it cannot read.
    cp "$WORK/world/parse.md" "$REPO/planning/TASKS.md"
    git -C "$REPO" commit -q -am "Odd headings"
    rc=0
    status "2026-10-12 09:00" T06 todo --no-push >"$OUT" 2>"$ERR" || rc=$?
    check_contains "set-status: the heading is named" "board: warning: T06: not a task heading" "$(cat "$ERR")"
    check_contains "set-status: then not found" "set-status: task T06 not found in planning/TASKS.md" "$rc $(cat "$ERR")"
}

CASES+=("qa3 QA verdicts come from the words after round N")
case_65() {
    # shellcheck source-path=SCRIPTDIR/.. source=lib.sh
    actual="$(source "$BOARD/lib.sh" && while IFS= read -r text; do
        printf '%s|%s|%s\n' "$(board_qa_verdict "$text")" "$(board_qa_round "$text")" "$text"
    done <<'EOF'
round 1 approved by the spec and adversarial testers, no changes requested
round 2 QA verdict: APPROVED by the spec tester (nothing rejected, nothing open)
round 2 approved by the adversarial tester: the round 1 findings that were rejected are fixed
round 1 rejected by the spec tester: a missing test
round 2 QA verdict: CHANGES REQUESTED (spec tester): the range parser
round 2 not approved by the adversarial tester
Round 3: approved by both testers
spec tester, round 4 — rejected: the cache key
QA verdict: CHANGES REQUESTED by the spec tester: x
rejected by the spec tester: x
escalated to the owner: 3 rounds rejected before
unapproved notes and a disapproved idea
picked up
EOF
    )"
    check "verdict|round|text" "approved|1|round 1 approved by the spec and adversarial testers, no changes requested
approved|2|round 2 QA verdict: APPROVED by the spec tester (nothing rejected, nothing open)
approved|2|round 2 approved by the adversarial tester: the round 1 findings that were rejected are fixed
rejected|1|round 1 rejected by the spec tester: a missing test
rejected|2|round 2 QA verdict: CHANGES REQUESTED (spec tester): the range parser
rejected|2|round 2 not approved by the adversarial tester
approved|3|Round 3: approved by both testers
rejected|4|spec tester, round 4 — rejected: the cache key
rejected||QA verdict: CHANGES REQUESTED by the spec tester: x
rejected||rejected by the spec tester: x
||escalated to the owner: 3 rounds rejected before
||unapproved notes and a disapproved idea
||picked up" "$actual"
    # An approval with a negation does not pass the WIP exception of a QA rejection.
    new_world
    status "2026-10-12 09:00" T05 "in progress" >/dev/null
    rc=0
    status "2026-10-12 09:10" T03 "in progress" --qa "round 1 approved by the spec tester, nothing rejected" >"$OUT" 2>"$ERR" || rc=$?
    check_contains "an approval is no rejection at a full In Progress" "1 set-status: the WIP limit of in progress is 2" "$rc $(cat "$ERR")"
}

CASES+=("qa3 set-status names a missing tool")
case_66() {
    new_world
    # A PATH with every command of this one but flock.
    nobin="$WORK/no-flock"
    mkdir -p "$nobin"
    local dir file
    IFS=: read -ra dirs <<<"$PATH"
    for dir in "${dirs[@]}"; do
        for file in "$dir"/*; do
            [[ -x "$file" && "${file##*/}" != flock && ! -e "$nobin/${file##*/}" ]] && ln -s "$file" "$nobin/${file##*/}"
        done
    done
    rc=0
    PATH="$nobin" status "2026-10-12 09:00" T05 "in progress" >"$OUT" 2>"$ERR" || rc=$?
    check "flock missing" "1 set-status: flock is required (set-status.sh needs git, python3, flock, od and cmp; on macOS: brew install flock)" "$rc $(cat "$ERR")"
}

run_cases

PASSED="$(grep -c '^ok$' "$RESULTS" || true)"
FAILED="$(grep -c '^not ok' "$RESULTS" || true)"
echo
if [[ "$FAILED" -gt 0 ]]; then
    echo "board tests: $FAILED failed, $PASSED passed"
    exit 1
fi
echo "board tests: all $PASSED checks passed"
