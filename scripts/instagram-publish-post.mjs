#!/usr/bin/env node
/**
 * Publish a single Instagram post (image + caption + location tag) by driving
 * the instagram.com web UI with Puppeteer. This bypasses the Graph API so we
 * can tag locations (Graph API requires App Review for that, scraped IDs not
 * accepted).
 *
 * Inputs (JSON on stdin):
 *   {
 *     "imagePath": "/abs/path/to/instagram_square.jpg",
 *     "caption":   "full caption with hashtags",
 *     "locationQuery": "Palatine, IL"   // optional
 *   }
 *
 * Output (single JSON line on stdout):
 *   { "ok": true,  "permalink": "https://www.instagram.com/p/Cabc123/" }
 *   { "ok": false, "error": "...", "screenshot": "/path/to/error.png" }
 *
 * Usage:
 *   echo '{"imagePath":"...","caption":"...","locationQuery":"Palatine, IL"}' \
 *     | node scripts/instagram-publish-post.mjs \
 *         --user-data-dir=storage/app/instagram-puppeteer
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

function emit(obj) {
  process.stdout.write(JSON.stringify(obj) + '\n');
}

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

/**
 * Click an element identified by visible text (case-sensitive substring).
 * Useful since IG's web UI does not have stable selectors.
 */
