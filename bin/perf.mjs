#!/usr/bin/env node
// Dashboard performance measurement (ADDENDUM R2.2 steps 3-5). Export-ignored dev tool.
//
//   node bin/perf.mjs capture --url "<dashboard url>?from=now-1h&to=now&kiosk" [--seconds 70] [--out build/perf/<name>.json]
//                             [--width 1920] [--height 1080]
//   node bin/perf.mjs replay build/perf/<name>.json --prometheus http://localhost:9091 [--cycle 1] [--repeat 3]
//   node bin/perf.mjs report build/perf/*.json [--budget docker/perf/budget.json]
//                            [--expect docker/perf/reference-legacy.json --tolerance 0.25]
//
// capture  loads the dashboard in headless Chromium (Playwright, locale en-US, UTC) and records every data request
//          (/api/ds/query and datasource resource calls) with its X-Panel-Id, body and start/end times, then groups
//          them into cycles: bursts separated by >= 2 s of silence. Cycle 0 is the first load, the others are
//          auto-refreshes. Needs `npm i --no-save playwright@1.56.1 && npx playwright install chromium`.
// replay   re-runs every Prometheus target of one cycle against /api/v1/query(_range)?stats=all, three times each
//          (median wall time), substituting $__interval, $__interval_ms, $__range, $__range_s, $__range_ms and
//          $__rate_interval as Grafana's backend does, with the captured intervalMs as the step. Unlike the T01 probe
//          the step is not floored at 15 s, so dashboards at a 10 s scrape interval replay at their resolution.
//          Writes <capture>.replay.json.
// report   prints per-panel and per-target tables and the totals: refresh p50/p95 over cycles 2-4, the Overview row,
//          first load, requests and variable requests per refresh, Infinity calls, sequential PromQL ms, samples and
//          interpolated KB. Exits 1 when a --budget limit or an --expect value (within --tolerance) is missed.
//          Times are from the first request of a cycle to its last response, so the first load excludes Grafana's
//          own page boot.
//
//   node bin/perf.mjs compare build/perf/<name>.json --prometheus <url> --against <url> [--cycle 1] [--tolerance 0.1]
//                             [--lag 60]
// compare  evaluates every Prometheus target of one cycle on two Prometheus servers at the same time (now - --lag
//          seconds, the same range length) and compares the series (without `instance`) and their values within a
//          relative --tolerance. The e2e dedupe gate: one server scrapes two replicas of the application, the other
//          one replica, so a panel that does not deduplicate replicas shows doubled values. Exits 1 on a difference.
import { readFileSync, writeFileSync, mkdirSync, existsSync } from 'node:fs';
import { dirname, basename } from 'node:path';

const SILENCE_MS = 2000;
const INFINITY = 'yesoreyeram-infinity-datasource';

function parseArgs(argv) {
  const positional = [];
  const options = {};
  for (let i = 0; i < argv.length; i++) {
    const arg = argv[i];
    if (arg.startsWith('--')) {
      const [key, inline] = arg.slice(2).split(/=(.*)/s, 2);
      if (inline !== undefined) options[key] = inline;
      else if (i + 1 < argv.length && !argv[i + 1].startsWith('--')) options[key] = argv[++i];
      else options[key] = true;
    } else {
      positional.push(arg);
    }
  }
  return { positional, options };
}

function fail(message) {
  console.error(message);
  process.exit(2);
}

// Seconds of a Grafana relative range in the URL (from=now-1h&to=now), or of the first panel request.
function rangeSeconds(url, rows) {
  try {
    const from = new URL(url).searchParams.get('from') || '';
    const match = from.match(/^now-(\d+)([smhd])$/);
    if (match) return Number(match[1]) * { s: 1, m: 60, h: 3600, d: 86400 }[match[2]];
  } catch { /* not a URL */ }
  for (const row of rows) {
    try {
      const body = JSON.parse(row.body || '{}');
      if (body.from && body.to) return Math.round((Number(body.to) - Number(body.from)) / 1000);
    } catch { /* form body */ }
  }
  return null;
}

