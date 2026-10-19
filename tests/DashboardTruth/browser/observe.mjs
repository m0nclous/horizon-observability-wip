#!/usr/bin/env node
// The capture stage of the dashboard truth suite (T17, design sections 5 and 10): for every case of the plan, one
// real page load in Chromium against one Grafana, with the browser's clock fixed at the dataset's end V.
//
//   node tests/DashboardTruth/browser/observe.mjs --plan build/dashboard-truth/<run>/plan.json --grafana 13 \
//        --out build/dashboard-truth/<run>/observations/13 [--cases S01,S02]
//
// Per case: a context at the case's width x 5000 (lazy panels stay unloaded at 1080), locale en-US and the case's
// time zone (the dashboard's is `browser`); `page.clock.setFixedTime(V)` (timers keep running; `refresh=1h` in the
// URL stops auto-refresh, design X8); both collapsed rows clicked open; then the completion criterion:
// - every (panel, refId) of the render's non-repeated query panels has an answer (`X-Panel-Id`);
// - every scene panel's data state is Done (repeat clones included: their ids are hashes, design 5.8);
// - no request has been open for 700 ms.
// Otherwise the case is written with `complete: false` after 90 s, which the assert stage reports as an
// infrastructure failure, never as a pass.
//
// The observation (schema tests/DashboardTruth/Contracts/schema/observation.schema.json) holds every /api/ds/query
// request body and response (with `meta.executedQueryString` and the frame grids), the scene variables (value, text,
// options), the scene panels (data state, request panel id, the transformed frames of the stat panels), the DOM of
// every panel (its text lines and table rows), the router log lines of the page load, page errors and timings. Only cycle 0, the first load, is
// captured.
//
// Needs Playwright 1.56.1 in ./node_modules (`npm i --no-save --no-package-lock playwright@1.56.1`) and Chromium.

import { appendFileSync, existsSync, mkdirSync, readFileSync, statSync, writeFileSync } from 'node:fs';
import { join } from 'node:path';

const options = {};
for (let i = 2; i < process.argv.length; i++) {
  const [key, inline] = process.argv[i].replace(/^--/, '').split(/=(.*)/s, 2);
  options[key] = inline !== undefined ? inline : process.argv[++i];
}
for (const required of ['plan', 'grafana', 'out']) {
  if (!options[required]) {
    console.error(`usage: observe.mjs --plan <plan.json> --grafana <12|13> --out <dir> [--cases a,b]`);
    process.exit(2);
  }
}

const COMPLETE_TIMEOUT_MS = 90000;
const QUIET_MS = 700;

let chromium;
try {
  ({ chromium } = await import(join(process.cwd(), 'node_modules/playwright/index.mjs')));
} catch {
  console.error('Playwright is missing: npm i --no-save --no-package-lock playwright@1.56.1 && npx playwright install chromium');
  process.exit(2);
}

const plan = JSON.parse(readFileSync(options.plan, 'utf8'));
const grafana = plan.grafanas[options.grafana];
if (!grafana) {
  console.error(`The plan has no Grafana ${options.grafana}`);
  process.exit(2);
}
const only = options.cases ? new Set(options.cases.split(',')) : null;
mkdirSync(options.out, { recursive: true });

const routerLog = plan.routerLog;
const routerSize = () => (routerLog && existsSync(routerLog) ? statSync(routerLog).size : 0);
const routerLines = (from) => {
  if (!routerLog || !existsSync(routerLog)) return [];
  const text = readFileSync(routerLog).subarray(from).toString('utf8');
  return text.split('\n').filter((line) => line.trim() !== '').map((line) => JSON.parse(line));
};

const browser = await chromium.launch();
let failures = 0;

for (const testCase of plan.cases) {
  if (only && !only.has(testCase.id)) continue;
  const started = Date.now();
  const observation = await observe(testCase);
  observation.timing.totalMs = Date.now() - started;
  writeFileSync(join(options.out, `${testCase.id}.json`), JSON.stringify(observation));
  if (!observation.complete) failures++;
  console.log(`grafana ${options.grafana} ${testCase.id}: ${observation.complete ? 'complete' : 'INCOMPLETE ' + observation.incomplete.join('; ')}, ${observation.requests.length} requests, ${observation.timing.totalMs} ms`);
}

