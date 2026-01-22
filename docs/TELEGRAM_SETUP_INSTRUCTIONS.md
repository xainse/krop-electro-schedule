# Покрокова інструкція: Налаштування Telegram бота

## Крок 1: Створення Telegram бота

### 1.1 Відкрийте BotFather в Telegram

1. Відкрийте Telegram на телефоні або комп'ютері
2. Знайдіть `@BotFather` (офіційний бот від Telegram)
3. Натисніть "Start" або надішліть `/start`

### 1.2 Створіть нового бота

1. Надішліть команду: `/newbot`
2. BotFather запитає **ім'я** бота (може бути будь-яким):
   ```
   Krop Electro Bot
   ```

3. BotFather запитає **username** бота (має закінчуватися на `bot`):
   ```
   krop_electro_bot
   ```
   
   *Примітка: Якщо цей username зайнятий, спробуйте інший, наприклад `krop_electro_schedule_bot`*

4. **ВАЖЛИВО!** BotFather надішле вам **токен бота**. Він виглядає так:
   ```
   1234567890:ABCdefGHIjklMNOpqrsTUVwxyz-1234567890
   ```
   
   **Скопіюйте цей токен** і збережіть у безпечному місці - він знадобиться в наступних кроках!

---

## Крок 2: Підготовка конфігураційного файлу

### 2.1 Створіть config.php локально

1. На вашому комп'ютері відкрийте папку проєкту
2. Перейдіть в папку `api/`
3. Скопіюйте файл `config.example.php` → `config.php`

### 2.2 Відредагуйте config.php

Відкрийте `api/config.php` в текстовому редакторі (VS Code, Sublime, Notepad++) та заповніть:

#### A. Вставте токен бота

Знайдіть рядок:
```php
define('TELEGRAM_BOT_TOKEN', 'YOUR_BOT_TOKEN_HERE');
```

Замініть `YOUR_BOT_TOKEN_HERE` на **токен від BotFather** (з кроку 1.2.4):
```php
define('TELEGRAM_BOT_TOKEN', '1234567890:ABCdefGHIjklMNOpqrsTUVwxyz-1234567890');
```

#### B. Створіть секретний ключ для webhook

Знайдіть рядок:
```php
define('TELEGRAM_WEBHOOK_SECRET', 'RANDOM_SECRET_KEY_32_CHARS');
```

Замініть на випадковий рядок (32+ символів). Можна використати:
```php
define('TELEGRAM_WEBHOOK_SECRET', 'mY_sEcReT_wEbHoOk_kEy_12345678abc');
```

*Або згенеруйте свій власний на сайті https://www.random.org/strings/*

#### C. Створіть пароль для setup скрипта

Знайдіть рядок:
```php
define('SETUP_PASSWORD', 'CHANGE_THIS_PASSWORD_123');
```

Замініть на ваш власний пароль:
```php
define('SETUP_PASSWORD', 'MySecurePassword999');
```

#### D. Збережіть файл

**ВАЖЛИВО:** Цей файл містить секретні дані, тому:
- ✅ НЕ комітьте його в git (він вже в `.gitignore`)
- ✅ НЕ публікуйте токен публічно
- ✅ Зберігайте резервну копію локально

---

## Крок 3: Завантаження файлів на сервер

### 3.1 Підключіться до сервера через FTP/SFTP

Використовуйте один з клієнтів:
- **FileZilla** (Windows/Mac/Linux)
- **WinSCP** (Windows)
- **Cyberduck** (Mac)
- Або панель хостингу (якщо є файловий менеджер)

### 3.2 Завантажте ВСІ файли з папки `api/`

Завантажте на сервер в папку `api/`:

**Нові файли (створені для Telegram):**
- ✅ `config.php` (ваш з токенами!)
- ✅ `config.example.php`
- ✅ `data.php`
- ✅ `parser.php`
- ✅ `telegram_webhook.php`
- ✅ `telegram_fetcher.php`
- ✅ `site_fetcher.php`
- ✅ `telegram_setup.php`
- ✅ `blackout_new.php`

