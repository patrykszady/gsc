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
      // accepts "None"/"Lax"/"Strict". "unspecified" has no CDP equivalent —
      // omit it rather than coercing to Lax, which would change cross-site
      // behaviour for cookies the browser deliberately left unset.
      if (ss.startsWith('no')) cookie.sameSite = 'None';
      else if (ss.startsWith('lax')) cookie.sameSite = 'Lax';
      else if (ss.startsWith('strict')) cookie.sameSite = 'Strict';
    }
    // Chrome rejects SameSite=None without Secure, and one rejected cookie
    // fails the whole batch below.
    if (cookie.sameSite === 'None' && !cookie.secure) cookie.sameSite = 'Lax';
    // CDP needs at least one of domain/url to know where the cookie belongs;
    // an entry with neither fails the entire Storage.setCookies call.
    if (!cookie.domain && !cookie.url) continue;
    if (c.partitionKey && typeof c.partitionKey === 'object') {
      cookie.partitionKey = c.partitionKey;
      cookie.secure = true;
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
//
// `ctx.setCookie(...cookies)` is a SINGLE Storage.setCookies command: if any
// one cookie is malformed, CDP rejects the whole call and ZERO cookies are
// set. Previously that error was caught by the callers and logged to stderr,
// so the run continued unauthenticated and surfaced 60+ seconds later as a
// misleading `session_expired` — the actual cause (one bad cookie) never
// appeared in the emitted JSON. We now fall back to setting them one at a
// time so a single bad entry costs one cookie instead of the session.
//
// Returns the number of cookies Chrome ACCEPTED, not the number sent. The old
// return of `cookies.length` meant "injected 53 cookies" was printed even when
// Chrome had taken none of them.
export async function applyCookies(browser, cookies) {
  if (!cookies || cookies.length === 0) return 0;
  const ctx = browser.defaultBrowserContext ? browser.defaultBrowserContext() : null;

  const setAll = async (list) => {
    if (ctx && typeof ctx.setCookie === 'function') {
      await ctx.setCookie(...list);
      return;
    }
    const page = (await browser.pages())[0] || (await browser.newPage());
    await page.setCookie(...list);
  };

  try {
    await setAll(cookies);
    return cookies.length;
  } catch (e) {
    console.error(`[yelp-cookies] batch injection rejected (${e?.message || e}); retrying one at a time`);
  }

  let accepted = 0;
  for (const c of cookies) {
    try {
      await setAll([c]);
      accepted++;
    } catch (e) {
      console.error(`[yelp-cookies] rejected cookie ${c.name} for ${c.domain || c.url}: ${e?.message || e}`);
    }
  }
  return accepted;
}

/**
 * Count how many of `cookies` the browser can actually still see.
 *
 * Injection succeeding is not the same as the jar being usable — an expired
 * or wrongly-scoped cookie is accepted and then ignored. Callers use this to
 * report a truthful number.
 */
export async function verifyCookies(browser, names) {
  try {
    const ctx = browser.defaultBrowserContext ? browser.defaultBrowserContext() : null;
    const live = ctx && typeof ctx.cookies === 'function' ? await ctx.cookies() : [];
    const have = new Set(live.map((c) => c.name));
    return names.filter((n) => have.has(n)).length;
  } catch {
    return -1; // unknown, don't pretend
  }
}