await browser.close();
process.exit(failures > 0 ? 1 : 0);

async function observe(testCase) {
  const context = await browser.newContext({
    viewport: { width: testCase.width, height: testCase.height },
    locale: 'en-US',
    timezoneId: testCase.timezone,
  });
  const page = await context.newPage();
  await page.clock.setFixedTime(testCase.fakeNow);

  const requests = [];
  const errors = [];
  let open = 0;
  let lastActivity = Date.now();
  let seq = 0;
  const pending = new Map();

  page.on('pageerror', (error) => errors.push(`pageerror: ${error.message}`));
  page.on('request', (request) => {
    const url = request.url();
    if (!url.startsWith(grafana.url) || !new URL(url).pathname.startsWith('/api/')) return;
    open++;
    lastActivity = Date.now();
    if (!url.includes('/api/ds/query')) return;
    const headers = request.headers();
    const entry = {
      seq: ++seq,
      panelId: headers['x-panel-id'] ?? null,
      panelTitle: headers['x-panel-title'] ?? null,
      pluginId: headers['x-panel-plugin-id'] ?? null,
      dashboardUid: headers['x-dashboard-uid'] ?? null,
      dsType: new URL(url).searchParams.get('ds_type'),
      url: url.slice(grafana.url.length),
      body: safeJson(request.postData()),
      status: null,
      response: null,
      error: null,
    };
    requests.push(entry);
    pending.set(request, entry);
  });
  const settle = async (request, failed) => {
    const url = request.url();
    if (!url.startsWith(grafana.url) || !new URL(url).pathname.startsWith('/api/')) return;
    const entry = pending.get(request);
    if (entry) {
      pending.delete(request);
      if (failed) {
        entry.error = request.failure()?.errorText ?? 'failed';
      } else {
        try {
          const response = await request.response();
          entry.status = response ? response.status() : null;
          entry.response = response ? safeJson(await response.text()) : null;
        } catch (error) {
          entry.error = error.message;
        }
      }
    }
    open--;
    lastActivity = Date.now();
  };
  page.on('requestfinished', (request) => settle(request, false));
  page.on('requestfailed', (request) => settle(request, true));

  const routerFrom = routerSize();
  const url = `${grafana.url}${testCase.path}`;
  const timing = {};
  const t0 = Date.now();
  await page.goto(url, { waitUntil: 'load', timeout: 60000 });
  timing.loadMs = Date.now() - t0;

  // The collapsed rows ("Details · Jobs", "Details · Batches"): the toggle is `dashboard-row-toggle-for-<title>`
  // on 13.2.4 and `dashboard-row-title-<title>` on 12.4.13; the scene says whether the row is still collapsed
  const rows = [];
  const deadline = Date.now() + COMPLETE_TIMEOUT_MS;
  for (const title of testCase.rows) {
    const toggle = page.locator(`[data-testid="data-testid dashboard-row-toggle-for-${title}"], [data-testid="data-testid dashboard-row-title-${title}"]`).first();
    while ((await toggle.count()) === 0 && Date.now() < deadline) await page.waitForTimeout(100);
    if ((await toggle.count()) === 0) {
      errors.push(`row "${title}" not found`);
      continue;
    }
    if ((await rowCollapsed(page, title)) !== false) await toggle.click();
    while ((await rowCollapsed(page, title)) !== false && Date.now() < deadline) await page.waitForTimeout(100);
    rows.push({ title, expanded: (await rowCollapsed(page, title)) === false });
  }

  const awaited = testCase.panels.filter((panel) => panel.queried && !panel.repeat);
  // Phase 1 on the fixed clock: every query panel answered and the network quiet. Phase 2: the clock is released to
  // run on from V (`setSystemTime`), because a fixed Date.now() stalls the `-- Dashboard --` datasource of #5 for good
  // (its rxjs timing never elapses); every query was sent on the fixed clock, the requests after the release are
  // recorded and checked by the assert stage.
  let incomplete = [];
  let released = null;
  while (true) {
    const scene = await scenePanels(page);
    incomplete = [];
    for (const panel of awaited) {
      const answered = new Set();
      for (const entry of requests) {
        if (entry.panelId !== String(panel.id) || !entry.response) continue;
        for (const refId of Object.keys(entry.response.results ?? {})) answered.add(refId);
      }
      const missing = panel.refIds.filter((refId) => !answered.has(refId));
      if (missing.length > 0) incomplete.push(`panel ${panel.id} ${missing.join(',')} unanswered`);
    }
    if (scene === null) incomplete.push('no __grafanaSceneContext');
    const quiet = open === 0 && Date.now() - lastActivity >= QUIET_MS;
    if (released === null && incomplete.length === 0 && quiet) {
      released = { atMs: Date.now() - t0, requests: requests.length };
      await page.clock.setSystemTime(testCase.fakeNow);
      lastActivity = Date.now();
      continue;
    }
    if (released === null) incomplete.push('clock not released yet');
    for (const panel of scene ?? []) {
      if (panel.dataState !== null && panel.dataState !== 'Done') incomplete.push(`${panel.key} ${panel.dataState}`);
    }
    if (open > 0) incomplete.push(`${open} requests open`);
    else if (Date.now() - lastActivity < QUIET_MS) incomplete.push('quiet period');
    if (incomplete.length === 0 || Date.now() > deadline) break;
    await page.waitForTimeout(100);
  }
  timing.completeMs = Date.now() - t0;

  const scene = await sceneState(page);
  const dom = await readDom(page);
  if (options.screenshots) {
    mkdirSync(options.screenshots, { recursive: true });
    await page.screenshot({ path: join(options.screenshots, `${options.grafana}-${testCase.id}.png`), fullPage: false });
  }
  await context.close();

  return {
    schema: 1,
    case: testCase.id,
    grafana: options.grafana,
    grafanaVersion: grafana.version,
    url,
    fakeNow: testCase.fakeNow,
    complete: incomplete.length === 0,
    incomplete,
    rows,
    released,
    requests: requests.sort((a, b) => a.seq - b.seq),
    variables: scene.variables,
    panels: scene.panels,
    dom,
    router: routerLines(routerFrom),
    errors,
    timing,
  };
}

