#!/usr/bin/env node
/**
 * Discovery: what does Yelp for Business show for LEADS, and which JSON does
 * the page fetch to show it? Read-only. Reuses the photo-upload session.
 *
 *   node scripts/yelp-discover-leads.mjs --user-data-dir=... [--cookies-file=...]
 *        --biz-id=... --out-dir=... [--timeout-ms=90000] [--proxy=...] [--headed]
 *
 * Visits the dashboard, follows any nav link that smells like leads / inbox /
 * notifications, plus a few known URL shapes, and for each page records the
 * final URL, title, headings, links, visible text and a screenshot — and every
 * JSON response the page pulled while it loaded. One JSON document on stdout.
 */
import fs from 'node:fs';
import path from 'node:path';
import puppeteer from 'puppeteer-extra';
import StealthPlugin from 'puppeteer-extra-plugin-stealth';
import { loadCookiesFromFile, applyCookies } from './lib/yelp-cookies.mjs';
import { launchPuppeteerWithLockRecovery, installShutdownHandlers } from './lib/yelp-userdata-lock.mjs';
import { wrapProxyForChromium } from './lib/yelp-proxy.mjs';
import { detectDataDome } from './lib/yelp-datadome.mjs';

puppeteer.use(StealthPlugin());

const args = {
  userDataDir: null, cookiesFile: null, bizId: null, outDir: '/tmp/yelp-discover', timeoutMs: 90000, proxy: null, headless: true,
  userAgent: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
};
for (const a of process.argv.slice(2)) {
  if (a.startsWith('--user-data-dir=')) args.userDataDir = a.slice(16);
  else if (a.startsWith('--cookies-file=')) args.cookiesFile = a.slice(15);
  else if (a.startsWith('--biz-id=')) args.bizId = a.slice(9);
  else if (a.startsWith('--out-dir=')) args.outDir = a.slice(10);
  else if (a.startsWith('--timeout-ms=')) args.timeoutMs = Number(a.slice(13)) || args.timeoutMs;
  else if (a.startsWith('--proxy=')) args.proxy = a.slice(8);
  else if (a.startsWith('--urls=')) args.urls = a.slice(7).split(',').map((u) => u.trim()).filter(Boolean);
  else if (a === '--headed') args.headless = false;
}
const log = (m) => console.error(`[discover] ${m}`);
const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
fs.mkdirSync(args.outDir, { recursive: true });

const proxyConfig = await wrapProxyForChromium(args.proxy);
const launchArgs = ['--no-sandbox', '--disable-setuid-sandbox', '--disable-blink-features=AutomationControlled', '--disable-dev-shm-usage', '--disable-gpu', '--no-zygote', '--no-first-run', '--no-default-browser-check'];
if (proxyConfig) launchArgs.push(`--proxy-server=${proxyConfig.localUrl}`, '--ignore-certificate-errors');

const browser = await launchPuppeteerWithLockRecovery({
  puppeteer,
  userDataDir: args.userDataDir,
  launchOptions: { headless: args.headless ? 'new' : false, userDataDir: args.userDataDir || undefined, defaultViewport: { width: 1366, height: 900 }, args: launchArgs },
});
installShutdownHandlers(browser);
if (args.cookiesFile && fs.existsSync(args.cookiesFile)) {
  try { const n = await applyCookies(browser, loadCookiesFromFile(args.cookiesFile)); log(`injected ${n} cookies`); } catch (e) { log(`cookie injection failed: ${e.message}`); }
}

const result = { ok: false, loggedIn: false, pages: [] };
let captured = [];
let captureSeq = 0;
const page = await browser.newPage();
await page.setUserAgent(args.userAgent);
await page.setExtraHTTPHeaders({ 'Accept-Language': 'en-US,en;q=0.9' });
page.on('response', async (res) => {
  try {
    const url = res.url();
    if (!/yelp\.com/.test(url)) return;
    const ct = (res.headers()['content-type'] || '').toLowerCase();
    if (!ct.includes('json')) return;
    const text = await res.text().catch(() => '');
    const req = res.request();
    const post = req.postData ? (req.postData() || '') : '';
    const n = ++captureSeq;
    const file = path.join(args.outDir, `json-${n}.json`);
    fs.writeFileSync(file, JSON.stringify({ url, status: res.status(), method: req.method(), request: post.slice(0, 20000), body: text }, null, 2));
    captured.push({ url: url.slice(0, 300), status: res.status(), method: req.method(), bytes: text.length, body: text.slice(0, 4000), file, requestHead: post.slice(0, 300) });
  } catch {}
});

