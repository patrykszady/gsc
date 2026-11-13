#!/usr/bin/env node
/**
 * Quick headless check: is the persisted Instagram puppeteer profile still
 * logged in? Visits instagram.com and looks for canonical logged-in markers
 * (inbox link, "New post" button, profile picture in nav).
 *
 * Usage:
 *   node scripts/instagram-check-session.mjs --user-data-dir=/var/data/instagram-puppeteer
 *
 * Output (single JSON line on stdout — always, unless the process is SIGKILLed):
 *   {"ok":true,"authenticated":true,"username":"gs.construction.co"}
 *   {"ok":true,"authenticated":false,"reason":"redirected_to_login"}
 *   {"ok":false,"reason":"saved_session_not_cleared","error":"..."}
 *   {"ok":false,"reason":"session_restored","tabs":104,"error":"..."}
 *   {"ok":false,"reason":"deadline","error":"..."}
 *   {"ok":false,"error":"..."}
 *
 * One tab, every run. Chrome reopens the profile's previous tabs at launch
 * and each run used to leave two more behind (lib/chrome-profile.mjs): by
 * 2026-11 the 02:35 check came up with ~100 Instagram tabs — 110 processes,
 * 5.8 GB within a minute — and chrome:reap-orphans killed it every night
 * before it could answer. The saved session is forgotten before launch and
 * again after a clean close; a browser that still comes up with a crowd of
 * tabs is refused before Instagram is loaded in any of them.
 */
import puppeteer from 'puppeteer-extra';
import StealthPlugin from 'puppeteer-extra-plugin-stealth';
import { guardBrowser } from './lib/browser-guard.mjs';
import { forgetSavedSession } from './lib/chrome-profile.mjs';

puppeteer.use(StealthPlugin());

// Chrome's own first tab and the one this script opens, with one to spare.
const MAX_TABS_AT_START = 3;

function parseArgs(argv) {
  const args = { userDataDir: null, timeoutMs: 30000 };
  for (const a of argv.slice(2)) {
    if (a.startsWith('--user-data-dir=')) args.userDataDir = a.slice('--user-data-dir='.length);
    else if (a.startsWith('--timeout-ms=')) args.timeoutMs = parseInt(a.slice('--timeout-ms='.length), 10) || args.timeoutMs;
  }
  return args;
}

// The first answer is the answer: a verdict already on stdout is never
// followed by a second line when closing the browser then goes wrong.
let answered = false;
const emit = (obj) => {
  if (answered) return;
  answered = true;
  process.stdout.write(JSON.stringify(obj) + '\n');
};

const sinceStart = () => Math.round(process.uptime() * 1000);
const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

(async () => {
  const args = parseArgs(process.argv);

  // A deadline from the first line, not from launch(): guardBrowser() is
  // only attached once launch() resolves, so a launch that hangs had no
  // limit at all (2026-11-05). process.exit() runs the guard's 'exit'
  // listener once a browser exists, and a pipe-mode Chrome dies with Node.
  // Counted from the start of the process, like the guard's own limit: it
  // lands a second after the caller's budget, before the guard (+2.5 s) and
  // before the caller's `timeout` (+5 s), so the reason is said here rather
  // than lost.
  setTimeout(() => {
    emit({ ok: false, reason: 'deadline', error: `no answer within ${Math.round(sinceStart() / 1000)} s; the browser was stopped` });
    console.error('instagram-check-session: deadline reached, exiting');
    process.exit(124);
  }, Math.max(1000, args.timeoutMs + 1000 - sinceStart())).unref();

  if (!args.userDataDir) {
    emit({ ok: false, error: '--user-data-dir is required' });
    process.exit(2);
  }

  // Nothing to reopen, or no launch at all: a browser that restores a crowd
  // of tabs is what the reaper has been killing.
  const stuck = forgetSavedSession(args.userDataDir);
  if (stuck.length > 0) {
    emit({
      ok: false,
      reason: 'saved_session_not_cleared',
      error: `could not remove the profile's saved tabs (${stuck.join(', ')}); Chrome was not started`,
    });
    process.exit(1);
  }

  let browser;
  try {
    browser = guardBrowser(await puppeteer.launch({
      pipe: true, // Chrome exits with this script, however it dies (lib/browser-guard.mjs)
      headless: 'new',
      userDataDir: args.userDataDir,
      defaultViewport: { width: 1280, height: 800 },
      args: [
        '--no-sandbox',
        '--disable-setuid-sandbox',
        '--disable-blink-features=AutomationControlled',
      ],
    }), { maxMs: args.timeoutMs + 2500 });

    const tabs = browser.targets().filter((target) => target.type() === 'page').length;
    if (tabs > MAX_TABS_AT_START) {
      emit({
        ok: false,
        reason: 'session_restored',
        tabs,
        error: `Chrome came up with ${tabs} tabs from the profile's saved session; stopped before loading Instagram in them`,
      });
      process.exit(1); // the guard's 'exit' listener SIGKILLs the browser
    }

    const page = await browser.newPage();
    await page.setUserAgent('Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36');
    await page.goto('https://www.instagram.com/', {
      waitUntil: 'networkidle2',
      // What is left of the caller's budget, not the whole of it again.
      timeout: Math.max(5000, args.timeoutMs - sinceStart() - 500),
    });

    const finalUrl = page.url();
    if (/\/accounts\/(login|emailsignup)/.test(finalUrl)) {
      emit({ ok: true, authenticated: false, reason: 'redirected_to_login', url: finalUrl });
    } else {
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
    }

    // The verdict is out. A clean close is what writes the cookies Instagram
    // just rotated back to the profile; give it ten seconds, no more.
    const closed = await Promise.race([
      browser.close().then(() => true, () => false),
      sleep(10000).then(() => false),
    ]);
    if (closed) {
      // Chrome has exited: leave no tabs for the next script on this profile.
      forgetSavedSession(args.userDataDir);
    }
    process.exit(0);
  } catch (e) {
    // Say why first — a close that hangs must not swallow the reason.
    emit({ ok: false, error: String(e?.message || e) });
    if (browser) {
      await Promise.race([browser.close().catch(() => {}), sleep(3000)]);
    }
    process.exit(1);
  }
})();
