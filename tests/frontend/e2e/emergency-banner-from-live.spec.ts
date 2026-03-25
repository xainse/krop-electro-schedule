import { test, expect } from '@playwright/test';

const PORT = 4173;
const ROOT_URL = `http://127.0.0.1:${PORT}`;

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
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify(fixtures.queue),
      });
    }

    return route.fulfill({ status: 404, contentType: 'text/plain', body: 'Not mocked' });
  });
}

test('emergency banner follows emergency_mode (queue 1.1 and 6.1)', async ({ page, request }) => {
  const queuesToCheck = ['1.1', '6.1'];

  // Завантажуємо live fixtures для першої черги і використовуємо їх як “джерело” моків.
  // emergency_mode за вашою логікою однаковий для всіх черг, тому цього достатньо.
  const queueFixtureBase = await request
    .get(`https://xain.in.ua/api/blackout.php?queue=${queuesToCheck[0]}`)
    .then((r) => r.json());
  const allFixture = await request.get('https://xain.in.ua/api/blackout.php?all=1').then((r) => r.json());

  await page.addInitScript(() => {
    (window as any).setInterval = () => 0;
    (window as any).Notification = {
      permission: 'denied',
      requestPermission: async () => 'denied',
    };
  });

  // Зафіксуємо стартову чергу.
  await page.addInitScript((q: string) => {
    localStorage.setItem('queue', q);
  }, queuesToCheck[0]);

  await mockBlackoutApiFromFixtures(page, { queue: queueFixtureBase, all: allFixture });

  await page.goto(`${ROOT_URL}/index.html`, { waitUntil: 'domcontentloaded' });

  const emergency = !!queueFixtureBase.emergency_mode;
  const emergencyMsg = page.locator('#emergencyMsg');

  for (const q of queuesToCheck) {
    // Оновлюємо чергу через UI, щоб сторінка перезавантажила дані
    await page.locator('#queueSelect').selectOption(q);
    await page.locator('#refreshBtn').click();

    if (emergency) {
      await expect(emergencyMsg).toBeVisible();
      await expect(emergencyMsg).toContainText('ГАВ');
    } else {
      await expect(emergencyMsg).toBeHidden();
    }

    // Додатково переконаємось, що load завершився (updatedMeta не має “—”)
    await expect(page.locator('#updatedMeta')).not.toHaveText('Останнє оновлення: —');
  }
});

