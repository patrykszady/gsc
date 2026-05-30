#!/usr/bin/env node
/**
 * Open biz.yelp.com in a headed Chromium so the user can complete login,
 * 2FA, captcha, or device-verification interactively. Session cookies are
 * persisted in --user-data-dir so subsequent headless uploads "just work".
 *
 * Usage:
 *   node scripts/yelp-login.mjs \
 *     --user-data-dir=/var/data/yelp-puppeteer \
 *     --email=you@example.com \
 *     --password=secret \
 *     [--mode=login|check] [--timeout-ms=600000] \
 *     [--proxy=http://user:pass@host:port] [--twocaptcha-key=...]
 *
 * Output (last stdout line is JSON for the PHP caller):
 *   {"ok":true,"authenticated":true}
 *   {"ok":false,"error":"..."}
 */

import puppeteer from 'puppeteer-extra';
import StealthPlugin from 'puppeteer-extra-plugin-stealth';
import fs from 'node:fs';
import path from 'node:path';
import { installShutdownHandlers, launchPuppeteerWithLockRecovery } from './lib/yelp-userdata-lock.mjs';
import { wrapProxyForChromium } from './lib/yelp-proxy.mjs';
import { loadCookiesFromFile, applyCookies } from './lib/yelp-cookies.mjs';

puppeteer.use(StealthPlugin());

const SELECTORS = {
  loginEmail: 'input[type="email"], input[name="email"], input#email, input[name="username"], input[autocomplete="username"]',
  loginPassword: 'input[type="password"], input[name="password"], input#password, input[autocomplete="current-password"]',
  loginSubmit: 'button[type="submit"], button[data-button-style="primary"], form button:not([type="button"])',
};

