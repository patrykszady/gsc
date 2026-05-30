#!/usr/bin/env node
/**
 * Instagram interactive login.
 *
 * Opens a real (headed) Chromium so a human can complete the login,
 * including any 2FA / "save your login" prompts. Once the session reaches
 * a logged-in URL (anything other than /accounts/login/ or /accounts/onetap/
 * with a profile/inbox marker present), the script waits a short grace
 * window for "Save info"/"Turn on notifications" prompts then exits
 * cleanly so the persistent userDataDir cookies are flushed to disk.
 *
 * Usage:
 *   node scripts/instagram-login.mjs \
 *       --user-data-dir=/var/data/instagram-puppeteer \
 *       [--timeout-ms=1200000]
 *
 * Output (single JSON line on stdout right before exit):
 *   {"ok":true,"authenticated":true,"username":"gs.construction.co"}
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
      // Login pages explicitly mean NOT logged in.
      if (/\/accounts\/(login|onetap|emailsignup)/.test(url)) return null;
      // Look for canonical logged-in signals.
      const inbox = document.querySelector('a[href="/direct/inbox/"], a[href^="/direct/"]');
      const newPost = document.querySelector('svg[aria-label="New post"], svg[aria-label="Create"]');
      const profilePic = document.querySelector('img[alt$="profile picture"]');
      if (!inbox && !newPost && !profilePic) return null;
      // Try to read username from the profile-link in the navbar.
      let username = null;
      const profileLink = document.querySelector('a[href^="/"][role="link"][tabindex="0"] img[alt$="profile picture"]');
      if (profileLink) {
        const alt = profileLink.getAttribute('alt') || '';
        const m = alt.match(/^(.+?)'s profile picture/);
        if (m) username = m[1];
      }
      return { username };
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
      '--start-maximized',
    ],
  });

  const page = (await browser.pages())[0] || (await browser.newPage());
  // Visit instagram.com first — if cookies are still good, IG redirects
  // away from the login page on its own, and we exit immediately without
  // forcing the operator through a re-login they don't need.
  await page.goto('https://www.instagram.com/', { waitUntil: 'networkidle2', timeout: 60000 });

  console.error('[ig-login] Chromium open. Complete login in the visible window. The script will detect a successful login automatically.');

  const startedAt = Date.now();
  let detected = null;
  let stableHits = 0;

  while (Date.now() - startedAt < args.timeoutMs) {
    await sleep(3000);
    const state = await detectLoggedIn(page);
    if (state) {
      stableHits += 1;
      // Require two consecutive hits ~3s apart so we don't trip on a brief
      // home-page splash before IG bounces to the onetap/save-info screen.
      if (stableHits >= 2) {
        detected = state;
        break;
      }
    } else {
      stableHits = 0;
    }
  }

  if (!detected) {
    emit({ ok: true, authenticated: false, reason: 'timeout' });
    try { await browser.close(); } catch {}
    process.exit(0);
  }

  console.error('[ig-login] login detected, dismissing post-login prompts and persisting cookies...');
  // Auto-click any "Save info" / "Turn on Notifications" prompts so the
  // session is fully usable and the cookies are flushed.
  for (let i = 0; i < 4; i++) {
    await sleep(1500);
    try {
      await page.evaluate(() => {
        const labels = ['Not Now', 'Not now', 'Save info', 'Decline optional cookies'];
        const btns = Array.from(document.querySelectorAll('button, div[role="button"]'));
        for (const label of labels) {
          const b = btns.find((x) => (x.textContent || '').trim() === label);
          if (b) { b.click(); return label; }
        }
        return null;
      });
    } catch {}
  }
  // Settle then close — closing flushes Chromium's cookies to disk.
  await sleep(2000);
  emit({ ok: true, authenticated: true, username: detected.username || null });
  try { await browser.close(); } catch {}
  process.exit(0);
})().catch((e) => {
  console.error('[ig-login] fatal:', e);
  emit({ ok: false, authenticated: false, error: String(e?.message || e) });
  process.exit(1);
});
