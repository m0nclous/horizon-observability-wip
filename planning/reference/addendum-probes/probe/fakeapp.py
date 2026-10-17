"""Fake app for the probes: GET /metrics continues the synthetic history; GET /live returns a prototype
live snapshot (the addendum's schema) computed from the same model at request time.

    python3 -I fakeapp.py <port>
"""
import json
import math
import os
import sys
import time
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import synth  # noqa: E402

CALLS = {"live": 0, "metrics": 0}


def snapshot(t):
    rows = list(synth.series(t))
    get = lambda n: [(l, v) for name, l, v in rows if name == n]  # noqa: E731
    pending = {l["queue"]: v for l, v in get("queue_pending_jobs")}
    delayed = {l["queue"]: v for l, v in get("queue_delayed_jobs")}
    due = {l["queue"]: v for l, v in get("queue_delayed_due_jobs")}
    age = {l["queue"]: v for l, v in get("queue_oldest_pending_job_age")}
    reserved = {}
    for l, v in get("queue_jobs_reserved"):
        reserved[l["queue"]] = reserved.get(l["queue"], 0) + v
    procs = {}
    for l, v in get("horizon_supervisor_processes"):
        procs.setdefault(l["supervisor"], {})[l["queue"]] = v
    maxp = {l["supervisor"]: v for l, v in get("horizon_supervisor_max_processes")}
    status = [v for _, v in get("horizon_status")][0]
    sups = []
    for s, qs in procs.items():
        busy = sum(reserved.get(q, 0) for q in qs)
        util = min(1.0, busy / maxp[s]) if maxp[s] else 0
        lvl = 2 if status < 1 else (2 if util >= 0.9 else 1 if util >= 0.7 else 0)
        n = sum(qs.values()) if status >= 1 else 0
        sups.append({"name": s, "display": s.removeprefix("supervisor-"), "processes": n, "max_processes": maxp[s],
                     "busy": busy, "utilization": round(util, 4), "level": lvl,
                     "color": ["green", "yellow", "red"][lvl], "value": n + lvl / 64,
                     "caption": f"{s.removeprefix('supervisor-')} · max {maxp[s]}"})
    worst = max(pending, key=lambda q: (age[q] / synth.WAITS[q]) if pending[q] else -1)
    ratio = age[worst] / synth.WAITS[worst] if pending[worst] else 0
    lvl = 2 if ratio > 2 else 1 if ratio > 1 else 0
    a = age[worst]
    oldest = "—" if not a else (f"{a} s · {worst}" if a < 120 else f"{round(a / 60)} min · {worst}")
    rpm = sum(synth.rate_at(x[2], t) for x in synth.TUPLES) * 60 * synth.STATUS_SHARE["completed"]
    fpm = sum(synth.rate_at(x[2], t) for x in synth.TUPLES) * 60 * (synth.STATUS_SHARE["failed"] + synth.STATUS_SHARE["timed_out"])
    return {
        "v": 1, "time": int(t * 1000), "age_ms": 0, "status": status,
        "horizon": {"status": status, "level": 0 if status >= 1 else 2, "color": "green" if status >= 1 else "red"},
        "pending": {"value": sum(pending.values()), "level": lvl, "color": ["green", "yellow", "red"][lvl],
                    "caption": f"Oldest: {oldest}", "oldest_seconds": a, "oldest_queue": worst},
        "delayed": {"value": sum(delayed.values()) - sum(due.values())},
        "throughput": {"window_seconds": 60, "completed_per_minute": round(rpm), "failed_per_minute": round(fpm, 1),
                       "caption": f"RPM: {round(rpm)}"},
        "supervisors": sups,
    }


class H(BaseHTTPRequestHandler):
    def log_message(self, *a):
        pass

    def do_GET(self):
        t = time.time()
        if self.path.startswith("/metrics"):
            CALLS["metrics"] += 1
            body = synth.exposition(t).encode()
            ctype = "text/plain; version=0.0.4"
        elif self.path.startswith("/live"):
            CALLS["live"] += 1
            body = json.dumps(snapshot(t)).encode()
            ctype = "application/json"
        elif self.path.startswith("/calls"):
            body = json.dumps(CALLS).encode()
            ctype = "application/json"
        else:
            self.send_response(404)
            self.end_headers()
            return
        self.send_response(200)
        self.send_header("Content-Type", ctype)
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)


if __name__ == "__main__":
    ThreadingHTTPServer(("0.0.0.0", int(sys.argv[1])), H).serve_forever()
