#!/usr/bin/env node
// End-to-end check of the rendered live tiles (ADDENDUM R4.8, Q9' WP8D), run by tests/Grafana/LiveE2eTest.php.
//
//   node tests/Grafana/Support/live-e2e.mjs --grafana http://localhost:3000 --uid <dashboard uid> --mode poll|stream
//        [--app http://localhost:8000] [--token workbench-token-0123456789abcdef] [--seconds 20]
//        [--stream horizon_observability] [--screenshot build/live.png] [--growing]
//
// Opens the dashboard (kiosk, last 15 minutes, its own refresh), samples the Overview tiles four times a second for
// --seconds while it polls GET {app}/horizon/observability/live, and prints a JSON report; exit 1 on a failure:
// - poll: Pending (value, caption), Delayed, every Supervisor Processes tile (caption, value) and the Completed caption
//   show what the last snapshot query of their panel returned (the Infinity frames of the panel's /api/ds/query
//   response, read in the browser), every refresh queries the snapshot again, and each answer is a snapshot of the
//   last 12 s (the 1 s cache and the stale copy served while another request rebuilds);
// - stream: Pending (value, caption), Delayed and Supervisor Processes show a /live answer of the last 2.5 s (the
//   stream leader and /live share the full-scope snapshot), two frames of the tiles channel are at most 2 s apart, and
//   the Completed caption polls (a /live answer of the last refresh plus 1.5 s);
// - both: Supervisor Processes shows one field per supervisor, no duplicate field after a refresh and a
//   "name · max N" caption each; Pending one field with an "Oldest: " caption;
// - with --growing (pause Horizon first, `testbench horizon:pause`, so the queues grow): the poll Pending tile changes
//   at every dashboard refresh, faster than Prometheus scrapes, and the stream Pending tile never stays unchanged for
//   more than 2 s.
// Needs Playwright (`npm i --no-save playwright@1.56.1 && npx playwright install chromium`).

const options = { growing: false };
for (let i = 2; i < process.argv.length; i++) {
  const [key, inline] = process.argv[i].replace(/^--/, '').split(/=(.*)/s, 2);
  options[key] = key === 'growing' ? true : inline !== undefined ? inline : process.argv[++i];
}
const grafana = (options.grafana || 'http://localhost:3000').replace(/\/$/, '');
const app = (options.app || 'http://localhost:8000').replace(/\/$/, '');
const token = options.token || 'workbench-token-0123456789abcdef';
const uid = options.uid || 'horizon-observability';
const mode = options.mode || 'poll';
const stream = options.stream || 'horizon_observability';
const duration = Number(options.seconds || 20) * 1000;

let chromium;
try {
  ({ chromium } = await import(process.cwd() + '/node_modules/playwright/index.mjs'));
} catch {
  console.error('Playwright is missing: npm i --no-save playwright@1.56.1 && npx playwright install chromium');
  process.exit(2);
}

const failures = [];
const fail = (message) => failures.push(message);

const dashboard = await (await fetch(`${grafana}/api/dashboards/uid/${uid}`)).json();
const refreshMs = (() => {
  const match = /^(\d+)(s|m)$/.exec(dashboard.dashboard?.refresh ?? '');
  return match ? Number(match[1]) * (match[2] === 'm' ? 60000 : 1000) : 10000;
})();

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1920, height: 1200 }, locale: 'en-US', timezoneId: 'UTC' });
const errors = [];
page.on('pageerror', (error) => errors.push(error.message));
const frames = [];
page.on('websocket', (ws) => ws.on('framereceived', (frame) => {
  if (String(frame.payload).includes(`stream/${stream}/tiles`)) frames.push(Date.now());
}));

// The snapshot frames of the live Infinity queries, per panel: [{t, refs: {refId: [{field: value}]}}]
const snapshots = { 2: [], 3: [], 8: [], 9: [] };
page.on('response', async (response) => {
  if (!response.url().includes('/api/ds/query')) return;
  const panel = Number(response.request().headers()['x-panel-id']);
  const body = response.request().postData() ?? '';
  if (!(panel in snapshots) || !body.includes('/live?')) return;
  const t = Date.now();
  try {
    const refs = {};
    for (const [refId, result] of Object.entries((await response.json()).results ?? {})) {
      const frame = result.frames?.[0];
      if (!frame) continue;
      const names = frame.schema.fields.map((field) => field.name);
      const values = frame.data?.values ?? [];
      refs[refId] = (values[0] ?? []).map((_, row) => Object.fromEntries(names.map((name, column) => [name, values[column]?.[row] ?? null])));
    }
    snapshots[panel].push({ t, refs });
  } catch (error) {
    errors.push(`ds/query of panel ${panel}: ${error.message}`);
  }
});

