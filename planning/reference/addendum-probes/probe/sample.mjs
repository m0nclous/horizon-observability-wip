// Samples the visible text of each panel once per second.
//   node sample.mjs <url> <seconds> <out.json>
import { chromium } from '/opt/node-tools/node_modules/playwright/index.mjs';
import { writeFileSync } from 'node:fs';
const [url, secs, out] = process.argv.slice(2);
const browser = await chromium.launch();
const page = await browser.newPage({ locale: 'en-US', timezoneId: 'UTC', viewport: { width: 1920, height: 1200 } });
const ws = { frames: 0, sample: [] };
page.on('websocket', (s) => s.on('framereceived', (f) => { ws.frames++; if (ws.sample.length < 6) ws.sample.push(String(f.payload).slice(0, 400)); }));
const errors = [];
page.on('pageerror', (e) => errors.push(e.message));
await page.goto(url, { waitUntil: 'domcontentloaded' });
const samples = [];
for (let i = 0; i < +secs; i++) {
  await page.waitForTimeout(1000);
  const panels = await page.evaluate(() => [...document.querySelectorAll('[data-viz-panel-key]')].map((el) => ({
    key: el.getAttribute('data-viz-panel-key'), text: el.innerText.replace(/\s+/g, ' ').slice(0, 160) })));
  samples.push({ t: i + 1, panels });
}
await page.screenshot({ path: out.replace(/\.json$/, '.png') });
writeFileSync(out, JSON.stringify({ samples, ws, errors }, null, 1));
await browser.close();
