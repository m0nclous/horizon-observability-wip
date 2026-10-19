"""One scrape target (Arch Q1.5: a Service or load balancer) in front of two exporter replicas (T16 QA round 2).

usage: python3 -I lb_exporter.py <t0 unix> <port>
A worker in sync with replica A records the first 3 attempts of a new series at t0 + 10 s. Replica A's clock is real
time, replica B's 15 s ahead (the worker is 15 s behind B: inside the hold's stated budget). Requests alternate
A, B, A, ... Each replica applies AttemptsCollector's rule with its own clock: 0 while |age| < 90 s, else 3.
"""
import itertools, sys, time
from http.server import BaseHTTPRequestHandler, HTTPServer

T0, PORT = float(sys.argv[1]), int(sys.argv[2])
SKEW = {"A": 0.0, "B": 15.0}
CREATED, HOLD = 10.0, 90.0
turn = itertools.cycle(["A", "B"])


class Handler(BaseHTTPRequestHandler):
    def do_GET(self):
        t = time.time() - T0
        replica = next(turn)
        lines = ["# TYPE ho_queue_jobs_total counter"]
        value = None
        if t >= CREATED:
            value = 0 if abs(t + SKEW[replica] - CREATED) < HOLD else 3
            lines.append('ho_queue_jobs_total{connection="redis",queue="default",job_class="New",status="completed"} %d' % value)
        sys.stdout.write("%.1f %s %s\n" % (t, replica, value))
        sys.stdout.flush()
        body = ("\n".join(lines) + "\n").encode()
        self.send_response(200)
        self.send_header("Content-Type", "text/plain; version=0.0.4")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, *args):
        pass


HTTPServer(("127.0.0.1", PORT), Handler).serve_forever()