const live = [];
let polling = true;
const pollLive = (async () => {
  while (polling) {
    try {
      const response = await fetch(`${app}/horizon/observability/live`, { headers: { Authorization: `Bearer ${token}` } });
      live.push({ t: Date.now(), doc: await response.json() });
    } catch {
      // keep sampling
    }
    await new Promise((resolve) => setTimeout(resolve, 200));
  }
})();

const readPanels = () => page.$$eval('[data-viz-panel-key]', (elements) => Object.fromEntries(
  elements.map((element) => [element.getAttribute('data-viz-panel-key'), element.innerText]),
)).catch(() => ({}));

// A stat panel's text: the title, then caption and value lines (value_and_name) or values only (value).
const lines = (text) => (text ?? '').split('\n').map((line) => line.trim()).filter((line) => line !== '').slice(1);
const pairs = (text) => {
  const rows = lines(text);
  const result = [];
  for (let i = 0; i + 1 < rows.length; i += 2) result.push({ caption: rows[i], value: rows[i + 1] });
  return result;
};
const number = (value) => (value === undefined || value === '—' ? null : Number(String(value).replace(/,/g, '')));

await page.goto(`${grafana}/d/${uid}?orgId=1&from=now-15m&to=now&kiosk`, { waitUntil: 'networkidle', timeout: 90000 });

// The first render of the live tiles (a cold Grafana starts the Infinity backend on the first query)
const ready = Date.now();
while (Date.now() - ready < 30000) {
  const panels = await readPanels();
  if (pairs(panels['panel-3']).length > 0 && pairs(panels['panel-2']).length === 1 && lines(panels['panel-9']).length > 0
    && /^RPM: /.test(pairs(panels['panel-8'])[0]?.caption ?? '')) break;
  await page.waitForTimeout(250);
}

const samples = [];
const start = Date.now();
while (Date.now() - start < duration) {
  samples.push({ t: Date.now(), panels: await readPanels() });
  await page.waitForTimeout(250);
}
if (options.screenshot) await page.screenshot({ path: options.screenshot });
polling = false;
await pollLive;
await browser.close();

const sameMultiset = (a, b) => JSON.stringify([...a].sort()) === JSON.stringify([...b].sort());
const liveWithin = (t, windowMs) => live.filter((s) => s.t <= t + 300 && s.t >= t - windowMs).map((s) => s.doc);
// The last snapshot answers of a panel at a sample: the one shown, or the one before while Grafana renders it (a
// response event can reach this script after the tile shows its data)
const answered = (panel, t) => snapshots[panel].filter((s) => s.t <= t + 1000).slice(-3).map((s) => s.refs);