async function clickByText(page, selector, text, { timeout = 15000 } = {}) {
  const handle = await page.waitForFunction(
    (sel, t) => {
      const els = Array.from(document.querySelectorAll(sel));
      const found = els.find((e) => (e.textContent || '').trim() === t);
      return found || null;
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

async function publish(page, input, opts) {
  const debugShot = async (name) => {
    if (opts.debug) await screenshot(page, opts.screenshotDir, `step-${name}`);
  };

  // 1. Open the home feed and wait for it to fully render.
  await page.goto('https://www.instagram.com/', { waitUntil: 'networkidle2', timeout: 60000 });
  // Wait for the main nav (svg aria-label="Home") which indicates the app shell is loaded.
  await page.waitForSelector('svg[aria-label="Home"]', { timeout: 45000 });
  await sleep(1500);
  await debugShot('1-home');

  // Dismiss "Save your login info?" / "Turn on Notifications" / cookie dialogs.
  // These show up 1-3s after the feed renders, so try a few times with delay.
  for (let i = 0; i < 4; i++) {
    await sleep(800);
    const dismissed = await page.evaluate(() => {
      const labels = ['Not Now', 'Not now', 'Save Info', 'Decline optional cookies', 'Allow all cookies'];
      const btns = Array.from(document.querySelectorAll('button, div[role="button"]'));
      for (const label of labels) {
        const b = btns.find((x) => (x.textContent || '').trim() === label);
        if (b) { b.click(); return label; }
      }
      return null;
    });
    if (!dismissed) break;
  }
  await page.keyboard.press('Escape').catch(() => {});
  await sleep(500);

  // 2. Click the "Create" (New post) nav item via a real mouse click.
  const createSelector = 'svg[aria-label="New post"]';
  try {
    await page.waitForSelector(createSelector, { timeout: 15000 });
    // Find the clickable ancestor and click its center coordinates.
    const box = await page.evaluate((sel) => {
      const svg = document.querySelector(sel);
      const btn = svg && (svg.closest('a') || svg.closest('div[role="button"]') || svg.closest('button'));
      if (!btn) return null;
      const r = btn.getBoundingClientRect();
      return { x: r.x + r.width / 2, y: r.y + r.height / 2 };
    }, createSelector);
    if (!box) throw new Error('create button has no clickable ancestor');
    await page.mouse.click(box.x, box.y);
  } catch (e) {
    await debugShot('2-create-missing');
    throw new Error('Create button not found — login may have expired');
  }
  await sleep(1500);
  await debugShot('2-after-create');

  // 3. Some accounts get a "Post / Reel / Story" flyout; click Post if present.
  try {
    await page.waitForFunction(() => {
      const els = Array.from(document.querySelectorAll('a, div[role="menuitem"], div[role="button"], button, span'));
      return els.some((e) => (e.textContent || '').trim() === 'Post');
    }, { timeout: 5000 });
    await page.evaluate(() => {
      const els = Array.from(document.querySelectorAll('a, div[role="menuitem"], div[role="button"], button, span'));
      // Pick the smallest element whose visible text is exactly "Post" (i.e., the leaf label).
      const matches = els.filter((e) => (e.textContent || '').trim() === 'Post');
      const target = matches.sort((a, b) => (a.textContent || '').length - (b.textContent || '').length)[0];
      if (target) {
        const clickable = target.closest('a, button, div[role="menuitem"], div[role="button"]') || target;
        clickable.click();
      }
    });
    await sleep(1500);
  } catch {
    // flyout skipped
  }
  await debugShot('3-after-post-click');

  // 4. Wait for the upload dialog and click "Select from computer" (renders a real <input type=file>).
  //    The button text is "Select from computer".
  try {
    await clickByText(page, 'button', 'Select from computer', { timeout: 20000 });
    await sleep(500);
  } catch {
    // some versions render only the <input type=file> directly
  }

  const fileInput = await page.waitForSelector('input[type="file"]', { timeout: 30000 });
  await fileInput.uploadFile(input.imagePath);
  await sleep(2500);
  await debugShot('4-after-upload');

  // 5. Image preview / crop step. Click "Next".
  await clickByText(page, 'div[role="button"]', 'Next', { timeout: 30000 });
  await sleep(2000);
  await debugShot('5-after-next1');

  // 6. Filter step. Click "Next" again.
  await clickByText(page, 'div[role="button"]', 'Next', { timeout: 20000 });
  await sleep(2000);
  await debugShot('6-after-next2');

  // 7. Caption + location step.
  const captionSel = 'div[aria-label="Write a caption..."][contenteditable="true"]';
  await page.waitForSelector(captionSel, { timeout: 20000 });
  await page.focus(captionSel);
  await page.evaluate((sel, text) => {
    const el = document.querySelector(sel);
    el.focus();
    document.execCommand('insertText', false, text);
  }, captionSel, input.caption);
  await sleep(800);
  await debugShot('7-after-caption');

  // 8. Location search (optional).
  let locationSelected = false;
  if (input.locationQuery) {
    try {
      const locSel = 'input[name="creation-location-input"], input[placeholder="Add location"]';
      await page.waitForSelector(locSel, { timeout: 8000 });
      await page.click(locSel);
      await page.type(locSel, input.locationQuery, { delay: 60 });
      await sleep(1800);
      await debugShot('8a-location-typed');

      const clicked = await page.evaluate((query) => {
        const cityOnly = query.toLowerCase().split(',')[0].trim();
        const buttons = Array.from(document.querySelectorAll('button[role="button"], div[role="button"], li button, [role="option"]'));
        const candidate = buttons.find((b) => {
          const t = (b.textContent || '').toLowerCase().trim();
          return t && (t.startsWith(cityOnly + ',') || t === cityOnly || t.startsWith(cityOnly + ' '));
        });
        if (candidate) { candidate.click(); return candidate.textContent.trim(); }
        return null;
      }, input.locationQuery);

      if (clicked) {
        locationSelected = true;
        await sleep(800);
      }
      await debugShot('8b-after-location');
    } catch {
      // soft-fail
    }
  }

  // 9. Click "Share".
  await clickByText(page, 'div[role="button"]', 'Share', { timeout: 20000 });
  await debugShot('9-after-share-click');

  // 10. Wait for the "Your post has been shared" confirmation.
  await page.waitForFunction(
    () => {
      const txt = document.body.innerText || '';
      return /post has been shared|Your reel has been shared/i.test(txt);
    },
    { timeout: 180000 },
  );
  await sleep(2000);
  await debugShot('10-shared');

  // 11. Resolve permalink from profile.
  await page.goto('https://www.instagram.com/gs.construction.co/', { waitUntil: 'networkidle2', timeout: 45000 });
  await sleep(2000);
  const permalink = await page.evaluate(() => {
    const a = document.querySelector('main a[href*="/p/"]');
    return a ? new URL(a.getAttribute('href'), location.origin).toString() : null;
  });

  return { permalink, locationSelected };
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

  if (!input.imagePath || !fs.existsSync(input.imagePath)) {
    emit({ ok: false, error: 'imagePath missing or file not found: ' + input.imagePath });
    process.exit(2);
  }
  if (!input.caption) {
    emit({ ok: false, error: 'caption is required' });
    process.exit(2);
  }

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
    await page.setUserAgent(
      'Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36',
    );

    const result = await publish(page, input, args);
    emit({ ok: true, permalink: result.permalink, locationSelected: result.locationSelected });
  } catch (e) {
    const shot = await screenshot((await browser.pages())[0], args.screenshotDir, 'ig-publish-error');
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
