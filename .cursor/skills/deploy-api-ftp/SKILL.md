---
name: deploy-api-ftp
description: Deploys PHP API to freehost via FTP from .env, syncs only changed files, deletes obsolete remote files, clears cache, and pulls logs for local analysis. Use when the user asks to deploy API, update server code, sync FTP, clear remote cache, or download production logs.
disable-model-invocation: true
---

# Deploy API (FTP)

Оновлює PHP API на хостингу. Підключення лише через **FTP (порт 21)** — SFTP на цьому хості недоступний. Креденшали з кореневого `.env`.

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
   - `https://xain.in.ua/api/blackout.php?queue=1.1` → JSON 200
   - `.../api/parser.php`, `.../api/config.php`, `.../api/cache/blackout_cache.json` → 403/404
6. Для аналізу: `pull-logs --days 2` (або більше за запитом).

## Deploy rules

**Завантажувати (allowlist):**
`.htaccess`, `blackout.php`, `bootstrap.php`, `response.php`, `parser.php`, `data.php`, `site_fetcher.php`, `telegram_fetcher.php`

**Ніколи не чіпати / не перезаписувати:**
`config.php`, вміст `cache/` (крім явного clear), історичні `logs/*.log`

**Видаляти з production якщо є:**
`blackout_new.php`, `test-cors.php`, `test_emergency_mode.php`, `config.example.php`, будь-який інший файл у `api/`, якого немає в allowlist і який не `config.php`/`cache`/`logs`

**Перед змінами:** скрипт робить backup у `.deploy-backup/<timestamp>/`.

**Після деплою:** гарантує `cache/.htaccess` і `logs/.htaccess`.

## Cache clear

Видаляє лише data-файли в `cache/`:
`blackout_cache.json`, `schedules.json`, `telegram_messages.json`, `last_source_check.txt`, `refresh.lock`  
Не чіпає `.htaccess`.

## Safety

- Не деплой frontend / GitHub Pages цим скілом.
- Не лей `tests/`, `node_modules`, `.git`, `.env`.
- Не виводити пароль з `.env` у чат.
- Якщо `status` показує `MISSING_LOCAL` для allowlist-файлу — зупинись.

Деталі сервера: [reference.md](reference.md).
