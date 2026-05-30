#!/usr/bin/env node
/**
 * Scrape Facebook Place IDs for cities by driving the news-feed composer's
 * Check-in flow. The plain `/search/places/` page returns Page IDs that are
 * NOT accepted by Graph API as the `place` parameter on /photos posts. The
 * composer's check-in typeahead, on the other hand, returns the canonical
 * Place entity_id that Graph accepts.
 *
 * Flow:
 *   1. Visit https://www.facebook.com/ (must already be logged in).
 *   2. Open the inline composer (click "What's on your mind").
 *   3. Click the "Check in" button to open the place picker dialog.
 *   4. For each query: clear the "Where are you?" input, type the query,
 *      wait for the `checkin_search_query` GraphQL response to settle, and
 *      pick the first result whose name matches the requested city.
 *
 * Reads queries from stdin (one per line) or --query flag(s). Emits NDJSON
 * to stdout, one object per query.
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

const stateExpansions = {
  IL: 'illinois', WI: 'wisconsin', IN: 'indiana', MI: 'michigan',
  IA: 'iowa', MO: 'missouri', OH: 'ohio',
};

const stateAbbrevs = Object.fromEntries(
  Object.entries(stateExpansions).map(([abbr, full]) => [full, abbr])
);

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

/**
 * Open the composer's Check-in dialog so the place-picker input is
 * focused and ready to receive typed queries.
 */
async function openCheckin(page) {
  await page.goto('https://www.facebook.com/', { waitUntil: 'networkidle2', timeout: 60000 });
  if (/\/(login|checkpoint|recover)/.test(page.url())) {
    throw new Error('auth_required');
  }

  const triggered = await page.evaluate(() => {
    for (const el of Array.from(document.querySelectorAll('[role="button"], div'))) {
      const txt = (el.textContent || '').trim();
      if (txt.startsWith("What's on your mind")) {
        el.click();
        return true;
      }
    }
    return false;
  });
  if (!triggered) throw new Error('composer_trigger_not_found');
  await sleep(3500);

  // Click the Check-in entry exactly once. We don't try to detect the
  // picker UI — FB renders it through several intermediate states (spinner,
  // dialog, inline) that don't all expose stable selectors. Instead we wait
  // a fixed budget for the picker to settle, then drive it via the keyboard
  // and watch for the typeahead GraphQL response.
  const clicked = await page.evaluate(() => {
    for (const el of Array.from(document.querySelectorAll('[aria-label="Check in"], [role="button"]'))) {
      const txt = (el.textContent || '').trim();
      const aria = el.getAttribute('aria-label') || '';
      if (txt === 'Check in' || aria === 'Check in') {
        el.scrollIntoView();
        el.click();
        return true;
      }
    }
    return false;
  });
  if (!clicked) {
    try { await page.screenshot({ path: '/tmp/fb-scraper-no-checkin.png', fullPage: false }); } catch (_) {}
    throw new Error('checkin_button_not_found');
  }
  await sleep(3500);
}

async function clearAndType(page, query, isFirst) {
  // Always spam Backspace before typing. On the very first query this
  // clears any prefilled "near me" placeholder text and reliably lands
  // the input focus on the picker; on subsequent queries it removes the
  // previous query.
  for (let i = 0; i < 80; i++) {
    await page.keyboard.press('Backspace');
  }
  await sleep(300);
  await page.keyboard.type(query, { delay: 130 });
}

function parseEdges(text) {
  // Pull entity_id + contextual_name pairs in document order.
  const out = [];
  const re = /"entity_id":"(\d+)"[^}]*?"contextual_name":"([^"]+)"/g;
  let m;
  while ((m = re.exec(text)) !== null) {
    out.push({ id: m[1], name: m[2] });
  }
  return out;
}

function expectedNamesFor(query) {
  const city = (query.split(',')[0] || '').trim().toLowerCase();
  const stateRaw = (query.split(',')[1] || '').trim();
  const stateRawLower = stateRaw.toLowerCase();
  // Resolve both abbreviation and full forms regardless of which the user passed.
  let stateAbbr = '';
  let stateFull = '';
  if (stateRaw) {
    if (stateExpansions[stateRaw.toUpperCase()]) {
      stateAbbr = stateRaw.toUpperCase();
      stateFull = stateExpansions[stateAbbr];
    } else if (stateAbbrevs[stateRawLower]) {
      stateFull = stateRawLower;
      stateAbbr = stateAbbrevs[stateFull];
    } else {
      stateFull = stateRawLower;
    }
  }
  const variants = new Set();
  if (stateAbbr) {
    variants.add(`${city}, ${stateAbbr.toLowerCase()}`);
    variants.add(`${city} ${stateAbbr.toLowerCase()}`);
  }
  if (stateFull) {
    variants.add(`${city}, ${stateFull}`);
    variants.add(`${city} ${stateFull}`);
  }
  if (city) variants.add(city);
  return { city, stateAbbr, stateFull, variants };
}

