# ⚡ Графік відключень електрики

[![GitHub stars](https://img.shields.io/github/stars/xainse/krop-electro-schedule)](https://github.com/xainse/krop-electro-schedule/stargazers)
[![GitHub forks](https://img.shields.io/github/forks/xainse/krop-electro-schedule)](https://github.com/xainse/krop-electro-schedule/network/members)
[![GitHub last commit](https://img.shields.io/github/last-commit/xainse/krop-electro-schedule)](https://github.com/xainse/krop-electro-schedule/commits/main)
[![GitHub language count](https://img.shields.io/github/languages/count/xainse/krop-electro-schedule)](https://github.com/xainse/krop-electro-schedule)
[![GitHub top language](https://img.shields.io/github/languages/top/xainse/krop-electro-schedule)](https://github.com/xainse/krop-electro-schedule)
[![Tests](https://img.shields.io/badge/tests-Jest%20%2B%20PHPUnit-blue)](https://github.com/xainse/krop-electro-schedule)

Веб-додаток для відображення графіку відключень електрики на 24 години з підтримкою всіх черг (1.1-6.2).

## 📋 Опис

Цей проєкт показує візуальний графік стану електрики протягом доби з півгодинною точністю. Кожна півгодина відображається окремою клітинкою:
- ⚡ **Жовта клітинка** — електрика є
- 🌑 **Темна клітинка** — електрика відключена

## 🚀 Функціонал

- 📊 Візуалізація графіку на 24 години з півгодинною точністю
- 🔄 Автоматичне оновлення даних кожні 10 хвилин
- 📋 Вибір черги (1.1, 1.2, 2.1, 2.2, 3.1, 3.2, 4.1, 4.2, 5.1, 5.2, 6.1, 6.2)
- 💾 Кешування даних на 10 хвилин для зменшення навантаження
- 📝 Логування всіх запитів до API
- 📱 Адаптивний дизайн для мобільних пристроїв
- ♿ Підтримка доступності (screen readers)

## 🛠 Технології

- Чистий HTML/CSS/JavaScript
- PHP API для парсингу даних з kiroe.com.ua
- Fetch API для отримання даних
- Кешування та логування на сервері

## 📡 API

Додаток отримує дані з API:
```
https://xain.in.ua/api/blackout.php?queue=X.X
```

API використовує каскадний fallback:
1. **JSON кеш** — швидка відповідь із перевіркою дати та часу отримання
2. **Telegram** — перевірка відкритого каналу після закінчення TTL кешу
3. **Сайт kiroe.com.ua** - резервне джерело якщо Telegram недоступний

Кеш відповіді чинний 10 хвилин. Невдалі перевірки джерел повторюються не частіше ніж раз на 5 хвилин. Дата графіка визначається за Europe/Kyiv; минулі та майбутні графіки не відображаються як сьогоднішні. Логи зберігаються 30 днів.

Формат відповіді: JSON з полем `schedule`, що містить діапазони часу відключення:
```json
{
  "schedule": "02:00-04:00, 06:00-08:00, 10:00-11:30, 14:00-16:00"
}
```

## 🎨 Використання

1. Запустіть `php -S 127.0.0.1:8080` у корені та відкрийте `http://127.0.0.1:8080/`
2. Виберіть чергу з випадаючого списку
3. Додаток автоматично завантажить дані з API
4. Дані оновлюються автоматично кожні 10 хвилин

## 📝 Версія

Поточна версія: **4.1**

## 📚 Документація

Детальна документація знаходиться в папці [`docs/`](docs/):

- **[Історія змін](docs/CHANGELOG.md)** - Всі зміни в проєкті

## 🔗 Посилання

- [Джерело даних (сайт)](https://kiroe.com.ua/electricity-blackout)
- [Telegram канал](https://t.me/SvitloKropyvnytskyiMisto)

## 📄 Ліцензія

Вільне використання.


## Контракт актуальності API

`GET /api/blackout.php?queue=1.1` або `?all=1`. Підтримуються черги 1.1–6.2 та методи GET/OPTIONS. Некоректні параметри повертають JSON 400, інші методи — 405, відсутність кешу й джерел — 503.

- `date`: дата графіка DD.MM.YYYY.
- `updated`: Unix timestamp останньої успішної перевірки цього графіка в джерелі, або null для неперевіреного старого кешу.
- `stale`: перевірка прострочена або дата не сьогоднішня.
- `available`: є перевірений графік для сьогоднішньої дати й запитаної черги.
- `schedule: null`: немає даних; `schedule: ""`: джерело явно повідомило про відсутність планових відключень.
- `emergency_mode`: true/false/null; null означає непідтверджений стан ГАВ.
- `source`: фактичне джерело перевірки.

Публічні `force_refresh` і `test_emergency` більше не змінюють стан або частоту перевірок. Frontend зберігає кеш окремо для черги, дати й API; старі записи без цих ознак не використовує.

## Встановлення та перевірка

Потрібні PHP 8.3+ з DOM, mbstring, cURL/OpenSSL, Node.js 22+ і Composer. API читає відкритий Telegram канал без bot token. `api/config.php` необов'язковий; зразок overrides — `api/config.example.php`. PHP повинен мати доступ до запису в runtime каталоги `api/cache` та `api/logs`.

```sh
cd tests/backend
composer install
vendor/bin/phpunit
cd ../frontend
npm ci
npm test -- --runInBand
npm run test:e2e
```

Локально Playwright використовує встановлений Chrome; CI — Chromium. E2E використовують локальні фікстури й не залежать від стану live API. Якщо сервер на 8080 запущений окремо, встановіть `KROP_E2E_EXTERNAL_SERVER=1`.

Деплой та перевірки після нього: [docs/DEPLOYMENT.md](docs/DEPLOYMENT.md).
