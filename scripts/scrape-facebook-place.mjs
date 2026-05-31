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

  // Dismiss any open popovers/dialogs (notifications panel etc.) that would
  // otherwise be picked up as the "composer dialog".
  await page.keyboard.press('Escape').catch(() => {});
  await sleep(300);
  await page.keyboard.press('Escape').catch(() => {});
  await sleep(300);

  // Click the actual composer trigger. The text "What's on your mind" is
  // wrapped by several divs; clicking an outer div doesn't always open the
  // dialog. Target the leaf [role="button"] that contains the prompt text,
  // or the textbox itself.
  const triggered = await page.evaluate(() => {
    // 1. Prefer a [role="button"] whose own text starts with "What's on your mind".
    const buttons = Array.from(document.querySelectorAll('[role="button"]'));
    for (const el of buttons) {
      const txt = (el.textContent || '').trim();
      if (txt.startsWith("What's on your mind") && txt.length < 60) {
        el.scrollIntoView();
        el.click();
        return 'button';
      }
    }
    // 2. Fallback: the textbox/textarea with that placeholder/aria-label.
    const inputs = Array.from(document.querySelectorAll('[role="textbox"], textarea, input'));
    for (const el of inputs) {
      const aria = (el.getAttribute('aria-label') || '').toLowerCase();
      const ph = (el.getAttribute('placeholder') || '').toLowerCase();
      if (aria.startsWith("what's on your mind") || ph.startsWith("what's on your mind")) {
        el.scrollIntoView();
        el.click();
        return 'textbox';
      }
    }
    return null;
  });
  if (!triggered) throw new Error('composer_trigger_not_found');

  // Wait for the composer dialog to actually appear. We identify it as a
  // dialog that contains the "Add to your post" toolbar (or a Check in /
  // Photo button), not just any new [role="dialog"].
  let composerDialogFound = false;
  for (let i = 0; i < 40; i++) {
    await sleep(250);
    composerDialogFound = await page.evaluate(() => {
      const dialogs = Array.from(document.querySelectorAll('div[role="dialog"]'));
      return dialogs.some((d) => {
        const txt = (d.textContent || '').toLowerCase();
        // Composer always contains "Add to your post" or has a Photo/video tile.
        return txt.includes('add to your post') ||
               txt.includes("what's on your mind") ||
               (txt.includes('photo') && txt.includes('check in'));
      });
    }).catch(() => false);
    if (composerDialogFound) break;
  }
  if (!composerDialogFound) {
    try { await page.screenshot({ path: '/tmp/fb-scraper-no-composer.png', fullPage: false }); } catch (_) {}
    throw new Error('composer_dialog_not_found (trigger=' + triggered + ')');
  }

  // Click the Check-in entry inside the composer dialog. Match the composer
  // by content, not by index — there may be multiple dialogs (e.g. a residual
  // notifications popover) and we want the one with the post toolbar.
  const clicked = await page.evaluate(() => {
    const dialogs = Array.from(document.querySelectorAll('div[role="dialog"]'));
    const dialog = dialogs.find((d) => {
      const txt = (d.textContent || '').toLowerCase();
      return txt.includes('add to your post') || txt.includes("what's on your mind");
    });
    if (!dialog) return { ok: false, reason: 'composer_dialog_lost' };
    const labels = [];
    const candidates = Array.from(
      dialog.querySelectorAll('[aria-label], [role="button"]')
    );
    let match = null;
    for (const el of candidates) {
      const aria = (el.getAttribute('aria-label') || '').toLowerCase();
      if (aria) labels.push(aria);
      if (aria === 'check in' || aria === 'add location' ||
          aria.startsWith('check in') || aria.includes('check-in')) {
        match = el;
        break;
      }
    }
    if (!match) {
      for (const el of Array.from(dialog.querySelectorAll('[role="button"], div, span'))) {
        const txt = (el.textContent || '').trim();
        if (txt === 'Check in') { match = el; break; }
      }
    }
    if (!match) return { ok: false, reason: 'not_in_dialog', labels: labels.slice(0, 30) };
    match.scrollIntoView();
    match.click();
    return { ok: true };
  });
  if (!clicked.ok) {
    try { await page.screenshot({ path: '/tmp/fb-scraper-no-checkin.png', fullPage: false }); } catch (_) {}
    const labelDump = clicked.labels ? ' labels=' + JSON.stringify(clicked.labels) : '';
    throw new Error('checkin_button_not_found:' + clicked.reason + labelDump);
  }

  // Wait for the place-picker input to appear. FB has shipped multiple
  // dialog variants — "Where are you?" composer picker, "Search for location"
  // standalone picker. Match any visible text-input inside an open dialog.
  let pickerReady = false;
  let pickerDebug = null;
  for (let i = 0; i < 50; i++) {
    await sleep(400);
    pickerDebug = await page.evaluate(() => {
      const sel = 'input, textarea, [contenteditable="true"], [role="combobox"], [role="searchbox"], [role="textbox"]';
      const inputs = Array.from(document.querySelectorAll(sel));
      const visible = [];
      for (const el of inputs) {
        const r = el.getBoundingClientRect();
        if (r.width < 100 || r.height < 10) continue;
        const style = window.getComputedStyle(el);
        if (style.visibility === 'hidden' || style.display === 'none') continue;
        const aria = (el.getAttribute('aria-label') || '').toLowerCase();
        if (aria.includes('search facebook')) continue;
        visible.push({
          tag: el.tagName.toLowerCase(),
          aria: el.getAttribute('aria-label') || '',
          placeholder: el.getAttribute('placeholder') || '',
          type: (el.type || '').toLowerCase(),
          role: el.getAttribute('role') || '',
          ce: el.getAttribute('contenteditable') || '',
        });
      }
      const dialogs = Array.from(document.querySelectorAll('[role="dialog"]')).map(d => ({
        label: d.getAttribute('aria-label') || '',
        labelledby: d.getAttribute('aria-labelledby') || '',
        text: (d.textContent || '').slice(0, 80),
      }));
      return { count: visible.length, inputs: visible, dialogs };
    }).catch(() => ({ count: 0, inputs: [], dialogs: [] }));
    if (pickerDebug.count > 0) { pickerReady = true; break; }
  }
  if (!pickerReady) {
    try { await page.screenshot({ path: '/tmp/fb-scraper-no-picker.png', fullPage: false }); } catch (_) {}
    throw new Error('place_picker_input_not_found:' + JSON.stringify(pickerDebug || {}));
  }
}

