"""Pushes the live snapshot of fakeapp (/live) to Grafana Live every second as Influx line protocol.
    python3 -I pusher2.py <grafana url> <token> <stream id> <seconds> <app url>
measurements: live (pending, caption, color), supervisors (tags supervisor, caption; field value),
supervisor_zones (tagless rows value, color)."""
import json, sys, time, urllib.request
url, tok, stream, secs, app = sys.argv[1], sys.argv[2], sys.argv[3], int(sys.argv[4]), sys.argv[5]
def tag(v):
    return v.replace('\\', '\\\\').replace(',', '\\,').replace('=', '\\=').replace(' ', '\\ ')
def sval(v):
    return '"' + v.replace('\\', '\\\\').replace('"', '\\"') + '"'
for i in range(secs):
    t0 = time.time()
    snap = json.load(urllib.request.urlopen(app + '/live'))
    ns = time.time_ns()
    p = snap['pending']
    lines = [f"live pending={p['value']}i,caption={sval(p['caption'])},color={sval(p['color'])} {ns}"]
    for s in snap['supervisors']:
        lines.append(f"supervisors,supervisor={tag(s['name'])},caption={tag(s['caption'])} value={s['value']} {ns}")
        lines.append(f"supervisor_zones value={s['value']},color={sval(s['color'])} {ns}")
    req = urllib.request.Request(f'{url}/api/live/push/{stream}', data=('\n'.join(lines) + '\n').encode(), method='POST',
                                 headers={'Authorization': f'Bearer {tok}'})
    with urllib.request.urlopen(req, timeout=5) as r:
        print(i, r.status, p['value'], [ (s['caption'], s['value'], s['color']) for s in snap['supervisors']], flush=True)
    time.sleep(max(0, 1 - (time.time() - t0)))
