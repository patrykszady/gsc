#!/usr/bin/env node
import puppeteer from 'puppeteer-extra';
import StealthPlugin from 'puppeteer-extra-plugin-stealth';

puppeteer.use(StealthPlugin());

function parseArgs(argv) {
  const args = {
    url: 'https://www.houzz.com/professionals/kitchen-and-bath-remodelers/gs-construction-pfvwus-pf~1225706575',
    timeoutMs: 120000,
    maxScrolls: 40,
    headless: true,
    proxy: null,
  };

  for (const arg of argv.slice(2)) {
    if (arg.startsWith('--url=')) args.url = arg.slice('--url='.length);
    if (arg.startsWith('--timeout-ms=')) args.timeoutMs = Number(arg.slice('--timeout-ms='.length)) || args.timeoutMs;
    if (arg.startsWith('--max-scrolls=')) args.maxScrolls = Number(arg.slice('--max-scrolls='.length)) || args.maxScrolls;
    if (arg.startsWith('--proxy=')) args.proxy = arg.slice('--proxy='.length);
    if (arg === '--headed') args.headless = false;
  }

  return args;
}

async function autoExpandReviews(page, maxScrolls) {
  const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
  const scrollReviewContainers = async () => {
    await page.evaluate(() => {
      window.scrollTo(0, document.body.scrollHeight);

      const nodes = Array.from(document.querySelectorAll('div, section, article'));
      for (const node of nodes) {
        const style = window.getComputedStyle(node);
        if (style.display === 'none' || style.visibility === 'hidden') continue;
        if (!/(auto|scroll)/.test(style.overflowY || '')) continue;
        if (node.scrollHeight <= node.clientHeight + 20) continue;

        node.scrollTop = node.scrollHeight;
      }
    });
  };

  const clickByPattern = async (pattern) => {
    return await page.evaluate((patternSource) => {
      const re = new RegExp(patternSource, 'i');

      const heading = Array.from(document.querySelectorAll('h1,h2,h3,h4,div,span'))
        .find((el) => (el.textContent || '').replace(/\s+/g, ' ').includes('Reviews for GS Construction'));
      const root = heading?.closest('section,div,article') || document.body;

      const candidates = Array.from(root.querySelectorAll('button, a, span, div'));
      let clicked = 0;

      for (const el of candidates) {
        const text = (el.textContent || '').replace(/\s+/g, ' ').trim();
        if (!text || !re.test(text)) continue;

        const target = el.closest('button, a') || el;
        if (!target) continue;

        const style = window.getComputedStyle(target);
        if (style.display === 'none' || style.visibility === 'hidden') continue;

        target.click();
        clicked++;
      }

      return clicked;
    }, pattern.source);
  };

  // Try hard to open the full review list first (e.g. "Show all 55 reviews").
  for (let i = 0; i < 12; i++) {
    await scrollReviewContainers();
    const clicked = await clickByPattern(/(?:show|load)\s*(?:all|more|next)?\s*\d*\s*reviews?/i);
    await sleep(clicked > 0 ? 2000 : 900);
    if (clicked === 0 && i > 3) break;
  }

  // Expand each review body by clicking all "Read More" triggers.
  for (let i = 0; i < maxScrolls; i++) {
    await scrollReviewContainers();
    const clicked = await clickByPattern(/read\s*more/i);
    await sleep(clicked > 0 ? 1200 : 700);
    if (clicked === 0 && i > 3) break;
  }
}

