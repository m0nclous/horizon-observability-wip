"""First-value probe queries: usage python3 -I query.py <prometheus port> <unix time>.

Evaluates, per scrape job, over the 140 s window before <unix time>: the samples per series, Prometheus' increase(),
the dashboard's exact delta() when the probe ran (Queries\\Attempts: the last value before the window, else the first
value in it; replica dedupe max by (job, ...)) and "count-first", a dashboard-only alternative that counts a new
series' first value.
"""
import json, sys, urllib.parse, urllib.request
port, at = sys.argv[1], sys.argv[2]

def q(expr):
    url = 'http://127.0.0.1:%s/api/v1/query?%s' % (port, urllib.parse.urlencode({'query': expr, 'time': at}))
    data = json.load(urllib.request.urlopen(url))
    if data['status'] != 'success':
        return data
    return sorted((r['metric'].get('job', ''), r['metric'].get('job_class', '').split('\\')[-1], r['metric'].get('instance', ''), r['value'][1]) for r in data['data']['result'])

L = 'job, connection, queue, job_class, status'
S = 'queue, job_class, status'
for job in ['ho', 'late']:
    x = 'ho_queue_jobs_total{status="completed", job="%s"}' % job
    now = 'sum by (%s) (max by (%s) (%s))' % (S, L, x)
    start_lb = 'sum by (%s) (max by (%s) (last_over_time(%s[10m] offset 140s)))' % (S, L, x)
    start_min = 'sum by (%s) (max by (%s) (min_over_time(%s[140s])))' % (S, L, x)
    delta = '((%s - (%s or %s)) >= 0) or %s' % (now, start_lb, start_min, now)
    first = '((%s - %s) >= 0) or %s' % (now, start_lb, now)
    print('job=%s' % job)
    print('  samples       ', q('count_over_time(%s[140s])' % x))
    print('  increase()    ', q('increase(%s[140s])' % x))
    print('  delta()       ', q(delta))
    print('  count-first   ', q(first))
