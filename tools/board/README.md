# Board sync

`planning/TASKS.md` is the source of truth for task status. `board.sh` mirrors it onto GitHub:

- one issue per `### Txx Title — \`status\`` heading, titled `[Txx] Title`, labelled `task` and `phase: <phase>`;
- the label `in progress` or `testing` while the task has that status; the issue is closed when the task is `done`
  and reopened if it goes back;
- one comment per `QA: ...` line under the task heading (testers' verdicts), posted once;
- with a board configured, every issue is on the GitHub Projects board in the matching Status column
  (`todo` → Todo, `in progress` → In Progress, `testing` → Testing, `done` → Done).

Change statuses with `set-status.sh`, never on the issue: the next sync overwrites the issue.

```bash
tools/board/set-status.sh T12 "in progress"
tools/board/set-status.sh T12 testing
tools/board/set-status.sh T12 "in progress" --qa "round 1 rejected by the adversarial tester: cache key ignores scope"
tools/board/set-status.sh T12 done --qa "round 2 approved by the spec and adversarial testers"
```

It edits `planning/TASKS.md` in the main checkout (from any worktree, under a lock), commits only that file and pushes;
`--no-push` keeps the commit local. The QA process is described in `planning/README.md` (decision 14).

## Setup (once)

1. Create a board: GitHub → Projects → New project → Board. Note its number (`.../projects/<number>`). Add the Status
   option **Testing** between In Progress and Done.
2. Create a token: a classic PAT with the scopes `repo`, `project`, `read:org` and `read:discussion` (gh requires the
   last two for `gh project`).
3. In the repository: Settings → Secrets and variables → Actions:
   - secret `PROJECT_TOKEN` = the token;
   - variable `BOARD_NUMBER` = the board number;
   - variable `BOARD_OWNER` = the board owner, only if it differs from the repository owner.
4. Make sure Actions are enabled. Every push that changes `planning/TASKS.md` now syncs; the workflow can also be run by
   hand (Actions → board → Run workflow).

## Local use

```bash
gh auth login --scopes project          # once
tools/board/board.sh list               # parse TASKS.md, no network
tools/board/board.sh sync --dry-run     # show what would change
BOARD_OWNER=<owner> BOARD_NUMBER=<n> tools/board/board.sh sync
```
