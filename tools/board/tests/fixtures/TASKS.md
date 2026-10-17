# Tasks

Status values: `todo`, `in progress`, `testing`, `needs owner`, `done`.

## Phase 0: foundation

### T00 Bootstrap — `done`
Repository skeleton.

### T01 Shared contracts — `todo` (depends on T00)
Interfaces and config.

## Phase B: parallel packages (all depend on T01; T05 only on T00)

### T02 Exposition — `todo`
Exporter and access.

### T03 Attempts — `todo`
Attempt tracking.

### T04 Queue state — `todo`
Queue inspector.

### T05 Grafana port — `todo` (depends only on T00; start first)
Golden port.

## Phase C: tail

### T06 Live backend — `todo` (depends on T02–T04, T05)
Live snapshot.

### T07 Release — `todo` (depends on everything)
Docs and tag.