function rangeLabel(seconds) {
  if (!seconds) return 'unknown';
  if (seconds % 86400 === 0) return `${seconds / 86400}d`;
  if (seconds % 3600 === 0) return `${seconds / 3600}h`;
  if (seconds % 60 === 0) return `${seconds / 60}m`;
  return `${seconds}s`;
}

export function cycles(rows) {
  const result = [];
  let current = null;
  for (const row of [...rows].sort((a, b) => a.start - b.start)) {
    if (!current || row.start > current.lastEnd + SILENCE_MS) {
      current = { start: row.start, lastEnd: row.end, rows: [] };
      result.push(current);
    }
    current.lastEnd = Math.max(current.lastEnd, row.end);
    current.rows.push(row);
  }
  return result;
}

function datasourceTypes(body) {
  try {
    const parsed = JSON.parse(body || '{}');
    return [...new Set((parsed.queries || []).map((q) => (q.datasource && q.datasource.type) || 'unknown'))];
  } catch {
    return [];
  }
}

async function capture(options) {
  if (!options.url) fail('capture needs --url');
  const seconds = Number(options.seconds || 70);
  const width = Number(options.width || 1920);
  const height = Number(options.height || 1080);
  const out = options.out || `build/perf/capture-${Date.now()}.json`;
  let chromium;
  try {
    ({ chromium } = await import('playwright'));
  } catch {
    fail('Playwright is missing: npm i --no-save playwright@1.56.1 && npx playwright install chromium');
  }
  const browser = await chromium.launch();
  const page = await browser.newPage({ locale: 'en-US', timezoneId: 'UTC', viewport: { width, height }, ignoreHTTPSErrors: true });
  const t0 = Date.now();
  const pending = new Map();
  const done = [];
  const errors = [];
  page.on('pageerror', (e) => errors.push(`pageerror: ${e.message}`));
  page.on('request', (request) => {
    const url = request.url();
    if (!url.includes('/api/ds/query') && !url.includes('/resources')) return;
    pending.set(request, { start: Date.now() - t0, url: url.replace(/^https?:\/\/[^/]+/, ''), method: request.method(), headers: request.headers(), body: request.postData() });
  });
  const finish = async (request, failed) => {
    const entry = pending.get(request);
    if (!entry) return;
    pending.delete(request);
    entry.end = Date.now() - t0;
    entry.failed = failed;
    try {
      const response = await request.response();
      entry.status = response ? response.status() : null;
      entry.size = response ? (await response.body()).length : 0;
    } catch { /* aborted */ }
    done.push(entry);
  };
  page.on('requestfinished', (r) => finish(r, false));
  page.on('requestfailed', (r) => finish(r, true));
  await page.goto(options.url, { waitUntil: 'domcontentloaded', timeout: 90000 });
  await page.waitForTimeout(seconds * 1000);
  await browser.close();

  const rows = done.map((e) => {
    let refIds = [];
    try { refIds = (JSON.parse(e.body || '{}').queries || []).map((q) => q.refId); } catch { /* form body */ }
    const panel = e.headers['x-panel-id'] || null;
    const types = e.url.startsWith('/api/ds/query') ? datasourceTypes(e.body) : ['prometheus'];
    return {
      start: e.start, end: e.end, ms: e.end - e.start, panel,
      kind: panel ? 'panel' : (e.url.startsWith('/api/ds/query') && !types.includes('prometheus') ? 'other' : 'variable'),
      datasources: types, refIds, status: e.status ?? null, failed: e.failed, size: e.size ?? 0,
      url: e.url.slice(0, 200), body: e.body,
    };
  }).sort((a, b) => a.start - b.start);
  const result = { tool: 'bin/perf.mjs', version: 1, url: options.url, range: rangeSeconds(options.url, rows), viewport: { width, height }, capturedAt: new Date(t0).toISOString(), errors, rows };
  mkdirSync(dirname(out), { recursive: true });
  writeFileSync(out, JSON.stringify(result, null, 1));
  console.log(JSON.stringify({ out, cycles: cycles(rows).map((c) => ({ start: c.start, duration: c.lastEnd - c.start, requests: c.rows.length })), errors: errors.slice(0, 5) }, null, 1));
}

