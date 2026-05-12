#!/usr/bin/env node
const fs = require('fs');
const path = require('path');
const dir = process.argv[2] || 'storage/lighthouse-reports';
const auditId = process.argv[3] || 'robots-txt';
const files = fs.readdirSync(dir).filter(f => f.endsWith('.json') && f !== 'manifest.json').sort();
for (const f of files) {
  let lh;
  try { lh = JSON.parse(fs.readFileSync(path.join(dir, f), 'utf8')); } catch { continue; }
  const a = lh.audits[auditId];
  if (!a) continue;
  console.log('\n=== ' + f + ' :: ' + auditId + ' (score=' + a.score + ') ===');
  console.log(JSON.stringify({
    title: a.title,
    displayValue: a.displayValue,
    explanation: a.explanation,
    details: a.details,
  }, null, 2).slice(0, 4000));
}
