#!/usr/bin/env python3
"""Verified FTPS sync for electro-scheduler API. Credentials from repo-root .env."""

from __future__ import annotations

import argparse
import hashlib
import io
import posixpath
import uuid
import sys
import ssl
from datetime import date, datetime, timedelta
from pathlib import Path
from typing import Iterable

import ftplib

REPO_ROOT = Path(__file__).resolve().parents[4]
LOCAL_API = REPO_ROOT / "api"
BACKUP_ROOT = REPO_ROOT / ".deploy-backup"
LOCAL_LOGS = LOCAL_API / "logs"

# Production PHP/modules + root .htaccess (never include config.php).
DEPLOY_FILES = (
    ".htaccess",
    "bootstrap.php",
    "security.php",
    "http.php",
    "response.php",
    "parser.php",
    "data.php",
    "site_fetcher.php",
    "telegram_fetcher.php",
    "blackout.php",  # Publish the entrypoint after its dependencies.
)

# Keep on server even if absent locally / not in DEPLOY_FILES.
PRESERVE_REMOTE = {
    "config.php",
    "cache",
    "logs",
    ".",
    "..",
}

# Always remove from production if present.
OBSOLETE_REMOTE = {
    "blackout_new.php",
    "test-cors.php",
    "test_emergency_mode.php",
    "config.example.php",
}

CACHE_CLEAR_FILES = {
    "blackout_cache.json",
    "schedules.json",
    "telegram_messages.json",
    "last_source_check.txt",
}

CACHE_HTACCESS = "Require all denied\n"

LOGS_HTACCESS = "Require all denied\n"


def load_env(path: Path) -> dict[str, str]:
    if not path.is_file():
        raise SystemExit(f"Missing {path}. Create it with ftp_host, ftp_login, ftp_pass, ftp_dir.")
    path.chmod(0o600)
    out: dict[str, str] = {}
    for raw in path.read_text(encoding="utf-8").splitlines():
        line = raw.strip()
        if not line or line.startswith("#") or "=" not in line:
            continue
        key, value = line.split("=", 1)
        value = value.strip()
        if len(value) >= 2 and value[0] == value[-1] and value[0] in ("\"", "'"):
            value = value[1:-1]
        out[key.strip()] = value
    required = ("ftp_host", "ftp_login", "ftp_pass", "ftp_dir")
    missing = [k for k in required if not out.get(k)]
    if missing:
        raise SystemExit(f".env missing keys: {', '.join(missing)}")
    return out


def md5_bytes(data: bytes) -> str:
    return hashlib.md5(data).hexdigest()


def connect(env: dict[str, str]) -> ftplib.FTP:
    if env["ftp_dir"].rstrip("/") != "/www.xain.in.ua/api":
        raise ValueError("Refusing to sync outside /www.xain.in.ua/api")
    context = ssl.create_default_context()
    context.minimum_version = ssl.TLSVersion.TLSv1_2
    ftp = ftplib.FTP_TLS(context=context)
    try:
        ftp.connect(env["ftp_host"], 21, timeout=20)
        # Negotiate and verify TLS before sending any credentials.
        ftp.auth()
        ftp.login(env["ftp_login"], env["ftp_pass"])
        ftp.prot_p()  # Encrypt listings and all file transfers too.
        ftp.cwd(env["ftp_dir"])
        return ftp
    except Exception:
        ftp.close()
        raise  # Never retry via plaintext FTP or with certificate checks disabled.


def nlst(ftp: ftplib.FTP) -> set[str]:
    names = set()
    current = ftp.pwd().rstrip("/")
    for entry in ftp.nlst():
        if entry in (".", ".."):
            continue
        if any(c in entry for c in ("\r", "\n", "\\", "\0")):
            raise ValueError("Unsafe FTP directory entry")
        if "/" in entry:
            if posixpath.dirname(entry) not in (".", current):
                raise ValueError("FTP listing contains an unexpected directory")
            entry = posixpath.basename(entry)
        if entry in ("", ".", ".."):
            raise ValueError("Unsafe FTP directory entry")
        names.add(entry)
    return names


def retr(ftp: ftplib.FTP, name: str) -> bytes:
    buf = io.BytesIO()
    ftp.retrbinary(f"RETR {name}", buf.write)
    return buf.getvalue()


def stor(ftp: ftplib.FTP, name: str, data: bytes) -> None:
    ftp.storbinary(f"STOR {name}", io.BytesIO(data))


def stage_upload(ftp: ftplib.FTP, name: str, data: bytes) -> str:
    # .tmp is denied by production .htaccess. Never truncate the live PHP file.
    staged = f".deploy-{uuid.uuid4().hex}.tmp"
    try:
        stor(ftp, staged, data)
        if retr(ftp, staged) != data:
            raise OSError(f"Upload verification failed: {name}")
    except Exception:
        try:
            ftp.delete(staged)
        except ftplib.all_errors:
            pass
        raise
    return staged


