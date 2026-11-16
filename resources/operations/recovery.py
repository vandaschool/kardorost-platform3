#!/usr/bin/env python3
"""Quiesced encrypted Compose snapshots and fail-closed, empty-target restores."""
import argparse
from contextlib import contextmanager
from datetime import datetime, timezone
import fcntl
import hashlib
import json
import os
from pathlib import Path, PurePosixPath
import re
import secrets
import shutil
import stat
import subprocess
import tarfile
import tempfile

ROOT = Path(__file__).resolve().parents[2]
SERVICES = ('app', 'web', 'worker-default', 'worker-sms', 'scheduler')
MAX_MEMBERS = 100000
DEFAULT_LIMIT = 10 * 1024 ** 3


class RecoveryError(Exception):
    pass


def require(condition, message):
    if not condition:
        raise RecoveryError(message)


def private_file(path):
    descriptor = os.open(path, os.O_RDONLY | os.O_NOFOLLOW | os.O_NONBLOCK)
    info = os.fstat(descriptor)
    if not stat.S_ISREG(info.st_mode) or info.st_uid != os.getuid() or info.st_mode & 0o077:
        os.close(descriptor)
        raise RecoveryError('Input must be an owned regular private file (0600 or stricter).')
    return os.fdopen(descriptor, 'rb')


def digest(path):
    with open(path, 'rb') as handle:
        return hashlib.file_digest(handle, 'sha256').hexdigest()


def bounded_copy(source, destination, limit):
    total = 0
    while chunk := source.read(min(1024 * 1024, limit - total + 1)):
        total += len(chunk)
        require(total <= limit, 'Archive exceeds the configured byte limit.')
        destination.write(chunk)
    return total


@contextmanager
def operation_lock():
    descriptor = os.open(ROOT / '.kardorost-ops.lock', os.O_WRONLY | os.O_CREAT | os.O_NOFOLLOW, 0o600)
    with os.fdopen(descriptor, 'w') as handle:
        try:
            fcntl.flock(handle, fcntl.LOCK_EX | fcntl.LOCK_NB)
        except BlockingIOError:
            raise RecoveryError('Another local deployment or recovery operation holds the lock.') from None
        yield


def gpg(source, destination, passphrase_file, decrypt=False, limit=DEFAULT_LIMIT):
    with private_file(passphrase_file) as key, tempfile.TemporaryDirectory(prefix='kardorost-gpg-') as home:
        phrase = key.read(4097).rstrip(b'\r\n')
        require(32 <= len(phrase) <= 4096 and b'\n' not in phrase and b'\r' not in phrase and b'\0' not in phrase,
                'Use an independent single-line backup passphrase of at least 32 bytes.')
        key.seek(0)
        args = ['gpg', '--no-options', '--homedir', home, '--batch', '--no-tty', '--pinentry-mode', 'loopback',
                '--no-symkey-cache', '--passphrase-fd', str(key.fileno()), '--output', '-']
        args += ['--decrypt'] if decrypt else ['--symmetric', '--cipher-algo', 'AES256', '--force-mdc',
                                             '--s2k-mode', '3', '--s2k-digest-algo', 'SHA512', '--s2k-count', '65011712']
        args += ['--', str(source)]
        with open(destination, 'xb') as output:
            os.chmod(destination, 0o600)
            process = subprocess.Popen(args, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL, pass_fds=(key.fileno(),))
            try:
                bounded_copy(process.stdout, output, limit)
                require(process.wait() == 0, 'Backup encryption/decryption failed; verify the key and archive integrity.')
                output.flush()
                os.fsync(output.fileno())
            finally:
                if process.poll() is None:
                    process.kill()
                    process.wait()
                process.stdout.close()


def storage_index(path, limit):
    files, names, directories = {}, set(), set()
    total = 0
    with tarfile.open(path, 'r:') as archive:
        for member in archive:
            require(len(names) < MAX_MEMBERS, 'Storage archive has too many members.')
            name = member.name
            parts = PurePosixPath(name).parts
            require(name and not name.startswith('/') and '\\' not in name and '..' not in parts
                    and len(name) <= 2048 and all(ord(c) >= 32 and ord(c) != 127 for c in name),
                    'Storage archive contains an unsafe path.')
            normalized = str(PurePosixPath(name))
            require(normalized not in names and (member.isfile() or member.isdir()) and not member.mode & 0o7000,
                    'Storage archive contains duplicate paths, links or special files.')
            require(normalized != '.' or member.isdir(), 'Storage archive root must be a directory.')
            names.add(normalized)
            if member.isdir():
                directories.add(normalized)
                continue
            total += member.size
            require(0 <= member.size and total <= limit, 'Storage archive exceeds the configured byte limit.')
            with archive.extractfile(member) as data:
                files[normalized] = {'size': member.size, 'sha256': hashlib.file_digest(data, 'sha256').hexdigest()}
    for name in names:
        require(not any(str(parent) in files for parent in PurePosixPath(name).parents),
                'Storage archive has a file used as a parent directory.')
    return files


