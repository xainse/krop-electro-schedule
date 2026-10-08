import { test, expect } from '@playwright/test';
import { readFileSync } from 'node:fs';
import { mockApi } from './fixtures';

test('production cannot be redirected to an untrusted API by query string', async ({ page }) => {
  const requests: string[] = [];
  await page.route('https://schedule.example/**', route => route.fulfill({
    contentType: 'text/html', body: readFileSync('../../index.html', 'utf8'),
  }));
  await mockApi(page);
  page.on('request', request => { if (request.url().includes('/api/blackout.php')) requests.push(request.url()); });
  await page.goto('https://schedule.example/?api_base=https://untrusted.example');
  await expect(page.locator('#statusMsg')).toHaveText('Готово');
  expect(requests.length).toBe(2);
  expect(requests.every(url => new URL(url).origin === 'https://xain.in.ua')).toBeTruthy();
});


test('CSP blocks injected inline code while the schedule works', async ({ page }) => {
  await mockApi(page);
  await page.goto('/index.html');
  await expect(page.locator('#statusMsg')).toHaveText('Готово');
  const ran = await page.evaluate(() => {
    const script = document.createElement('script');
    script.textContent = 'document.documentElement.dataset.securityProbe="executed"';
    document.head.appendChild(script);
    return document.documentElement.dataset.securityProbe;
  });
  expect(ran).toBeUndefined();
});

test('an expired absence notice does not look current', async ({ page }) => {
  await mockApi(page, {not_announced: true, available: false, stale: true, date: '01.01.2000'});
  await page.goto('/index.html');
  await expect(page.locator('#statusMsg')).not.toHaveText('Готово');
  await expect(page.locator('#apiErrorMsg')).not.toHaveText('Графіки відключення не оголошені');
});
