// Helpers to keep the Yelp Puppeteer userDataDir healthy across runs.
//
// Two failure modes we work around:
//
//  1. Stale Chromium SingletonLock. When Node is hard-killed (e.g. Symfony
//     Process SIGKILL after a timeout) the browser child can survive and
//     hold the lock, or Node dies before Chromium and leaves the symlink
//     pointing at a PID that no longer exists. Either way the next
//     puppeteer.launch() throws "The browser is already running for ...".
//
//  2. Orphan Chromium processes that outlive their parent Node and keep
//     using the same userDataDir. We can identify them via /proc cmdline
//     and SIGKILL them so the next launch succeeds.
//
// Usage:
//   import { purgeStaleChromiumLocks, installShutdownHandlers } from './lib/yelp-userdata-lock.mjs';
//   purgeStaleChromiumLocks(args.userDataDir);
//   const browser = await puppeteer.launch({ userDataDir: args.userDataDir, ... });
//   installShutdownHandlers(browser);

import fs from 'node:fs';
import path from 'node:path';

const LOCK_FILES = ['SingletonLock', 'SingletonSocket', 'SingletonCookie'];

function isPidAlive(pid) {
  if (!pid || !Number.isFinite(pid) || pid <= 0) return false;
  try {
    // Signal 0 = existence/permission probe, doesn't actually kill.
    process.kill(pid, 0);
    return true;
  } catch (e) {
    // ESRCH = no such process; EPERM = process exists but we can't signal it.
    return e.code === 'EPERM';
  }
}

function readLockPid(lockPath) {
  try {
    // Chromium creates SingletonLock as a symlink whose target encodes
    // "<hostname>-<pid>". Parse the trailing -<pid>.
    const target = fs.readlinkSync(lockPath);
    const m = target.match(/-(\d+)$/);
    return m ? parseInt(m[1], 10) : null;
  } catch {
    return null;
  }
}

function killOrphanChromiumForDir(userDataDir, log) {
  let entries;
  try {
    entries = fs.readdirSync('/proc');
  } catch {
    return 0;
  }
  const wanted = `--user-data-dir=${userDataDir}`;
  let killed = 0;
  for (const entry of entries) {
    if (!/^\d+$/.test(entry)) continue;
    const pid = parseInt(entry, 10);
    if (pid === process.pid) continue;
    let cmdline;
    try {
      cmdline = fs.readFileSync(`/proc/${pid}/cmdline`, 'utf8');
    } catch {
      continue;
    }
    if (!cmdline) continue;
    // /proc cmdline is NUL-separated. Match exact arg to avoid stray hits.
    const args = cmdline.split('\0');
    if (!args.some(a => a === wanted)) continue;
    // Only target Chromium-ish binaries to avoid hitting unrelated processes.
    const exe = (args[0] || '').toLowerCase();
    if (!/(chrome|chromium)/.test(exe)) continue;
    try {
      process.kill(pid, 'SIGKILL');
      killed++;
      log?.(`[yelp-lock] killed orphan chromium pid=${pid}`);
    } catch (e) {
      log?.(`[yelp-lock] could not kill pid=${pid}: ${e.message}`);
    }
  }
  return killed;
}

/**
 * Remove stale Chromium singleton locks and kill orphan chromes still
 * bound to this userDataDir. Safe to call before every puppeteer.launch.
 */
export function purgeStaleChromiumLocks(userDataDir, log = (m) => console.error(m)) {
  if (!userDataDir) return;
  if (!fs.existsSync(userDataDir)) return;

  // Step 1: examine each lock. If it points at a dead PID, unlink it. If
  // the PID is alive, try to kill it (it's an orphan from a previous run,
  // since no other tenant should be using this dir).
  for (const name of LOCK_FILES) {
    const p = path.join(userDataDir, name);
    let stat;
    try { stat = fs.lstatSync(p); } catch { continue; }
    if (!stat) continue;

    const pid = stat.isSymbolicLink() ? readLockPid(p) : null;
    if (pid && isPidAlive(pid)) {
      try {
        process.kill(pid, 'SIGKILL');
        log(`[yelp-lock] killed lock-holding pid=${pid} (${name})`);
      } catch (e) {
        log(`[yelp-lock] could not kill pid=${pid} (${name}): ${e.message}`);
      }
    }
    try {
      fs.unlinkSync(p);
      log(`[yelp-lock] removed stale ${name}`);
    } catch (e) {
      if (e.code !== 'ENOENT') log(`[yelp-lock] could not unlink ${name}: ${e.message}`);
    }
  }

  // Step 2: sweep /proc for any chrome process still bound to this dir
  // that didn't show up in the lock files (lock may have been already
  // unlinked but the browser kept running).
  killOrphanChromiumForDir(userDataDir, log);
}

/**
 * Install SIGTERM / SIGINT handlers that close the Puppeteer browser
 * before the Node process exits. Lets Symfony Process timeouts (which
 * send SIGTERM first) clean up Chromium instead of leaving an orphan
 * that holds SingletonLock for the next attempt.
 */
export function installShutdownHandlers(browser, log = (m) => console.error(m)) {
  let firing = false;
  const handler = (signal) => {
    if (firing) return;
    firing = true;
    log(`[yelp-lock] received ${signal}, closing browser`);
    Promise.resolve(browser?.close?.()).catch(() => {}).finally(() => {
      // Re-raise the signal as a normal exit code so the parent knows we
      // were terminated.
      process.exit(signal === 'SIGTERM' ? 143 : 130);
    });
  };
  process.once('SIGTERM', () => handler('SIGTERM'));
  process.once('SIGINT', () => handler('SIGINT'));
}

const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

function isLaunchRaceError(err) {
  const msg = String(err?.message || err || '');
  return (
    msg.includes('Target closed') ||
    msg.includes('Target.setDiscoverTargets') ||
    msg.includes('Protocol error')
  );
}

/**
 * Launch Chromium with one recovery retry after lock cleanup.
 * This handles startup races where a freshly-killed stale browser
 * has not fully exited yet and Puppeteer fails with TargetCloseError.
 */
export async function launchPuppeteerWithLockRecovery({
  puppeteer,
  launchOptions,
  userDataDir,
  log = (m) => console.error(m),
}) {
  purgeStaleChromiumLocks(userDataDir, log);
  await sleep(1200);

  try {
    return await puppeteer.launch(launchOptions);
  } catch (e) {
    if (!isLaunchRaceError(e)) throw e;
    log(`[yelp-lock] launch failed (${e.message}); retrying after second lock purge`);

    purgeStaleChromiumLocks(userDataDir, log);
    await sleep(1800);
    return await puppeteer.launch(launchOptions);
  }
}