for (const { t, panels } of samples) {
  const at = `+${t - start} ms`;
  const supervisors = pairs(panels['panel-3']);
  const captions = supervisors.map((pair) => pair.caption);
  if (new Set(captions).size !== captions.length) fail(`Supervisor Processes has duplicate fields at ${at}: ${captions.join(' | ')}`);
  for (const caption of captions) if (!/^.+ · max \d+$/.test(caption)) fail(`Supervisor Processes caption "${caption}" at ${at}`);
  const shown = supervisors.map((pair) => `${pair.caption}=${number(pair.value)}`);
  const pending = pairs(panels['panel-2']);
  if (pending.length !== 1 || !pending[0].caption.startsWith('Oldest: ')) fail(`Pending at ${at}: ${JSON.stringify(pending)}`);
  const delayed = number(lines(panels['panel-9'])[0]);
  const completed = pairs(panels['panel-8'])[0]?.caption;

  if (mode === 'poll') {
    if (!answered(3, t).some((refs) => sameMultiset(shown, (refs.S ?? []).filter((row) => row.tile === 'supervisor').map((row) => `${row.caption}=${row.value}`)))) {
      fail(`Supervisor Processes ${shown.join(', ')} at ${at} is not the last snapshot answer of the panel`);
    }
    if (!answered(2, t).some((refs) => refs.L?.[0]?.Value === number(pending[0]?.value) && refs.C?.[0]?.caption === pending[0]?.caption)) {
      fail(`Pending ${pending[0]?.value} "${pending[0]?.caption}" at ${at} is not the last snapshot answer of the panel`);
    }
    if (!answered(9, t).some((refs) => refs.L?.[0]?.Value === delayed)) fail(`Delayed ${delayed} at ${at} is not the last snapshot answer of the panel`);
    if (!answered(8, t).some((refs) => refs.C?.[0]?.caption === completed)) fail(`Completed caption "${completed}" at ${at} is not the last snapshot answer of the panel`);
  } else {
    if (!liveWithin(t, 2500).some((doc) => sameMultiset(shown, doc.supervisors.map((s) => `${s.caption}=${s.processes}`)))) {
      fail(`Supervisor Processes ${shown.join(', ')} at ${at} matches no /live answer of the last 2.5 s`);
    }
    if (!liveWithin(t, 2500).some((doc) => doc.pending.value === number(pending[0]?.value) && doc.pending.caption === pending[0]?.caption)) {
      fail(`Pending ${pending[0]?.value} "${pending[0]?.caption}" at ${at} matches no /live answer of the last 2.5 s`);
    }
    if (!liveWithin(t, 2500).some((doc) => doc.delayed.value === delayed)) fail(`Delayed ${delayed} at ${at} matches no /live answer of the last 2.5 s`);
    if (!liveWithin(t, refreshMs + 1500).some((doc) => doc.throughput.caption === completed)) {
      fail(`Completed caption "${completed}" at ${at} matches no /live answer of the last ${refreshMs + 1500} ms`);
    }
  }
}

if (mode === 'poll') {
  for (const panel of [2, 3, 8, 9]) {
    const during = snapshots[panel].filter((s) => s.t >= start);
    // Every refresh queries the snapshot again
    if (during.length < Math.floor(duration / refreshMs) - 1) fail(`panel ${panel}: ${during.length} snapshot answers in ${duration / 1000} s at a ${refreshMs} ms refresh`);
    for (const { t, refs } of during) {
      const time = refs.L?.[0]?.Time;
      if (time !== undefined && (time > t + 1000 || time < t - 12000)) fail(`panel ${panel}: the snapshot of the answer at +${t - start} ms is ${t - time} ms old`);
    }
  }
}

const gaps = (times) => times.slice(1).map((time, i) => time - times[i]);
const tileGaps = gaps(frames.filter((time) => time >= start));
if (mode === 'stream' && (tileGaps.length < duration / 2000 - 1 || Math.max(...tileGaps) > 2000)) {
  fail(`${tileGaps.length + 1} tiles frames in ${duration / 1000} s, largest gap ${Math.max(...tileGaps)} ms`);
}

const changes = (key) => {
  const times = [];
  let previous = null;
  for (const { t, panels } of samples) {
    const value = pairs(panels[key])[0]?.value;
    if (previous !== null && value !== previous) times.push(t);
    previous = value;
  }
  return times;
};
const pendingChanges = changes('panel-2');
if (options.growing && mode === 'stream') {
  const longest = Math.max(...gaps([start, ...pendingChanges, samples.at(-1).t]));
  if (longest > 2000) fail(`the stream Pending tile did not change for ${longest} ms`);
}
if (options.growing && mode === 'poll' && pendingChanges.length < Math.floor(duration / refreshMs) - 1) {
  fail(`the poll Pending tile changed ${pendingChanges.length} times in ${duration / 1000} s at a ${refreshMs} ms refresh`);
}
if (samples.length < duration / 500) fail(`only ${samples.length} samples`);
if (live.length < duration / 1000) fail(`only ${live.length} /live answers`);

const last = samples.at(-1)?.panels ?? {};
console.log(JSON.stringify({
  grafana,
  uid,
  mode,
  refreshMs,
  samples: samples.length,
  liveAnswers: live.length,
  snapshotAnswers: Object.fromEntries(Object.entries(snapshots).map(([panel, list]) => [panel, list.length])),
  tilesFrames: frames.length,
  largestTilesGapMs: tileGaps.length ? Math.max(...tileGaps) : null,
  pendingChanges: pendingChanges.length,
  last: Object.fromEntries(['panel-3', 'panel-2', 'panel-8', 'panel-9'].map((key) => [key, (last[key] ?? '').replace(/\n/g, ' | ')])),
  errors: errors.slice(0, 5),
  failures: failures.slice(0, 20),
  failureCount: failures.length,
}, null, 1));
process.exit(failures.length === 0 ? 0 : 1);
