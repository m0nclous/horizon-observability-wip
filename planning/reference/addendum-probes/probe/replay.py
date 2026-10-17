"""Replays the PromQL of one captured refresh cycle (measure.mjs output) directly against Prometheus.

    python3 -I replay.py <measure.json> <prometheus base url> [cycle=1] [repeat=3]

Prints one line per target: panel, refId, range/instant, interpolated size, median wall ms,
Prometheus evalTotalTime, totalQueryableSamples and peakSamples (stats=all), series returned.
Also writes <measure.json>.replay.json.
"""
import json
import statistics
import sys
import time
import urllib.parse
import urllib.error
import urllib.request

path, prom = sys.argv[1], sys.argv[2]
cycle = int(sys.argv[3]) if len(sys.argv) > 3 else 1
repeat = int(sys.argv[4]) if len(sys.argv) > 4 else 3
d = json.load(open(path))
rows = d["rows"]
# cycle boundaries as in measure.mjs
cycles, cur, last_end = [], None, -1e9
for r in rows:
    if cur is None or r["start"] > last_end + 2000:
        cur = []
        cycles.append(cur)
        last_end = r["end"]
    cur.append(r)
    last_end = max(last_end, r["end"])
sel = cycles[min(cycle, len(cycles) - 1)]


def call(endpoint, params):
    data = urllib.parse.urlencode(params).encode()
    req = urllib.request.Request(prom + endpoint, data=data, method="POST",
                                 headers={"Content-Type": "application/x-www-form-urlencoded"})
    t = time.perf_counter()
    with urllib.request.urlopen(req, timeout=120) as resp:
        body = json.load(resp)
    return (time.perf_counter() - t) * 1000, body


out = []
for r in sel:
    if not r["url"].startswith("/api/ds/query"):
        # variable query through the datasource resource API
        q = urllib.parse.parse_qs(r["body"] or "")
        if "query" not in q:
            continue
        targets = [{"refId": "var", "expr": q["query"][0], "instant": True, "time": q.get("time", [None])[0]}]
        frm = to = None
        panel = "variable"
    else:
        b = json.loads(r["body"])
        frm, to = int(b["from"]) / 1000, int(b["to"]) / 1000
        targets = b["queries"]
        panel = r["panel"]
    for q in targets:
        if "expr" not in q:
            continue
        step = max(15, round(q.get("intervalMs", 15000) / 1000))
        rng = int(round((to - frm))) if frm is not None else 3600
        expr = (q["expr"].replace("$__rate_interval", f"{4 * step}s").replace("$__interval_ms", str(step * 1000))
                .replace("${__interval}", f"{step}s").replace("$__interval", f"{step}s")
                .replace("${__range}", f"{rng}s").replace("$__range_s", str(rng)).replace("$__range", f"{rng}s"))
        if q.get("instant", not q.get("range")) and not q.get("range"):
            ep = "/api/v1/query"
            params = {"query": expr, "time": q.get("time") or to, "stats": "all"}
        else:
            ep = "/api/v1/query_range"
            params = {"query": expr, "start": frm - frm % step, "end": to - to % step, "step": step, "stats": "all"}
        walls, stats, n = [], {}, 0
        for _ in range(repeat):
            try:
                ms, body = call(ep, params)
            except urllib.error.HTTPError as e:
                ms, body = -1, {"error": e.read().decode()[:300]}
            except Exception as e:  # noqa: BLE001
                ms, body = -1, {"error": str(e)}
            walls.append(ms)
            st = (body.get("data") or {}).get("stats") or {}
            stats = st
            n = len((body.get("data") or {}).get("result") or [])
        row = {"panel": panel, "refId": q["refId"], "kind": ep.rsplit("/", 1)[1], "kb": round(len(expr) / 1024, 1),
               "wall_ms": round(statistics.median(walls)), "eval_ms": round(1000 * stats.get("timings", {}).get("evalTotalTime", 0)),
               "samples": stats.get("samples", {}).get("totalQueryableSamples"), "peak": stats.get("samples", {}).get("peakSamples"),
               "series": n, "error": body.get("error")}
        out.append(row)
        print(row, flush=True)
json.dump(out, open(path + ".replay.json", "w"), indent=1)
by_panel = {}
for row in out:
    p = by_panel.setdefault(row["panel"], [0, 0, 0])
    p[0] += row["wall_ms"]
    p[1] += row["samples"] or 0
    p[2] += 1
print("per panel (sum of target wall ms, samples, targets):")
for k, v in sorted(by_panel.items(), key=lambda kv: -kv[1][0]):
    print(f"  {k}: {v[0]} ms, {v[1]} samples, {v[2]} targets")
