#!/usr/bin/env node
/**
 * Upload one photo to the main Yelp Business Photos gallery.
 *
 * Unlike yelp-upload-portfolio-photo.mjs (per-project portfolio), this
 * targets the account-wide business photo gallery at biz.yelp.com/biz_photos.
 * After auth we navigate to https://biz.yelp.com/ and follow the
 * "Business Information" / "Photos" links, or jump directly to a known
 * --photos-url if supplied. If neither is available we auto-detect the
 * canonical photos URL from the dashboard.
 *
 * Usage:
 *   node scripts/yelp-upload-business-photo.mjs \
 *     --photo=/abs/path/to/image.jpg \
 *     --caption="Kitchen remodel - after" \
 *     --user-data-dir=/var/data/yelp-puppeteer \
 *     --email=you@example.com \
 *     --password=secret \
 *     [--photos-url=https://biz.yelp.com/biz_photos/<bizId>] \
 *     [--headed] [--proxy=http://user:pass@host:port] \
 *     [--twocaptcha-key=...] [--anticaptcha-key=...] \
 *     [--timeout-ms=180000]
 *
 * Output (last stdout line is JSON for the PHP caller):
 *   {"ok":true,"photo_id":"<id>","photos_url":"https://biz.yelp.com/biz_photos/..."}
 *   {"ok":false,"error":"<message>"}
 */

import puppeteer from 'puppeteer-extra';
import StealthPlugin from 'puppeteer-extra-plugin-stealth';
import fs from 'node:fs';
import path from 'node:path';
import { maybeBypassDataDome, detectDataDome } from './lib/yelp-datadome.mjs';

puppeteer.use(StealthPlugin());

const SELECTORS = {
  // Yelp has shipped several login UIs. Accept any of them.
  loginEmail: 'input[type="email"], input[name="email"], input#email, input[name="username"], input[autocomplete="username"]',
  loginPassword: 'input[type="password"], input[name="password"], input#password, input[autocomplete="current-password"]',
  loginSubmit: 'button[type="submit"], button[data-button-style="primary"], form button:not([type="button"])',
  // Hidden <input type="file"> behind the "Add Photos" button on biz_photos page.
  fileInput: 'input[type="file"][accept*="image"]',
  // Caption field on the per-photo editor that opens after upload.
  captionField: 'textarea[placeholder*="describe your photo" i], textarea[placeholder*="caption" i], textarea[name*="caption" i], textarea[aria-label*="caption" i]',
  // Save / done in the photo editor modal.
  saveButton: 'button[type="submit"], button[data-test*="save" i], button[data-button-style="primary"]',
};

