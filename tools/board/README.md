# Board sync

`planning/TASKS.md` is the source of truth for task status. `board.sh` mirrors it onto GitHub (run by
`.github/workflows/board.yml`), `set-status.sh` is the only way to change it. The rules behind the flow are owner
decision 14 in `planning/README.md`.

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
  marker makes it idempotent); questions mention the owner (`@m0nclous`, `BOARD_MENTION`);
- for a blocked todo task, one `**Blocked** by T12, T13` comment per set of missing dependencies;
- the board card in the Status column of the table and the fields **Phase**, **Area** (from `areas.tsv`),
  **QA rounds**, **Started** (first `board: Txx -> in progress` commit) and **Finished** (last `board: Txx -> done`
  commit, only while done). Missing fields are created; the options of a single-select field (a new phase, the
  **Needs owner** column) must be added in the board settings, and the sync names any that are missing.

`board.sh report` rewrites the pinned issue **[Board] Flow report** (label `report`): the CI state of the integration
branch, WIP per column against the limits, the open owner questions, and per task the status (ready or blocked by
what), area, QA rounds, start, finish and cycle days, plus the QA rejection rate and the average cycle time.

`board.sh ci` is the stop-the-line gate (below). Every command is idempotent: a second run with nothing new makes no
write. `--dry-run` prints the writes instead of making them (with `GH_TOKEN` it reads the current state and prints
only what would change; without it, it reads nothing).

Dependencies come from the heading tail: `(depends on T02, T03–T08)`, `(depends only on T00; ...)`,
`(depends on everything)`; a task without one inherits its phase heading's `(all depend on T02; ...)`.

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
```

It edits `planning/TASKS.md` in the main checkout (from any worktree, under a lock), adds the QA, question or answer
line right under the heading (`QA: 2026-10-17 11:20 UTC: ...`), commits only that file (and the screenshots) as
`board: Txx -> <status>` and pushes. `--no-push` keeps the commit local, `--dry-run` prints the diff and the checks.

Gates (`--force` overrides them; the commit message records what was forced):

- **Dependencies:** `in progress`, `testing` and `done` need every dependency `done`.
- **WIP limits:** a task enters In Progress only while fewer than `BOARD_WIP_IN_PROGRESS` (default 2) tasks are
  there, and Testing below `BOARD_WIP_TESTING` (default 3). A QA rejection (`testing` -> `in progress`) always returns.
  Finish, test or hand over work before pulling more.
- **Needs owner** needs `--question`. The owner's answer goes in with `--answer` (as said in chat or on the issue);
  the task then returns to `in progress` (WIP permitting) or `todo`.

QA lines say `round N approved by ...` or `round N rejected by ...: findings`: the board counts a line with
"approved" or "rejected" as a QA round (rejected wins) for the QA rounds field and the rejection rate.

### Screenshots

`--attach file.png` (repeatable) copies the screenshot to `planning/screenshots/<Txx>/<YYYYMMDD-HHMM>-<name>.png`,
commits it with the status change and appends `![Txx name](https://github.com/<repo>/blob/<branch>/<path>?raw=true)` to
the QA text (or to the question without `--qa`), so the issue comment shows it. Visual tasks (the dashboard: T13,
T15, T17 and any change of `src/Grafana/**` or `resources/grafana/**`) attach screenshots from **both Grafana 12.4.13
and 13.2.4** with every verdict; testers reject a visual task without them.

## Stop the line

When the `tests` workflow fails on a push to the integration branch (`claude/hopeful-hawking-njeu3v`, variable
`INTEGRATION_BRANCH`), the board workflow opens **[CI] The integration branch is red** (label `stop-the-line`) with the
run link and comments once per further red run with the failed job names; when the branch is green again it closes
the issue with a comment. A run of an older commit than the branch head changes nothing; cancelled runs neither.

While that issue is open **nothing moves to `done`**: testers check it (MCP `search_issues`:
`repo:m0nclous/horizon-observability-wip is:issue is:open label:stop-the-line`) before posting an approval, the
integrator checks it before merging, and everyone helps to make the branch green first (fix forward or revert).

## One pull request per task

1. The implementer works on `wip/Txx-<slug>` (a worktree of the integration branch), pushes it to `origin` and opens a
   **draft PR to the integration branch** via MCP with `Closes #<issue>` in the description (the task issue; GitHub
   closes issues only from the default branch, so the sync closes it when the task is `done`). Pull requests run the
   fast CI subset (static, PHP 8.3 / L13 / phpredis, PHP 8.2 / L11 / predis prefer-lowest, Grafana 13.2.4 assets);
   pushes to `master` and `claude/**` run the full matrix.
2. Gates green and committed: mark the PR ready and `set-status.sh Txx testing`.
3. Each tester posts one **COMMENT** review (MCP `pull_request_review_write`, event `COMMENT`) that starts with
   `QA verdict: APPROVED` or `QA verdict: CHANGES REQUESTED`: all GitHub writes act as the owner's account, and
   GitHub refuses APPROVE and REQUEST_CHANGES reviews on a PR its own author opened. The combined verdict goes into
   TASKS.md with `set-status.sh ... --qa` as before.
4. The integrator merges via MCP (merge commit, like `--no-ff`) only when the task is `done`, the PR checks are green
   and no `stop-the-line` issue is open. The full matrix then runs on the integration branch.

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
5. Make sure Actions are enabled. Every push to the integration branch that changes `planning/TASKS.md` or
   `tools/board/` syncs and rewrites the report (pushes to other branches never move cards); the workflow can also be
   run by hand (Actions → board → Run workflow). The first sync creates the fields Phase, Area,
   QA rounds, Started and Finished; the first report creates and pins the flow report issue.

## Local use

These scripts call the GitHub API through `gh`; they run in GitHub Actions or on the owner's machine, not in agent
sandboxes.

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
a history written by `set-status.sh` itself) and a stub `gh` (`tests/stub/gh`) that logs every call and keeps a
fake GitHub state (`tests/fixtures/state.json`). It checks the exact gh calls, issue and board state, file edits and
commits for every feature above, including idempotency (a second run writes nothing). The `static` CI job runs it
with shellcheck. After an intended change of behaviour, `BOARD_TEST_UPDATE=1 tools/board/tests/run.sh` rewrites
`tests/expected/`; review that diff like code.
