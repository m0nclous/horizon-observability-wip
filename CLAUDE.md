# CLAUDE.md

This repository is being rewritten from an internal pilot into the public package `sushi-market/horizon-observability`
(namespace `DF\HorizonObservability`). Before doing anything, read `planning/README.md` (handoff, owner decisions,
environment) and `planning/TASKS.md` (task list and progress). Continue with the next open task there.

Conventions:
- Everything public is English. PHP `^8.2`, `declare(strict_types=1)` everywhere, Laravel `^11.23|^12|^13`, Horizon `^5.29.1`.
- `legacy/` is reference code only. Do not autoload it or extend it.
- Run `vendor/bin/pest`, `vendor/bin/phpstan`, `vendor/bin/pint --test` before every commit. Tests needing Redis use
  127.0.0.1:6379 and the `redis` group; run them with phpredis and with `REDIS_CLIENT=predis`.
- Until `src/` contains PHP files, `vendor/bin/phpstan` exits 1 with "No files found to analyse"; that is expected.
- Change task statuses only with `tools/board/set-status.sh <Txx> <todo|in progress|testing|needs owner|done>
  [--qa "..."] [--question "..."] [--answer "..."] [--attach shot.png] [--trailer "Key: value"]` (it fast-forwards the
  main checkout to origin, commits `planning/TASKS.md` and pushes; the board workflow moves the card; add your
  session's commit attribution with `--trailer`). It refuses unfinished dependencies when work starts or finishes,
  full WIP columns, another branch, a merge or rebase in progress, uncommitted `TASKS.md` edits and unpushed commits
  that are not status changes (`--force --reason "why"` overrides only the gates). A failed commit or push undoes
  itself. It needs git, python3, flock, od and cmp.
- Follow `planning/README.md` decision 14 and its flow rules (proposed on 2026-10-17, pending the owner's
  confirmation; the tooling enforces them meanwhile, do not cite them as owner decisions): take only `ready` tasks;
  one draft PR per task (`wip/Txx-<slug>`, `Closes #<issue>`, via MCP); implementer -> `testing`; two independent
  testers post COMMENT reviews starting with `QA verdict: APPROVED` or `QA verdict: CHANGES REQUESTED`, then `done` or
  back to `in progress` with `--qa "round N ..."`; questions for the owner go through `needs owner --question`;
  visual tasks attach Grafana 12 and 13 screenshots.
- Stop the line: while an open `stop-the-line` issue exists (check via MCP), nothing moves to `done` and nothing is
  merged; make the integration branch green first. A green push run does not clear a red weekly e2e job: after the
  fix, run the `tests` workflow by hand on the integration branch (MCP `actions_run_trigger`).
- `tools/board/board.sh` talks to the GitHub API and runs in GitHub Actions (or on the owner's machine), not in agent
  sessions, except `tools/board/board.sh list`, which only parses `TASKS.md` (offline, no token) and shows the
  `[ready]` tasks; agents use it, `set-status.sh` (git, no API call) and the GitHub MCP tools.
  `tools/board/tests/run.sh` tests the board tooling offline (stub `gh`); run it after changing `tools/board/`.
- Push task code to its task branch `wip/Txx-<slug>` on `origin` and open a draft PR to `claude/hopeful-hawking-njeu3v`;
  only `set-status.sh` commits and `integrator:` commits go to `claude/hopeful-hawking-njeu3v` directly. The integrator
  merges (MCP merge commit, or `--no-ff` locally in a separate worktree, never in the main checkout) only when the
  task is `done`, the PR checks are green and no `stop-the-line` issue is open.
