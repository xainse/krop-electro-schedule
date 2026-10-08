# Розгортання

Frontend: https://xainse.github.io/krop-electro-schedule/ (GitHub Pages).
PHP API: https://xain.in.ua/api/blackout.php.

Перед публікацією запустіть Jest, PHPUnit та Playwright за README. Змініть версію й changelog до коміту. Production API і frontend публікуються окремо.

## API сервер

1. Збережіть резервну копію поточних PHP файлів і `.htaccess` поза document root.
2. Завантажте `api/bootstrap.php`, `response.php`, `parser.php`, `data.php`, `site_fetcher.php`, `telegram_fetcher.php`, потім `blackout.php` і `api/.htaccess` у document root сайту xain.in.ua зі збереженням `api/`.
3. Збережіть існуючий приватний `api/config.php`. Не завантажуйте приклади, тести, Git metadata, залежності, локальні cache/logs.
4. Apache має дозволяти `.htaccess`, `Require` та `mod_rewrite`. Для Nginx налаштуйте аналогічну заборону `api/cache`, `api/logs` і всіх PHP модулів, окрім `blackout.php`, у конфігурації сервера.
5. Перевірте сертифікати системного CA bundle. TLS-перевірку джерел не вимикайте.
6. Дочекайтеся наступної дозволеної перевірки джерел (до 5 хвилин). Старий кеш без `verified_at` не відображається як перевірений.

## Frontend

Після успішних тестів і перевірки API запуште зміни у `main`, звідки публікується GitHub Pages. Перевірте фактичний deployment Pages і видиму версію 4.1 після оновлення сторінки.

## Smoke перевірки

- `?all=1`: JSON; `queues` — об'єкт; `date`, `updated`, `stale`, `available`, `source` узгоджені.
- Усі 12 `?queue=X.X`: відсутня черга → null, явне повідомлення про відсутність відключень → порожній рядок.
- `?queue[]=1.1`, `?queue=99.99`: JSON 400; POST: JSON 405; OPTIONS: 204, один CORS заголовок.
- `api/cache/blackout_cache.json`, `api/logs/`, `api/config.php`, `api/parser.php`, `api/test-cors.php`: 403/404.
- Прострочене джерело → «Актуальний графік відсутній», сірі комірки, невідомі підсумки.
- Перемикання черг, оновлення, ГАВ, відмова API та мобільна ширина 375 px.

Якщо smoke перевірка не пройшла, відновіть попередні PHP файли та `.htaccess` із резервної копії. Не відновлюйте застарілий кеш як перевірені дані. Адреса FTP/SFTP, шлях document root і спосіб автентифікації повинні бути визначені перед деплоєм; у репозиторії секретів немає.
