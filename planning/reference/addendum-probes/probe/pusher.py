"""Pushes Influx line protocol to Grafana Live every second.
    python3 -I pusher.py <grafana url> <token> <stream id> <seconds> [start=1000]
measurement live: pending (int, start+i), level, caption (string); measurement supervisors: tag supervisor.
"""
import sys, time, urllib.request
url, tok, stream, secs = sys.argv[1], sys.argv[2], sys.argv[3], int(sys.argv[4])
start = int(sys.argv[5]) if len(sys.argv) > 5 else 1000
for i in range(secs):
    ns = time.time_ns()
    body = (f'live pending={start + i}i,level={i % 3}i,caption="Oldest: {i} s · default" {ns}\n'
            f'supervisors,supervisor=default processes={i % 5}i,max=12i,color="green" {ns}\n'
            f'supervisors,supervisor=slow processes={(i + 2) % 5}i,max=6i,color="yellow" {ns}\n')
    req = urllib.request.Request(f'{url}/api/live/push/{stream}', data=body.encode(), method='POST',
                                 headers={'Authorization': f'Bearer {tok}'})
    t = time.perf_counter()
    with urllib.request.urlopen(req, timeout=5) as r:
        code = r.status
    print(i, code, round((time.perf_counter() - t) * 1000, 1), 'ms', flush=True)
    time.sleep(max(0, 1 - (time.time_ns() - ns) / 1e9))