async function scrapeProfileReviews(page) {
  return await page.evaluate(() => {
    const clean = (v) => (v || '').replace(/\s+/g, ' ').trim();
    const preserveParagraphs = (v) => {
      const lines = (v || '')
        .replace(/\r/g, '')
        .split('\n')
        .map((line) => line.replace(/[ \t]+/g, ' ').trim());

      const compacted = [];
      for (const line of lines) {
        if (line === '') {
          if (compacted.length && compacted[compacted.length - 1] !== '') {
            compacted.push('');
          }
          continue;
        }

        compacted.push(line);
      }

      return compacted.join('\n').replace(/\n{3,}/g, '\n\n').trim();
    };

    const parseDate = (text) => {
      const re = /([A-Z][a-z]+ \d{1,2}, \d{4})/g;
      let earliest = null;
      let match;
      while ((match = re.exec(text)) !== null) {
        const d = new Date(match[1]);
        if (!earliest || d < earliest.date) {
          earliest = { raw: match[1], date: d };
        }
      }
      return earliest ? earliest.raw : null;
    };

    const parseRating = (text) => {
      const m = text.match(/Average rating:\s*([0-5](?:\.\d)?)\s*out of 5 stars/i);
      if (!m) return null;
      const n = Number(m[1]);
      return Number.isFinite(n) ? Math.round(n) : null;
    };

    const reviewsHeading = Array.from(document.querySelectorAll('h1,h2,h3,h4,div,span'))
      .find((el) => clean(el.textContent).match(/Reviews\s+for\s+GS\s+Construction/i));

    let root = document.body;
    if (reviewsHeading) {
      root = reviewsHeading.closest('section, div, article') || document.body;
    }

    const profileSelector = 'a[href*="/user/"], a[href*="/pro/"], a[href*="/professionals/"]';

    const isReviewNode = (el) => {
      const txt = clean(el.textContent);
      return txt.includes('Average rating:')
        && txt.length > 120
        && txt.length < 10000
        && !!el.querySelector(profileSelector);
    };

    const reviewNodes = Array.from(root.querySelectorAll('div, li, article, section'))
      .filter((n) => {
        if (!isReviewNode(n)) return false;

        // Keep the smallest matching node to avoid ancestor/descendant duplicates.
        return !Array.from(n.querySelectorAll('div, li, article, section')).some((child) => child !== n && isReviewNode(child));
      })
      .slice(0, 1200);

    const seen = new Set();
    const reviews = [];

    for (const node of reviewNodes) {
      const blockRaw = (node.innerText || node.textContent || '').replace(/\r/g, '').trim();
      const block = clean(blockRaw);
      if (!block.includes('Average rating:')) continue;

      const userLinks = Array.from(node.querySelectorAll(profileSelector));
      const userLink = userLinks.length ? userLinks[userLinks.length - 1] : null;
      const reviewerName = clean(userLink?.textContent || '');
      if (!reviewerName) continue;

      let reviewerProfileUrl = null;
      const reviewerHref = userLink?.getAttribute('href') || '';
      if (reviewerHref.startsWith('http')) reviewerProfileUrl = reviewerHref;
      else if (reviewerHref.startsWith('/')) reviewerProfileUrl = `https://www.houzz.com${reviewerHref}`;

      let reviewUrl = null;
      const anchors = Array.from(node.querySelectorAll('a[href*="/viewReview/"]'));
      if (anchors.length) {
        const href = anchors[0].getAttribute('href') || '';
        if (href.startsWith('http')) reviewUrl = href;
        else if (href.startsWith('/')) reviewUrl = `https://www.houzz.com${href}`;
      }

      let reviewText = blockRaw;
      reviewText = reviewText.replace(/^.*?Average rating:[^\n]*?stars\s*/is, '');
      reviewText = reviewText.replace(/\n?\s*Helpful.*$/is, '');
      reviewText = reviewText.replace(/\n?\s*Read More.*$/is, '');
      reviewText = reviewText.replace(/\n?\s*Read Less.*$/is, '');
      reviewText = preserveParagraphs(reviewText);

      // Drop obvious page chrome contamination.
      if (
        reviewText.includes('Kitchen & Bath Remodelers')
        || reviewText.includes('About Us')
        || reviewText.includes('Frequently Asked Questions')
      ) {
        continue;
      }

      const reviewDateRaw = parseDate(block);
      const rating = parseRating(block);

      if (!reviewText || reviewText.length < 20) continue;

      const key = `${reviewerName.toLowerCase()}|${(reviewDateRaw || '').toLowerCase()}|${reviewText.slice(0, 100).toLowerCase()}`;
      if (seen.has(key)) continue;
      seen.add(key);

      reviews.push({
        reviewer_name: reviewerName,
        reviewer_profile_url: reviewerProfileUrl,
        review_description: reviewText,
        review_date_raw: reviewDateRaw,
        star_rating: rating,
        url: reviewUrl,
      });
    }

    return reviews;
  });
}