def read_archive(path, directory, limit):
    expected = {'manifest.json', 'database.dump', 'storage.tar'}
    seen = set()
    total = 0
    with tarfile.open(path, 'r:') as archive:
        for member in archive:
            require(member.name in expected and member.name not in seen and member.isfile(),
                    'Backup archive contains an unexpected, duplicate or unsafe member.')
            require(member.size >= 0 and (member.name != 'manifest.json' or member.size <= 32 * 1024 ** 2),
                    'Backup manifest is too large.')
            total += member.size
            require(total <= limit, 'Backup exceeds the configured byte limit.')
            seen.add(member.name)
            with archive.extractfile(member) as source, open(directory / member.name, 'xb') as output:
                bounded_copy(source, output, member.size)
                os.chmod(directory / member.name, 0o600)
    require(seen == expected, 'Backup archive is incomplete.')
    try:
        manifest = json.loads((directory / 'manifest.json').read_text())
    except (ValueError, UnicodeError):
        raise RecoveryError('Backup manifest is invalid.') from None
    require(isinstance(manifest, dict) and type(manifest.get('format')) is int and manifest.get('format') in (1, 2) and manifest.get('postgres_major') == 17,
            'Backup format or PostgreSQL version is unsupported.')
    reconciliation_window(manifest)
    for name in ('database.dump', 'storage.tar'):
        require(manifest.get('sha256', {}).get(name) == digest(directory / name), 'Backup content hash does not match.')
    require(manifest.get('files') == storage_index(directory / 'storage.tar', limit), 'Private storage hashes do not match.')
    with open(directory / 'database.dump', 'rb') as dump:
        require(dump.read(5) == b'PGDMP', 'Database backup is not PostgreSQL custom format.')
    return manifest


def reconciliation_window(manifest):
    require(type(manifest.get('format')) is int and manifest.get('format') in (1, 2), 'Snapshot format is unsupported.')
    if manifest.get('format') == 1:
        return {'external_reconciliation_from_utc': None, 'snapshot_completed_at_utc': None,
                'source_restart_acknowledged_at_utc': None}
    fields = ('quiesced_at', 'snapshot_completed_at', 'source_restart_acknowledged_at', 'created_at')
    timestamps = []
    for field in fields:
        value = manifest.get(field)
        require(isinstance(value, str) and len(value) <= 64, 'Snapshot timing metadata is incomplete.')
        try:
            timestamp = datetime.fromisoformat(value)
        except ValueError:
            raise RecoveryError('Snapshot timing metadata is invalid.') from None
        require(timestamp.tzinfo is not None and timestamp.utcoffset() is not None,
                'Snapshot timing metadata must include a timezone.')
        timestamps.append(timestamp.astimezone(timezone.utc))
    require(timestamps == sorted(timestamps), 'Snapshot timing boundaries are inconsistent.')
    require(manifest.get('external_reconciliation_from') == manifest['quiesced_at'],
            'External reconciliation must begin at the quiesced snapshot boundary.')
    return {'external_reconciliation_from_utc': timestamps[0].isoformat(),
            'snapshot_completed_at_utc': timestamps[1].isoformat(),
            'source_restart_acknowledged_at_utc': timestamps[2].isoformat()}


