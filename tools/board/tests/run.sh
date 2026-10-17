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
unset CI_CONCLUSION CI_RUN_ID CI_RUN_ATTEMPT CI_RUN_URL CI_HEAD_SHA
export BOARD_REPO="m0nclous/board-fixture" BOARD_OWNER="m0nclous" BOARD_NUMBER=5 BOARD_AREAS_FILE="$FIXTURES/areas.tsv"

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
    check "QA commit message" "board: T02 -> in progress (QA)

QA: round 1 rejected by the adversarial tester: the cache key ignores the scope

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_016iQqPzUbzkWUQnPmE7dxcs" "$(git -C "$REPO" log -1 --format=%B HEAD~2)"
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
T07  todo         phase:C  -           Release [blocked by T02, T03, T04, T05, T06]" "$(board list)"
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
    check "T07 keeps one comment per missing set" "  comment: **Blocked** by T02, T03, T04, T05, T06: this task becomes ready when they are done.\\n\\n<!-- blocked:T02,T03,T04,T05,T06 -->
  comment: **Blocked** by T06: this task becomes ready when they are done.\\n\\n<!-- blocked:T06 -->" \
        "$(comments_of_issue 8)"
    check "labels of T06 and T07" "gh issue edit 7 --repo m0nclous/board-fixture --add-label ready --remove-label blocked" "$(writes | grep '^gh issue edit [78] ')"
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
card #8 Status=Todo; QA rounds=0" "$(summary | grep -E '^card #(5|7|8) ')"
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
    head="$(git -C "$REPO" rev-parse HEAD)"
    short="${head:0:7}"
    ci failure 900 "$head" >"$OUT" 2>"$ERR"
    check_file "red: gh calls" "ci-red.calls" "$(calls | sed "s/$short/<head>/g")"
    check "red: issue body" "<!-- board:stop-the-line -->
