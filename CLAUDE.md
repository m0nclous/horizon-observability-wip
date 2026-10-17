# CLAUDE.md

This repository is being rewritten from an internal pilot into the public package `sushi-market/horizon-observability`
(namespace `DF\HorizonObservability`). Before doing anything, read `planning/README.md` (handoff, owner decisions,
environment) and `planning/TASKS.md` (task list and progress). Continue with the next open task there.

Conventions:
- Everything public is English. PHP `^8.2`, `declare(strict_types=1)` everywhere, Laravel `^11.23|^12|^13`, Horizon `^5.22`.
- `legacy/` is reference code only. Do not autoload it or extend it.
- Run `vendor/bin/pest`, `vendor/bin/phpstan`, `vendor/bin/pint --test` before every commit. Tests needing Redis use
  127.0.0.1:6379 and the `redis` group; run them with phpredis and with `REDIS_CLIENT=predis`.
- Until `src/` contains PHP files, `vendor/bin/phpstan` exits 1 with "No files found to analyse"; that is expected.
- Update `planning/TASKS.md` statuses in the same commit as the work, then push to `origin` (branch `claude/hopeful-hawking-njeu3v`).
