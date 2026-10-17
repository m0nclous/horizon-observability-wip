"""Probe dashboard 2 (uid probe-live2): candidate production shapes of the live tiles.
    python3 -I live_dashboard2.py <out.json> [refresh=5s]
P201 poll Pending   : Mixed(Prometheus A history + Infinity L {Time, Value} + Infinity C {caption, color})
                      merge(A|L) then configFromData(C -> displayName, color)
P202 stream Pending : Grafana Live V {time, pending} + C {caption, color} -> configFromData(C)
P203 stream Supervisors: Grafana Live V (labels mode, tags supervisor+caption, field value = n + zone/64)
                      + C (tagless rows value,color; buffer 2 s) -> configFromData(C -> mappings)
P204 poll Supervisors: Infinity rows -> rowsToFields(displayName=caption, value, color)
P205 legacy-style Pending (Prometheus only) for comparison
"""
import json
import sys

out = sys.argv[1]
refresh = sys.argv[2] if len(sys.argv) > 2 else "5s"
PROM = {"type": "prometheus", "uid": "prometheus"}
GRAFANA = {"type": "datasource", "uid": "grafana"}
MIXED = {"type": "datasource", "uid": "-- Mixed --"}
INF = {"type": "yesoreyeram-infinity-datasource", "uid": "app-api"}


def inf(ref, columns, root=""):
    return {"datasource": INF, "refId": ref, "type": "json", "source": "url", "parser": "backend", "format": "table",
            "url": "/live", "url_options": {"method": "GET", "data": ""}, "root_selector": root, "columns": columns}


def live(ref, channel, fields=None, buffer=None):
    q = {"datasource": GRAFANA, "refId": ref, "queryType": "measurements", "channel": channel}
    if fields:
        q["filter"] = {"fields": fields}
    if buffer:
        q["buffer"] = buffer
    return q


def stat(pid, title, x, ds, targets, transformations, graph="area", w=6, y=0, defaults=None, fields=""):
    d = {"color": {"mode": "thresholds"}, "decimals": 0, "unit": "locale", "noValue": "—",
         "thresholds": {"mode": "absolute", "steps": [{"color": "green", "value": None}]}}
    d.update(defaults or {})
    return {"id": pid, "type": "stat", "title": title, "datasource": ds, "gridPos": {"h": 5, "w": w, "x": x, "y": y},
            "targets": targets, "transformations": transformations,
            "fieldConfig": {"defaults": d, "overrides": []},
            "options": {"colorMode": "background", "graphMode": graph, "justifyMode": "center", "orientation": "auto",
                        "reduceOptions": {"calcs": ["lastNotNull"], "fields": fields, "values": False},
                        "textMode": "value_and_name", "wideLayout": True, "showPercentChange": False,
                        "text": {"titleSize": 14}}}


PENDING_A = {"datasource": PROM, "refId": "A", "expr": "sum(office_queue_pending_jobs)", "range": True,
             "instant": False, "legendFormat": "__auto", "editorMode": "code"}
CAPTION_COLOR = {"id": "configFromData", "options": {
    "configRefId": "C",
    "mappings": [{"fieldName": "caption", "handlerKey": "displayName"},
                 {"fieldName": "color", "handlerKey": "color"}]}}

panels = [
    stat(201, "P201 poll Pending", 0, MIXED,
         [PENDING_A,
          inf("L", [{"selector": "time", "text": "Time", "type": "timestamp_epoch"},
                    {"selector": "pending.value", "text": "Value", "type": "number"}]),
          inf("C", [{"selector": "pending.caption", "text": "caption", "type": "string"},
                    {"selector": "pending.color", "text": "color", "type": "string"}])],
         [{"id": "merge", "filter": {"id": "byRefId", "options": "A|L"}, "options": {}},
          CAPTION_COLOR]),
    stat(202, "P202 stream Pending", 6, GRAFANA,
         [live("V", "stream/ho_probe/live", ["time", "pending"]),
          live("C", "stream/ho_probe/live", ["caption", "color"], 2000)],
         [CAPTION_COLOR]),
    stat(203, "P203 stream Supervisors", 12, GRAFANA,
         [live("V", "stream/ho_probe/supervisors", ["time", "value"]),
          live("C", "stream/ho_probe/supervisor_zones", ["value", "color"], 2000)],
         [{"id": "configFromData", "options": {"configRefId": "C", "mappings": [
             {"fieldName": "value", "handlerKey": "mappings.value"},
             {"fieldName": "color", "handlerKey": "mappings.color"}]}}],
         defaults={"displayName": "${__field.labels.caption}"}),
    stat(204, "P204 poll Supervisors", 18, INF,
         [inf("S", [{"selector": "caption", "text": "caption", "type": "string"},
                    {"selector": "processes", "text": "processes", "type": "number"},
                    {"selector": "color", "text": "color", "type": "string"}], root="supervisors")],
         [{"id": "rowsToFields", "options": {"mappings": [
             {"fieldName": "caption", "handlerKey": "field.name"},
             {"fieldName": "processes", "handlerKey": "field.value"},
             {"fieldName": "color", "handlerKey": "color"}]}}],
         graph="none"),
    stat(205, "P205 Prometheus Pending (reference)", 0, PROM, [PENDING_A], [], y=5),
]
dash = {"uid": "probe-live2", "title": "Live probe 2", "tags": ["probe"], "schemaVersion": 42, "refresh": refresh,
        "time": {"from": "now-15m", "to": "now"}, "timepicker": {"refresh_intervals": ["1s", "5s", "10s", "15s", "30s"]},
        "panels": panels, "templating": {"list": []}, "annotations": {"list": []}, "editable": True}
json.dump(dash, open(out, "w"), indent=2, ensure_ascii=False)
print("ok")
