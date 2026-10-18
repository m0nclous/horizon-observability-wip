#!/usr/bin/env node
// The browser half of tests/e2e/first-dashboard.sh (owner decision 12, T14): waits until the Overview tiles of the
// provisioned dashboard show values, as a newcomer sees them, and prints a JSON report.
//
//   node tests/e2e/first-dashboard.mjs --grafana http://localhost:3000 [--uid horizon-observability]
//        [--timeout 240] [--screenshot build/first-dashboard.png]
//
// A tile counts when it shows a value (not "No data", "Live unavailable" or the em dash of an unknown value) and its
// live caption has replaced the series name; the Completed tile must count more than 0 (the demo jobs went through
// Horizon, the exporter and Prometheus). Grafana's home page must be the provisioned dashboard itself (uid --uid, or a
// redirect to it), not a copy, so the alert annotations linked to that uid show there too. Exit 0 when every Overview
// tile does within --timeout seconds and the home page is right, 1 otherwise (with the last texts), 2 without
// Playwright (`npm i --no-save playwright@1.56.1 && npx playwright install chromium`).

const options = {};
for (let i = 2; i < process.argv.length; i++) {
  const [key, inline] = process.argv[i].replace(/^--/, '').split(/=(.*)/s, 2);
  options[key] = inline !== undefined ? inline : process.argv[++i];
}
const grafana = (options.grafana || 'http://localhost:3000').replace(/\/$/, '');
const uid = options.uid || 'horizon-observability';
const deadline = Date.now() + Number(options.timeout || 240) * 1000;

let chromium;
for (const base of [process.env.PLAYWRIGHT_DIR, process.cwd()].filter(Boolean)) {
  try {
    ({ chromium } = await import(`${base}/node_modules/playwright/index.mjs`));
    break;
  } catch {}
}
if (!chromium) {
  console.error('Playwright is missing: npm i --no-save playwright@1.56.1 && npx playwright install chromium');
  process.exit(2);
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const started = Date.now();

// 1. Grafana answers and has provisioned the dashboard.
let dashboard = null;
while (Date.now() < deadline) {
  try {
    const response = await fetch(`${grafana}/api/dashboards/uid/${uid}`);
    if (response.ok) {
      dashboard = (await response.json()).dashboard;
      break;
    }
  } catch {}
  await sleep(1000);
}
if (!dashboard) {
  console.log(JSON.stringify({ ok: false, reason: `no dashboard ${uid} at ${grafana}` }));
  process.exit(1);
}
const provisioned = Date.now();

// 2. The tiles of the first row (Overview or Live Overview): the stat panels until the next row.
const panels = dashboard.panels ?? [];
const firstRow = panels.findIndex((panel) => panel.type === 'row');
const nextRow = panels.findIndex((panel, index) => index > firstRow && panel.type === 'row');
const tiles = panels
  .slice(firstRow + 1, nextRow === -1 ? undefined : nextRow)
  .filter((panel) => panel.type === 'stat')
  .map((panel) => ({ id: panel.id, title: panel.title }));

const browser = await chromium.launch();
const page = await browser.newPage({ viewport: { width: 1920, height: 1200 }, locale: 'en-US', timezoneId: 'UTC' });
await page.goto(`${grafana}/d/${uid}?orgId=1&from=now-15m&to=now&kiosk`, { waitUntil: 'domcontentloaded', timeout: 90000 });

const read = () => page.$$eval('[data-viz-panel-key]', (elements) => Object.fromEntries(
  elements.map((element) => [element.getAttribute('data-viz-panel-key'), element.innerText]),
)).catch(() => ({}));

// A stat panel's text is its title, then caption and value lines.
const values = (text, title) => (text ?? '').split('\n').map((line) => line.trim()).filter((line) => line !== '' && line !== title);
const number = (line) => Number(String(line).replace(/[,\s]/g, ''));

let last = {};
let ok = false;
while (Date.now() < deadline) {
  last = await read();
  const states = tiles.map((tile) => {
    const lines = values(last[`panel-${tile.id}`], tile.title);
    // Not yet (or not) rendered: nothing, "No data", the em dash of an unknown value, or a series named after its
    // query, which a stat shows while its live caption has not arrived.
    const empty = lines.length === 0
      || lines.some((line) => /No data|Live unavailable|[{}]|=~/.test(line))
      || lines[lines.length - 1] === '\u2014';
    const completed = tile.title === 'Completed' ? lines.some((line) => number(line) > 0) : true;
    return !empty && completed;
  });
  if (tiles.length > 0 && states.every(Boolean)) {
    ok = true;
    break;
  }
  await sleep(1000);
}

if (options.screenshot) {
  await page.screenshot({ path: options.screenshot, fullPage: false }).catch(() => {});
}
await browser.close();

// 3. The home page: the stack sets the organisation's home dashboard once Grafana answers (a redirect to /d/<uid>/).
let home = null;
const homeDeadline = Math.max(deadline, Date.now() + 30000);
while (Date.now() < homeDeadline) {
  try {
    const answer = await (await fetch(`${grafana}/api/dashboards/home`)).json();
    home = answer.redirectUri ?? answer.dashboard?.uid ?? null;
    if (home === uid || String(home).startsWith(`/d/${uid}/`)) {
      break;
    }
  } catch {}
  await sleep(1000);
}
const homeOk = home === uid || String(home).startsWith(`/d/${uid}/`);

const report = {
  ok: ok && homeOk,
  dashboard: uid,
  home,
  tiles: Object.fromEntries(tiles.map((tile) => [tile.title, values(last[`panel-${tile.id}`], tile.title)])),
  provisionedAfterSeconds: Math.round((provisioned - started) / 100) / 10,
  tilesAfterSeconds: Math.round((Date.now() - started) / 100) / 10,
};
console.log(JSON.stringify(report, null, 2));
process.exit(ok && homeOk ? 0 : 1);
