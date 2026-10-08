import { test, expect } from '@playwright/test';
import { mockApi } from './fixtures';
for (const emergency of [true, false]) {
  test(`emergency banner ${emergency} survives queue changes`, async ({ page }) => {
    await mockApi(page, { emergency_mode: emergency });
    await page.goto('/index.html');
    for (const queue of ['1.1', '6.1']) {
      await page.locator('#queueSelect').selectOption(queue);
      await expect(page.locator('#statusMsg')).toHaveText('Готово');
      await expect(page.locator('#emergencyMsg')).toBeVisible({ visible: emergency });
    }
  });
}
