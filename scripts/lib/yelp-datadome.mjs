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

// ---- 2captcha ----
async function solveWith2Captcha({ captchaUrl, pageUrl, proxyStr, userAgent, apiKey }) {
  console.error('[datadome:2captcha] submitting challenge');
  const t = new URL(captchaUrl).searchParams.get('t');
  if (t === 'bv') {
    console.error('[datadome:2captcha] t=bv - IP banned, rotate proxy');
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
  }).catch((e) => ({ ok: false, _err: e?.message }));
  const submitData = await submitRes.json?.().catch(() => null);
  if (!submitData || submitData.status !== 1) {
    console.error(`[datadome:2captcha] submit error: ${submitData?.request || submitRes._err || 'unknown'}`);
    return null;
  }
  const taskId = submitData.request;
  console.error(`[datadome:2captcha] task ${taskId} submitted, polling...`);
  for (let i = 0; i < 36; i++) {
    await sleep(5000);
    const r = await fetch(`https://2captcha.com/res.php?key=${apiKey}&action=get&id=${taskId}&json=1`).catch(() => null);
    const d = await r?.json?.().catch(() => null);
    if (!d) continue;
    if (d.status === 1) {
      console.error('[datadome:2captcha] solved');
      return d.request;
    }
    if (d.request !== 'CAPCHA_NOT_READY') {
      console.error(`[datadome:2captcha] error: ${d.request}`);
      return null;
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
  for (let i = 0; i < 6; i++) {
    if (!(await detectDataDome(page))) return true;
    await sleep(2000);
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

  const cdp = await page.createCDPSession();
  await cdp.send('Network.deleteCookies', { name: 'datadome', domain: '.yelp.com' }).catch(() => {});
  await cdp.detach();
  for (const domain of ['.yelp.com', '.biz.yelp.com']) {
    await page.setCookie({ name: 'datadome', value, domain, path: '/', secure: true, sameSite: 'Lax' });
  }
  await page.evaluate((v) => {
    document.cookie = `datadome=${v}; domain=.yelp.com; path=/; secure; SameSite=Lax`;
  }, value).catch(() => {});

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
