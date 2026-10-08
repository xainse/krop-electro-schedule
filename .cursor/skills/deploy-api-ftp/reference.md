# Remote host reference

## Connection

| Fact | Value |
|------|-------|
| Protocol | **FTP** port 21 (plain). Port 22/SFTP closed. |
| Host | from `.env` `ftp_host` (freehost `ftp.s61...`) |
| Remote API dir | `.env` `ftp_dir` → `/www.xain.in.ua/api` |
| Public URL | `https://xain.in.ua/api/blackout.php` |

Frontend is on GitHub Pages, not this FTP host. Site root `/www.xain.in.ua/` also has unrelated personal site files — **never sync the whole site**, only `api/`.

## Expected remote layout

```
/www.xain.in.ua/api/
  .htaccess              # deny non-blackout PHP + cache/logs
  blackout.php           # only public entrypoint
  bootstrap.php
  response.php
  parser.php
  data.php
  site_fetcher.php
  telegram_fetcher.php
  config.php             # private; preserve forever
  cache/
    .htaccess
    *.json / locks       # runtime; optional clear
  logs/
    .htaccess
    *.log                # pull for analysis
```

## Audit notes (2026-10-08)

- Production PHP was older than local (missing `bootstrap.php`, `response.php`).
- Obsolete remote file: `blackout_new.php`.
- Dev leftovers on remote: `test-cors.php`, `test_emergency_mode.php`, `config.example.php`.
- `config.php` matched local MD5 — keep as source of truth on server.
- `logs/.htaccess` was missing once; deploy/ensure recreates it.
- Live smoke (before full redeploy): `blackout.php?queue=1.1` → 200; `parser.php` / `config.php` / `cache/*.json` → 403.

## Log names

Remote historically uses:
- `blackout_YYYY-MM-DD.log`
- `source_content_YYYY-MM-DD.log`

Local/newer code may also write `api_YYYY-MM-DD.log`. `pull-logs` matches by date substring.

## Rollback

1. Find latest folder in `.deploy-backup/`.
2. Manually STOR needed files back via FTP, or restore from that backup directory with a one-off script.
3. Do **not** restore stale cache as “verified” data after a format change — clear cache instead.