function parseArgs(argv) {
  const args = {
    userDataDir: null,
    email: null,
    password: null,
    mode: 'login',
    timeoutMs: 600000,
    proxy: null,
    twocaptchaKey: null,
    cookiesFile: null,
    userAgent:
      'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
  };
  for (const a of argv.slice(2)) {
    if (a.startsWith('--user-data-dir=')) args.userDataDir = a.slice('--user-data-dir='.length);
    else if (a.startsWith('--email=')) args.email = a.slice('--email='.length);
    else if (a.startsWith('--password=')) args.password = a.slice('--password='.length);
    else if (a.startsWith('--mode=')) args.mode = a.slice('--mode='.length);
    else if (a.startsWith('--timeout-ms=')) args.timeoutMs = Number(a.slice('--timeout-ms='.length)) || args.timeoutMs;
    else if (a.startsWith('--proxy=')) args.proxy = a.slice('--proxy='.length);
    else if (a.startsWith('--twocaptcha-key=')) args.twocaptchaKey = a.slice('--twocaptcha-key='.length);
    else if (a.startsWith('--cookies-file=')) args.cookiesFile = a.slice('--cookies-file='.length);
    else if (a.startsWith('--user-agent=')) args.userAgent = a.slice('--user-agent='.length);
  }
  return args;
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const emit = (p) => process.stdout.write(JSON.stringify(p) + '\n');

function isAuthedUrl(url) {
  // Must match the dashboard URL shapes the upload script also uses:
  //   biz.yelp.com/home/<bizId>(/...)
  //   biz.yelp.com/biz_photos/<bizId>(/...)
  //   biz.yelp.com/biz/<bizId>(/...)
  //   business.yelp.com/<bizId>/{home,photos,reviews,leads,insights,messages,settings}
  // Anything else (bare "/", "/home", "/login", regional landings, marketing
  // root) is NOT authed — accepting them gives a false positive that the
  // operator's session is good when the cookies are actually missing/expired.
  try {
    const u = new URL(url);
    const host = u.hostname.toLowerCase();
    const path = u.pathname || '/';
    if (host === 'biz.yelp.com') {
      return /^\/(home\/[A-Za-z0-9_-]{12,}|biz_photos\/[A-Za-z0-9_-]{12,}|biz\/[A-Za-z0-9_-]{12,})(\/|$)/i.test(path);
    }
    if (host === 'business.yelp.com') {
      return /^\/[A-Za-z0-9_-]{12,}\/(home|photos|reviews|leads|insights|messages|settings)(\/|$)/i.test(path);
    }
    return false;
  } catch {
    return false;
  }
}

function parseProxyUrl(proxyUrl) {
  if (!proxyUrl) return null;
  try {
    const url = new URL(proxyUrl);
    return {
      host: `${url.protocol}//${url.hostname}:${url.port}`,
      hostname: url.hostname,
      port: url.port,
      username: decodeURIComponent(url.username || ''),
      password: decodeURIComponent(url.password || ''),
    };
  } catch {
    return null;
  }
}

async function buildBrowser(args, headless) {
  if (args.userDataDir) fs.mkdirSync(args.userDataDir, { recursive: true });
  // Pre-launch: rewrite Default/Preferences so Chromium doesn't try to
  // restore the previous session's tabs (operator was getting 7+ stale
  // biz.yelp tabs across launches) and doesn't show the "Chrome didn't
  // shut down correctly" infobar.
  if (args.userDataDir) {
    try {
      const prefsPath = path.join(args.userDataDir, 'Default', 'Preferences');
      if (fs.existsSync(prefsPath)) {
        const prefs = JSON.parse(fs.readFileSync(prefsPath, 'utf8'));
        prefs.profile = prefs.profile || {};
        prefs.profile.exit_type = 'Normal';
        prefs.profile.exited_cleanly = true;
        prefs.session = prefs.session || {};
        prefs.session.restore_on_startup = 5; // "Open New Tab Page"
        delete prefs.session.startup_urls;
        fs.writeFileSync(prefsPath, JSON.stringify(prefs));
      }
      // Preferences alone is NOT enough — Chromium reads the actual tab
      // list from Default/Sessions/{Session,Tabs}_* files and restores
      // them regardless of the restore_on_startup pref. Delete them.
      // We also nuke Default/Current Session / Current Tabs / Last Session
      // / Last Tabs which Chromium uses as fallbacks.
      const sessionDir = path.join(args.userDataDir, 'Default', 'Sessions');
      if (fs.existsSync(sessionDir)) {
        for (const f of fs.readdirSync(sessionDir)) {
          try { fs.unlinkSync(path.join(sessionDir, f)); } catch (_) {}
        }
      }
      for (const f of ['Current Session', 'Current Tabs', 'Last Session', 'Last Tabs']) {
        const p = path.join(args.userDataDir, 'Default', f);
        try { if (fs.existsSync(p)) fs.unlinkSync(p); } catch (_) {}
      }
    } catch (_) { /* best effort */ }
  }
  const launchArgs = ['--no-sandbox', '--disable-setuid-sandbox', '--disable-blink-features=AutomationControlled', '--hide-crash-restore-bubble'];
  // Wrap upstream proxy in a local auth-injecting forwarder. Chromium's
  // --proxy-server flag does NOT support `user:pass@host` for HTTPS-via-CONNECT
  // (page.authenticate() fails with ERR_PROXY_AUTH_UNSUPPORTED on the first
  // CONNECT). proxy-chain spins up a local HTTP proxy that injects the upstream
  // credentials and exposes an auth-free target to Chromium.
  const proxyConfig = await wrapProxyForChromium(args.proxy);
  if (proxyConfig) {
    launchArgs.push(`--proxy-server=${proxyConfig.localUrl}`);
    // Bright Data residential proxies present their own CA on HTTPS — without
    // installing that CA system-wide, Chromium throws ERR_CERT_AUTHORITY_INVALID
    // on every navigation. Accept the proxy's cert chain. Safe here because
    // the only traffic going through this Chromium is to known target sites.
    launchArgs.push('--ignore-certificate-errors');
  }
  const browser = await launchPuppeteerWithLockRecovery({
    puppeteer,
    userDataDir: args.userDataDir,
    launchOptions: {
      headless: headless ? 'new' : false,
      userDataDir: args.userDataDir || undefined,
      defaultViewport: { width: 1366, height: 900 },
      args: launchArgs,
    },
  });
  installShutdownHandlers(browser);
  if (args.cookiesFile) {
    try {
      const cookies = loadCookiesFromFile(args.cookiesFile);
      const n = await applyCookies(browser, cookies);
      console.error(`[yelp-login] injected ${n} cookies from ${args.cookiesFile}`);
    } catch (e) {
      console.error(`[yelp-login] cookie injection failed: ${e?.message || e}`);
    }
  }
  return { browser, proxyConfig };
}

async function setupPage(page, args, proxyConfig) {
  await page.setUserAgent(args.userAgent);
  await page.setExtraHTTPHeaders({ 'Accept-Language': 'en-US,en;q=0.9' });
  if (proxyConfig && (proxyConfig.username || proxyConfig.password)) {
    // proxy-chain handles upstream auth; record session username for the
    // 2captcha DataDome bypass which still needs the upstream URL with creds.
    proxyConfig._sessionUsername = proxyConfig.username;
  }
  attachInteractionLogging(page);
}

// Attach event listeners that surface every meaningful browser action to
// stderr (and thus to storage/logs/yelp-remote-chrome.log, which the admin
// panel live-tails). Lets us debug what the operator is doing inside the
// noVNC viewer without screen-sharing.
function attachInteractionLogging(page, label = 'main') {
  if (page._interactionLoggingInstalled) return;
  page._interactionLoggingInstalled = true;

  page.on('framenavigated', (frame) => {
    if (frame === page.mainFrame()) {
      console.error(`[browser:${label}] nav ${frame.url()}`);
    }
  });
  page.on('console', (msg) => {
    const type = msg.type();
    if (type === 'error' || type === 'warning') {
      const text = msg.text();
      if (text && text.length < 400) {
        console.error(`[browser:${label}] console.${type}: ${text}`);
      }
    }
  });
  page.on('pageerror', (err) => {
    console.error(`[browser:${label}] pageerror: ${err?.message || err}`);
  });
  page.on('dialog', (dlg) => {
    console.error(`[browser:${label}] dialog ${dlg.type()}: ${dlg.message()}`);
  });
  page.on('response', (res) => {
    try {
      const req = res.request();
      if (req.resourceType() !== 'document') return;
      if (req.frame() !== page.mainFrame()) return;
      const status = res.status();
      if (status >= 400 || (status >= 300 && status < 400)) {
        console.error(`[browser:${label}] doc ${status} ${req.method()} ${res.url()}`);
      }
    } catch {}
  });
  page.on('requestfailed', (req) => {
    try {
      if (req.resourceType() !== 'document') return;
      const err = req.failure()?.errorText || 'unknown';
      console.error(`[browser:${label}] reqfailed ${req.method()} ${req.url()} - ${err}`);
    } catch {}
  });
}

// ---- DataDome handling ----
async function detectDataDome(page) {
  try {
    return await page.evaluate(() => {
      const iframes = Array.from(document.querySelectorAll('iframe'));
      for (const iframe of iframes) {
        const src = iframe.getAttribute('src') || '';
        if (src.includes('captcha-delivery.com')) return src;
      }
      const html = document.documentElement.innerHTML || '';
      if (html.includes('captcha-delivery.com')) {
        const m = html.match(/src=["'](https?:\/\/[^"']*captcha-delivery\.com[^"']*)/);
        if (m) return m[1];
      }
      return null;
    });
  } catch {
    return null;
  }
}

