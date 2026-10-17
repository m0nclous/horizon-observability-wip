"""Probe dashboard 3 (uid probe-live3): stream-mode tiles that keep caption, colour and a seeded sparkline.
    python3 -I live_dashboard3.py <out.json>
Channels (stream id ho_probe):
  pending_series  K rows per push (window), field value          -> sparkline + value (maxDataPoints = K)
  pending_meta    1 row per push: kind="config", caption, color   -> partitionByValues(kind) gives it a refId
  sup_series      K rows per push per supervisor, tags supervisor+caption, field value (n + zone/64)
  sup_meta        1 row per supervisor per push: kind="config", value, color -> value mappings
"""
import json
import sys

out = sys.argv[1]
K = int(sys.argv[2]) if len(sys.argv) > 2 else 60
GRAFANA = {"type": "datasource", "uid": "grafana"}


def live(ref, channel, buffer=None):
    q = {"datasource": GRAFANA, "refId": ref, "queryType": "measurements", "channel": channel}
    if buffer:
        q["buffer"] = buffer
    return q


def stat(pid, title, x, targets, transformations, defaults=None, w=6):
    d = {"color": {"mode": "thresholds"}, "decimals": 0, "unit": "locale", "noValue": "—",
         "thresholds": {"mode": "absolute", "steps": [{"color": "green", "value": None}]}}
    d.update(defaults or {})
    return {"id": pid, "type": "stat", "title": title, "datasource": GRAFANA, "maxDataPoints": K,
            "gridPos": {"h": 6, "w": w, "x": x, "y": 0}, "targets": targets, "transformations": transformations,
            "fieldConfig": {"defaults": d, "overrides": []},
            "options": {"colorMode": "background", "graphMode": "area", "justifyMode": "center", "orientation": "auto",
                        "reduceOptions": {"calcs": ["lastNotNull"], "fields": "", "values": False},
                        "textMode": "value_and_name", "wideLayout": True, "showPercentChange": False,
                        "text": {"titleSize": 14}}}


def partition(frame):
    return {"id": "partitionByValues", "filter": {"id": "byName", "options": f"/^{frame}$/"},
            "options": {"fields": ["kind"], "keepFields": False}}


panels = [
    stat(301, "P301 stream Pending (window + meta)", 0,
         [live("V", "stream/ho_probe/pending_series"), live("C", "stream/ho_probe/pending_meta", 1)],
         [partition("pending_meta"),
          {"id": "configFromData", "options": {"configRefId": "undefined-config", "mappings": [
              {"fieldName": "caption", "handlerKey": "displayName"},
              {"fieldName": "color", "handlerKey": "color"}]}}]),
    stat(302, "P302 stream Supervisors (window + zone mappings)", 6,
         [live("V", "stream/ho_probe/sup_series"), live("C", "stream/ho_probe/sup_meta", 1)],
         [partition("sup_meta"),
          {"id": "configFromData", "options": {"configRefId": "undefined-config", "mappings": [
              {"fieldName": "value", "handlerKey": "mappings.value"},
              {"fieldName": "color", "handlerKey": "mappings.color"}]}}],
         defaults={"displayName": "${__field.labels.caption}"}, w=12),
    {"id": 303, "type": "table", "title": "P303 debug: frames after partition", "datasource": GRAFANA,
     "gridPos": {"h": 6, "w": 6, "x": 18, "y": 0},
     "targets": [live("C", "stream/ho_probe/pending_meta", 1)],
     "transformations": [partition("pending_meta")],
     "fieldConfig": {"defaults": {}, "overrides": []}, "options": {"showHeader": True}},
]
dash = {"uid": "probe-live3", "title": "Live probe 3", "tags": ["probe"], "schemaVersion": 42, "refresh": "",
        "time": {"from": "now-15m", "to": "now"}, "panels": panels, "templating": {"list": []},
        "annotations": {"list": []}, "editable": True}
json.dump(dash, open(out, "w"), indent=2, ensure_ascii=False)
print("ok")
