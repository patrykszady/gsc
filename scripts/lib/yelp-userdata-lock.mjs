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

function normalizePath(p) {
  if (!p) return null;
  try {
    return fs.realpathSync(p);
  } catch {
    try {
      return path.resolve(p);
    } catch {
      return p;
    }
  }
}

function getUserDataDirArg(args) {
  for (const a of args) {
    if (typeof a === 'string' && a.startsWith('--user-data-dir=')) {
      return a.slice('--user-data-dir='.length);
    }
  }
  return null;
}

function readPgid(pid) {
  // /proc/<pid>/stat: pid (comm) state ppid pgrp ...
  // comm may contain spaces and parens, so we slice on the last ')'.
  try {
    const data = fs.readFileSync(`/proc/${pid}/stat`, 'utf8');
    const tail = data.slice(data.lastIndexOf(')') + 2).split(' ');
    // tail[0]=state, tail[1]=ppid, tail[2]=pgrp
    const pgid = parseInt(tail[2], 10);
    return Number.isFinite(pgid) ? pgid : null;
  } catch {
    return null;
  }
}

function killProcessGroup(pgid, log) {
  if (!pgid || pgid <= 1) return 0;
  try {
    process.kill(-pgid, 'SIGKILL');
    log?.(`[yelp-lock] SIGKILL process group pgid=${pgid}`);
    return 1;
  } catch (e) {
    if (e.code !== 'ESRCH') log?.(`[yelp-lock] could not kill pgid=${pgid}: ${e.message}`);
    return 0;
  }
}

function killAllChromiumForDir(userDataDir, log) {
  let entries;
  try {
    entries = fs.readdirSync('/proc');
  } catch {
    return { killed: 0, pgids: new Set() };
  }
  const wantedRaw = path.resolve(userDataDir);
  const wantedNorm = normalizePath(userDataDir);
  const pgids = new Set();
  // First pass: find every chrome/chromium pid that references the dir
  // via --user-data-dir OR via an open cwd/fd inside the dir.
  for (const entry of entries) {
    if (!/^\d+$/.test(entry)) continue;
    const pid = parseInt(entry, 10);
    if (pid === process.pid) continue;

    let cmdline = '';
    try { cmdline = fs.readFileSync(`/proc/${pid}/cmdline`, 'utf8'); } catch { continue; }
    if (!cmdline) continue;
    const args = cmdline.split('\0');
    const exe = (args[0] || '').toLowerCase();
    if (!/(chrome|chromium)/.test(exe)) continue;

    let matchesDir = false;
    const argDir = getUserDataDirArg(args);
    if (argDir) {
      const argRaw = path.resolve(argDir);
      const argNorm = normalizePath(argDir);
      matchesDir = argRaw === wantedRaw || (argNorm && wantedNorm && argNorm === wantedNorm);
    }
    if (!matchesDir) {
      // Fallback: helper processes (renderer/GPU/zygote) do NOT carry
      // --user-data-dir. Check their cwd symlink and any open fd that
      // points into the user-data dir.
      try {
        const cwd = fs.readlinkSync(`/proc/${pid}/cwd`);
        if (cwd && (cwd === wantedRaw || cwd.startsWith(wantedRaw + '/') ||
            (wantedNorm && (cwd === wantedNorm || cwd.startsWith(wantedNorm + '/'))))) {
          matchesDir = true;
        }
      } catch {}
    }
    if (!matchesDir) continue;

    const pgid = readPgid(pid);
    if (pgid && pgid > 1) pgids.add(pgid);
  }

  // Second pass: kill every process group we identified. -SIGKILL on the
  // group nukes the entire Chromium tree (main + renderer + GPU + zygote
  // + crashpad_handler) in one syscall \u2014 helpers don't get reparented
  // to init half-alive.
  let killed = 0;
  for (const pgid of pgids) {
    killed += killProcessGroup(pgid, log);
  }
  return { killed, pgids };
}

