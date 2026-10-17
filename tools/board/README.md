# Board sync

`planning/TASKS.md` is the source of truth for task status. `board.sh` mirrors it onto GitHub:

- one issue per `### Txx Title — \`status\`` heading, titled `[Txx] Title`, labelled `task` and `phase: <phase>`;
- the label `in progress` while the task is in progress; the issue is closed when the task is `done` and reopened if
  it goes back;
- with a board configured, every issue is on the GitHub Projects board in the matching Status column
  (`todo` → Todo, `in progress` → In Progress, `done` → Done; the default options of the Board template).

Edit the status in `planning/TASKS.md`, never on the issue: the next sync overwrites the issue.

## Setup (once)

1. Create a board: GitHub → Projects → New project → Board. Note its number (`.../projects/<number>`).
2. Create a token: a fine-grained PAT for this repository with Issues read/write and Projects read/write
   (or a classic PAT with the `repo` and `project` scopes).
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
