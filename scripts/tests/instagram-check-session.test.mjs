// instagram-check-session.mjs, up to the point where it would start Chrome.
// No browser is ever started here: every run is pointed at /bin/false as its
// "Chrome", so even a regression that reaches launch() cannot open one.
//   node --test scripts/tests/
import { test } from 'node:test';
import assert from 'node:assert/strict';
import { spawnSync } from 'node:child_process';
import fs from 'node:fs';
import os from 'node:os';
import path from 'node:path';
import { fileURLToPath } from 'node:url';

const script = path.join(path.dirname(fileURLToPath(import.meta.url)), '..', 'instagram-check-session.mjs');

function check(args) {
  const run = spawnSync(process.execPath, [script, ...args], {
    encoding: 'utf8',
    timeout: 20000,
    env: { ...process.env, PUPPETEER_EXECUTABLE_PATH: '/bin/false' },
  });
  const lines = run.stdout.trim().split('\n').filter((line) => line.startsWith('{'));

  return { status: run.status, stderr: run.stderr, lines, said: lines.length ? JSON.parse(lines.at(-1)) : null };
}

const puppeteerMissing = (run) => /ERR_MODULE_NOT_FOUND/.test(run.stderr) && 'node_modules is not installed here';

test('saved tabs that cannot be removed: it says so and never starts Chrome', { skip: process.getuid?.() === 0 && 'root removes anything' }, (t) => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'ig-check-test-'));
  const sessions = path.join(dir, 'Default', 'Sessions');
  fs.mkdirSync(sessions, { recursive: true });
  fs.writeFileSync(path.join(sessions, 'Session_1'), 'https://www.instagram.com/ x104');
  fs.chmodSync(sessions, 0o555);

  try {
    const run = check([`--user-data-dir=${dir}`, '--timeout-ms=5000']);
    if (puppeteerMissing(run)) return t.skip(puppeteerMissing(run));

    assert.equal(run.status, 1);
    assert.equal(run.lines.length, 1, 'one JSON line, no more');
    assert.equal(run.said.ok, false);
    assert.equal(run.said.reason, 'saved_session_not_cleared');
    assert.match(run.said.error, /Chrome was not started/);
    assert.equal('authenticated' in run.said, false, 'no verdict about the sign-in');
  } finally {
    fs.chmodSync(sessions, 0o755);
    fs.rmSync(dir, { recursive: true, force: true });
  }
});

test('the saved tabs are gone before Chrome is asked for, and a browser that will not start is a reason, not silence', (t) => {
  const dir = fs.mkdtempSync(path.join(os.tmpdir(), 'ig-check-test-'));
  const sessions = path.join(dir, 'Default', 'Sessions');
  fs.mkdirSync(sessions, { recursive: true });
  fs.writeFileSync(path.join(sessions, 'Session_1'), 'https://www.instagram.com/ x104');
  fs.writeFileSync(path.join(dir, 'Default', 'Cookies'), 'sessionid=…');

  try {
    const run = check([`--user-data-dir=${dir}`, '--timeout-ms=5000']);
    if (puppeteerMissing(run)) return t.skip(puppeteerMissing(run));

    assert.equal(fs.existsSync(sessions), false, 'the saved session was forgotten first');
    assert.equal(fs.existsSync(path.join(dir, 'Default', 'Cookies')), true, 'the sign-in was not touched');
    assert.equal(run.status, 1);
    assert.equal(run.lines.length, 1, 'one JSON line, no more');
    assert.equal(run.said.ok, false);
    assert.ok(run.said.error.length > 0, 'the launch failure is spelled out');
    assert.equal('authenticated' in run.said, false);
  } finally {
    fs.rmSync(dir, { recursive: true, force: true });
  }
});

test('no profile given: usage error, nothing started', (t) => {
  const run = check([]);
  if (puppeteerMissing(run)) return t.skip(puppeteerMissing(run));

  assert.equal(run.status, 2);
  assert.match(run.said.error, /--user-data-dir is required/);
});
