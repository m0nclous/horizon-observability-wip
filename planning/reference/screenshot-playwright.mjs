import { chromium } from 'playwright';
const [url, out, w, h] = process.argv.slice(2);
const browser = await chromium.launch();
const page = await browser.newPage({ locale: 'en-US', timezoneId: 'UTC', viewport: { width: +w, height: +h } });
const errors = [];
page.on('pageerror', e => errors.push('pageerror: ' + e.message));
page.on('console', m => { if (m.type() === 'error') errors.push(m.text().slice(0, 200)); });
await page.goto(url, { waitUntil: 'networkidle', timeout: 90000 });
for (const title of ['Details · Jobs', 'Details · Batches']) {
  const row = page.getByText(title, { exact: true }).first();
  if (await row.count()) { await row.click(); await page.waitForTimeout(1500); }
}
await page.waitForLoadState('networkidle');
await page.waitForTimeout(8000);
await page.screenshot({ path: out });
console.log(JSON.stringify({ errors: errors.slice(0, 10) }, null, 1));
await browser.close();
