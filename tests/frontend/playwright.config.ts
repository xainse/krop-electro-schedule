import { defineConfig } from '@playwright/test';

const PORT = 4173;

export default defineConfig({
  testDir: './e2e',
  timeout: 45_000,
  retries: process.env.CI ? 1 : 0,
  // На macOS стабільніше запускати один браузерний воркер.
  workers: 1,
  use: {
    baseURL: `http://127.0.0.1:${PORT}`,
    // Використовуємо інстальований Chrome через channel.
    // Це стабільніше, ніж ручний executablePath + no-sandbox args.
    channel: 'chrome',
    trace: 'on-first-retry',
  },
  webServer: {
    command: `php -S 127.0.0.1:${PORT} -t ../..`,
    url: `http://127.0.0.1:${PORT}/index.html`,
    reuseExistingServer: !process.env.CI,
  },
});