function safeJson(text) {
  if (text === null || text === undefined) return null;
  try {
    return JSON.parse(text);
  } catch {
    return { unparsed: String(text).slice(0, 2000) };
  }
}

// The scene's VizPanels (clones included) with their data state: a walk over the scene graph from the debug hook
// window.__grafanaSceneContext (present on 12.4.13 and 13.2.4, design X10).
async function scenePanels(page) {
  return page.evaluate(() => {
    const root = window.__grafanaSceneContext;
    if (!root) return null;
    const seen = new Set();
    const panels = [];
    const walk = (object, depth) => {
      if (!object || typeof object !== 'object' || seen.has(object) || depth > 60) return;
      seen.add(object);
      if (Array.isArray(object)) return object.forEach((item) => walk(item, depth + 1));
      const state = object.state;
      if (!state || typeof state !== 'object') return;
      if (typeof state.pluginId === 'string' && typeof state.key === 'string' && state.key.startsWith('panel-')) {
        panels.push({ key: state.key, dataState: state.$data?.state?.data?.state ?? null });
      }
      for (const value of Object.values(state)) walk(value, depth + 1);
    };
    walk(root, 0);
    return panels;
  });
}

// Whether a row of the scene is collapsed (12.4.13 SceneGridRow `isCollapsed`, 13.2.4 row `collapse`), null when
// the scene has no such row.
async function rowCollapsed(page, title) {
  return page.evaluate((title) => {
    const root = window.__grafanaSceneContext;
    if (!root) return null;
    const seen = new Set();
    let found = null;
    const walk = (object, depth) => {
      if (found !== null || !object || typeof object !== 'object' || seen.has(object) || depth > 60) return;
      seen.add(object);
      if (Array.isArray(object)) return object.forEach((item) => walk(item, depth + 1));
      const state = object.state;
      if (!state || typeof state !== 'object') return;
      if (state.title === title && (typeof state.isCollapsed === 'boolean' || typeof state.collapse === 'boolean')) {
        found = typeof state.isCollapsed === 'boolean' ? state.isCollapsed : state.collapse;
        return;
      }
      for (const value of Object.values(state)) walk(value, depth + 1);
    };
    walk(root, 0);
    return found;
  }, title);
}

