import { test, expect } from '@playwright/test';
import { mockApi } from './fixtures';
test('grid and overview show deterministic half-hour schedules and accessible hours', async ({ page }) => {
  await mockApi(page);
  await page.goto('/index.html');
  await expect(page.locator('#grid .cell')).toHaveCount(24);
  await expect(page.locator('#overviewTableBody tr')).toHaveCount(12);
  await expect(page.locator('#grid .half-left-off')).toHaveCount(1);
  await expect(page.locator('#overviewTable thead th')).toHaveCount(25);
  await expect(page.locator('#overviewTableBody td[aria-label]')).toHaveCount(288);
  await expect(page.locator('#statusMsg')).toHaveText('Готово');
});