function parseArgs(argv) {
  const args = {
    photo: null,
    caption: '',
    photosUrl: null,
    userDataDir: null,
    email: null,
    password: null,
    headless: true,
    proxy: null,
    twoCaptchaKey: null,
    antiCaptchaKey: null,
    timeoutMs: 180000,
    userAgent: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
  };
  for (const a of argv.slice(2)) {
    if (a.startsWith('--photo=')) args.photo = a.slice('--photo='.length);
    else if (a.startsWith('--caption=')) args.caption = a.slice('--caption='.length);
    else if (a.startsWith('--photos-url=')) args.photosUrl = a.slice('--photos-url='.length);
    else if (a.startsWith('--user-data-dir=')) args.userDataDir = a.slice('--user-data-dir='.length);
    else if (a.startsWith('--email=')) args.email = a.slice('--email='.length);
    else if (a.startsWith('--password=')) args.password = a.slice('--password='.length);
    else if (a.startsWith('--proxy=')) args.proxy = a.slice('--proxy='.length);
    else if (a.startsWith('--twocaptcha-key=')) args.twoCaptchaKey = a.slice('--twocaptcha-key='.length);
    else if (a.startsWith('--anticaptcha-key=')) args.antiCaptchaKey = a.slice('--anticaptcha-key='.length);
    else if (a.startsWith('--timeout-ms=')) args.timeoutMs = Number(a.slice('--timeout-ms='.length)) || args.timeoutMs;
    else if (a.startsWith('--user-agent=')) args.userAgent = a.slice('--user-agent='.length);
    else if (a === '--headed') args.headless = false;
  }
  return args;
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const emit = (p) => process.stdout.write(JSON.stringify(p) + '\n');

function parseProxyUrl(proxyUrl) {
  if (!proxyUrl) return null;
  try {
    const u = new URL(proxyUrl);
    return {
      host: `${u.protocol}//${u.hostname}:${u.port}`,
      hostname: u.hostname,
      port: u.port,
      username: decodeURIComponent(u.username || ''),
      password: decodeURIComponent(u.password || ''),
    };
  } catch {
    return null;
  }
}

async function isLoggedIn(page) {
  const url = page.url();
  return url.startsWith('https://biz.yelp.com/') && !url.includes('/login');
}

async function dumpPage(page, label) {
  try {
    const html = await page.content();
    const fs = await import('node:fs');
    const file = `/tmp/yelp-${label}-${Date.now()}.html`;
    fs.writeFileSync(file, html);
    console.error(`[yelp:dump] ${label} -> ${file} (url=${page.url()}, len=${html.length})`);
  } catch (e) {
    console.error(`[yelp:dump] failed: ${e.message}`);
  }
}

async function tryLogin(page, email, password, timeoutMs, proxyConfig, args) {
  console.error('[yelp] navigating to login');
  await page.goto('https://biz.yelp.com/login', { waitUntil: 'domcontentloaded', timeout: timeoutMs });
  await sleep(1500);

  // /login often loads a DataDome overlay that hides the form.
  await maybeBypassDataDome(page, proxyConfig, args);

  // Dismiss OneTrust / cookie-consent banners that can block form elements.
  await page.evaluate(() => {
    const btn = document.querySelector('#onetrust-accept-btn-handler, button[id*="accept"][id*="cookie" i], button[class*="cookie" i][class*="accept" i]');
    if (btn) btn.click();
  }).catch(() => {});
  await sleep(600);

  if (await isLoggedIn(page)) {
    console.error('[yelp] already logged in (cookies reused)');
    return true;
  }

  // Wait for the email field. Yelp may ship a 2-step (email first, then password)
  // flow where the password input only appears after submitting the email.
  await page.waitForSelector(SELECTORS.loginEmail, { timeout: 30000 }).catch(() => {});
  let emailEl = await page.$(SELECTORS.loginEmail);
  if (!emailEl) {
    await dumpPage(page, 'no-email-field');
    throw new Error('login email field not found - update SELECTORS.loginEmail (HTML dumped to /tmp)');
  }
  await emailEl.click({ clickCount: 3 });
  await emailEl.type(email, { delay: 30 });

  let passEl = await page.$(SELECTORS.loginPassword);

  if (!passEl) {
    // Likely an email-first flow - click the "Continue" / submit button to reveal
    // the password field, then wait for it.
    console.error('[yelp] email-first flow detected - submitting email to reveal password field');
    const submitFirst = await page.$(SELECTORS.loginSubmit);
    if (submitFirst) await submitFirst.click().catch(() => page.evaluate(el => el.click(), submitFirst));
    await page.waitForSelector(SELECTORS.loginPassword, { timeout: 15000 }).catch(() => {});
    passEl = await page.$(SELECTORS.loginPassword);
  }

  if (!passEl) {
    await dumpPage(page, 'no-password-field');
    throw new Error('login password field not found - update SELECTORS.loginPassword (HTML dumped to /tmp)');
  }
  await passEl.click({ clickCount: 3 });
  await passEl.type(password, { delay: 30 });

  const submit = await page.$(SELECTORS.loginSubmit);
  if (!submit) {
    await dumpPage(page, 'no-submit');
    throw new Error('login submit button not found');
  }
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle2', timeout: timeoutMs }).catch(() => {}),
    // Use JS click as fallback — bypasses Puppeteer's "not clickable" check
    // that fires when a cookie-consent overlay covers the button.
    submit.click().catch(() => page.evaluate(el => el.click(), submit)),
  ]);

  // Yelp may show DataDome again after submit.
  await sleep(3000);
  await maybeBypassDataDome(page, proxyConfig, args);

  if (!(await isLoggedIn(page))) {
    await dumpPage(page, 'post-submit-not-authed');
    throw new Error(
      'login did not result in authenticated session. ' +
      'Likely 2FA / captcha / device-verification. Re-run scripts/yelp-login.mjs --mode=login --headed once to complete it manually; ' +
      'the persistent userDataDir will remember the session.'
    );
  }
  console.error('[yelp] login OK');
  return true;
}

/**
 * Auto-detect the canonical /biz_photos/<id> URL by scraping the dashboard.
 */
