#!/usr/bin/env node
// Screenshots of the Grafana dashboard for the look review (Arch 8.3). Export-ignored dev tool.
//
//   node bin/screenshot.mjs [--url <dashboard url>] [--grafana http://localhost:3000] [--uid horizon-observability]
//                           [--from now-1h] [--out build/screenshots] [--name dashboard] [--width 1920] [--height 3600]
//                           [--wait 8]
//
// Headless Chromium (Playwright) with locale en-US (otherwise Grafana fails with "Invalid language tag") and timezone
// UTC, viewport 1920x3600, kiosk mode. After network idle plus --wait seconds it writes <out>/<name>-overview.png
// (the dashboard down to the collapsed Details rows), then expands "Details · Jobs" and "Details · Batches", waits
// again and writes <name>-details.png (the viewport from the first Details row) and <name>-full.png (the expanded
// dashboard from the top): the views of planning/baseline/dashboard-*.png. Run the load simulation for at least
// 15 minutes first. Exits 1 when the dashboard shows an error or no panel.
// Needs `npm i --no-save playwright@1.56.1 && npx playwright install chromium`.
import { mkdirSync } from 'node:fs';

const options = {};
for (let i = 2; i < process.argv.length; i++) {
  const [key, inline] = process.argv[i].replace(/^--/, '').split(/=(.*)/s, 2);
  options[key] = inline !== undefined ? inline : process.argv[++i];
}
const grafana = (options.grafana || `http://localhost:${process.env.GRAFANA_PORT || 3000}`).replace(/\/$/, '');
const url = options.url || `${grafana}/d/${options.uid || 'horizon-observability'}?orgId=1&from=${options.from || 'now-1h'}&to=now&kiosk`;
const out = options.out || 'build/screenshots';
const name = options.name || 'dashboard';
const width = Number(options.width || 1920);
const height = Number(options.height || 3600);

let chromium;
try {
  ({ chromium } = await import('playwright'));
} catch {
  console.error('Playwright is missing: npm i --no-save playwright@1.56.1 && npx playwright install chromium');
  process.exit(2);
}

mkdirSync(out, { recursive: true });
const browser = await chromium.launch();
const page = await browser.newPage({ locale: 'en-US', timezoneId: 'UTC', viewport: { width, height } });
const errors = [];
page.on('pageerror', (e) => errors.push(`pageerror: ${e.message}`));
page.on('console', (m) => { if (m.type() === 'error') errors.push(m.text().slice(0, 200)); });
await page.goto(url, { waitUntil: 'networkidle', timeout: 90000 });

await page.waitForTimeout(Number(options.wait || 8) * 1000);
const files = [`${out}/${name}-full.png`, `${out}/${name}-overview.png`, `${out}/${name}-details.png`];
const panels = await page.locator('[data-viz-panel-key], .react-grid-item').count();
const failures = await page.getByText(/Dashboard not found|Panel plugin not found|Failed to load/i).count();

// Overview: the collapsed dashboard down to the first Details row.
let split = Math.min(height, 1093);
const rows = [];
for (const title of ['Details · Jobs', 'Details · Batches']) {
  const row = page.getByText(title, { exact: true }).first();
  // Rows render after the first network idle; wait for them before deciding that a row is missing.
  if (await row.waitFor({ timeout: 15000 }).then(() => true, () => false)) rows.push(row);
}
if (rows.length) {
  const box = await rows[0].boundingBox();
  if (box) split = Math.max(1, Math.floor(box.y) - 12);
}
await page.screenshot({ path: files[1], clip: { x: 0, y: 0, width, height: split } });

// Details: both rows expanded, the first one at the top of the viewport.
for (const row of rows) {
  await row.click();
  await page.waitForTimeout(1500);
}
await page.waitForLoadState('networkidle');
await page.waitForTimeout(Number(options.wait || 8) * 1000);
if (rows.length) {
  await rows[0].scrollIntoViewIfNeeded();
  await page.mouse.move(width / 2, height / 2);
}
await page.screenshot({ path: files[2] });

// Full: the expanded dashboard from the top.
await page.mouse.move(width / 2, height / 2);
await page.mouse.wheel(0, -100000);
await page.evaluate(() => {
  window.scrollTo(0, 0);
  for (const el of document.querySelectorAll('*')) if (el.scrollTop > 0) el.scrollTop = 0;
});
await page.waitForTimeout(1500);
await page.screenshot({ path: files[0] });
await browser.close();

console.log(JSON.stringify({ url, files, panels, failures, errors: errors.slice(0, 10) }, null, 1));
process.exit(panels > 0 && failures === 0 ? 0 : 1);
