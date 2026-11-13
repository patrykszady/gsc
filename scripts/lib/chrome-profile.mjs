// A Chrome that Puppeteer launches on a persistent profile (userDataDir)
// reopens every tab the profile's last run had open — after a clean close and
// after a SIGKILL alike — and then adds its own: the `about:blank` Puppeteer
// puts on the command line, plus whatever the script opens. Nothing ever
// takes a tab back out, so the saved session grows by a tab or two on every
// run, and each restored tab is a full page in a renderer process of its own
// (Puppeteer turns off ProcessPerSiteUpToMainFrameThreshold, so tabs of one
// site do not share one).
//
// Measured 2026-11-12 with Puppeteer 24.40 / Chrome 146 and the launch
// options instagram-check-session.mjs uses: tabs at launch 1, 3, 5, 7, 9, …
// and two more Chrome processes each run, kill or no kill. The nightly
// instagram:check-session did exactly that to storage/app/instagram-puppeteer
// for three months: by November the browser came up with ~100 Instagram tabs
// — 110 processes and 5.8 GB inside a minute — and the reaper killed it every
// night before the check could answer.
//
// The saved session is only the list of tabs to reopen. The sign-in lives in
// Cookies / Local Storage / IndexedDB, which this never touches.
import fs from 'node:fs';
import path from 'node:path';

// `Sessions/` since Chrome 86; the four loose files are where older builds
// kept the same thing.
const SAVED_SESSION = ['Sessions', 'Current Session', 'Current Tabs', 'Last Session', 'Last Tabs'];

/**
 * Delete what Chrome would reopen at its next launch on this profile. Call it
 * before launching (and again after a clean close, so nothing is left for the
 * next script that opens the profile).
 *
 * Returns the paths it could NOT remove — empty when the profile is clean.
 * A caller that gets a non-empty list should not launch: the tabs are still
 * there.
 */
export function forgetSavedSession(userDataDir, profile = 'Default') {
  const stuck = [];

  for (const name of SAVED_SESSION) {
    const target = path.join(userDataDir, profile, name);

    try {
      fs.rmSync(target, { recursive: true, force: true });
    } catch {}

    if (fs.existsSync(target)) {
      stuck.push(target);
    }
  }

  return stuck;
}
