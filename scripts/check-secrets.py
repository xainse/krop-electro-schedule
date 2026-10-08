#!/usr/bin/env python3
"""Targeted tracked-file secret guard; prints locations, never matched values.

Complements GitHub secret scanning; does not claim to recognize every credential.
"""
import pathlib
import re
import subprocess
import sys

ROOT = pathlib.Path(__file__).resolve().parents[1]
PATTERNS = [
    re.compile(rb"-----BEGIN (?:RSA |EC |OPENSSH |DSA )?PRIVATE KEY-----"),
    re.compile(rb"\bgh[pousr]_[A-Za-z0-9]{36,}\b"),
    re.compile(rb"\bgithub_pat_[A-Za-z0-9_]{60,}\b"),
    re.compile(rb"\bAKIA[A-Z0-9]{16}\b"),
    re.compile(rb"\b[0-9]{8,12}:[A-Za-z0-9_-]{35}\b"),
]

def main():
    names = subprocess.check_output(['git', 'ls-files', '-z'], cwd=ROOT).split(b'\0')
    failures = []
    for raw in names:
        if not raw:
            continue
        name = raw.decode('utf-8')
        path = ROOT / name
        if path.name == '.env' or name == 'api/config.php' or name.startswith('.deploy-backup/'):
            failures.append(f'{name}: private file is tracked')
        if not path.is_file() or path.is_symlink():
            continue
        data = path.read_bytes()
        for pattern in PATTERNS:
            match = pattern.search(data)
            if match:
                line = data[:match.start()].count(b'\n') + 1
                failures.append(f'{name}:{line}: possible secret (value redacted)')
                break
    print('\n'.join(failures) if failures else 'Tracked-file secret guard passed.')
    return bool(failures)

if __name__ == '__main__':
    sys.exit(main())
