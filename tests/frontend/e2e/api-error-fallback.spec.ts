import { test, expect } from '@playwright/test';

const PORT = 4173;
const ROOT_URL = `http://127.0.0.1:${PORT}`;

const OFF_ALL_PERIODS = Array(48).fill(false);

async function mockAllQueuesOk(page: any) {
  const queues: Record<string, string> = {};
  const allQueues = ['1.1', '1.2', '2.1', '2.2', '3.1', '3.2', '4.1', '4.2', '5.1', '5.2', '6.1', '6.2'];
  for (const q of allQueues) queues[q] = '-';

  await page.route('**/api/blackout.php**', async (route) => {
    const reqUrl = new URL(route.request().url());
    const all = reqUrl.searchParams.get('all');
    const queue = reqUrl.searchParams.get('queue');

    if (all === '1') {
      return route.fulfill({
        status: 200,
        contentType: 'application/json',
        body: JSON.stringify({ success: true, queues }),
      });
    }

    if (queue) {
      // Імітуємо помилку API для основного графіку
      return route.fulfill({
        status: 500,
        contentType: 'text/plain',
        body: 'Internal Server Error',
      });
    }

    return route.fulfill({ status: 404, contentType: 'text/plain', body: 'Not mocked' });
  });
}

test('api error uses lastSuccessfulData fallback', async ({ page }) => {
  await page.addInitScript(() => {
    (window as any).setInterval = () => 0;
    (window as any).Notification = {
      permission: 'denied',
      requestPermission: async () => 'denied',
    };
  });

  const lastSuccessfulTimeISO = new Date(Date.now() - 60 * 60 * 1000).toISOString();
  await page.addInitScript(
    ({ periods, timeIso }) => {
      localStorage.setItem('queue', '1.1');
      localStorage.setItem('lastSuccessfulData', JSON.stringify(periods));
      localStorage.setItem('lastSuccessfulTime', timeIso);
    },
    { periods: OFF_ALL_PERIODS, timeIso: lastSuccessfulTimeISO }
  );

  await mockAllQueuesOk(page);

  await page.goto(`${ROOT_URL}/index.html`, { waitUntil: 'domcontentloaded' });

  // statusMsg може перезаписуватись loadAllQueues(), тому перевіряємо стабільні маркери fallback.
  await expect(page.locator('#updatedMeta')).toContainText('⚠️ застарілі');
  await expect(page.locator('#apiErrorMsg')).toContainText('Не вдалося підключитися до API');

  // Якщо lastSuccessfulData весь false => кожен годинний блок має відключено (🌑)
  await expect(page.locator('#grid .cell .emoji', { hasText: '🌑' })).toHaveCount(24);
});