function waitForChromiumExit(userDataDir, log, maxMs = 8000) {
  const start = Date.now();
  const wantedRaw = path.resolve(userDataDir);
  const wantedNorm = normalizePath(userDataDir);
  while (Date.now() - start < maxMs) {
    let alive = 0;
    let entries;
    try { entries = fs.readdirSync('/proc'); } catch { return; }
    for (const entry of entries) {
      if (!/^\d+$/.test(entry)) continue;
      const pid = parseInt(entry, 10);
      let cmdline = '';
      try { cmdline = fs.readFileSync(`/proc/${pid}/cmdline`, 'utf8'); } catch { continue; }
      if (!cmdline) continue;
      const exe = (cmdline.split('\0')[0] || '').toLowerCase();
      if (!/(chrome|chromium)/.test(exe)) continue;
      const args = cmdline.split('\0');
      const argDir = getUserDataDirArg(args);
      let matches = false;
      if (argDir) {
        const argRaw = path.resolve(argDir);
        const argNorm = normalizePath(argDir);
        matches = argRaw === wantedRaw || (argNorm && wantedNorm && argNorm === wantedNorm);
      }
      if (!matches) {
        try {
          const cwd = fs.readlinkSync(`/proc/${pid}/cwd`);
          if (cwd && (cwd === wantedRaw || cwd.startsWith(wantedRaw + '/') ||
              (wantedNorm && (cwd === wantedNorm || cwd.startsWith(wantedNorm + '/'))))) {
            matches = true;
          }
        } catch {}
      }
      if (matches) alive++;
    }
    if (alive === 0) {
      log?.(`[yelp-lock] chromium tree fully exited after ${Date.now() - start}ms`);
      return;
    }
    // busy-wait without blocking: 100ms sleep
    const wait = new Int32Array(new SharedArrayBuffer(4));
    Atomics.wait(wait, 0, 0, 100);
  }
  log?.(`[yelp-lock] WARN: chromium tree still alive after ${maxMs}ms wait`);
}

function killOrphanChromiumForDir(userDataDir, log) {
  // Backwards-compatible export name; delegates to the stronger
  // group-killing implementation.
  const { killed } = killAllChromiumForDir(userDataDir, log);
  if (killed > 0) waitForChromiumExit(userDataDir, log);
  return killed;
}

/**
 * Remove stale Chromium singleton locks and kill orphan chromes still
 * bound to this userDataDir. Safe to call before every puppeteer.launch.
 */
export function purgeStaleChromiumLocks(userDataDir, log = (m) => console.error(m)) {
  if (!userDataDir) return;
  if (!fs.existsSync(userDataDir)) return;

  // Step 1: find any chrome/chromium pids still bound to this dir
  // (main + helpers reparented to init) and SIGKILL their entire
  // process groups. This is the only way to nuke helper processes
  // (renderer/GPU/zygote/crashpad) that don't carry --user-data-dir.
  const { killed } = killAllChromiumForDir(userDataDir, log);

  // Step 2: also pick up the PID encoded in the SingletonLock symlink
  // (covers the case where the lock survived but the helpers already
  // exited).
  for (const name of LOCK_FILES) {
    const p = path.join(userDataDir, name);
    let stat;
    try { stat = fs.lstatSync(p); } catch { continue; }
    if (!stat) continue;
    const pid = stat.isSymbolicLink() ? readLockPid(p) : null;
    if (pid && isPidAlive(pid)) {
      const pgid = readPgid(pid);
      if (pgid && pgid > 1) {
        killProcessGroup(pgid, log);
      } else {
        try { process.kill(pid, 'SIGKILL'); log(`[yelp-lock] killed lock-holding pid=${pid} (${name})`); } catch {}
      }
    }
  }

  // Step 3: WAIT for the kernel to actually reap everything before we
  // unlink the singleton files. If we unlink while helpers are still
  // alive, Chromium's next launch will detect them and refuse with
  // "browser is already running for ...".
  if (killed > 0) waitForChromiumExit(userDataDir, log);

  // Step 4: now it's safe to drop stale lock files.
  for (const name of LOCK_FILES) {
    const p = path.join(userDataDir, name);
    try {
      fs.unlinkSync(p);
      log(`[yelp-lock] removed stale ${name}`);
    } catch (e) {
      if (e.code !== 'ENOENT') log(`[yelp-lock] could not unlink ${name}: ${e.message}`);
    }
  }
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
