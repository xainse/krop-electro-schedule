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
│   ├── blackout_functions.php          # Витягує функції з blackout.php
│   ├── ParserTest.php                  # parseScheduleMessage, extractQueues, normalizeSchedule
│   ├── ValidationTest.php              # validateSchedule, extractDate
│   ├── ParseAllQueuesTest.php          # parseAllQueues
│   └── EmergencyModeTest.php           # detectEmergencyMode, checkEmergencyMode
└── README.md
```

## Запуск тестів

### Frontend (JavaScript)

```bash
cd tests/frontend
npm install        # Перший раз
npm test           # Запустити всі тести
```

### Backend (PHP)

```bash
cd tests/backend
composer install   # Перший раз
vendor/bin/phpunit # Запустити всі тести
```

## Покриття

### Frontend (62 тести)

| Функція | Тестів | Що перевіряється |
|---------|--------|------------------|
| `parseHalfHourSchedule` | 11 | Парсинг графіків, edge cases, різні формати |
| `normalizeTo24` | 9 | Усі формати API відповідей |
| `hoursFromIntervals` | 9 | Інтервали, перетікання через північ |
| `calculateDailyStats` | 7 | Підрахунок годин, півгодини, null |
| `formatHoursText` | 6 | Форматування часу |
| `hasScheduleChanged` | 6 | Виявлення змін |
| `initialGrid` | 6 | Створення DOM елементів |
| `render` | 8 | Рендеринг станів, статистика, CSS класи |

### Backend (61 тест)

| Клас тесту | Тестів | Що перевіряється |
|------------|--------|------------------|
| `ParserTest` | 19 | parseScheduleMessage, extractDate, extractQueues, normalizeSchedule |
| `ValidationTest` | 11 | validateSchedule, extractDate edge cases, normalizeSchedule edge cases |
| `ParseAllQueuesTest` | 8 | parseAllQueues з різними форматами |
| `EmergencyModeTest` | 23 | detectEmergencyMode (ГАВ/СГАВ), checkEmergencyMode |
