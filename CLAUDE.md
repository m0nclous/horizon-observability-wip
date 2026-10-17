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
- Change task statuses only with `tools/board/set-status.sh <Txx> <todo|in progress|testing|done> [--qa "..."]` (it
  commits and pushes `planning/TASKS.md`; the board workflow moves the card). Follow the QA gate in `planning/README.md`
  decision 14: implementer -> `testing`, two independent testers -> `done` or back to `in progress` with a QA verdict.
- Push work to `origin` (branch `claude/hopeful-hawking-njeu3v`).