async function main() {
  const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));
  const args = parseArgs(process.argv);

  const baseLaunchArgs = [
      '--no-sandbox',
      '--disable-setuid-sandbox',
      '--disable-dev-shm-usage',
  ];

  let proxyConfig = null;

  if (args.proxy) {
    try {
      const proxyUrl = new URL(args.proxy);
      proxyConfig = {
        host: `${proxyUrl.hostname}:${proxyUrl.port || 8080}`,
        username: decodeURIComponent(proxyUrl.username),
        password: decodeURIComponent(proxyUrl.password || ''),
      };
    } catch {
      // If not a URL, treat as host:port directly
      baseLaunchArgs.push(`--proxy-server=${args.proxy}`);
    }
  }

  const maxAttempts = proxyConfig ? 5 : 1;

  for (let attempt = 1; attempt <= maxAttempts; attempt++) {
    const launchArgs = [...baseLaunchArgs];
    let proxyAuth = null;

    if (proxyConfig) {
      launchArgs.push(`--proxy-server=${proxyConfig.host}`);

      // Append a random session ID so each attempt gets a different residential IP.
      const sessionId = Math.random().toString(36).slice(2, 10);
      const username = `${proxyConfig.username}-session-${sessionId}`;
      proxyAuth = { username, password: proxyConfig.password };

      if (attempt > 1) {
        console.error(`[proxy] Attempt ${attempt}/${maxAttempts} with session ${sessionId}`);
      }
    }

    const browser = await puppeteer.launch({
      headless: args.headless,
      args: launchArgs,
    });

    try {
      const page = await browser.newPage();

      if (proxyAuth) {
        await page.authenticate(proxyAuth);
      }

      await page.setViewport({ width: 1440, height: 2200 });
      await page.setUserAgent('Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/124.0.0.0 Safari/537.36');
      await page.setExtraHTTPHeaders({ 'Accept-Language': 'en-US,en;q=0.9' });

      const reviewsUrl = args.url.includes('#reviews') ? args.url : `${args.url}#reviews`;
      await page.goto(reviewsUrl, { waitUntil: 'networkidle2', timeout: args.timeoutMs });
      await sleep(2500);

      await autoExpandReviews(page, args.maxScrolls);

      const reviews = await scrapeProfileReviews(page);

      // Resolve missing viewReview URLs by visiting each reviewer's activity/reviews page.
      const unresolvedReviews = reviews.filter((r) => !r.url && r.reviewer_profile_url);
      if (unresolvedReviews.length > 0) {
        console.error(`[resolve] Resolving ${unresolvedReviews.length} missing viewReview URL(s)...`);
        for (const review of unresolvedReviews) {
          try {
            const slug = review.reviewer_profile_url.match(/\/user\/([^/?#]+)/i)?.[1];
            if (!slug) continue;
            const activityUrl = `https://www.houzz.com/activities/user/${slug}/reviews`;
            console.error(`[resolve] ${review.reviewer_name} -> ${activityUrl}`);
            await page.goto(activityUrl, { waitUntil: 'networkidle2', timeout: 30000 });
            await sleep(1500);
            const viewReviewUrl = await page.evaluate(() => {
              const links = Array.from(document.querySelectorAll('a[href*="/viewReview/"]'));
              const gsLink = links.find((a) => {
                const href = (a.getAttribute('href') || '').toLowerCase();
                return href.includes('gs-construction');
              });
              if (!gsLink) return null;
              const href = gsLink.getAttribute('href') || '';
              if (href.startsWith('http')) return href;
              if (href.startsWith('/')) return `https://www.houzz.com${href}`;
              return null;
            });
            if (viewReviewUrl) {
              review.url = viewReviewUrl;
              console.error(`[resolve] Found: ${viewReviewUrl}`);
            }
          } catch (err) {
            console.error(`[resolve] Failed for ${review.reviewer_name}: ${err.message}`);
          }
        }
      }

      // If proxy returned 0 reviews, the IP was likely blocked — retry.
      if (reviews.length === 0 && proxyConfig && attempt < maxAttempts) {
        console.error(`[proxy] Attempt ${attempt} returned 0 reviews, retrying...`);
        await browser.close();
        await sleep(2000);
        continue;
      }

      process.stdout.write(JSON.stringify({
        source_url: args.url,
        count: reviews.length,
        reviews,
      }));

      await browser.close();
      return;
    } catch (err) {
      await browser.close();

      if (proxyConfig && attempt < maxAttempts) {
        console.error(`[proxy] Attempt ${attempt} failed: ${err.message}, retrying...`);
        await sleep(2000);
        continue;
      }

      throw err;
    }
  }
}

main().catch((err) => {
  console.error(err?.stack || String(err));
  process.exit(1);
});
