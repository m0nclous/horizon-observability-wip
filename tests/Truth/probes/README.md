# Truth probes (T16)

Evidence for the two decisions in `../README.md`, on a real Prometheus v3.15.0 (Docker, host network). They are not
part of the Pest suite; rerun them by hand when the exporter's counter semantics or the dashboard's counting
queries change.

| Probe | Question | Run | Results |
|---|---|---|---|
| `first-value/run-scripted.sh` | Does Prometheus count the first value of a new counter series, with and without the exporter's hold, and what would a dashboard-only fix count for a scrape job added later? | `tests/Truth/probes/first-value/run-scripted.sh` | `first-value/results-scripted.txt` |
| `first-value/run.sh` | The same with the real exporter of two checkouts (before and after the fix) and real workers | `tests/Truth/probes/first-value/run.sh <before checkout> <after checkout>` | `first-value/results-real.txt` |
| `pooled-queues/run.sh` | Can the queue table #5 find the supervisor, the processes and the busy workers of a balance=false pooled queue? | `tests/Truth/probes/pooled-queues/run.sh` | `pooled-queues/results.txt` |

The scripted probe serves `ho_queue_jobs_total` from `fake_exporter.py` as a function of time (mode `baseline` or
`hold`); `query.py` evaluates `increase()`, the dashboard's `delta()` (T13 `Queries\Attempts::delta()`) and a
dashboard-only "count the first value" alternative. The real probe runs `export-loop.php` (the checkout's
`Exporter::scrape()` once a second, served as a static file) and `dispatch.php` plus `testbench queue:work` per
checkout, each on its own key prefix.
