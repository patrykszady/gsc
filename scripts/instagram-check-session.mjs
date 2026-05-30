#!/usr/bin/env node
/**
 * Quick headless check: is the persisted Instagram puppeteer profile still
 * logged in? Visits instagram.com and looks for canonical logged-in markers
 * (inbox link, "New post" button, profile picture in nav).
 *
 * Usage:
 *   node scripts/instagram-check-session.mjs --user-data-dir=/var/data/instagram-puppeteer
 *
 * Output (single JSON line on stdout):
 *   {"ok":true,"authenticated":true,"username":"gs.construction.co"}
 *   {"ok":true,"authenticated":false,"reason":"redirected_to_login"}
 *   {"ok":false,"error":"..."}
 */
import puppeteer from 'puppeteer-extra';
import StealthPlugin from 'puppeteer-extra-plugin-stealth';

puppeteer.use(StealthPlugin());

function parseArgs(argv) {
  const args = { userDataDir: null, timeoutMs: 30000 };
  for (const a of argv.slice(2)) {
    if (a.startsWith('--user-data-dir=')) args.userDataDir = a.slice('--user-data-dir='.length);
    else if (a.startsWith('--timeout-ms=')) args.timeoutMs = parseInt(a.slice('--timeout-ms='.length), 10);
  }
  return args;
}

const emit = (obj) => { process.stdout.write(JSON.stringify(obj) + '\n'); };

(async () => {
  const args = parseArgs(process.argv);
  if (!args.userDataDir) {
    emit({ ok: false, error: '--user-data-dir is required' });
    process.exit(2);
  }

  let browser;
  try {
    browser = await puppeteer.launch({
      headless: 'new',
      userDataDir: args.userDataDir,
      defaultViewport: { width: 1280, height: 800 },
      args: [
        '--no-sandbox',
        '--disable-setuid-sandbox',
        '--disable-blink-features=AutomationControlled',
      ],
    });
    const page = await browser.newPage();
    await page.setUserAgent('Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36');
    await page.goto('https://www.instagram.com/', { waitUntil: 'networkidle2', timeout: args.timeoutMs });

    const finalUrl = page.url();
    if (/\/accounts\/(login|emailsignup)/.test(finalUrl)) {
      emit({ ok: true, authenticated: false, reason: 'redirected_to_login', url: finalUrl });
      await browser.close();
      process.exit(0);
    }

    const state = await page.evaluate(() => {
      const inbox = document.querySelector('a[href="/direct/inbox/"], a[href^="/direct/"]');
      const newPost = document.querySelector('svg[aria-label="New post"], svg[aria-label="Create"]');
      const profilePic = document.querySelector('img[alt$="profile picture"]');
      if (!inbox && !newPost && !profilePic) return { authed: false };
      let username = null;
      const profileLink = document.querySelector('a[href^="/"][role="link"][tabindex="0"] img[alt$="profile picture"]');
      if (profileLink) {
        const alt = profileLink.getAttribute('alt') || '';
        const m = alt.match(/^(.+?)'s profile picture/);
        if (m) username = m[1];
      }
      return { authed: true, username };
    });

    if (!state.authed) {
      emit({ ok: true, authenticated: false, reason: 'no_logged_in_markers', url: finalUrl });
    } else {
      emit({ ok: true, authenticated: true, username: state.username || null, url: finalUrl });
    }
    await browser.close();
    process.exit(0);
  } catch (e) {
    try { if (browser) await browser.close(); } catch {}
    emit({ ok: false, error: String(e?.message || e) });
    process.exit(1);
  }
})();
