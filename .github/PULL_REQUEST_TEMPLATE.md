## What and why

<!-- The change and the reason for it. Link the issue if there is one. -->

## Checklist

- [ ] Tests cover the change (`composer test`; Redis tests also with `composer test:predis`).
- [ ] `CHANGELOG.md` has an entry under `Unreleased` (breaking changes also in `UPGRADE.md`).
- [ ] Dashboard or alert changes go through `src/Grafana`, and `composer test:grafana` passes with the re-rendered assets.
- [ ] Visual dashboard changes: screenshots from `node bin/screenshot.mjs` on Grafana 12 and 13, before and after.
- [ ] Performance-relevant dashboard changes: a `node bin/perf.mjs report` against `docker/perf/budget.json`.
