"""Mutates ONLY synthetic validation-prefixed Compose projects and preserves source volumes."""
import importlib.util
import json
import os
from pathlib import Path
import secrets
import subprocess
import tempfile

from container_runtime_probes import DISCUSSION_ATTACHMENT_FIXTURE_STATE, RICH_CONTENT_FIXTURE_STATE, CONTENT_MEDIA_FIXTURE_STATE

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('recovery', ROOT / 'resources/operations/recovery.py')
recovery = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(recovery)
os.umask(0o077)


def heartbeat():
    owner = os.environ.get('KARDOROST_LEASE_OWNER')
    if owner:
        result = subprocess.run(['python3', os.environ.get('KARDOROST_LEASE_HELPER', '/workspace/work/kardorost-autonomy-lease.py'), 'touch', owner], stdout=subprocess.DEVNULL)
        assert result.returncode == 0, 'Delivery ownership changed; stop the integration check.'


class GuardedComposition(recovery.Composition):
    def execute(self, *args, **kwargs):
        heartbeat()
        return super().execute(*args, **kwargs)

source_file = Path(os.environ['CONTAINER_ENV_FILE']).resolve()
assert source_file.is_relative_to('/tmp')
source = GuardedComposition(source_file)
assert source.config['name'].startswith('kardorost-validation-')
assert source.settings['APP_URL'].startswith('http://127.0.0.1:')
source_services = source.running()
assert source_services.issuperset({'app', 'web', 'postgres'})
probe = r"""require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();$lead=\App\Models\ContactLead::sole();$file=\Illuminate\Support\Facades\Storage::disk('support')->get('container-acceptance.txt');echo json_encode(['users'=>\App\Models\User::count(),'synthetic'=>\App\Models\User::where('name','like','بررسی کانتینر %')->count(),'lead_id'=>$lead->id,'lead_ok'=>$lead->name==='درخواست آزمایشی کانتینر','encrypted'=>$lead->getRawOriginal('name')!==$lead->name,'file_hash'=>hash('sha256',$file),'queue_probes'=>array_map(fn($s)=>app(\App\Services\OperationalHeartbeats::class)->status($s)['fresh'],['scheduler','queue.default','queue.sms'])]);"""
original = json.loads(source.run(['exec', '-T', 'app', 'php', '-r', probe]))
assert original['users'] == original['synthetic'] == 4 and original['lead_ok'] and original['encrypted']

discussion_original = None
if os.environ.get('CONTAINER_EXPECT_DISCUSSION_ATTACHMENTS') == '1':
    discussion_original = json.loads(source.run(['exec', '-T', 'app', 'php', '-r', DISCUSSION_ATTACHMENT_FIXTURE_STATE]))
    assert discussion_original['fixture_complete'], 'Only the completed owned software-only discussion fixture.'

rich_content_original = None
if os.environ.get('CONTAINER_EXPECT_RICH_CONTENT') == '1':
    rich_content_original = json.loads(source.run(['exec', '-T', 'app', 'php', '-r', RICH_CONTENT_FIXTURE_STATE]))
    assert rich_content_original['fixture_complete'], 'Only the completed owned software-only rich CMS fixture.'

content_media_original = None
if os.environ.get('CONTAINER_EXPECT_CONTENT_MEDIA') == '1':
    content_media_original = json.loads(source.run(['exec', '-T', 'app', 'php', '-r', CONTENT_MEDIA_FIXTURE_STATE]))
    assert content_media_original['fixture_complete'], 'Only the completed owned active/private/withdrawn media fixture.'

