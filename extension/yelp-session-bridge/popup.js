const $ = (id) => document.getElementById(id);

function render(s) {
  const el = $('status');
  if (!s) {
    el.className = 'status idle';
    el.textContent = 'Not sent yet. Log in to biz.yelp.com, then click above.';
    return;
  }
  const when = s.at ? new Date(s.at).toLocaleString() : '';
  el.className = 'status ' + (s.ok ? 'ok' : 'bad');
  let text = s.ok ? `Sent ${s.stored ?? ''} cookies. ${s.message || ''}` : s.error;
  // Token problems fix themselves by re-pairing — tell the user the one
  // action that resolves it instead of showing a dead end.
  if (!s.ok && /unauthorized|token/i.test(s.error || '')) {
    text += '\nFix: open Admin → Platforms once — the extension re-pairs itself.';
  }
  el.textContent = text + (when ? `\n${when}` : '');
  el.style.whiteSpace = 'pre-line';
}

$('send').addEventListener('click', async () => {
  const btn = $('send');
  btn.disabled = true;
  btn.textContent = 'Sending…';
  const res = await chrome.runtime.sendMessage({ type: 'push-now' });
  render({ ...res, at: new Date().toISOString() });
  btn.disabled = false;
  btn.textContent = 'Send session now';
});

$('opts').addEventListener('click', (e) => {
  e.preventDefault();
  chrome.runtime.openOptionsPage();
});

chrome.runtime.sendMessage({ type: 'status' }).then(render);
