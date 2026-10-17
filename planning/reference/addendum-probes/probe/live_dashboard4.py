"""Probe dashboard 4 (uid probe-live4): stream-mode shapes that work without refIds.
P401 stream Pending      : tiles measurement (latest rows only, buffer 1 ms) -> filterByValue tile=pending -> rowsToFields
P402 stream Supervisors  : same measurement, rows tile=supervisor
P403 stream Delayed      : delayed_series window push (K rows), maxDataPoints K -> value + seeded sparkline
"""
import json, sys
out = sys.argv[1]; K = int(sys.argv[2]) if len(sys.argv) > 2 else 60
G = {"type": "datasource", "uid": "grafana"}
def stat(pid, title, x, w, targets, tr, graph="none", mdp=None):
    p = {"id": pid, "type": "stat", "title": title, "datasource": G, "gridPos": {"h": 6, "w": w, "x": x, "y": 0},
         "targets": targets, "transformations": tr,
         "fieldConfig": {"defaults": {"color": {"mode": "thresholds"}, "decimals": 0, "unit": "locale", "noValue": "—",
                                      "thresholds": {"mode": "absolute", "steps": [{"color": "text", "value": None}]}}, "overrides": []},
         "options": {"colorMode": "background", "graphMode": graph, "justifyMode": "center", "orientation": "auto",
                     "reduceOptions": {"calcs": ["lastNotNull"], "fields": "", "values": False},
                     "textMode": "value_and_name", "wideLayout": True, "showPercentChange": False, "text": {"titleSize": 14}}}
    if mdp: p["maxDataPoints"] = mdp
    return p
def tiles(tile):
    return [{"id": "filterByValue", "options": {"type": "include", "match": "any", "filters": [
                {"fieldName": "tile", "config": {"id": "equal", "options": {"value": tile}}}]}},
            {"id": "rowsToFields", "options": {"mappings": [
                {"fieldName": "caption", "handlerKey": "field.name"},
                {"fieldName": "value", "handlerKey": "field.value"},
                {"fieldName": "color", "handlerKey": "color"},
                {"fieldName": "tile", "handlerKey": "__ignore"},
                {"fieldName": "time", "handlerKey": "__ignore"}]}}]
LIVE = lambda: [{"datasource": G, "refId": "A", "queryType": "measurements", "channel": "stream/ho_probe/tiles", "buffer": 1}]
panels = [
    stat(401, "P401 stream Pending (rowsToFields)", 0, 6, LIVE(), tiles("pending")),
    stat(402, "P402 stream Supervisors (rowsToFields)", 6, 12, LIVE(), tiles("supervisor")),
    stat(403, "P403 stream Delayed (window sparkline)", 18, 6,
         [{"datasource": G, "refId": "A", "queryType": "measurements", "channel": "stream/ho_probe/delayed_series"}], [],
         graph="area", mdp=K),
]
json.dump({"uid": "probe-live4", "title": "Live probe 4", "tags": ["probe"], "schemaVersion": 42, "refresh": "",
           "time": {"from": "now-15m", "to": "now"}, "panels": panels, "templating": {"list": []},
           "annotations": {"list": []}, "editable": True}, open(out, "w"), indent=2, ensure_ascii=False)
print("ok")