**Існуючі файли (оновлені):**
- ✅ `.htaccess` (оновлений з правилами безпеки)

**Зберігаємо як резерв:**
- 📦 `blackout.php` (старий, залишаємо як fallback)

### 3.3 Створіть папки (якщо їх немає)

Через FTP або панель хостингу створіть:
- `api/cache/` (права: 755)
- `api/logs/` (права: 755)

Завантажте в `api/cache/`:
- ✅ `.htaccess` (заборона доступу до JSON)

---

## Крок 4: Ініціалізація через браузер

### 4.1 Відкрийте URL ініціалізації

У браузері вставте (замініть `MySecurePassword999` на ваш пароль з `config.php`):

```
https://xain.in.ua/api/telegram_setup.php?password=MySecurePassword999&action=init
```

**Очікуваний результат:**
```
✅ Ініціалізація завершена
Структура папок створена успішно
```

Якщо бачите помилку - перевірте права доступу до папок.

---

## Крок 5: Тест з'єднання з Telegram API

### 5.1 Перевірте токен бота

У браузері вставте:

```
https://xain.in.ua/api/telegram_setup.php?password=MySecurePassword999&action=test
```

**Очікуваний результат:**
```
✅ Тест успішний
З'єднання з Telegram API працює!

Інформація про бота:
ID: 1234567890
Username: @krop_electro_bot
Ім'я: Krop Electro Bot
```

**Якщо бачите помилку:**
- ❌ "Invalid bot token" → перевірте TELEGRAM_BOT_TOKEN в config.php
- ❌ HTTP 401 → токен неправильний
- ❌ HTTP 0 → проблема з інтернет-з'єднанням на сервері

---

## Крок 6: Встановлення Webhook

### 6.1 Встановіть webhook

У браузері вставте:

```
https://xain.in.ua/api/telegram_setup.php?password=MySecurePassword999&action=set_webhook
```

**Очікуваний результат:**
```
✅ Webhook встановлено
Webhook успішно налаштовано!

URL: https://xain.in.ua/api/telegram_webhook.php?secret=ВАШ_SECRET

Тепер бот буде отримувати повідомлення з каналу автоматично.
```

---

## Крок 7: Додавання бота до каналу

Є два варіанти:

### ВАРІАНТ A: Ви адміністратор каналу @SvitloKropyvnytskyiMisto

1. Відкрийте канал в Telegram
2. Натисніть на **назву каналу** вгорі
3. Оберіть **"Адміністратори"**
4. Натисніть **"Додати адміністратора"**
5. Знайдіть вашого бота (наприклад `@krop_electro_bot`)
6. Додайте його
7. **Права:** можна залишити мінімальні (всі галочки вимкнені)
8. Збережіть

### ВАРІАНТ B: Ви НЕ адміністратор каналу (створюємо власний)

1. **Створіть власний канал:**
   - Telegram → Меню → "Новий канал"
   - Назва: "Світло Кропивницький Копія"
   - Тип: Публічний або Приватний

2. **Додайте бота як адміністратора** (як у Варіанті А)

3. **Пересилайте повідомлення:**
   - Відкрийте @SvitloKropyvnytskyiMisto
   - Знайдіть повідомлення з графіком
   - Натисніть "Forward" → оберіть **ваш канал**
   - Бот автоматично обробить переслане повідомлення

---

## Крок 8: Завантаження початкових даних

### 8.1 Завантажте історію повідомлень

У браузері вставте:

```
https://xain.in.ua/api/telegram_setup.php?password=MySecurePassword999&action=fetch
```

**Очікуваний результат:**
```
✅ Графік завантажено
Графік успішно завантажено з Telegram!

Дата: 20.01.2026
Черг: 12
ГАВ: Ні
```

**Якщо бачите "Графік не знайдено":**
- Бот не доданий до каналу як адміністратор
- В каналі немає повідомлень з графіком
- Надішліть тестове повідомлення в канал

