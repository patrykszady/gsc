#!/usr/bin/env node
/**
 * Upload one photo to a Yelp Portfolio Project on biz.yelp.com.
 *
 * Usage:
 *   node scripts/yelp-upload-portfolio-photo.mjs \
 *     --portfolio-url=https://biz.yelp.com/portfolio/<biz>/<project>/edit \
 *     --photo=/abs/path/to/image.jpg \
 *     --caption="Kitchen remodel - after" \
 *     --user-data-dir=/var/data/yelp-puppeteer \
 *     --email=you@example.com \
 *     --password=secret \
 *     [--headed] [--proxy=http://user:pass@host:port] \
 *     [--twocaptcha-key=...] [--timeout-ms=180000]
 *
 * IMPORTANT
 * - Yelp does NOT offer a public photo-upload API to non-partner accounts.
 *   This script automates biz.yelp.com via headless Chromium. UI selectors are
 *   best-effort and WILL break when Yelp changes the dashboard. When that
 *   happens, run with --headed to inspect, then update SELECTORS below.
 * - Doing this likely violates Yelp's Terms of Service. The Yelp account may
 *   be flagged or suspended. Use at your own risk.
 *
 * Output (stdout, last line is JSON for the PHP caller):
 *   {"ok":true,"photo_id":"<best-effort id or url>"}
 *   {"ok":false,"error":"<message>"}
 */

import puppeteer from 'puppeteer-extra';
import StealthPlugin from 'puppeteer-extra-plugin-stealth';
import fs from 'node:fs';
import path from 'node:path';
import { installShutdownHandlers, launchPuppeteerWithLockRecovery } from './lib/yelp-userdata-lock.mjs';
import { wrapProxyForChromium } from './lib/yelp-proxy.mjs';
import { loadCookiesFromFile, applyCookies } from './lib/yelp-cookies.mjs';

puppeteer.use(StealthPlugin());

// ---- Selectors that need verification against the live biz.yelp.com UI ----
// Update these as Yelp's DOM changes. Run with --headed to inspect.
const SELECTORS = {
  // Login page
  loginEmail: 'input[name="email"], input#email',
  loginPassword: 'input[name="password"], input#password',
  loginSubmit: 'button[type="submit"]',
  // Portfolio project edit page
  fileInput: 'input[type="file"][accept*="image"]',
  // Caption field appears in the per-photo editor modal/section after upload
  captionField: 'textarea[name*="caption" i], textarea[aria-label*="caption" i], input[name*="caption" i]',
  // The save / done button in the photo editor
  saveButton: 'button[type="submit"], button:has-text("Save"), button:has-text("Done")',
};

