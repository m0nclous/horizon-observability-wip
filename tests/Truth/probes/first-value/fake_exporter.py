"""Scripted exporter for the first-value evidence (T16): serves ho_queue_jobs_total as a function of time.

usage: python3 -I fake_exporter.py <mode: baseline|hold> <t0 unix> <port> <start offset s>
The target answers 503 before t0 + start offset (as if it did not exist yet).
"""
import sys, time
from http.server import BaseHTTPRequestHandler, HTTPServer

MODE, T0, PORT, START = sys.argv[1], float(sys.argv[2]), int(sys.argv[3]), float(sys.argv[4])
STATUSES = ["completed", "released", "retried", "failed", "timed_out"]
HOLD = 75.0


def exposition(t):
    lines = ["# HELP ho_queue_jobs_total Finished job attempts by outcome.", "# TYPE ho_queue_jobs_total counter"]
    tuples = {"App\\\\Jobs\\\\OldJob": {"completed": 100 if t < 60 else 102}}
    if t >= 30:
        completed = 3 if t < 60 else 5
        if MODE == "hold" and t < 30 + HOLD:
            completed = 0
        tuples["App\\\\Jobs\\\\NewJob"] = {"completed": completed}
    for cls, counts in sorted(tuples.items()):
        for status in STATUSES:
            lines.append('ho_queue_jobs_total{connection="redis",queue="default",job_class="%s",status="%s"} %d' % (cls, status, counts.get(status, 0)))
    return "\n".join(lines) + "\n"


class Handler(BaseHTTPRequestHandler):
    def do_GET(self):
        t = time.time() - T0
        if t < START:
            self.send_response(503)
            self.end_headers()
            return
        body = exposition(t).encode()
        self.send_response(200)
        self.send_header("Content-Type", "text/plain; version=0.0.4")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, *args):
        pass


HTTPServer(("127.0.0.1", PORT), Handler).serve_forever()
