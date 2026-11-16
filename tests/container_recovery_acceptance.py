"""Mutates ONLY synthetic validation-prefixed Compose projects and preserves source volumes."""
import importlib.util
import json
import os
from pathlib import Path
import secrets
import subprocess
import tempfile

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('recovery', ROOT / 'resources/operations/recovery.py')
recovery = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(recovery)
os.umask(0o077)
source_file = Path(os.environ['CONTAINER_ENV_FILE']).resolve()
assert source_file.is_relative_to('/tmp')
source = recovery.Composition(source_file)
assert source.config['name'].startswith('kardorost-validation-')
assert source.settings['APP_URL'].startswith('http://127.0.0.1:')
assert 'app' in source.running()
probe = r"""require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();$lead=\App\Models\ContactLead::sole();$file=\Illuminate\Support\Facades\Storage::disk('support')->get('container-acceptance.txt');echo json_encode(['users'=>\App\Models\User::count(),'synthetic'=>\App\Models\User::where('name','like','بررسی کانتینر %')->count(),'lead_id'=>$lead->id,'lead_ok'=>$lead->name==='درخواست آزمایشی کانتینر','encrypted'=>$lead->getRawOriginal('name')!==$lead->name,'file_hash'=>hash('sha256',$file),'queue_probes'=>array_map(fn($s)=>app(\App\Services\OperationalHeartbeats::class)->status($s)['fresh'],['scheduler','queue.default','queue.sms'])]);"""
original = json.loads(source.run(['exec', '-T', 'app', 'php', '-r', probe]))
assert original['users'] == original['synthetic'] == 4 and original['lead_ok'] and original['encrypted']


def invoke(command, *args, expected=0):
    result = subprocess.run(['python3', 'resources/operations/recovery.py', command, *map(str, args)],
                            cwd=ROOT, capture_output=True, text=True)
    # Do not print raw subprocess output or values from either environment.
    assert result.returncode == expected, f'Recovery {command} returned unexpected status; diagnostics remain private.'
    return result


with tempfile.TemporaryDirectory(prefix='kardorost-recovery-acceptance-') as temporary:
    private = Path(temporary)
    key = private / 'backup.key';backup = private / 'snapshot.gpg'
    invoke('generate-key', '--output', key)
    invoke('backup', '--env-file', source_file, '--passphrase-file', key, '--output', backup)
    assert source.running().issuperset({'app', 'web', 'postgres', 'worker-default', 'worker-sms', 'scheduler'})
    before = recovery.digest(backup)
    invoke('backup', '--env-file', source_file, '--passphrase-file', key, '--output', backup, expected=1)
    assert before == recovery.digest(backup)
    # Remove source containers, retaining its database/storage volumes, to fit the local VFS driver.
    source.run(['down', '--timeout', '70'])
    target_file = private / 'target.env'
    project = 'kardorost-validation-restore-' + secrets.token_hex(4)
    subprocess.run(['python3', 'resources/operations/initialize-environment.py', '--url', 'http://127.0.0.1:18089',
                    '--output', str(target_file), '--project', project, '--image-tag', source.image.split(':')[-1],
                    '--revision', 'local-phase39-validation', '--local'], cwd=ROOT, check=True, stdout=subprocess.DEVNULL)
    target = recovery.Composition(target_file)
    common = ['--env-file', target_file, '--passphrase-file', key, '--backup', backup]
    # Wrong application key is rejected before even creating the target database container.
    invoke('restore', *common, expected=1)
    assert not target.running()
    contents = target_file.read_text()
    old_key = target.settings['APP_KEY']
    contents = contents.replace('APP_KEY=' + old_key, 'APP_KEY=' + source.settings['APP_KEY'])
    target_file.write_text(contents)
    target = recovery.Composition(target_file)
    assert target.settings['DB_PASSWORD'] != source.settings['DB_PASSWORD']
    assert target.config['services']['postgres']['environment']['POSTGRES_PASSWORD'] != source.config['services']['postgres']['environment']['POSTGRES_PASSWORD']
    # Enabled external effects must fail closed before database startup.
    target_file.write_text(contents.replace('SMS_DRIVER=disabled', 'SMS_DRIVER=melipayamak'))
    invoke('restore', *common, expected=1)
    assert not target.running()
    target_file.write_text(contents)
    wrong = private / 'wrong.key';wrong.write_text(secrets.token_urlsafe(48))
    invoke('restore', '--env-file', target_file, '--passphrase-file', wrong, '--backup', backup, expected=1)
    assert not target.running()
    target.run(['up', '-d', '--no-build', '--wait', '--wait-timeout', '120', 'postgres'])
    target.php("file_put_contents('/var/www/html/storage/app/restore-refusal-fixture','keep-this-synthetic-file');")
    invoke('restore', *common, expected=1)
    assert target.sql("SELECT count(*) FROM pg_tables WHERE schemaname='public'") == '0'
    assert target.php("echo file_get_contents('/var/www/html/storage/app/restore-refusal-fixture');") == 'keep-this-synthetic-file'
    target.php("unlink('/var/www/html/storage/app/restore-refusal-fixture');")
    invoke('restore', *common)
    assert target.running() == {'postgres'}
    restored = json.loads(target.php(probe))
    assert {key: value for key, value in restored.items() if key != 'queue_probes'} == {key: value for key, value in original.items() if key != 'queue_probes'}
    assert restored['queue_probes'] == [False, False, False]
    invoke('restore', *common, expected=1)
    assert target.running() == {'postgres'}
    after_refusal = json.loads(target.php(probe))
    assert after_refusal == restored
    target.run(['up', '-d', '--no-build', '--wait', '--wait-timeout', '120', 'app', 'web'])
    # Save only the synthetic private destination environment for separate browser acceptance.
    destination = Path('/tmp/kardorost-compose-restored.env')
    assert not destination.exists(), 'Preserve any prior destination environment instead of overwriting it.'
    descriptor = os.open(destination, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    with os.fdopen(descriptor, 'w') as output:
        output.write(contents)
    report = {'result': 'passed', 'local_storage_mode': 'vfs_flattened_filesystem_and_verified_runtime_config',
              'checks': ['real-quiesced-encrypted-postgresql-and-private-storage-snapshot', 'source-running-state-restored',
                         'existing-backup-preserved', 'wrong-app-key-wrong-backup-key-live-effects-refused-before-db-start',
                         'nonempty-private-storage-refused-with-empty-db-preserved', 'separate-target-project-volumes-db-passwords',
                         'real-postgresql-transactional-restore', 'encrypted-pii-decryption-and-private-file-hash',
                         'stale-source-heartbeats-cleared', 'nonempty-db-refused-with-data-preserved', 'restored-workers-remain-stopped']}
    Path('/tmp/kardorost-recovery-acceptance.json').write_text(json.dumps(report))
    print(json.dumps(report))