async function clearAndType(page, query, isFirst) {
  // Focus the place-picker input explicitly before typing. Don't rely on
  // residual focus from openCheckin — between queries FB may re-render
  // the input and lose it, sending keystrokes into the post body.
  const focused = await page.evaluate(() => {
    const inputs = Array.from(document.querySelectorAll('input'));
    let target = null;
    for (const el of inputs) {
      const r = el.getBoundingClientRect();
      if (r.width < 100 || r.height < 10) continue;
      const style = window.getComputedStyle(el);
      if (style.visibility === 'hidden' || style.display === 'none') continue;
      const t = (el.type || '').toLowerCase();
      if (t && t !== 'text' && t !== 'search') continue;
      // Skip the top-of-page "Search Facebook" input (it's also visible).
      const aria = (el.getAttribute('aria-label') || '').toLowerCase();
      if (aria.includes('search facebook')) continue;
      target = el;
      break;
    }
    if (!target) return false;
    target.focus();
    return true;
  });
  if (!focused) throw new Error('place_picker_input_lost');

  // Select-all + delete to clear any prior query (or "near me" prefill).
  await page.keyboard.down('Control');
  await page.keyboard.press('KeyA');
  await page.keyboard.up('Control');
  await page.keyboard.press('Delete');
  await sleep(200);
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
  const debugDump = process.env.FB_DEBUG_GRAPHQL === '1';
  let dumpIdx = 0;
  const onResponse = async (resp) => {
    const url = resp.url();
    if (!url.includes('/graphql') && !url.includes('/api/graphql')) return;
    try {
      const text = await resp.text();
      if (!text) return;
      if (debugDump) {
        try {
          fs.mkdirSync('/tmp/fb-graphql', { recursive: true });
          const fname = `/tmp/fb-graphql/${Date.now()}-${dumpIdx++}.txt`;
          fs.writeFileSync(fname, `URL: ${url}\n\n${text.slice(0, 20000)}`);
        } catch (_) {}
      }
      // Capture any GraphQL payload that mentions "place" / "location" /
      // entity-id pairs. FB renames the doc_id constantly so a strict
      // match misses traffic.
      if (/place_results|location_picker|checkin_search_query|"entity_id":"\d+"|"contextual_name"|placeResults|"page_id":"\d+","name":/.test(text)) {
        responses.push({ at: Date.now(), text, url });
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

  // FB rate-limited the location-typeahead endpoint for this account+IP.
  // Every response body looks like:
  //   {"errors":[{"message":"Rate limit exceeded","code":1675004}],...}
  // No point continuing the batch \u2014 surface a distinct error so the
  // caller can abort.
  const allRateLimited = responses.every(r => /"code":1675004|Rate limit exceeded/.test(r.text));
  if (allRateLimited) {
    return { id: null, name: null, error: 'rate_limited' };
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

  // Wipe Chromium session-restore state so the persistent profile doesn't
  // re-open every tab from the previous run. Also flip Preferences.exit_type
  // to "Normal" so Chromium doesn't show the "Restore tabs" prompt.
  try {
    const defaultDir = `${args.userDataDir}/Default`;
    for (const f of ['Current Tabs', 'Current Session', 'Last Tabs', 'Last Session']) {
      try { fs.unlinkSync(`${defaultDir}/${f}`); } catch (_) {}
    }
    const prefsPath = `${defaultDir}/Preferences`;
    if (fs.existsSync(prefsPath)) {
      const raw = fs.readFileSync(prefsPath, 'utf8');
      const prefs = JSON.parse(raw);
      if (prefs.profile) {
        prefs.profile.exit_type = 'Normal';
        prefs.profile.exited_cleanly = true;
      }
      fs.writeFileSync(prefsPath, JSON.stringify(prefs));
    }
  } catch (_) {}

  const browser = await puppeteer.launch({
    headless: args.headless ? 'new' : false,
    userDataDir: args.userDataDir,
    defaultViewport: { width: 1366, height: 900 },
    args: [
      '--no-sandbox',
      '--disable-setuid-sandbox',
      '--disable-blink-features=AutomationControlled',
      '--disable-session-crashed-bubble',
      '--no-default-browser-check',
      '--no-first-run',
      '--hide-crash-restore-bubble',
    ],
  });

  let exitCode = 0;
  try {
    const allPages = await browser.pages();
    const page = allPages[0] || (await browser.newPage());
    // Close any extra tabs the persistent profile restored on launch.
    for (let i = 1; i < allPages.length; i++) {
      try { await allPages[i].close({ runBeforeUnload: false }); } catch (_) {}
    }
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
