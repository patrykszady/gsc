#!/usr/bin/env node
/**
 * Read an Angi profile's reviews.
 *
 * Angi ships every review in the page's schema.org LocalBusiness block —
 * reviewer, rating, ISO date and the full untruncated body — so this reads
 * that rather than the rendered cards, which truncate and change often.
 *
 * Cloudflare stands in front of it and turns away plain requests AND
 * headless Chromium, so this drives a real headed browser; the caller
 * supplies a virtual display (xvfb-run). Two things get past the guard:
 * waiting out the "Just a moment…" interstitial, which clears itself in a
 * few seconds, and — when the server's own address is refused outright —
 * retrying through a residential proxy, a fresh session each time. A
 * persistent profile directory keeps the clearance cookie between runs,
 * and is used only for the direct attempt, since that cookie is tied to
 * the address that earned it.
 *
 * Usage:
 *   node scripts/scrape-angi-reviews.mjs --url=<profile url> --brand="<business name>"
 *        [--out=<json path>] [--profile-dir=<chrome profile>] [--max-pages=10]
 *        [--timeout-ms=90000] [--clearance-ms=45000] [--proxy=<url>]
 *        [--proxy-attempts=4] [--headless]
 *
 * Writes {source_url, business_name, count, reviews[], error?} to --out, or
 * to stdout when no --out is given. Progress goes to stderr. Pass --out
 * whenever the process runs under xvfb-run: it merges the child's stderr
 * into stdout, which would corrupt JSON written there.
 */
import { createRequire } from 'module';
import fs from 'fs';

import { installShutdownHandlers, launchPuppeteerWithLockRecovery } from './lib/yelp-userdata-lock.mjs';

const require = createRequire(import.meta.url);
const puppeteer = require('puppeteer-extra');
const StealthPlugin = require('puppeteer-extra-plugin-stealth');

puppeteer.use(StealthPlugin());

