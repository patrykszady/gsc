#!/usr/bin/env node
import puppeteer from 'puppeteer-extra';
import StealthPlugin from 'puppeteer-extra-plugin-stealth';

puppeteer.use(StealthPlugin());

function parseArgs(argv) {
  const args = {
    url: 'https://www.yelp.com/biz/gs-construction-chicago-2',
    timeoutMs: 120000,
    maxPages: 10,
    headless: true,
    proxy: null,
    twocaptchaKey: null,
    userAgent: 'Mozilla/5.0 (X11; Linux x86_64) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/131.0.0.0 Safari/537.36',
  };

  for (const arg of argv.slice(2)) {
    if (arg.startsWith('--url=')) args.url = arg.slice('--url='.length);
    if (arg.startsWith('--timeout-ms=')) args.timeoutMs = Number(arg.slice('--timeout-ms='.length)) || args.timeoutMs;
    if (arg.startsWith('--max-pages=')) args.maxPages = Number(arg.slice('--max-pages='.length)) || args.maxPages;
    if (arg.startsWith('--proxy=')) args.proxy = arg.slice('--proxy='.length);
    if (arg.startsWith('--twocaptcha-key=')) args.twocaptchaKey = arg.slice('--twocaptcha-key='.length);
    if (arg.startsWith('--user-agent=')) args.userAgent = arg.slice('--user-agent='.length);
    if (arg === '--headed') args.headless = false;
  }

  return args;
}

function parseProxyUrl(proxyUrl) {
  if (!proxyUrl) return null;
  try {
    const url = new URL(proxyUrl);
    return {
      host: `${url.protocol}//${url.hostname}:${url.port}`,
      hostname: url.hostname,
      port: url.port,
      username: decodeURIComponent(url.username),
      password: decodeURIComponent(url.password),
    };
  } catch {
    return null;
  }
}

const sleep = (ms) => new Promise((resolve) => setTimeout(resolve, ms));

/**
 * Detect if the page is blocked by DataDome and extract the captcha iframe URL.
 */
async function detectDataDome(page) {
  const captchaUrl = await page.evaluate(() => {
    // Check for captcha-delivery.com iframe
    const iframes = Array.from(document.querySelectorAll('iframe'));
    for (const iframe of iframes) {
      const src = iframe.getAttribute('src') || '';
      if (src.includes('captcha-delivery.com') || src.includes('geo.captcha-delivery.com')) {
        return src;
      }
    }

    // Check page content for DataDome markers
    const html = document.documentElement.innerHTML || '';
    if (html.includes('captcha-delivery.com')) {
      const match = html.match(/src=["'](https?:\/\/[^"']*captcha-delivery\.com[^"']*)/);
      if (match) return match[1];
    }

    return null;
  });

  return captchaUrl;
}

/**
 * Use 2captcha DataDome solver API.
 * Returns a datadome cookie string on success, or null on failure.
 */
async function solveDataDome(captchaUrl, pageUrl, proxy, proxyType, userAgent, apiKey) {
  console.error('[datadome] Submitting DataDome challenge to 2captcha...');

  // Check that t=fe (not t=bv which means IP is banned)
  const tParam = new URL(captchaUrl).searchParams.get('t');
  if (tParam === 'bv') {
    console.error('[datadome] captcha_url has t=bv — IP is banned by DataDome. Change proxy.');
    return null;
  }

  // Submit captcha task
  const submitUrl = new URL('https://2captcha.com/in.php');
  const submitBody = {
    key: apiKey,
    method: 'datadome',
    captcha_url: captchaUrl,
    pageurl: pageUrl,
    userAgent: userAgent,
    proxy: proxy,
    proxytype: proxyType,
    json: 1,
  };

  const submitRes = await fetch('https://2captcha.com/in.php', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify(submitBody),
  });

  const submitData = await submitRes.json();
  if (submitData.status !== 1) {
    console.error(`[datadome] 2captcha submit error: ${submitData.request}`);
    return null;
  }

  const taskId = submitData.request;
  console.error(`[datadome] Task submitted: ${taskId}. Polling for result...`);

  // Poll for result (up to 180 seconds)
  for (let i = 0; i < 36; i++) {
    await sleep(5000);

    const resultRes = await fetch(
      `https://2captcha.com/res.php?key=${apiKey}&action=get&id=${taskId}&json=1`
    );
    const resultData = await resultRes.json();

    if (resultData.status === 1) {
      console.error('[datadome] DataDome solved successfully!');
      console.error(`[datadome] Raw cookie response: ${resultData.request.substring(0, 200)}`);
      return resultData.request; // Cookie string
    }

    if (resultData.request === 'CAPCHA_NOT_READY') {
      continue;
    }

    console.error(`[datadome] 2captcha error: ${resultData.request}`);
    return null;
  }

  console.error('[datadome] Timed out waiting for DataDome solution.');
  return null;
}