async function detectPhotosUrl(page, timeoutMs) {
  console.error('[yelp] auto-detecting biz_photos URL');
  await page.goto('https://biz.yelp.com/', { waitUntil: 'networkidle2', timeout: timeoutMs }).catch(() => {});
  await sleep(1500);

  // Common locations: sidebar nav link, "Photos" tab, business URL pattern.
  const found = await page.evaluate(() => {
    const links = Array.from(document.querySelectorAll('a[href*="/biz_photos/"]'));
    if (links.length) return links[0].href;
    // Some dashboards link to /photos/<bizId> first then redirect.
    const alt = Array.from(document.querySelectorAll('a[href*="/photos/"]'));
    if (alt.length) return alt[0].href;
    return null;
  });
  if (found) {
    console.error(`[yelp] detected photos URL: ${found}`);
    return found;
  }

  // Last resort: try guessing from any /biz/<id>/ link in the dashboard.
  const bizId = await page.evaluate(() => {
    const m = document.documentElement.innerHTML.match(/\/biz_photos\/([A-Za-z0-9_-]+)/)
      || document.documentElement.innerHTML.match(/\/biz\/([A-Za-z0-9_-]+)/);
    return m ? m[1] : null;
  });
  if (bizId) {
    const url = `https://biz.yelp.com/biz_photos/${bizId}`;
    console.error(`[yelp] guessed photos URL: ${url}`);
    return url;
  }
  return null;
}

async function countPhotos(page) {
  return await page.evaluate(() => {
    // Yelp's biz_photos gallery uses <img> tiles inside the main grid.
    // Heuristic: count images served from photo CDN domains.
    const imgs = Array.from(document.querySelectorAll('img'));
    return imgs.filter(i => /yelpcdn\.com|ybiz\.yelp|s3-media|biz\.yelp\.com\/uploads/i.test(i.src || '')).length;
  });
}

async function snap(page, label) {
  try {
    const file = `/tmp/yelp-${label}-${Date.now()}.png`;
    await page.screenshot({ path: file, fullPage: true });
    console.error(`[yelp:snap] ${label} -> ${file}`);
  } catch (e) {
    console.error(`[yelp:snap] failed: ${e.message}`);
  }
}

