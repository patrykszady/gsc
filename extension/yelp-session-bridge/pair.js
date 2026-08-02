/**
 * Self-pairing content script.
 *
 * Runs on the admin platforms page. Because that page sits behind the admin
 * login, being able to load it *is* the authorization — so we fetch the
 * pairing endpoint same-origin (the browser attaches the admin session
 * cookie), hand the returned server URL + token to the service worker, and
 * the extension is configured. The human copies nothing.
 *
 * Re-runs on Livewire SPA navigations (`livewire:navigated` fires on the
 * shared DOM even though this script lives in the isolated world).
 */
(() => {
  let lastPairedKey = null;

  const toast = (text, ok) => {
    const el = document.createElement('div');
    el.textContent = text;
    el.style.cssText = [
      'position:fixed', 'bottom:24px', 'right:24px', 'z-index:99999',
      'padding:10px 16px', 'border-radius:8px', 'font:600 13px system-ui,sans-serif',
      'color:#fff', `background:${ok ? '#059669' : '#dc2626'}`,
      'box-shadow:0 4px 12px rgba(0,0,0,.25)', 'transition:opacity .4s',
    ].join(';');
    document.body.appendChild(el);
    setTimeout(() => { el.style.opacity = '0'; setTimeout(() => el.remove(), 500); }, 3500);
  };

  const tryPair = async () => {
    const m = location.pathname.match(/^(\/admin\/[^/]+\/platforms)\/?$/);
    if (!m) return;

    let data;
    try {
      const res = await fetch(`${location.origin}${m[1]}/extension-pairing`, {
        credentials: 'same-origin',
        headers: { Accept: 'application/json' },
      });
      if (!res.ok) return; // not logged in / endpoint absent — stay silent
      data = await res.json();
    } catch {
      return;
    }
    if (!data?.ok || !data.token) return;

    const key = `${data.serverUrl}|${data.token}`;
    if (key === lastPairedKey) return;

    let result;
    try {
      result = await chrome.runtime.sendMessage({
        type: 'pair',
        serverUrl: data.serverUrl,
        token: data.token,
      });
    } catch {
      // Extension was reloaded and this orphaned script lost its runtime.
      return;
    }

    if (result?.ok) {
      lastPairedKey = key;
      // Only announce a real change — a toast on every admin visit is noise.
      if (result.changed) toast('✓ Yelp Session Bridge connected', true);
    } else if (result?.error) {
      toast(`Yelp bridge: ${result.error}`, false);
    }
  };

  tryPair();
  document.addEventListener('livewire:navigated', tryPair);
})();