---

## Крок 9: Тест нового API

### 9.1 Перевірте API endpoint

У браузері відкрийте:

```
https://xain.in.ua/api/blackout_new.php?queue=1.1
```

**Очікуваний результат (JSON):**
```json
{
  "success": true,
  "queue": "1.1",
  "schedule": "00:00-02:00, 04:00-07:00, ...",
  "emergency_mode": false,
  "updated": 1737369600,
  "source": "telegram"
}
```

### 9.2 Перевірте всі черги

```
https://xain.in.ua/api/blackout_new.php?all=1
```

---

## Крок 10: Переключення фронтенду (коли готовий)

### 10.1 Оновіть index.html

Коли новий API працює стабільно, змініть в `index.html`:

**Було:**
```javascript
let API_URL = `https://xain.in.ua/api/blackout.php?queue=${currentQueue}`;
```

**Стане:**
```javascript
let API_URL = `https://xain.in.ua/api/blackout_new.php?queue=${currentQueue}`;
```

Також оновіть в функції `loadAllQueues()`:

**Було:**
```javascript
const res = await fetch('https://xain.in.ua/api/blackout.php?all=1', ...);
```

**Стане:**
```javascript
const res = await fetch('https://xain.in.ua/api/blackout_new.php?all=1', ...);
```

### 10.2 Оновіть версію

В `index.html` збільшіть версію:
```javascript
const VERSION = '2.0'; // Було 1.35
```

### 10.3 Оновіть CHANGELOG.md

Додайте запис про нову версію.

---

## Крок 11: БЕЗПЕКА (ОБОВ'ЯЗКОВО!)

### 11.1 Видаліть setup скрипт АБО змініть пароль

**Варіант 1 (рекомендовано):** Видаліть файл
```
Видаліть з сервера: api/telegram_setup.php
```

**Варіант 2:** Змініть пароль

Відредагуйте `api/config.php` на сервері:
```php
define('SETUP_PASSWORD', 'NEW_COMPLETELY_DIFFERENT_PASSWORD_789');
```

### 11.2 Перевірте .htaccess

Переконайтесь що на сервері є:
- ✅ `api/.htaccess` (захист config.php)
- ✅ `api/cache/.htaccess` (захист JSON файлів)

---

## Що далі?

### Автоматична робота

Тепер система працює автоматично:

1. **Нове повідомлення в каналі** → Telegram надсилає webhook → `telegram_webhook.php` обробляє → зберігає в `schedules.json`

2. **Користувач відкриває сайт** → фронтенд запитує `blackout_new.php` → читає дані з `schedules.json` → показує графік

3. **Якщо Telegram не працює** → fallback на `kiroe.com.ua` → все працює як раніше

### Моніторинг

Перевіряйте час від часу:
- Логи: `api/logs/api_YYYY-MM-DD.log`
- Дані: `api/cache/schedules.json`
- Webhook статус: `telegram_setup.php?password=PASSWORD&action=info`

---

## Що робити при проблемах?

### Проблема: "Invalid bot token"
- Перевірте токен в config.php
- Токен має бути БЕЗ пробілів
- Формат: `числа:літериЦифри`

### Проблема: "Webhook failed"
- Перевірте чи сервер доступний по HTTPS
- Перевірте чи існує telegram_webhook.php
- Перевірте TELEGRAM_WEBHOOK_SECRET

### Проблема: "No data available"
- Бот не доданий до каналу
- Надішліть тестове повідомлення з графіком
- Перевірте логи: `api/logs/`

### Проблема: API не повертає дані
- Перевірте чи існує `api/cache/schedules.json`
- Перевірте права (755 для папок, 644 для файлів)
- Перевірте логи помилок PHP

---

## Контакти

Якщо щось не виходить:
1. Перевірте логи на сервері
2. Збережіть скріншот помилки
3. Перевірте всі кроки інструкції

**Успіхів! 🚀⚡**
