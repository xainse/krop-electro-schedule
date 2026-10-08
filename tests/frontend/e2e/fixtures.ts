import { Page } from '@playwright/test';
export const date = new Intl.DateTimeFormat('uk-UA', { timeZone: 'Europe/Kyiv', day: '2-digit', month: '2-digit', year: 'numeric' }).format(new Date());
export const base = { success: true, available: true, stale: false, date, updated: Math.floor(Date.now() / 1000), emergency_mode: false };
export const queues = Object.fromEntries(['1.1','1.2','2.1','2.2','3.1','3.2','4.1','4.2','5.1','5.2','6.1','6.2'].map(q => [q, '02:00-04:30']));
export async function mockApi(page: Page, overrides = {}) {
  await page.route('**/api/blackout.php**', route => {
    const url = new URL(route.request().url());
    const queue = url.searchParams.get('queue');
    return route.fulfill({ json: queue ? { ...base, queue, schedule: queues[queue], ...overrides } : { ...base, queues, ...overrides } });
  });
}