class Composition:
    def __init__(self, environment_file):
        with private_file(environment_file) as handle:
            # Compose owns dotenv parsing; secret values never enter command arguments or diagnostics.
            require(len(handle.read(1024 * 1024 + 1)) <= 1024 * 1024, 'Environment file is too large.')
        self.base = ['docker', 'compose', '--project-directory', str(ROOT), '--env-file', str(Path(environment_file).resolve()),
                     '-f', str(ROOT / 'compose.yaml')]
        # Do not let unrelated shell exports silently select another project, key, image or database.
        self.environment = {key: value for key, value in os.environ.items()
                            if key in ('PATH', 'HOME', 'DOCKER_HOST', 'DOCKER_CONTEXT', 'DOCKER_CONFIG',
                                       'XDG_RUNTIME_DIR', 'SSL_CERT_FILE', 'SSL_CERT_DIR')}
        self.config = json.loads(self.run(['config', '--format', 'json']))
        self.settings = self.config['services']['app']['environment']
        require(self.settings['DB_HOST'] == 'postgres' and self.settings['DB_CONNECTION'] == 'pgsql',
                'Recovery only supports the bundled isolated PostgreSQL service.')
        require(re.fullmatch(r'[A-Za-z0-9_]+', self.settings['DB_DATABASE']) is not None, 'Unsupported database name.')
        self.image = self.config['services']['app']['image']
        self.limit = DEFAULT_LIMIT
        self.image_id = self.docker(['image', 'inspect', '--format', '{{.Id}}', self.image]).strip()
        require(self.image_id.startswith('sha256:'), 'Application image must be built before recovery.')
        self.key_hash = hashlib.sha256(self.settings['APP_KEY'].encode()).hexdigest()

    def docker(self, args, **options):
        return self.execute(['docker'] + args, **options)

    def execute(self, command, input=None, output=None):
        if output is not None:
            process = subprocess.Popen(command, cwd=ROOT, env=self.environment, stdout=subprocess.PIPE, stderr=subprocess.DEVNULL)
            try:
                bounded_copy(process.stdout, output, self.limit)
                require(process.wait() == 0, 'Container stream failed; no secrets were logged.')
            finally:
                if process.poll() is None:
                    process.kill()
                    process.wait()
                process.stdout.close()
            return ''
        options = {'stdin': input} if hasattr(input, 'read') else {'input': input}
        result = subprocess.run(command, cwd=ROOT, env=self.environment, stdout=subprocess.PIPE,
                                stderr=subprocess.DEVNULL, **options)
        require(result.returncode == 0, 'Container operation failed. Check service state locally; no secrets were logged.')
        return result.stdout.decode()

    def run(self, args, **options):
        return self.execute(self.base + args, **options)

    def running(self):
        return set(self.run(['ps', '--services', '--status', 'running']).split())

    def php(self, code):
        return self.run(['run', '--rm', '--no-deps', '--entrypoint', 'php', 'app', '-r', code])

    def pg(self, args, **options):
        return self.run(['exec', '-T', 'postgres'] + args, **options)

    def sql(self, query):
        return self.pg(['psql', '-X', '-U', 'postgres', '-d', self.settings['DB_DATABASE'], '-At', '-v', 'ON_ERROR_STOP=1', '-c', query]).strip()

    def pg_version(self):
        require(self.sql('SHOW server_version_num;').isdigit() and int(self.sql('SHOW server_version_num;')) // 10000 == 17,
                'PostgreSQL 17 is required for this backup format.')


def independent_key(path, composition):
    with private_file(path) as handle:
        phrase = handle.read(4097).rstrip(b'\r\n')
    forbidden = [composition.settings['APP_KEY'], composition.settings['DB_PASSWORD'],
                 composition.config['services']['postgres']['environment']['POSTGRES_PASSWORD']]
    require(32 <= len(phrase) <= 4096 and all(phrase != item.encode() for item in forbidden),
            'Backup passphrase must be independent of application and database keys.')


