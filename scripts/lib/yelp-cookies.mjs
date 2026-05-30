// Load Yelp session cookies from a JSON file (Cookie-Editor / EditThisCookie
// extension export format) and apply them via Chrome DevTools Protocol so
// they take effect for ALL hosts, BEFORE any navigation. This bypasses the
// puppeteer login flow entirely — DataDome only challenges unauthenticated
// sessions; with valid cookies it shrugs and lets us through.
import fs from 'node:fs';

function normalize(raw) {
  if (!Array.isArray(raw)) {
    if (raw && Array.isArray(raw.cookies)) raw = raw.cookies;
    else throw new Error('cookies file must be a JSON array');
  }
  const out = [];
  for (const c of raw) {
    if (!c || !c.name || c.value === undefined || c.value === null) continue;
    const cookie = {
      name: String(c.name),
      value: String(c.value),
      path: c.path || '/',
      httpOnly: !!c.httpOnly,
      secure: !!c.secure,
    };
    if (c.domain) cookie.domain = String(c.domain);
    if (c.url) cookie.url = String(c.url);
    // Cookie-Editor uses `expirationDate` (seconds, float). Puppeteer wants
    // `expires` (seconds). Skip session cookies (no expiry).
    if (typeof c.expirationDate === 'number') cookie.expires = Math.floor(c.expirationDate);
    else if (typeof c.expires === 'number' && c.expires > 0) cookie.expires = Math.floor(c.expires);
    if (c.sameSite) {
      const ss = String(c.sameSite).toLowerCase();
      // Cookie-Editor exports "no_restriction" / "lax" / "strict"; puppeteer
      // accepts "None"/"Lax"/"Strict".
      if (ss.startsWith('no')) cookie.sameSite = 'None';
      else if (ss.startsWith('lax') || ss === 'unspecified') cookie.sameSite = 'Lax';
      else if (ss.startsWith('strict')) cookie.sameSite = 'Strict';
    }
    out.push(cookie);
  }
  return out;
}

export function loadCookiesFromFile(file) {
  const raw = JSON.parse(fs.readFileSync(file, 'utf8'));
  return normalize(raw);
}

// Apply cookies to the browser via CDP so they're set across the profile,
// not just one page. Must be called BEFORE the first navigation.
export async function applyCookies(browser, cookies) {
  if (!cookies || cookies.length === 0) return 0;
  const ctx = browser.defaultBrowserContext
    ? browser.defaultBrowserContext()
    : null;
  if (ctx && typeof ctx.setCookie === 'function') {
    await ctx.setCookie(...cookies);
    return cookies.length;
  }
  // Fallback: open a blank page and set per-page (still uses CDP).
  const page = (await browser.pages())[0] || (await browser.newPage());
  await page.setCookie(...cookies);
  return cookies.length;
}