The \`tests\` workflow is red on the integration branch \`$BRANCH\` since [tests run 900](https://github.com/m0nclous/board-fixture/actions/runs/900) (attempt 1) at \`$short\` on \`$BRANCH\`.

**Stop the line.** While this issue is open nothing moves to \`done\`: testers do not post \`QA verdict: APPROVED\`, nobody runs \`set-status.sh <Txx> done\` and the integrator merges no task branch. Make the branch green first (fix forward or revert the breaking merge). The board workflow comments every further red run here and closes this issue when the \`tests\` workflow is green again." \
        "$(jq -r '.issues[] | select(.number == 1) | .body' "$GH_STUB_STATE")"

    reset_log
    ci failure 900 "$head" >"$OUT" 2>"$ERR"
    check "the same run again: reads only" "gh label list --repo m0nclous/board-fixture --limit 1000 --json name,color,description --jq '.[] | [.name, (.color | ascii_downcase), .description] | @tsv'
gh issue list --repo m0nclous/board-fixture --state open --label stop-the-line --limit 100 --json number --jq '.[].number'
gh issue view 1 --repo m0nclous/board-fixture --json comments --jq '.comments[].body'" "$(calls)"
    check "the same run again: output" "ci      tests run 900 (attempt 1) is already on #1" "$(cat "$OUT")"

    reset_log
    ci failure 901 "$head" >"$OUT" 2>"$ERR"
    check "another red run: one comment" "gh issue comment 1 --repo m0nclous/board-fixture --body '**Red:** [tests run 901](https://github.com/m0nclous/board-fixture/actions/runs/901) (attempt 1) at \`<head>\` on \`$BRANCH\`.\\n\\nFailed jobs:\\n- Coverage (pcov, minimum 85 %)\\n\\n<!-- ci-run:901-1 -->'" \
        "$(writes | sed "s/$short/<head>/g")"

    reset_log
    ci success 902 "$(git -C "$REPO" rev-parse HEAD~1)" >"$OUT" 2>"$ERR"
    check "green run of an older commit: no call" "" "$(calls)"
    check_contains "green run of an older commit: output" "ci      ignoring tests run 902 of" "$(cat "$OUT")"

    ci cancelled 903 "$head" >"$OUT" 2>"$ERR"
    check "cancelled run: no call" "" "$(calls)"
    check "cancelled run: output" "ci      tests run 903 concluded \"cancelled\": the line stays as it is" "$(cat "$OUT")"

    ci success 902 "$head" >"$OUT" 2>"$ERR"
    check "green: close with a comment" "gh issue list --repo m0nclous/board-fixture --state open --label stop-the-line --limit 100 --json number --jq '.[].number'
gh issue close 1 --repo m0nclous/board-fixture --reason completed --comment '**Green again:** [tests run 902](https://github.com/m0nclous/board-fixture/actions/runs/902) (attempt 1) at \`<head>\` on \`$BRANCH\`. The line moves again.\\n\\n<!-- ci-green:902-1 -->'" \
        "$(calls | sed "s/$short/<head>/g")"

    reset_log
    ci success 902 "$head" >"$OUT" 2>"$ERR"
    check "green again: reads only" "gh issue list --repo m0nclous/board-fixture --state open --label stop-the-line --limit 100 --json number --jq '.[].number'" "$(calls)"
    check "green again: output" "ci      the line is clear" "$(cat "$OUT")"
    check "final state" "label stop-the-line #b60205 The integration branch is red: nothing moves to done
issue #1 CLOSED [CI] The integration branch is red [stop-the-line]
  comment: **Red:** [tests run 900](https://github.com/m0nclous/board-fixture/actions/runs/900) (attempt 1) at \`<head>\` on \`$BRANCH\`.\\n\\nFailed jobs:\\n- PHP 8.2 / L^11.23 / prefer-lowest / predis / redis:6.2-alpine\\n- Dist archive contents\\n\\n<!-- ci-run:900-1 -->
  comment: **Red:** [tests run 901](https://github.com/m0nclous/board-fixture/actions/runs/901) (attempt 1) at \`<head>\` on \`$BRANCH\`.\\n\\nFailed jobs:\\n- Coverage (pcov, minimum 85 %)\\n\\n<!-- ci-run:901-1 -->
  comment: **Green again:** [tests run 902](https://github.com/m0nclous/board-fixture/actions/runs/902) (attempt 1) at \`<head>\` on \`$BRANCH\`. The line moves again.\\n\\n<!-- ci-green:902-1 -->" \
        "$(summary | grep -E '^(label stop-the-line|issue #1 |  comment)' | sed "s/$short/<head>/g")"

    reset_log
    ci failure 904 "$head" >"$OUT" 2>"$ERR"
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
    check "message" "set-status: T06 depends on T02 (in progress), T03 (testing), T04 (needs owner), T05 (todo), not done yet; --force overrides" "$(cat "$ERR")"
    check "nothing changed" "$before" "$(tasks_sum)"
    rc=0
    status "2026-10-12 09:00" T07 "done" >"$OUT" 2>"$ERR" || rc=$?
    check_status "done is gated too" 1 "$rc"

    status "2026-10-12 09:00" T06 "in progress" --force >"$OUT" 2>"$ERR"
    check "forced: warning" "set-status: --force: T06 depends on T02 (in progress), T03 (testing), T04 (needs owner), T05 (todo), not done yet" "$(cat "$ERR")"
    check "forced: heading" "### T06 Live backend — \`in progress\` (depends on T02–T04, T05)" "$(grep '^### T06 ' "$REPO/planning/TASKS.md")"
    check "forced: commit message" "board: T06 -> in progress

Forced: T06 depends on T02 (in progress), T03 (testing), T04 (needs owner), T05 (todo), not done yet

Co-Authored-By: Claude Opus 5.5 <noreply@anthropic.com>
Claude-Session: https://claude.ai/code/session_016iQqPzUbzkWUQnPmE7dxcs" "$(git -C "$REPO" log -1 --format=%B)"

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
    check "In Progress full: message" "set-status: the WIP limit of in progress is 2 and it holds T02, T05; finish or hand over a task first; --force overrides" "$(cat "$ERR")"
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
    check "Testing full: message" "set-status: the WIP limit of testing is 1 and it holds T03; finish or hand over a task first; --force overrides" "$(cat "$ERR")"
    BOARD_WIP_TESTING=1 status "2026-10-12 13:00" T02 testing --force >"$OUT" 2>"$ERR"
    check "Testing full: forced" "set-status: --force: the WIP limit of testing is 1 and it holds T03; finish or hand over a task first" "$(cat "$ERR")"
}

CASES+=("set-status needs owner and notes")
case_19() {
    new_world
    rc=0
    status "2026-10-12 09:00" T05 "needs owner" >"$OUT" 2>"$ERR" || rc=$?
    check_status "needs owner without a question" 1 "$rc"
    check "needs owner without a question: message" "set-status: needs owner needs --question \"...\" (the owner sees it as an issue comment); --force overrides" "$(cat "$ERR")"
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
    check "a dry run applies the gates" "1 set-status: T06 depends on T02 (in progress), T03 (testing), T04 (needs owner), T05 (todo), not done yet; --force overrides" "$rc $(cat "$ERR")"
}

# --- set-status.sh --attach -----------------------------------------------------------------------------------------

CASES+=("set-status screenshots")
case_21() {
    new_world
    cp "$FIXTURES/shot.png" "$WORK/world/grafana 13.png"
    status "2026-10-12 11:00" T03 "done" --qa "round 1 approved by the spec and adversarial testers" \
        --attach "$FIXTURES/shot.png" --attach "$WORK/world/grafana 13.png" >"$OUT" 2>"$ERR"
    link="https://github.com/m0nclous/board-fixture/blob/$BRANCH/planning/screenshots/T03"
    check "QA line with links" "QA: 2026-10-12 11:00 UTC: round 1 approved by the spec and adversarial testers ![T03 shot]($link/20261012-1100-shot.png?raw=true) ![T03 grafana-13]($link/20261012-1100-grafana-13.png?raw=true)" \
        "$(grep -A1 '^### T03 ' "$REPO/planning/TASKS.md" | tail -n 1)"
    check "files in the commit" "planning/TASKS.md
planning/screenshots/T03/20261012-1100-grafana-13.png
planning/screenshots/T03/20261012-1100-shot.png" "$(git -C "$REPO" show --format= --name-only HEAD | sort)"
    check "copies are identical" "same same" "$(cmp -s "$FIXTURES/shot.png" "$REPO/planning/screenshots/T03/20261012-1100-shot.png" && echo same) $(cmp -s "$FIXTURES/shot.png" "$REPO/planning/screenshots/T03/20261012-1100-grafana-13.png" && echo same)"
    check_contains "commit message lists them" "Screenshots: planning/screenshots/T03/20261012-1100-shot.png planning/screenshots/T03/20261012-1100-grafana-13.png" "$(git -C "$REPO" log -1 --format=%B)"
    check "working tree clean" "" "$(git -C "$REPO" status --porcelain)"
    board sync >/dev/null 2>&1
    check_contains "the issue comment shows them" "**QA:** 2026-10-12 11:00 UTC: round 1 approved by the spec and adversarial testers ![T03 shot]($link/20261012-1100-shot.png?raw=true) ![T03 grafana-13]($link/20261012-1100-grafana-13.png?raw=true)" "$(comments_of_issue 4)"

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
    printf 'other' | cat "$FIXTURES/shot.png" - >"$WORK/world/shot.png"
    rc=0
    status "2026-10-12 11:00" T03 "done" --qa "again" --attach "$WORK/world/shot.png" >"$OUT" 2>"$ERR" || rc=$?
    check "an existing name with other content" "1 set-status: planning/screenshots/T03/20261012-1100-shot.png already exists with other content" "$rc $(cat "$ERR")"
    check "refusals change nothing" "$before" "$(tasks_sum)"

    status "2026-10-12 12:00" T04 "needs owner" --question "Does this look right?" --attach "$FIXTURES/shot.png" >/dev/null
    check "linked from the question" "Owner question: 2026-10-12 12:00 UTC: Does this look right? ![T04 shot](https://github.com/m0nclous/board-fixture/blob/$BRANCH/planning/screenshots/T04/20261012-1200-shot.png?raw=true)" \
        "$(grep -A1 '^### T04 ' "$REPO/planning/TASKS.md" | tail -n 1)"

    git -C "$REPO" remote set-url origin https://github.com/acme/demo.git
    (unset BOARD_REPO && status "2026-10-12 13:00" T05 todo --qa "look" --attach "$FIXTURES/shot.png" --no-push >/dev/null)
    check "owner/repo from origin" "QA: 2026-10-12 13:00 UTC: look ![T05 shot](https://github.com/acme/demo/blob/$BRANCH/planning/screenshots/T05/20261012-1300-shot.png?raw=true)" \
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

# --- Adversarial QA, round 1: findings (these fail until the scripts are fixed) ---------------------------------------

CASES+=("qa1 finding: rebase or cherry-pick stopped on a conflict")
case_28() {
    new_world
    conflicting_side
    git -C "$REPO" rebase -q "$BRANCH" >/dev/null 2>&1 || true
    before="$(tasks_sum)"
    rc=0
    PATH="$WORK/fastbin:$PATH" status "2026-10-12 09:00" T05 "in progress" --no-push >"$OUT" 2>"$ERR" || rc=$?
    check_status "a stopped rebase: refused" 1 "$rc"
    check "a stopped rebase: no commit, no edit (no conflict markers committed)" "$before" "$(tasks_sum)"

    new_world
    conflicting_side
    git -C "$REPO" checkout -q "$BRANCH"
    git -C "$REPO" cherry-pick side >/dev/null 2>&1 || true
    before="$(tasks_sum)"
    rc=0
    PATH="$WORK/fastbin:$PATH" status "2026-10-12 09:00" T05 "in progress" --no-push >"$OUT" 2>"$ERR" || rc=$?
    check_status "a stopped cherry-pick: refused" 1 "$rc"
    check "a stopped cherry-pick: TASKS.md left as it was" "$before" "$(tasks_sum)"
}

CASES+=("qa1 finding: uncommitted TASKS.md edits stay out of the status commit")
case_29() {
    new_world
    sed -i 's/^Golden port\./Golden port. HALF-WRITTEN EDIT OF THE INTEGRATOR/' "$REPO/planning/TASKS.md"
    status "2026-10-12 09:00" T05 "in progress" --no-push >"$OUT" 2>"$ERR" || true
    check "the status commit does not carry the edit" "" "$(git -C "$REPO" show HEAD -- planning/TASKS.md | grep 'HALF-WRITTEN' || true)"
    check_contains "the edit is still in the working tree" "HALF-WRITTEN EDIT OF THE INTEGRATOR" "$(cat "$REPO/planning/TASKS.md")"
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
    check "no unpushable local commit left behind" "$local_head" "$(git -C "$REPO" rev-parse HEAD)"
}

CASES+=("qa1 finding: testing -> in progress without a QA line")
case_31() {
    new_world
    status "2026-10-12 09:00" T05 "in progress" >/dev/null
    rc=0
    status "2026-10-12 09:10" T03 "in progress" >"$OUT" 2>"$ERR" || rc=$?
    check_status "In Progress full (T02, T05) and no QA rejection recorded: refused" 1 "$rc"
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
