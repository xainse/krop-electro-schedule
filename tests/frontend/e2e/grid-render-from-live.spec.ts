import { test, expect } from '@playwright/test';

const PORT = 4173;
const ROOT_URL = `http://127.0.0.1:${PORT}`;

const ALL_QUEUES = ['1.1', '1.2', '2.1', '2.2', '3.1', '3.2', '4.1', '4.2', '5.1', '5.2', '6.1', '6.2'];

async function mockBlackoutApiFromFixtures(page: any, fixtures: { queue: any; all: any }) {
  await page.route('**/api/blackout.php**', async (route) => {
    const reqUrl = new URL(route.request().url());
    const queue = reqUrl.searchParams.get('queue');
    const all = reqUrl.searchParams.get('all');

    if (all === '1') {
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(fixtures.all),
      });
    }

    if (queue) {
      // У цьому тесті нам потрібна тільки поточна черга.
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(fixtures.queue),
      });
    }

    return route.fulfill({ status: 404, contentType: 'text/plain', body: 'Not mocked' });
  });
}

test('grid renders (live API fixtures)', async ({ page, request }) => {
  // Фіксуємо джерела для моків на основі live API
  const queue = '1.1';
  const queueFixture = await request
    .get(`https://xain.in.ua/api/blackout.php?queue=${queue}`)
    .then((r) => r.json());

  const allFixture = await request
    .get('https://xain.in.ua/api/blackout.php?all=1')
    .then((r) => r.json());

  // Вимикаємо інтервали та нотифікації, щоб сторінка не робила повторні запити.
  await page.addInitScript(() => {
    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    (window as any).setInterval = () => 0;

    // eslint-disable-next-line @typescript-eslint/no-explicit-any
    (window as any).Notification = {
      permission: 'denied',
      requestPermission: async () => 'denied',
    };
  });

  // Зафіксуємо чергу (на випадок, якщо в environment вже є localStorage)
  await page.addInitScript((q: string) => {
    localStorage.setItem('queue', q);
  }, queue);

  await mockBlackoutApiFromFixtures(page, { queue: queueFixture, all: allFixture });

  await page.goto(`${ROOT_URL}/index.html`, { waitUntil: 'domcontentloaded' });

  await expect(page.locator('#grid .cell')).toHaveCount(24);
  await expect(page.locator('#overviewTableBody tr')).toHaveCount(12);

  const emojis = await page.$$eval('#grid .cell .emoji', (els) => els.map((e) => e.textContent?.trim() ?? ''));
  expect(emojis).not.toContain('?');
});

