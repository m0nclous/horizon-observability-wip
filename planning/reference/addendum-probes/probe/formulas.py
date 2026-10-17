"""Cost and result of per-step attempt formulations for the Completed/Failed tiles (1 h, step 15 s)."""
import json, sys, time, urllib.parse, urllib.request
P = 'http://127.0.0.1:9391'
d = json.load(open(sys.argv[1]))
status = sys.argv[2] if len(sys.argv) > 2 else 'completed'
panel = '8' if status == 'completed' else '4'
b = [json.loads(r['body']) for r in d['rows'] if r['panel'] == panel][1]
to = int(time.time()) // 15 * 15 - 30
frm = to - 3600
cur = b['queries'][0]['expr'].replace('$__interval', '15s').replace('${__from:date:seconds}', str(frm)).replace('${__to:date:seconds}', str(to))
def q(expr, step=15):
    t = time.perf_counter()
    r = json.load(urllib.request.urlopen(urllib.request.Request(P + '/api/v1/query_range', data=urllib.parse.urlencode({'query': expr, 'start': frm, 'end': to, 'step': step, 'stats': 'all'}).encode())))
    ms = round((time.perf_counter() - t) * 1000)
    vals = [float(v[1]) for v in r['data']['result'][0]['values']] if r['data']['result'] else []
    return ms, r['data']['stats']['samples']['totalQueryableSamples'], round(sum(vals)), len(vals)
S = f'office_queue_jobs_total{{status=~"{status}"}}'
L = '[10m]'
E1 = (f'clamp_min(clamp_max(sum(last_over_time({S}{L} offset -15s)), scalar(sum(last_over_time({S}{L} @ {to})))) '
      f'- clamp_min(sum(last_over_time({S}{L})), scalar(sum(last_over_time({S}{L} @ {frm})))), 0)')
E3 = f'sum((last_over_time({S}{L} offset -15s) - last_over_time({S}{L})) >= 0)'
E2 = f'sum(increase({S}[1m]))/4'
exact = f'sum(last_over_time({S}{L} @ {to})) - sum(last_over_time({S}{L} @ {frm}))'
r = json.load(urllib.request.urlopen(urllib.request.Request(P + '/api/v1/query', data=urllib.parse.urlencode({'query': exact, 'time': to}).encode())))
print(status, 'exact period total (C(to)-C(from))', r['data']['result'][0]['value'][1])
for name, e in [('legacy attempts_per_step ([10m] lookback)', cur), ('E1 aggregate first, edge-clipped', E1),
                ('E3 per-series diff >= 0', E3), ('E2 sum(increase[1m])/4 (shape only)', E2)]:
    rs = [q(e) for _ in range(3)]
    print(f'  {name:42s} {sorted(x[0] for x in rs)[1]:6d} ms {rs[0][1]:>12,d} samples  sum={rs[0][2]} points={rs[0][3]}')
