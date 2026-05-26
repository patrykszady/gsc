/**
 * Shared DataDome detection + bypass helpers for Yelp Puppeteer scripts.
 *
 * Supports two captcha providers with automatic fallback:
 *   1) 2captcha       (--twocaptcha-key=...)   primary
 *   2) anti-captcha   (--anticaptcha-key=...)  fallback
 *
 * Both providers expose a "datadome" task type. We try the primary first;
 * on submit error / poll error / timeout we transparently retry on the
 * fallback so a single transient outage on one provider can't break the
 * whole pipeline.
 */

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

export async function detectDataDome(page) {
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

export function parseDataDomeCookie(cookieStr) {
  const parts = (cookieStr || '').split(';').map((p) => p.trim());
  const kv = parts.find((p) => p.startsWith('datadome='));
  return kv ? kv.slice('datadome='.length) : null;
}

/**
 * Wipe any persisted "datadome" cookies from the browser cookie jar before
 * navigating to Yelp. A stale (banned) datadome value carried over from a
 * previous run causes DataDome to immediately hard-block the session, so we
 * always want to start clean and let DataDome issue a fresh challenge/cookie
 * based on the current fingerprint + IP.
 *
 * Other Yelp session cookies (yelp_session, etc.) are preserved.
 */
export async function clearStaleDataDomeCookies(page) {
  try {
    const cdp = await page.createCDPSession();
    for (const domain of ['.yelp.com', '.biz.yelp.com', 'biz.yelp.com', 'yelp.com', 'www.yelp.com', 'www.biz.yelp.com']) {
      await cdp.send('Network.deleteCookies', { name: 'datadome', domain }).catch(() => {});
      await cdp.send('Network.deleteCookies', { name: 'datadome', domain, path: '/' }).catch(() => {});
    }
    await cdp.detach().catch(() => {});
  } catch (e) {
    console.error(`[datadome] clearStaleDataDomeCookies failed: ${e?.message || e}`);
  }
}

// ---- 2captcha (modern createTask API) ----
// Migrated from legacy in.php (which expects form-encoded body and was
// rejecting our JSON payload with ERROR_BAD_PARAMETERS) to the modern
// api.2captcha.com/createTask endpoint. Same JSON task shape as
// anti-captcha. Handles both t=fe (slider) and t=bv (interstitial)
// DataDome challenge variants.
async function solveWith2Captcha({ captchaUrl, pageUrl, proxyStr, userAgent, apiKey }) {
  let proxyParts = null;
  if (proxyStr) {
    // proxyStr format: "user:pass@host:port" - password may contain ':' so
    // use a non-greedy match on user and a greedy match on password.
    const m = proxyStr.match(/^([^:]+):(.+)@([^:]+):(\d+)$/);
    if (m) proxyParts = { login: m[1], password: m[2], address: m[3], port: Number(m[4]) };
  }
  if (!proxyParts) {
    console.error('[datadome:2captcha] missing/invalid proxy - DataDome requires solving through the same egress IP');
    return null;
  }

  let t = null;
  try { t = new URL(captchaUrl).searchParams.get('t'); } catch {}
  console.error(`[datadome:2captcha] submitting challenge (t=${t || 'unknown'})`);

  const task = {
    type: 'DataDomeSliderTask',
    websiteURL: pageUrl,
    captchaUrl,
    userAgent,
    proxyType: 'http',
    proxyAddress: proxyParts.address,
    proxyPort: proxyParts.port,
    proxyLogin: proxyParts.login,
    proxyPassword: proxyParts.password,
  };

  const createRes = await fetch('https://api.2captcha.com/createTask', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ clientKey: apiKey, task }),
  }).catch((e) => ({ ok: false, _err: e?.message }));
  const createData = await createRes.json?.().catch(() => null);
  if (!createData || createData.errorId !== 0) {
    const desc = createData?.errorDescription || createData?.errorCode || createRes._err || 'unknown';
    console.error(`[datadome:2captcha] createTask error: ${desc}`);
    return null;
  }
  const taskId = createData.taskId;
  console.error(`[datadome:2captcha] task ${taskId} submitted, polling...`);

  for (let i = 0; i < 36; i++) {
    await sleep(5000);
    const r = await fetch('https://api.2captcha.com/getTaskResult', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ clientKey: apiKey, taskId }),
    }).catch(() => null);
    const d = await r?.json?.().catch(() => null);
    if (!d) continue;
    if (d.errorId !== 0) {
      console.error(`[datadome:2captcha] error: ${d.errorDescription || d.errorCode}`);
      return null;
    }
    if (d.status === 'ready') {
      console.error('[datadome:2captcha] solved');
      return d.solution?.cookie || d.solution?.token || null;
    }
  }
  console.error('[datadome:2captcha] timed out');
  return null;
}

