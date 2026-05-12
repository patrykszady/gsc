#!/usr/bin/env node
const fs = require('fs');
const path = require('path');
const dir = process.argv[2] || 'storage/lighthouse-reports';
const files = fs.readdirSync(dir).filter(f => f.endsWith('.json') && f !== 'manifest.json').sort();
for (const f of files) {
  const full = path.join(dir, f);
  const html = fs.readFileSync(full, 'utf8');
  let lh;
  try {
    // Files are saved with .html extension but content is JSON.
    lh = JSON.parse(html);
  } catch (e) {
    // Fallback: try to find embedded JSON in real HTML.
    const m = html.match(/id="__LIGHTHOUSE_JSON__"[^>]*>([\s\S]*?)<\/script>/);
    if (!m) { console.log('no json:', f); continue; }
    try { lh = JSON.parse(m[1]); } catch (e2) { console.log('parse failed:', f, e2.message); continue; }
  }
  console.log('\n=== ' + f + ' ===');
  console.log('URL:', lh.finalUrl || lh.requestedUrl);
  for (const catId of ['seo', 'best-practices', 'accessibility', 'performance']) {
    const cat = lh.categories[catId];
    if (!cat) continue;
    if (cat.score >= 0.9) continue;
    console.log('  [' + catId + '] score=' + cat.score);
    for (const ref of cat.auditRefs) {
      const a = lh.audits[ref.id];
      if (!a) continue;
      if (a.score === null) continue;
      if (a.score >= 0.9) continue;
      if (a.scoreDisplayMode === 'manual' || a.scoreDisplayMode === 'notApplicable' || a.scoreDisplayMode === 'informative') continue;
      console.log('    - ' + a.id + ' (' + a.score + '): ' + a.title);
      if (a.description) console.log('        ' + a.description.slice(0, 180).replace(/\n/g, ' '));
    }
  }
}
