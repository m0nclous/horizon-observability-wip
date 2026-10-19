"""One scrape target (a Service or load balancer) in front of two exporter replicas.

usage: python3 -I lb.py <directory> <port>
Requests alternate between <directory>/A and <directory>/B, the expositions the two replicas' export loops write
once a second; every request is logged as "<unix time> <replica> <ho_queue_jobs_total of the probe's class>".
"""
import itertools, os, re, sys, time
from http.server import BaseHTTPRequestHandler, HTTPServer

DIRECTORY, PORT = sys.argv[1], int(sys.argv[2])
turn = itertools.cycle(["A", "B"])
SERIES = re.compile(r'^\S*_queue_jobs_total\{[^}]*job_class="[^"]*CompletingJob"[^}]*status="completed"[^}]*\} (\S+)$', re.M)


class Handler(BaseHTTPRequestHandler):
    def do_GET(self):
        replica = next(turn)
        try:
            with open(os.path.join(DIRECTORY, replica), "rb") as f:
                body = f.read()
        except OSError:
            body = b""
        match = SERIES.search(body.decode("utf-8", "replace"))
        sys.stdout.write("%.1f %s %s\n" % (time.time(), replica, match.group(1) if match else "none"))
        sys.stdout.flush()
        self.send_response(200)
        self.send_header("Content-Type", "text/plain; version=0.0.4")
        self.send_header("Content-Length", str(len(body)))
        self.end_headers()
        self.wfile.write(body)

    def log_message(self, *args):
        pass


HTTPServer(("127.0.0.1", PORT), Handler).serve_forever()
