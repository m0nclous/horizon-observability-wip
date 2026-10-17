"""Writes OpenMetrics chunk files (one per 2 h block window) for promtool tsdb create-blocks-from openmetrics.

    python3 -I backfill.py <out_dir> <end_unix> <hours> [step=15]
"""
import collections
import multiprocessing
import os
import sys

sys.path.insert(0, os.path.dirname(os.path.abspath(__file__)))
import synth  # noqa: E402

OUT, END, HOURS = sys.argv[1], int(sys.argv[2]), float(sys.argv[3])
STEP = int(sys.argv[4]) if len(sys.argv) > 4 else 15
START = END - int(HOURS * 3600)
START -= START % STEP


def chunk(window_start):
    lo = max(START, window_start)
    hi = min(END, window_start + 7200)
    if lo >= hi:
        return None
    data = collections.OrderedDict()
    t = lo - (lo % STEP) + (STEP if lo % STEP else 0)
    while t < hi:
        for name, lab, val in synth.series(t):
            if name == "horizon_info":
                continue  # written separately with 7 days of history
            data.setdefault((synth.PREFIX + name, synth.labels(lab)), []).append((t, val))
        t += STEP
    path = os.path.join(OUT, f"chunk-{window_start}.om")
    with open(path, "w") as f:
        by_family = collections.OrderedDict()
        for (name, lab), pts in data.items():
            by_family.setdefault(name, []).append((lab, pts))
        for name, rows in by_family.items():
            f.write(f"# TYPE {name} unknown\n")
            for lab, pts in rows:
                f.writelines(f"{name}{lab} {v} {ts}\n" for ts, v in pts)
        f.write("# EOF\n")
    return path


if __name__ == "__main__":
    os.makedirs(OUT, exist_ok=True)
    windows = list(range(START - START % 7200, END, 7200))
    with multiprocessing.Pool(4) as pool:
        for p in pool.imap_unordered(chunk, windows):
            print(p, flush=True)
    # horizon_info: 7 days at 60 s steps (only read with last_over_time(...[7d]))
    name = synth.PREFIX + "horizon_info"
    lab = synth.labels({"url": "https://office.example.test/horizon", "tts_url": "http://piper:5000"})
    with open(os.path.join(OUT, "info.om"), "w") as f:
        f.write(f"# TYPE {name} unknown\n")
        t = END - 7 * 86400
        t -= t % 60
        while t < END:
            f.write(f"{name}{lab} 1 {t}\n")
            t += 60
        f.write("# EOF\n")
    print("info done")
