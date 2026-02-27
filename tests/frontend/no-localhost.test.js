/**
 * Перевірка відсутності localhost у фронтенд-файлах проєкту.
 * Гарантує, що в продакшені немає звернень до localhost.
 */
const fs = require('fs');
const path = require('path');

const projectRoot = path.resolve(__dirname, '..', '..');

const frontendFiles = [
  path.join(projectRoot, 'index.html'),
  path.join(projectRoot, 'styles.css'),
  path.join(projectRoot, 'test-error.html'),
];

// Усі .js файли в tests/frontend крім node_modules
const testsDir = path.join(projectRoot, 'tests', 'frontend');
function listTestJs(dir) {
  const entries = fs.readdirSync(dir, { withFileTypes: true });
  const out = [];
  for (const e of entries) {
    const full = path.join(dir, e.name);
    if (e.name === 'node_modules') continue;
    if (e.isDirectory()) {
      out.push(...listTestJs(full));
    } else if (e.isFile() && (e.name.endsWith('.js') || e.name.endsWith('.html') || e.name.endsWith('.css'))) {
      out.push(full);
    }
  }
  return out;
}

const allFiles = [...frontendFiles.filter((f) => fs.existsSync(f)), ...listTestJs(testsDir)].filter(
  (f) => path.basename(f) !== 'no-localhost.test.js'
);

function findLocalhost(filePath) {
  const content = fs.readFileSync(filePath, 'utf-8');
  const lines = content.split(/\r?\n/);
  const matches = [];
  const re = /localhost/gi;
  lines.forEach((line, i) => {
    if (re.test(line)) {
      matches.push({ line: i + 1, content: line.trim() });
    }
  });
  return matches;
}

describe('no localhost in project files', () => {
  test('frontend and test files do not contain localhost', () => {
    const violations = [];
    for (const filePath of allFiles) {
      if (!fs.existsSync(filePath)) continue;
      const matches = findLocalhost(filePath);
      if (matches.length > 0) {
        const relative = path.relative(projectRoot, filePath);
        violations.push({
          file: relative,
          lines: matches.map((m) => `${m.line}: ${m.content}`),
        });
      }
    }
    if (violations.length > 0) {
      const msg = violations
        .map(
          (v) =>
            `${v.file}:\n${v.lines.map((l) => `  ${l}`).join('\n')}`
        )
        .join('\n\n');
      throw new Error(`Found "localhost" in project files (not allowed):\n\n${msg}`);
    }
  });
});
