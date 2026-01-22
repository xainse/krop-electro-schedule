# План перепланування проєкту: RSS замість Telegram
**Дата:** 22.01.2026

## Огляд змін

Проєкт буде переведено з Telegram Bot API на отримання даних через RSS фід (https://rss.app/feeds/mqBOeTuLJO2YFCZC.xml). Якщо RSS недоступний, система автоматично перемикається на парсинг сайту kiroe.com.ua.

## Файли для видалення

Повністю прибираємо Telegram функціонал:
- `api/telegram_fetcher.php` - отримання даних через Telegram Bot API
- `api/telegram_setup.php` - налаштування бота
- `api/telegram_webhook.php` - обробка webhook

## Нові файли

### 1. RSS Fetcher - `api/rss_fetcher.php`

Новий модуль для роботи з RSS фідом:

**Функції:**
- `fetchFromRSS($limit)` - завантаження та парсинг RSS
- `parseRSSItem($item)` - парсинг окремого RSS елемента
- `extractTextFromHTML($html)` - витягування тексту з HTML description
- `saveRSSMessage($messageData)` - збереження повідомлення в історію

**Логіка:**
1. Завантажує RSS через `file_get_contents()` або `curl`
2. Парсить XML через `SimpleXML` або `DOMDocument`
3. Для кожного `<item>` витягує:
   - `<title>` - заголовок
   - `<description>` - HTML з текстом повідомлення (містить графіки)
   - `<pubDate>` - дата публікації
   - `<link>` - посилання на повідомлення
4. Очищає HTML з `<description>`, витягує текст
5. Використовує існуючий `parseScheduleMessage()` з `parser.php`
6. Зберігає в `schedules.json` через `saveSchedules()`
7. Зберігає в історію `rss_messages.json` (аналогічно telegram_messages.json)

**Приклад структури RSS item:**
```xml
<item>
  <title>⚠ 22.01.2026 - Графік погодинних відключень</title>
  <description><![CDATA[<div>Черга 1.1: 00-03, 04-06...</div>]]></description>
  <pubDate>Wed, 21 Jan 2026 18:42:53 +0000</pubDate>
  <link>https://t.me/SvitloKropyvnytskyiMisto/1324</link>
</item>
```

### 2. Історія RSS повідомлень

Файл: `cache/rss_messages.json`

Структура (аналогічно telegram_messages.json):
```json
[
  {
    "link": "https://t.me/...",
    "title": "⚠ 22.01.2026 - Графік...",
    "text": "витягнутий текст",
    "pub_date": "2026-01-21T18:42:53Z",
    "parsed": true,
    "saved_at": 1234567890
  }
]
```

## Оновлення існуючих файлів

### 1. Головний API - `api/blackout.php`

**Змінити логіку оновлення даних (рядки 408-563):**

Поточна логіка:
```
if (needUpdate) {
  html = fetchUrl(site)
  parse HTML
  save to cache
}
```

Нова логіка (каскадний fallback):
```
if (needUpdate && shouldCheckRSS()) {
  // 1. Спочатку пробуємо RSS
  result = fetchFromRSS()
  if (result) {
    save to cache
  } else {
    // 2. Якщо RSS недоступний - парсимо сайт
    html = fetchUrl(site)
    parse HTML
    save to cache
  }
}
```

**Функція `shouldCheckRSS()`:**
- Перевіряє чи минуло 5 хвилин з останньої перевірки RSS
- Зберігає timestamp останньої перевірки в `cache/last_rss_check.txt`
- Повертає `true` якщо минуло >= 5 хвилин

**Оновити константи:**
```php
const RSS_CHECK_INTERVAL = 300; // 5 хвилин в секундах
const RSS_URL = 'https://rss.app/feeds/mqBOeTuLJO2YFCZC.xml';
```

### 2. Конфігурація - `api/config.php`

**Видалити:**
- `TELEGRAM_BOT_TOKEN`
- `TELEGRAM_WEBHOOK_SECRET`
- `SETUP_PASSWORD`
- `SOURCE_TELEGRAM`

**Додати:**
```php
define('RSS_URL', 'https://rss.app/feeds/mqBOeTuLJO2YFCZC.xml');
define('RSS_CHECK_INTERVAL', 300); // 5 хвилин
define('RSS_MESSAGES_FILE', CACHE_DIR . '/rss_messages.json');
define('SOURCE_RSS', 'rss');
```

### 3. Модуль даних - `api/data.php`

**Додати функції:**
- `saveRSSMessage($messageData)` - збереження RSS повідомлення (аналогічно `saveTelegramMessage()`)
- `getRSSMessages($limit)` - читання історії RSS (аналогічно `getTelegramMessages()`)

**Оновити:**
- Функція `saveSchedules()` - додати підтримку `SOURCE_RSS` як джерела

## Три режими роботи

### 1. Ініціалізація (перший запуск)

**Коли:** `schedules.json` не існує або порожній

**Що відбувається:**
1. API отримує запит від frontend
2. Перевіряє `isDataEmpty()` = true
3. Викликає `fetchFromRSS()` 
4. Парсить ВСІ items з RSS (limit = 100)
5. Шукає найновіший графік
6. Зберігає в `schedules.json`
7. Якщо RSS недоступний → fallback на `fetchFromSite()`

### 2. Поточна робота

**Коли:** є дані в кеші

**Що відбувається:**
1. API отримує запит від frontend
2. Перевіряє `shouldCheckRSS()` - чи минуло 5 хвилин?
3. Якщо так:
   - Викликає `fetchFromRSS()` з limit = 10
   - Якщо знайдено новіший графік → оновлює кеш
   - Зберігає timestamp перевірки
4. Якщо ні → повертає дані з кешу
5. Якщо RSS недоступний → fallback на `fetchFromSite()`

### 3. Fallback на сайт

**Коли:** RSS не відповідає або повертає помилку

**Що відбувається:**
1. Викликає існуючу функцію `fetchFromSite()`
2. Парсить HTML сайту kiroe.com.ua
3. Зберігає з source = `SOURCE_SITE`

## Структура файлів після змін

```
api/
├── blackout.php         [ОНОВЛЕНО] Головний API з RSS логікою
├── config.php           [ОНОВЛЕНО] Прибрано Telegram, додано RSS
├── data.php             [ОНОВЛЕНО] Додано saveRSSMessage(), getRSSMessages()
├── parser.php           [БЕЗ ЗМІН] Використовується для RSS
├── site_fetcher.php     [БЕЗ ЗМІН] Fallback парсер
├── rss_fetcher.php      [НОВИЙ] RSS парсер
└── cache/
    ├── schedules.json
    ├── rss_messages.json [НОВИЙ] Історія RSS повідомлень
    └── last_rss_check.txt [НОВИЙ] Timestamp останньої перевірки
```

## Оновлення документації

Файли для оновлення:
- `README.md` - замінити інформацію про Telegram на RSS
- `docs/CHANGELOG.md` - додати запис про перехід на RSS
- Видалити/оновити:
  - `docs/TELEGRAM_BOT_PLAN.md`
  - `docs/TELEGRAM_SETUP_INSTRUCTIONS.md`
  - `docs/QUICK_START.md`
  - `docs/НАСТУПНІ_КРОКИ.md`

## Переваги нового підходу

1. **Простота** - не потрібен Bot Token, webhook, налаштування
2. **Надійність** - RSS більш стабільний ніж Bot API
3. **Швидкість** - прямий доступ до даних
4. **Fallback** - автоматичне перемикання на сайт
5. **Історія** - зберігаємо всі отримані повідомлення

## Тестування

Після реалізації перевірити:
1. Ініціалізацію з порожнім кешем
2. Оновлення кожні 5 хвилин
3. Fallback при недоступності RSS
4. Парсинг різних типів повідомлень (ГАВ, графіки, зміни)
5. Збереження історії в rss_messages.json
