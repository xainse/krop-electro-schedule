# Remote host reference

## Connection

| Fact | Value |
|------|-------|
| Protocol | **Explicit FTPS** port 21 with verified TLS and PROT P. Host support requires a successful status probe; never fall back to plaintext. |
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

## Transport migration

Run `status` to verify FTPS support without changing remote files. A DNS/network failure does not prove that FTPS is unsupported. After the first verified secure connection, rotate the previously used FTP password through the hosting control panel and keep the account scoped to the API directory. Never print credentials or disable certificate validation.

## Rollback

1. Find latest folder in `.deploy-backup/`.
2. Restore needed code files through verified temporary uploads and FTP rename, dependencies before the entrypoint. Do not truncate live PHP with direct STOR. A multi-file release is not transactional; inspect status after a failed deployment.
3. Do **not** restore stale cache as “verified” data after a format change — clear cache instead.