// The Prometheus targets of a captured request: [{panel, refId, expr, instant, from, to, intervalMs, time}]
export function targetsOf(row) {
  if (row.url.startsWith('/api/ds/query')) {
    let body;
    try { body = JSON.parse(row.body || '{}'); } catch { return []; }
    return (body.queries || [])
      .filter((q) => typeof q.expr === 'string' && (!q.datasource || !q.datasource.type || q.datasource.type === 'prometheus'))
      .map((q) => ({
        panel: row.panel || 'variable', refId: q.refId, expr: q.expr.replace(/^query_result\(([\s\S]*)\)$/, '$1'),
        instant: q.instant === true && q.range !== true, from: Number(body.from) / 1000, to: Number(body.to) / 1000,
        intervalMs: Number(q.intervalMs || 0), time: null,
      }));
  }
  const form = new URLSearchParams(row.body || '');
  const query = form.get('query');
  if (!query || !/\/api\/v1\/query(\?|$)/.test(row.url)) return [];
  const time = form.get('time') || form.get('end');
  return [{ panel: 'variable', refId: 'var', expr: query, instant: true, from: null, to: time ? Number(time) : null, intervalMs: 0, time }];
}

// Grafana backend macros, with the captured intervalMs as the step (never floored at 15 s).
export function interpolate(target, defaultRange) {
  const step = Math.max(1, Math.round((target.intervalMs || 1000) / 1000));
  const range = target.from !== null && target.to !== null ? Math.round(target.to - target.from) : defaultRange;
  const expr = target.expr
    .replaceAll('$__rate_interval', `${4 * step}s`)
    .replaceAll('${__interval_ms}', String(step * 1000)).replaceAll('$__interval_ms', String(step * 1000))
    .replaceAll('${__interval}', `${step}s`).replaceAll('$__interval', `${step}s`)
    .replaceAll('${__range_ms}', String(range * 1000)).replaceAll('$__range_ms', String(range * 1000))
    .replaceAll('${__range_s}', String(range)).replaceAll('$__range_s', String(range))
    .replaceAll('${__range}', `${range}s`).replaceAll('$__range', `${range}s`);
  return { expr, step, range };
}

async function prometheusCall(base, endpoint, params) {
  const started = performance.now();
  const response = await fetch(base.replace(/\/$/, '') + endpoint, {
    method: 'POST',
    headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
    body: new URLSearchParams(params).toString(),
  });
  const body = await response.json().catch(() => ({ status: 'error', error: `HTTP ${response.status}` }));
  return { ms: performance.now() - started, body };
}

function median(values) {
  const sorted = [...values].sort((a, b) => a - b);
  if (!sorted.length) return null;
  const middle = Math.floor(sorted.length / 2);
  return sorted.length % 2 ? sorted[middle] : (sorted[middle - 1] + sorted[middle]) / 2;
}

// Nearest-rank percentile.
function percentile(values, p) {
  const sorted = [...values].sort((a, b) => a - b);
  if (!sorted.length) return null;
  return sorted[Math.min(sorted.length - 1, Math.max(0, Math.ceil((p / 100) * sorted.length) - 1))];
}

