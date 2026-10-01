// Chrome must never outlive the script that launched it. Puppeteer closes
// the browser on a clean exit and on SIGTERM/SIGINT/SIGHUP, but not when
// Node itself is SIGKILLed — which is exactly what Symfony Process does to a
// script that runs past its timeout (stop(0): SIGTERM, then SIGKILL at once).
// The browser is then orphaned under PID 1 with every tab it had open (one
// Instagram browser ran 22 days with ~85 processes on hive-prod, killed
// 2026-10-01).
//
// guardBrowser() kills the browser whenever Node exits, on any of those
// signals, and — the part that matters — by itself once `maxMs` has passed,
// so a caller with a hard timeout should pass a limit a little under it:
// the script then ends on its own terms before anything can SIGKILL Node.
//
//   const browser = guardBrowser(await puppeteer.launch({...}), { maxMs: 280_000 });
export function guardBrowser(browser, { maxMs = 0 } = {}) {
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
    setTimeout(() => {
      console.error(`browser-guard: ${maxMs}ms limit reached, closing the browser`);
      kill();
      process.exit(124);
    }, maxMs).unref();
  }

  return browser;
}
