#!/usr/bin/env node
/**
 * Facebook interactive login.
 *
 * Opens a headed Chromium so a human can complete login (incl. 2FA / device
 * approval). Exits cleanly once a logged-in marker is found so the persistent
 * userDataDir flushes session cookies to disk. Mirrors instagram-login.mjs.
 *
 * Usage:
 *   node scripts/facebook-login.mjs \
 *       --user-data-dir=/var/data/facebook-puppeteer \
 *       [--timeout-ms=1200000]
 *
 * Output (single JSON line on stdout before exit):
 *   {"ok":true,"authenticated":true}
 *   {"ok":true,"authenticated":false,"reason":"timeout"}
 */
import puppeteer from 'puppeteer-extra';
import StealthPlugin from 'puppeteer-extra-plugin-stealth';
import fs from 'node:fs';

puppeteer.use(StealthPlugin());

function parseArgs(argv) {
  const args = { userDataDir: null, timeoutMs: 20 * 60 * 1000 };
  for (const a of argv.slice(2)) {
    if (a.startsWith('--user-data-dir=')) args.userDataDir = a.slice('--user-data-dir='.length);
    else if (a.startsWith('--timeout-ms=')) args.timeoutMs = parseInt(a.slice('--timeout-ms='.length), 10);
  }
  return args;
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));
const emit = (obj) => process.stdout.write(JSON.stringify(obj) + '\n');

async function detectLoggedIn(page) {
  try {
    return await page.evaluate(() => {
      const url = location.href;
      if (/\/login|\/checkpoint|\/recover/.test(url)) return null;
      // Logged-in signals on www.facebook.com: composer, profile shortcut,
      // or the left-rail home links.
      const composer = document.querySelector('[aria-label="Create a post"], [role="button"][aria-label^="What"]');
      const home = document.querySelector('a[aria-label="Home"]');
      const profile = document.querySelector('a[aria-label="Your profile"]');
      if (!composer && !home && !profile) return null;
      return { ok: true };
    });
  } catch {
    return null;
  }
}

(async () => {
  const args = parseArgs(process.argv);
  if (!args.userDataDir) {
    console.error('--user-data-dir is required');
    process.exit(2);
  }
  fs.mkdirSync(args.userDataDir, { recursive: true });

  const browser = await puppeteer.launch({
    headless: false,
    userDataDir: args.userDataDir,
    defaultViewport: { width: 1366, height: 900 },
    args: [
      '--no-sandbox',
      '--disable-setuid-sandbox',
      '--disable-blink-features=AutomationControlled',
    ],
  });

  let authenticated = false;
  try {
    const page = (await browser.pages())[0] || (await browser.newPage());
    await page.setUserAgent('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');
    await page.goto('https://www.facebook.com/', { waitUntil: 'domcontentloaded', timeout: 60000 }).catch(() => {});

    const deadline = Date.now() + args.timeoutMs;
    while (Date.now() < deadline) {
      const state = await detectLoggedIn(page);
      if (state) { authenticated = true; break; }
      await sleep(2000);
    }

    // Grace window to let "Save device"/notification prompts complete.
    if (authenticated) await sleep(4000);
  } finally {
    await browser.close().catch(() => {});
  }

  emit({ ok: true, authenticated, reason: authenticated ? null : 'timeout' });
  process.exit(authenticated ? 0 : 4);
})().catch((e) => {
  emit({ ok: false, authenticated: false, error: e.message || String(e) });
  process.exit(1);
});
