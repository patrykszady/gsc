/**
 * Helpers every citation adapter gets: state/log/screenshots, navigation,
 * the form-field classifier that prefills a listing form from the payload,
 * photo download + upload, and the human-step wait (resume.flag).
 */

import fs from 'node:fs';
import path from 'node:path';

const FIELD_PATTERNS = [
  // order matters: specific before generic
  ['first_name', /first ?name|fname|given ?name/i],
  ['last_name', /last ?name|lname|surname|family ?name/i],
  ['email', /e-?mail/i],
  ['website', /web ?site|^url$|homepage|site ?url|website ?url/i],
  ['phone', /phone|tel(ephone)?|mobile|cell/i],
  ['zip', /zip|postal/i],
  ['city', /\bcity\b|town|locality/i],
  ['state', /\bstate\b|province|region/i],
  ['country', /country/i],
  ['street', /street|address ?(line)? ?1?$|address1|addr1|^address$|business address|street address/i],
  ['founded', /founded|established|year (in )?business|start(ed)? year/i],
  ['description', /description|about|bio|overview|summary|details|tell us about/i],
  ['category', /category|categories|industry|business type|type of business|trade|profession/i],
  ['business_name', /business ?name|company ?name|company|organization|listing ?name|trade ?name|business$|^name$|full ?name|contact ?name|your ?name/i],
  ['password_confirm', /confirm|repeat|again|re-?enter|verify password/i],
  ['password', /pass(word)?/i],
];

function randomPassword() {
  const chars = 'ABCDEFGHJKLMNPQRSTUVWXYZabcdefghjkmnpqrstuvwxyz23456789';
  let out = '';
  for (let i = 0; i < 14; i++) out += chars[Math.floor(Math.random() * chars.length)];
  return out + '!7';
}