async function solveDataDome(captchaUrl, pageUrl, proxyStr, userAgent, apiKey) {
  console.error('[datadome] submitting challenge to 2captcha');
  const t = new URL(captchaUrl).searchParams.get('t');
  if (t === 'bv') {
    console.error('[datadome] t=bv - IP banned, rotate proxy');
    return null;
  }
  const submitRes = await fetch('https://2captcha.com/in.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({
      key: apiKey,
      method: 'datadome',
      captcha_url: captchaUrl,
      pageurl: pageUrl,
      userAgent,
      proxy: proxyStr,
      proxytype: 'http',
      json: 1,
    }),
  });
  const submitData = await submitRes.json();
  if (submitData.status !== 1) {
    console.error(`[datadome] submit error: ${submitData.request}`);
    return null;
  }
  const taskId = submitData.request;
  console.error(`[datadome] task ${taskId} submitted, polling...`);
  for (let i = 0; i < 36; i++) {
    await sleep(5000);
    const r = await fetch(`https://2captcha.com/res.php?key=${apiKey}&action=get&id=${taskId}&json=1`);
    const d = await r.json();
    if (d.status === 1) {
      console.error('[datadome] solved');
      return d.request;
    }
    if (d.request !== 'CAPCHA_NOT_READY') {
      console.error(`[datadome] error: ${d.request}`);
      return null;
    }
  }
  console.error('[datadome] timed out');
  return null;
}

function parseDataDomeCookie(cookieStr) {
  const parts = cookieStr.split(';').map((p) => p.trim());
  const kv = parts.find((p) => p.startsWith('datadome='));
  return kv ? kv.slice('datadome='.length) : null;
}