def backup(args):
    composition = Composition(args.env_file)
    composition.limit = args.max_bytes
    independent_key(args.passphrase_file, composition)
    target = args.output.absolute()
    parent = target.parent.resolve(strict=True)
    require(parent != ROOT and ROOT not in parent.parents and not parent.stat().st_mode & 0o077
            and parent.stat().st_uid == os.getuid(), 'Use an owned private backup directory outside the repository and web root.')
    require(not target.exists() and not target.is_symlink(), 'Backup output already exists; it will not be overwritten.')
    running = composition.running()
    require('postgres' in running and 'app' in running, 'Backup requires the running source application and database.')
    composition.pg_version()
    fingerprint = composition.run(['exec', '-T', 'app', 'php', '-r',
        "require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\\Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();echo hash('sha256',config('app.key')); "]).strip()
    require(fingerprint == composition.key_hash, 'Running application key differs from the environment file; preserve the correct key.')
    with tempfile.TemporaryDirectory(prefix='kardorost-backup-') as temporary:
        directory = Path(temporary)
        try:
            composition.run(['stop', '--timeout', '70'] + [service for service in SERVICES if service in running])
            require(not composition.running().intersection(SERVICES), 'Application writers did not stop; backup aborted.')
            quiesced_at = datetime.now(timezone.utc).isoformat()
            with open(directory / 'database.dump', 'xb') as output:
                composition.pg(['pg_dump', '-U', 'postgres', '-d', composition.settings['DB_DATABASE'],
                                '--format=custom', '--no-owner', '--no-acl'], output=output)
            with open(directory / 'storage.tar', 'xb') as output:
                composition.run(['run', '--rm', '--no-deps', '--entrypoint', 'tar', 'app', '-C',
                                 '/var/www/html/storage/app', '-cf', '-', '.'], output=output)
            snapshot_completed_at = datetime.now(timezone.utc).isoformat()
        finally:
            composition.run(['start'] + [service for service in SERVICES if service in running])
            source_restart_acknowledged_at = datetime.now(timezone.utc).isoformat()
        require(sum((directory / name).stat().st_size for name in ('database.dump', 'storage.tar')) <= args.max_bytes,
                'Snapshot exceeds the configured byte limit.')
        manifest = {'format': 2, 'postgres_major': 17, 'created_at': datetime.now(timezone.utc).isoformat(),
                    'quiesced_at': quiesced_at, 'external_reconciliation_from': quiesced_at,
                    'snapshot_completed_at': snapshot_completed_at, 'source_restart_acknowledged_at': source_restart_acknowledged_at,
                    'application_image_id': composition.image_id, 'application_key_sha256': composition.key_hash,
                    'sha256': {name: digest(directory / name) for name in ('database.dump', 'storage.tar')},
                    'files': storage_index(directory / 'storage.tar', args.max_bytes)}
        reconciliation_window(manifest)
        (directory / 'manifest.json').write_text(json.dumps(manifest, ensure_ascii=False))
        required_space = sum((directory / name).stat().st_size for name in ('database.dump', 'storage.tar', 'manifest.json'))
        require(shutil.disk_usage(directory).free >= required_space * 2 + 64 * 1024 ** 2, 'Insufficient temporary disk space for the encrypted snapshot.')
        with tarfile.open(directory / 'snapshot.tar', 'w:') as archive:
            for name in ('manifest.json', 'database.dump', 'storage.tar'):
                archive.add(directory / name, arcname=name, recursive=False)
        require((directory / 'snapshot.tar').stat().st_size <= args.max_bytes, 'Complete archive exceeds the configured byte limit.')
        # Publish atomically without ever overwriting an existing output, including symlinks.
        with tempfile.TemporaryDirectory(prefix='.kardorost-encrypted-', dir=parent) as encrypted:
            ciphertext = Path(encrypted) / 'snapshot.gpg'
            gpg(directory / 'snapshot.tar', ciphertext, args.passphrase_file, limit=args.max_bytes + 1024 * 1024)
            os.link(ciphertext, target)
            directory_fd = os.open(parent, os.O_DIRECTORY)
            try:
                os.fsync(directory_fd)
            finally:
                os.close(directory_fd)
    print('External reconciliation begins at quiesced UTC boundary: ' + quiesced_at)
    print('Encrypted snapshot created. Previously running source services were restarted. Store keys separately and copy the backup off-server.')


