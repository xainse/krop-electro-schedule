"""Exercise deployment failures without a network connection or credentials."""
import contextlib
import ftplib
import importlib.util
import io
from pathlib import Path
import tempfile
import unittest
from unittest.mock import patch

SCRIPT = Path(__file__).resolve().parents[2] / '.cursor/skills/deploy-api-ftp/scripts/ftp_sync.py'
spec = importlib.util.spec_from_file_location('ftp_sync', SCRIPT)
sync = importlib.util.module_from_spec(spec)
spec.loader.exec_module(sync)
API = '/www.xain.in.ua/api'


class FakeFTP:
    def __init__(self):
        self.directory = API
        self.files = {API + '/' + name: b'old' for name in sync.DEPLOY_FILES}
        self.files.update({API + '/config.php': b'private', API + '/unrelated.txt': b'keep',
                           API + '/blackout_new.php': b'obsolete',
                           API + '/cache/refresh.lock': b'', API + '/cache/blackout_cache.json': b'{}',
                           API + '/cache/.htaccess': b'old protection',
                           API + '/logs/.htaccess': sync.LOGS_HTACCESS.encode()})
        self.dirs = {API, API + '/cache', API + '/logs'}
        self.mutations = []
        self.corrupt = False
        self.fail_delete = False
        self.fail_rename = False

    def cwd(self, path):
        if path not in self.dirs:
            raise ftplib.error_perm('550 Missing directory')
        self.directory = path

    def pwd(self):
        return self.directory

    def nlst(self):
        return sorted({p[len(self.directory) + 1:].split('/')[0]
                       for p in self.files.keys() | self.dirs
                       if p.startswith(self.directory + '/')})

    def retrbinary(self, command, callback):
        callback(self.files[self.directory + '/' + command[5:]])

    def storbinary(self, command, stream):
        name = command[5:]
        self.mutations.append(('stor', name))
        data = stream.read()
        self.files[self.directory + '/' + name] = b'corrupt' if self.corrupt else data

    def rename(self, old, new):
        if self.fail_rename:
            raise ftplib.error_perm('550 Cannot rename')
        self.mutations.append(('rename', new))
        self.files[self.directory + '/' + new] = self.files.pop(self.directory + '/' + old)

    def delete(self, name):
        key = self.directory + '/' + name
        if self.fail_delete and name == 'blackout_new.php':
            raise ftplib.error_perm('550 Cannot delete')
        if key not in self.files:
            raise ftplib.error_perm('550 Missing')
        self.mutations.append(('delete', name))
        del self.files[key]

    def mkd(self, name):
        self.mutations.append(('mkdir', name))
        self.dirs.add(self.directory + '/' + name)