function parseArgs(argv) {
  const args = {
    portfolioUrl: null,
    photo: null,
    caption: '',
    userDataDir: null,
    email: null,
    password: null,
    headless: true,
    proxy: null,
    twocaptchaKey: null,
    cookiesFile: null,
    timeoutMs: 180000,
    userAgent: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
  };
  for (const arg of argv.slice(2)) {
    if (arg.startsWith('--portfolio-url=')) args.portfolioUrl = arg.slice('--portfolio-url='.length);
    else if (arg.startsWith('--photo=')) args.photo = arg.slice('--photo='.length);
    else if (arg.startsWith('--caption=')) args.caption = arg.slice('--caption='.length);
    else if (arg.startsWith('--user-data-dir=')) args.userDataDir = arg.slice('--user-data-dir='.length);
    else if (arg.startsWith('--email=')) args.email = arg.slice('--email='.length);
    else if (arg.startsWith('--password=')) args.password = arg.slice('--password='.length);
    else if (arg.startsWith('--proxy=')) args.proxy = arg.slice('--proxy='.length);
    else if (arg.startsWith('--twocaptcha-key=')) args.twocaptchaKey = arg.slice('--twocaptcha-key='.length);
    else if (arg.startsWith('--cookies-file=')) args.cookiesFile = arg.slice('--cookies-file='.length);
    else if (arg.startsWith('--timeout-ms=')) args.timeoutMs = Number(arg.slice('--timeout-ms='.length)) || args.timeoutMs;
    else if (arg.startsWith('--user-agent=')) args.userAgent = arg.slice('--user-agent='.length);
    else if (arg === '--headed') args.headless = false;
  }
  return args;
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

function emit(payload) {
  // last line on stdout: machine-readable JSON.
  process.stdout.write(JSON.stringify(payload) + '\n');
}

async function isLoggedIn(page) {
  // biz.yelp.com redirects unauthenticated users to login page.
  const url = page.url();
  return url.startsWith('https://biz.yelp.com/') && !url.includes('/login');
}

async function tryLogin(page, email, password, timeoutMs) {
  console.error('[yelp] navigating to login');
  await page.goto('https://biz.yelp.com/login', { waitUntil: 'domcontentloaded', timeout: timeoutMs });

  // If we got bounced because already logged in, exit early.
  if (await isLoggedIn(page)) {
    console.error('[yelp] already logged in (cookies reused)');
    return true;
  }

  await page.waitForSelector(SELECTORS.loginEmail, { timeout: 30000 }).catch(() => {});
  await page.waitForSelector(SELECTORS.loginPassword, { timeout: 30000 }).catch(() => {});

  const emailEl = await page.$(SELECTORS.loginEmail);
  const passEl = await page.$(SELECTORS.loginPassword);
  if (!emailEl || !passEl) {
    throw new Error('login fields not found - update SELECTORS.loginEmail/loginPassword');
  }

  await emailEl.click({ clickCount: 3 });
  await emailEl.type(email, { delay: 30 });
  await passEl.click({ clickCount: 3 });
  await passEl.type(password, { delay: 30 });

  const submit = await page.$(SELECTORS.loginSubmit);
  if (!submit) throw new Error('login submit button not found');
  await Promise.all([
    page.waitForNavigation({ waitUntil: 'networkidle2', timeout: timeoutMs }).catch(() => {}),
    submit.click(),
  ]);

  // Yelp may show 2FA, captcha, or "verify your device" interstitials.
  await sleep(3000);
  if (!(await isLoggedIn(page))) {
    throw new Error(
      'login did not result in authenticated session. ' +
      'Likely 2FA / captcha / device-verification. Re-run with --headed once to complete it manually; ' +
      'the persistent userDataDir will remember the session.'
    );
  }
  console.error('[yelp] login OK');
  return true;
}

async function uploadPhoto(page, portfolioUrl, photoPath, caption, timeoutMs) {
  console.error(`[yelp] navigating to portfolio: ${portfolioUrl}`);
  await page.goto(portfolioUrl, { waitUntil: 'domcontentloaded', timeout: timeoutMs });

  if (!(await isLoggedIn(page))) {
    throw new Error('redirected away from portfolio URL - session not authenticated');
  }

  // Wait for the file input to appear. Yelp's portfolio editor uses a hidden
  // <input type="file"> behind an "Add photos" button.
  const fileInput = await page.waitForSelector(SELECTORS.fileInput, { timeout: 30000 }).catch(() => null);
  if (!fileInput) {
    throw new Error('file input not found on portfolio page - update SELECTORS.fileInput');
  }

  console.error(`[yelp] uploading file: ${photoPath}`);
  await fileInput.uploadFile(photoPath);

  // Give Yelp time to upload and render the per-photo editor.
  await sleep(5000);

  // Try to set caption (best-effort).
  if (caption) {
    const captionEl = await page.$(SELECTORS.captionField);
    if (captionEl) {
      try {
        await captionEl.click({ clickCount: 3 });
        await captionEl.type(caption, { delay: 20 });
        console.error('[yelp] caption set');
      } catch (e) {
        console.error(`[yelp] caption set failed: ${e.message}`);
      }
    } else {
      console.error('[yelp] caption field not found - skipping');
    }
  }

  // Click save/done. Yelp dashboards often auto-save uploads, so missing this
  // button is not necessarily fatal.
  const saveBtn = await page.$(SELECTORS.saveButton);
  if (saveBtn) {
    await saveBtn.click().catch(() => {});
    await sleep(3000);
  }

  // Best-effort photo identifier: hash of upload time + filename.
  const photoId = `yelp-${Date.now()}-${path.basename(photoPath)}`;
  return photoId;
}

async function main() {
  const args = parseArgs(process.argv);

  if (!args.portfolioUrl || !args.photo || !args.email || !args.password) {
    emit({ ok: false, error: 'missing required args: --portfolio-url, --photo, --email, --password' });
    process.exit(2);
  }
  if (!fs.existsSync(args.photo)) {
    emit({ ok: false, error: `photo not found: ${args.photo}` });
    process.exit(2);
  }
  if (args.userDataDir) {
    fs.mkdirSync(args.userDataDir, { recursive: true });
  }

  const launchOpts = {
    headless: args.headless ? 'new' : false,
    userDataDir: args.userDataDir || undefined,
    defaultViewport: { width: 1366, height: 900 },
    args: [
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
    ],
  };
  const proxyWrap = await wrapProxyForChromium(args.proxy);
  if (proxyWrap) {
    // proxy-chain local forwarder — see yelp-proxy.mjs for rationale.
    launchOpts.args.push(`--proxy-server=${proxyWrap.localUrl}`);
    launchOpts.args.push('--ignore-certificate-errors');
  }

  const browser = await launchPuppeteerWithLockRecovery({
    puppeteer,
    userDataDir: args.userDataDir,
    launchOptions: launchOpts,
  });
  installShutdownHandlers(browser);
  if (args.cookiesFile) {
    try {
      const cookies = loadCookiesFromFile(args.cookiesFile);
      const n = await applyCookies(browser, cookies);
      console.error(`[yelp-portfolio] injected ${n} cookies from ${args.cookiesFile}`);
    } catch (e) {
      console.error(`[yelp-portfolio] cookie injection failed: ${e?.message || e}`);
    }
  }
  let exitCode = 0;
  try {
    const page = await browser.newPage();
    await page.setUserAgent(args.userAgent);

    // proxy-chain forwarder handles upstream auth; nothing to do here.

    await tryLogin(page, args.email, args.password, args.timeoutMs);
    const photoId = await uploadPhoto(page, args.portfolioUrl, args.photo, args.caption, args.timeoutMs);

    emit({ ok: true, photo_id: photoId });
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
