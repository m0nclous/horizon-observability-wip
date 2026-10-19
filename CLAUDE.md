# CLAUDE.md

`sushi-market/horizon-observability` (namespace `DF\HorizonObservability`): Prometheus metrics, live job lists, a
Grafana dashboard and alert rules for Laravel Horizon and its Redis queues.

Conventions:
- Everything is English: code, comments, log lines, HELP texts, label values and docs.
- PHP `^8.2` with `declare(strict_types=1)` in every PHP file; Laravel `^11.23 || ^12.0 || ^13.0`, Horizon `^5.29.1`.
- Gates before every commit: `vendor/bin/pest` with phpredis and again with `REDIS_CLIENT=predis`, `vendor/bin/phpstan`,
  `vendor/bin/pint --test` and `vendor/bin/rector --dry-run`. The `redis` test group needs Redis on 127.0.0.1:6379.
- The dashboard and the alert rules are rendered from `src/Grafana`; never edit `resources/grafana` by hand
  (`composer test:grafana` compares them with the render).
- Every public change needs an entry in `CHANGELOG.md` (breaking changes also in `UPGRADE.md`).
- Docs: `README.md` (users), `CONTRIBUTING.md` (development setup, test groups, CI), `tests/Truth/README.md` (the
  truth suite).
