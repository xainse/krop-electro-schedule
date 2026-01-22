# Швидкий старт: Що робити далі

## ✅ Створені файли

Всі необхідні файли вже створені:

### Нові файли для Telegram інтеграції:
- `api/config.example.php` - шаблон конфігурації
- `api/data.php` - робота з JSON кешем
- `api/parser.php` - парсинг повідомлень
- `api/telegram_webhook.php` - обробник webhook від Telegram
- `api/telegram_fetcher.php` - отримання повідомлень через API
- `api/site_fetcher.php` - fallback на сайт kiroe.com.ua
- `api/telegram_setup.php` - веб-інтерфейс для налаштування
- `api/blackout_new.php` - новий API з каскадним fallback
- `api/cache/.htaccess` - захист JSON файлів
- `TELEGRAM_BOT_PLAN.md` - повний план архітектури
- `TELEGRAM_SETUP_INSTRUCTIONS.md` - детальна інструкція

### Оновлені файли:
- `api/.htaccess` - додано правила безпеки
- `.gitignore` - додано config.php

---

## 📋 Що потрібно зробити (покрокова інструкція)

### КРОК 1: Створити Telegram бота (5 хвилин)

1. Відкрий Telegram → знайди `@BotFather`
2. Надішли: `/newbot`
3. Введи ім'я: `Krop Electro Bot`
4. Введи username: `krop_electro_bot` (або інший)
5. **ЗБЕРЕЖИ ТОКЕН** (виглядає як `123:ABC...xyz`)

---

### КРОК 2: Створити config.php (3 хвилини)

```bash
# У папці проєкту виконай:
cd api
cp config.example.php config.php
```

Відкрий `api/config.php` і заповни:

```php
// 1. Вставте токен від BotFather
define('TELEGRAM_BOT_TOKEN', 'ВСТАВТЕ_ТОКЕН_СЮДИ');

// 2. Придумайте секретний ключ (32+ символів)
define('TELEGRAM_WEBHOOK_SECRET', 'mY_sEcReT_kEy_123456789');

// 3. Придумайте пароль для setup скрипта
define('SETUP_PASSWORD', 'MyPassword999');
```

**ВАЖЛИВО:** НЕ комітьте `config.php` в git! (вже в .gitignore)

---

### КРОК 3: Завантажити файли на сервер (10 хвилин)

Через FTP/SFTP завантаж на сервер:

**З папки `api/` завантаж ВСІ файли:**
- ✅ config.php (твій з токенами)
- ✅ config.example.php
- ✅ data.php
- ✅ parser.php
- ✅ telegram_webhook.php
- ✅ telegram_fetcher.php
- ✅ site_fetcher.php
- ✅ telegram_setup.php
- ✅ blackout_new.php
- ✅ .htaccess (оновлений)

**Створи папки на сервері:**
- `api/cache/` (права 755)
- `api/logs/` (права 755)

**Завантаж в `api/cache/`:**
- ✅ .htaccess

---

### КРОК 4: Налаштувати через браузер (5 хвилин)

#### 4.1 Ініціалізація
Відкрий в браузері (замість `PASSWORD` підстав свій з config.php):
```
https://xain.in.ua/api/telegram_setup.php?password=PASSWORD&action=init
```
Має показати: ✅ Структура папок створена

#### 4.2 Тест з'єднання
```
https://xain.in.ua/api/telegram_setup.php?password=PASSWORD&action=test
```
Має показати інфо про бота

#### 4.3 Встановити webhook
```
https://xain.in.ua/api/telegram_setup.php?password=PASSWORD&action=set_webhook
```
Має показати: ✅ Webhook встановлено успішно

---

### КРОК 5: Додати бота до каналу (2 хвилини)

#### Якщо ти адмін @SvitloKropyvnytskyiMisto:
1. Відкрий канал в Telegram
2. Назва каналу → "Адміністратори" → "Додати адміністратора"
3. Знайди `@krop_electro_bot` (твій username)
4. Додай з мінімальними правами

#### Якщо НЕ адмін:
1. Створи свій канал в Telegram
2. Додай бота як адміністратора
3. Пересилай повідомлення з @SvitloKropyvnytskyiMisto в свій канал

---

### КРОК 6: Завантажити дані (1 хвилина)

```
https://xain.in.ua/api/telegram_setup.php?password=PASSWORD&action=fetch
```
Має показати: ✅ Графік завантажено (Дата, Черг: 12)

---

### КРОК 7: Перевірити API (1 хвилина)

Відкрий в браузері:
```
https://xain.in.ua/api/blackout_new.php?queue=1.1
```

Має повернути JSON:
```json
{
  "success": true,
  "queue": "1.1",
  "schedule": "00:00-02:00, ...",
  "source": "telegram"
}
```

---

### КРОК 8: Видалити setup скрипт (БЕЗПЕКА!) ⚠️

**Після успішного налаштування:**

Видали з сервера: `api/telegram_setup.php`

АБО

Зміни пароль в `config.php` на сервері на новий.

---

## 🎯 Все готово!

Тепер система працює так:

1. **Нове повідомлення в каналі** → Telegram webhook → автоматично оновлює дані
2. **Користувач відкриває сайт** → API читає з кешу → швидка відповідь
3. **Якщо Telegram не працює** → fallback на kiroe.com.ua

---

## 📊 Де перевірити що все працює?

### Перевірка даних:
```
https://xain.in.ua/api/telegram_setup.php?password=PASSWORD&action=info
```

### Логи запитів:
Файли в `api/logs/api_YYYY-MM-DD.log`

### Кеш даних:
Файл `api/cache/schedules.json`

---

## 🚀 Коли переключати фронтенд?

**Зараз НЕ ТРЕБА нічого міняти в index.html!**

Новий API (`blackout_new.php`) працює паралельно зі старим (`blackout.php`).

**Переключай фронтенд тільки коли:**
1. ✅ Бот працює (webhook встановлено)
2. ✅ Дані завантажуються (schedules.json існує)
3. ✅ API повертає коректні дані
4. ✅ Протестовано 1-2 дні

**Як переключити:**
В `index.html` зміни:
```javascript
// Було:
let API_URL = `https://xain.in.ua/api/blackout.php?queue=${currentQueue}`;

// Стане:
let API_URL = `https://xain.in.ua/api/blackout_new.php?queue=${currentQueue}`;
```

І в функції `loadAllQueues()`:
```javascript
// Було:
const res = await fetch('https://xain.in.ua/api/blackout.php?all=1', ...);

// Стане:
const res = await fetch('https://xain.in.ua/api/blackout_new.php?all=1', ...);
```

Оновити версію до `2.0` та додати в CHANGELOG.

---

## 📚 Детальна документація

- **Повна інструкція:** `TELEGRAM_SETUP_INSTRUCTIONS.md`
- **Архітектура системи:** `TELEGRAM_BOT_PLAN.md`

---

## ❓ Проблеми?

**"Invalid bot token"** → Перевір токен в config.php

**"Webhook failed"** → Перевір HTTPS на сервері

**"No data available"** → Бот не доданий до каналу

**API не працює** → Перевір права доступу (755/644)

---

**Успіхів! ⚡🤖**