async function replay(file, options) {
  if (!options.prometheus) fail('replay needs --prometheus <base url>');
  const capture = JSON.parse(readFileSync(file, 'utf8'));
  const all = cycles(capture.rows);
  const index = Math.min(Number(options.cycle ?? 1), all.length - 1);
  if (index < 0) fail(`${file} has no data requests`);
  const repeat = Number(options.repeat || 3);
  const results = [];
  for (const row of all[index].rows) {
    for (const target of targetsOf(row)) {
      const { expr, step, range } = interpolate(target, capture.range || 3600);
      const endpoint = target.instant ? '/api/v1/query' : '/api/v1/query_range';
      const params = target.instant
        ? { query: expr, time: String(target.time ?? target.to), stats: 'all' }
        : { query: expr, start: String(target.from - (target.from % step)), end: String(target.to - (target.to % step)), step: String(step), stats: 'all' };
      const walls = [];
      let body = {};
      for (let i = 0; i < repeat; i++) {
        try {
          const call = await prometheusCall(options.prometheus, endpoint, params);
          walls.push(call.ms);
          body = call.body;
        } catch (e) {
          walls.push(-1);
          body = { status: 'error', error: String(e) };
        }
      }
      const stats = (body.data && body.data.stats) || {};
      const row_ = {
        panel: target.panel, refId: target.refId, kind: target.instant ? 'instant' : 'range', step, range,
        bytes: Buffer.byteLength(expr), wallMs: Math.round(median(walls)), evalMs: Math.round(1000 * ((stats.timings || {}).evalTotalTime || 0)),
        samples: (stats.samples || {}).totalQueryableSamples ?? null, peakSamples: (stats.samples || {}).peakSamples ?? null,
        series: ((body.data || {}).result || []).length, error: body.status === 'error' ? String(body.error).slice(0, 300) : null,
      };
      results.push(row_);
      console.log(JSON.stringify(row_));
    }
  }
  const out = `${file}.replay.json`;
  writeFileSync(out, JSON.stringify({ tool: 'bin/perf.mjs', version: 1, capture: basename(file), prometheus: options.prometheus, cycle: index, targets: results }, null, 1));
  const totals = summariseReplay(results);
  console.log(`replayed ${results.length} targets of cycle ${index}: ${totals.promqlMs} ms, ${totals.samples} samples, ${totals.interpolatedKb} KB -> ${out}`);
}

function seriesKey(metric) {
  return JSON.stringify(Object.keys(metric).filter((k) => k !== 'instance').sort().map((k) => [k, metric[k]]));
}

// [seriesKey => [[t, v], ...]] of a query result (vector or matrix).
function resultValues(body) {
  const out = new Map();
  for (const series of (body.data && body.data.result) || []) {
    const values = series.values || (series.value ? [series.value] : []);
    const key = seriesKey(series.metric || {});
    out.set(key, [...(out.get(key) || []), ...values.map(([t, v]) => [Number(t), Number(v)])]);
  }
  return out;
}

async function compare(file, options) {
  if (!options.prometheus || !options.against) fail('compare needs --prometheus <url> --against <url>');
  const capture = JSON.parse(readFileSync(file, 'utf8'));
  const all = cycles(capture.rows);
  const index = Math.min(Number(options.cycle ?? 1), all.length - 1);
  if (index < 0) fail(`${file} has no data requests`);
  const tolerance = Number(options.tolerance ?? 0.1);
  const end = Math.floor(Date.now() / 1000) - Number(options.lag ?? 60);
  const differences = [];
  let compared = 0;
  for (const row of all[index].rows) {
    for (const target of targetsOf(row)) {
      const { expr, step, range } = interpolate(target, capture.range || 3600);
      const params = target.instant
        ? { query: expr, time: String(end) }
        : { query: expr, start: String(end - range - ((end - range) % step)), end: String(end - (end % step)), step: String(step) };
      const endpoint = target.instant ? '/api/v1/query' : '/api/v1/query_range';
      const [a, b] = await Promise.all([prometheusCall(options.prometheus, endpoint, params), prometheusCall(options.against, endpoint, params)]);
      const left = resultValues(a.body);
      const right = resultValues(b.body);
      const label = `panel ${target.panel} ${target.refId}`;
      compared++;
      for (const key of new Set([...left.keys(), ...right.keys()])) {
        if (!left.has(key) || !right.has(key)) {
          differences.push(`${label}: series ${key} only on ${left.has(key) ? options.prometheus : options.against}`);
          continue;
        }
        const other = new Map(right.get(key));
        for (const [t, v] of left.get(key)) {
          if (!other.has(t)) continue;
          const w = other.get(t);
          if (Number.isNaN(v) && Number.isNaN(w)) continue;
          if (Math.abs(v - w) > tolerance * Math.max(Math.abs(v), Math.abs(w), 1)) {
            differences.push(`${label}: ${key} at ${t}: ${v} vs ${w}`);
            break;
          }
        }
      }
    }
  }
  console.log(`compared ${compared} targets of cycle ${index} at ${end}: ${differences.length} difference(s)`);
  for (const d of differences.slice(0, 50)) console.log(d);
  process.exit(differences.length ? 1 : 0);
}

