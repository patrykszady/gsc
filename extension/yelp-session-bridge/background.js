/**
 * Yelp Session Bridge — service worker.
 *
 * biz.yelp.com is behind DataDome, which blocks scripted logins from the
 * server's datacentre IP. This extension sidesteps that entirely: the owner
 * logs in to Yelp in their own browser — a real person on a residential
 * connection, which DataDome has no quarrel with — and we hand that session
 * to the server, which injects it into its automation profile.
 *
 * Everything here runs in the SERVICE WORKER, deliberately:
 *   - chrome.cookies can read httpOnly cookies; page JS and content scripts
 *     cannot, and Yelp's session cookies are httpOnly. A bookmarklet is
 *     therefore impossible.
 *   - service-worker fetches to a host listed in host_permissions bypass CORS
 *     entirely (no preflight, no server CORS config). Content-script fetches
 *     do not.
 */

const DEFAULTS = {
  serverUrl: 'https://gs.construction',
  token: '',
  // 6h. Yelp sessions last far longer, so this is not about outrunning
  // expiry — it is so the server's copy is refreshed long before it goes
  // stale, and so a session that IS revoked gets replaced within a few hours.
  intervalMinutes: 360,
  enabled: true,
};

const ALARM = 'push-yelp-cookies';

async function settings() {
  return { ...DEFAULTS, ...(await chrome.storage.sync.get(Object.keys(DEFAULTS))) };
}

async function setStatus(status) {
  await chrome.storage.local.set({ lastStatus: { ...status, at: new Date().toISOString() } });
}

/**
 * Collect every yelp.com cookie, including httpOnly and partitioned ones.
 *
 * chrome.cookies.getAll({domain}) matches the domain AND its subdomains, so a
 * single call covers yelp.com, www.yelp.com and biz.yelp.com. It does NOT
 * return partitioned (CHIPS) cookies unless a partitionKey is supplied, so we
 * ask for those separately and merge.
 */
async function collectCookies() {
  const plain = await chrome.cookies.getAll({ domain: 'yelp.com' });

  let partitioned = [];
  try {
    partitioned = await chrome.cookies.getAll({
      domain: 'yelp.com',
      partitionKey: { topLevelSite: 'https://yelp.com' },
    });
  } catch {
    // partitionKey is Chrome 119+; older builds throw. Not fatal.
  }

  const seen = new Set();
  const all = [];
  for (const c of [...plain, ...partitioned]) {
    const key = `${c.name}|${c.domain}|${c.path}|${c.partitionKey?.topLevelSite || ''}`;
    if (seen.has(key)) continue;
    seen.add(key);
    all.push(c);
  }
  return all;
}

/**
 * Does this jar actually contain a login?
 *
 * `bse`/`bsd` are the biz-session cookies, `s` the consumer one. Checking here
 * means "you're not logged in" is reported instantly instead of the server
 * discovering it 90 seconds into a Chromium run.
 */
function looksAuthenticated(cookies) {
  const names = new Set(cookies.map((c) => c.name.toLowerCase()));
  return names.has('bse') || names.has('bsd') || names.has('s');
}

async function push({ interactive = false } = {}) {
  const cfg = await settings();

  if (!cfg.enabled) return { ok: false, error: 'Bridge is turned off.' };
  if (!cfg.token) return { ok: false, error: 'No token set — open the extension options.' };

  const cookies = await collectCookies();
  if (cookies.length === 0) {
    const r = { ok: false, error: 'No yelp.com cookies found. Log in to biz.yelp.com first.' };
    await setStatus(r);
    return r;
  }
  if (!looksAuthenticated(cookies)) {
    const r = { ok: false, error: 'Found Yelp cookies but no session — log in to biz.yelp.com first.' };
    await setStatus(r);
    return r;
  }

  // Sent raw: the server normalises (sameSite casing, host-only vs domain,
  // session-cookie expiry). Keeping that logic server-side means it can be
  // corrected without everyone reinstalling the extension.
  let res;
  try {
    res = await fetch(`${cfg.serverUrl.replace(/\/+$/, '')}/api/yelp/cookies`, {
      method: 'POST',
      headers: {
        'Content-Type': 'application/json',
        Authorization: `Bearer ${cfg.token}`,
      },
      body: JSON.stringify({ cookies }),
    });
  } catch (e) {
    const r = { ok: false, error: `Could not reach the server: ${e.message}` };
    await setStatus(r);
    return r;
  }

  let body = {};
  try {
    body = await res.json();
  } catch {
    /* non-JSON error page */
  }

  const result = res.ok
    ? { ok: true, sent: cookies.length, stored: body.stored ?? null, message: body.message || 'Session sent.' }
    : { ok: false, error: body.error || `Server returned ${res.status}.` };

  await setStatus(result);

  if (interactive) {
    chrome.notifications.create({
      type: 'basic',
      iconUrl: 'icon128.png',
      title: result.ok ? 'Yelp session sent' : 'Yelp session not sent',
      message: result.ok ? result.message : result.error,
    });
  }

  return result;
}

// Re-push whenever the biz session cookie itself changes — i.e. the moment a
// fresh login happens. This is what makes the bridge feel automatic: log in
// to Yelp, and the server has the new session seconds later.
chrome.cookies.onChanged.addListener(({ cookie, removed }) => {
  if (removed) return;
  if (!cookie.domain.includes('yelp.com')) return;
  if (!['bse', 'bsd', 's'].includes(cookie.name.toLowerCase())) return;
  // Debounce: a login writes several cookies at once.
  chrome.alarms.create('push-debounce', { delayInMinutes: 0.5 });
});

chrome.alarms.onAlarm.addListener((alarm) => {
  if (alarm.name === ALARM || alarm.name === 'push-debounce') push();
});

async function installAlarm() {
  const cfg = await settings();
  chrome.alarms.create(ALARM, {
    periodInMinutes: Math.max(0.5, Number(cfg.intervalMinutes) || DEFAULTS.intervalMinutes),
  });
}

chrome.runtime.onInstalled.addListener(installAlarm);
chrome.runtime.onStartup.addListener(installAlarm);
chrome.storage.onChanged.addListener((changes, area) => {
  if (area === 'sync' && changes.intervalMinutes) installAlarm();
});

/**
 * Self-pairing: the content script on the admin platforms page hands us the
 * server URL + token it fetched with the admin session. Store, then
 * immediately try a push — if the user is already logged in to Yelp, the
 * entire setup completes with zero further action.
 */
async function pair(msg) {
  const serverUrl = String(msg.serverUrl || '').replace(/\/+$/, '');
  const token = String(msg.token || '');
  if (!/^https?:\/\//.test(serverUrl) || !token) {
    return { ok: false, error: 'invalid pairing payload' };
  }

  const prev = await settings();
  const changed = prev.serverUrl !== serverUrl || prev.token !== token;

  await chrome.storage.sync.set({ serverUrl, token, enabled: true });
  await setStatus({ ok: true, message: 'Paired with ' + serverUrl });

  if (changed) push(); // fire-and-forget; status lands in storage

  return { ok: true, changed };
}

chrome.runtime.onMessage.addListener((msg, _sender, sendResponse) => {
  if (msg?.type === 'push-now') {
    push({ interactive: true }).then(sendResponse);
    return true; // async response
  }
  if (msg?.type === 'pair') {
    pair(msg).then(sendResponse);
    return true;
  }
  if (msg?.type === 'status') {
    chrome.storage.local.get('lastStatus').then((d) => sendResponse(d.lastStatus || null));
    return true;
  }
});
