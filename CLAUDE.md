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
  [--qa "..."] [--question "..."] [--answer "..."] [--attach shot.png]` (it commits and pushes `planning/TASKS.md`; the
  board workflow moves the card). It refuses unfinished dependencies and full WIP columns (`--force` only with a
  reason). Follow `planning/README.md` decision 14: take only `ready` tasks; one draft PR per task (`wip/Txx-<slug>`,
  `Closes #<issue>`, via MCP); implementer -> `testing`; two independent testers post COMMENT reviews starting with
  `QA verdict: APPROVED` or `QA verdict: CHANGES REQUESTED`, then `done` or back to `in progress` with a QA line;
  questions for the owner go through `needs owner --question`; visual tasks attach Grafana 12 and 13 screenshots.
- Stop the line: while an open `stop-the-line` issue exists (check via MCP), nothing moves to `done` and nothing is
  merged; make the integration branch green first.
- `tools/board/board.sh` talks to the GitHub API and runs in GitHub Actions (or on the owner's machine), not in agent
  sessions; agents use `set-status.sh` (git only) and the GitHub MCP tools. `tools/board/tests/run.sh` tests the board
  tooling offline (stub `gh`); run it after changing `tools/board/`.
- Push work to `origin` (branch `claude/hopeful-hawking-njeu3v`).
