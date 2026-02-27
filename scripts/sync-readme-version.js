#!/usr/bin/env node
/**
 * Підставляє поточну версію з index.html (const VERSION) у README.md.
 * Запуск: node scripts/sync-readme-version.js
 * В README використовуйте placeholder: <!-- VERSION -->
 */
const fs = require('fs');
const path = require('path');

const root = path.resolve(__dirname, '..');
const indexPath = path.join(root, 'index.html');
const readmePath = path.join(root, 'README.md');

const indexHtml = fs.readFileSync(indexPath, 'utf-8');
const match = indexHtml.match(/const\s+VERSION\s*=\s*['"]([^'"]+)['"]/);
if (!match) {
  console.error('sync-readme-version: VERSION not found in index.html');
  process.exit(1);
}
const version = match[1];

let readme = fs.readFileSync(readmePath, 'utf-8');
// Замінюємо або placeholder <!-- VERSION -->, або вже підставлену версію **X.Y**
const linePattern = /(Поточна версія:\s*)(?:\*\*[^*]+\*\*|<!-- VERSION -->)([^\n]*)/;
if (!linePattern.test(readme)) {
  console.error('sync-readme-version: Expected "Поточна версія: ..." line with <!-- VERSION --> or **X.Y** in README.md');
  process.exit(1);
}
readme = readme.replace(linePattern, `$1**${version}**$2`);
fs.writeFileSync(readmePath, readme);
console.log(`README.md: version set to ${version}`);