function parseArgs(argv) {
  const args = {
    url: null,
    brand: '',
    out: null,
    profileDir: null,
    maxPages: 10,
    timeoutMs: 90000,
    clearanceMs: 45000,
    proxy: null,
    proxyAttempts: 4,
    headless: false,
  };

  for (const arg of argv.slice(2)) {
    if (arg.startsWith('--url=')) args.url = arg.slice('--url='.length);
    if (arg.startsWith('--brand=')) args.brand = arg.slice('--brand='.length).trim();
    if (arg.startsWith('--out=')) args.out = arg.slice('--out='.length);
    if (arg.startsWith('--profile-dir=')) args.profileDir = arg.slice('--profile-dir='.length);
    if (arg.startsWith('--max-pages=')) args.maxPages = Number(arg.slice('--max-pages='.length)) || args.maxPages;
    if (arg.startsWith('--timeout-ms=')) args.timeoutMs = Number(arg.slice('--timeout-ms='.length)) || args.timeoutMs;
    if (arg.startsWith('--clearance-ms=')) args.clearanceMs = Number(arg.slice('--clearance-ms='.length)) || args.clearanceMs;
    if (arg.startsWith('--proxy=')) args.proxy = arg.slice('--proxy='.length);
    if (arg.startsWith('--proxy-attempts=')) args.proxyAttempts = Number(arg.slice('--proxy-attempts='.length)) || args.proxyAttempts;
    if (arg === '--headless') args.headless = true;
  }

  return args;
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const normalizeName = (value) => (value || '').toLowerCase().replace(/[^a-z0-9]+/g, '');

/** The interstitial clears itself; the block page does not. */
const CHALLENGE = /just a moment|checking your browser|security verification|performing security/i;
const HARD_BLOCK = /attention required|you have been blocked|access denied|forbidden/i;

/**
 * Cloudflare answers the first request with an interstitial that reloads
 * the page once it is satisfied. Give it that time before calling the page
 * blocked.
 *
 * @return {Promise<string|null>} an error code, or null once the real page is up
 */
async function waitForClearance(page, clearanceMs) {
  const deadline = Date.now() + clearanceMs;
  let waited = false;

  for (;;) {
    const title = await page.title().catch(() => '');

    if (HARD_BLOCK.test(title)) return 'blocked';

    if (!CHALLENGE.test(title)) {
      if (waited) console.error('[angi] challenge cleared');

      return null;
    }

    if (Date.now() >= deadline) return 'blocked';

    waited = true;
    await sleep(2500);
  }
}

/** The page's LocalBusiness block: its name and its reviews, as Angi published them. */
async function readStructuredReviews(page) {
  return await page.evaluate(() => {
    const decode = (value) => {
      const el = document.createElement('textarea');
      el.innerHTML = value || '';

      return el.value;
    };

    for (const script of document.querySelectorAll('script[type="application/ld+json"]')) {
      let data;
      try {
        data = JSON.parse(script.textContent);
      } catch {
        continue;
      }

      const nodes = Array.isArray(data) ? data : [data];
      for (const node of nodes) {
        if (!node || node['@type'] !== 'LocalBusiness') continue;

        const reviews = Array.isArray(node.review) ? node.review : [];

        return {
          businessName: decode(node.name || ''),
          reviewCount: Number(node.aggregateRating?.reviewCount ?? 0) || 0,
          reviews: reviews.map((review) => ({
            reviewer_name: decode(review.author?.name || '').trim(),
            review_description: decode(review.reviewBody || '').replace(/\r/g, '').trim(),
            review_date_raw: review.datePublished || null,
            star_rating: Number(review.reviewRating?.ratingValue ?? 0) || null,
          })),
        };
      }
    }

    return null;
  });
}

function pageUrl(base, pageNumber) {
  if (pageNumber <= 1) return base;

  const url = new URL(base);
  url.searchParams.set('page', String(pageNumber));

  return url.toString();
}

/**
 * One pass over the profile with one browser.
 *
 * @return {Promise<{error: string, businessName?: string} | {reviews: Array, businessName: string}>}
 */
async function collectReviews(page, args) {
  const seen = new Set();
  const reviews = [];
  let businessName = '';

  for (let pageNumber = 1; pageNumber <= args.maxPages; pageNumber++) {
    const response = await page.goto(pageUrl(args.url, pageNumber), { waitUntil: 'networkidle2', timeout: args.timeoutMs });

    if (response?.status?.() === 404) {
      if (pageNumber === 1) return { error: 'not_found' };
      break;
    }

    const blocked = await waitForClearance(page, args.clearanceMs);
    if (blocked) {
      // A challenge on a later page still leaves the earlier pages usable.
      if (pageNumber === 1) return { error: blocked };
      console.error(`[angi] page ${pageNumber} was ${blocked}; keeping ${reviews.length} review(s)`);
      break;
    }

    await sleep(1500 + Math.random() * 1500);

    const structured = await readStructuredReviews(page);
    if (!structured) {
      if (pageNumber === 1) return { error: 'no_structured_data' };
      break;
    }

    if (pageNumber === 1) {
      businessName = structured.businessName;
      const wanted = normalizeName(args.brand);
      // Guard the pasted URL: another contractor's page must not import as
      // ours. With no brand to compare against there is nothing to check, so
      // this refuses rather than importing whatever the page holds.
      if (!wanted) return { error: 'brand_not_configured', businessName };
      if (!normalizeName(businessName).startsWith(wanted)) {
        return { error: 'wrong_business', businessName };
      }
      console.error(`[angi] ${businessName}: ${structured.reviewCount} review(s) advertised`);
    }

    let fresh = 0;
    for (const review of structured.reviews) {
      if (!review.reviewer_name || !review.review_description) continue;
      const key = `${review.reviewer_name.toLowerCase()}|${review.review_date_raw ?? ''}|${review.review_description.slice(0, 120).toLowerCase()}`;
      if (seen.has(key)) continue;
      seen.add(key);
      reviews.push(review);
      fresh++;
    }

    console.error(`[angi] page ${pageNumber}: ${fresh} new review(s), ${reviews.length} total`);

    // Angi serves an empty review list past the last page, which is where this stops.
    if (fresh === 0) break;
  }

  return { reviews, businessName };
}

async function main() {
  const args = parseArgs(process.argv);
  if (!args.url) throw new Error('Usage: scrape-angi-reviews.mjs --url=<angi profile url> --brand=<business name>');

  const baseLaunchArgs = [
    '--no-sandbox',
    '--disable-setuid-sandbox',
    '--disable-dev-shm-usage',
    '--disable-blink-features=AutomationControlled',
  ];

  let proxyConfig = null;
  if (args.proxy) {
    try {
      const proxyUrl = new URL(args.proxy);
      proxyConfig = {
        host: `${proxyUrl.protocol}//${proxyUrl.hostname}:${proxyUrl.port || 8080}`,
        username: decodeURIComponent(proxyUrl.username || ''),
        password: decodeURIComponent(proxyUrl.password || ''),
      };
    } catch {
      console.error('[angi] ignoring an unparseable --proxy');
    }
  }

  // The server's own address first, with the profile that may already hold a
  // clearance cookie; then residential sessions, each in a clean browser
  // because that cookie belongs to the address that earned it.
  const attempts = [{ proxy: null, profileDir: args.profileDir }];
  if (proxyConfig) {
    for (let i = 0; i < Math.max(0, args.proxyAttempts); i++) {
      attempts.push({ proxy: proxyConfig, profileDir: null });
    }
  }

  const emit = (payload) => {
    const json = JSON.stringify(payload);
    if (args.out) {
      fs.writeFileSync(args.out, json);
      console.error(`[angi] wrote ${payload.count} review(s) to ${args.out}`);
    } else {
      process.stdout.write(json);
    }
  };

  let lastError = 'blocked';
  let lastBusinessName = '';

  for (const [index, attempt] of attempts.entries()) {
    const launchArgs = [...baseLaunchArgs];
    let proxyAuth = null;

    if (attempt.proxy) {
      launchArgs.push(`--proxy-server=${attempt.proxy.host}`);
      // A fresh session id asks the proxy for a different residential address.
      const sessionId = Math.random().toString(36).slice(2, 10);
      proxyAuth = { username: `${attempt.proxy.username}-session-${sessionId}`, password: attempt.proxy.password };
      console.error(`[angi] attempt ${index + 1}/${attempts.length} through a residential session`);
    } else if (attempts.length > 1) {
      console.error(`[angi] attempt ${index + 1}/${attempts.length} from this server`);
    }

    // A job killed mid-scrape leaves Chromium's SingletonLock behind, and
    // every later run on that profile would fail to launch.
    const browser = attempt.profileDir
      ? await launchPuppeteerWithLockRecovery({
        puppeteer,
        launchOptions: { headless: args.headless ? 'new' : false, args: launchArgs, userDataDir: attempt.profileDir },
        userDataDir: attempt.profileDir,
      })
      : await puppeteer.launch({ headless: args.headless ? 'new' : false, args: launchArgs });
    installShutdownHandlers(browser);

    try {
      const page = await browser.newPage();
      if (proxyAuth) await page.authenticate(proxyAuth);
      await page.setViewport({ width: 1440, height: 2200 });
      await page.setUserAgent('Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36');
      await page.setExtraHTTPHeaders({ 'Accept-Language': 'en-US,en;q=0.9' });

      const outcome = await collectReviews(page, args);

      if (outcome.error) {
        lastError = outcome.error;
        lastBusinessName = outcome.businessName ?? lastBusinessName;
        // Only a refusal is worth another address; the rest would repeat.
        if (outcome.error !== 'blocked') break;
        console.error(`[angi] ${outcome.error}; trying again`);
        continue;
      }

      emit({
        source_url: args.url,
        business_name: outcome.businessName,
        count: outcome.reviews.length,
        reviews: outcome.reviews,
      });

      return;
    } catch (err) {
      lastError = 'scrape_failed';
      console.error(`[angi] attempt ${index + 1} failed: ${err.message}`);
    } finally {
      await browser.close().catch(() => {});
    }

    if (index < attempts.length - 1) await sleep(2000 + Math.random() * 3000);
  }

  emit({ source_url: args.url, business_name: lastBusinessName, count: 0, reviews: [], error: lastError });
}

main().catch((err) => {
  console.error(err?.stack || String(err));
  process.exit(1);
});
