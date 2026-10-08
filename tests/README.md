# Тести для Electro Scheduler

## Структура

```
tests/
├── frontend/          # JavaScript тести (Jest + jsdom)
│   ├── package.json
│   ├── jest.config.js
│   ├── setup.js                        # Витягує JS функції з index.html
│   ├── parseHalfHourSchedule.test.js   # Парсинг півгодинних графіків
│   ├── normalizeTo24.test.js           # Нормалізація форматів API
│   ├── hoursFromIntervals.test.js      # Конвертація інтервалів
│   ├── calculateDailyStats.test.js     # Статистика годин on/off
│   ├── formatHoursText.test.js         # Форматування тексту часу
│   ├── hasScheduleChanged.test.js      # Виявлення змін графіку
│   ├── render.test.js                  # Рендеринг DOM сітки
│   └── initialGrid.test.js            # Ініціалізація сітки
├── backend/           # PHP тести (PHPUnit)
│   ├── composer.json
│   ├── phpunit.xml
│   ├── bootstrap.php
│   ├── ParserTest.php                  # parseScheduleMessage, extractQueues, normalizeSchedule
│   ├── ValidationTest.php              # validateSchedule, extractDate
│   ├── ParseAllQueuesTest.php          # extractQueues (parser)
│   └── EmergencyModeTest.php           # detectEmergencyMode (parser), checkEmergencyModeInHTML (site_fetcher)
└── README.md
```

## Запуск тестів

### Frontend (JavaScript)

```bash
cd tests/frontend
npm install        # Перший раз
npm test           # Запустити всі тести
```

### Frontend (e2e) — Playwright

```bash
cd tests/frontend
npm install
#
# 1) Завантажити браузери для Playwright (потрібен інтернет)
#
npm run playwright:install
npm run test:e2e  # Запустити e2e у headless режимі
```

Для дебагу:

```bash
cd tests/frontend
npm run test:e2e:headed
```

### Backend (PHP)

```bash
cd tests/backend
composer install   # Перший раз
vendor/bin/phpunit # Запустити всі тести
```

## Покриття

Jest перевіряє чисті функції та весь inline-застосунок у jsdom, включно з простроченим live payload, гонкою запитів, ізоляцією кешу й відмовою storage.

PHPUnit перевіряє парсер, джерела, дати, актуальність відповіді та валідацію HTTP-запитів. Тестовий runtime ізольований від локального `api/config.php` і production кешу.

Playwright використовує детерміновані fixtures та перевіряє UI на desktop/mobile. Немає обов'язкових звернень до живого API. Для окремо запущеного сервера 8080 використовуйте `KROP_E2E_EXTERNAL_SERVER=1 npm run test:e2e`.

CI: `.github/workflows/tests.yml`. Кількість тестів визначається поточним результатом запуску; ручний аудит і обмеження середовища задокументовано в `docs/AUDIT-2026-10-08.md`.
