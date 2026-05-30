#!/usr/bin/env node
/**
 * Add a location tag to an existing Instagram post by driving instagram.com.
 * The post must already be published (e.g., via Graph API). This script opens
 * the post, clicks the "..." menu → "Edit", types the location query, picks
 * the matching suggestion, and clicks "Done".
 *
 * Inputs (JSON on stdin):
 *   { "permalink": "https://www.instagram.com/p/Cabc123/", "locationQuery": "Palatine, IL" }
 *
 * Output (single JSON line on stdout):
 *   { "ok": true,  "locationSelected": true|false, "matchedLabel": "Palatine, Illinois" }
 *   { "ok": false, "error": "...", "screenshot": "/path/to/error.png" }
 */
import puppeteer from 'puppeteer-extra';
import StealthPlugin from 'puppeteer-extra-plugin-stealth';
import fs from 'node:fs';
import path from 'node:path';

puppeteer.use(StealthPlugin());

function parseArgs(argv) {
  const args = { userDataDir: null, headless: true, screenshotDir: '/tmp', debug: false };
  for (const a of argv.slice(2)) {
    if (a.startsWith('--user-data-dir=')) args.userDataDir = a.slice('--user-data-dir='.length);
    else if (a === '--headed') args.headless = false;
    else if (a === '--debug') args.debug = true;
    else if (a.startsWith('--screenshot-dir=')) args.screenshotDir = a.slice('--screenshot-dir='.length);
  }
  return args;
}

