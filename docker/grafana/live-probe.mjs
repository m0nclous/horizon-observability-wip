#!/usr/bin/env node
// End-to-end check of the live modes with docker/grafana/live-probe.json (ADDENDUM R4.8, T12). Export-ignored dev tool.
//
//   node docker/grafana/live-probe.mjs [--grafana http://localhost:3000] [--app http://localhost:8000]
//                                      [--token workbench-token-0123456789abcdef] [--seconds 20] [--growing]
//                                      [--stream horizon_observability] [--screenshot build/live-probe.png]
//
// Needs the dev stack with the probe dashboard provisioned (mount this directory's live-probe.json into
// /etc/grafana/dashboards/horizon-observability, or import it), the app on --app with Horizon and the load
// simulation, Prometheus scraping it, and `horizon-observability:stream` pushing to this Grafana (bootstrap-live.sh).
// It samples the probe's tiles four times a second for --seconds (default 20 s, at least three dashboard refreshes
// of 5 s) while it polls GET {app}/horizon/observability/live, and fails (exit 1) when
// - a poll or stream Supervisor Processes tile shows a duplicate field or another number of supervisors than /live;
// - a Pending tile has more than one field or no "Oldest: ..." caption;
// - the poll Pending value or the Completed caption matches no /live answer of the last refresh (6.5 s);
// - the stream Pending value matches no /live answer of the last 2.5 s;
// - two Grafana Live frames of the tiles channel are more than 2 s apart;
// - with --growing (pause Horizon first: `testbench horizon:pause`, so the queues grow), the stream Pending tile
//   stays unchanged for more than 2 s, or the poll Pending tile does not change at each refresh.
// It prints a JSON report. Needs `npm i --no-save playwright@1.56.1 && npx playwright install chromium`.

const options = { growing: false };
for (let i = 2; i < process.argv.length; i++) {
  const [key, inline] = process.argv[i].replace(/^--/, '').split(/=(.*)/s, 2);
  options[key] = key === 'growing' ? true : inline !== undefined ? inline : process.argv[++i];
}
const grafana = (options.grafana || `http://localhost:${process.env.GRAFANA_PORT || 3000}`).replace(/\/$/, '');
const app = (options.app || 'http://localhost:8000').replace(/\/$/, '');
const token = options.token || 'workbench-token-0123456789abcdef';
const duration = Number(options.seconds || 20) * 1000;
const stream = options.stream || 'horizon_observability';

let chromium;
try {
  ({ chromium } = await import('playwright'));
} catch {
  console.error('Playwright is missing: npm i --no-save playwright@1.56.1 && npx playwright install chromium');
  process.exit(2);
}

const failures = [];
const fail = (message) => failures.push(message);
const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1920, height: 1080 }, locale: 'en-US', timezoneId: 'UTC' });
const frames = [];
page.on('websocket', (ws) => ws.on('framereceived', (frame) => {
  if (String(frame.payload).includes(`stream/${stream}/tiles`)) frames.push(Date.now());
}));

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
));

// A stat panel's text: the title, then caption and value lines (value_and_name) or one value.
const fields = (text) => {
  const lines = (text ?? '').split('\n').map((line) => line.trim()).filter((line) => line !== '');
  lines.shift();
  const pairs = [];
  for (let i = 0; i + 1 < lines.length; i += 2) pairs.push({ caption: lines[i], value: lines[i + 1] });
  return pairs;
};
const number = (value) => (value === undefined || value === '—' ? null : Number(String(value).replace(/,/g, '')));

await page.goto(`${grafana}/d/horizon-observability-live-probe?orgId=1&kiosk`, { waitUntil: 'networkidle', timeout: 90000 });

// The first render of every live tile (a cold Grafana starts the Infinity backend on the first query).
const ready = Date.now();
while (Date.now() - ready < 30000) {
  const panels = await readPanels();
  if (['panel-103', 'panel-203'].every((key) => fields(panels[key]).length > 0) && ['panel-102', 'panel-202'].every((key) => fields(panels[key]).length === 1)) break;
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

const liveWithin = (t, windowMs) => live.filter((s) => s.t <= t + 300 && s.t >= t - windowMs).map((s) => s.doc);
for (const { t, panels } of samples) {
  const at = `+${t - start} ms`;
  for (const key of ['panel-103', 'panel-203']) {
    const captions = fields(panels[key]).map((pair) => pair.caption);
    const counts = liveWithin(t, 6500).map((doc) => doc.supervisors.length);
    if (new Set(captions).size !== captions.length) fail(`${key} has duplicate fields at ${at}: ${captions.join(' | ')}`);
    if (!counts.includes(captions.length)) fail(`${key} shows ${captions.length} supervisors at ${at}, /live ${[...new Set(counts)].join('/')}`);
    for (const caption of captions) if (!/^.+ · max \d+$/.test(caption)) fail(`${key} caption "${caption}" at ${at}`);
  }
  for (const key of ['panel-102', 'panel-202']) {
    const pairs = fields(panels[key]);
    if (pairs.length !== 1 || !pairs[0].caption.startsWith('Oldest: ')) fail(`${key} at ${at}: ${JSON.stringify(pairs)}`);
  }
  const pollPending = number(fields(panels['panel-102'])[0]?.value);
  if (!liveWithin(t, 6500).some((doc) => doc.pending.value === pollPending)) fail(`poll Pending ${pollPending} at ${at} matches no /live answer of the last 6.5 s`);
  const completed = fields(panels['panel-108'])[0]?.caption;
  if (!liveWithin(t, 6500).some((doc) => doc.throughput.caption === completed)) fail(`poll Completed caption "${completed}" at ${at} matches no /live answer`);
  const streamPending = number(fields(panels['panel-202'])[0]?.value);
  if (!liveWithin(t, 2500).some((doc) => doc.pending.value === streamPending)) fail(`stream Pending ${streamPending} at ${at} matches no /live answer of the last 2.5 s`);
}

const gaps = (times) => times.slice(1).map((t, i) => t - times[i]);
const tileGaps = gaps(frames.filter((t) => t >= start));
if (frames.length < duration / 2000 || Math.max(...tileGaps) > 2000) fail(`${frames.length} tiles frames in ${duration / 1000} s, largest gap ${Math.max(...tileGaps)} ms`);

const changes = (key) => {
  const times = [];
  let previous = null;
  for (const { t, panels } of samples) {
    const value = fields(panels[key])[0]?.value;
    if (previous !== null && value !== previous) times.push(t);
    previous = value;
  }
  return times;
};
const streamChanges = changes('panel-202');
const pollChanges = changes('panel-102');
if (options.growing) {
  const longest = Math.max(...gaps([start, ...streamChanges, samples.at(-1).t]));
  if (longest > 2000) fail(`the stream Pending tile did not change for ${longest} ms`);
  if (pollChanges.length < Math.floor(duration / 5000) - 1) fail(`the poll Pending tile changed ${pollChanges.length} times in ${duration / 1000} s`);
}

console.log(JSON.stringify({
  grafana,
  samples: samples.length,
  liveAnswers: live.length,
  tilesFrames: frames.length,
  largestTilesGapMs: Math.max(...tileGaps),
  streamPendingChanges: streamChanges.length,
  pollPendingChanges: pollChanges.length,
  last: Object.fromEntries(Object.entries(samples.at(-1)?.panels ?? {}).map(([key, text]) => [key, text.replace(/\n/g, ' | ')])),
  failures: failures.slice(0, 20),
  failureCount: failures.length,
}, null, 1));
process.exit(failures.length === 0 ? 0 : 1);
