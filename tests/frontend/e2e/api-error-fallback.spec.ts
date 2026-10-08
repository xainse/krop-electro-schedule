import { test, expect } from '@playwright/test';
import { base, mockApi } from './fixtures';

test('same queue same day fallback is marked stale and keeps emergency warning', async ({ page }) => {
  await mockApi(page, { schedule: '00:00-24:00', emergency_mode: true });
  await page.goto('/index.html');
  await expect(page.locator('#statusMsg')).toHaveText('Готово');
  await page.route('**/api/blackout.php**', route => route.fulfill({ status: 503, body: 'Unavailable' }));
  await page.locator('#refreshBtn').click();
  await expect(page.locator('#updatedMeta')).toContainText('застарілі');
  await expect(page.locator('#apiErrorMsg')).toBeVisible();
  await expect(page.locator('#emergencyMsg')).toContainText('не підтверджено');
  await expect(page.locator('#grid .emoji', { hasText: '🌑' })).toHaveCount(24);
  await page.locator('#queueSelect').selectOption('2.2');
  await expect(page.locator('#updatedMeta')).toHaveText('Актуальний графік відсутній');
  await expect(page.locator('#grid .emoji', { hasText: '🌑' })).toHaveCount(0);
});

test('expired live payload cannot show all-day power or emergency as current', async ({ page }) => {
  await mockApi(page, { date: '30.06.2026', schedule: '', emergency_mode: true });
  await page.goto('/index.html');
  await expect(page.locator('#apiErrorMsg')).toContainText('30.06.2026');
  await expect(page.locator('#grid .emoji', { hasText: '⚡' })).toHaveCount(0);
  await expect(page.locator('#overviewTableBody td.state-unknown')).toHaveCount(288);
  await expect(page.locator('#emergencyMsg')).toBeHidden();
});

test('late response from previous queue cannot overwrite selection', async ({ page }) => {
  let release: () => void = () => {};
  const gate = new Promise<void>(resolve => { release = resolve; });
  let seen: () => void = () => {};
  const started = new Promise<void>(resolve => { seen = resolve; });
  await page.route('**/api/blackout.php**', async route => {
    const queue = new URL(route.request().url()).searchParams.get('queue');
    if (queue === '1.1') { seen(); await gate; }
    await route.fulfill({ json: { ...base, queue, schedule: queue === '1.1' ? '00:00-24:00' : '', queues: {} } });
  });
  await page.goto('/index.html', { waitUntil: 'domcontentloaded' });
  await started;
  await page.locator('#queueSelect').selectOption('2.2');
  await expect(page.locator('#statusMsg')).toHaveText('Готово');
  release();
  await expect(page.locator('#grid .emoji', { hasText: '⚡' })).toHaveCount(24);
  await expect(page.locator('.title')).toContainText('2.2');
});

test('blocked local storage does not stop loading', async ({ page }) => {
  await page.addInitScript(() => { Object.defineProperty(window, 'localStorage', { get() { throw new Error('Storage blocked'); } }); });
  await mockApi(page);
  await page.goto('/index.html');
  await expect(page.locator('#statusMsg')).toHaveText('Готово');
});

// Keep runtime fixture timestamps out of test identifiers.
for (const { name, payload } of [
  { name: 'API error', payload: { success: false, error: 'failure' } },
  { name: 'invalid time range', payload: { schedule: '99:99-88:88' } },
]) {
  test(`malformed payload: ${name} stays unknown`, async ({ page }) => {
    await mockApi(page, payload);
    await page.goto('/index.html');
    await expect(page.locator('#apiErrorMsg')).toBeVisible();
    await expect(page.locator('#grid .emoji', { hasText: '⚡' })).toHaveCount(0);
  });
}

test('mobile layout keeps page within viewport', async ({ page }) => {
  await page.setViewportSize({ width: 375, height: 812 });
  await mockApi(page);
  await page.goto('/index.html');
  await expect(page.locator('#statusMsg')).toHaveText('Готово');
  expect(await page.evaluate(() => document.documentElement.scrollWidth <= window.innerWidth)).toBe(true);
});
