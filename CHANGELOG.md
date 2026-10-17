# Changelog

All notable changes to this project are documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `config/horizon-observability.php` with the `HORIZON_OBSERVABILITY_*` environment variables, validated by a typed
  snapshot that replaces invalid values by defaults and reports them (security values fail closed).
- Routes `GET {horizon.path}/observability/metrics`, `/jobs/{pending|completed|failed|delayed}` and `/live`, registered
  ahead of Horizon's catch-all route in either provider order and with cached routes.
- `HorizonObservability::auth()` and `HorizonObservability::resolveBatchKindUsing()` hooks.
- The metric catalog with the default namespace `ho` (`ho_queue_jobs_total`, `ho_exporter_collector_success`, ...).

### Changed

- Rewrite of the internal `sushi-market/horizon-prometheus-grafana` pilot as the public
  `sushi-market/horizon-observability` package (namespace `DF\HorizonObservability`).
