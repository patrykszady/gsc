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
import { installShutdownHandlers, launchPuppeteerWithLockRecovery } from './lib/yelp-userdata-lock.mjs';

puppeteer.use(StealthPlugin());

const SELECTORS = {
  // Yelp has shipped several login UIs. Accept any of them.
  loginEmail: 'input[type="email"], input[name="email"], input#email, input[name="username"], input[autocomplete="username"]',
  loginPassword: 'input[type="password"], input[name="password"], input#password, input[autocomplete="current-password"]',
  loginSubmit: 'button[type="submit"], button[data-button-style="primary"], form button:not([type="button"])',
  // Hidden <input type="file"> behind the "Add Photos" button on biz_photos page.
  // Keep this broad because Yelp frequently changes accept/data-testid attrs.
  fileInput: 'input[type="file"]',
  uploadTriggers: [
    '[data-testid*="photo" i][data-testid*="add" i]',
    '[data-testid*="upload" i]',
    'button[aria-label*="add photo" i]',
    'button[aria-label*="upload" i]',
    'button[title*="add photo" i]',
    'button[title*="upload" i]',
    'label[for*="file" i]',
  ].join(', '),
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

// Accepted Yelp business hosts. Yelp now redirects biz.yelp.com/ to the
// rebranded dashboard at business.yelp.com/<bizId>/home, but the actual
// biz_photos uploader endpoint is still served from biz.yelp.com/biz_photos/...
// so we accept BOTH and let direct nav to the photos URL do the work.
const BIZ_YELP_HOST_RE = /^https:\/\/(biz|business)\.yelp\.(com|co\.uk|ca|com\.au|ie|fr|de|it|es|nl|com\.mx|com\.br|com\.sg|com\.ph|com\.hk|cl|co\.nz|at|be|ch|cz|dk|fi|no|pl|pt|se|tr)\//i;

function isBizYelpUrl(url) {
  return BIZ_YELP_HOST_RE.test(url || '');
}

// Paths that Yelp serves to UNauthenticated visitors on biz.yelp.* hosts.
// Landing/signup pages happily render with no session, so being on
// biz.yelp.com.br/landing/... etc. must NOT be treated as logged-in.
const UNAUTHED_BIZ_PATH_RE = /^\/(login|signup|signup_fy21|landing|claim|welcome|forgot|reset|password|verify|signup-|account\/(login|signup))(\/|$|\?)/i;

// -----------------------------------------------------------------------------
// bizId capture + persistent cache
// -----------------------------------------------------------------------------
// Yelp's dashboard URLs leak the account's bizId in the path immediately after
// any successful login (or persistent-session reuse). We sniff every page URL
// we observe and persist the first match to a small file inside the user-data
// dir so subsequent runs skip auto-detection entirely.

// Recognised dashboard URL shapes:
//   biz.yelp.com/home/<bizId>/...           (legacy)
//   business.yelp.com/<bizId>/home          (rebranded, mid-2026+)
//   business.yelp.com/<bizId>/{photos,reviews,leads,insights,messages,settings}
function extractBizIdFromAnyUrl(u) {
  try {
    const url = new URL(u);
    if (/^biz\.yelp\.com$/i.test(url.hostname)) {
      const m = url.pathname.match(/^\/home\/([A-Za-z0-9_-]{12,})(?:\/|$)/);
      if (m) return m[1];
    }
    if (/^business\.yelp\.com$/i.test(url.hostname)) {
      const m = url.pathname.match(/^\/([A-Za-z0-9_-]{12,})\/(?:home|photos|reviews|leads|insights|messages|settings)\b/);
      if (m) return m[1];
    }
  } catch {}
  return null;
}

function bizIdCachePath(userDataDir) {
  return userDataDir ? path.join(userDataDir, '.yelp-bizid') : null;
}

function readCachedBizId(userDataDir) {
  const p = bizIdCachePath(userDataDir);
  if (!p) return null;
  try {
    const v = fs.readFileSync(p, 'utf8').trim();
    return /^[A-Za-z0-9_-]{12,}$/.test(v) ? v : null;
  } catch { return null; }
}

function writeCachedBizId(userDataDir, bizId) {
  const p = bizIdCachePath(userDataDir);
  if (!p || !bizId) return;
  try {
    fs.writeFileSync(p, bizId, 'utf8');
    console.error(`[yelp] cached bizId=${bizId} to ${p}`);
  } catch (e) {
    console.error(`[yelp] WARN: could not cache bizId: ${e.message}`);
  }
}

// Install a frame-navigation listener that opportunistically captures the
// bizId from any URL we visit and persists it on first sight. Idempotent.
function installBizIdCapture(page, userDataDir, state) {
  if (page._bizIdCaptureInstalled) return;
  page._bizIdCaptureInstalled = true;
  const capture = (url) => {
    if (state.bizId) return;
    const id = extractBizIdFromAnyUrl(url);
    if (id) {
      state.bizId = id;
      console.error(`[yelp] captured bizId=${id} from ${url}`);
      writeCachedBizId(userDataDir, id);
    }
  };
  page.on('framenavigated', (f) => { try { capture(f.url()); } catch {} });
  // Catch the current URL too in case we've already navigated past it.
  capture(page.url());
}

async function isLoggedIn(page) {
  const url = page.url();
  if (!isBizYelpUrl(url)) return false;
  let pathname = '/';
  try { pathname = new URL(url).pathname; } catch { /* noop */ }
  if (UNAUTHED_BIZ_PATH_RE.test(pathname)) return false;
  // Root marketing pages are often visible to anonymous visitors.
  // Treat only dashboard-ish paths as authenticated.
  if (pathname === '/' || /^\/($|\?)/.test(pathname)) {
    const hasBizMarker = await page.evaluate(() => {
      const html = (document.documentElement && document.documentElement.outerHTML) || '';
      return /\/home\/[A-Za-z0-9_-]{12,}|business\.yelp\.com\/[A-Za-z0-9_-]{12,}\//i.test(html);
    }).catch(() => false);
    if (!hasBizMarker) {
      return false;
    }
  }
  return true;
}

async function dumpPage(page, label) {
  try {
    const fs = await import('node:fs');
    const stamp = Date.now();
    const html = await page.content();
    const htmlFile = `/tmp/yelp-${label}-${stamp}.html`;
    fs.writeFileSync(htmlFile, html);

    // Visible text is what the user actually sees - far more useful than the
    // full HTML (which on Yelp's React SPA contains the entire i18n bundle
    // and produces thousands of false-positive grep hits for "captcha" /
    // "locked" / etc.). innerText strips invisible JS-state strings.
    let visible = '';
    try {
      visible = await page.evaluate(() => (document.body && document.body.innerText) || '');
    } catch {}
    const txtFile = `/tmp/yelp-${label}-${stamp}.txt`;
    fs.writeFileSync(txtFile, visible);

    // Screenshot is the ultimate ground truth - shows reCAPTCHA widgets,
    // "verify it's you" dialogs, error toasts, hCaptcha frames, etc.
    let pngFile = null;
    try {
      pngFile = `/tmp/yelp-${label}-${stamp}.png`;
      await page.screenshot({ path: pngFile, fullPage: true });
    } catch (e) {
      pngFile = `screenshot-failed:${e?.message}`;
    }

    console.error(`[yelp:dump] ${label} url=${page.url()} html=${htmlFile} (${html.length}b) text=${txtFile} (${visible.length}b) png=${pngFile}`);
  } catch (e) {
    console.error(`[yelp:dump] failed: ${e.message}`);
  }
}

async function waitForStablePage(page, timeoutMs = 15000) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    try {
      await page.waitForFunction(() => document.readyState === 'complete' || document.readyState === 'interactive', { timeout: 2500 });
      return true;
    } catch (e) {
      // Yelp does SPA/client redirects around auth; tolerate transient context swaps.
      if (!String(e?.message || '').includes('Execution context was destroyed')) {
        await sleep(400);
      }
    }
  }
  return false;
}