def publish(ftp: ftplib.FTP, staged: str, name: str, data: bytes) -> None:
    # If overwrite-rename is unsupported, stop; never fall back to delete + STOR.
    ftp.rename(staged, name)
    if retr(ftp, name) != data:
        raise OSError(f"Published file verification failed: {name}")


def ensure_dir(ftp: ftplib.FTP, remote_dir: str, api_dir: str) -> None:
    ftp.cwd(api_dir)
    if remote_dir not in nlst(ftp):
        ftp.mkd(remote_dir)


def cmd_status(ftp: ftplib.FTP, env: dict[str, str]) -> int:
    ftp.cwd(env["ftp_dir"])
    remote = nlst(ftp)
    print(f"Remote: {env['ftp_host']}{env['ftp_dir']}")
    print(f"Local:  {LOCAL_API}")
    print()
    print(f"{'STATUS':14} {'FILE':28} LOCAL  REMOTE")
    missing = False
    for name in DEPLOY_FILES:
        local_path = LOCAL_API / name
        if not local_path.is_file():
            print(f"{'MISSING_LOCAL':14} {name:28}")
            missing = True
            continue
        local = local_path.read_bytes()
        if name not in remote:
            print(f"{'UPLOAD':14} {name:28} {len(local):6d}      -")
            continue
        remote_data = retr(ftp, name)
        if md5_bytes(local) == md5_bytes(remote_data):
            print(f"{'OK':14} {name:28} {len(local):6d} {len(remote_data):6d}")
        else:
            print(f"{'DIFF':14} {name:28} {len(local):6d} {len(remote_data):6d}")

    junk = sorted((remote - PRESERVE_REMOTE) - set(DEPLOY_FILES))
    if junk:
        print("\nRemote extras (only OBSOLETE will be deleted):")
        for name in junk:
            mark = "OBSOLETE" if name in OBSOLETE_REMOTE else "PRESERVED"
            print(f"  [{mark}] {name}")
    return 1 if missing else 0


def backup_remote(ftp: ftplib.FTP, env: dict[str, str], names: Iterable[str]) -> Path:
    stamp = datetime.now().strftime("%Y%m%d-%H%M%S-%f")
    dest = BACKUP_ROOT / stamp
    BACKUP_ROOT.mkdir(mode=0o700, parents=True, exist_ok=True)
    dest.mkdir(mode=0o700)
    ftp.cwd(env["ftp_dir"])
    remote = nlst(ftp)
    for name in names:
        if name not in remote:
            continue
        data = retr(ftp, name)
        with (dest / name).open("xb") as handle:
            (dest / name).chmod(0o600)
            handle.write(data)
        print(f"backup {name} ({len(data)} bytes)")
    print(f"BACKUP_DIR {dest}")
    return dest


def cmd_deploy(ftp: ftplib.FTP, env: dict[str, str], *, dry_run: bool, clear_cache: bool) -> int:
    ftp.cwd(env["ftp_dir"])
    remote = nlst(ftp)

    uploads: list[str] = []
    for name in DEPLOY_FILES:
        path = LOCAL_API / name
        if not path.is_file():
            raise SystemExit(f"Local file missing: {path}")
        local = path.read_bytes()
        if name not in remote:
            uploads.append(name)
            continue
        if md5_bytes(local) != md5_bytes(retr(ftp, name)):
            uploads.append(name)

    deletes = sorted(remote & OBSOLETE_REMOTE)
    protections = []
    for directory in ("cache", "logs"):
        ftp.cwd(env["ftp_dir"])
        existing = None
        if directory in remote:
            ftp.cwd(env["ftp_dir"] + "/" + directory)
            if ".htaccess" in nlst(ftp):
                existing = retr(ftp, ".htaccess")
        if existing != CACHE_HTACCESS.encode("utf-8"):
            protections.append((directory, existing))
    ftp.cwd(env["ftp_dir"])

    print("Plan:")
    print(f"  upload ({len(uploads)}): {', '.join(uploads) or '—'}")
    print(f"  delete ({len(deletes)}): {', '.join(deletes) or '—'}")
    print(f"  protections: {', '.join(d for d, _ in protections) or '—'}")
    print(f"  clear_cache: {clear_cache}")
    if dry_run:
        print("Dry-run only; no changes.")
        return 0

    if not uploads and not deletes and not protections and not clear_cache:
        print("Already in sync; no changes.")
        return 0
    backup_names = sorted(set(uploads) | set(deletes))
    backup = backup_remote(ftp, env, backup_names)
    for directory, previous in protections:
        if previous is not None:
            target = backup / directory
            target.mkdir(mode=0o700)
            (target / ".htaccess").write_bytes(previous)
            (target / ".htaccess").chmod(0o600)

    # All uploads must finish and verify before any live code is replaced.
    staged = []
    try:
        ftp.cwd(env["ftp_dir"])
        for name in uploads:
            data = (LOCAL_API / name).read_bytes()
            staged.append((stage_upload(ftp, name, data), name, data))
        for temporary, name, data in staged:
            publish(ftp, temporary, name, data)
            print(f"uploaded and verified {name} ({len(data)} bytes)")
        for directory, _ in protections:
            ensure_dir(ftp, directory, env["ftp_dir"])
            ftp.cwd(env["ftp_dir"] + "/" + directory)
            data = CACHE_HTACCESS.encode("utf-8")
            temporary = stage_upload(ftp, ".htaccess", data)
            publish(ftp, temporary, ".htaccess", data)
            print(f"protected {directory}/.htaccess")
        ftp.cwd(env["ftp_dir"])
        for name in deletes:
            ftp.delete(name)  # Failure must produce a nonzero exit status.
            print(f"deleted {name}")
    finally:
        ftp.cwd(env["ftp_dir"])
        for temporary, _, _ in staged:
            try:
                ftp.delete(temporary)
            except ftplib.error_perm:
                pass

    if clear_cache:
        clear_remote_cache(ftp, env)

    print("Deploy done.")
    return 0


