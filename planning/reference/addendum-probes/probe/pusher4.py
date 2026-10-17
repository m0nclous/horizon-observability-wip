"""Pushes measurement tiles (latest rows: tile, caption, value, color) and delayed_series (K-row window).
    python3 -I pusher4.py <grafana url> <token> <stream id> <seconds> <app url> [K=60]"""
import collections, json, sys, time, urllib.request
url, tok, stream, secs, app = sys.argv[1], sys.argv[2], sys.argv[3], int(sys.argv[4]), sys.argv[5]
K = int(sys.argv[6]) if len(sys.argv) > 6 else 60
def sval(v): return '"' + v.replace('\\', '\\\\').replace('"', '\\"') + '"'
ring = collections.deque(maxlen=K)
for i in range(secs):
    t0 = time.time(); snap = json.load(urllib.request.urlopen(app + '/live')); ns = time.time_ns()
    ring.append((ns, snap['delayed']['value']))
    p = snap['pending']
    lines = [f"tiles tile=\"pending\",caption={sval(p['caption'])},value={p['value']},color={sval(p['color'])} {ns}"]
    for s in snap['supervisors']:
        lines.append(f"tiles tile=\"supervisor\",caption={sval(s['caption'])},value={s['processes']},color={sval(s['color'])} {ns}")
    lines += [f"delayed_series value={v}i {ts}" for ts, v in ring]
    req = urllib.request.Request(f'{url}/api/live/push/{stream}', data=('\n'.join(lines) + '\n').encode(), method='POST', headers={'Authorization': f'Bearer {tok}'})
    with urllib.request.urlopen(req, timeout=5) as r:
        print(i, r.status, p['value'], p['color'], [(s['caption'], s['processes'], s['color']) for s in snap['supervisors']], snap['delayed']['value'], flush=True)
    time.sleep(max(0, 1 - (time.time() - t0)))
