const FIELDS = ['serverUrl', 'token', 'intervalMinutes', 'enabled'];
const DEFAULTS = {
  serverUrl: 'https://gs.construction',
  token: '',
  intervalMinutes: 360,
  enabled: true,
};

const el = (id) => document.getElementById(id);

chrome.storage.sync.get(FIELDS).then((stored) => {
  const cfg = { ...DEFAULTS, ...stored };
  for (const f of FIELDS) {
    if (f === 'enabled') el(f).checked = !!cfg[f];
    else el(f).value = cfg[f];
  }
});

el('save').addEventListener('click', async () => {
  const values = {
    serverUrl: el('serverUrl').value.trim() || DEFAULTS.serverUrl,
    token: el('token').value.trim(),
    intervalMinutes: Math.max(30, Number(el('intervalMinutes').value) || DEFAULTS.intervalMinutes),
    enabled: el('enabled').checked,
  };
  await chrome.storage.sync.set(values);
  el('saved').textContent = 'Saved';
  setTimeout(() => (el('saved').textContent = ''), 2000);
});
