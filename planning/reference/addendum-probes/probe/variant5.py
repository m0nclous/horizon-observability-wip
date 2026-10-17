"""v5 = v4 + the per-supervisor Queues table computed once by a top-level source panel (id 90) and read by the
repeated table through the -- Dashboard -- datasource with filterByValue(supervisor == ${supervisor_title}).
    python3 -I variant5.py <v4.json> <out.json>"""
import copy, json, re, sys
d = json.load(open(sys.argv[1]))
TAIL = re.compile(r'^\((.*)\) and on \(queue\) group by \(queue\) \(last_over_time\(office_horizon_supervisor_processes\{supervisor=~"\$supervisor_title"\}\[1d\]\)\)$', re.S)
JOIN = ' * on (queue) group_left (supervisor) group by (queue, supervisor) (last_over_time(office_horizon_supervisor_processes[1d]))'
def walk(ps):
    for p in ps:
        yield p
        yield from p.get('panels', [])
table = next(p for p in walk(d['panels']) if p['id'] == 5)
src = copy.deepcopy(table)
for t in src['targets']:
    m = TAIL.match(t['expr'])
    if m:
        t['expr'] = f"({m.group(1)}){JOIN}"
    elif t['refId'] == 'Z':
        t['expr'] = 'label_replace(label_replace(vector(1), "queue", "—", "", ""), "supervisor", "—", "", "") unless on () (' + src['targets'][0]['expr'] + ')'
src.update({'id': 90, 'title': 'shared queue source', 'repeat': None, 'transformations': [], 'gridPos': {'h': 1, 'w': 24, 'x': 0, 'y': 11}})
src.pop('repeat'); src.pop('repeatDirection', None)
table['datasource'] = {'type': 'datasource', 'uid': '-- Dashboard --'}
table['targets'] = [{'datasource': table['datasource'], 'refId': 'A', 'panelId': 90, 'withTransforms': False}]
table['transformations'] = [{'id': 'filterByValue', 'options': {'type': 'include', 'match': 'any', 'filters': [
    {'fieldName': 'supervisor', 'config': {'id': 'equal', 'options': {'value': '${supervisor_title}'}}}]}}] + table['transformations']
org = next(t for t in table['transformations'] if t['id'] == 'organize')
org['options']['excludeByName']['supervisor'] = True
# insert the source panel right after the queues table (inside the Current Workload row)
idx = d['panels'].index(table)
d['panels'].insert(idx + 1, src)
d['uid'], d['title'] = 'probe-v5', 'Queues v5'
json.dump(d, open(sys.argv[2], 'w'), ensure_ascii=False, indent=2)
print('ok', sum(1 for t in src['targets'] if JOIN in t['expr']), 'targets rewritten')
