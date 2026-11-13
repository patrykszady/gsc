// What Chrome would reopen at its next launch on a persistent profile, and
// what must survive forgetting it. Files only — no browser is started.
//   node --test scripts/tests/
import { test } from 'node:test';
import assert from 'node:assert/strict';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { forgetSavedSession } from '../lib/chrome-profile.mjs';

function profile() {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'chrome-profile-test-'));
  const def = path.join(dir, 'Default');

  // The shape of storage/app/instagram-puppeteer after a few runs.
  fs.mkdirSync(path.join(def, 'Sessions'), { recursive: true });
  fs.writeFileSync(path.join(def, 'Sessions', 'Session_13431909929848714'), 'https://www.instagram.com/ x104');
  fs.writeFileSync(path.join(def, 'Sessions', 'Tabs_13431909930047509'), 'https://www.instagram.com/ x104');
  fs.mkdirSync(path.join(def, 'Local Storage', 'leveldb'), { recursive: true });
  fs.writeFileSync(path.join(def, 'Local Storage', 'leveldb', '000003.log'), 'ig');
  fs.mkdirSync(path.join(def, 'Session Storage'), { recursive: true });
  fs.writeFileSync(path.join(def, 'Cookies'), 'sessionid=…');
  fs.writeFileSync(path.join(def, 'Preferences'), '{}');

  const cleanup = () => {
    try {
      fs.chmodSync(path.join(def, 'Sessions'), 0o755);
    } catch {}
    fs.rmSync(dir, { recursive: true, force: true });
  };

  return { dir, def, cleanup };
}

test('the saved tabs go; the sign-in stays', () => {
  const { dir, def, cleanup } = profile();

  try {
    assert.deepEqual(forgetSavedSession(dir), []);

    assert.equal(fs.existsSync(path.join(def, 'Sessions')), false);
    for (const kept of ['Cookies', 'Preferences', 'Local Storage/leveldb/000003.log', 'Session Storage']) {
      assert.equal(fs.existsSync(path.join(def, kept)), true, `${kept} must survive`);
    }
  } finally {
    cleanup();
  }
});

test('the loose files older Chrome builds kept the session in go too', () => {
  const { dir, def, cleanup } = profile();

  try {
    for (const name of ['Current Session', 'Current Tabs', 'Last Session', 'Last Tabs']) {
      fs.writeFileSync(path.join(def, name), 'tabs');
    }

    assert.deepEqual(forgetSavedSession(dir), []);

    for (const name of ['Current Session', 'Current Tabs', 'Last Session', 'Last Tabs']) {
      assert.equal(fs.existsSync(path.join(def, name)), false, `${name} must go`);
    }
  } finally {
    cleanup();
  }
});

test('a profile with nothing saved, or no profile at all, is already clean', () => {
  const { dir, cleanup } = profile();

  try {
    assert.deepEqual(forgetSavedSession(dir), []);
    assert.deepEqual(forgetSavedSession(dir), []);
    assert.deepEqual(forgetSavedSession(path.join(dir, 'never-created')), []);
  } finally {
    cleanup();
  }
});

test('tabs it could not remove are named, so the caller does not launch', { skip: process.getuid?.() === 0 && 'root removes anything' }, () => {
  const { dir, def, cleanup } = profile();

  try {
    fs.chmodSync(path.join(def, 'Sessions'), 0o555);

    assert.deepEqual(forgetSavedSession(dir), [path.join(def, 'Sessions')]);
  } finally {
    cleanup();
  }
});
