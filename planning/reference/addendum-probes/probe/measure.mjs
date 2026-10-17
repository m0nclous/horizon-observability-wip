// Records every Grafana data request of a dashboard load and of the following auto-refresh cycles.
//   node measure.mjs <url> <out.json> [observeSeconds=70] [width=1920] [height=1080]
// Output: one entry per /api/ds/query (and other /api/ calls) with panel id, refIds, start/end ms
// relative to navigation start, plus the cycles (bursts separated by >= 2 s of silence).
import { chromium } from 'playwright';
import { writeFileSync } from 'node:fs';

const [url, out, observe = '70', w = '1920', h = '1080'] = process.argv.slice(2);
const browser = await chromium.launch();
const page = await browser.newPage({ locale: 'en-US', timezoneId: 'UTC', viewport: { width: +w, height: +h } });
const t0 = Date.now();
const reqs = new Map();
const done = [];
page.on('request', (r) => {
  const u = r.url();
  if (!u.includes('/api/')) return;
  reqs.set(r, { start: Date.now() - t0, url: u.replace(/^https?:\/\/[^/]+/, ''), method: r.method(), headers: r.headers(), body: r.postData() });
});
const finish = async (r, failed) => {
  const e = reqs.get(r);
  if (!e) return;
  e.end = Date.now() - t0;
  e.failed = failed;
  try { const resp = await r.response(); e.status = resp ? resp.status() : null; e.size = resp ? (await resp.body()).length : 0; } catch { /* ignore */ }
  done.push(e);
};
page.on('requestfinished', (r) => finish(r, false));
page.on('requestfailed', (r) => finish(r, true));
const errors = [];
page.on('pageerror', (e) => errors.push('pageerror: ' + e.message));
await page.goto(url, { waitUntil: 'domcontentloaded', timeout: 90000 });
await page.waitForTimeout(+observe * 1000);
await browser.close();

const rows = done.filter((e) => e.url.startsWith('/api/ds/query') || e.url.startsWith('/api/live') || e.url.includes('/resources'))
  .map((e) => {
    let refIds = [], kind = 'panel';
    try {
      const b = JSON.parse(e.body || '{}');
      refIds = (b.queries || []).map((q) => q.refId);
      if (!e.headers['x-panel-id']) kind = 'variable';
    } catch { /* ignore */ }
    return { start: e.start, end: e.end, ms: e.end - e.start, panel: e.headers['x-panel-id'] || null, kind, refIds, status: e.status, size: e.size,
      url: e.url.slice(0, 120), body: e.body };
  }).sort((a, b) => a.start - b.start);
// cycles: bursts separated by >= 2000 ms without an active request
const cycles = [];
let cur = null;
for (const r of rows) {
  if (!cur || r.start > cur.lastEnd + 2000) { cur = { start: r.start, lastEnd: r.end, n: 0, panels: new Set(), variables: 0 }; cycles.push(cur); }
  cur.lastEnd = Math.max(cur.lastEnd, r.end);
  cur.n++;
  if (r.kind === 'variable') cur.variables++; else cur.panels.add(r.panel);
}
const summary = cycles.map((c) => ({ start: c.start, duration: c.lastEnd - c.start, requests: c.n, variableRequests: c.variables, panels: [...c.panels].length }));
writeFileSync(out, JSON.stringify({ url, summary, errors, rows }, null, 1));
console.log(JSON.stringify({ summary, errors: errors.slice(0, 5) }, null, 1));
