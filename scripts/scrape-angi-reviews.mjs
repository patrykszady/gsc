#!/usr/bin/env node
/**
 * Read an Angi profile's reviews.
 *
 * Angi ships every review in the page's schema.org LocalBusiness block —
 * reviewer, rating, ISO date and the full untruncated body — so this reads
 * that rather than the rendered cards, which truncate and change often.
 *
 * Cloudflare turns away plain requests AND headless Chromium (403 "Just a
 * moment…"), so this runs a real headed browser; the caller supplies a
 * virtual display (xvfb-run). A persistent profile directory keeps the
 * clearance cookie between runs.
 *
 * Usage:
 *   node scripts/scrape-angi-reviews.mjs --url=<profile url> --brand="<business name>"
 *        [--out=<json path>] [--profile-dir=<chrome profile>] [--max-pages=10]
 *        [--timeout-ms=90000] [--proxy=<url>] [--headless]
 *
 * Writes {source_url, business_name, count, reviews[], error?} to --out, or
 * to stdout when no --out is given. Progress goes to stderr. Pass --out
 * whenever the process runs under xvfb-run: it merges the child's stderr
 * into stdout, which would corrupt JSON written there.
 */
import { createRequire } from 'module';
import fs from 'fs';

const require = createRequire(import.meta.url);
const puppeteer = require('puppeteer-extra');
const StealthPlugin = require('puppeteer-extra-plugin-stealth');

puppeteer.use(StealthPlugin());

function parseArgs(argv) {
  const args = { url: null, brand: '', out: null, profileDir: null, maxPages: 10, timeoutMs: 90000, proxy: null, headless: false };

  for (const arg of argv.slice(2)) {
    if (arg.startsWith('--url=')) args.url = arg.slice('--url='.length);
    if (arg.startsWith('--brand=')) args.brand = arg.slice('--brand='.length).trim();
    if (arg.startsWith('--out=')) args.out = arg.slice('--out='.length);
    if (arg.startsWith('--profile-dir=')) args.profileDir = arg.slice('--profile-dir='.length);
    if (arg.startsWith('--max-pages=')) args.maxPages = Number(arg.slice('--max-pages='.length)) || args.maxPages;
    if (arg.startsWith('--timeout-ms=')) args.timeoutMs = Number(arg.slice('--timeout-ms='.length)) || args.timeoutMs;
    if (arg.startsWith('--proxy=')) args.proxy = arg.slice('--proxy='.length);
    if (arg === '--headless') args.headless = true;
  }

  return args;
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
const normalizeName = (value) => (value || '').toLowerCase().replace(/[^a-z0-9]+/g, '');

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

/** Cloudflare's interstitial and its hard block both come back as a 403 with a telltale title. */
function blockedBy(status, title) {
  if (/just a moment|attention required|access denied|security verification/i.test(title || '')) return 'blocked';
  if (status === 403) return 'blocked';
  if (status === 404) return 'not_found';

  return null;
}

function pageUrl(base, pageNumber) {
  if (pageNumber <= 1) return base;

  const url = new URL(base);
  url.searchParams.set('page', String(pageNumber));

  return url.toString();
}

async function main() {
  const args = parseArgs(process.argv);
  if (!args.url) throw new Error('Usage: scrape-angi-reviews.mjs --url=<angi profile url> --brand=<business name>');

  const launchArgs = [
    '--no-sandbox',
    '--disable-setuid-sandbox',
    '--disable-dev-shm-usage',
    '--disable-blink-features=AutomationControlled',
  ];

  let proxyAuth = null;
  if (args.proxy) {
    const proxyUrl = new URL(args.proxy);
    if (proxyUrl.username) {
      proxyAuth = { username: decodeURIComponent(proxyUrl.username), password: decodeURIComponent(proxyUrl.password) };
    }
    launchArgs.push(`--proxy-server=${proxyUrl.protocol}//${proxyUrl.host}`);
  }

  const browser = await puppeteer.launch({
    headless: args.headless ? 'new' : false,
    args: launchArgs,
    ...(args.profileDir ? { userDataDir: args.profileDir } : {}),
  });

  const emit = async (payload) => {
    const json = JSON.stringify(payload);
    if (args.out) {
      fs.writeFileSync(args.out, json);
      console.error(`[angi] wrote ${payload.count} review(s) to ${args.out}`);
    } else {
      process.stdout.write(json);
    }
    await browser.close();
  };

  try {
    const page = await browser.newPage();
    if (proxyAuth) await page.authenticate(proxyAuth);
    await page.setViewport({ width: 1440, height: 2200 });
    await page.setUserAgent('Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36');
    await page.setExtraHTTPHeaders({ 'Accept-Language': 'en-US,en;q=0.9' });

    const seen = new Set();
    const reviews = [];
    let businessName = '';

    for (let pageNumber = 1; pageNumber <= args.maxPages; pageNumber++) {
      const target = pageUrl(args.url, pageNumber);
      const response = await page.goto(target, { waitUntil: 'networkidle2', timeout: args.timeoutMs });
      await sleep(2500 + Math.random() * 1500);

      const status = response?.status?.() ?? 0;
      const blocked = blockedBy(status, await page.title());
      if (blocked) {
        // A challenge on a later page still leaves the earlier pages usable.
        if (pageNumber === 1) return await emit({ source_url: args.url, business_name: '', count: 0, reviews: [], error: blocked });
        console.error(`[angi] page ${pageNumber} came back ${blocked}; keeping ${reviews.length} review(s)`);
        break;
      }

      const structured = await readStructuredReviews(page);
      if (!structured) {
        if (pageNumber === 1) return await emit({ source_url: args.url, business_name: '', count: 0, reviews: [], error: 'no_structured_data' });
        break;
      }

      if (pageNumber === 1) {
        businessName = structured.businessName;
        const wanted = normalizeName(args.brand);
        // Guard the pasted URL: another contractor's page must not import as ours.
        if (wanted && !normalizeName(businessName).startsWith(wanted)) {
          return await emit({ source_url: args.url, business_name: businessName, count: 0, reviews: [], error: 'wrong_business' });
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

    return await emit({ source_url: args.url, business_name: businessName, count: reviews.length, reviews });
  } catch (err) {
    await browser.close();
    throw err;
  }
}

main().catch((err) => {
  console.error(err?.stack || String(err));
  process.exit(1);
});