latest_probe = r"""require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();$credential=\App\Models\CoachCredential::sole();$service=app(\App\Services\CoachCredentials::class);$metadata=$service->metadata($credential);$fileHash=hash('sha256',\Illuminate\Support\Facades\Storage::disk('learning')->get($credential->file_path));$share=\App\Models\ResumeShare::sole();$sharing=app(\App\Services\ResumeSharing::class);$data=$sharing->metadata($share);$public=$sharing->publicPayload($data['token']);echo json_encode(['credential_source_valid'=>$metadata!==null&&$metadata['credential_id']===$credential->id&&$metadata['user_id']===$credential->user_id,'credential_cipher_hash'=>hash('sha256',$credential->getRawOriginal('definition')),'credential_hash'=>$metadata['sha256'],'original_file_hash'=>$fileHash,'credential_cipher_private'=>!str_contains($credential->getRawOriginal('definition'),'مدرک صرفاً آزمایشی'),'share_cipher_hash'=>hash('sha256',$share->getRawOriginal('definition')),'share_source_valid'=>$data['share_id']===$share->id&&$data['version_id']===$share->resume_version_id,'public_source_name'=>$public['definition']['name']==='نام نسخه آزمایشی کانتینر','public_contacts_omitted'=>$public['definition']['phone']===''&&$public['definition']['email']==='','token_encrypted'=>!str_contains($share->getRawOriginal('definition'),$data['token'])]);"""
latest_original = None
if os.environ.get('CONTAINER_EXPECT_LATEST_FIXTURES') == '1':
    latest_original = json.loads(source.run(['exec', '-T', 'app', 'php', '-r', latest_probe]))
    assert latest_original['credential_hash'] == latest_original['original_file_hash']
    for name in ['credential_source_valid', 'credential_cipher_private', 'share_source_valid', 'public_source_name', 'public_contacts_omitted', 'token_encrypted']:
        assert latest_original[name]