async function maybeBypassDataDome(page, proxyConfig, args) {
  // Give the DataDome JS challenge ("Verifying the device...") a chance to
  // self-resolve via stealth before we pay 2captcha.
  for (let i = 0; i < 6; i++) {
    if (!(await detectDataDome(page))) return true;
    await sleep(2000);
  }

  const captchaUrl = await detectDataDome(page);
  if (!captchaUrl) return true;

  // Soft block: page DOM still has real content underneath the overlay.
  const bodyLen = await page.evaluate(() => document.documentElement.innerHTML.length).catch(() => 0);
  if (bodyLen > 50000) {
    console.error(`[datadome] soft block (${bodyLen} bytes), proceeding`);
    return true;
  }

  console.error('[datadome] hard block on ' + page.url());
  if (!args.twocaptchaKey || !proxyConfig) {
    console.error('[datadome] cannot solve: need both --proxy and --twocaptcha-key');
    return false;
  }

  let actualPageUrl = page.url();
  try {
    const ref = new URL(captchaUrl).searchParams.get('referer');
    if (ref) actualPageUrl = ref;
  } catch {}

  const proxyStr = `${proxyConfig._sessionUsername || proxyConfig.username}:${proxyConfig.password}@${proxyConfig.hostname}:${proxyConfig.port}`;
  const cookieStr = await solveDataDome(captchaUrl, actualPageUrl, proxyStr, args.userAgent, args.twocaptchaKey);
  if (!cookieStr) return false;
  const value = parseDataDomeCookie(cookieStr);
  if (!value) return false;

  const cdp = await page.createCDPSession();
  await cdp.send('Network.deleteCookies', { name: 'datadome', domain: '.yelp.com' }).catch(() => {});
  await cdp.detach();
  for (const domain of ['.yelp.com', '.biz.yelp.com']) {
    await page.setCookie({ name: 'datadome', value, domain, path: '/', secure: true, sameSite: 'Lax' });
  }
  await page.evaluate((v) => {
    document.cookie = `datadome=${v}; domain=.yelp.com; path=/; secure; SameSite=Lax`;
  }, value).catch(() => {});

  // After the cookie is set, block DataDome's tag scripts so they can't
  // re-fingerprint the browser and invalidate our cookie.
  if (!page._ddInterceptInstalled) {
    page._ddInterceptInstalled = true;
    await page.setRequestInterception(true);
    page.on('request', (req) => {
      const u = req.url();
      if (u.includes('captcha-delivery.com') || u.includes('datadome.co') || u.endsWith('/tags.js')) {
        req.abort().catch(() => {});
      } else {
        req.continue().catch(() => {});
      }
    });
  }

  await page.reload({ waitUntil: 'networkidle2', timeout: 60000 }).catch(() => {});
  await sleep(3000);
  if (await detectDataDome(page)) {
    console.error('[datadome] still blocked after cookie injection');
    return false;
  }
  console.error('[datadome] bypassed');
  return true;
}

// ---- Modes ----
function humanDelay() {
  // 80-180ms between keystrokes - roughly matches a real typist.
  return 80 + Math.floor(Math.random() * 100);
}

async function typeHumanLike(el, text) {
  for (const ch of text) {
    await el.type(ch, { delay: humanDelay() });
  }
}

async function waitForVisible(page, selector, timeout = 20000) {
  // Wait for the element to be present AND visible AND not disabled. Yelp's
  // login form mounts via JS after a brief delay, so the selector can match
  // before the input is actually interactive.
  return page.waitForFunction(
    (sel) => {
      const el = document.querySelector(sel);
      if (!el) return false;
      const rect = el.getBoundingClientRect();
      if (rect.width === 0 || rect.height === 0) return false;
      const style = window.getComputedStyle(el);
      if (style.visibility === 'hidden' || style.display === 'none') return false;
      if (el.disabled || el.readOnly) return false;
      return true;
    },
    { timeout, polling: 200 },
    selector,
  );
}