async function sceneState(page) {
  return page.evaluate(() => {
    const root = window.__grafanaSceneContext;
    if (!root) return { variables: [], panels: [] };
    const plain = (value) => (value === undefined ? null : JSON.parse(JSON.stringify(value)));
    const variables = (root.state.$variables?.state?.variables ?? []).map((variable) => ({
      name: variable.state.name,
      type: variable.state.type,
      value: plain(variable.state.value),
      text: plain(variable.state.text),
      isMulti: variable.state.isMulti ?? null,
      includeAll: variable.state.includeAll ?? null,
      options: (variable.state.options ?? []).map((option) => ({ text: plain(option.label ?? option.text), value: plain(option.value) })),
    }));
    const seen = new Set();
    const panels = [];
    const frames = (data) => (data?.series ?? []).map((frame) => ({
      refId: frame.refId ?? null,
      name: frame.name ?? null,
      fields: frame.fields.map((field) => ({
        name: field.name,
        type: field.type,
        labels: plain(field.labels ?? null),
        displayName: field.state?.displayName ?? field.config?.displayName ?? null,
        values: Array.from(field.values ?? []).map((value) => (typeof value === 'number' && !Number.isFinite(value) ? String(value) : value)),
      })),
    }));
    const walk = (object, depth) => {
      if (!object || typeof object !== 'object' || seen.has(object) || depth > 60) return;
      seen.add(object);
      if (Array.isArray(object)) return object.forEach((item) => walk(item, depth + 1));
      const state = object.state;
      if (!state || typeof state !== 'object') return;
      if (typeof state.pluginId === 'string' && typeof state.key === 'string' && state.key.startsWith('panel-')) {
        const data = state.$data?.state?.data;
        panels.push({
          key: state.key,
          pluginId: state.pluginId,
          title: state.title ?? null,
          dataState: data?.state ?? null,
          requestPanelId: data?.request?.panelId ?? null,
          errors: (data?.errors ?? []).map((error) => error.message ?? String(error)),
          transformed: state.pluginId === 'stat' ? frames(data) : null,
        });
      }
      for (const value of Object.values(state)) walk(value, depth + 1);
    };
    walk(root, 0);
    return { variables, panels };
  });
}

// The DOM of every panel as a user sees it: the text lines, the lines of every table row (`role="row"`, the header
// row included, in display order), the title, the sparkline and the error and no-data indicators.
async function readDom(page) {
  return page.$$eval('[data-viz-panel-key]', (elements) => Object.fromEntries(elements.map((element) => {
    const key = element.getAttribute('data-viz-panel-key');
    const split = (text) => (text ?? '').split('\n').map((line) => line.trim()).filter((line) => line !== '');
    const lines = split(element.innerText);
    const rows = Array.from(element.querySelectorAll('[role="row"]')).map((row) => split(row.innerText));
    const header = element.querySelector('h2');
    const error = element.querySelector('[data-testid*="Panel status error"], [data-testid*="panel-status-error"]') !== null;
    return [key, {
      key,
      panelId: element.getAttribute('data-viz-panel-id'),
      title: header ? header.innerText.trim() : null,
      lines,
      rows,
      sparkline: element.querySelector('canvas, svg path') !== null,
      error,
      noData: lines.includes('No data'),
    }];
  })));
}