async function findSelectorWithRetries(page, selector, timeoutMs = 30000) {
  const deadline = Date.now() + timeoutMs;
  while (Date.now() < deadline) {
    try {
      await page.waitForSelector(selector, { timeout: 3500 });
      const el = await page.$(selector);
      if (el) return el;
    } catch (e) {
      const msg = String(e?.message || '');
      const isTransient =
        msg.includes('Execution context was destroyed') ||
        msg.includes('detached Frame') ||
        msg.includes('Cannot find context with specified id');
      if (!isTransient) throw e;
      await waitForStablePage(page, 4000);
    }
    await sleep(300);
  }
  return null;
}

async function tryLogin(page, email, password, timeoutMs, proxyConfig, args) {
  console.error('[yelp] navigating to login');
  await page.goto('https://biz.yelp.com/login', { waitUntil: 'domcontentloaded', timeout: timeoutMs });
  await sleep(1500);

  // /login often loads a DataDome overlay that hides the form.
  const bypassed = await maybeBypassDataDome(page, proxyConfig, args);
  if (!bypassed) {
    throw new Error('DataDome hard block could not be bypassed on /login (likely banned proxy or captcha provider failure). Rotate proxy/session and retry.');
  }

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
  await waitForStablePage(page, 10000);
  let emailEl = await findSelectorWithRetries(page, SELECTORS.loginEmail, 30000);
  if (!emailEl) {
    await dumpPage(page, 'no-email-field');
    throw new Error('login email field not found - update SELECTORS.loginEmail (HTML dumped to /tmp)');
  }
  await emailEl.click({ clickCount: 3 });
  await emailEl.type(email, { delay: 30 });

  let passEl = await findSelectorWithRetries(page, SELECTORS.loginPassword, 4000);

  if (!passEl) {
    // Likely an email-first flow - click the "Continue" / submit button to reveal
    // the password field, then wait for it.
    console.error('[yelp] email-first flow detected - submitting email to reveal password field');
    const submitFirst = await page.$(SELECTORS.loginSubmit);
    if (submitFirst) await submitFirst.click().catch(() => page.evaluate(el => el.click(), submitFirst));
    passEl = await findSelectorWithRetries(page, SELECTORS.loginPassword, 15000);
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
  const bypassedAfterSubmit = await maybeBypassDataDome(page, proxyConfig, args);
  if (!bypassedAfterSubmit) {
    throw new Error('DataDome hard block after login submit (likely banned proxy/session). Rotate proxy and retry.');
  }

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
  // The biz_photos uploader endpoint is ALWAYS served from biz.yelp.com,
  // even though Yelp rebranded the dashboard to business.yelp.com. We only
  // need to discover the bizId; once known, we construct the photos URL on
  // biz.yelp.com directly.
  const origin = 'https://biz.yelp.com';

  // Tokens that look like a path segment but are NEVER bizIds. The Yelp
  // marketing CMS at business.yelp.com serves /wp-content/, /wp-admin/,
  // /static/, /assets/, etc., and we must not mistake those for accounts.
  const BIZID_BLACKLIST = new Set([
    'wp-content', 'wp-admin', 'wp-includes', 'wp-json',
    'static', 'assets', 'public', 'images', 'image', 'img', 'photos', 'photo',
    'css', 'js', 'fonts', 'media', 'uploads', 'cdn',
    'api', 'graphql', '_next', '_nuxt', '__data',
    'home', 'login', 'logout', 'signup', 'signin', 'account', 'settings',
    'dashboard', 'support', 'help', 'contact', 'about', 'pricing',
    'reviews', 'leads', 'messages', 'insights', 'advertise', 'products',
    'biz', 'biz_photos',
  ]);
  // Real Yelp bizIds are URL-safe base64-ish strings, usually 18-30 chars
  // long, containing a healthy mix of letters/digits. Reject obvious
  // non-matches (anything in the blacklist or too short).
  const looksLikeBizId = (token) => {
    if (!token) return false;
    if (BIZID_BLACKLIST.has(token.toLowerCase())) return false;
    if (!/^[A-Za-z0-9_-]{12,}$/.test(token)) return false;
    // bizIds always contain at least one digit OR mixed case; pure-lowercase
    // single-word slugs (wp-content, hello-world, etc.) almost never qualify.
    return /[0-9]/.test(token) || /[A-Z]/.test(token);
  };

  // Try to extract bizId from the current page URL. Recognised patterns:
  //   biz.yelp.com/home/<bizId>          (legacy dashboard)
  //   business.yelp.com/<bizId>/home     (rebranded dashboard, mid-2026+)
  //   business.yelp.com/<bizId>/...      (any other rebranded sub-page)
  const extractBizIdFromUrl = (u) => {
    try {
      const url = new URL(u);
      let m = url.pathname.match(/^\/home\/([A-Za-z0-9_-]+)/);
      if (m && looksLikeBizId(m[1])) return m[1];
      if (/^business\.yelp\.com$/i.test(url.hostname)) {
        m = url.pathname.match(/^\/([A-Za-z0-9_-]+)(?:\/|$)/);
        if (m && looksLikeBizId(m[1])) return m[1];
      }
    } catch {}
    return null;
  };

  let homeBizId = extractBizIdFromUrl(page.url());
  if (homeBizId) {
    const url = `${origin}/biz_photos/${homeBizId}`;
    console.error(`[yelp] derived photos URL from dashboard URL: ${url}`);
    return url;
  }

  // Navigate to entry URLs that should force Yelp to redirect to the
  // authenticated per-business dashboard, then poll for the bizId in the URL.
  // Try several candidates because regional/AB variations affect which URL
  // resolves to /<bizId>/home vs. a generic marketing landing.
  const candidates = [
    'https://business.yelp.com/home',
    'https://biz.yelp.com/home',
    'https://business.yelp.com/',
  ];
  for (const candidate of candidates) {
    await page.goto(candidate, { waitUntil: 'networkidle2', timeout: timeoutMs }).catch(() => {});
    // Yelp's SPA dashboard often does a client-side redirect after initial paint.
    try {
      await page.waitForFunction(
        () => /\/[A-Za-z0-9_-]{12,}\/(home|photos|reviews|leads|insights|messages|settings)\b/.test(location.pathname),
        { timeout: 8000 }
      );
    } catch {}
    homeBizId = extractBizIdFromUrl(page.url());
    if (homeBizId) {
      const url = `${origin}/biz_photos/${homeBizId}`;
      console.error(`[yelp] derived photos URL after redirect via ${candidate}: ${url}`);
      return url;
    }
  }

  // Yelp's dashboard often does client-side navigations after initial paint;
  // retry evaluate() a few times so we ride out "Execution context was destroyed".
  const safeEval = async (fn) => {
    let lastErr;
    for (let i = 0; i < 5; i++) {
      try {
        return await page.evaluate(fn);
      } catch (e) {
        lastErr = e;
        if (!String(e && e.message).includes('Execution context was destroyed')) throw e;
        // Wait for the new context to settle, then retry.
        await page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 5000 }).catch(() => {});
        await sleep(800);
      }
    }
    throw lastErr;
  };

  // Common locations: sidebar nav link, "Photos" tab, business URL pattern.
  const candidateUrls = await safeEval(() => {
    const out = [];
    for (const a of document.querySelectorAll('a[href*="/biz_photos/"]')) out.push(a.href);
    // Some dashboards link to /photos/<bizId> first then redirect.
    for (const a of document.querySelectorAll('a[href*="/photos/"]')) out.push(a.href);
    return out;
  });
  for (const href of (candidateUrls || [])) {
    let m = href.match(/\/biz_photos\/([A-Za-z0-9_-]+)/);
    if (!m) m = href.match(/\/photos\/([A-Za-z0-9_-]+)/);
    if (m && looksLikeBizId(m[1])) {
      const url = `${origin}/biz_photos/${m[1]}`;
      console.error(`[yelp] detected photos URL: ${url}`);
      return url;
    }
  }

  // Last resort: try guessing from any /biz/<id>/ link in the dashboard.
  // Collect candidates and filter through looksLikeBizId so we never pick up
  // CMS asset paths like /wp-content/.
  const bizId = await safeEval(() => {
    const html = document.documentElement.innerHTML;
    const hits = [];
    for (const re of [
      /\/biz_photos\/([A-Za-z0-9_-]+)/g,
      /\/biz\/([A-Za-z0-9_-]+)/g,
      // business.yelp.com rebrand: dashboard links look like /<bizId>/home,
      // /<bizId>/photos, /<bizId>/reviews, etc.
      /business\.yelp\.com\/([A-Za-z0-9_-]+)\/(?:home|photos|reviews|leads|insights|messages|settings)\b/g,
    ]) {
      let m;
      while ((m = re.exec(html))) hits.push(m[1]);
    }
    return hits;
  });
  const filtered = (Array.isArray(bizId) ? bizId : []).filter(looksLikeBizId);
  if (filtered.length) {
    const url = `${origin}/biz_photos/${filtered[0]}`;
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
    const candidates = Array.from(document.querySelectorAll('button, a, [role="button"], label, [data-testid], [aria-label], [title]'));
    const re = /(add|upload).*(photo|image)|photo.*(add|upload)|image.*(add|upload)|add media|upload media/i;
    const trigger = candidates.find(el => {
      const text = (el.textContent || '').trim();
      const aria = el.getAttribute('aria-label') || '';
      const title = el.getAttribute('title') || '';
      const tid = el.getAttribute('data-testid') || '';
      const id = el.id || '';
      const cls = el.className || '';
      const haystack = [text, aria, title, tid, id, cls].join(' ');
      if (!re.test(haystack)) return false;
      const style = window.getComputedStyle(el);
      return style && style.display !== 'none' && style.visibility !== 'hidden';
    });
    if (trigger) {
      trigger.click();
      return (trigger.outerHTML || '').slice(0, 200);
    }
    return null;
  }).catch(() => null);
  console.error(`[yelp] upload trigger clicked: ${clicked ? 'yes' : 'no'}`);
  await sleep(1500);

  if (!clicked) {
    const clickedBySelector = await page.evaluate((selector) => {
      const nodes = Array.from(document.querySelectorAll(selector));
      const node = nodes.find(el => {
        const style = window.getComputedStyle(el);
        return style && style.display !== 'none' && style.visibility !== 'hidden';
      });
      if (!node) return null;
      node.click();
      return (node.outerHTML || '').slice(0, 200);
    }, SELECTORS.uploadTriggers).catch(() => null);
    console.error(`[yelp] upload trigger fallback clicked: ${clickedBySelector ? 'yes' : 'no'}`);
    await sleep(1200);
  }

  let fileInput = await page.waitForSelector('input[data-testid="photo-file-input"]', { timeout: 8000 }).catch(() => null);
  if (!fileInput) {
    fileInput = await page.waitForSelector(SELECTORS.fileInput, { timeout: 8000 }).catch(() => null);
  }
  if (!fileInput) {
    fileInput = await page.$('input[type="file"]');
  }
  if (!fileInput) {
    // Some Yelp variants use a native file chooser without exposing a stable
    // input in DOM. Fall back to filechooser flow.
    const openChooser = async () => {
      const chooser = await page.waitForFileChooser({ timeout: 15000 });
      await page.evaluate(() => {
        const nodes = Array.from(document.querySelectorAll('button, a, [role="button"], label, [data-testid], [aria-label], [title]'));
        const re = /(add|upload).*(photo|image)|photo.*(add|upload)|image.*(add|upload)|add media|upload media/i;
        const target = nodes.find(el => {
          const text = (el.textContent || '').trim();
          const aria = el.getAttribute('aria-label') || '';
          const title = el.getAttribute('title') || '';
          const tid = el.getAttribute('data-testid') || '';
          const id = el.id || '';
          const cls = el.className || '';
          const haystack = [text, aria, title, tid, id, cls].join(' ');
          if (!re.test(haystack)) return false;
          const style = window.getComputedStyle(el);
          return style && style.display !== 'none' && style.visibility !== 'hidden';
        });
        if (target) target.click();
      });
      return chooser;
    };

    let chooser = null;
    try {
      chooser = await openChooser();
    } catch (_) {
      chooser = null;
    }

    if (chooser) {
      console.error('[yelp] DOM file input missing; using file chooser fallback');
      await chooser.accept([photoPath]);
    } else {
      await dumpPage(page, 'no-file-input');
      await snap(page, 'no-file-input');
      throw new Error('file input/chooser not found on photos page - Yelp UI changed (see /tmp/yelp-no-file-input-*.html)');
    }
  }
  // Log which input we picked.
  const inputInfo = await page.evaluate(el => ({
    name: el.name, id: el.id, accept: el.accept, multiple: el.multiple,
    visible: !!(el.offsetWidth || el.offsetHeight),
  }), fileInput);
  console.error(`[yelp] file input found: ${JSON.stringify(inputInfo)}`);

  if (fileInput) {
    console.error(`[yelp] uploading file via input: ${photoPath}`);
    await fileInput.uploadFile(photoPath);
  } else {
    console.error(`[yelp] uploading file via chooser: ${photoPath}`);
  }

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

  // Yelp sometimes delays reflecting the new upload in the gallery count even
  // after the modal has closed and the upload has been accepted. Treat the
  // closed modal as the primary success signal, and keep the count check as a
  // diagnostic only.
  if (galleryOk && afterCount <= beforeCount) {
    await dumpPage(page, 'verify-softfail');
    console.error(`[yelp] gallery count did not increase yet (before=${beforeCount}, after=${afterCount}); trusting closed modal as success`);
  } else if (galleryOk) {
    console.error(`[yelp] verified: gallery grew by ${afterCount - beforeCount}`);
  } else {
    console.error('[yelp] gallery kept erroring - trusting modal-closed signal as success');
  }
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
  const launchArgs = [
    '--no-sandbox',
    '--disable-setuid-sandbox',
    '--disable-blink-features=AutomationControlled',
    '--disable-dev-shm-usage',
    '--disable-gpu',
    '--disable-software-rasterizer',
    '--renderer-process-limit=1',
    '--no-zygote',
    '--no-first-run',
    '--no-default-browser-check',
  ];
  if (proxyConfig) launchArgs.push(`--proxy-server=${proxyConfig.host}`);

  const browser = await launchPuppeteerWithLockRecovery({
    puppeteer,
    userDataDir: args.userDataDir,
    launchOptions: {
    headless: args.headless ? 'new' : false,
    userDataDir: args.userDataDir || undefined,
    defaultViewport: { width: 1366, height: 900 },
    args: launchArgs,
    },
  });
  installShutdownHandlers(browser);

  let exitCode = 0;
  try {
    const page = await browser.newPage();
    await page.setUserAgent(args.userAgent);
    await page.setExtraHTTPHeaders({ 'Accept-Language': 'en-US,en;q=0.9' });
    if (proxyConfig && (proxyConfig.username || proxyConfig.password)) {
      await page.authenticate({ username: proxyConfig.username, password: proxyConfig.password });
      proxyConfig._sessionUsername = proxyConfig.username;
    }

    // Opportunistically capture and cache the account's bizId from any
    // dashboard URL we navigate to. Once cached, future runs skip the
    // detectPhotosUrl() dance entirely.
    const bizIdState = { bizId: readCachedBizId(args.userDataDir) };
    if (bizIdState.bizId) {
      console.error(`[yelp] using cached bizId=${bizIdState.bizId}`);
    }
    installBizIdCapture(page, args.userDataDir, bizIdState);

    // NOTE: stale datadome cookie wiping is now CONDITIONAL inside
    // maybeBypassDataDome(): it only fires if the page is actually hard-blocked.
    // An unconditional wipe forces DataDome into a fresh fingerprint
    // evaluation that frequently scores low (t=fe) and is unsolvable,
    // so we preserve the cookie unless it proves to be the problem.

    // Initial visit + datadome handling. If the persistent profile already
    // holds a valid biz.yelp.com session, Yelp redirects '/' to '/home/<bizId>/'
    // and we can skip the (DataDome-fortified) /login flow entirely.
    await page.goto('https://biz.yelp.com/', { waitUntil: 'networkidle2', timeout: 60000 }).catch(() => {});
    await sleep(1500 + Math.random() * 1500);
    const bypassed = await maybeBypassDataDome(page, proxyConfig, args);
    if (!bypassed) {
      throw new Error('DataDome hard block on initial Yelp load (likely proxy IP banned). Rotate proxy/session and retry.');
    }

    // If Yelp regionally redirected us (e.g. to biz.yelp.com.br/landing/signup_fy21)
    // the session is effectively useless: those landing pages render fully for
    // anonymous visitors. Force the canonical US host once to give cookies a
    // fair chance, then re-evaluate.
    try {
      const u = new URL(page.url());
      // biz.yelp.com and business.yelp.com are both valid post-login landings
      // (Yelp redirects biz -> business for the rebranded dashboard). Only retry
      // if we ended up on a different host entirely or on an unauthenticated path.
      const okHost = /^(biz|business)\.yelp\.com$/i.test(u.hostname);
      if (!okHost || UNAUTHED_BIZ_PATH_RE.test(u.pathname)) {
        console.error(`[yelp] persistent session landed on ${page.url()} - retrying via biz.yelp.com/home`);
        await page.goto('https://biz.yelp.com/home', { waitUntil: 'networkidle2', timeout: 60000 }).catch(() => {});
        await sleep(1500);
        const bypassedRetry = await maybeBypassDataDome(page, proxyConfig, args);
        if (!bypassedRetry) {
          throw new Error('DataDome hard block after forcing biz.yelp.com/home (proxy likely banned). Rotate proxy/session and retry.');
        }
      }
    } catch { /* noop */ }

    if (await isLoggedIn(page)) {
      console.error(`[yelp] reusing persistent session (url=${page.url()}) - skipping /login`);
    } else {
      await tryLogin(page, args.email, args.password, args.timeoutMs, proxyConfig, args);
      if (await detectDataDome(page)) {
        await maybeBypassDataDome(page, proxyConfig, args);
      }
    }

    // If we still don't know the bizId (e.g. session restored at the marketing
    // root https://business.yelp.com/ where the URL leaks nothing), force-nav
    // to biz.yelp.com/home which 302s to /home/<bizId>/ for authed users. The
    // framenavigated listener will capture+cache it during the redirect.
    if (!bizIdState.bizId) {
      try {
        const cur = new URL(page.url());
        const onDashboard = /^biz\.yelp\.com$/i.test(cur.hostname) && /^\/home\//.test(cur.pathname);
        if (!onDashboard) {
          console.error('[yelp] no bizId yet - forcing nav to biz.yelp.com/home to capture it');
          await page.goto('https://biz.yelp.com/home', { waitUntil: 'networkidle2', timeout: 60000 }).catch(() => {});
          await sleep(1500);
          // Fallback: scrape the bizId out of the rendered HTML if the URL
          // didn't end up with /home/<id>/ (e.g. SPA-style routing).
          if (!bizIdState.bizId) {
            const scraped = await page.evaluate(() => {
              const re = /\/home\/([A-Za-z0-9_-]{12,})(?:[\/"?])|business\.yelp\.com\/([A-Za-z0-9_-]{12,})\/(?:home|photos|reviews|leads|insights|messages|settings)\b/g;
              const html = document.documentElement.outerHTML;
              const m = re.exec(html);
              return m ? (m[1] || m[2]) : null;
            }).catch(() => null);
            if (scraped) {
              bizIdState.bizId = scraped;
              console.error(`[yelp] scraped bizId=${scraped} from page HTML`);
              writeCachedBizId(args.userDataDir, scraped);
            }
          }
        }
      } catch { /* noop */ }
    }

    let photosUrl = args.photosUrl;
    if (!photosUrl && bizIdState.bizId) {
      photosUrl = `https://biz.yelp.com/biz_photos/${bizIdState.bizId}`;
      console.error(`[yelp] using cached bizId for photos URL: ${photosUrl}`);
    }
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
