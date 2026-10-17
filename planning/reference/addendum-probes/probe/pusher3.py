"""Pushes windowed series + meta rows (see live_dashboard3.py) every second.
    python3 -I pusher3.py <grafana url> <token> <stream id> <seconds> <app url> [K=60]"""
import collections, json, sys, time, urllib.request
url, tok, stream, secs, app = sys.argv[1], sys.argv[2], sys.argv[3], int(sys.argv[4]), sys.argv[5]
K = int(sys.argv[6]) if len(sys.argv) > 6 else 60
def tag(v): return v.replace('\\', '\\\\').replace(',', '\\,').replace('=', '\\=').replace(' ', '\\ ')
def sval(v): return '"' + v.replace('\\', '\\\\').replace('"', '\\"') + '"'
ring = collections.deque(maxlen=K)   # (ns, snapshot)
for i in range(secs):
    t0 = time.time()
    snap = json.load(urllib.request.urlopen(app + '/live'))
    ns = time.time_ns()
    ring.append((ns, snap))
    lines = []
    for ts, s in ring:
        lines.append(f"pending_series value={s['pending']['value']}i {ts}")
        for sv in s['supervisors']:
            lines.append(f"sup_series,supervisor={tag(sv['name'])},caption={tag(sv['caption'])} value={sv['value']} {ts}")
    p = snap['pending']
    lines.append(f"pending_meta kind=\"config\",caption={sval(p['caption'])},color={sval(p['color'])} {ns}")
    for sv in snap['supervisors']:
        lines.append(f"sup_meta kind=\"config\",value={sv['value']},color={sval(sv['color'])} {ns}")
    body = ('\n'.join(lines) + '\n').encode()
    req = urllib.request.Request(f'{url}/api/live/push/{stream}', data=body, method='POST', headers={'Authorization': f'Bearer {tok}'})
    with urllib.request.urlopen(req, timeout=5) as r:
        print(i, r.status, len(body), 'bytes', p['value'], p['caption'], p['color'], flush=True)
    time.sleep(max(0, 1 - (time.time() - t0)))