async function readStdinJson() {
  const chunks = [];
  for await (const c of process.stdin) chunks.push(c);
  return JSON.parse(Buffer.concat(chunks).toString('utf8'));
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const emit = (obj) => process.stdout.write(JSON.stringify(obj) + '\n');

async function screenshot(page, dir, name) {
  try {
    fs.mkdirSync(dir, { recursive: true });
    const p = path.join(dir, `${name}-${Date.now()}.png`);
    await page.screenshot({ path: p, fullPage: false });
    return p;
  } catch {
    return null;
  }
}

async function clickByExactText(page, selector, text, { timeout = 15000 } = {}) {
  const handle = await page.waitForFunction(
    (sel, t) => {
      const els = Array.from(document.querySelectorAll(sel));
      return els.find((e) => (e.textContent || '').trim() === t) || null;
    },
    { timeout },
    selector,
    text,
  );
  const el = handle.asElement();
  if (!el) throw new Error(`element not found: ${selector} text="${text}"`);
  await el.click();
  return el;
}

async function dismissDialogs(page) {
  for (let i = 0; i < 4; i++) {
    await sleep(700);
    const dismissed = await page.evaluate(() => {
      const labels = ['Not Now', 'Not now', 'Decline optional cookies', 'Allow all cookies'];
      const btns = Array.from(document.querySelectorAll('button, div[role="button"]'));
      for (const label of labels) {
        const b = btns.find((x) => (x.textContent || '').trim() === label);
        if (b) { b.click(); return label; }
      }
      return null;
    });
    if (!dismissed) break;
  }
}

async function addLocation(page, input, opts) {
  const debugShot = async (name) => {
    if (opts.debug) await screenshot(page, opts.screenshotDir, `loc-${name}`);
  };

  // 1. Open the post.
  await page.goto(input.permalink, { waitUntil: 'networkidle2', timeout: 60000 });
  await page.waitForSelector('article, main', { timeout: 30000 });
  await sleep(1500);
  await dismissDialogs(page);
  await debugShot('1-post-open');

  // 2. Click the "More options" button (svg aria-label="More options").
  await page.waitForSelector('svg[aria-label="More options"]', { timeout: 15000 });
  {
    const box = await page.evaluate(() => {
      const svg = document.querySelector('svg[aria-label="More options"]');
      const btn = svg && (svg.closest('button') || svg.closest('div[role="button"]') || svg.closest('span'));
      if (!btn) return null;
      const r = btn.getBoundingClientRect();
      return { x: r.x + r.width / 2, y: r.y + r.height / 2 };
    });
    if (!box) throw new Error('More options button not found');
    await page.mouse.click(box.x, box.y);
  }
  await sleep(1200);
  await debugShot('2-menu-open');

  // 3. Click "Edit" in the menu — find the menu row and click its center.
  {
    const box = await page.waitForFunction(() => {
      // Menu items in the IG dialog are buttons / div[role="button"] inside a [role="dialog"].
      const dialog = document.querySelector('div[role="dialog"]');
      const root = dialog || document;
      const items = Array.from(
        root.querySelectorAll('button, div[role="button"], [tabindex="0"]'),
      );
      const item = items.find((el) => (el.textContent || '').trim() === 'Edit');
      if (!item) return null;
      const r = item.getBoundingClientRect();
      if (r.width === 0 || r.height === 0) return null;
      return { x: r.x + r.width / 2, y: r.y + r.height / 2 };
    }, { timeout: 8000 });
    const coords = await box.jsonValue();
    if (!coords) throw new Error('Edit menu item not found');
    await page.mouse.click(coords.x, coords.y);
  }
  await sleep(2000);
  await debugShot('3-edit-modal');

  // 4. Click directly on the "Add location" row in the Edit dialog and type.
  //    The visible "Add location" text IS the input's placeholder/label.
  //    Click its center coordinates so focus + caret land in the right spot,
  //    regardless of whether IG renders it as <input> or contenteditable.
  const locTargetSel = 'input[placeholder="Add location"], input[aria-label="Add location"], input[name="creation-location-input"]';
  let locBox = await page.evaluate(() => {
    // 1. Try a real <input> first.
    const inp = document.querySelector(
      'input[placeholder="Add location"], input[aria-label="Add location"], input[name="creation-location-input"]',
    );
    if (inp) {
      const r = inp.getBoundingClientRect();
      return { x: r.x + r.width / 2, y: r.y + r.height / 2, isInput: true };
    }
    // 2. Fall back to a row whose text is exactly "Add location".
    const dialog = document.querySelector('div[role="dialog"]') || document.body;
    const candidates = Array.from(dialog.querySelectorAll('div, span, button, [role="button"], [tabindex="0"]'));
    const hit = candidates.find((el) => (el.textContent || '').trim() === 'Add location');
    if (!hit) return null;
    hit.scrollIntoView({ block: 'center' });
    const r = hit.getBoundingClientRect();
    return { x: r.x + r.width / 2, y: r.y + r.height / 2, isInput: false };
  });
  if (!locBox) throw new Error('"Add location" target not found in Edit dialog');
  await page.mouse.click(locBox.x, locBox.y);
  await sleep(600);
  await debugShot('4a-location-clicked');

  // Type — works for both <input> and contenteditable since the cursor is now placed.
  await page.keyboard.type(input.locationQuery, { delay: 60 });
  await sleep(1800);
  await debugShot('4b-location-typed');

  // 5. Pick the first matching suggestion (city must match start of label).
  const cityOnly = input.locationQuery.toLowerCase().split(',')[0].trim();
  const pick = await page.evaluate((city) => {
    const all = Array.from(document.querySelectorAll('button, div[role="button"], [role="option"], li'));
    // Keep ones whose text starts with the city name and have non-zero size.
    const matches = all.filter((el) => {
      const t = (el.textContent || '').toLowerCase().trim();
      if (!(t.startsWith(city + ',') || t === city || t.startsWith(city + ' '))) return false;
      const r = el.getBoundingClientRect();
      return r.width > 0 && r.height > 0;
    });
    // The leaf-most match (smallest text length) is the suggestion row.
    matches.sort((a, b) => (a.textContent || '').length - (b.textContent || '').length);
    const target = matches[0];
    if (!target) return null;
    target.scrollIntoView({ block: 'center' });
    const r = target.getBoundingClientRect();
    return { x: r.x + r.width / 2, y: r.y + r.height / 2, label: target.textContent.trim() };
  }, cityOnly);

  let locationSelected = false;
  let matchedLabel = null;
  if (pick) {
    await page.mouse.click(pick.x, pick.y);
    locationSelected = true;
    matchedLabel = pick.label;
    await sleep(800);
  }
  await debugShot('5-after-select');

  // 6. Click "Done" to save (use mouse coordinates for reliability).
  const doneBox = await page.evaluate(() => {
    const dialog = document.querySelector('div[role="dialog"]') || document.body;
    const items = Array.from(dialog.querySelectorAll('button, div[role="button"], [tabindex="0"]'));
    const item = items.find((el) => (el.textContent || '').trim() === 'Done');
    if (!item) return null;
    const r = item.getBoundingClientRect();
    if (r.width === 0 || r.height === 0) return null;
    return { x: r.x + r.width / 2, y: r.y + r.height / 2 };
  });
  if (!doneBox) throw new Error('Done button not found');
  await page.mouse.click(doneBox.x, doneBox.y);

  // 7. Wait until the Edit dialog (the one containing "Add location" / "Done")
  //    has closed. The post-detail lightbox itself uses a role="dialog", so
  //    just checking for any dialog is wrong — check that no dialog still
  //    contains a "Done" button or the location editor.
  await page.waitForFunction(
    () => {
      const dialogs = Array.from(document.querySelectorAll('div[role="dialog"]'));
      return !dialogs.some((d) => {
        const txt = d.textContent || '';
        return /\bDone\b/.test(txt) && /Add location|Add collaborators|Accessibility/.test(txt);
      });
    },
    { timeout: 30000 },
  );
  await sleep(1500);
  await debugShot('6-saved');

  return { locationSelected, matchedLabel };
}

(async () => {
  const args = parseArgs(process.argv);
  if (!args.userDataDir) {
    emit({ ok: false, error: '--user-data-dir is required' });
    process.exit(2);
  }

  let input;
  try {
    input = await readStdinJson();
  } catch (e) {
    emit({ ok: false, error: 'invalid stdin JSON: ' + e.message });
    process.exit(2);
  }

  if (!input.permalink) {
    emit({ ok: false, error: 'permalink is required' });
    process.exit(2);
  }
  if (!input.locationQuery) {
    emit({ ok: false, error: 'locationQuery is required' });
    process.exit(2);
  }

  const browser = await puppeteer.launch({
    headless: args.headless ? 'new' : false,
    userDataDir: args.userDataDir,
    defaultViewport: { width: 1366, height: 900 },
    args: ['--no-sandbox', '--disable-setuid-sandbox', '--disable-blink-features=AutomationControlled'],
  });

  let exitCode = 0;
  try {
    const page = (await browser.pages())[0] || (await browser.newPage());
    await page.setUserAgent(
      'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    );
    const result = await addLocation(page, input, args);
    emit({ ok: true, locationSelected: result.locationSelected, matchedLabel: result.matchedLabel });
  } catch (e) {
    const shot = await screenshot((await browser.pages())[0], args.screenshotDir, 'ig-loc-error');
    emit({ ok: false, error: e.message || String(e), screenshot: shot });
    exitCode = 1;
  } finally {
    await browser.close().catch(() => {});
  }
  process.exit(exitCode);
})().catch((e) => {
  emit({ ok: false, error: 'fatal: ' + (e.message || String(e)) });
  process.exit(1);
});
