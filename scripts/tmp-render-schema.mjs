import puppeteer from 'puppeteer-extra';
const browser = await puppeteer.launch({ headless: 'new', args: ['--no-sandbox'] });
const page = await browser.newPage();
await page.setUserAgent('Mozilla/5.0 (compatible; Googlebot/2.1; +http://www.google.com/bot.html) Chrome/126.0.0.0');
await page.goto(process.argv[2], { waitUntil: 'networkidle2', timeout: 60000 });
await new Promise(r => setTimeout(r, 5000));
const schemas = await page.evaluate(() =>
  [...document.querySelectorAll('script[type="application/ld+json"]')].map(s => s.textContent)
);
const micro = await page.evaluate(() => document.querySelectorAll('[itemprop="aggregateRating"],[itemprop="ratingValue"]').length);
await browser.close();
console.log('blocks after render:', schemas.length, '| microdata rating nodes:', micro);
for (const s of schemas) {
  try {
    const d = JSON.parse(s);
    const arr = d['@graph'] ? d['@graph'] : (Array.isArray(d) ? d : [d]);
    for (const o of arr) {
      console.log('TYPE:', JSON.stringify(o['@type']), o.name ? '| ' + String(o.name).slice(0, 60) : '');
      if (o.aggregateRating) console.log('   aggregateRating:', JSON.stringify(o.aggregateRating));
    }
  } catch { console.log('unparseable block'); }
}