async function waitForSubmitEnabled(page, timeout = 10000) {
  // Yelp enables the submit button only after JS validates the form.
  // Poll using the same allow/block rules as clickSubmit.
  try {
    await page.waitForFunction(
      () => {
        const allowed = new Set(['continue', 'next', 'log in', 'sign in', 'login', 'submit']);
        const blocked = ['google', 'apple', 'facebook', 'email', 'link', 'forgot', 'claim'];
        const candidates = Array.from(document.querySelectorAll('button, input[type="submit"], a[role="button"], [role="button"]'));
        for (const el of candidates) {
          const raw = (el.innerText || el.value || '').trim().toLowerCase();
          if (!raw) continue;
          if (blocked.some((kw) => raw.includes(kw))) continue;
          if (!allowed.has(raw)) continue;
          const r = el.getBoundingClientRect();
          const s = window.getComputedStyle(el);
          if (r.width === 0 || r.height === 0) continue;
          if (s.visibility === 'hidden' || s.display === 'none') continue;
          if (el.disabled) continue;
          if (el.getAttribute('aria-disabled') === 'true') continue;
          return true;
        }
        return false;
      },
      { timeout, polling: 200 },
    );
    return true;
  } catch {
    return false;
  }
}

async function clickSubmit(page, label) {
  // Try the CSS selector first.
  const byCss = await page.$(SELECTORS.loginSubmit);
  if (byCss) {
    const visible = await page.evaluate((el) => {
      const r = el.getBoundingClientRect();
      const s = window.getComputedStyle(el);
      if (r.width === 0 || r.height === 0) return false;
      if (s.visibility === 'hidden' || s.display === 'none') return false;
      if (el.disabled) return false;
      if (el.getAttribute('aria-disabled') === 'true') return false;
      return true;
    }, byCss).catch(() => false);
    if (visible) {
      console.error(`[yelp-login] clicking submit (css) for ${label}`);
      await byCss.click().catch(() => {});
      return true;
    }
  }
  // Fall back to finding a button by visible text (Continue / Next / Log in / Sign in).
  const handle = await page.evaluateHandle(() => {
    // Exact-match whitelist of acceptable button labels.
    const allowed = new Set(['continue', 'next', 'log in', 'sign in', 'login', 'submit']);
    // Anything containing these substrings is a social/alt-login button - skip.
    const blocked = ['google', 'apple', 'facebook', 'email', 'link', 'forgot', 'claim'];
    const candidates = Array.from(document.querySelectorAll('button, input[type="submit"], a[role="button"], [role="button"]'));
    for (const el of candidates) {
      const raw = (el.innerText || el.value || '').trim().toLowerCase();
      if (!raw) continue;
      if (blocked.some((kw) => raw.includes(kw))) continue;
      if (!allowed.has(raw)) continue;
      const r = el.getBoundingClientRect();
      const s = window.getComputedStyle(el);
      if (r.width === 0 || r.height === 0) continue;
      if (s.visibility === 'hidden' || s.display === 'none') continue;
      if (el.disabled) continue;
      if (el.getAttribute('aria-disabled') === 'true') continue;
      return el;
    }
    return null;
  });
  const el = handle.asElement();
  if (el) {
    console.error(`[yelp-login] clicking submit (text-match) for ${label}`);
    await el.click().catch(() => {});
    await handle.dispose();
    return true;
  }
  await handle.dispose();
  // Last resort: press Enter in the currently-focused field.
  console.error(`[yelp-login] no submit button found; pressing Enter for ${label}`);
  await page.keyboard.press('Enter').catch(() => {});
  return false;
}

