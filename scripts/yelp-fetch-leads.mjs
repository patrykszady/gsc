#!/usr/bin/env node
/**
 * Yelp for Business — lead fetcher (read-only).
 *
 * Reads the account's Request-a-Quote leads from biz.yelp.com with the same
 * signed-in Chromium session the photo uploads use, and prints them as JSON
 * for `php artisan yelp:sync-leads`. Yelp exposes no API for this to
 * non-partners; the lead pages, however, embed their data as the page's own
 * hydration state (window.yelp.react_apollo_state), so this reads that state
 * rather than scraping the layout — a redesign of the cards does not break it.
 *
 *   node scripts/yelp-fetch-leads.mjs --user-data-dir=... [--cookies-file=...]
 *        --biz-id=... --out-dir=... [--known=ENCID@ISO,...] [--all]
 *        [--max-pages=5] [--timeout-ms=90000] [--proxy=...] [--headed]
 *
 * --known lists leads already stored with their last-event time; details
 * (and photos) are fetched only for leads not in it or whose last event moved.
 * Photos are downloaded into --out-dir/<lead>/ — Yelp's links expire in a day.
 *
 * Exit codes: 0 ok · 2 bad args · 3 session not authenticated · 1 other.
 * The last stdout line is the JSON result; everything else goes to stderr.
 */
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const LEAD_LIKE_KEYS = /^(newLeads|contactedLeads)\(/;

/** Follow an Apollo cache reference ({__ref: "Type:id"}) into the state. */
export function resolve(state, value) {
  if (value && typeof value === 'object' && typeof value.__ref === 'string') return state[value.__ref] || null;
  return value ?? null;
}

/** The value of the first key that starts with `prefix` ("workflowStatus(" …). */
function field(obj, prefix) {
  if (!obj || typeof obj !== 'object') return undefined;
  if (prefix in obj) return obj[prefix];
  const key = Object.keys(obj).find((k) => k.startsWith(prefix + '(') || k.startsWith(prefix + '{'));
  return key ? obj[key] : undefined;
}

/** The Business:* entry the page is about. */
function business(state) {
  const key = Object.keys(state).find((k) => k.startsWith('Business:'));
  return key ? state[key] : null;
}

/**
 * Leads listed on the leads-center page: every LeadConnection under the
 * business's privateBizInfo (new + contacted), each resolved to a summary.
 *
 * @returns {{ leads: Array, hasMore: boolean }}
 */
export function leadsFromState(state) {
  const biz = business(state);
  const info = biz?.privateBizInfo;
  const leads = new Map();
  let hasMore = false;

  if (info && typeof info === 'object') {
    for (const [key, conn] of Object.entries(info)) {
      if (!LEAD_LIKE_KEYS.test(key) || !conn || typeof conn !== 'object' || !Array.isArray(conn.edges)) continue;
      if (conn.pageInfo?.hasNextPage) hasMore = true;
      for (const edge of conn.edges) {
        const lead = resolve(state, edge?.node);
        const summary = leadSummary(state, lead);
        if (summary && !leads.has(summary.encid)) leads.set(summary.encid, summary);
      }
    }
  }

  // Any Lead entry the page holds counts too (a card rendered from a query
  // this walk did not recognise still names its lead).
  for (const [key, entry] of Object.entries(state)) {
    if (!key.startsWith('Lead:')) continue;
    const summary = leadSummary(state, entry);
    if (summary && !leads.has(summary.encid)) leads.set(summary.encid, summary);
  }

  return { leads: Array.from(leads.values()), hasMore };
}

function leadSummary(state, lead) {
  if (!lead || typeof lead !== 'object' || !lead.encid) return null;
  const project = resolve(state, lead.project);
  const user = resolve(state, project?.user);
  const workflow = field(lead, 'workflowStatus');

  return {
    encid: lead.encid,
    status: lead.status ?? null,
    workflowStatus: workflow?.status ?? null,
    workflowText: workflow?.displayText ?? null,
    needsAttention: !!lead.needsAttention,
    lastEventAt: lead.lastEventTime?.utcDateTime ?? null,
    customerName: user?.displayName ?? null,
    title: project?.jobSummaryTitle ?? project?.name ?? null,
    zip: project?.zip ?? null,
    urgency: project?.urgency?.level ?? null,
    previewText: lead.leadPreview?.previewText ?? null,
  };
}

/**
 * Everything the lead's own page knows about it.
 */
export function leadDetailFromState(state, encid) {
  const lead = state[`Lead:${encid}`]
    || Object.values(state).find((e) => e && typeof e === 'object' && e.__typename === 'Lead' && e.encid === encid);
  if (!lead) return null;

  const project = resolve(state, lead.project);
  const user = resolve(state, project?.user);
  const conversation = resolve(state, lead.conversation);
  const createdLocal = field(lead, 'createdAt')?.__typename ? field(field(lead, 'createdAt'), 'localDateTime') : null;
  const workflow = field(lead, 'workflowStatus');

  const attachments = Object.entries(state)
    .filter(([k, v]) => k.startsWith('AttachmentFile:') && v && typeof v.url === 'string')
    .map(([, v]) => ({ encid: v.encid ?? null, url: v.url }));

  const answers = Array.isArray(project?.surveyQuestionAnswers)
    ? project.surveyQuestionAnswers.map((qa) => ({ question: qa?.question ?? '', answers: Array.isArray(qa?.answers) ? qa.answers : [] }))
    : [];

  return {
    encid,
    status: lead.status ?? null,
    workflowStatus: workflow?.status ?? null,
    workflowText: workflow?.displayText ?? null,
    createdAt: createdLocal ?? null,
    lastEventAt: lead.lastEventTime?.utcDateTime ?? null,
    location: { city: lead.location?.city ?? null, state: lead.location?.state ?? null },
    phone: lead.phoneNumberConnectionInfo?.consumerPhoneNumber ?? null,
    conversationId: conversation?.encid ?? null,
    customer: {
      name: user?.displayName ?? null,
      location: user?.displayLocation ?? null,
      reviewCount: user?.reviewCount ?? null,
    },
    project: {
      encid: project?.encid ?? null,
      title: project?.jobSummaryTitle ?? project?.name ?? null,
      zip: project?.zip ?? null,
      urgency: project?.urgency?.level ?? null,
      description: project?.description ?? null,
      details: project?.unstructuredDetails ?? null,
      offerings: Array.isArray(project?.serviceOfferings) ? project.serviceOfferings : [],
      keywords: Array.isArray(project?.summaryKeywords) ? project.summaryKeywords : [],
      answers,
    },
    attachments,
  };
}

/** "ENCID@2026-09-15T01:46:46Z,ENCID2@…" → Map(encid → iso). */
export function parseKnown(spec) {
  const known = new Map();
  for (const part of String(spec || '').split(',')) {
    const [encid, at] = part.split('@');
    const id = (encid || '').trim();
    if (id) known.set(id, (at || '').trim());
  }
  return known;
}

// ─────────────────────────────────────────────────────────────────────────

function parseArgs(argv) {
  const args = {
    userDataDir: null, cookiesFile: null, bizId: null, outDir: null, known: '', all: false,
    maxPages: 5, timeoutMs: 90000, proxy: null, headless: true,
    userAgent: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
  };
  for (const a of argv.slice(2)) {
    if (a.startsWith('--user-data-dir=')) args.userDataDir = a.slice(16);
    else if (a.startsWith('--cookies-file=')) args.cookiesFile = a.slice(15);
    else if (a.startsWith('--biz-id=')) args.bizId = a.slice(9);
    else if (a.startsWith('--out-dir=')) args.outDir = a.slice(10);
    else if (a.startsWith('--known=')) args.known = a.slice(8);
    else if (a.startsWith('--max-pages=')) args.maxPages = Number(a.slice(12)) || args.maxPages;
    else if (a.startsWith('--timeout-ms=')) args.timeoutMs = Number(a.slice(13)) || args.timeoutMs;
    else if (a.startsWith('--proxy=')) args.proxy = a.slice(8);
    else if (a === '--all') args.all = true;
    else if (a === '--headed') args.headless = false;
  }
  return args;
}

const emit = (p) => process.stdout.write(JSON.stringify(p) + '\n');
const log = (m) => console.error(`[yelp-leads] ${m}`);
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

async function pageState(page) {
  return page.evaluate(() => (window.yelp && window.yelp.react_apollo_state) || null).catch(() => null);
}

function isLeadsCenterUrl(url) {
  return /^https:\/\/biz\.yelp\.com\/leads_center\/[A-Za-z0-9_-]{12,}\/leads/.test(url || '');
}

async function download(url, dest) {
  const res = await fetch(url, { redirect: 'follow' });
  if (!res.ok) throw new Error(`HTTP ${res.status}`);
  const mime = (res.headers.get('content-type') || '').split(';')[0].trim() || null;
  const buf = Buffer.from(await res.arrayBuffer());
  fs.writeFileSync(dest, buf);
  return { mime, size: buf.length };
}

async function main() {
  const args = parseArgs(process.argv);
  if (!args.bizId || !args.outDir) {
    emit({ ok: false, error: 'missing required args: --biz-id, --out-dir' });
    process.exit(2);
  }
  fs.mkdirSync(args.outDir, { recursive: true });

  const [{ default: puppeteer }, { default: StealthPlugin }, cookies, lock, proxyLib, dd] = await Promise.all([
    import('puppeteer-extra'), import('puppeteer-extra-plugin-stealth'),
    import('./lib/yelp-cookies.mjs'), import('./lib/yelp-userdata-lock.mjs'), import('./lib/yelp-proxy.mjs'), import('./lib/yelp-datadome.mjs'),
  ]);
  puppeteer.use(StealthPlugin());

  const proxyConfig = await proxyLib.wrapProxyForChromium(args.proxy);
  const launchArgs = ['--no-sandbox', '--disable-setuid-sandbox', '--disable-blink-features=AutomationControlled', '--disable-dev-shm-usage', '--disable-gpu', '--renderer-process-limit=1', '--no-zygote', '--no-first-run', '--no-default-browser-check'];
  if (proxyConfig) launchArgs.push(`--proxy-server=${proxyConfig.localUrl}`, '--ignore-certificate-errors');
  if (args.userDataDir) fs.mkdirSync(args.userDataDir, { recursive: true });

  const browser = await lock.launchPuppeteerWithLockRecovery({
    puppeteer,
    userDataDir: args.userDataDir,
    launchOptions: { headless: args.headless ? 'new' : false, userDataDir: args.userDataDir || undefined, defaultViewport: { width: 1366, height: 900 }, args: launchArgs },
  });
  lock.installShutdownHandlers(browser);

  if (args.cookiesFile && fs.existsSync(args.cookiesFile)) {
    try { const n = await cookies.applyCookies(browser, cookies.loadCookiesFromFile(args.cookiesFile)); log(`injected ${n} cookies`); } catch (e) { log(`cookie injection failed: ${e.message}`); }
  }

  const known = parseKnown(args.known);
  const result = { ok: false, bizId: args.bizId, listed: 0, fetched: 0, leads: [], errors: [] };
  let exitCode = 0;

  try {
    const page = await browser.newPage();
    await page.setUserAgent(args.userAgent);
    await page.setExtraHTTPHeaders({ 'Accept-Language': 'en-US,en;q=0.9' });

    // Lead objects that arrive through GraphQL while paging the list.
    const gqlLeads = new Map();
    page.on('response', async (res) => {
      try {
        if (!/biz\.yelp\.com\/gql/.test(res.url())) return;
        const text = await res.text();
        if (!text.includes('"Lead"')) return;
        const walk = (o) => {
          if (Array.isArray(o)) { o.forEach(walk); return; }
          if (!o || typeof o !== 'object') return;
          if (o.__typename === 'Lead' && o.encid && !gqlLeads.has(o.encid)) {
            gqlLeads.set(o.encid, { encid: o.encid, status: o.status ?? null, lastEventAt: o.lastEventTime?.utcDateTime ?? null, fromGql: true });
          }
          Object.values(o).forEach(walk);
        };
        walk(JSON.parse(text));
      } catch {}
    });

    const listUrl = `https://biz.yelp.com/leads_center/${args.bizId}/leads`;
    await page.goto(listUrl, { waitUntil: 'networkidle2', timeout: args.timeoutMs }).catch((e) => log(`goto list: ${e.message}`));
    await sleep(2000);

    if (await dd.detectDataDome(page)) {
      result.error = 'DataDome challenge on the leads page';
      exitCode = 1;
      throw new Error(result.error);
    }
    if (!isLeadsCenterUrl(page.url())) {
      result.error = `not authenticated (landed on ${page.url()})`;
      exitCode = 3;
      throw new Error(result.error);
    }

    let state = await pageState(page);
    if (!state) {
      result.error = 'leads page has no react_apollo_state — layout changed?';
      exitCode = 1;
      throw new Error(result.error);
    }

    const listed = new Map();
    const first = leadsFromState(state);
    for (const l of first.leads) listed.set(l.encid, l);
    log(`list: ${first.leads.length} lead(s) in page state${first.hasMore ? ', more pages' : ''}`);

    // Page on through the list's own "Next" control; the state snapshot is
    // the server render, so later pages are read off the GraphQL responses.
    let pages = 1;
    let more = first.hasMore;
    while (more && pages < args.maxPages) {
      const clicked = await page.evaluate(() => {
        const el = Array.from(document.querySelectorAll('button, a')).find((b) => /^(next|load more|show more)$/i.test((b.innerText || '').trim()) && !b.disabled);
        if (!el) return false;
        el.click();
        return true;
      }).catch(() => false);
      if (!clicked) break;
      await sleep(3000);
      pages++;
      const before = gqlLeads.size;
      more = before > 0 && gqlLeads.size >= before; // keep going while the click produced leads
      if (gqlLeads.size === before) more = false;
    }
    for (const [encid, l] of gqlLeads) if (!listed.has(encid)) listed.set(encid, l);
    result.listed = listed.size;

    for (const summary of listed.values()) {
      const knownAt = known.get(summary.encid);
      const changed = knownAt === undefined || args.all || (summary.lastEventAt && summary.lastEventAt !== knownAt);
      if (!changed) { result.leads.push({ ...summary, detail: false }); continue; }

      const url = `${listUrl}/${summary.encid}`;
      await page.goto(url, { waitUntil: 'networkidle2', timeout: args.timeoutMs }).catch((e) => log(`goto ${summary.encid}: ${e.message}`));
      await sleep(1500);
      const detailState = await pageState(page);
      const detail = detailState ? leadDetailFromState(detailState, summary.encid) : null;
      if (!detail) { result.errors.push({ encid: summary.encid, error: 'no detail state' }); result.leads.push({ ...summary, detail: false }); continue; }

      const dir = path.join(args.outDir, summary.encid);
      fs.mkdirSync(dir, { recursive: true });
      const files = [];
      for (const a of detail.attachments) {
        const name = (a.encid || `att-${files.length + 1}`).replace(/[^A-Za-z0-9_-]/g, '_');
        const dest = path.join(dir, name);
        try {
          const meta = await download(a.url, dest);
          files.push({ encid: a.encid, file: dest, mime: meta.mime, size: meta.size });
        } catch (e) {
          result.errors.push({ encid: summary.encid, attachment: a.encid, error: e.message });
        }
        await sleep(400);
      }
      result.fetched++;
      result.leads.push({ ...summary, ...detail, lastEventAt: detail.lastEventAt || summary.lastEventAt, url, attachments: files, detail: true });
      await sleep(1200);
    }

    result.ok = true;
  } catch (e) {
    if (!result.error) result.error = e?.message || String(e);
    if (!exitCode) exitCode = 1;
  } finally {
    await browser.close().catch(() => {});
  }

  emit(result);
  process.exit(result.ok ? 0 : exitCode);
}

if (process.argv[1] && path.resolve(process.argv[1]) === fileURLToPath(import.meta.url)) {
  main().catch((e) => { emit({ ok: false, error: e?.message || String(e) }); process.exit(1); });
}