/**
 * Parse the datadome cookie value from the full cookie string returned by 2captcha.
 * Example: "datadome=abc123; Max-Age=31536000; Domain=.yelp.com; Path=/; Secure; SameSite=Lax"
 */
function parseDataDomeCookie(cookieStr) {
  const parts = cookieStr.split(';').map((p) => p.trim());
  const kvPart = parts.find((p) => p.startsWith('datadome='));
  if (!kvPart) return null;
  return kvPart.slice('datadome='.length);
}

/**
 * Scrape reviews from the current Yelp page.
 */
async function scrapeReviewsFromPage(page) {
  return await page.evaluate(() => {
    const clean = (v) => (v || '').replace(/\s+/g, ' ').trim();
    const reviews = [];

    // Yelp wraps each review in an <li> containing both a user_details link and a comment paragraph.
    const allLis = Array.from(document.querySelectorAll('ul li'));
    const reviewNodes = allLis.filter(
      (li) => li.querySelector('a[href*="/user_details"]') && li.querySelector('[class*="comment__"]'),
    );

    for (const node of reviewNodes) {
      // Reviewer name — find the user_details link that has actual text (not the avatar image link)
      let reviewerName = '';
      let nameEl = null;
      const userLinks = node.querySelectorAll('a[href*="/user_details"]');
      for (const link of userLinks) {
        const t = clean(link.textContent);
        if (t.length >= 2 && t.length < 80) {
          reviewerName = t;
          nameEl = link;
          break;
        }
      }
      if (!reviewerName) continue;

      // Star rating — div[role="img"][aria-label*="star rating"]
      let starRating = null;
      const ratingEl = node.querySelector('div[role="img"][aria-label*="star rating"]');
      if (ratingEl) {
        const m = (ratingEl.getAttribute('aria-label') || '').match(/(\d+)/);
        if (m) starRating = parseInt(m[1], 10);
      }

      // Review date — "Mon DD, YYYY" text inside a <span> sibling of the star block
      let reviewDate = null;
      const dateRe = /^[A-Z][a-z]{2}\s+\d{1,2},\s*\d{4}$/;
      for (const span of node.querySelectorAll('span')) {
        const t = clean(span.textContent);
        if (dateRe.test(t)) {
          reviewDate = t;
          break;
        }
      }

      // Review text — <span lang="en"> inside the comment paragraph
      let reviewText = '';
      const commentEl =
        node.querySelector('[class*="comment__"] span[lang]') ||
        node.querySelector('[class*="comment__"]');
      if (commentEl) reviewText = clean(commentEl.textContent);
      if (reviewText.length < 20) continue;

      // Review ID from reactions wrapper: data-testid="reactions-wrapper-{ID}"
      let reviewUrl = null;
      const reactionsEl = node.querySelector('[data-testid^="reactions-wrapper-"]');
      if (reactionsEl) {
        const tid = reactionsEl.getAttribute('data-testid') || '';
        const rid = tid.replace('reactions-wrapper-', '');
        if (rid) reviewUrl = `${window.location.origin}${window.location.pathname}?hrid=${rid}`;
      }

      // User profile URL
      let userProfileUrl = null;
      if (nameEl) {
        const href = nameEl.getAttribute('href') || '';
        userProfileUrl = href.startsWith('http') ? href : `${window.location.origin}${href}`;
      }

      reviews.push({
        reviewer_name: reviewerName,
        review_description: reviewText,
        star_rating: starRating,
        review_date_raw: reviewDate,
        url: reviewUrl,
        user_profile_url: userProfileUrl,
      });
    }

    // Deduplicate by reviewer name + text prefix
    const seen = new Set();
    return reviews.filter((r) => {
      const key = `${r.reviewer_name.toLowerCase()}|${r.review_description.substring(0, 80).toLowerCase()}`;
      if (seen.has(key)) return false;
      seen.add(key);
      return true;
    });
  });
}

/**
 * Get the URL for the next page of reviews, if any.
 */
async function getNextPageUrl(page) {
  return await page.evaluate(() => {
    // Look for "Next" pagination link
    const links = Array.from(document.querySelectorAll('a[href]'));
    for (const link of links) {
      const text = (link.textContent || '').trim();
      const ariaLabel = link.getAttribute('aria-label') || '';
      if (text === 'Next' || ariaLabel.toLowerCase().includes('next')) {
        const href = link.getAttribute('href') || '';
        if (href.startsWith('http')) return href;
        if (href.startsWith('/')) return `${window.location.origin}${href}`;
      }
    }
    return null;
  });
}