async function autofillLogin(page, email, password) {
  // Give the page a generous settle period - Yelp hydrates the login form
  // via JS and the input is briefly non-interactive after first paint.
  console.error('[yelp-login] waiting for email field to become interactive');
  try {
    await waitForVisible(page, SELECTORS.loginEmail, 20000);
  } catch {
    console.error('[yelp-login] email field never became interactive; leaving form for manual entry');
    return;
  }
  await sleep(1500 + Math.random() * 1000);

  const emailEl = await page.$(SELECTORS.loginEmail);
  if (!emailEl) {
    console.error('[yelp-login] email field disappeared; leaving form for manual entry');
    return;
  }
  console.error('[yelp-login] autofilling email');
  await emailEl.focus();
  await sleep(200 + Math.random() * 300);
  await emailEl.click({ clickCount: 3 });
  await sleep(300 + Math.random() * 300);
  await typeHumanLike(emailEl, email);

  let passEl = await page.$(SELECTORS.loginPassword);
  if (!passEl) {
    // Email-first 2-step flow: submit email, wait for password field.
    console.error('[yelp-login] email-first flow; submitting to reveal password field');
    await sleep(500 + Math.random() * 500);
    console.error('[yelp-login] waiting for email-step submit to enable');
    await waitForSubmitEnabled(page, 10000);
    await clickSubmit(page, 'email-step');
    try {
      await waitForVisible(page, SELECTORS.loginPassword, 15000);
    } catch {}
    await sleep(800 + Math.random() * 600);
    passEl = await page.$(SELECTORS.loginPassword);
  }
  if (!passEl) {
    console.error('[yelp-login] password field still not found; leaving for manual entry');
    return;
  }
  console.error('[yelp-login] autofilling password');
  await passEl.focus();
  await sleep(200 + Math.random() * 300);
  await passEl.click({ clickCount: 3 });
  await sleep(300 + Math.random() * 300);
  await typeHumanLike(passEl, password);

  // Read back what actually landed in the field. If the length differs from
  // what we typed (Yelp's React onChange dedup, IME interference, or the
  // field being a duplicate "confirm password") we'd otherwise hand Yelp a
  // truncated password and the operator sees "wrong password".
  try {
    const actual = await page.$eval(SELECTORS.loginPassword, (el) => el.value || '');
    if (actual.length !== password.length) {
      console.error(`[yelp-login] WARNING password field readback mismatch: typed=${password.length} chars, field=${actual.length} chars`);
    } else {
      console.error(`[yelp-login] password readback OK (${actual.length} chars)`);
    }
  } catch (e) {
    console.error('[yelp-login] password readback failed: ' + (e?.message || e));
  }

  // Yelp validates the form async and only enables the submit button after
  // the password field has been blurred / debounced. Tab off the field and
  // wait for the button to become clickable.
  await sleep(400 + Math.random() * 400);
  await page.keyboard.press('Tab').catch(() => {});
  // NOTE: intentionally do NOT auto-click the final Continue button. Yelp's
  // DataDome layer flags programmatic submits even when the keystrokes look
  // human — leaving the actual click to the operator (who is watching the
  // embedded noVNC viewer) is the most reliable bypass. The credentials are
  // pre-filled; they just press Continue themselves.
  console.error('[yelp-login] form pre-filled; waiting for operator to press Continue in viewer');
}

async function modeCheck(args) {
  const { browser, proxyConfig } = await buildBrowser(args, true);
  try {
    const page = await browser.newPage();
    await setupPage(page, args, proxyConfig);
    await page.goto('https://biz.yelp.com/', { waitUntil: 'networkidle2', timeout: 60000 }).catch(() => {});
    await sleep(2000 + Math.random() * 2000);
    await maybeBypassDataDome(page, proxyConfig, args);
    if (isAuthedUrl(page.url())) return true;

    // Yelp sometimes redirects '/' to a regional landing page
    // (e.g. biz.yelp.com.br/landing/signup_fy21) for authenticated US
    // accounts when proxy geo guesses wrong. Force the canonical /home once
    // before declaring the session dead.
    console.error(`[yelp-login] check: '/' redirected to ${page.url()} - retrying via /home`);
    await page.goto('https://biz.yelp.com/home', { waitUntil: 'networkidle2', timeout: 60000 }).catch(() => {});
    await sleep(1500);
    await maybeBypassDataDome(page, proxyConfig, args);
    return isAuthedUrl(page.url());
  } finally {
    await browser.close().catch(() => {});
  }
}

