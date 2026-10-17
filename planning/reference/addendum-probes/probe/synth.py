"""Deterministic synthetic model of the legacy office_* metrics (what the current queues.json reads).

Every value is a pure function of the Unix time t, so the backfill generator and the live fake
exporter produce one continuous history. Scale ("medium production" profile):
  4 supervisors, 12 queues, 160 job classes (20 of them on a second queue -> 180 queue/class tuples),
  16 duration buckets, 8 batch kinds with a new batch every 10 minutes per kind.
"""
import hashlib
import math

PREFIX = "office_"
JOB = "office-horizon"
INSTANCE = "app:8080"
CONN = "redis"
DAY = 86400.0

SUPERVISORS = {
    "supervisor-default": (["default", "notifications", "emails"], 1, 12, "auto"),
    "supervisor-slow": (["slow", "reports", "exports"], 1, 6, "auto"),
    "supervisor-high": (["high", "payments"], 2, 10, "simple"),
    "supervisor-sync": (["sync", "imports", "webhooks", "crm"], 1, 8, "auto"),
}
QUEUES = [q for qs, *_ in SUPERVISORS.values() for q in qs]
WAITS = {"default": 60, "notifications": 60, "emails": 120, "slow": 300, "reports": 600, "exports": 600,
         "high": 10, "payments": 10, "sync": 60, "imports": 300, "webhooks": 30, "crm": 120}
BOUNDS = [0.05, 0.1, 0.25, 0.5, 1, 2.5, 5, 10, 30, 60, 120, 300, 600, 1800, 3600]
STATUSES = ["completed", "released", "retried", "failed", "timed_out"]
STATUS_SHARE = {"completed": 0.9825, "released": 0.002, "retried": 0.01, "failed": 0.005, "timed_out": 0.0005}
DOMAINS = ["Orders", "Payments", "Crm", "Mail", "Reports", "Exports", "Imports", "Catalog", "Delivery", "Loyalty"]
VERBS = ["Sync", "Send", "Build", "Process", "Import", "Export", "Recalculate", "Notify", "Refresh", "Archive",
         "Validate", "Dispatch", "Index", "Cleanup", "Charge", "Render"]
BATCH_KINDS = ["import_catalog", "export_orders", "recalc_loyalty", "send_campaign", "sync_crm",
               "reindex_search", "build_reports", "cleanup_media"]


def h(*parts):
    return int(hashlib.md5("|".join(map(str, parts)).encode()).hexdigest()[:12], 16)


def u(*parts):
    return h(*parts) / float(16 ** 12)


CLASSES = []
for i in range(160):
    d = DOMAINS[i % len(DOMAINS)]
    v = VERBS[(i * 7) % len(VERBS)]
    CLASSES.append(f"App\\Jobs\\{d}\\{v}{d}Job{i:03d}")

TUPLES = []  # (queue, job_class, base rate per second, mean duration)
for i, c in enumerate(CLASSES):
    q = QUEUES[h("q", c) % len(QUEUES)]
    rate = 0.0005 + (u("r", c) ** 3) * 0.6  # heavy-tailed: a few busy classes
    mean = 0.05 + (u("d", c) ** 2) * (40 if q in ("slow", "reports", "exports", "imports") else 4)
    TUPLES.append((q, c, rate, mean))
    if i % 8 == 0:
        q2 = QUEUES[(QUEUES.index(q) + 1) % len(QUEUES)]
        TUPLES.append((q2, c, rate / 4, mean))


def diurnal_integral(a, t):
    """Integral of a*(1 + 0.6*sin(2*pi*t/DAY)) from 0 to t."""
    return a * (t - 0.6 * DAY / (2 * math.pi) * (math.cos(2 * math.pi * t / DAY) - 1))


def rate_at(a, t):
    return a * (1 + 0.6 * math.sin(2 * math.pi * t / DAY))


def lognorm_cdf(x, mean):
    sigma = 0.8
    mu = math.log(mean) - sigma * sigma / 2
    return 0.5 * (1 + math.erf((math.log(x) - mu) / (sigma * math.sqrt(2))))


def esc(v):
    return str(v).replace("\\", "\\\\").replace('"', '\\"')


def labels(d):
    d = dict(d)
    d["job"] = JOB
    d["instance"] = INSTANCE
    return "{" + ",".join(f'{k}="{esc(v)}"' for k, v in d.items()) + "}"


def spike(t, period, width, key):
    phase = (t + u("phase", key) * period) % period
    return max(0.0, 1 - phase / width) if phase < width else 0.0


