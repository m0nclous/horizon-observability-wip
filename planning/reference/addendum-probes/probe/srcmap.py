"""Extracts original sources from Grafana's source maps.  python3 -I srcmap.py <build dir> <out dir> <path regex>"""
import glob, json, os, re, sys
build, out, pat = sys.argv[1], sys.argv[2], re.compile(sys.argv[3])
seen = 0
for m in glob.glob(os.path.join(build, '*.map')):
    try:
        d = json.load(open(m))
    except Exception:
        continue
    for src, content in zip(d.get('sources', []), d.get('sourcesContent') or []):
        if content and pat.search(src):
            dst = os.path.join(out, re.sub(r'^(webpack://)?[^/]*/', '', src).lstrip('./').replace('..', '_'))
            os.makedirs(os.path.dirname(dst), exist_ok=True)
            if not os.path.exists(dst):
                open(dst, 'w').write(content)
                seen += 1
print('extracted', seen)
