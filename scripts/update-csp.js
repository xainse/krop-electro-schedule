#!/usr/bin/env node
const fs = require('node:fs');
const path = require('node:path');
const crypto = require('node:crypto');
const file = path.resolve(__dirname, '../index.html');
const html = fs.readFileSync(file, 'utf8');
const hashes = [...html.matchAll(/<script>([\s\S]*?)<\/script>/g)]
  .map(match => `'sha256-${crypto.createHash('sha256').update(match[1]).digest('base64')}'`).join(' ');
const policy = `default-src 'none'; script-src 'self' ${hashes} https://www.googletagmanager.com; connect-src 'self' https://xain.in.ua https://www.google-analytics.com https://region1.google-analytics.com https://www.googletagmanager.com; style-src 'self' 'unsafe-inline'; img-src 'self' data: https://www.google-analytics.com; font-src 'self'; object-src 'none'; base-uri 'none'; form-action 'none'`;
const tag = `<meta http-equiv="Content-Security-Policy" content="${policy}" />`;
const updated = /<meta http-equiv="Content-Security-Policy"[^>]*>/.test(html)
  ? html.replace(/<meta http-equiv="Content-Security-Policy"[^>]*>/, tag)
  : html.replace('<meta charset="utf-8" />', `<meta charset="utf-8" />\n  ${tag}\n  <meta name="referrer" content="strict-origin-when-cross-origin" />`);
if (process.argv.includes('--check')) {
  if (html !== updated) { console.error('CSP hashes are stale; run node scripts/update-csp.js'); process.exit(1); }
  console.log('CSP hashes match all inline scripts.');
} else { fs.writeFileSync(file, updated); console.log('Updated CSP hashes.'); }
