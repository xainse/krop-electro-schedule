---
name: deploy-api-ftp
description: Deploys PHP API to freehost via verified FTPS from .env, syncs only changed files, deletes obsolete remote files, clears cache, and pulls logs for local analysis. Use when the user asks to deploy API, update server code, sync FTP, clear remote cache, or download production logs.
disable-model-invocation: true
---

# Deploy API (FTPS)

Оновлює PHP API на хостингу. Підключення через **explicit FTPS (порт 21)** з перевіркою сертифіката та шифруванням data channel. Plaintext FTP заборонений; якщо AUTH TLS/сертифікат/PROT P не проходить, це блокер деплою. Креденшали з кореневого `.env`.

## When to use

- деплой / оновлення API на сервері
- синхронізація `api/` з production
- очищення remote cache
- забір логів для аналізу

## Credentials (`.env`)

```env
ftp_host=...
ftp_login=...
ftp_pass=...
ftp_dir=/www.xain.in.ua/api
```

Ніколи не коміть `.env`, `api/config.php`, `.deploy-backup/`.

## Tool

```bash
python3 .cursor/skills/deploy-api-ftp/scripts/ftp_sync.py <command>
```

Команди:

| Command | Purpose |
|---------|---------|
| `status` | Порівняти local `api/` vs remote (MD5) |
| `deploy [--dry-run] [--clear-cache]` | Залити зміни, видалити застаріле |
| `clear-cache [--dry-run]` | Очистити remote `cache/*` data |
| `pull-logs [--days N]` | Забрати логи в `api/logs/` (gitignored) |

## Mandatory workflow

1. Підтвердь, що користувач просить деплой/логи/кеш (не деплой «на всяк випадок»).
2. `status` — покажи що зміниться.
3. Для деплою спочатку `--dry-run`, потім реальний `deploy`.
4. За замовчуванням **не** чистити кеш; додай `--clear-cache` лише якщо користувач просить або після breaking-зміни формату кешу.
5. Після деплою — smoke:
   - `https://xain.in.ua/api/blackout.php?queue=1.1` → JSON 200 або контрольований JSON 503 з `available: false`, якщо актуального графіка немає
   - `.../api/parser.php`, `.../api/config.php`, `.../api/cache/blackout_cache.json` → 403/404
6. Для аналізу: `pull-logs --days 2` (або більше за запитом).

## Deploy rules

**Завантажувати (allowlist):**
`.htaccess`, `blackout.php`, `bootstrap.php`, `response.php`, `parser.php`, `data.php`, `site_fetcher.php`, `telegram_fetcher.php`

**Ніколи не чіпати / не перезаписувати:**
`config.php`, вміст `cache/` (крім явного clear), історичні `logs/*.log`

**Видаляти з production якщо є:**
`blackout_new.php`, `test-cors.php`, `test_emergency_mode.php`, `config.example.php`; невідомі файли лише показувати, не видаляти

**Перед змінами:** скрипт робить приватний backup змінюваних файлів у `.deploy-backup/<timestamp>/`; `config.php` не копіює.

**Публікація:** усі PHP-файли спочатку завантажуються у `.tmp` і перевіряються читанням назад. Далі FTP rename замінює кожен файл; `blackout.php` — останнім. Це атомарність окремого файла, не всього релізу: зміни залежностей повинні бути сумісними зі старим endpoint. При відмові rename або перевірки зупинитися, звірити `status` і backup; не переходити на прямий STOR робочих файлів.

**Після деплою:** гарантує повну заборону HTTP доступу в `cache/.htaccess` і `logs/.htaccess`. Зміни цих файлів також включені в dry-run і backup.

**Перевірка скрипта:** `python3 -m unittest discover -s tests/deploy -v` (без мережі та credentials).

## Cache clear

Видаляє лише data-файли в `cache/`:
`blackout_cache.json`, `schedules.json`, `telegram_messages.json`, `last_source_check.txt`
Не чіпає `.htaccess` і `refresh.lock`: видалення lock під час refresh дозволяє паралельні оновлення на різних inode.

## Safety

- Не деплой frontend / GitHub Pages цим скілом.
- Не лей `tests/`, `node_modules`, `.git`, `.env`.
- Не виводити пароль з `.env` у чат.
- Якщо `status` показує `MISSING_LOCAL` для allowlist-файлу — зупинись.

Деталі сервера: [reference.md](reference.md).