// ---- Anti-captcha ----
async function solveWithAntiCaptcha({ captchaUrl, pageUrl, proxyStr, userAgent, apiKey }) {
  console.error('[datadome:anticaptcha] submitting challenge');
  let proxyParts = null;
  if (proxyStr) {
    // proxyStr format: "user:pass@host:port"
    const m = proxyStr.match(/^([^:]+):([^@]+)@([^:]+):(\d+)$/);
    if (m) proxyParts = { login: m[1], password: m[2], address: m[3], port: Number(m[4]) };
  }

  const task = {
    type: proxyParts ? 'DataDomeSliderTask' : 'DataDomeSliderTaskProxyless',
    websiteURL: pageUrl,
    captchaUrl,
    userAgent,
  };
  if (proxyParts) {
    Object.assign(task, {
      proxyType: 'http',
      proxyAddress: proxyParts.address,
      proxyPort: proxyParts.port,
      proxyLogin: proxyParts.login,
      proxyPassword: proxyParts.password,
    });
  }

  const createRes = await fetch('https://api.anti-captcha.com/createTask', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ clientKey: apiKey, task }),
  }).catch((e) => ({ ok: false, _err: e?.message }));
  const createData = await createRes.json?.().catch(() => null);
  if (!createData || createData.errorId !== 0) {
    console.error(`[datadome:anticaptcha] createTask error: ${createData?.errorDescription || createRes._err || 'unknown'}`);
    return null;
  }
  const taskId = createData.taskId;
  console.error(`[datadome:anticaptcha] task ${taskId} submitted, polling...`);

  for (let i = 0; i < 36; i++) {
    await sleep(5000);
    const r = await fetch('https://api.anti-captcha.com/getTaskResult', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify({ clientKey: apiKey, taskId }),
    }).catch(() => null);
    const d = await r?.json?.().catch(() => null);
    if (!d) continue;
    if (d.errorId !== 0) {
      console.error(`[datadome:anticaptcha] error: ${d.errorDescription}`);
      return null;
    }
    if (d.status === 'ready') {
      console.error('[datadome:anticaptcha] solved');
      // anti-captcha returns { cookie: "datadome=..." } or { token: "..." }
      return d.solution?.cookie || d.solution?.token || null;
    }
  }
  console.error('[datadome:anticaptcha] timed out');
  return null;
}

/**
 * Try 2captcha first, fall back to anticaptcha if available.
 * Returns the raw cookie string ("datadome=xxx") or just the value
 * (we extract via parseDataDomeCookie afterwards).
 */
export async function solveDataDome({ captchaUrl, pageUrl, proxyStr, userAgent, twoCaptchaKey, antiCaptchaKey }) {
  if (twoCaptchaKey) {
    const v = await solveWith2Captcha({ captchaUrl, pageUrl, proxyStr, userAgent, apiKey: twoCaptchaKey });
    if (v) return v;
    console.error('[datadome] 2captcha failed, attempting anticaptcha fallback');
  }
  if (antiCaptchaKey) {
    const v = await solveWithAntiCaptcha({ captchaUrl, pageUrl, proxyStr, userAgent, apiKey: antiCaptchaKey });
    if (v) return v;
  }
  return null;
}

/**
 * Detect DataDome on the current page; if present, solve via captcha
 * provider, inject the cookie, block re-fingerprinting scripts, and
 * reload. Returns true if the page is unblocked, false otherwise.
 */
