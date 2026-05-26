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
import { purgeStaleChromiumLocks, installShutdownHandlers } from './lib/yelp-userdata-lock.mjs';

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
    else if (a.startsWith('--user-agent=')) args.userAgent = a.slice('--user-agent='.length);
  }
  return args;
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const emit = (p) => process.stdout.write(JSON.stringify(p) + '\n');

function isAuthedUrl(url) {
  try {
    const u = new URL(url);
    return u.hostname.endsWith('biz.yelp.com') && !u.pathname.startsWith('/login') && !u.pathname.startsWith('/signup');
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
  const launchArgs = ['--no-sandbox', '--disable-setuid-sandbox', '--disable-blink-features=AutomationControlled'];
  const proxyConfig = parseProxyUrl(args.proxy);
  if (proxyConfig) {
    launchArgs.push(`--proxy-server=${proxyConfig.host}`);
  }
  // Reap any leftover Chromium / SingletonLock from a prior killed run.
  purgeStaleChromiumLocks(args.userDataDir);
  const browser = await puppeteer.launch({
    headless: headless ? 'new' : false,
    userDataDir: args.userDataDir || undefined,
    defaultViewport: { width: 1366, height: 900 },
    args: launchArgs,
  });
  installShutdownHandlers(browser);
  return { browser, proxyConfig };
}

async function setupPage(page, args, proxyConfig) {
  await page.setUserAgent(args.userAgent);
  await page.setExtraHTTPHeaders({ 'Accept-Language': 'en-US,en;q=0.9' });
  if (proxyConfig && (proxyConfig.username || proxyConfig.password)) {
    // Authenticate with the exact credentials from the proxy URL. Do NOT
    // mutate the username with session suffixes — most providers (2captcha,
    // BrightData gateway, etc.) reject anything other than the literal
    // credentials and the connection silently resets.
    await page.authenticate({ username: proxyConfig.username, password: proxyConfig.password });
    proxyConfig._sessionUsername = proxyConfig.username;
  }
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
        const pages = await browser.pages();
        const page = pages[0] || (await browser.newPage());
        await setupPage(page, args, proxyConfig);

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
          } else {
            try {
              await autofillLogin(page, args.email, args.password);
            } catch (e) {
              console.error('[yelp-login] autofill skipped: ' + (e?.message || e));
            }
          }
        }

        const startedAt = Date.now();
        while (Date.now() - startedAt < args.timeoutMs) {
          if (browser.connected === false) break;
          // Check ALL open tabs — the user may complete login on a tab other
          // than the original login page (e.g. email verification link opens
          // a new tab that lands on the biz dashboard).
          const allPages = await browser.pages().catch(() => [page]);
          const authenticated = allPages.some(p => isAuthedUrl(p.url()));
          if (authenticated) {
            await sleep(1500);
            finish({ closed: false, authenticated: true });
            // Race browser.close() against a 3s timeout — Chromium with a
            // persistent profile sometimes hangs on close, which would leave
            // the noVNC viewer showing a stale desktop until manual cleanup.
            await Promise.race([
              browser.close().catch(() => {}),
              new Promise(r => setTimeout(r, 3000)),
            ]);
            // Belt-and-suspenders: kill the spawned chromium process tree
            // directly, then force-exit node so the PHP poll sees the death.
            try {
              const proc = browser.process && browser.process();
              if (proc && proc.pid) {
                try { process.kill(-proc.pid, 'SIGKILL'); } catch (_) {}
                try { process.kill(proc.pid, 'SIGKILL'); } catch (_) {}
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
      if (!args.email || !args.password) {
        emit({ ok: false, error: 'missing --email/--password' });
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
