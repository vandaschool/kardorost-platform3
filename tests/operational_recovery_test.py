import hashlib
import importlib.util
import io
import json
import os
from pathlib import Path
import subprocess
import tarfile
import tempfile
import unittest
from unittest.mock import patch
from types import SimpleNamespace

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('recovery', ROOT / 'resources/operations/recovery.py')
recovery = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(recovery)


class OperationalRecoveryTest(unittest.TestCase):
    def setUp(self):
        self.temporary = tempfile.TemporaryDirectory(prefix='kardorost-recovery-test-')
        self.directory = Path(self.temporary.name)
        self.key = self.directory / 'passphrase'
        self.key.write_text('independent-synthetic-backup-key-1234567890\n')
        self.key.chmod(0o600)

    def tearDown(self):
        self.temporary.cleanup()

    def tar(self, members, name='storage.tar'):
        path = self.directory / name
        with tarfile.open(path, 'w:') as archive:
            for filename, content, kind in members:
                member = tarfile.TarInfo(filename)
                member.mode = 0o640
                member.type = kind
                member.size = len(content) if kind == tarfile.REGTYPE else 0
                if kind in (tarfile.SYMTYPE, tarfile.LNKTYPE):
                    member.linkname = '/outside/private'
                archive.addfile(member, io.BytesIO(content) if member.isfile() else None)
        return path

    def test_generated_key_is_private_independent_and_never_printed_or_overwritten(self):
        target = self.directory / 'fresh.key'
        command = ['python3', str(ROOT / 'resources/operations/recovery.py'), 'generate-key', '--output', str(target)]
        result = subprocess.run(command, capture_output=True, text=True)
        self.assertEqual(result.returncode, 0)
        secret = target.read_text().strip()
        self.assertGreaterEqual(len(secret), 48)
        self.assertEqual(target.stat().st_mode & 0o777, 0o600)
        self.assertNotIn(secret, result.stdout + result.stderr)
        self.assertNotEqual(subprocess.run(command, capture_output=True).returncode, 0)
        self.assertEqual(target.read_text().strip(), secret)

    def test_private_inputs_reject_other_readable_file_and_symlink(self):
        self.key.chmod(0o644)
        with self.assertRaises(recovery.RecoveryError):
            recovery.private_file(self.key)
        self.key.chmod(0o600)
        link = self.directory / 'link'
        link.symlink_to(self.key)
        with self.assertRaises(OSError):
            recovery.private_file(link)

    def test_bounded_stream_rejects_oversized_plaintext(self):
        with self.assertRaises(recovery.RecoveryError):
            recovery.bounded_copy(io.BytesIO(b'x' * 33), io.BytesIO(), 32)

    def test_storage_hashes_include_unicode_paths_and_file_contents(self):
        path = self.tar([('.', b'', tarfile.DIRTYPE), ('./support-private/فایل.txt', b'private-content', tarfile.REGTYPE)])
        self.assertEqual(recovery.storage_index(path, 1024), {'support-private/فایل.txt': {'size': 15, 'sha256': hashlib.sha256(b'private-content').hexdigest()}})

    def test_storage_rejects_traversal_absolute_control_and_windows_paths(self):
        for index, name in enumerate(('../outside', '/absolute', './a/../../out', 'a\\b', 'bad\nname')):
            with self.subTest(name=repr(name)):
                path = self.tar([(name, b'private', tarfile.REGTYPE)], f'bad-{index}.tar')
                with self.assertRaises(recovery.RecoveryError):
                    recovery.storage_index(path, 1024)

    def test_storage_rejects_symlinks_hardlinks_devices_and_fifos(self):
        for index, kind in enumerate((tarfile.SYMTYPE, tarfile.LNKTYPE, tarfile.CHRTYPE, tarfile.FIFOTYPE)):
            with self.subTest(kind=kind):
                path = self.tar([('private', b'', kind)], f'link-{index}.tar')
                with self.assertRaises(recovery.RecoveryError):
                    recovery.storage_index(path, 1024)

    def test_storage_rejects_duplicate_normalized_paths_and_file_parent(self):
        for index, members in enumerate(([('./private', b'a', tarfile.REGTYPE), ('private', b'b', tarfile.REGTYPE)],
                                         [('private', b'a', tarfile.REGTYPE), ('private/file', b'b', tarfile.REGTYPE)])):
            path = self.tar(members, f'duplicate-{index}.tar')
            with self.assertRaises(recovery.RecoveryError):
                recovery.storage_index(path, 1024)

    def test_storage_rejects_excessive_payload_and_special_permissions(self):
        path = self.tar([('private', b'12345', tarfile.REGTYPE)])
        with self.assertRaises(recovery.RecoveryError):
            recovery.storage_index(path, 4)
        with tarfile.open(self.directory / 'special.tar', 'w:') as archive:
            member = tarfile.TarInfo('suid');member.mode = 0o4755;member.size = 1
            archive.addfile(member, io.BytesIO(b'a'))
        with self.assertRaises(recovery.RecoveryError):
            recovery.storage_index(self.directory / 'special.tar', 1024)

    def test_gpg_roundtrip_is_private_and_wrong_key_tampering_and_limit_fail(self):
        source = self.directory / 'plain';source.write_bytes(b'synthetic private Persian data ' * 5000)
        encrypted = self.directory / 'snapshot.gpg'
        recovery.gpg(source, encrypted, self.key)
        self.assertEqual(encrypted.stat().st_mode & 0o777, 0o600)
        self.assertNotIn(b'synthetic private', encrypted.read_bytes())
        restored = self.directory / 'restored'
        recovery.gpg(encrypted, restored, self.key, decrypt=True)
        self.assertEqual(restored.read_bytes(), source.read_bytes())
        wrong = self.directory / 'wrong';wrong.write_text('another-independent-backup-passphrase-1234567890');wrong.chmod(0o600)
        with self.assertRaises(recovery.RecoveryError):
            recovery.gpg(encrypted, self.directory / 'wrong-output', wrong, decrypt=True)
        damaged = bytearray(encrypted.read_bytes());damaged[-10] ^= 32
        corrupt = self.directory / 'corrupt';corrupt.write_bytes(damaged)
        with self.assertRaises(recovery.RecoveryError):
            recovery.gpg(corrupt, self.directory / 'corrupt-output', self.key, decrypt=True)
        with self.assertRaises(recovery.RecoveryError):
            recovery.gpg(encrypted, self.directory / 'limited-output', self.key, decrypt=True, limit=100)

    def test_nested_archive_roundtrip_checks_manifest_and_hashes_before_restore(self):
        storage = self.tar([('private/file', b'private', tarfile.REGTYPE)])
        dump = self.directory / 'database.dump';dump.write_bytes(b'PGDMP-synthetic-not-a-real-dump')
        manifest = {'format': 1, 'postgres_major': 17, 'sha256': {p.name: recovery.digest(p) for p in (storage, dump)},
                    'files': recovery.storage_index(storage, 1024)}
        (self.directory / 'manifest.json').write_text(json.dumps(manifest))
        outer = self.directory / 'snapshot.tar'
        with tarfile.open(outer, 'w:') as archive:
            for name in ('manifest.json', 'database.dump', 'storage.tar'):
                archive.add(self.directory / name, arcname=name)
        extracted = self.directory / 'unpacked';extracted.mkdir()
        self.assertEqual(recovery.read_archive(outer, extracted, 1024 * 1024), manifest)
        manifest['sha256']['storage.tar'] = '0' * 64
        (self.directory / 'manifest.json').write_text(json.dumps(manifest))
        with tarfile.open(outer, 'w:') as archive:
            for name in ('manifest.json', 'database.dump', 'storage.tar'):
                archive.add(self.directory / name, arcname=name)
        rejected = self.directory / 'rejected';rejected.mkdir()
        with self.assertRaises(recovery.RecoveryError):
            recovery.read_archive(outer, rejected, 1024 * 1024)

    def test_outer_archive_never_extracts_unexpected_paths_or_links(self):
        for index, member in enumerate([('../outside', b'x', tarfile.REGTYPE), ('manifest.json', b'', tarfile.SYMTYPE)]):
            path = self.tar([member], f'outer-{index}.tar')
            target = self.directory / f'out-{index}';target.mkdir()
            with self.assertRaises(recovery.RecoveryError):
                recovery.read_archive(path, target, 1024)
            self.assertEqual(list(target.iterdir()), [])

    def test_failed_database_snapshot_restarts_exact_previously_running_source_services(self):
        class BrokenSource:
            settings = {'DB_DATABASE': 'synthetic'}
            key_hash = 'safe-test-fingerprint'
            image_id = 'sha256:synthetic'
            stopped = False
            restarted = None

            def running(self):
                return {'postgres'} if self.stopped else {'postgres', 'app', 'web'}

            def pg_version(self):
                pass

            def run(self, command):
                if command[0] == 'exec':
                    return self.key_hash
                if command[0] == 'stop':
                    self.stopped = True
                if command[0] == 'start':
                    self.restarted = command[1:]
                    self.stopped = False
                return ''

            def pg(self, command, output=None):
                raise recovery.RecoveryError('Synthetic database stream interruption.')

        source = BrokenSource()
        args = SimpleNamespace(env_file=self.directory / 'unused.env', passphrase_file=self.key,
                               output=self.directory / 'never-published.gpg', max_bytes=1024 * 1024)
        with patch.object(recovery, 'Composition', return_value=source), patch.object(recovery, 'independent_key'):
            with self.assertRaises(recovery.RecoveryError):
                recovery.backup(args)
        self.assertEqual(set(source.restarted), {'app', 'web'})
        self.assertFalse(source.stopped)
        self.assertFalse(args.output.exists())

    def test_local_operation_lock_refuses_overlapping_operation(self):
        lock = self.directory / 'root';lock.mkdir()
        previous = recovery.ROOT
        recovery.ROOT = lock
        try:
            with recovery.operation_lock():
                with self.assertRaises(recovery.RecoveryError):
                    with recovery.operation_lock():
                        pass
            with recovery.operation_lock():
                pass
        finally:
            recovery.ROOT = previous


if __name__ == '__main__':
    unittest.main()