class DeploymentTests(unittest.TestCase):
    def setUp(self):
        self.temp = tempfile.TemporaryDirectory()
        self.addCleanup(self.temp.cleanup)
        root = Path(self.temp.name)
        self.local = root / 'api'
        self.local.mkdir()
        for name in sync.DEPLOY_FILES:
            (self.local / name).write_bytes(b'new ' + name.encode())
        self.backups = root / 'backups'
        stack = contextlib.ExitStack()
        self.addCleanup(stack.close)
        stack.enter_context(patch.object(sync, 'LOCAL_API', self.local))
        stack.enter_context(patch.object(sync, 'BACKUP_ROOT', self.backups))
        stack.enter_context(contextlib.redirect_stdout(io.StringIO()))
        self.ftp = FakeFTP()
        self.env = {'ftp_dir': API, 'ftp_host': 'example.invalid'}

    def deploy(self, dry_run=False):
        return sync.cmd_deploy(self.ftp, self.env, dry_run=dry_run, clear_cache=False)

    def test_dry_run_has_no_remote_or_local_mutations(self):
        self.deploy(dry_run=True)
        self.assertEqual(self.ftp.mutations, [])
        self.assertFalse(self.backups.exists())

    def test_publish_preserves_unknown_config_cache_and_backs_up(self):
        self.deploy()
        self.assertEqual(self.ftp.files[API + '/config.php'], b'private')
        self.assertEqual(self.ftp.files[API + '/unrelated.txt'], b'keep')
        self.assertEqual(self.ftp.files[API + '/cache/refresh.lock'], b'')
        self.assertNotIn(API + '/blackout_new.php', self.ftp.files)
        self.assertEqual(self.ftp.files[API + '/cache/.htaccess'], b'Require all denied\n')
        for name in sync.DEPLOY_FILES:
            self.assertEqual(self.ftp.files[API + '/' + name], (self.local / name).read_bytes())
        backups = list(self.backups.iterdir())
        self.assertEqual(len(backups), 1)
        self.assertEqual((backups[0] / 'blackout.php').read_bytes(), b'old')
        self.assertEqual((backups[0] / 'blackout.php').stat().st_mode & 0o777, 0o600)
        self.assertFalse((backups[0] / 'config.php').exists())
        self.assertEqual((backups[0] / 'cache/.htaccess').read_bytes(), b'old protection')
        published_php = [n for op, n in self.ftp.mutations if op == 'rename' and n.endswith('.php')]
        self.assertEqual(published_php[-1], 'blackout.php')
        self.assertFalse(any(op == 'stor' and n in sync.DEPLOY_FILES for op, n in self.ftp.mutations))

    def test_corrupt_transfer_never_replaces_live_files(self):
        self.ftp.corrupt = True
        with self.assertRaisesRegex(OSError, 'verification failed'):
            self.deploy()
        for name in sync.DEPLOY_FILES:
            self.assertEqual(self.ftp.files[API + '/' + name], b'old')
        self.assertFalse(any(n.endswith('.tmp') for n in self.ftp.files))

    def test_rename_failure_does_not_delete_live_file(self):
        self.ftp.fail_rename = True
        with self.assertRaises(ftplib.error_perm):
            self.deploy()
        for name in sync.DEPLOY_FILES:
            self.assertEqual(self.ftp.files[API + '/' + name], b'old')

    def test_delete_failure_is_not_reported_as_success(self):
        self.ftp.fail_delete = True
        with self.assertRaises(ftplib.error_perm):
            self.deploy()

    def test_clear_cache_keeps_lock_and_protection(self):
        sync.clear_remote_cache(self.ftp, self.env)
        self.assertIn(API + '/cache/refresh.lock', self.ftp.files)
        self.assertIn(API + '/cache/.htaccess', self.ftp.files)
        self.assertNotIn(API + '/cache/blackout_cache.json', self.ftp.files)

    def test_missing_local_is_failure_and_no_mutations(self):
        (self.local / 'parser.php').unlink()
        self.assertEqual(sync.cmd_status(self.ftp, self.env), 1)
        with self.assertRaises(SystemExit):
            self.deploy()
        self.assertEqual(self.ftp.mutations, [])

    def test_listing_accepts_absolute_current_dir_but_rejects_traversal(self):
        with patch.object(self.ftp, 'nlst', return_value=[API + '/parser.php', './data.php']):
            self.assertEqual(sync.nlst(self.ftp), {'parser.php', 'data.php'})
        for entry in ('../config.php', '/other/file.php', 'bad\r\nDELE config.php'):
            with patch.object(self.ftp, 'nlst', return_value=[entry]):
                with self.assertRaises(ValueError):
                    sync.nlst(self.ftp)

    def test_second_deploy_does_not_write_unchanged_files(self):
        self.deploy()
        self.ftp.mutations.clear()
        self.deploy()
        self.assertEqual(self.ftp.mutations, [])
        self.assertEqual(len(list(self.backups.iterdir())), 1)

    def test_wrong_destination_rejected_before_connect(self):
        with patch.object(sync.ftplib, 'FTP') as ftp:
            with self.assertRaises(ValueError):
                sync.connect({'ftp_dir': '/www.xain.in.ua'})
            ftp.assert_not_called()


if __name__ == '__main__':
    unittest.main()