function summariseReplay(targets) {
  const byPanel = {};
  for (const t of targets) {
    const p = (byPanel[t.panel] ||= { panel: t.panel, targets: 0, wallMs: 0, samples: 0, kb: 0 });
    p.targets++;
    p.wallMs += t.wallMs;
    p.samples += t.samples || 0;
    p.kb += t.bytes / 1024;
  }
  return {
    promqlMs: targets.reduce((s, t) => s + t.wallMs, 0),
    samples: targets.reduce((s, t) => s + (t.samples || 0), 0),
    interpolatedKb: Math.round(targets.reduce((s, t) => s + t.bytes, 0) / 102.4) / 10,
    maxPanelKb: Math.round(Math.max(0, ...Object.values(byPanel).filter((p) => p.panel !== 'variable').map((p) => p.kb)) * 10) / 10,
    errors: targets.filter((t) => t.error).length,
    targetsByPanel: Object.values(byPanel).sort((a, b) => b.wallMs - a.wallMs),
  };
}

export function summariseCapture(capture, overviewPanels) {
  const all = cycles(capture.rows);
  const refreshes = all.slice(1, 4);
  const overview = new Set((overviewPanels || []).map(String));
  const overviewMs = refreshes.map((c) => {
    const ends = c.rows.filter((r) => overview.has(String(r.panel))).map((r) => r.end);
    return ends.length ? Math.max(...ends) - c.start : null;
  }).filter((v) => v !== null);
  const durations = refreshes.map((c) => c.lastEnd - c.start);
  return {
    range: rangeLabel(capture.range),
    cycles: all.length,
    firstLoadMs: all.length ? all[0].lastEnd - all[0].start : null,
    refreshP50Ms: median(durations),
    refreshP95Ms: percentile(durations, 95),
    overviewP50Ms: median(overviewMs),
    requestsPerRefresh: median(refreshes.map((c) => c.rows.length)),
    variableRequestsPerRefresh: median(refreshes.map((c) => c.rows.filter((r) => r.kind === 'variable').length)),
    infinityRequestsPerRefresh: median(refreshes.map((c) => c.rows.filter((r) => (r.datasources || []).includes(INFINITY)).length)),
    failedRequests: capture.rows.filter((r) => r.failed || (r.status && r.status >= 400)).length,
    byPanel: Object.values(refreshes.flatMap((c) => c.rows).reduce((acc, r) => {
      const key = r.panel || 'variable';
      const p = (acc[key] ||= { panel: key, requests: 0, ms: [] });
      p.requests++;
      p.ms.push(r.ms);
      return acc;
    }, {})).map((p) => ({ panel: p.panel, requestsPerRefresh: Math.round((p.requests / Math.max(1, refreshes.length)) * 10) / 10, medianMs: median(p.ms) }))
      .sort((a, b) => b.medianMs - a.medianMs),
  };
}

function table(rows, columns) {
  const widths = columns.map((c) => Math.max(c.length, ...rows.map((r) => String(r[c] ?? '').length)));
  const line = (values) => values.map((v, i) => String(v ?? '').padStart(widths[i])).join('  ');
  console.log(line(columns));
  for (const r of rows) console.log(line(columns.map((c) => r[c])));
}