async function uploadPhoto(page, photosUrl, photoPath, caption, timeoutMs) {
  console.error(`[yelp] navigating to photos page: ${photosUrl}`);
  await page.goto(photosUrl, { waitUntil: 'networkidle2', timeout: timeoutMs });
  await sleep(2000);

  if (!(await isLoggedIn(page))) {
    throw new Error('redirected away from photos URL - session not authenticated');
  }

  const beforeCount = await countPhotos(page);
  console.error(`[yelp] gallery photo count before upload: ${beforeCount}`);

  // The "Add Photos" button on the biz_photos page often hides the real file
  // input behind a styled button - click any visible upload trigger first so
  // the underlying <input type=file> mounts.
  const clicked = await page.evaluate(() => {
    const candidates = Array.from(document.querySelectorAll('button, a, [role="button"], label'));
    const trigger = candidates.find(el => {
      const t = (el.textContent || '').trim().toLowerCase();
      return /^(add photo|upload photo|add photos|upload photos|add a photo)$/i.test(t)
        || /add photo|upload photo|add photos|upload photos/.test(t);
    });
    if (trigger) {
      trigger.click();
      return trigger.outerHTML.slice(0, 200);
    }
    return null;
  }).catch(() => null);
  console.error(`[yelp] upload trigger clicked: ${clicked ? 'yes' : 'no'}`);
  await sleep(1500);

  let fileInput = await page.waitForSelector('input[data-testid="photo-file-input"]', { timeout: 15000 }).catch(() => null);
  if (!fileInput) {
    fileInput = await page.waitForSelector(SELECTORS.fileInput, { timeout: 15000 }).catch(() => null);
  }
  if (!fileInput) {
    fileInput = await page.$('input[type="file"]');
  }
  if (!fileInput) {
    await dumpPage(page, 'no-file-input');
    await snap(page, 'no-file-input');
    throw new Error('file input not found on photos page - update SELECTORS.fileInput');
  }
  // Log which input we picked.
  const inputInfo = await page.evaluate(el => ({
    name: el.name, id: el.id, accept: el.accept, multiple: el.multiple,
    visible: !!(el.offsetWidth || el.offsetHeight),
  }), fileInput);
  console.error(`[yelp] file input found: ${JSON.stringify(inputInfo)}`);

  console.error(`[yelp] uploading file: ${photoPath}`);
  await fileInput.uploadFile(photoPath);

  // Wait longer + watch for upload completion modal / save button.
  await sleep(8000);
  await snap(page, 'after-upload');
  await dumpPage(page, 'after-upload');

  if (caption) {
    const captionEl = await page.$(SELECTORS.captionField);
    if (captionEl) {
      try {
        await captionEl.focus();
        // Set value via React's native setter so React's onChange handler picks it up.
        const setOk = await page.evaluate((el, val) => {
          const proto = window.HTMLTextAreaElement.prototype;
          const setter = Object.getOwnPropertyDescriptor(proto, 'value').set;
          setter.call(el, '');
          el.dispatchEvent(new Event('input', { bubbles: true }));
          setter.call(el, val);
          el.dispatchEvent(new Event('input', { bubbles: true }));
          el.dispatchEvent(new Event('change', { bubbles: true }));
          return { value: el.value, length: el.value.length };
        }, captionEl, caption);
        // Also type one trailing space + backspace to ensure React state syncs, then blur.
        await captionEl.press('End');
        await captionEl.type(' ', { delay: 30 });
        await captionEl.press('Backspace');
        await page.evaluate((el) => el.dispatchEvent(new Event('blur', { bubbles: true })), captionEl);
        await sleep(500);
        const verify = await page.evaluate((el) => el.value, captionEl);
        console.error(`[yelp] caption set (len=${verify.length}, preview="${verify.slice(0,60).replace(/\n/g,' ')}")`);
      } catch (e) {
        console.error(`[yelp] caption set failed: ${e.message}`);
      }
    } else {
      console.error('[yelp] caption field not found - skipping');
    }
  }

  // Try clicking save/done/upload/post in any modal that appeared. Yelp's
  // post-upload modal has a red "Upload" primary button.
  const saveClicked = await page.evaluate(() => {
    const all = Array.from(document.querySelectorAll('button, [role="button"]'));
    // Prefer exact-match primary actions first.
    const exact = all.find(b => {
      const t = (b.textContent || '').trim().toLowerCase();
      return /^(upload|save|done|post|publish|submit)$/i.test(t)
        && !b.disabled && b.getAttribute('aria-disabled') !== 'true'
        && (b.offsetWidth > 0 || b.offsetHeight > 0);
    });
    if (exact) { exact.click(); return exact.textContent.trim(); }
    return null;
  }).catch(() => null);
  console.error(`[yelp] save/upload button clicked: ${saveClicked || 'none'}`);
  if (!saveClicked) {
    await dumpPage(page, 'no-save-button');
    await snap(page, 'no-save-button');
    throw new Error('upload modal save/Upload button not found - see /tmp/yelp-no-save-button-*.html');
  }

  // Wait for the dialog to actually close (= upload committed). The upload
  // can take 30-60s; do NOT navigate away while the dialog is still open or
  // we'll cancel the in-flight upload.
  console.error('[yelp] waiting for upload modal to close...');
  const modalClosed = await page.waitForFunction(
    () => {
      const dialogs = Array.from(document.querySelectorAll('[role="dialog"][aria-modal="true"]'));
      // Ignore OneTrust cookie consent dialog (always in DOM, mostly hidden).
      const real = dialogs.filter(d => {
        const label = (d.getAttribute('aria-label') || '').toLowerCase();
        if (label.includes('cookie')) return false;
        // visible?
        return d.offsetWidth > 0 || d.offsetHeight > 0;
      });
      return real.length === 0;
    },
    { timeout: 90000 }
  ).then(() => true).catch(() => false);
  await snap(page, 'after-save-click');
  if (!modalClosed) {
    await dumpPage(page, 'modal-stuck');
    throw new Error('upload modal did not close within 90s - upload likely failed (see /tmp/yelp-modal-stuck-*.html)');
  }
  console.error('[yelp] upload modal closed');
  await sleep(3000);

  // Re-navigate to gallery. Yelp's gallery sometimes shows a transient
  // "Oops! Something went wrong" page - retry a couple times.
  let afterCount = 0;
  let galleryOk = false;
  for (let attempt = 1; attempt <= 3; attempt++) {
    await page.goto(photosUrl, { waitUntil: 'networkidle2', timeout: timeoutMs }).catch(() => {});
    await sleep(3000);
    const hasError = await page.evaluate(() =>
      /Oops!\s*Something went wrong/i.test(document.body ? document.body.innerText : '')
    ).catch(() => false);
    if (hasError) {
      console.error(`[yelp] gallery error page on attempt ${attempt}, retrying...`);
      await sleep(5000);
      continue;
    }
    afterCount = await countPhotos(page);
    galleryOk = true;
    break;
  }
  console.error(`[yelp] gallery photo count after upload: ${afterCount} (galleryOk=${galleryOk})`);
  await snap(page, `gallery-final-${afterCount}`);

  // If gallery rendered OK, require the count to have grown. If it kept
  // erroring, fall back to trusting the modal-closed signal.
  if (galleryOk && afterCount <= beforeCount) {
    await dumpPage(page, 'verify-failed');
    throw new Error(`upload not visible in gallery (before=${beforeCount}, after=${afterCount}) - check /tmp/yelp-gallery-final-*.png`);
  }

  console.error(galleryOk
    ? `[yelp] verified: gallery grew by ${afterCount - beforeCount}`
    : '[yelp] gallery kept erroring - trusting modal-closed signal as success');
  return `yelp-biz-${Date.now()}-${path.basename(photoPath)}`;
}