def series(t):
    """Yields (name, labels-dict, value) for every live series at time t."""
    yield "horizon_status", {}, 1 if (t % (6 * 3600)) > 120 else 0
    for sup, (qs, mn, mx, bal) in SUPERVISORS.items():
        yield "horizon_supervisor_min_processes", {"supervisor": sup}, mn
        yield "horizon_supervisor_max_processes", {"supervisor": sup}, mx
        yield "horizon_supervisor_info", {"supervisor": sup, "balance": bal, "connection": CONN}, 1
        for q in qs:
            busy = 1 + int((mx - 1) * (0.5 + 0.5 * math.sin(t / 900 + u("p", q) * 6)) * (len(q) % 3 + 1) / 3)
            procs = max(mn, min(mx, busy))
            yield "horizon_supervisor_processes", {"supervisor": sup, "queue": q}, procs
            yield "horizon_current_processes", {"queue": q}, procs
    yield "horizon_info", {"url": "https://office.example.test/horizon", "tts_url": "http://piper:5000"}, 1
    for q in QUEUES:
        yield "horizon_wait_threshold_seconds", {"connection": CONN, "queue": q}, WAITS[q]
        p = int(40 * spike(t, 1800, 300, q) + 3 * (0.5 + 0.5 * math.sin(t / 300 + h(q) % 7)))
        yield "queue_pending_jobs", {"connection": CONN, "queue": q}, p
        dl = int(5 + 20 * spike(t, 3600, 900, "d" + q))
        yield "queue_delayed_jobs", {"connection": CONN, "queue": q}, dl
        yield "queue_delayed_due_jobs", {"connection": CONN, "queue": q}, int(dl * 0.1)
        yield "queue_oldest_pending_job_age", {"connection": CONN, "queue": q}, (int(WAITS[q] * 1.5 * spike(t, 1800, 300, q)) if p else 0)
        for bi in range(16):
            le = "+Inf" if bi == 15 else (str(BOUNDS[bi]) if BOUNDS[bi] % 1 else str(int(BOUNDS[bi])))
            total = diurnal_integral(0.4, t)
            share = 1.0 if bi == 15 else min(1.0, lognorm_cdf(BOUNDS[bi], 2.0 + (h(q) % 5)))
            yield "queue_job_wait_seconds_bucket", {"connection": CONN, "queue": q, "le": le}, int(total * share)
        yield "queue_job_wait_seconds_sum", {"connection": CONN, "queue": q}, round(diurnal_integral(0.4, t) * 3.1, 3)
        yield "queue_job_wait_seconds_count", {"connection": CONN, "queue": q}, int(diurnal_integral(0.4, t))
    for c in CLASSES:
        yield "queue_job_classes", {"job_class": c}, 1
    for q, c, a, mean in TUPLES:
        base = {"connection": CONN, "queue": q, "job_class": c}
        total = diurnal_integral(a, t)
        for s in STATUSES:
            yield "queue_jobs_total", {**base, "status": s}, int(total * STATUS_SHARE[s])
        cnt = int(total * (1 - STATUS_SHARE["released"]))
        for bi in range(16):
            le = "+Inf" if bi == 15 else (str(BOUNDS[bi]) if BOUNDS[bi] % 1 else str(int(BOUNDS[bi])))
            share = 1.0 if bi == 15 else lognorm_cdf(BOUNDS[bi], mean)
            yield "queue_job_duration_seconds_bucket", {**base, "le": le}, int(cnt * share)
        yield "queue_job_duration_seconds_sum", base, round(cnt * mean, 3)
        yield "queue_job_duration_seconds_count", base, cnt
        if rate_at(a, t) > 0.02:  # min/max exist only while the class ran in the last minute
            yield "queue_job_duration_min_seconds", base, round(mean * 0.3, 3)
            yield "queue_job_duration_max_seconds", base, round(mean * 3.2, 3)
        r = rate_at(a, t) * mean
        if r > 0.3:
            yield "queue_jobs_reserved", base, max(1, int(r))
        pend = int(r * 3 * spike(t, 1800, 300, q))
        if pend > 0:
            yield "queue_jobs_pending", base, pend
    # batches: a new batch of each kind every 10 minutes, running 6 minutes, exported 1 h after close
    for k, kind in enumerate(BATCH_KINDS):
        q = QUEUES[k % len(QUEUES)]
        yield "queue_batch_info", {"batch": kind, "queue": q, "connection": CONN}, 1
        for c in [x[1] for x in TUPLES if x[0] == q][:3]:
            yield "queue_batch_job_classes", {"batch": kind, "queue": q, "job_class": c}, 1
        start0 = int(t // 600) * 600 + (k * 37) % 600
        running = 0
        oldest = 0
        jobs = {"total": 0, "pending": 0, "failed": 0}
        for n in range(0, 8):
            created = start0 - n * 600
            if created > t:
                continue
            closed = created + 360
            if closed < t - 3600:
                break
            bid = f"{h('b', kind, created):012x}-batch"
            name = f"{kind}_{created}"
            total = 200 + h("n", bid) % 800
            done = total if t >= closed else int(total * (t - created) / 360)
            failed = (h("f", bid) % 3) if done else 0
            status = "Running" if t < closed else ("Failures" if failed else "Finished")
            for st, v in (("total", total), ("pending", total - done + failed), ("failed", failed)):
                yield "queue_batch_job_counts", {"batch": kind, "name": name, "state": st}, v
            yield "queue_batch_created_timestamp_seconds", {"batch": kind, "name": name}, created
            if t >= closed:
                yield "queue_batch_closed_timestamp_seconds", {"batch": kind, "name": name}, closed
            else:
                running += 1
                oldest = max(oldest, t - created)
                jobs["total"] += total
                jobs["pending"] += total - done + failed
                jobs["failed"] += failed
            yield "queue_batch_progress", {"batch": kind, "name": name, "status": status, "size": str(total),
                                           "created": str(created), "id": bid}, int(100 * (done) / total)
        yield "queue_batches_running", {"batch": kind}, running
        for st, v in jobs.items():
            yield "queue_batch_jobs", {"batch": kind, "state": st}, v
        if running:
            yield "queue_batch_oldest_age_seconds", {"batch": kind}, int(oldest)


def exposition(t):
    out = []
    for name, lab, val in series(t):
        body = ",".join(f'{k}="{esc(v)}"' for k, v in lab.items())
        out.append(f"{PREFIX}{name}{{{body}}} {val}")
    return "\n".join(out) + "\n"