def restore(args):
    composition = Composition(args.env_file)
    composition.limit = args.max_bytes
    independent_key(args.passphrase_file, composition)
    require(not composition.running().intersection(SERVICES), 'Restore requires all target application services to be stopped.')
    require(composition.settings.get('SMS_DRIVER') == 'disabled' and composition.settings.get('ZIBAL_ENABLED') == 'false'
            and all(composition.settings.get(key) == 'false' for key in ('MELIPAYAMAK_INVITATIONS_ENABLED',
                     'MELIPAYAMAK_REMINDERS_ENABLED', 'MELIPAYAMAK_DELIVERY_ENABLED')),
            'Restore target must have live SMS, delivery tracking and gateway disabled.')
    with tempfile.TemporaryDirectory(prefix='kardorost-restore-') as temporary:
        directory = Path(temporary)
        with private_file(args.backup) as source:
            require(os.fstat(source.fileno()).st_size <= args.max_bytes + 1024 * 1024, 'Encrypted snapshot exceeds the byte limit.')
            # Keep the opened private inode pinned while GPG reads it.
            with open(directory / 'encrypted.gpg', 'xb') as copy:
                bounded_copy(source, copy, args.max_bytes + 1024 * 1024)
        gpg(directory / 'encrypted.gpg', directory / 'snapshot.tar', args.passphrase_file, decrypt=True, limit=args.max_bytes)
        manifest = read_archive(directory / 'snapshot.tar', directory, args.max_bytes)
        require(manifest.get('application_key_sha256') == composition.key_hash, 'Application key does not match the snapshot; target preserved.')
        require(manifest.get('application_image_id') == composition.image_id, 'Use the exact backed-up application image before migrating code.')
        composition.run(['up', '-d', '--no-build', '--wait', '--wait-timeout', '120', 'postgres'])
        composition.pg_version()
        objects = composition.sql("SELECT count(*) FROM pg_class c JOIN pg_namespace n ON n.oid=c.relnamespace WHERE n.nspname NOT LIKE 'pg_%' AND n.nspname <> 'information_schema';")
        schemas = composition.sql("SELECT count(*) FROM pg_namespace WHERE nspname NOT LIKE 'pg_%' AND nspname NOT IN ('public','information_schema');")
        require(objects == '0' and schemas == '0', 'Restore refuses a nonempty target database. No target data was removed.')
        empty = composition.php("$root='/var/www/html/storage/app';$it=new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root,FilesystemIterator::SKIP_DOTS),RecursiveIteratorIterator::SELF_FIRST);foreach($it as $file){if($file->isLink()||!$file->isDir()){exit(3);}}echo 'empty';")
        require(empty == 'empty', 'Restore refuses nonempty target private storage.')
        require(not composition.running().intersection(SERVICES), 'Target writers started during validation; restore aborted.')
        # No --clean: restore is a single database transaction, never a destructive replacement.
        with open(directory / 'database.dump', 'rb') as dump:
            composition.pg(['pg_restore', '-U', composition.settings['DB_USERNAME'], '-d', composition.settings['DB_DATABASE'],
                            '--no-owner', '--no-acl', '--exit-on-error', '--single-transaction'], input=dump)
        with open(directory / 'storage.tar', 'rb') as storage:
            composition.run(['run', '--rm', '--no-deps', '--entrypoint', 'tar', 'app', '-C',
                             '/var/www/html/storage/app', '-xf', '-', '--no-same-owner'], input=storage)
        with open(directory / 'restored-storage.tar', 'xb') as output:
            composition.run(['run', '--rm', '--no-deps', '--entrypoint', 'tar', 'app', '-C',
                             '/var/www/html/storage/app', '-cf', '-', '.'], output=output)
        require(storage_index(directory / 'restored-storage.tar', args.max_bytes) == manifest['files'], 'Restored private file verification failed; keep target stopped.')
        # Restored queue probes must not falsely attest to a running destination worker.
        composition.php("require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\\Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();foreach(['scheduler','queue.default','queue.sms'] as $key){\\Illuminate\\Support\\Facades\\Cache::forget('platform:operations:heartbeat:v1:'.$key);}echo 'verified';")
    timing = reconciliation_window(manifest)
    if timing['external_reconciliation_from_utc'] is None:
        print('Legacy snapshot has no quiesced cutoff. Establish an earlier confirmed external reconciliation boundary before starting workers.')
    else:
        print('External reconciliation begins at quiesced UTC boundary: ' + timing['external_reconciliation_from_utc'])
    print('Empty-target restore and private file hashes verified. Application services remain stopped; reconcile external payments/messages before starting workers.')


def main():
    os.umask(0o077)
    parser = argparse.ArgumentParser(description=__doc__)
    commands = parser.add_subparsers(dest='command', required=True)
    key = commands.add_parser('generate-key', help='Create an independent private backup passphrase without printing it.')
    key.add_argument('--output', type=Path, required=True)
    for command in ('backup', 'restore'):
        sub = commands.add_parser(command)
        sub.add_argument('--env-file', type=Path, required=True)
        sub.add_argument('--passphrase-file', type=Path, required=True)
        sub.add_argument('--max-bytes', type=int, default=DEFAULT_LIMIT)
        sub.add_argument('--output' if command == 'backup' else '--backup', type=Path, required=True)
    args = parser.parse_args()
    try:
        if args.command == 'generate-key':
            descriptor = os.open(args.output, os.O_WRONLY | os.O_CREAT | os.O_EXCL | os.O_NOFOLLOW, 0o600)
            with os.fdopen(descriptor, 'w') as handle:
                handle.write(secrets.token_urlsafe(48) + '\n')
                handle.flush()
                os.fsync(handle.fileno())
            print('Independent private backup key created; store it separately from snapshots and the application key.')
        else:
            require(args.max_bytes >= 1024 * 1024, 'Byte limit must be at least one MiB.')
            with operation_lock():
                (backup if args.command == 'backup' else restore)(args)
    except RecoveryError as error:
        import sys
        print(str(error), file=sys.stderr)
        return 1
    except (OSError, ValueError, KeyError, TypeError, AttributeError, tarfile.TarError):
        # Neither Docker output, parsed credentials, decrypted PII nor exception values are logged.
        import sys
        print('Recovery failed safely. Check private file permissions, archive/key/image compatibility, service state and an empty restore target. Keep a partially restored target stopped.', file=sys.stderr)
        return 1
    return 0


if __name__ == '__main__':
    raise SystemExit(main())