def invoke(command, *args, expected=0):
    heartbeat()
    result = subprocess.run(['python3', 'resources/operations/recovery.py', command, *map(str, args)],
                            cwd=ROOT, capture_output=True, text=True)
    # Do not print raw subprocess output or values from either environment.
    if result.returncode != expected:
        heartbeat()
        diagnostic = Path('/tmp') / ('kardorost-recovery-failure-' + command + '-' + secrets.token_hex(4) + '.log')
        descriptor = os.open(diagnostic, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
        with os.fdopen(descriptor, 'w') as output:
            output.write(result.stdout + '\n' + result.stderr)
        raise AssertionError(f'Recovery {command} returned unexpected status; private diagnostic: {diagnostic.name}')
    return result


with tempfile.TemporaryDirectory(prefix='kardorost-recovery-acceptance-') as temporary:
    private = Path(temporary)
    key = private / 'backup.key';backup = private / 'snapshot.gpg'
    invoke('generate-key', '--output', key)
    invoke('backup', '--env-file', source_file, '--passphrase-file', key, '--output', backup)
    with tempfile.TemporaryDirectory(prefix='kardorost-snapshot-window-') as inspection:
        inspected = Path(inspection)
        recovery.gpg(backup, inspected / 'snapshot.tar', key, decrypt=True)
        manifest = recovery.read_archive(inspected / 'snapshot.tar', inspected, recovery.DEFAULT_LIMIT)
        assert manifest['format'] == 2
        window = recovery.reconciliation_window(manifest)
        assert window['external_reconciliation_from_utc'] is not None
        assert manifest['quiesced_at'] <= manifest['snapshot_completed_at'] <= manifest['source_restart_acknowledged_at'] <= manifest['created_at']
    assert source.running() == source_services
    before = recovery.digest(backup)
    invoke('backup', '--env-file', source_file, '--passphrase-file', key, '--output', backup, expected=1)
    assert before == recovery.digest(backup)
    # Remove source containers, retaining its database/storage volumes, to fit the local VFS driver.
    source.run(['down', '--timeout', '70'])
    source_revision = source.docker(['image', 'inspect', '--format', '{{index .Config.Labels \"org.opencontainers.image.revision\"}}', source.image]).strip()
    target_file = private / 'target.env'
    project = 'kardorost-validation-restore-' + secrets.token_hex(4)
    subprocess.run(['python3', 'resources/operations/initialize-environment.py', '--url', 'http://127.0.0.1:18089',
                    '--output', str(target_file), '--project', project, '--image-tag', source.image.split(':')[-1],
                    '--revision', source_revision, '--local'], cwd=ROOT, check=True, stdout=subprocess.DEVNULL)
    target = GuardedComposition(target_file)
    common = ['--env-file', target_file, '--passphrase-file', key, '--backup', backup]
    # Wrong application key is rejected before even creating the target database container.
    invoke('restore', *common, expected=1)
    assert not target.running()
    contents = target_file.read_text()
    old_key = target.settings['APP_KEY']
    contents = contents.replace('APP_KEY=' + old_key, 'APP_KEY=' + source.settings['APP_KEY'])
    target_file.write_text(contents)
    target = GuardedComposition(target_file)
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
    if latest_original is not None:
        assert json.loads(target.php(latest_probe)) == latest_original
    invoke('restore', *common, expected=1)
    assert target.running() == {'postgres'}
    after_refusal = json.loads(target.php(probe))
    assert after_refusal == restored
    target.run(['up', '-d', '--no-build', '--wait', '--wait-timeout', '120', 'app', 'web'])
    if discussion_original is not None:
        discussion_restored = json.loads(target.run(['exec', '-T', 'app', 'php', '-r', DISCUSSION_ATTACHMENT_FIXTURE_STATE]))
        assert discussion_restored == discussion_original, 'Private attachment ciphertexts, bytes, consent, withdrawal or revision history changed.'
    if rich_content_original is not None:
        rich_content_restored = json.loads(target.run(['exec', '-T', 'app', 'php', '-r', RICH_CONTENT_FIXTURE_STATE]))
        assert rich_content_restored == rich_content_original, 'Rich content, public versions, private draft or cancelled schedule snapshot changed.'
    if content_media_original is not None:
        content_media_restored = json.loads(target.run(['exec', '-T', 'app', 'php', '-r', CONTENT_MEDIA_FIXTURE_STATE]))
        assert content_media_restored == content_media_original, 'Media bytes, ciphertexts, publication/withdrawal, private draft, frozen image references or audit history changed.'
    # Save only the synthetic private destination environment for separate browser acceptance.
    destination = Path(os.environ.get('RECOVERY_DESTINATION_ENV_FILE', '/tmp/kardorost-compose-restored.env')).resolve()
    assert destination.is_relative_to('/tmp') and destination.name.startswith('kardorost-compose-restored')
    assert not destination.exists(), 'Preserve any prior destination environment instead of overwriting it.'
    descriptor = os.open(destination, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    with os.fdopen(descriptor, 'w') as output:
        output.write(contents)
    report = {'result': 'passed', 'local_storage_mode': 'vfs_flattened_filesystem_and_verified_runtime_config',
              'source_running_services_restored': sorted(source_services),
              'checks': ['real-quiesced-encrypted-postgresql-and-private-storage-snapshot', 'authenticated-quiesced-reconciliation-boundary-and-snapshot-restart-times', 'source-running-state-restored',
                         'existing-backup-preserved', 'wrong-app-key-wrong-backup-key-live-effects-refused-before-db-start',
                         'nonempty-private-storage-refused-with-empty-db-preserved', 'separate-target-project-volumes-db-passwords',
                         'real-postgresql-transactional-restore', 'encrypted-pii-decryption-and-private-file-hash',
                         'stale-source-heartbeats-cleared', 'nonempty-db-refused-with-data-preserved', 'restored-workers-remain-stopped']}
    if latest_original is not None:
        report['checks'].append('latest_private_credential_original_bytes_encrypted_source_and_consented_resume_share_restored')
    if discussion_original is not None:
        report['checks'].append('private_discussion_original_file_hashes_encrypted_bound_metadata_consent_withdrawal_and_revision_history_restored_without_republishing')
    if rich_content_original is not None:
        report['checks'].append('exact_rich_CMS_publications_private_draft_cancelled_schedule_snapshot_and_financial_rows_restored_without_republication')
    if content_media_original is not None:
        report['checks'].append('active_private_and_withdrawn_raster_bytes_source_bound_ciphertexts_rights_review_publication_draft_cancelled_image_schedule_and_audit_history_restored_without_republication')
    heartbeat()
    Path('/tmp/kardorost-recovery-acceptance.json').write_text(json.dumps(report))
    print(json.dumps(report))
