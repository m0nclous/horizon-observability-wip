"""Writes measurable variants of the legacy dashboard (text-level rewrites, for timing only).
    python3 -I variants.py <legacy queues.json> <out dir>
v1: counter baseline lookback [1d] -> [10m] (attempts_delta / attempts_per_step)
v2: v1 + job_class All = ".+" (allValue) instead of the explicit list (upper bound of the regex-shrink win)
v3: v2 + variables refreshed on dashboard load only (refresh 1) instead of on every time-range change
"""
import copy, json, re, sys
src, out = sys.argv[1], sys.argv[2]
text = open(src).read()
v1 = re.sub(r'(office_queue_jobs_total\{[^}]*\})\[1d\]', r'\1[10m]', text)
d1 = json.loads(v1)
d2 = copy.deepcopy(d1)
for v in d2['templating']['list']:
    if v['name'] == 'job_class':
        v['allValue'] = '.+'
d3 = copy.deepcopy(d2)
for v in d3['templating']['list']:
    if v.get('refresh') == 2:
        v['refresh'] = 1
for name, d in (('v1', d1), ('v2', d2), ('v3', d3)):
    d['uid'] = 'probe-' + name
    d['title'] = 'Queues ' + name
    d['tags'] = ['probe']
    json.dump(d, open(f'{out}/{name}.json', 'w'), ensure_ascii=False, indent=2)
print('ok')

# v4: v3 + Completed/Failed tile sparkline computed aggregate-first (E1); exact per-step values on continuous data
def per_step(statuses):
    s = f'office_queue_jobs_total{{queue=~"$queue", job_class=~"$job_class", status=~"{statuses}"}}'
    c_to = f'scalar(sum(last_over_time({s}[10m] @ ${{__to:date:seconds}})))'
    c_from = (f'scalar(sum(last_over_time({s}[10m] @ ${{__from:date:seconds}})'
              f' or min_over_time({s}[$__range] @ ${{__to:date:seconds}})))')
    return (f'clamp_min(clamp_max(sum(last_over_time({s}[10m] offset -$__interval)), {c_to})'
            f' - clamp_min(sum(last_over_time({s}[10m])), {c_from}), 0) or on () 0 * group(office_horizon_status)')
d4 = copy.deepcopy(d3)
def walk(ps):
    for p in ps:
        yield p
        yield from p.get('panels', [])
for p in walk(d4['panels']):
    if p['id'] == 8:
        p['targets'][0]['expr'] = per_step('completed')
    if p['id'] == 4:
        p['targets'][0]['expr'] = per_step('failed|timed_out')
d4['uid'], d4['title'], d4['tags'] = 'probe-v4', 'Queues v4', ['probe']
json.dump(d4, open(f'{out}/v4.json', 'w'), ensure_ascii=False, indent=2)
print('v4 ok')