export function makeContext({ browser, page, payload, args, deadline }) {
  const listing = payload.listing;
  const directory = payload.directory;
  const hints = directory.hints || {};
  const stateFile = args.state;
  const dir = args.dir;
  const state = {
    slug: args.slug, phase: 'starting', step: null, needs_human: false, reason: null, listing_url: null,
    error: null, done: false, outcome: null, note: null, photos_uploaded: 0, shots: [], log: [],
    account: { email: listing.email, password: null }, started_at: new Date().toISOString(), updated_at: null,
  };

  const write = () => {
    state.updated_at = new Date().toISOString();
    const tmp = `${stateFile}.tmp`;
    fs.writeFileSync(tmp, JSON.stringify(state));
    fs.renameSync(tmp, stateFile);
  };
  const setState = (patch) => { Object.assign(state, patch); write(); };
  const log = (msg, step) => {
    const line = { at: new Date().toISOString().slice(0, 19).replace('T', ' '), msg: String(msg).slice(0, 400), step: step || state.phase };
    state.log.push(line);
    if (state.log.length > 200) state.log.shift();
    process.stdout.write(`[citations:${args.slug}] ${line.msg}\n`);
    write();
  };
  const shot = async (label) => {
    const file = path.join(dir, 'shots', `${String(state.shots.length + 1).padStart(2, '0')}-${label.replace(/[^a-z0-9]+/gi, '-').toLowerCase()}.png`);
    try {
      await page.screenshot({ path: file, fullPage: false });
      state.shots.push({ file, label, at: new Date().toISOString() });
      write();
    } catch (e) {
      log(`screenshot failed: ${e.message}`);
    }
    return file;
  };
  const sleep = (ms) => new Promise((r) => setTimeout(r, ms));

  const goto = async (url) => {
    setState({ phase: 'navigating', step: url });
    log(`open ${url}`);
    try {
      const resp = await page.goto(url, { waitUntil: 'domcontentloaded' });
      await sleep(1500);
      return resp ? resp.status() : 0;
    } catch (e) {
      log(`navigation failed: ${e.message}`);
      return 0;
    }
  };

  /** Click the first link that reads like "add your business" / sign up. Returns true when navigation happened. */
  const clickSignupLink = async () => {
    const before = page.url();
    const clicked = await page.evaluate(() => {
      const want = /add (your|a|my) (business|listing|company)|list (your|my) (business|company)|get listed|claim (your|this|my)|add (business|company|listing)|sign ?up|register|join (now|free|as a pro)|for business|become a pro|create (a )?(free )?(listing|account)/i;
      const links = Array.from(document.querySelectorAll('a[href], button'));
      const scored = links.map((el) => {
        const text = ((el.innerText || el.textContent || '') + ' ' + (el.getAttribute('aria-label') || '') + ' ' + (el.getAttribute('href') || '')).replace(/\s+/g, ' ').trim();
        let score = 0;
        if (/add (your|a|my) (business|listing|company)|list (your|my) (business|company)|get listed|add (business|company|listing)/i.test(text)) score = 3;
        else if (/claim|for business|become a pro/i.test(text)) score = 2;
        else if (want.test(text)) score = 1;
        return { el, score };
      }).filter((x) => x.score > 0).sort((a, b) => b.score - a.score);
      if (!scored.length) return null;
      const el = scored[0].el;
      const label = (el.innerText || el.textContent || '').trim().slice(0, 60);
      el.click();
      return label;
    });
    if (clicked === null) return false;
    log(`clicked "${clicked}"`);
    try { await page.waitForNavigation({ waitUntil: 'domcontentloaded', timeout: 10000 }); } catch {}
    await sleep(1200);
    return page.url() !== before;
  };

  /** Describe every visible form control on the page for classification. */
  const scanFields = async () => page.evaluate(() => {
    const visible = (el) => {
      const r = el.getBoundingClientRect();
      const cs = getComputedStyle(el);
      return r.width > 0 && r.height > 0 && cs.visibility !== 'hidden' && cs.display !== 'none';
    };
    const labelFor = (el) => {
      let t = '';
      if (el.id) { const l = document.querySelector(`label[for="${CSS.escape(el.id)}"]`); if (l) t += ' ' + l.innerText; }
      const wrap = el.closest('label'); if (wrap) t += ' ' + wrap.innerText;
      const prev = el.previousElementSibling; if (prev && /label|span|div|p/i.test(prev.tagName) && prev.innerText && prev.innerText.length < 80) t += ' ' + prev.innerText;
      const parentLabel = el.parentElement?.querySelector?.('label'); if (parentLabel) t += ' ' + parentLabel.innerText;
      return t;
    };
    const controls = Array.from(document.querySelectorAll('input, textarea, select'));
    return controls.map((el, i) => {
      const type = (el.getAttribute('type') || (el.tagName === 'TEXTAREA' ? 'textarea' : el.tagName === 'SELECT' ? 'select' : 'text')).toLowerCase();
      if (['hidden', 'submit', 'button', 'image', 'reset', 'checkbox', 'radio', 'search'].includes(type)) return null;
      if (!visible(el)) return null;
      el.setAttribute('data-cit-idx', String(i));
      const desc = [el.name, el.id, el.placeholder, el.getAttribute('aria-label'), el.getAttribute('autocomplete'), labelFor(el)].filter(Boolean).join(' | ');
      const options = el.tagName === 'SELECT' ? Array.from(el.options).map((o) => ({ value: o.value, text: o.text })) : null;
      return { idx: i, tag: el.tagName.toLowerCase(), type, desc: desc.replace(/\s+/g, ' ').trim().slice(0, 300), options, value: el.value || '' };
    }).filter(Boolean);
  });

  const classify = (f) => {
    if (f.type === 'email') return 'email';
    if (f.type === 'tel') return 'phone';
    if (f.type === 'url') return 'website';
    if (f.type === 'password') return /confirm|repeat|again|re-?enter|verify/i.test(f.desc) ? 'password_confirm' : 'password';
    if (f.type === 'file') return 'file';
    const desc = f.desc;
    if (/e-?mail/i.test(desc) && /address/i.test(desc)) return 'email';
    for (const [kind, re] of FIELD_PATTERNS) {
      if (re.test(desc)) {
        if (kind === 'description' && f.tag !== 'textarea' && !/description|about/i.test(desc)) continue;
        return kind;
      }
    }
    if (f.tag === 'textarea') return 'description';
    return null;
  };

  const valueFor = (kind, field) => {
    const a = listing.address || {};
    const c = listing.contact || {};
    switch (kind) {
      case 'business_name': return listing.name;
      case 'first_name': return c.first_name;
      case 'last_name': return c.last_name;
      case 'email': return listing.email;
      case 'website': return listing.website;
      case 'phone': return field && /\(|-|\./.test(field.value) ? listing.phone : listing.phone;
      case 'street': return a.street;
      case 'city': return a.city;
      case 'zip': return a.zip;
      case 'founded': return String(listing.founded || '');
      case 'description': return field && field.tag !== 'textarea' ? listing.description.short : listing.description.medium;
      case 'category': return (listing.categories || [])[0] || 'Kitchen remodeler';
      case 'password':
      case 'password_confirm':
        if (!state.account.password) state.account.password = randomPassword();
        return state.account.password;
      default: return null;
    }
  };

  const chooseOption = (options, kind) => {
    const a = listing.address || {};
    const tests = {
      state: [new RegExp(`^${a.state}$`, 'i'), /^illinois$/i, /illinois/i],
      country: [/^(us|usa)$/i, /^united states/i, /united states/i],
      category: [/kitchen.*remodel|remodel.*kitchen/i, /bath.*remodel/i, /remodel/i, /general contractor/i, /contractor/i, /construction/i, /home improvement/i],
      founded: [new RegExp(`^${listing.founded}$`)],
    }[kind] || [];
    for (const re of tests) {
      const hit = options.find((o) => re.test(o.text.trim()) || re.test(o.value.trim()));
      if (hit) return hit.value;
    }
    return null;
  };

  const setControl = async (idx, value) => {
    const handle = await page.$(`[data-cit-idx="${idx}"]`);
    if (!handle) return false;
    try {
      await handle.click({ clickCount: 3 });
      await page.keyboard.press('Backspace');
      await handle.type(String(value), { delay: 15 });
      return true;
    } catch (e) {
      try { await page.evaluate((i, v) => { const el = document.querySelector(`[data-cit-idx="${i}"]`); if (el) { el.value = v; el.dispatchEvent(new Event('input', { bubbles: true })); el.dispatchEvent(new Event('change', { bubbles: true })); } }, idx, String(value)); return true; } catch { return false; }
    }
  };

  /** Fill everything recognisable on the current page. Returns {filled, kinds}. */
  const prefill = async () => {
    setState({ phase: 'prefilling', step: page.url() });
    const fields = await scanFields();
    const overrides = hints.fields || {};
    let filled = 0;
    const kinds = [];
    const used = new Set();
    for (const [kind, selector] of Object.entries(overrides)) {
      const value = valueFor(kind);
      if (value == null) continue;
      try {
        const el = await page.$(selector);
        if (el) { await el.click({ clickCount: 3 }); await el.type(String(value), { delay: 15 }); filled++; kinds.push(kind); used.add(kind); }
      } catch (e) { log(`override ${kind} failed: ${e.message}`); }
    }
    for (const f of fields) {
      const kind = classify(f);
      if (!kind || kind === 'file' || (used.has(kind) && kind !== 'password_confirm')) continue;
      if (f.tag === 'select') {
        const opt = chooseOption(f.options || [], kind);
        if (opt !== null) {
          try { await page.select(`[data-cit-idx="${f.idx}"]`, opt); filled++; kinds.push(kind); used.add(kind); } catch {}
        }
        continue;
      }
      if (f.value && f.value.trim() !== '' && kind !== 'password' && kind !== 'password_confirm') continue; // respect prefilled values
      const value = valueFor(kind, f);
      if (value == null || value === '') continue;
      if (await setControl(f.idx, value)) { filled++; kinds.push(kind); if (kind !== 'password_confirm') used.add(kind); }
    }
    log(`prefilled ${filled} field(s): ${[...new Set(kinds)].join(', ') || 'none'}`);
    return { filled, kinds };
  };

  /** Download the first n listing photos (once) and return local paths. */
  const photoFiles = async (n) => {
    const photos = (listing.photos || []).slice(0, n);
    const out = [];
    for (let i = 0; i < photos.length; i++) {
      const p = photos[i];
      const ext = (p.url.split('?')[0].match(/\.(jpe?g|png|webp)$/i) || [null, 'jpg'])[1];
      const file = path.join(dir, 'photos', `${String(i + 1).padStart(2, '0')}.${ext}`);
      if (!fs.existsSync(file)) {
        try {
          const res = await fetch(p.url);
          if (!res.ok) continue;
          fs.writeFileSync(file, Buffer.from(await res.arrayBuffer()));
        } catch (e) { log(`photo download failed: ${p.url} (${e.message})`); continue; }
      }
      out.push({ file, caption: p.caption });
    }
    return out;
  };

  /** Render the SVG logo to a PNG once (most upload fields reject SVG). */
  const logoFile = async () => {
    const file = path.join(path.dirname(dir), 'logo.png');
    if (fs.existsSync(file)) return file;
    try {
      const p = await browser.newPage();
      await p.setViewport({ width: 800, height: 800 });
      await p.setContent(`<html><body style="margin:0;background:#fff;display:flex;align-items:center;justify-content:center;width:800px;height:800px"><img src="${listing.logo.svg}" style="max-width:720px;max-height:720px"></body></html>`);
      await sleep(800);
      await p.screenshot({ path: file, clip: { x: 0, y: 0, width: 800, height: 800 } });
      await p.close();
      return file;
    } catch (e) { log(`logo render failed: ${e.message}`); return null; }
  };

  /** Upload photos into the first visible-or-hidden file input on the page. Returns how many were sent. */
  const uploadPhotosIfPossible = async (max) => {
    const limit = Math.min(max || 20, (listing.photos || []).length);
    const inputs = await page.$$('input[type="file"]');
    if (!inputs.length || limit <= 0) return 0;
    const input = inputs[0];
    const multiple = await input.evaluate((el) => el.hasAttribute('multiple'));
    const accept = (await input.evaluate((el) => el.getAttribute('accept') || '')).toLowerCase();
    if (accept && !/image|jpe?g|png|\*/.test(accept)) return 0;
    const files = await photoFiles(multiple ? limit : 1);
    if (!files.length) return 0;
    setState({ phase: 'uploading', step: `${files.length} photo(s)` });
    try {
      await input.uploadFile(...files.map((f) => f.file));
      await sleep(4000);
      state.photos_uploaded += files.length;
      write();
      log(`uploaded ${files.length} photo(s)`);
      return files.length;
    } catch (e) {
      log(`upload failed: ${e.message}`);
      return 0;
    }
  };

  /** Pause for a human; resolves when resume.flag appears (true) or the deadline passes (false). */
  const waitHuman = async (reason) => {
    const flag = path.join(dir, 'resume.flag');
    try { fs.unlinkSync(flag); } catch {}
    setState({ phase: 'waiting_human', needs_human: true, reason, step: page.url() });
    log(`waiting for a human: ${reason}`);
    while (Date.now() < deadline) {
      if (fs.existsSync(flag)) {
        try { fs.unlinkSync(flag); } catch {}
        setState({ needs_human: false, reason: null, phase: 'resumed', step: page.url() });
        log('resumed');
        await sleep(500);
        return true;
      }
      await sleep(2000);
    }
    return false;
  };

  const finish = ({ listing_url = null, outcome = 'done', note = null } = {}) => {
    setState({ phase: 'done', done: true, needs_human: false, reason: null, listing_url, outcome, note });
    log(`finished: ${outcome}${listing_url ? ' ' + listing_url : ''}${note ? ' — ' + note : ''}`);
  };

  return { browser, page, payload, listing, directory, hints, args, state, setState, log, shot, sleep, goto, clickSignupLink, scanFields, classify, prefill, photoFiles, logoFile, uploadPhotosIfPossible, waitHuman, finish };
}
