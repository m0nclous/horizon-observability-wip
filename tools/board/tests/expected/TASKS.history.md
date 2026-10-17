# Tasks

Status values: `todo`, `in progress`, `testing`, `needs owner`, `done`.

## Phase 0: foundation

### T00 Bootstrap — `done`
Repository skeleton.

### T01 Shared contracts — `done` (depends on T00)
QA: 2026-10-03 21:00 UTC: round 1 approved by the spec and adversarial testers
Interfaces and config.

## Phase B: parallel packages (all depend on T01; T05 only on T00)

### T02 Exposition — `in progress`
QA: 2026-10-10 09:00 UTC: round 1 rejected by the adversarial tester: the cache key ignores the scope
Exporter and access.

### T03 Attempts — `testing`
Attempt tracking.

### T04 Queue state — `needs owner`
Owner question: 2026-10-11 08:30 UTC: Keep the 1 s server cache for the live endpoint?
Queue inspector.

### T05 Grafana port — `todo` (depends only on T00; start first)
Golden port.

## Phase C: tail

### T06 Live backend — `todo` (depends on T02–T04, T05)
Live snapshot.

### T07 Release — `todo` (depends on everything)
Docs and tag.
