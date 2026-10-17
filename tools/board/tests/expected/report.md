<!-- board:report -->
# Flow report

Rewritten from [`planning/TASKS.md`](https://github.com/m0nclous/board-fixture/blob/claude/integration/planning/TASKS.md) by `tools/board/board.sh report` after every board sync and CI run; edits here are overwritten.

## Integration branch

Green: no open `stop-the-line` issue.

## WIP

| Column | Tasks | WIP limit |
|---|---|---|
| Todo | 3 (1 ready, 2 blocked) |  |
| In Progress | 1 | 2 |
| Testing | 1 | 3 |
| Needs owner | 1 |  |
| Done | 2 |  |

## Waiting for the owner

- #5 T04 Queue state: 2026-10-11 08:30 UTC: Keep the 1 s server cache for the live endpoint?

## Tasks

| Task | Status | Area | QA rounds | Started | Finished | Cycle days |
|---|---|---|---|---|---|---|
| #1 T00 Bootstrap | done | process | 0 |  |  |  |
| #2 T01 Shared contracts | done | exporter | 1 | 2026-10-01 | 2026-10-03 | 2.5 |
| #3 T02 Exposition | in progress | exporter | 1 | 2026-10-09 |  |  |
| #4 T03 Attempts | testing | attempts | 0 | 2026-10-09 |  |  |
| #5 T04 Queue state | needs owner | queues | 0 | 2026-10-10 |  |  |
| #6 T05 Grafana port | todo, ready | grafana | 0 |  |  |  |
| #7 T06 Live backend | todo, blocked by T02, T03, T04, T05 | live | 0 |  |  |  |
| #8 T07 Release | todo, blocked by T02, T03, T04, T05, T06 | docs | 0 |  |  |  |

## Quality

- QA rounds: 2 (1 approved, 1 rejected), rejection rate 50 %.
- Cycle time (first `in progress` to the last `done`): average 2.5 days over 1 finished task.
