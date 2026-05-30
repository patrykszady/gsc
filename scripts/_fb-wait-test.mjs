import puppeteer from 'puppeteer-extra';
import StealthPlugin from 'puppeteer-extra-plugin-stealth';
puppeteer.use(StealthPlugin());
const browser = await puppeteer.launch({
  headless: 'new',
  userDataDir: '/home/patryk/web/gsc/storage/app/facebook-puppeteer',
  defaultViewport: { width: 1366, height: 900 },
  args: ['--no-sandbox','--disable-setuid-sandbox','--disable-blink-features=AutomationControlled'],
});
const page = (await browser.pages())[0];
await page.setUserAgent('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 Chrome/120.0.0.0 Safari/537.36');

// Capture all graphql request URLs/postData to see what dialog asks for.
page.on('request', (req) => {
  const u = req.url();
  if (!/graphql/.test(u)) return;
  const post = req.postData() || '';
  const m = post.match(/(?:fb_api_req_friendly_name|doc_id)=([^&]{1,80})/g);
  if (m) console.log('REQ', m.join(' '));
});
page.on('response', async (resp) => {
  const u = resp.url();
  if (!/graphql/.test(u)) return;
  try {
    const t = await resp.text();
    const fn = (resp.request().postData() || '').match(/fb_api_req_friendly_name=([^&]+)/);
    if (fn && /checkin|location|place|composer/i.test(decodeURIComponent(fn[1]))) {
      console.log('RESP', decodeURIComponent(fn[1]), 'len=', t.length, t.includes('place_results') ? 'HAS_PLACE_RESULTS' : '');
    }
  } catch (_) {}
});

await page.goto('https://www.facebook.com/', { waitUntil: 'networkidle2' });
await page.evaluate(() => {
  for (const el of document.querySelectorAll('[role="button"], div')) {
    if ((el.textContent||'').trim().startsWith("What's on your mind")) { el.click(); return; }
  }
});
await new Promise(r => setTimeout(r, 4000));
await page.evaluate(() => {
  for (const el of document.querySelectorAll('[aria-label="Check in"], [role="button"]')) {
    const t=(el.textContent||'').trim(); const a=el.getAttribute('aria-label')||'';
    if (t==='Check in'||a==='Check in') { el.click(); return; }
  }
});
console.log('Clicked check-in. Waiting 15s and watching...');
await new Promise(r => setTimeout(r, 15000));

const finalState = await page.evaluate(() => {
  const dialogs = Array.from(document.querySelectorAll('[role="dialog"]'));
  return dialogs.map(d => ({
    aria: d.getAttribute('aria-label'),
    inputs: Array.from(d.querySelectorAll('input')).map(i => ({type:i.type,ph:i.placeholder||i.getAttribute('aria-label')||''})),
    hasSpinner: !!d.querySelector('[role="progressbar"], svg circle'),
    text: (d.textContent||'').slice(0,200),
  }));
});
console.log('Final dialogs:', JSON.stringify(finalState, null, 2));
await page.screenshot({ path: '/tmp/fb-wait-final.png' });
await browser.close();
