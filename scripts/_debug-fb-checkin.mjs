#!/usr/bin/env node
/**
 * Debug helper: open the FB composer Check-in flow, type a query, and dump
 * every GraphQL response payload that comes back so we can identify which
 * one carries the Place typeahead results.
 */
import puppeteer from 'puppeteer-extra';
import StealthPlugin from 'puppeteer-extra-plugin-stealth';
import fs from 'node:fs';
puppeteer.use(StealthPlugin());

const userDataDir = process.argv.find(a=>a.startsWith('--user-data-dir='))?.slice(16);
const query = process.argv.find(a=>a.startsWith('--query='))?.slice(8) || 'Palatine, Illinois';
if (!userDataDir) { console.error('--user-data-dir required'); process.exit(2); }

const browser = await puppeteer.launch({
  headless: 'new',
  userDataDir,
  defaultViewport: { width: 1366, height: 900 },
  args: ['--no-sandbox','--disable-setuid-sandbox','--disable-blink-features=AutomationControlled'],
});

const page = (await browser.pages())[0];
await page.setUserAgent('Mozilla/5.0 (Macintosh; Intel Mac OS X 10_15_7) AppleWebKit/537.36 (KHTML, like Gecko) Chrome/120.0.0.0 Safari/537.36');

const captured = [];
page.on('request', (req) => {
  const url = req.url();
  if (!url.includes('/graphql') && !url.includes('/api/graphql')) return;
  const post = req.postData() || '';
  if (/checkin_search|PlacesTypeahead|LocationTypeahead|place_search/i.test(post)) {
    captured.push({ kind: 'req', url, post: post.slice(0, 4000) });
  }
});
page.on('response', async (resp) => {
  const url = resp.url();
  if (!url.includes('/graphql') && !url.includes('/api/graphql')) return;
  try {
    const text = await resp.text();
    if (!text) return;
    if (/checkin_search_query|place_results/i.test(text)) {
      captured.push({ kind: 'resp', url, len: text.length, body: text.slice(0, 12000) });
    }
  } catch (_) {}
});

console.log('GET https://www.facebook.com/');
await page.goto('https://www.facebook.com/', { waitUntil: 'networkidle2', timeout: 60000 });
console.log('Page loaded:', page.url());

// Click the inline "What's on your mind" composer trigger to open the dialog.
console.log('Opening composer dialog...');
const triggered = await page.evaluate(() => {
  for (const el of Array.from(document.querySelectorAll('[role="button"], div'))) {
    const txt = (el.textContent || '').trim();
    if (txt.startsWith("What's on your mind")) {
      el.click();
      return txt;
    }
  }
  return null;
});
console.log('Trigger clicked:', triggered);
await new Promise(r => setTimeout(r, 4000));

await page.screenshot({ path: '/tmp/fb-composer.png', fullPage: false });
console.log('Screenshot → /tmp/fb-composer.png');

// Find Check in within the open dialog
const buttons = await page.$$eval('[role="button"], [role="dialog"] [role="button"]', els =>
  els.map(e => ({
    text: (e.textContent || '').trim().slice(0, 60),
    aria: e.getAttribute('aria-label') || '',
  })).filter(e => e.aria === 'Check in' || e.text === 'Check in')
);
console.log('Check-in candidates:', buttons);

const clicked = await page.evaluate(() => {
  // Find the Check-in button anywhere on the page (composer dialog isn't
  // always a literal [role="dialog"] in newer FB).
  for (const el of Array.from(document.querySelectorAll('[role="button"], [aria-label="Check in"]'))) {
    const txt = (el.textContent || '').trim();
    const aria = el.getAttribute('aria-label') || '';
    if (txt === 'Check in' || aria === 'Check in') {
      el.scrollIntoView();
      el.click();
      return true;
    }
  }
  return false;
});
console.log('Check-in clicked?', clicked);
await new Promise(r => setTimeout(r, 8000));
await page.screenshot({ path: '/tmp/fb-after-checkin.png', fullPage: false });

