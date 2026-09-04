#!/usr/bin/env node
/**
 * Citation builder runner: opens one directory in Chromium (headed on the
 * server's virtual display, or headless), prefills our listing from the
 * payload, uploads photos where the site takes them, and pauses for the
 * human steps (CAPTCHA, verification, the final Submit) — the admin sees
 * the browser through noVNC and clicks Continue, which touches
 * <dir>/resume.flag. State is written to <dir>/state.json after every
 * step so PHP can mirror it into the citations table.
 *
 *   node scripts/citations/run.mjs --slug=remodelersup --payload=<json> --state=<json> \
 *        --dir=<work dir> --user-data-dir=<chrome profile> [--timeout-ms=2700000] [--headless]
 *
 * Adapters: scripts/citations/adapters/<slug>.mjs (default export async (ctx) => {}),
 * falling back to adapters/generic.mjs.
 */

import puppeteer from 'puppeteer-extra';
import StealthPlugin from 'puppeteer-extra-plugin-stealth';
import fs from 'node:fs';
import path from 'node:path';
import { fileURLToPath, pathToFileURL } from 'node:url';
import { makeContext } from './lib/ctx.mjs';

puppeteer.use(StealthPlugin());

const here = path.dirname(fileURLToPath(import.meta.url));

function parseArgs(argv) {
  const args = { slug: null, payload: null, state: null, dir: null, userDataDir: null, timeoutMs: 2700000, headless: false };
  for (const a of argv.slice(2)) {
    if (a.startsWith('--slug=')) args.slug = a.slice(7);
    else if (a.startsWith('--payload=')) args.payload = a.slice(10);
    else if (a.startsWith('--state=')) args.state = a.slice(8);
    else if (a.startsWith('--dir=')) args.dir = a.slice(6);
    else if (a.startsWith('--user-data-dir=')) args.userDataDir = a.slice(16);
    else if (a.startsWith('--timeout-ms=')) args.timeoutMs = parseInt(a.slice(13), 10) || args.timeoutMs;
    else if (a === '--headless') args.headless = true;
  }
  if (!args.slug || !args.payload || !args.state || !args.dir) {
    throw new Error('Usage: run.mjs --slug --payload --state --dir --user-data-dir [--timeout-ms] [--headless]');
  }
  return args;
}

async function main() {
  const args = parseArgs(process.argv);
  const payload = JSON.parse(fs.readFileSync(args.payload, 'utf8'));
  fs.mkdirSync(path.join(args.dir, 'shots'), { recursive: true });
  fs.mkdirSync(path.join(args.dir, 'photos'), { recursive: true });

  const started = Date.now();
  const deadline = started + args.timeoutMs;

  const browser = await puppeteer.launch({
    headless: args.headless ? true : false,
    defaultViewport: null,
    userDataDir: args.userDataDir || undefined,
    args: [
      '--no-sandbox', '--disable-dev-shm-usage', '--no-first-run', '--no-default-browser-check',
      '--window-size=1366,900', '--window-position=0,0', '--disable-blink-features=AutomationControlled',
      '--lang=en-US,en',
    ],
  });
  const page = (await browser.pages())[0] || (await browser.newPage());
  await page.setUserAgent('Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36');
  page.setDefaultNavigationTimeout(45000);
  page.setDefaultTimeout(20000);

  const ctx = makeContext({ browser, page, payload, args, deadline });
  ctx.log(`runner started for ${args.slug}${args.headless ? ' (headless)' : ''}`);

  let adapter;
  const custom = path.join(here, 'adapters', `${args.slug}.mjs`);
  const generic = path.join(here, 'adapters', 'generic.mjs');
  adapter = (await import(pathToFileURL(fs.existsSync(custom) ? custom : generic).href)).default;

  const stopWatcher = setInterval(() => {
    if (fs.existsSync(path.join(args.dir, 'stop.flag'))) {
      ctx.log('stop requested');
      ctx.setState({ phase: 'stopped', done: false, error: 'Stopped by the admin.' });
      browser.close().catch(() => {});
      process.exit(0);
    }
    if (Date.now() > deadline) {
      ctx.setState({ phase: 'timeout', error: 'The session reached its time limit.' });
      browser.close().catch(() => {});
      process.exit(0);
    }
  }, 2000);

  try {
    await adapter(ctx);
  } catch (e) {
    ctx.log(`error: ${e?.message || e}`);
    try { await ctx.shot('error'); } catch {}
    ctx.setState({ phase: 'error', error: String(e?.message || e).slice(0, 400) });
  } finally {
    clearInterval(stopWatcher);
    await browser.close().catch(() => {});
  }
}

main().catch((e) => {
  process.stderr.write(`[citations] fatal: ${e?.stack || e}\n`);
  process.exit(1);
});
