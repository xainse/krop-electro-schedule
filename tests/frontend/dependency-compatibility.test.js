/** @jest-environment node */
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const { loadNycConfig } = require('@istanbuljs/load-nyc-config');

test('coverage config retains YAML semantics after the scoped js-yaml upgrade', async () => {
  const directory = fs.mkdtempSync(path.join(os.tmpdir(), 'krop-nyc-'));
  try {
    fs.writeFileSync(path.join(directory, 'package.json'), '{"name":"krop-nyc-test","private":true}');
    fs.writeFileSync(path.join(directory, '.nycrc.yaml'), 'include:\n  - src/**/*.js\nexclude: tests/**\nsourceMap: false\n');
    const config = await loadNycConfig({ cwd: directory, nycrcPath: '.nycrc.yaml' });
    expect(config.include).toEqual(['src/**/*.js']);
    expect(config.exclude).toEqual(['tests/**']);
    expect(config.sourceMap).toBe(false);
  } finally {
    fs.rmSync(directory, { recursive: true, force: true });
  }
});