async function main() {
  const args = parseArgs(process.argv);
  const proxyConfig = parseProxyUrl(args.proxy);
  const maxAttempts = 5;

  if (proxyConfig && !args.twocaptchaKey) {
    console.error('[error] --twocaptcha-key is required for DataDome solving when using a proxy');
    process.exit(1);
  }

  for (let attempt = 1; attempt <= maxAttempts; attempt++) {
    const launchArgs = [
      '--no-sandbox',
      '--disable-setuid-sandbox',
      '--disable-blink-features=AutomationControlled',
    ];

    let proxyAuth = null;

    if (proxyConfig) {
      launchArgs.push(`--proxy-server=${proxyConfig.host}`);

      const sessionId = Math.random().toString(36).slice(2, 10);
      const username = `${proxyConfig.username}-session-${sessionId}`;
      proxyAuth = { username, password: proxyConfig.password };

      if (attempt > 1) {
        console.error(`[proxy] Attempt ${attempt}/${maxAttempts} with session ${sessionId}`);
      }
    }

    const browser = await puppeteer.launch({
      headless: args.headless ? 'new' : false,
      args: launchArgs,
    });

    try {
      const page = await browser.newPage();

      if (proxyAuth) {
        await page.authenticate(proxyAuth);
      }

      await page.setViewport({ width: 1366, height: 768 });
      await page.setUserAgent(args.userAgent);
      await page.setExtraHTTPHeaders({ 'Accept-Language': 'en-US,en;q=0.9' });

      console.error(`[navigate] Loading ${args.url}...`);
      await page.goto(args.url, { waitUntil: 'networkidle2', timeout: args.timeoutMs });
      await sleep(2000 + Math.random() * 2000);

      // Check for DataDome and solve if needed
      let dataDomeCaptchaUrl = await detectDataDome(page);
      if (dataDomeCaptchaUrl) {
        console.error('[datadome] DataDome captcha detected.');

        // Check if this is a "soft block" (page content loaded behind captcha overlay)
        const softBlockCheck = await page.evaluate(() => document.documentElement.innerHTML.length);
        if (softBlockCheck > 50000) {
          console.error(`[datadome] Soft block detected (${softBlockCheck} bytes). Page content is available, proceeding.`);
        } else if (!proxyConfig) {
          // No proxy = can't solve DataDome via 2captcha (requires proxy). Retry and hope for soft block.
          console.error('[datadome] Hard block and no proxy available. Will retry...');
          if (attempt < maxAttempts) {
            await browser.close();
            await sleep(3000 + Math.random() * 5000);
            continue;
          }
        } else {
          console.error('[datadome] Attempting to solve with 2captcha...');

          // Extract actual page URL from the captcha URL referer, or fall back to current URL
          let actualPageUrl = await page.url();
          try {
            const captchaParams = new URL(dataDomeCaptchaUrl).searchParams;
            const referer = captchaParams.get('referer');
            if (referer) actualPageUrl = referer;
          } catch {}
          console.error(`[datadome] Captcha URL: ${dataDomeCaptchaUrl.substring(0, 120)}...`);
          console.error(`[datadome] Page URL for solver: ${actualPageUrl}`);

          // Build the proxy string for 2captcha: login:password@host:port
          let proxyStr = '';
          if (proxyConfig) {
            proxyStr = `${proxyAuth.username}:${proxyAuth.password}@${proxyConfig.hostname}:${proxyConfig.port}`;
          }

          const cookieStr = await solveDataDome(
            dataDomeCaptchaUrl,
            actualPageUrl,
            proxyStr,
            'http',
            args.userAgent,
            args.twocaptchaKey
          );

          if (cookieStr) {
            const cookieValue = parseDataDomeCookie(cookieStr);
            if (cookieValue) {
              console.error(`[datadome] Setting datadome cookie (${cookieValue.length} chars) and reloading...`);

              const client = await page.createCDPSession();
              await client.send('Network.deleteCookies', { name: 'datadome', domain: '.yelp.com' });
              await client.detach();

              await page.setCookie({
                name: 'datadome',
                value: cookieValue,
                domain: '.yelp.com',
                path: '/',
                secure: true,
                sameSite: 'Lax',
              });

              await page.evaluate((val) => {
                document.cookie = `datadome=${val}; domain=.yelp.com; path=/; secure; SameSite=Lax`;
              }, cookieValue);

              const cookies = await page.cookies('https://www.yelp.com');
              const ddCookie = cookies.find((c) => c.name === 'datadome');
              console.error(`[datadome] Cookie set: ${ddCookie ? 'yes (' + ddCookie.value.length + ' chars)' : 'NO'}`);

              await page.setRequestInterception(true);
              page.on('request', (req) => {
                const reqUrl = req.url();
                if (
                  reqUrl.includes('captcha-delivery.com') ||
                  reqUrl.includes('datadome.co') ||
                  reqUrl.includes('/tags.js')
                ) {
                  req.abort();
                } else {
                  req.continue();
                }
              });

              await page.reload({ waitUntil: 'networkidle2', timeout: args.timeoutMs });
              await sleep(2000 + Math.random() * 2000);
              await page.setRequestInterception(false);

              const reloadTitle = await page.title();
              const reloadHtmlLen = await page.evaluate(() => document.documentElement.innerHTML.length);
              console.error(`[datadome] After reload: title="${reloadTitle}", HTML=${reloadHtmlLen}`);

              if (reloadHtmlLen > 50000 && !reloadTitle.match(/^yelp\.com$/i)) {
                console.error('[datadome] DataDome bypassed successfully!');
              } else {
                console.error('[datadome] Still blocked after setting cookie.');
                if (attempt < maxAttempts) {
                  await browser.close();
                  await sleep(3000 + Math.random() * 5000);
                  continue;
                }
              }
            }
          } else {
            console.error('[datadome] Failed to solve DataDome captcha.');
            if (attempt < maxAttempts) {
              await browser.close();
              await sleep(3000 + Math.random() * 5000);
              continue;
            }
          }
        }
      }

      // Verify we have actual Yelp content
      const pageTitle = await page.title();
      const pageHtmlLength = await page.evaluate(() => document.documentElement.innerHTML.length);
      console.error(`[page] Title: "${pageTitle}", HTML length: ${pageHtmlLength}`);

      if (pageHtmlLength < 5000) {
        console.error('[page] Page too small — likely still blocked.');
        if (attempt < maxAttempts) {
          await browser.close();
          await sleep(3000 + Math.random() * 5000);
          continue;
        }
      }

      // Scrape reviews across pages
      const allReviews = [];
      let currentUrl = args.url;

      for (let pageNum = 1; pageNum <= args.maxPages; pageNum++) {
        console.error(`[scrape] Scraping page ${pageNum}...`);

        const reviews = await scrapeReviewsFromPage(page);
        console.error(`[scrape] Found ${reviews.length} review(s) on page ${pageNum}.`);
        allReviews.push(...reviews);

        if (pageNum >= args.maxPages) break;

        const nextUrl = await getNextPageUrl(page);
        if (!nextUrl) {
          console.error('[scrape] No next page found. Done.');
          break;
        }

        console.error(`[navigate] Loading next page: ${nextUrl}`);
        await page.goto(nextUrl, { waitUntil: 'networkidle2', timeout: args.timeoutMs });
        await sleep(2000 + Math.random() * 2000);

        // Check DataDome again on pagination
        const ddCheck = await detectDataDome(page);
        if (ddCheck) {
          console.error('[datadome] DataDome appeared on pagination. Stopping.');
          break;
        }
      }

      // Deduplicate across pages by reviewer_name + text prefix
      const seen = new Set();
      const dedupedReviews = allReviews.filter((r) => {
        const key = `${r.reviewer_name.toLowerCase()}|${r.review_description.substring(0, 80).toLowerCase()}`;
        if (seen.has(key)) return false;
        seen.add(key);
        return true;
      });

      console.error(`[result] Total reviews: ${dedupedReviews.length}`);

      // Debug dump if 0 reviews found
      if (dedupedReviews.length === 0) {
        const fs = await import('fs');
        const html = await page.content();
        const dumpPath = '/tmp/yelp-debug-' + Date.now() + '.html';
        fs.writeFileSync(dumpPath, html);
        const screenshotPath = dumpPath.replace('.html', '.png');
        await page.screenshot({ path: screenshotPath, fullPage: true }).catch(() => {});
        console.error(`[debug] 0 reviews found. Page dumped to ${dumpPath} (${html.length} bytes)`);
      }

      process.stdout.write(JSON.stringify({
        source_url: args.url,
        count: dedupedReviews.length,
        reviews: dedupedReviews,
      }));

      await browser.close();
      return;
    } catch (err) {
      await browser.close();

      if (proxyConfig && attempt < maxAttempts) {
        console.error(`[proxy] Attempt ${attempt} failed: ${err.message}, retrying...`);
        await sleep(3000 + Math.random() * 5000);
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