def clear_remote_cache(ftp: ftplib.FTP, env: dict[str, str]) -> None:
    ftp.cwd(env["ftp_dir"] + "/cache")
    remote = nlst(ftp)
    for name in sorted(CACHE_CLEAR_FILES & remote):
        ftp.delete(name)
        print(f"cache cleared: {name}")


def cmd_clear_cache(ftp: ftplib.FTP, env: dict[str, str], *, dry_run: bool) -> int:
    ftp.cwd(env["ftp_dir"] + "/cache")
    remote = nlst(ftp)
    targets = sorted(CACHE_CLEAR_FILES & remote)
    print("Cache clear plan:", ", ".join(targets) or "—")
    if dry_run:
        return 0
    for name in targets:
        ftp.delete(name)
        print(f"deleted cache/{name}")
    return 0


def cmd_pull_logs(ftp: ftplib.FTP, env: dict[str, str], days: int) -> int:
    LOCAL_LOGS.mkdir(mode=0o700, parents=True, exist_ok=True)
    LOCAL_LOGS.chmod(0o700)
    ftp.cwd(env["ftp_dir"] + "/logs")
    remote = nlst(ftp)
    wanted_dates = {
        (date.today() - timedelta(days=i)).isoformat() for i in range(max(days, 1))
    }
    pulled = []
    for name in sorted(remote):
        if not name.endswith(".log"):
            continue
        if any(d in name for d in wanted_dates):
            if Path(name).name != name or name.startswith('.'):
                raise ValueError('Unsafe remote log filename')
            data = retr(ftp, name)
            (LOCAL_LOGS / name).write_bytes(data)
            (LOCAL_LOGS / name).chmod(0o600)
            pulled.append((name, len(data)))
            print(f"pulled {name} ({len(data)} bytes)")
    if not pulled:
        print("No matching log files for requested dates.")
    else:
        print(f"Saved under {LOCAL_LOGS}")
    return 0


def main(argv: list[str] | None = None) -> int:
    parser = argparse.ArgumentParser(description="FTP deploy/sync for api/")
    parser.add_argument(
        "--env",
        default=str(REPO_ROOT / ".env"),
        help="Path to .env (default: repo root)",
    )
    sub = parser.add_subparsers(dest="cmd", required=True)

    sub.add_parser("status", help="Compare local api/ vs remote")

    p_deploy = sub.add_parser("deploy", help="Upload changed files, delete obsolete")
    p_deploy.add_argument("--dry-run", action="store_true")
    p_deploy.add_argument(
        "--clear-cache",
        action="store_true",
        help="Clear remote cache/*.json etc after deploy",
    )

    p_cache = sub.add_parser("clear-cache", help="Clear remote api/cache data files")
    p_cache.add_argument("--dry-run", action="store_true")

    p_logs = sub.add_parser("pull-logs", help="Download recent remote logs")
    p_logs.add_argument("--days", type=int, default=2, help="How many days back (default 2)")

    args = parser.parse_args(argv)
    env = load_env(Path(args.env))

    # Explicit FTPS on port 21; a host without verified TLS is a deployment blocker.
    ftp = connect(env)
    try:
        if args.cmd == "status":
            return cmd_status(ftp, env)
        if args.cmd == "deploy":
            return cmd_deploy(ftp, env, dry_run=args.dry_run, clear_cache=args.clear_cache)
        if args.cmd == "clear-cache":
            return cmd_clear_cache(ftp, env, dry_run=args.dry_run)
        if args.cmd == "pull-logs":
            return cmd_pull_logs(ftp, env, days=args.days)
        raise SystemExit(f"Unknown command: {args.cmd}")
    finally:
        try:
            ftp.quit()
        except Exception:
            ftp.close()


if __name__ == "__main__":
    sys.exit(main())
