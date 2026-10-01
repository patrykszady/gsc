// Chrome must never outlive the script that launched it. Puppeteer closes
// the browser on a clean exit and on SIGTERM/SIGINT/SIGHUP, but not when
// Node itself is SIGKILLed — which is exactly what Symfony Process does to a
// script that runs past its timeout (stop(0): SIGTERM, then SIGKILL at once).
// The browser is then orphaned under PID 1 with every tab it had open (one
// Instagram browser ran 22 days with ~85 processes on hive-prod, killed
// 2026-10-01).
//
// The same evening instagram-add-location.mjs reached its 300 s Process
// timeout before its own 280 s limit fired (the limit counted from the end
// of a slow launch), Node was SIGKILLed, and the orphaned Chrome grew to
// ~126 processes and ~8 GB until hive-prod stopped answering. So:
//
//  - Launch with `pipe: true` (launchGuarded() does, and every script must):
//    Chrome then talks to Node over a pipe instead of a WebSocket and exits
//    by itself the moment Node dies, however it dies. Measured: SIGKILL Node
//    and a WebSocket Chrome stays up; a pipe Chrome is gone within a second.
//  - `maxMs` counts from the script's start, not from the launch, so a
//    caller with a hard timeout can pass a limit a little under it and the
//    script ends on its own terms first.
//  - More than `maxPages` open tabs closes the browser: none of these
//    scripts needs more than a handful.
//
//   const browser = await launchGuarded(puppeteer, { headless: 'new', ... }, { maxMs: 280_000 });
export async function launchGuarded(puppeteer, options = {}, guard = {}) {
  return guardBrowser(await puppeteer.launch({ ...options, pipe: true }), guard);
}

export function guardBrowser(browser, { maxMs = 0, maxPages = 15 } = {}) {
  const kill = () => {
    try {
      browser.process()?.kill('SIGKILL');
    } catch {}
  };

  process.on('exit', kill);

  for (const [signal, code] of [['SIGTERM', 143], ['SIGINT', 130], ['SIGHUP', 129]]) {
    process.on(signal, () => {
      kill();
      process.exit(code);
    });
  }

  if (maxMs > 0) {
    const remaining = Math.max(1000, maxMs - process.uptime() * 1000);

    setTimeout(() => {
      console.error(`browser-guard: ${maxMs}ms limit reached, closing the browser`);
      kill();
      process.exit(124);
    }, remaining).unref();
  }

  if (maxPages > 0) {
    browser.on('targetcreated', async (target) => {
      if (target.type() !== 'page') {
        return;
      }

      const open = (await browser.pages().catch(() => [])).length;

      if (open > maxPages) {
        console.error(`browser-guard: ${open} tabs open (limit ${maxPages}), closing the browser`);
        kill();
        process.exit(125);
      }
    });
  }

  return browser;
}
