#!/usr/bin/env node
/**
 * Scrape Instagram location IDs for one or more cities.
 *
 * Uses an authenticated Chromium session (created via instagram-login.mjs)
 * to hit IG's authenticated topsearch endpoint:
 *   https://www.instagram.com/web/search/topsearch/?context=blended&query=<q>
 *
 * Reads queries from stdin (one per line) or --query flag(s). Emits NDJSON
 * to stdout, one object per query:
 *   {"query":"Palatine, IL","id":"108424509199446","name":"Palatine, Illinois"}
 *   {"query":"...","id":null,"error":"no_result"}
 *
 * Usage:
 *   echo "Palatine, IL\nBarrington, IL" | \
 *     node scripts/scrape-instagram-location.mjs \
 *       --user-data-dir=/var/data/instagram-puppeteer
 *
 *   node scripts/scrape-instagram-location.mjs \
 *     --user-data-dir=/var/data/instagram-puppeteer \
 *     --query="Long Grove, IL"
 */
import puppeteer from 'puppeteer-extra';
import StealthPlugin from 'puppeteer-extra-plugin-stealth';
import fs from 'node:fs';
import readline from 'node:readline';

puppeteer.use(StealthPlugin());

function parseArgs(argv) {
  const args = { userDataDir: null, queries: [], delayMs: 4000, headless: true };
  for (const a of argv.slice(2)) {
    if (a.startsWith('--user-data-dir=')) args.userDataDir = a.slice('--user-data-dir='.length);
    else if (a.startsWith('--query=')) args.queries.push(a.slice('--query='.length));
    else if (a.startsWith('--delay-ms=')) args.delayMs = parseInt(a.slice('--delay-ms='.length), 10);
    else if (a === '--headed') args.headless = false;
  }
  return args;
}

async function readStdinLines() {
  if (process.stdin.isTTY) return [];
  const rl = readline.createInterface({ input: process.stdin, crlfDelay: Infinity });
  const lines = [];
  for await (const line of rl) {
    const t = line.trim();
    if (t) lines.push(t);
  }
  return lines;
}

async function scrapeOne(page, query) {
  // IG's authenticated fbsearch/places endpoint. Requires X-IG-App-ID header
  // (set globally below) and an authenticated session cookie.
  const url = 'https://www.instagram.com/api/v1/fbsearch/places/?query=' + encodeURIComponent(query);
  const resp = await page.goto(url, { waitUntil: 'networkidle0', timeout: 30000 });
  if (!resp || !resp.ok()) {
    return { id: null, name: null, error: `http_${resp ? resp.status() : 'no_response'}` };
  }
  const text = await page.evaluate(() => document.body.innerText);
  let data;
  try {
    data = JSON.parse(text);
  } catch (e) {
    return { id: null, name: null, error: 'parse_failed' };
  }

  if (data.message === 'checkpoint_required' || data.status === 'fail') {
    return { id: null, name: null, error: data.message || 'fail' };
  }

  const items = data.items || [];
  if (!items.length) {
    return { id: null, name: null, error: 'no_result' };
  }

  // Match a "City, State" item exactly when possible.
  const city = query.split(',')[0].trim().toLowerCase();
  const stateRaw = (query.split(',')[1] || '').trim();
  // Expand "IL" → "illinois" for name matching
  const stateExpansions = { IL: 'illinois', WI: 'wisconsin', IN: 'indiana' };
  const stateFull = (stateExpansions[stateRaw.toUpperCase()] || stateRaw).toLowerCase();
  const expectedName = stateFull ? `${city}, ${stateFull}` : city;

  let best = null;
  for (const it of items) {
    const loc = it.location;
    if (!loc || !loc.pk) continue;
    const name = (loc.name || it.title || '').toLowerCase();
    if (name === expectedName) { best = it; break; }
  }
  if (!best) {
    for (const it of items) {
      const loc = it.location;
      if (!loc || !loc.pk) continue;
      const short = (loc.short_name || '').toLowerCase();
      if (short === city) { best = it; break; }
    }
  }
  if (!best) {
    // Loose match: name starts with "<city>," (e.g. "Palatine, Il")
    for (const it of items) {
      const loc = it.location;
      if (!loc || !loc.pk) continue;
      const name = (loc.name || it.title || '').toLowerCase();
      if (name.startsWith(city + ',') || name === city) { best = it; break; }
    }
  }
  if (!best) {
    // Refuse to guess — better to leave it unresolved than tag the wrong place.
    return { id: null, name: null, error: 'no_match' };
  }

  const id = best.location && best.location.pk ? String(best.location.pk) : null;
  const name = (best.location && best.location.name) || best.title || null;

  return id ? { id, name, error: null } : { id: null, name: null, error: 'no_pk' };
}

(async () => {
  const args = parseArgs(process.argv);
  if (!args.userDataDir) {
    console.error('--user-data-dir is required');
    process.exit(2);
  }

  const stdinQueries = await readStdinLines();
  const queries = [...args.queries, ...stdinQueries];
  if (queries.length === 0) {
    console.error('No queries provided (via --query or stdin)');
    process.exit(2);
  }

  fs.mkdirSync(args.userDataDir, { recursive: true });

  const browser = await puppeteer.launch({
    headless: args.headless ? 'new' : false,
    userDataDir: args.userDataDir,
    defaultViewport: { width: 1366, height: 900 },
    args: [
      '--no-sandbox',
      '--disable-setuid-sandbox',
      '--disable-blink-features=AutomationControlled',
    ],
  });

  let exitCode = 0;
  try {
    const page = (await browser.pages())[0] || (await browser.newPage());
    await page.setUserAgent('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
    await page.setExtraHTTPHeaders({ 'X-IG-App-ID': '936619743392459' });

    for (let i = 0; i < queries.length; i++) {
      const q = queries[i];
      let result;
      try {
        result = await scrapeOne(page, q);
      } catch (e) {
        result = { id: null, name: null, error: 'exception: ' + (e.message || String(e)) };
      }
      process.stdout.write(JSON.stringify({ query: q, ...result }) + '\n');

      if (result.error && (result.error === 'checkpoint_required' || result.error.startsWith('http_4') || result.error.startsWith('http_5'))) {
        // Stop early — session is broken or we're rate limited
        exitCode = 3;
        break;
      }

      if (i < queries.length - 1 && args.delayMs > 0) {
        await new Promise((r) => setTimeout(r, args.delayMs));
      }
    }
  } finally {
    await browser.close().catch(() => {});
  }

  process.exit(exitCode);
})().catch((e) => {
  console.error(e);
  process.exit(1);
});