function pickMatch(results, query) {
  if (!results.length) return null;
  const { city, stateAbbr, stateFull, variants } = expectedNamesFor(query);
  const lower = (s) => (s || '').toLowerCase();
  const abbrLower = stateAbbr.toLowerCase();

  // 1. Exact name match.
  for (const r of results) {
    if (variants.has(lower(r.name))) return r;
  }
  // 2. Starts with "city, " and contains state token (abbr or full).
  for (const r of results) {
    const n = lower(r.name);
    if (n.startsWith(city + ',') && (
      (abbrLower && n.includes(', ' + abbrLower)) ||
      (stateFull && n.includes(stateFull))
    )) return r;
  }
  // 3. Name starts with city and mentions state somewhere.
  for (const r of results) {
    const n = lower(r.name);
    if (n.startsWith(city) && (
      (stateFull && n.includes(stateFull)) ||
      (abbrLower && n.includes(abbrLower))
    )) return r;
  }
  return null;
}

async function scrapeOne(page, query, isFirst) {
  const responses = [];
  const onResponse = async (resp) => {
    const url = resp.url();
    if (!url.includes('/graphql') && !url.includes('/api/graphql')) return;
    try {
      const text = await resp.text();
      if (!text) return;
      // Match either the legacy `checkin_search_query` payload or the
      // current `useComposerLocationPickerTypeaheadDataSourceQuery` results.
      // Both shapes embed `entity_id`+`contextual_name` pairs we can extract.
      if (/place_results|location_picker|checkin_search_query|"entity_id":"\d+","contextual_name"/.test(text)) {
        responses.push({ at: Date.now(), text });
      }
    } catch (_) {}
  };
  page.on('response', onResponse);

  try {
    await clearAndType(page, query, isFirst);
    if (isFirst) {
      try { await page.screenshot({ path: '/tmp/fb-scraper-first-typed.png', fullPage: false }); } catch (_) {}
    }

    // Wait for typeahead to settle. We require:
    //   - at least one response that contains text matching the typed query, OR
    //   - 2.5s of quiet after the last response, OR
    //   - 12s hard ceiling.
    const queryToken = query.split(',')[0].trim().toLowerCase();
    const start = Date.now();
    let lastCount = 0;
    let lastChange = Date.now();
    let sawQueryToken = false;
    while (Date.now() - start < 14000) {
      await sleep(400);
      if (responses.length !== lastCount) {
        lastCount = responses.length;
        lastChange = Date.now();
        // Check if the latest response includes a name containing our city
        // token \u2014 if so, the typeahead has caught up with the typed query.
        const latest = responses[responses.length - 1].text.toLowerCase();
        if (queryToken && latest.includes(queryToken)) {
          sawQueryToken = true;
        }
      } else if (sawQueryToken && Date.now() - lastChange >= 1200) {
        break;
      } else if (responses.length > 0 && Date.now() - lastChange >= 2500) {
        break;
      }
    }
  } finally {
    page.off('response', onResponse);
  }

  if (!responses.length) {
    return { id: null, name: null, error: 'no_typeahead_response' };
  }

  // Use the most recent response (matches the fully-typed query).
  const last = responses[responses.length - 1];
  const results = parseEdges(last.text);
  if (!results.length) {
    return { id: null, name: null, error: 'no_results' };
  }

  const match = pickMatch(results, query);
  if (match) {
    return { id: match.id, name: match.name, error: null };
  }
  return {
    id: null,
    name: null,
    error: 'no_match',
    top: results.slice(0, 5),
  };
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

    // Grant geolocation so FB doesn't block the place-picker on a permission
    // prompt (Chicago coords; biases default suggestions but exact-name match
    // still picks the right city).
    const ctx = browser.defaultBrowserContext();
    try {
      await ctx.overridePermissions('https://www.facebook.com', ['geolocation']);
      await page.setGeolocation({ latitude: 41.85, longitude: -87.65 });
    } catch (_) {}

    try {
      await openCheckin(page);
    } catch (e) {
      const msg = e.message || String(e);
      process.stdout.write(JSON.stringify({ query: null, id: null, name: null, error: msg }) + '\n');
      process.exit(msg === 'auth_required' ? 3 : 4);
    }

    // Warm-up query: the very first typeahead invocation reliably misses
    // because focus hasn't settled into the picker input yet. Run a throwaway
    // query and discard the result so subsequent user queries land cleanly.
    try { await scrapeOne(page, 'Chicago, Illinois', true); } catch (_) {}
    await sleep(800);

    for (let i = 0; i < queries.length; i++) {
      const q = queries[i];
      let result;
      try {
        result = await scrapeOne(page, q, false);
        // Retry once if we got Breckenridge-style geo-defaults (no match
        // and top results don't include the queried city token).
        if (result && result.error === 'no_match') {
          const token = (q.split(',')[0] || '').trim().toLowerCase();
          const top = (result.top || []).map((r) => (r.name || '').toLowerCase());
          if (token && !top.some((n) => n.includes(token))) {
            await sleep(800);
            try {
              const retry = await scrapeOne(page, q, false);
              if (retry && retry.id) result = retry;
            } catch (_) {}
          }
        }
      } catch (e) {
        result = { id: null, name: null, error: 'exception: ' + (e.message || String(e)) };
      }
      process.stdout.write(JSON.stringify({ query: q, ...result }) + '\n');

      if (i < queries.length - 1 && args.delayMs > 0) {
        await sleep(args.delayMs);
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