async function main() {
  const args = parseArgs(process.argv);

  if (!args.photo || !args.email || !args.password) {
    emit({ ok: false, error: 'missing required args: --photo, --email, --password' });
    process.exit(2);
  }
  if (!fs.existsSync(args.photo)) {
    emit({ ok: false, error: `photo not found: ${args.photo}` });
    process.exit(2);
  }
  if (args.userDataDir) {
    fs.mkdirSync(args.userDataDir, { recursive: true });
  }

  const proxyConfig = parseProxyUrl(args.proxy);
  const launchArgs = ['--no-sandbox', '--disable-setuid-sandbox', '--disable-blink-features=AutomationControlled'];
  if (proxyConfig) launchArgs.push(`--proxy-server=${proxyConfig.host}`);

  const browser = await puppeteer.launch({
    headless: args.headless ? 'new' : false,
    userDataDir: args.userDataDir || undefined,
    defaultViewport: { width: 1366, height: 900 },
    args: launchArgs,
  });

  let exitCode = 0;
  try {
    const page = await browser.newPage();
    await page.setUserAgent(args.userAgent);
    await page.setExtraHTTPHeaders({ 'Accept-Language': 'en-US,en;q=0.9' });
    if (proxyConfig && (proxyConfig.username || proxyConfig.password)) {
      await page.authenticate({ username: proxyConfig.username, password: proxyConfig.password });
      proxyConfig._sessionUsername = proxyConfig.username;
    }

    // Initial visit + datadome handling. If the persistent profile already
    // holds a valid biz.yelp.com session, Yelp redirects '/' to '/home/<bizId>/'
    // and we can skip the (DataDome-fortified) /login flow entirely.
    await page.goto('https://biz.yelp.com/', { waitUntil: 'networkidle2', timeout: 60000 }).catch(() => {});
    await sleep(1500 + Math.random() * 1500);
    await maybeBypassDataDome(page, proxyConfig, args);

    if (await isLoggedIn(page)) {
      console.error(`[yelp] reusing persistent session (url=${page.url()}) - skipping /login`);
    } else {
      await tryLogin(page, args.email, args.password, args.timeoutMs, proxyConfig, args);
      if (await detectDataDome(page)) {
        await maybeBypassDataDome(page, proxyConfig, args);
      }
    }

    let photosUrl = args.photosUrl;
    if (!photosUrl) {
      photosUrl = await detectPhotosUrl(page, args.timeoutMs);
    }
    if (!photosUrl) {
      throw new Error('could not determine biz_photos URL - pass --photos-url=https://biz.yelp.com/biz_photos/<bizId>');
    }

    const photoId = await uploadPhoto(page, photosUrl, args.photo, args.caption, args.timeoutMs);
    emit({ ok: true, photo_id: photoId, photos_url: photosUrl });
  } catch (e) {
    console.error(`[yelp] error: ${e.stack || e.message}`);
    emit({ ok: false, error: e.message });
    exitCode = 1;
  } finally {
    await browser.close().catch(() => {});
    process.exit(exitCode);
  }
}

main();
