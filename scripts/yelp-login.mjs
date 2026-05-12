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

puppeteer.use(StealthPlugin());

const SELECTORS = {
  loginEmail: 'input[name="email"], input#email',
  loginPassword: 'input[name="password"], input#password',
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
  return {
    browser: await puppeteer.launch({
      headless: headless ? 'new' : false,
      userDataDir: args.userDataDir || undefined,
      defaultViewport: { width: 1366, height: 900 },
      args: launchArgs,
    }),
    proxyConfig,
  };
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
async function modeCheck(args) {
  const { browser, proxyConfig } = await buildBrowser(args, true);
  try {
    const page = await browser.newPage();
    await setupPage(page, args, proxyConfig);
    await page.goto('https://biz.yelp.com/', { waitUntil: 'networkidle2', timeout: 60000 }).catch(() => {});
    await sleep(2000 + Math.random() * 2000);
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
          // Intentionally do NOT pre-fill the form. Yelp's bot detection
          // scores the keystroke timing of the email/password fields, so
          // synthetic typing tanks the trust score. Let the human type.
          try {
            await page.waitForSelector(SELECTORS.loginEmail, { timeout: 15000 });
          } catch {}
        }

        const startedAt = Date.now();
        while (Date.now() - startedAt < args.timeoutMs) {
          if (browser.connected === false) break;
          if (isAuthedUrl(page.url())) {
            await sleep(1500);
            finish({ closed: false, authenticated: true });
            await browser.close().catch(() => {});
            return;
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
