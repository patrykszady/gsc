import puppeteer from 'puppeteer';
const browser = await puppeteer.launch({ headless: 'new', args: ['--no-sandbox'] });
for (const [w,label] of [[1100,'desktop'],[390,'mobile']]) {
  const page = await browser.newPage();
  await page.setViewport({ width: w, height: 800 });
  await page.goto('http://gsc.localhost:8003/compare/advance-design-studio', { waitUntil: 'domcontentloaded', timeout: 90000 });
  await new Promise(r => setTimeout(r, 2000));
  const o = await page.evaluate(() => {
    const partner = [...document.querySelectorAll('a')].find(a => a.textContent.includes('Tradespeople, designers'));
    const grid = [...document.querySelectorAll('section')].reverse().find(s => s.querySelector('a img') && s.getBoundingClientRect().bottom <= partner.getBoundingClientRect().top);
    const faq = [...document.querySelectorAll('h2')].find(h => h.textContent.includes('Frequently asked'))?.closest('div[class*="rounded"]');
    const p = partner.getBoundingClientRect();
    return { above: grid ? Math.round(p.top - grid.getBoundingClientRect().bottom) : null,
             below: faq ? Math.round(faq.getBoundingClientRect().top - p.bottom) : null };
  });
  console.log(`  ${label}: above ${o.above}px | below ${o.below}px`);
  await page.close();
}
await browser.close();
