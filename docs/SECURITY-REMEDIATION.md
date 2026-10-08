# Security remediation — 2026-10-08

Репозиторій: https://github.com/xainse/krop-electro-schedule

Цей документ описує пакет виправлень v4.6. Проходження тестів не є підтвердженням деплою або успішного production audit.

| Issue | Зміни | Що залишається |
|---|---|---|
| [#19](https://github.com/xainse/krop-electro-schedule/issues/19) | FTPS, перевірка сертифіката, TLS ≥1.2, PROT P, жодного plaintext fallback; коміт `ebe1d42` | Перевірити FTPS хостингу та змінити раніше використаний FTP-пароль через панель |
| [#20](https://github.com/xainse/krop-electro-schedule/issues/20) | Production API зафіксовано на `https://xain.in.ua`; debug допускається лише на тому самому loopback origin | Деплой |
| [#21](https://github.com/xainse/krop-electro-schedule/issues/21) | Відсутність графіка вимагає явного датованого повідомлення; збій парсера зберігає невизначеність; legacy-маркери кешу відкидаються | Перевірка джерел після деплою |
| [#22](https://github.com/xainse/krop-electro-schedule/issues/22) | Ліміт запитів, bounded logs, вилучення IP/User-Agent з логів | Налаштування лімітів вебсервера та перевірка production UID/проксі |
| [#23](https://github.com/xainse/krop-electro-schedule/issues/23) | HTTPS allowlist, перевірка й фіксація public IPv4, обмежені redirects/body/час | Перевірка cURL, DNS і доступності джерел на сервері |
| [#24](https://github.com/xainse/krop-electro-schedule/issues/24) | Оновлено 9 транзитивних npm-залежностей | Дві невиправлені upstream advisories; npm повідомляє 34 вразливі пакети з урахуванням транзитивних шляхів |
| [#25](https://github.com/xainse/krop-electro-schedule/issues/25) | CSP hashes для inline JS, дозволені origins, заголовки PHP API | Деплой і заголовки самого frontend-хостингу |
| [#26](https://github.com/xainse/krop-electro-schedule/issues/26) | Actions pinned SHA, npm/Composer audits, Dependabot, targeted secret guard, CSP check | Увімкнути required checks і публікацію лише після CI у налаштуваннях репозиторію/Pages |
| [#27](https://github.com/xainse/krop-electro-schedule/issues/27) | Локальні secrets `0600`, runtime `0700`, приватні нові файли, підтримка зовнішнього runtime/config | Перенесення і права на production-хостингу |
| [#28](https://github.com/xainse/krop-electro-schedule/issues/28) | Відтворюваний скрипт HTTP smoke | Запуск із доступної мережі та перевірки панелі хостингу |

## Межі захисту

- Rate limiter: 60 валідних GET за хвилину на `REMOTE_ADDR`, 600 загалом, одна фіксована хвилина. Підроблений `X-Forwarded-For` ігнорується. При недоступному/зайнятому сховищі — 429 з Retry-After. За reverse proxy потрібно забезпечити довірене відновлення адреси на рівні сервера; інакше всі клієнти ділять одну квоту. Цей PHP-ліміт не захищає від об'ємних мережевих атак, OPTIONS/invalid-input floods чи доступу до інших URL. Потрібні також connection/request limits хостингу.
- Нові записи логів: до 2 MiB на файл, до 20 MiB сумарно, retention 30 днів; переповнені записи пропускаються. Історичні файли, що вже перевищують ліміт, треба перевірити/архівувати окремо. IP і User-Agent більше не записуються.
- Upstream: тільки `t.me`, `kiroe.com.ua`, `www.kiroe.com.ua`, HTTPS:443, максимум 3 redirects, 2 MiB розпакованого тіла сумарно, 32 KiB headers на відповідь і бюджет 4 секунди на ланцюжок. IPv6-only джерела не підтримуються. Системний DNS resolver може перевищити цей бюджет до повернення керування: на хостингу потрібен обмежений DNS timeout. cURL обов'язковий, streams fallback відсутній.
- CSP не дозволяє довільний inline JavaScript або eval. Google Analytics залишається довіреною зовнішньою script-залежністю. `style-src 'unsafe-inline'` потрібний для наявних стилів. Після кожної зміни inline JS або VERSION виконати `node scripts/update-csp.js`.
- Meta CSP не забезпечує `frame-ancestors` для HTML. Для frontend потрібний HTTP header від хостингу/CDN. Заголовок API вже додано в код. HSTS слід налаштувати й перевірити на рівні HTTPS-хостингу; shared-domain includeSubDomains без інвентаризації домену не додавати.
- `scripts/check-secrets.py` перевіряє лише tracked files та відомі формати, не є універсальним детектором. Увімкнути GitHub secret scanning/push protection і перевірити історію при появі сигналу витоку.

## Залежності

Реальний online audit під час `npm install --ignore-scripts` після оновлення: **34 (29 high, 5 moderate)**. Причини: [braces GHSA-vfj7-8cjw-p6xm](https://github.com/advisories/GHSA-vfj7-8cjw-p6xm) і [sprintf-js GHSA-hp3w-g68c-fv3c](https://github.com/advisories/GHSA-hp3w-g68c-fv3c). Перевірені registry-релізи 3.0.3 і 1.1.3 відповідно залишаються вразливими. Залежності використовуються тестами, не включаються в production PHP/статичний frontend. CI audit навмисно не приховує цей результат і наразі буде червоним; потрібні upstream patches або перевірена міграція тестового стеку. Offline audit не приймається як доказ відсутності вразливостей.

## Production перевірка та міграція

1. На сервері підтвердити підтримувану security-updated PHP-версію, cURL/DOM/mbstring, `display_errors=Off`, `expose_php=Off`, HTTPS redirect і TLS 1.2/1.3, HSTS для власного host. Не створювати публічний phpinfo.
2. Перевірити FTPS командою `python3 .cursor/skills/deploy-api-ftp/scripts/ftp_sync.py status`. Помилка TLS/DNS не дозволяє повертатися до plaintext FTP. Після переходу змінити FTP-пароль у панелі та приватному `.env`.
3. Поза webroot створити runtime directory з власником PHP service user (`0700`), приватний config (`0600`), встановити серверні `KROP_RUNTIME_DIR` та `KROP_CONFIG_FILE`. Прибрати старі CACHE_DIR/LOGS_DIR overrides або привести їх до тих самих шляхів. Локальні тести не підтверджують server UID і можливість такого перенесення.
4. Залишити старий кеш як backup до перевірки. В новому runtime почати з порожнього кешу, щоб не копіювати активні lock-файли. Зовнішній runtime не обслуговується командою FTPS clear-cache/pull-logs: вона працює з legacy `api/cache` і `api/logs`.
5. Після проходження всіх тестів виконати FTPS dry-run/deploy за skill. API entrypoint публікується після модулів. Загальний багатофайловий реліз не є атомарним; потрібне коротке контрольоване вікно змін або release-directory switch хостингу.
6. Запустити `python3 scripts/security-smoke.py`: статуси 400/405/204, CORS без credentials, JSON freshness, HTTP→HTTPS, CSP, закриті private URL перевіряються без завантаження їх тіл. Публічні запити не змінюють дані користувачів, але можуть спричинити звичайне оновлення кешу API. Це не навантажувальний тест і не заміна server-side audit.
7. У GitHub зробити `Tests / verify` та `Tests / dependencies` required checks; заборонити обхід для релізів. Pages, що публікує прямо з branch, не чекає цих checks: потрібен workflow deploy з `needs` або окрема release branch, що оновлюється лише після checks. Поточні налаштування не змінені та не підтверджені.

## Перевірки локального пакета

- Jest: 90 tests PASS.
- PHPUnit: 95 tests / 269 assertions PASS.
- Deployment unittest: 13 PASS.
- CSP hash check, targeted secret guard, git diff whitespace check: PASS.
- Playwright: **14 passed (8.1s)** — результат запуску користувачем у локальному терміналі, підтверджений у чаті. Попередній запуск усередині agent sandbox був заблокований до assertions (SIGABRT/EPERM).
- Production smoke / FTPS status: мережеві обмеження цього середовища, результат не підтверджено.

Вимогу успішного E2E для коміту виконано. FTPS status повторно зупинився на DNS resolution до login; серверні файли не змінено. Production acceptance залишається окремим незавершеним кроком.
