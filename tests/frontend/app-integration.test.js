/** @jest-environment node */
const { JSDOM } = require('jsdom');
const fs = require('fs');
const path = require('path');
const html = fs.readFileSync(path.join(__dirname, '../../index.html'), 'utf8');
const script = [...html.matchAll(/<script>([\s\S]*?)<\/script>/g)].at(-1)[1];
const today = () => new Intl.DateTimeFormat('uk-UA', { timeZone: 'Europe/Kyiv', day: '2-digit', month: '2-digit', year: 'numeric' }).format(new Date());
const payload = extra => ({ success: true, available: true, stale: false, updated: Math.floor(Date.now() / 1000), date: today(), schedule: '00:00-24:00', queues: { '1.1': '00:00-24:00' }, ...extra });
const response = data => ({ ok: true, status: 200, text: async () => JSON.stringify(data) });
let dom;
const tick = () => new Promise(resolve => setImmediate(resolve));
async function start(fetch, prepare = () => {}, url = 'https://schedule.example/index.html') {
  dom = new JSDOM(html, { runScripts: 'outside-only', url });
  dom.window.fetch = fetch;
  prepare(dom.window);
  dom.window.eval(script);
  await tick();
  return dom.window;
}
const text = id => dom.window.document.getElementById(id).textContent;
const refresh = async () => { dom.window.document.getElementById('refreshBtn').click(); await tick(); };
afterEach(() => dom?.window.close());

test('expired production payload never becomes all-day power', async () => {
  await start(async () => response(payload({date: '30.06.2026', schedule: '', emergency_mode: true})));
  expect(text('apiErrorMsg')).toContain('30.06.2026');
  expect(text('grid')).not.toContain('⚡');
  expect(text('hoursOnStat')).toBe('—');
  expect(dom.window.document.getElementById('emergencyMsg').style.display).toBe('none');
});
test('not announced schedule shows plain message without connection error', async () => {
  await start(async () => response(payload({
    available: false,
    not_announced: true,
    message: 'Графіки відключення не оголошені',
    schedule: null,
    queues: {},
  })));
  expect(text('apiErrorMsg')).toBe('Графіки відключення не оголошені');
  expect(text('statusMsg')).toBe('Готово');
  expect(text('grid')).not.toContain('⚡');
  expect(text('grid')).not.toContain('🌑');
});
test('API failure preserves only the same queue and keeps emergency uncertainty', async () => {
  const w = await start(async () => response(payload({emergency_mode: true})));
  expect(text('statusMsg')).toBe('Готово');
  w.fetch = async () => { throw new Error('offline'); };
  await refresh();
  expect(text('updatedMeta')).toContain('застарілі');
  expect(text('grid')).toContain('🌑');
  expect(text('emergencyMsg')).toContain('не підтверджено');
  const select = w.document.getElementById('queueSelect');
  select.value = '2.2'; select.dispatchEvent(new w.Event('change'));
  await tick();
  expect(text('grid')).not.toContain('🌑');
  expect(text('updatedMeta')).toBe('Актуальний графік відсутній');
});
test('late first request cannot replace the selected queue', async () => {
  let release;
  const w = await start(url => url.includes('queue=1.1') ? new Promise(resolve => { release = resolve; }) : Promise.resolve(response(payload({schedule: ''}))));
  const select = w.document.getElementById('queueSelect');
  select.value = '2.2'; select.dispatchEvent(new w.Event('change'));
  await tick();
  release(response(payload({queue:'1.1'})));
  await tick();
  expect(text('grid')).not.toContain('🌑');
  expect(text('grid')).toContain('⚡');
});
test('storage denial does not prevent startup or queue change', async () => {
  await start(async () => response(payload()), w => Object.defineProperty(w, 'localStorage', { get() { throw new Error('denied'); } }));
  expect(text('statusMsg')).toBe('Готово');
});
test.each([payload({success:false}), payload({schedule:'99:99-88:88'}), payload({updated: null})])('invalid response stays unknown', async data => {
  await start(async () => response(data));
  expect(text('grid')).not.toContain('⚡');
  expect(text('grid')).not.toContain('🌑');
});
test('overview rendering does not overwrite selected queue status', async () => {
  await start(async url => response(payload(url.includes('all=1') ? {queues:{'1.1':''}} : {})));
  expect(text('statusMsg')).toBe('Готово');
  expect(dom.window.document.querySelectorAll('#overviewTableBody td[aria-label]')).toHaveLength(288);
});


test.each(['https://untrusted.example', 'http://127.0.0.1:9999', '//untrusted.example'])('production ignores API override %s', async base => {
  const urls = [];
  await start(async url => { urls.push(url); return response(payload()); }, () => {},
    'https://xainse.github.io/krop-electro-schedule/?api_base=' + encodeURIComponent(base));
  expect(urls.length).toBe(2);
  expect(urls.every(url => new URL(url).origin === 'https://xain.in.ua')).toBe(true);
});
test('local development accepts only a loopback API origin', async () => {
  const urls = [];
  await start(async url => { urls.push(url); return response(payload()); }, () => {},
    'http://127.0.0.1:8080/?api_base=http://127.0.0.1:8080');
  expect(urls.every(url => url.startsWith('http://127.0.0.1:8080/'))).toBe(true);
});


test.each([
  { date: '01.01.2000' }, { stale: true }, { updated: null },
  { updated: Math.floor(Date.now()/1000) - 900 }, { updated: Math.floor(Date.now()/1000) + 3600 },
])('unverified absence notice does not become current: %j', async override => {
  await start(async () => response(payload({not_announced: true, available: false, ...override})));
  expect(text('statusMsg')).not.toBe('Готово');
  expect(text('apiErrorMsg')).not.toBe('Графіки відключення не оголошені');
});