async function modeLogin(args) {
  const { browser, proxyConfig } = await buildBrowser(args, false);
  return await new Promise((resolve) => {
    let settled = false;
    const finish = (val) => {
      if (settled) return;
      settled = true;
      resolve(val);
    };
    browser.on('disconnected', () => finish({ closed: true }));

    (async () => {
      try {
        // Chromium with a persistent userDataDir restores the previous
        // session's tabs on launch — operator was seeing 7+ stale tabs from
        // prior login attempts. Open a fresh blank tab and close every
        // restored page so they start clean every time.
        const restored = await browser.pages();
        const page = await browser.newPage();
        for (const p of restored) {
          try { await p.close({ runBeforeUnload: false }); } catch {}
        }
        await setupPage(page, args, proxyConfig);

        // Defensive: for the first 6 seconds after launch, slam shut any
        // OTHER tab Chromium async-restores from session files we missed.
        // The hard-reset-session-files step above handles 99% of cases but
        // some Chromium versions write extra files (CurrentSession.bak,
        // SessionLog_*, etc.) we don't enumerate; this is the safety net.
        const initialKill = (target) => {
          if (target.type() !== 'page') return;
          (async () => {
            try {
              const p = await target.page();
              if (!p || p === page) return;
              await p.close({ runBeforeUnload: false }).catch(() => {});
              console.error('[yelp-login] closed unexpected restored tab');
            } catch (_) {}
          })();
        };
        browser.on('targetcreated', initialKill);
        setTimeout(() => browser.off('targetcreated', initialKill), 6000);

        // Attach interaction logging to every NEW tab the operator (or Yelp)
        // opens — magic-link verification typically lands on a fresh tab.
        let tabCounter = 1;
        browser.on('targetcreated', async (target) => {
          if (target.type() !== 'page') return;
          try {
            const newPage = await target.page();
            if (!newPage) return;
            const label = `tab${tabCounter++}`;
            attachInteractionLogging(newPage, label);
            console.error(`[browser:${label}] opened url=${newPage.url()}`);
          } catch {}
        });

        await page.goto('https://biz.yelp.com/login', { waitUntil: 'networkidle2', timeout: 60000 }).catch(() => {});
        await sleep(2000 + Math.random() * 2000);
        await maybeBypassDataDome(page, proxyConfig, args);

        if (!isAuthedUrl(page.url())) {
          // If DataDome has already flagged this session (url carries
          // ?dd_referrer=, or Yelp is showing the generic "error processing
          // your request" copy) skip autofill entirely and let the operator
          // handle the page manually in the viewer.
          const url = page.url();
          const burned = url.includes('dd_referrer') || url.includes('datadome');
          if (burned) {
            console.error('[yelp-login] DataDome challenge detected on initial load; leaving form for manual entry');
          } else if (args.cookiesFile) {
            console.error('[yelp-login] cookies injected; skipping autofill');
          } else {
            try {
              await autofillLogin(page, args.email, args.password);
            } catch (e) {
              console.error('[yelp-login] autofill skipped: ' + (e?.message || e));
            }
          }
        }

        const startedAt = Date.now();
        let lastLoggedUrls = new Map(); // pageId -> url, to avoid spamming
        let magicLinkLogged = false;
        let pollCount = 0;
        while (Date.now() - startedAt < args.timeoutMs) {
          if (browser.connected === false) break;
          pollCount++;
          // Check ALL open tabs — the user may complete login on a tab other
          // than the original login page (e.g. email verification link opens
          // a new tab that lands on the biz dashboard).
          const allPages = await browser.pages().catch(() => [page]);

          // Log every URL transition across every tab so the PHP log
          // viewer can show exactly what the operator-driven browser is
          // doing (magic-link redirects, DataDome challenges, regional
          // landing traps, etc.).
          for (let i = 0; i < allPages.length; i++) {
            const p = allPages[i];
            let u = '';
            try { u = p.url(); } catch (_) { continue; }
            const key = `${i}`;
            if (lastLoggedUrls.get(key) !== u) {
              lastLoggedUrls.set(key, u);
              console.error(`[yelp-login] tab[${i}] url=${u}`);
              if (!magicLinkLogged && u.includes('/login/passwordless/')) {
                magicLinkLogged = true;
                console.error('[yelp-login] passwordless/magic-link redirect detected — waiting for biz dashboard');
              }
            }
          }

          // Periodic heartbeat every ~30s so the operator knows the loop
          // is alive and what state we are in.
          if (pollCount % 15 === 0) {
            const elapsed = Math.round((Date.now() - startedAt) / 1000);
            console.error(`[yelp-login] heartbeat: ${elapsed}s elapsed, ${allPages.length} tab(s), waiting for authenticated url`);
          }

          const authedPage = allPages.find(p => { try { return isAuthedUrl(p.url()); } catch { return false; } });
          if (authedPage) {
            let authedUrl = '';
            try { authedUrl = authedPage.url(); } catch (_) {}
            console.error(`[yelp-login] authenticated url detected: ${authedUrl}`);

            // CRITICAL: immediately silence every OTHER tab. The main tab
            // is typically still sitting on /login and Yelp's JS will
            // navigate it on a timer — that navigation triggers DataDome,
            // burns 2captcha credit, and (worst of all) flags the proxy
            // exit IP. Stop their loading and close them BEFORE the
            // cookie-flush sleep so they can't fire during the 3s window.
            for (const p of allPages) {
              if (p === authedPage) continue;
              try { await p.evaluate(() => { try { window.stop(); } catch (_) {} }); } catch (_) {}
              try { await p._client().send('Page.stopLoading'); } catch (_) {}
              try { await p.close({ runBeforeUnload: false }); } catch (_) {}
            }
            // Also stop any further navigation on the authed tab itself
            // (Yelp loves to bounce post-login users through tracker URLs
            // that can 403 → DataDome challenge).
            try { await authedPage.evaluate(() => { try { window.stop(); } catch (_) {} }); } catch (_) {}

            // Dump the cookies we'll be persisting BEFORE closing the browser.
            // This is critical for debugging "logged in" -> "session expired"
            // 8 minutes later: if `s` / `bse` / `_csrf` / `bsd` are absent
            // here, no amount of disk-flush will help — they were never set
            // in the first place.
            try {
              const cookies = await authedPage.cookies('https://biz.yelp.com', 'https://business.yelp.com', 'https://www.yelp.com');
              const summary = cookies.map(c => ({
                n: c.name, d: c.domain, p: c.path,
                sz: (c.value || '').length,
                exp: c.expires > 0 ? new Date(c.expires * 1000).toISOString() : 'session',
                http: !!c.httpOnly, sec: !!c.secure,
              }));
              const interesting = summary.filter(c => /^(s|bse|bsd|_csrf|yuv|hl|datadome|recentlocations|location)$/i.test(c.n));
              console.error(`[yelp-login] persisted cookies count=${summary.length} interesting=${JSON.stringify(interesting)}`);
            } catch (e) {
              console.error('[yelp-login] cookie dump failed: ' + (e?.message || e));
            }

            // Give Chromium time to flush its cookie SQLite DB to disk BEFORE
            // we tear it down. The earlier code SIGKILL'd after 7ms which
            // bypassed the cookie write -> upload script 8 min later sees a
            // profile with no session cookies. 3s is plenty for sqlite WAL.
            await sleep(3000);
            finish({ closed: false, authenticated: true });
            // Graceful close lets Chromium run its OnExit handlers
            // (cookie/preferences flush, session restore, etc.). Race
            // against 6s in case a persistent profile hangs on close.
            await Promise.race([
              browser.close().catch(() => {}),
              new Promise(r => setTimeout(r, 6000)),
            ]);
            // Only after the graceful close do we resort to killing leftover
            // chromium helper processes (zombie GPU/utility processes that
            // sometimes linger). By this point the parent has already
            // checkpointed its on-disk state.
            try {
              const proc = browser.process && browser.process();
              if (proc && proc.pid) {
                if (proc.exitCode === null) {
                  try { process.kill(-proc.pid, 'SIGTERM'); } catch (_) {}
                  await sleep(800);
                  try { process.kill(-proc.pid, 'SIGKILL'); } catch (_) {}
                  try { process.kill(proc.pid, 'SIGKILL'); } catch (_) {}
                }
              }
            } catch (_) {}
            emit({ ok: true, authenticated: true, closed: true });
            process.exit(0);
          }
          if (await detectDataDome(page)) {
            await maybeBypassDataDome(page, proxyConfig, args);
          }
          await sleep(2000);
        }
        finish({ closed: false, authenticated: false });
        await browser.close().catch(() => {});
      } catch (e) {
        console.error('[yelp-login] error: ' + (e?.stack || e?.message));
        finish({ closed: false, authenticated: false, error: e?.message });
      }
    })();
  });
}

async function main() {
  const args = parseArgs(process.argv);
  if (!args.userDataDir) {
    emit({ ok: false, error: 'missing --user-data-dir' });
    process.exit(2);
  }
  try {
    if (args.mode === 'check') {
      const authed = await modeCheck(args);
      emit({ ok: true, authenticated: authed });
      process.exit(0);
    }
    if (args.mode === 'login') {
      if ((!args.email || !args.password) && !args.cookiesFile) {
        emit({ ok: false, error: 'missing --email/--password (or --cookies-file)' });
        process.exit(2);
      }
      const result = await modeLogin(args);
      emit({ ok: true, authenticated: !!result.authenticated, closed: !!result.closed });
      process.exit(0);
    }
    emit({ ok: false, error: `unknown mode: ${args.mode}` });
    process.exit(2);
  } catch (e) {
    emit({ ok: false, error: e?.message || String(e) });
    process.exit(1);
  }
}

main();