// Dump full dialog HTML for inspection
const dialogHTML = await page.evaluate(() => {
  const d = document.querySelector('[role="dialog"][aria-label="Search for location"]');
  return d ? d.outerHTML : null;
});
if (dialogHTML) {
  fs.writeFileSync('/tmp/fb-dialog.html', dialogHTML);
  console.log('Dialog HTML → /tmp/fb-dialog.html (', dialogHTML.length, 'bytes)');
}
console.log('After-check-in screenshot → /tmp/fb-after-checkin.png');

// Dump all inputs/textareas in dialog
const inputs = await page.evaluate(() => {
  const out = [];
  for (const el of Array.from(document.querySelectorAll('input, textarea, [role="textbox"], [contenteditable="true"]'))) {
    out.push({
      tag: el.tagName.toLowerCase(),
      type: el.getAttribute('type'),
      placeholder: el.getAttribute('placeholder') || el.getAttribute('aria-label') || '',
      role: el.getAttribute('role'),
    });
  }
  return out;
});
console.log('Inputs after check-in:', JSON.stringify(inputs, null, 2));

// Dump *all* elements that have a placeholder OR are likely search bars.
const searchish = await page.evaluate(() => {
  const out = [];
  const all = document.querySelectorAll('input, [role="combobox"], [role="searchbox"], [aria-label*="earch" i], [placeholder]');
  for (const el of all) {
    out.push({
      tag: el.tagName.toLowerCase(),
      role: el.getAttribute('role'),
      type: el.getAttribute('type'),
      aria: el.getAttribute('aria-label') || '',
      placeholder: el.getAttribute('placeholder') || '',
    });
  }
  return out;
});
console.log('Search-ish elements:', JSON.stringify(searchish, null, 2));

// Now explore inside the "Search for location" dialog specifically.
const placeDialog = await page.evaluate(() => {
  const dialog = document.querySelector('[role="dialog"][aria-label="Search for location"]');
  if (!dialog) return null;
  // Try to click any visible "Search" labeled button inside it.
  const elements = [];
  for (const el of dialog.querySelectorAll('*')) {
    const tag = el.tagName.toLowerCase();
    if (['input','div','span','button'].includes(tag)) {
      const aria = el.getAttribute('aria-label') || '';
      const role = el.getAttribute('role') || '';
      const ph = el.getAttribute('placeholder') || '';
      const ce = el.getAttribute('contenteditable') || '';
      const txt = (el.textContent || '').trim().slice(0, 40);
      if (aria || role === 'textbox' || role === 'combobox' || ph || ce === 'true' || (tag==='input' && el.type !== 'hidden')) {
        elements.push({ tag, role, aria, placeholder: ph, contenteditable: ce, text: txt });
      }
    }
  }
  return elements;
});
console.log('Inside place dialog:', JSON.stringify(placeDialog, null, 2));

if (clicked) {
  // Find the "Where are you?" input.
  const found = await page.evaluate(() => {
    const inputs = document.querySelectorAll('input[placeholder], input[aria-label]');
    for (const el of inputs) {
      const ph = (el.getAttribute('placeholder') || el.getAttribute('aria-label') || '').toLowerCase();
      if (ph.includes('where are you') || ph.includes('search for location')) {
        el.focus();
        return ph;
      }
    }
    return null;
  });
  console.log('Focused input:', found);
  await new Promise(r => setTimeout(r, 500));
  console.log('Typing query...');
  await page.keyboard.type(query, { delay: 150 });
  await new Promise(r => setTimeout(r, 8000));
  await page.screenshot({ path: '/tmp/fb-checkin-results.png', fullPage: false });
  console.log('Results screenshot → /tmp/fb-checkin-results.png');
}

console.log(`\nCaptured ${captured.length} graphql responses with place/location keywords`);
fs.writeFileSync('/tmp/fb-graphql.json', JSON.stringify(captured, null, 2));
console.log('Dumped → /tmp/fb-graphql.json');

await new Promise(r => setTimeout(r, 2000));
await browser.close();