function report(files, options) {
  const budget = options.budget ? JSON.parse(readFileSync(options.budget, 'utf8')) : null;
  const expected = options.expect ? JSON.parse(readFileSync(options.expect, 'utf8')) : null;
  const tolerance = Number(options.tolerance ?? 0.25);
  const overviewPanels = (budget && budget.overviewPanels) || (expected && expected.overviewPanels) || [1, 3, 2, 8, 9, 4, 21];
  const violations = [];
  const summaries = [];
  for (const file of files.filter((f) => !f.endsWith('.replay.json'))) {
    const capture = JSON.parse(readFileSync(file, 'utf8'));
    const summary = { file: basename(file), ...summariseCapture(capture, overviewPanels) };
    const replayFile = `${file}.replay.json`;
    if (existsSync(replayFile)) Object.assign(summary, summariseReplay(JSON.parse(readFileSync(replayFile, 'utf8')).targets));
    summaries.push(summary);
    console.log(`\n== ${summary.file} (range ${summary.range}, ${summary.cycles} cycles)`);
    console.log('requests per panel (refresh cycles 2-4):');
    table(summary.byPanel, ['panel', 'requestsPerRefresh', 'medianMs']);
    if (summary.targetsByPanel) {
      console.log('PromQL replay per panel:');
      table(summary.targetsByPanel.map((p) => ({ ...p, kb: p.kb.toFixed(1) })), ['panel', 'targets', 'wallMs', 'samples', 'kb']);
    }
    const totals = ['firstLoadMs', 'refreshP50Ms', 'refreshP95Ms', 'overviewP50Ms', 'requestsPerRefresh', 'variableRequestsPerRefresh',
      'infinityRequestsPerRefresh', 'failedRequests', 'promqlMs', 'samples', 'interpolatedKb', 'maxPanelKb', 'errors'];
    table(totals.filter((k) => summary[k] !== undefined).map((k) => ({ metric: k, value: summary[k] })), ['metric', 'value']);

    const limits = budget && budget.ranges && budget.ranges[summary.range];
    for (const [metric, limit] of Object.entries(limits || {})) {
      if (summary[metric] === undefined || summary[metric] === null) continue;
      if (summary[metric] > limit) violations.push(`${summary.file}: ${metric} ${summary[metric]} > budget ${limit}`);
    }
    for (const [metric, value] of Object.entries((expected && expected.values) || {})) {
      if (summary[metric] === undefined || summary[metric] === null) continue;
      const deviation = (summary[metric] - value) / value;
      console.log(`expect ${metric}: ${summary[metric]} vs ${value} (${(deviation * 100).toFixed(1)} %)`);
      if (Math.abs(deviation) > tolerance) violations.push(`${summary.file}: ${metric} ${summary[metric]} deviates ${(deviation * 100).toFixed(1)} % from ${value}`);
    }
  }
  if (options.json) writeFileSync(options.json, JSON.stringify(summaries, null, 1));
  if (violations.length) {
    console.log(`\n${violations.length} violation(s):\n${violations.join('\n')}`);
    process.exit(1);
  }
  console.log('\nwithin budget');
}

const isMain = process.argv[1] && import.meta.url === new URL(`file://${process.argv[1]}`).href;
if (isMain) {
  const [command, ...rest] = process.argv.slice(2);
  const { positional, options } = parseArgs(rest);
  if (command === 'capture') await capture(options);
  else if (command === 'replay') await replay(positional[0] || fail('replay needs a capture file'), options);
  else if (command === 'report') report(positional.length ? positional : fail('report needs capture files'), options);
  else if (command === 'compare') await compare(positional[0] || fail('compare needs a capture file'), options);
  else {
    const lines = readFileSync(new URL(import.meta.url), 'utf8').split('\n').slice(1);
    console.log(lines.slice(0, lines.findIndex((l) => !l.startsWith('//'))).map((l) => l.replace(/^\/\/ ?/, '')).join('\n'));
    process.exit(command ? 2 : 0);
  }
}
