# Board sync

`planning/TASKS.md` is the source of truth for task status. `board.sh` mirrors it onto GitHub (run by
`.github/workflows/board.yml`), `set-status.sh` is the only way to change it. The rules behind the flow are owner
decision 14 in `planning/README.md` and the flow rules proposed under it (pending the owner's confirmation).

| TASKS.md status | Board column | Issue label | Issue |
|---|---|---|---|
| `todo` | Todo | `ready` (every dependency done) or `blocked` | open |
| `in progress` | In Progress | `in progress` | open |
| `testing` | Testing | `testing` | open |
| `needs owner` | Needs owner | `needs owner` | open |
| `done` | Done | | closed |

## What the sync keeps

`board.sh sync` keeps, for every `### Txx Title — \`status\`` heading:

- one issue `[Txx] Title` with the labels `task`, `phase: <phase>` and the status label of the table above; labels
  that the sync owns (`phase: *`, the status labels, `ready`, `blocked`) are removed when they no longer apply, other
  labels stay;
- one comment per `QA: ...`, `Owner question: ...` and `Owner answer: ...` line of the task, posted once (a hidden
  marker makes it idempotent). Questions mention the owner (`@m0nclous`, `BOARD_MENTION`) and are posted with
  `BOARD_NOTIFY_TOKEN` (the workflow passes its `GITHUB_TOKEN`, so they come from `github-actions[bot]`): GitHub never
  notifies anyone of their own comments, and every other write uses the owner's token. Without that token (the
  owner's machine) the mention notifies nobody; the owner can enable *Settings → Notifications → Include your own
  updates* to get those by email;
- one **Blocked by T12, T13** comment per blocked task, edited in place when the missing dependencies change and
  turned into **Ready** when they are all done;
- the board card in the Status column of the table and the fields **Phase**, **Area** (from `areas.tsv`; the sync
  warns about tasks without a line), **QA rounds**, **Started** (first `board: Txx -> in progress` commit) and
  **Finished** (last `board: Txx -> done` commit, only while done; a reopened task loses it, a task back in todo
  loses Started). Missing fields are created; the options of a single-select field (a new phase, the **Needs owner**
  column) must be added in the board settings, and the sync names any that are missing. A field of the same name but
  another type, one that cannot be created and one that refuses a write are skipped with a warning, and so is a card
  that cannot be added (the next sync tries again): they cannot stop the status sync.

The board comes last: issues, labels and comments are synced first, so a board that cannot be read at all (the
token's `project` scope, a wrong number or owner) leaves them in place and fails the run with a message that says so.
In the workflow every `board: warning:` line becomes an annotation of the run (a task without an area, a heading or
clause the parser cannot read, a missing option), so it shows on the run's summary page.

`board.sh report` rewrites the pinned issue **[Board] Flow report** (label `report`): the CI state of the integration
branch, WIP per column against the limits, the open owner questions, and per task the status (ready or blocked by
what), area, QA rounds, start, finish and cycle days, plus the QA rejection rate and the average cycle time. A pin
that failed (GitHub pins at most 3 issues) is tried again on every run.

`board.sh ci` is the stop-the-line gate (below). Every command is idempotent: a second run with nothing new makes no
write. `--dry-run` prints the writes instead of making them (with `GH_TOKEN` it reads the current state and prints
only what would change; without it, it reads nothing).

Dependencies come from the heading tail: `(depends on T02, T03–T08)`, `(depends only on T00; ...)`,
`(depends on everything)`; a task without one inherits its phase heading's `(all depend on T02; ...)`, and any other
`## ` heading ends the phase. Clause words are read in any case (`(Depends on T01)`). Lists take ids (any case),
ranges with a hyphen, en or em dash (spaced or not), `..`, `to`, `through` or `thru`, and the separators `,`, `and`,
`&`, `+`, `/`; any other text in the clause up to `;` or `)` is reported as a warning (`board.sh list` shows them).
So are a `### Txx` heading that is not a task heading (a hyphen instead of the em dash, `Todo`, `in-progress`: the
task would be missing from the board and `set-status.sh` would not find it), a tail that names an id without a
`depends on` clause (`(depend on T01)`, `(after T05)`) and a dependency clause inside the title: a typo must not
silently drop a dependency or a task.

## Changing a status

```bash
tools/board/set-status.sh T12 "in progress"
tools/board/set-status.sh T12 testing
tools/board/set-status.sh T12 "in progress" --qa "round 1 rejected by the adversarial tester: cache key ignores scope"
tools/board/set-status.sh T12 done --qa "round 2 approved by the spec and adversarial testers"
tools/board/set-status.sh T12 "needs owner" --question "Should stream mode also push on Grafana 11?"
tools/board/set-status.sh T12 "in progress" --answer "No: Grafana 12.3 is the floor (decision 4)"
tools/board/set-status.sh T13 done --qa "round 1 approved by the spec and adversarial testers" \
    --attach build/screenshots/dashboard-12.4.13.png --attach build/screenshots/dashboard-13.2.4.png
tools/board/set-status.sh T12 testing --trailer "Co-Authored-By: ..." --trailer "Claude-Session: ..."
```

It works in the main checkout (from any worktree, under a lock):

1. It waits up to `BOARD_WAIT` seconds (default 300, without holding the lock) while the main checkout is in the
   middle of a merge, rebase, cherry-pick or revert, has unmerged paths, a detached HEAD or uncommitted changes of
   `planning/TASKS.md`, and refuses if that does not clear. Changes of other files stay out of its commits.
2. It refuses unless the main checkout is on the integration branch (`BOARD_INTEGRATION_BRANCH`, default
   `claude/hopeful-hawking-njeu3v`): the board reads only that branch.
3. It fetches the branch and fast-forwards the main checkout, so that the gates read the state on origin (PRs merged
   on GitHub, other sessions' status changes). Its own unpushed commits (`--no-push`) go out with the status change.
   Any other local commit that origin does not have (an integrator's merge under test, a commit with a `board:`
   subject that carries code) is refused: the push would publish it. Push such commits yourself when they are ready,
   or merge in a separate worktree (below). Local commits while origin moved too are refused as well ("integrate
   them first").
4. It applies the gates, adds the QA, question or answer line right under the heading
   (`QA: 2026-10-17 11:20 UTC: ...`), commits only that file as `board: Txx -> <status>` and pushes. When the push
   loses a race with another push, it undoes its commit, waits a random 0.1 s to 3 s and starts again from step 3
   (the gates run again on the new state), up to `BOARD_PUSH_TRIES` (default 10) attempts. Any other failure after
   the first change (a commit refused by signing, a hook or a missing identity; a push that fails; an interrupt)
   undoes the attempt too and exits non-zero: HEAD, `TASKS.md` and the screenshots go back to where they were, so
   the shared main checkout never stays dirty for the next caller.

`--no-push` works offline (no fetch, no push; the gates read the local state, the commit stays local),
`--dry-run` fetches and prints the diff and the checks (against origin's task list when the checkout is behind) and
changes nothing. Commits carry no trailers unless given: `--trailer "Key: value"` (repeatable) or `BOARD_TRAILERS`
(newline-separated lines), for example the attribution lines a session must add to its commits.

Gates (`--force --reason "why"` overrides them; the commit records what was forced and why):

- **Dependencies:** starting work (`todo` -> `in progress` or `testing`, and `needs owner` -> `in progress` or
  `testing` for a task that had not started before its question) and `done` need every dependency `done`. Moves that
  keep, continue or return work do not: a note, `in progress` -> `testing`, a QA rejection, an answer that resumes
  work (the task's last `board:` move before the question was to `in progress`, `testing` or `done`) and reopening a
  done task. A dependency that is reopened later therefore never locks its dependants in place.
- **WIP limits:** a task enters In Progress only while fewer than `BOARD_WIP_IN_PROGRESS` (default 2) tasks are
  there, and Testing below `BOARD_WIP_TESTING` (default 3). A QA rejection always returns: `testing` ->
  `in progress` with a `--qa` text that rejects (below) passes over the limit; without one it is gated like any move.
  Finish, test or hand over work before pulling more.
- **Needs owner** needs `--question`. The owner's answer goes in with `--answer` (as said in chat or on the issue);
  the task then returns to `in progress` (WIP permitting) or `todo`.
- **Branch:** the main checkout must be on the integration branch.

QA lines say `round N approved by ...` or `round N rejected by ...: findings` (the PR wording
`round N QA verdict: CHANGES REQUESTED ...` works too). The verdict is the word right after the first `round N`
(an optional `QA verdict:` between them), else right after `QA verdict:`, else the first word of the text:
`approved`, or `rejected`, `changes requested` or `not approved`. Later words do not count, so
`round 1 approved ..., no changes requested` is an approval; a parenthesis between `round N` and the verdict hides
the verdict. The board counts one QA round per distinct `round N` of a task that carries a verdict, rejected when
any line of that round rejects. The spec and the adversarial tester may write one line each for the same round;
lines without `round N` are notes and are not counted.

### Screenshots

`--attach file.png` (repeatable, PNG, at most `BOARD_ATTACH_MAX_KB`, default 1024 KiB each) copies the screenshot to
`planning/screenshots/<Txx>/<YYYYMMDD-HHMM>-<name>.png` and commits it on its own (`board: Txx screenshots`) right
before the status commit, then appends `![Txx name](https://github.com/<repo>/blob/<that commit>/<path>?raw=true)`
to the QA text (or to the question without `--qa`), so the issue comment shows it. The link names the commit, not
the branch, so it keeps working after T15 deletes `planning/`. Visual tasks (the dashboard: T13, T15, T17 and any
change of `src/Grafana/**` or `resources/grafana/**`) attach screenshots from **both Grafana 12.4.13 and 13.2.4** with
every verdict; testers reject a visual task without them. (With `--no-push`, push both commits as they are: a rebase
would change the linked commit.)

## Stop the line

When a run of the `tests` workflow on the integration branch (`claude/hopeful-hawking-njeu3v`, variable
`INTEGRATION_BRANCH`) fails, the board workflow opens **[CI] The integration branch is red** (label `stop-the-line`)
with the run link and comments once per further red run with the failed job names. Push runs, the weekly run and
manual runs all count; pull request runs have their own checks. Cancelled runs change nothing.

Each red comment records, hidden, the conclusion of every job of its run. A green run closes the issue only when
every job is green by its latest record, that is when it ran every job that is still red: a push run skips the e2e
job, so after a red weekly e2e job a green push run leaves the issue open and says once (per set of waiting jobs)
which jobs it did not run. Run the `tests` workflow by hand on the integration branch (Actions → tests → Run
workflow, or MCP `actions_run_trigger`) after the fix, or wait for the next weekly run. A green run whose jobs
cannot be listed closes the issue on its conclusion alone, with a warning.

Pushes that change only `planning/TASKS.md` and `planning/screenshots/**` (status changes) run no tests
(`paths-ignore` in `tests.yml`), so they neither cost a matrix run nor cancel the run in progress. The verdict of the
last run stands for them: `board.sh ci` accepts a run of an older commit when the commits after it change only those
paths (`BOARD_ONLY_PATHS`; the `ci` job checks out the full history), and ignores it when code changed since (the
newer run decides) or when the commit is not in the branch's history.

While that issue is open **nothing moves to `done`**: testers check it (MCP `search_issues`:
`repo:m0nclous/horizon-observability-wip is:issue is:open label:stop-the-line`) before posting an approval, the
integrator checks it before merging, and everyone helps to make the branch green first (fix forward or revert).

## One pull request per task

1. The implementer works on `wip/Txx-<slug>` (a worktree of the integration branch), pushes it to `origin` and opens a
   **draft PR to the integration branch** via MCP with `Closes #<issue>` in the description (the task issue; GitHub
   closes issues only from the default branch, so the sync closes it when the task is `done`). Pull requests run the
   fast CI subset (static, PHP 8.3 / L13 / phpredis, PHP 8.2 / L11 / predis prefer-lowest, Grafana 13.2.4 assets);
   pushes to `master` and `claude/**` run the full matrix. Task code never goes to the integration branch directly:
   only `set-status.sh` commits and `integrator:` commits do.
2. Gates green and committed: mark the PR ready and `set-status.sh Txx testing`.
3. Each tester posts one **COMMENT** review (MCP `pull_request_review_write`, event `COMMENT`) that starts with
   `QA verdict: APPROVED` or `QA verdict: CHANGES REQUESTED`: all GitHub writes act as the owner's account, and
   GitHub refuses APPROVE and REQUEST_CHANGES reviews on a PR its own author opened. The combined verdict goes into
   TASKS.md with `set-status.sh ... --qa "round N ..."` as before.
4. The integrator merges via MCP (merge commit, like `--no-ff`) only when the task is `done`, the PR checks are green
   and no `stop-the-line` issue is open. The full matrix then runs on the integration branch. The next
   `set-status.sh` call in any main checkout fast-forwards it to the merge. A local merge goes in a separate
   worktree (`git worktree add --detach ../ho-merge origin/claude/hopeful-hawking-njeu3v`, merge `--no-ff`, run the
   gates, `git push origin HEAD:claude/hopeful-hawking-njeu3v`), not in the main checkout: there it would block
   every status change until it is pushed.

## Setup (once)

1. Create a board: GitHub → Projects → New project → Board. Note its number (`.../projects/<number>`). Add the Status
   options **Testing** and **Needs owner** between In Progress and Done.
2. Create a token: a classic PAT with the scopes `repo`, `project`, `read:org` and `read:discussion` (gh requires the
   last two for `gh project`).
3. In the repository: Settings → Secrets and variables → Actions:
   - secret `PROJECT_TOKEN` = the token;
   - variable `BOARD_NUMBER` = the board number;
   - optional variables: `BOARD_OWNER` (if the board owner differs from the repository owner), `INTEGRATION_BRANCH`
     (default `claude/hopeful-hawking-njeu3v`), `BOARD_WIP_IN_PROGRESS` and `BOARD_WIP_TESTING` (the limits shown in
     the report; agents pass the same values to `set-status.sh` if they differ from 2 and 3).
4. The stop-the-line job runs on `workflow_run`, which GitHub fires only from the copy of `board.yml` on the
   **default branch**: make the integration branch the default branch (Settings → General) or copy `board.yml` there.
   Scheduled runs also run only on the default branch, so the weekly full run (with e2e) reaches the integration
   branch only when it is the default branch.
5. Make sure Actions are enabled. The sync job asks for `issues: write` for its own `GITHUB_TOKEN` (owner questions
   come from `github-actions[bot]`). Every push to the integration branch that changes `planning/TASKS.md` or
   `tools/board/` syncs and rewrites the report; the workflow can also be run by hand (Actions → board → Run
   workflow) on the integration branch. Pushes and manual runs of any other branch never move cards. The owner's
   token is given only to the `board.sh` steps, not to checkout or the workflow as a whole. The first sync creates
   the fields Phase, Area, QA rounds, Started and Finished; the first report creates and pins the flow report issue.

## Local use

`board.sh` calls the GitHub API through `gh`; it runs in GitHub Actions or on the owner's machine, not in agent
sandboxes (it needs bash 4+, gh, git, jq and awk). `set-status.sh` makes no API call; it needs bash 4+, git,
python3, `flock` (util-linux; on macOS `brew install flock`), `od` and `cmp`, and names a missing one.

```bash
gh auth login --scopes project          # once
tools/board/board.sh list               # parse TASKS.md: status, phase, area, ready or blocked; no network
tools/board/board.sh sync --dry-run     # show what would change
BOARD_OWNER=<owner> BOARD_NUMBER=<n> tools/board/board.sh sync
tools/board/board.sh report
CI_CONCLUSION=failure CI_RUN_ID=<run id> tools/board/board.sh ci --dry-run
```

## Tests

`tools/board/tests/run.sh` runs both scripts offline against temporary git clones of `tests/fixtures/TASKS.md` (with
a history written by `set-status.sh` itself, a bare origin and, where a case needs it, a second clone playing another
session) and a stub `gh` (`tests/stub/gh`) that logs every call and keeps a fake GitHub state
(`tests/fixtures/state.json`). It checks the exact gh calls, issue and board state, file edits and commits for every
feature above, including idempotency (a second run writes nothing), push races (a pre-push hook lets the other clone
push first) and the QA findings of each round. The `static` CI job runs it after shellcheck 0.11.0, pinned
(`pip install shellcheck-py==0.11.0.1` gives the same version locally; the runner's own 0.9.0 reports other checks):
`shellcheck -x tools/board/*.sh tools/board/tests/run.sh`. The real `TASKS.md` is not checked against `areas.tsv`
there (a `TASKS.md` change runs no tests, so a missing line would turn the next unrelated push red); the board run
warns instead. After an intended change of behaviour, `BOARD_TEST_UPDATE=1 tools/board/tests/run.sh` rewrites
`tests/expected/`; review that diff like code.
