import { chromium } from '/opt/node-tools/node_modules/playwright/index.mjs';
const [url, out, w = '1920', h = '1400', wait = '8000'] = process.argv.slice(2);
const browser = await chromium.launch();
const page = await browser.newPage({ locale: 'en-US', timezoneId: 'UTC', viewport: { width: +w, height: +h } });
await page.goto(url, { waitUntil: 'networkidle', timeout: 90000 });
await page.waitForTimeout(+wait);
await page.screenshot({ path: out });
await browser.close();