async function describe(label) {
  const info = await page.evaluate(() => {
    const clean = (s) => (s || '').replace(/\s+/g, ' ').trim();
    const links = Array.from(document.querySelectorAll('a[href]')).map((a) => ({ href: a.href, text: clean(a.innerText).slice(0, 80) })).filter((l) => l.text || /lead|inbox|message|notif|request|quote/i.test(l.href));
    const seen = new Set();
    const uniq = links.filter((l) => { const k = l.href + '|' + l.text; if (seen.has(k)) return false; seen.add(k); return true; });
    return {
      url: location.href, title: document.title,
      headings: Array.from(document.querySelectorAll('h1,h2,h3')).map((h) => clean(h.innerText)).filter(Boolean).slice(0, 40),
      links: uniq.slice(0, 200),
      text: clean(document.body?.innerText).slice(0, 6000),
      hasDataDome: !!document.querySelector('iframe[src*="captcha-delivery"]'),
    };
  }).catch((e) => ({ url: page.url(), error: e.message }));
  const shot = path.join(args.outDir, `${label}.png`);
  await page.screenshot({ path: shot, fullPage: false }).catch(() => {});
  const html = path.join(args.outDir, `${label}.html`);
  await page.content().then((c) => fs.writeFileSync(html, c)).catch(() => {});
  info.screenshot = shot; info.html = html; info.label = label;
  info.json = captured; captured = [];
  result.pages.push(info);
  log(`${label}: ${info.url} "${info.title}" headings=${(info.headings || []).length} links=${(info.links || []).length} json=${info.json.length}`);
  return info;
}

async function visit(label, url) {
  captured = [];
  await page.goto(url, { waitUntil: 'networkidle2', timeout: args.timeoutMs }).catch((e) => log(`goto ${url}: ${e.message}`));
  await sleep(2500);
  if (await detectDataDome(page)) { log(`DataDome on ${url}`); }
  return describe(label);
}

try {
  const home = await visit('home', args.bizId ? `https://biz.yelp.com/home/${args.bizId}/` : 'https://biz.yelp.com/');
  result.loggedIn = /biz\.yelp\.com\/(home|biz_photos|biz)\/[A-Za-z0-9_-]{12,}|business\.yelp\.com\/[A-Za-z0-9_-]{12,}\//.test(home.url || '');
  if (!result.loggedIn) { result.error = `not logged in (landed on ${home.url})`; }
  else if (args.urls && args.urls.length) {
    let i = 0;
    for (const u of args.urls) { await visit(`u${i++}`, u); await sleep(1500); }
    result.ok = true;
  } else {
    const candidates = [];
    for (const l of home.links || []) {
      if (/lead|inbox|message|notif|request|quote/i.test(l.href + ' ' + l.text) && !candidates.includes(l.href)) candidates.push(l.href);
    }
    for (const u of [
      `https://biz.yelp.com/notifications/${args.bizId}`, 'https://biz.yelp.com/notifications', 'https://biz.yelp.com/inbox',
      `https://biz.yelp.com/inbox/${args.bizId}`, `https://business.yelp.com/${args.bizId}/leads`, `https://business.yelp.com/${args.bizId}/messages`,
      `https://biz.yelp.com/leads/${args.bizId}`, `https://biz.yelp.com/messages/${args.bizId}`,
    ]) if (!candidates.includes(u)) candidates.push(u);
    let i = 0;
    for (const u of candidates.slice(0, 12)) { await visit(`c${i++}`, u); }
    // One level deeper: on any page that listed things that smell like conversations, open the first two.
    const convo = [];
    for (const p of result.pages) for (const l of p.links || []) if (/conversation|inbox\/|message\/|lead\/|request/i.test(l.href) && !/^https?:\/\/biz\.yelp\.com\/(notifications|inbox)\/?$/.test(l.href) && !convo.includes(l.href) && !candidates.includes(l.href)) convo.push(l.href);
    let j = 0;
    for (const u of convo.slice(0, 3)) { await visit(`d${j++}`, u); }
    result.ok = true;
  }
} catch (e) {
  result.error = e?.message || String(e);
} finally {
  await browser.close().catch(() => {});
}
fs.writeFileSync(path.join(args.outDir, 'result.json'), JSON.stringify(result, null, 2));
process.stdout.write(JSON.stringify({ ok: result.ok, loggedIn: result.loggedIn, error: result.error || null, pages: result.pages.map((p) => ({ label: p.label, url: p.url, title: p.title, headings: p.headings, json: (p.json || []).map((j) => ({ url: j.url, status: j.status, bytes: j.bytes })) })) }) + '\n');
