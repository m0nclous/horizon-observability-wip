"""Probe dashboard for Grafana Live / Infinity / Mixed combinations (uid probe-live).
    python3 -I live_dashboard.py <out.json> [refresh=5s]
"""
import json
import sys

out = sys.argv[1]
refresh = sys.argv[2] if len(sys.argv) > 2 else "5s"
PROM = {"type": "prometheus", "uid": "prometheus"}
GRAFANA = {"type": "datasource", "uid": "grafana"}
MIXED = {"type": "datasource", "uid": "-- Mixed --"}
DASH = {"type": "datasource", "uid": "-- Dashboard --"}
INF = {"type": "yesoreyeram-infinity-datasource", "uid": "app-api"}

PENDING_PROM = {"datasource": PROM, "refId": "A", "expr": "sum(office_queue_pending_jobs)", "range": True, "instant": False,
                "legendFormat": "pending", "editorMode": "code"}
LIVE_Q = {"datasource": GRAFANA, "refId": "L", "queryType": "measurements", "channel": "stream/ho_probe/live",
          "filter": {"fields": ["time", "pending"]}}
INF_Q = {"datasource": INF, "refId": "L", "type": "json", "source": "url", "parser": "backend", "format": "table",
         "url": "/live", "url_options": {"method": "GET", "data": ""},
         "root_selector": "", "columns": [
             {"selector": "time", "text": "Time", "type": "timestamp_epoch"},
             {"selector": "pending.value", "text": "pending", "type": "number"},
             {"selector": "pending.level", "text": "level", "type": "number"},
             {"selector": "pending.caption", "text": "caption", "type": "string"}]}

# Prometheus frame -> fields Time + "pending"; live frame -> Time + pending (+ level, caption): rename, then merge
MERGE = [
    {"id": "renameByRegex", "options": {"regex": "^Value$", "renamePattern": "pending"}},
    {"id": "organize", "options": {"excludeByName": {"level": True, "caption": True}, "renameByName": {"time": "Time"}}},
    {"id": "merge", "options": {}},
    {"id": "sortBy", "options": {"sort": [{"field": "Time"}]}},
]


def stat(pid, title, x, y, ds, targets, transformations=None, graph="area", w=6, extra_opts=None):
    return {"id": pid, "type": "stat", "title": title, "datasource": ds, "gridPos": {"h": 5, "w": w, "x": x, "y": y},
            "targets": targets, "transformations": transformations or [],
            "fieldConfig": {"defaults": {"color": {"mode": "thresholds"}, "decimals": 0, "unit": "locale", "noValue": "—",
                                         "thresholds": {"mode": "absolute", "steps": [{"color": "green", "value": None}]}},
                            "overrides": []},
            "options": {"colorMode": "background", "graphMode": graph, "justifyMode": "center", "orientation": "auto",
                        "reduceOptions": {"calcs": ["lastNotNull"], "fields": "", "values": False},
                        "textMode": "value_and_name", "wideLayout": True, "showPercentChange": False,
                        **(extra_opts or {})}}


panels = [
    stat(101, "P101 live only (Grafana Live)", 0, 0, GRAFANA, [LIVE_Q]),
    stat(102, "P102 Mixed: Prometheus + Grafana Live", 6, 0, MIXED, [PENDING_PROM, LIVE_Q], MERGE),
    stat(103, "P103 Mixed: Prometheus + Infinity /live", 12, 0, MIXED, [PENDING_PROM, INF_Q], MERGE),
    stat(104, "P104 Dashboard DS -> P101", 18, 0, DASH, [{"datasource": DASH, "refId": "A", "panelId": 101, "withTransforms": True}]),
    stat(105, "P105 Mixed: Prometheus + Dashboard DS -> P101", 0, 5, MIXED,
         [PENDING_PROM, {"datasource": DASH, "refId": "L", "panelId": 101, "withTransforms": False}], MERGE),
    stat(106, "P106 Prometheus only", 6, 5, PROM, [PENDING_PROM]),
    {"id": 107, "type": "timeseries", "title": "P107 live timeseries (all fields)", "datasource": GRAFANA,
     "gridPos": {"h": 8, "w": 12, "x": 12, "y": 5},
     "targets": [{"datasource": GRAFANA, "refId": "A", "queryType": "measurements", "channel": "stream/ho_probe/live"}],
     "fieldConfig": {"defaults": {}, "overrides": []}, "options": {"legend": {"showLegend": True, "displayMode": "list", "placement": "bottom"}}},
    {"id": 108, "type": "table", "title": "P108 supervisors measurement (tags)", "datasource": GRAFANA,
     "gridPos": {"h": 8, "w": 12, "x": 0, "y": 10},
     "targets": [{"datasource": GRAFANA, "refId": "A", "queryType": "measurements", "channel": "stream/ho_probe/supervisors"}],
     "fieldConfig": {"defaults": {}, "overrides": []}, "options": {"showHeader": True}},
]
dash = {"uid": "probe-live", "title": "Live probe", "tags": ["probe"], "schemaVersion": 42, "refresh": refresh,
        "time": {"from": "now-15m", "to": "now"}, "timepicker": {"refresh_intervals": ["1s", "5s", "10s", "15s", "30s"]},
        "panels": panels, "templating": {"list": []}, "annotations": {"list": []}, "editable": True}
json.dump(dash, open(out, "w"), indent=2)
print("ok")