export async function maybeBypassDataDome(page, proxyConfig, args) {
  // Give the DataDome JS challenge a chance to self-resolve via stealth.
  // A real Chromium on a clean residential IP often passes within 20-40s
  // once the embedded JS runs and posts the challenge solution. Calling
  // 2captcha is expensive and the returned cookie is fragile (IP-bound),
  // so we wait substantially longer than before (was 12s, now up to 60s)
  // before escalating.
  for (let i = 0; i < 20; i++) {
    if (!(await detectDataDome(page))) {
      if (i > 0) console.error(`[datadome] self-resolved after ${i * 3}s`);
      return true;
    }
    await sleep(3000);
  }

  const captchaUrl = await detectDataDome(page);
  if (!captchaUrl) return true;

  // Soft block: page DOM has real content underneath the overlay.
  const bodyLen = await page.evaluate(() => document.documentElement.innerHTML.length).catch(() => 0);
  if (bodyLen > 50000) {
    console.error(`[datadome] soft block (${bodyLen} bytes), proceeding`);
    return true;
  }

  console.error('[datadome] hard block on ' + page.url());

  // One-shot conditional cookie wipe: if we're hard-blocked AND we still
  // have a (possibly stale/banned) datadome cookie sitting in the jar,
  // try wiping it once and reloading. A stale banned cookie can keep the
  // session hard-blocked even on a clean IP; conversely a valid cookie
  // gets us past without any solver call. Only fires once per page.
  if (!page._ddCookieWipeAttempted) {
    page._ddCookieWipeAttempted = true;
    let hadCookie = false;
    try {
      const cookies = await page.cookies('https://biz.yelp.com', 'https://www.yelp.com', 'https://yelp.com');
      hadCookie = cookies.some((c) => c.name === 'datadome');
    } catch {}
    if (hadCookie) {
      console.error('[datadome] hard block with existing cookie - wiping and retrying once');
      await clearStaleDataDomeCookies(page);
      const curUrl = page.url();
      await page.goto(curUrl, { waitUntil: 'networkidle2', timeout: 60000 }).catch(() => {});
      await sleep(2000);
      // Re-enter bypass logic from the top so the self-resolve window
      // applies to the freshly issued challenge.
      return maybeBypassDataDome(page, proxyConfig, args);
    }
  }

  // t=fe (fingerprint) challenges are NOT solvable by 2captcha/anticaptcha:
  // there is no human action and no slider, the challenge is a pure
  // client-side fingerprint score. Calling a third-party solver just wastes
  // ~30s and credits, then returns a short bogus "cookie" that DataDome
  // immediately rejects. The only real fixes for t=fe are (a) a better
  // browser fingerprint (run headed under Xvfb, real-browser puppeteer)
  // and (b) a higher-trust IP. Fail fast so the caller can surface the
  // right action instead of looping on a non-bypassable challenge.
  let challengeType = null;
  try { challengeType = new URL(captchaUrl).searchParams.get('t'); } catch {}
  if (challengeType === 'fe') {
    console.error('[datadome] t=fe (fingerprint) challenge: solver providers cannot bypass this; needs headed/stealth browser or higher-trust IP. Aborting.');
    return false;
  }

  if (!args.twoCaptchaKey && !args.antiCaptchaKey) {
    console.error('[datadome] cannot solve: no captcha provider keys supplied');
    return false;
  }
  if (!proxyConfig) {
    console.error('[datadome] cannot solve: --proxy required (captcha must be solved through the same egress IP)');
    return false;
  }

  let actualPageUrl = page.url();
  try {
    const ref = new URL(captchaUrl).searchParams.get('referer');
    if (ref) actualPageUrl = ref;
  } catch {}

  const proxyStr = `${proxyConfig._sessionUsername || proxyConfig.username}:${proxyConfig.password}@${proxyConfig.hostname}:${proxyConfig.port}`;
  const raw = await solveDataDome({
    captchaUrl,
    pageUrl: actualPageUrl,
    proxyStr,
    userAgent: args.userAgent,
    twoCaptchaKey: args.twoCaptchaKey,
    antiCaptchaKey: args.antiCaptchaKey,
  });
  if (!raw) return false;
  const value = parseDataDomeCookie(raw) || raw;
  if (!value) return false;
  console.error(`[datadome] injecting cookie (len=${value.length}, head=${value.slice(0, 20)}...)`);

  // Clear any existing datadome cookies across all yelp domains.
  const cdp = await page.createCDPSession();
  for (const domain of ['.yelp.com', '.biz.yelp.com', 'biz.yelp.com', 'yelp.com', 'www.yelp.com']) {
    await cdp.send('Network.deleteCookies', { name: 'datadome', domain }).catch(() => {});
  }
  // Inject via CDP Network.setCookie too (matches what a real Set-Cookie
  // response header would do, including httpOnly which document.cookie
  // can never set). DataDome typically sets its cookie httpOnly so the
  // server-side check passes only when the cookie is present in the
  // request header AND was originally set with httpOnly semantics.
  for (const domain of ['.yelp.com', '.biz.yelp.com']) {
    await cdp.send('Network.setCookie', {
      name: 'datadome',
      value,
      domain,
      path: '/',
      secure: true,
      httpOnly: true,
      sameSite: 'Lax',
      expires: Math.floor(Date.now() / 1000) + 31536000,
    }).catch((e) => console.error(`[datadome] CDP setCookie ${domain} failed: ${e?.message}`));
  }
  await cdp.detach();
  // Also call Puppeteer setCookie as a belt-and-braces fallback.
  for (const domain of ['.yelp.com', '.biz.yelp.com']) {
    await page.setCookie({ name: 'datadome', value, domain, path: '/', secure: true, httpOnly: true, sameSite: 'Lax' }).catch(() => {});
  }

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
