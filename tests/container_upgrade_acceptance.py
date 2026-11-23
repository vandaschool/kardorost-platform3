"""Upgrade only a four-user loopback validation project; preserve its volumes and old environment."""
import hashlib
import importlib.util
import json
import os
from pathlib import Path
import re
import subprocess

from container_runtime_probes import CURRENT_CONSULTATION_AUTHORIZATION, CURRENT_FINANCIAL_AUTHORIZATION, CURRENT_COURSE_ORGANIZATION_AUTHORIZATION, CURRENT_DISCUSSION_ATTACHMENT_AUTHORIZATION, CURRENT_COACH_PHOTO_AUTHORIZATION

ROOT = Path(__file__).resolve().parents[1]
SPEC = importlib.util.spec_from_file_location('recovery', ROOT / 'resources/operations/recovery.py')
recovery = importlib.util.module_from_spec(SPEC)
SPEC.loader.exec_module(recovery)
os.umask(0o077)


def heartbeat():
    owner = os.environ.get('KARDOROST_LEASE_OWNER')
    if owner:
        helper = os.environ.get('KARDOROST_LEASE_HELPER', '/workspace/work/kardorost-autonomy-lease.py')
        result = subprocess.run(['python3', helper, 'touch', owner], stdout=subprocess.DEVNULL)
        assert result.returncode == 0, 'Delivery ownership changed; do not mutate the environment.'


def run(composition, args):
    heartbeat()
    return composition.run(args)


def php(composition, code):
    return run(composition, ['run', '--rm', '--no-deps', '--entrypoint', 'php', 'app', '-r', code])


source_file = Path(os.environ['CONTAINER_SOURCE_ENV_FILE']).resolve()
destination = Path(os.environ['CONTAINER_UPGRADE_ENV_FILE']).resolve()
tag = os.environ['CONTAINER_TARGET_IMAGE_TAG']
revision = os.environ['CONTAINER_TARGET_REVISION']
assert source_file.is_relative_to('/tmp') and destination.is_relative_to('/tmp')
assert destination.name.startswith('kardorost-compose-upgraded')
reuse = os.environ.get('CONTAINER_UPGRADE_REUSE') == '1'
journal = Path(os.environ.get('CONTAINER_UPGRADE_BASELINE_FILE', '/tmp/kardorost-container-upgrade-baseline.json')).resolve()
assert journal.is_relative_to('/tmp') and journal.name.startswith('kardorost-container-upgrade-baseline')
assert not destination.exists() or reuse
assert re.fullmatch(r'[a-zA-Z0-9_.-]+', tag) and re.fullmatch(r'[a-f0-9]{40}', revision)
source = recovery.Composition(source_file)
assert source.config['name'].startswith('kardorost-validation-')
assert source.settings['APP_URL'].startswith('http://127.0.0.1:')
assert source.settings['SMS_DRIVER'] == 'disabled' and source.settings['ZIBAL_ENABLED'] == 'false'
assert reuse or not source.running().difference({'postgres'}), 'Pause the owned source writers before upgrading.'
bootstrap = r"""require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();"""
probe = bootstrap + r"""$lead=\App\Models\ContactLead::sole();$bytes=\Illuminate\Support\Facades\Storage::disk('support')->get('container-acceptance.txt');echo json_encode(['users'=>\App\Models\User::count(),'synthetic'=>\App\Models\User::where('name','like','بررسی کانتینر %')->count(),'lead_ok'=>$lead->name==='درخواست آزمایشی کانتینر','cipher_hash'=>hash('sha256',$lead->getRawOriginal('name')),'encrypted'=>$lead->getRawOriginal('name')!==$lead->name,'file_hash'=>hash('sha256',$bytes),'migration_count'=>\Illuminate\Support\Facades\DB::table('migrations')->count()]);"""
run(source, ['up', '-d', '--no-build', '--wait', '--wait-timeout', '120', 'postgres'])
before = json.loads(journal.read_text()) if reuse else json.loads(php(source, probe))
if not reuse:
    heartbeat()
    journal.write_text(json.dumps(before))
assert before['users'] == before['synthetic'] == 4 and before['lead_ok'] and before['encrypted']
contents = source_file.read_text()
contents, count = re.subn(r'(?m)^IMAGE_TAG=.*$', 'IMAGE_TAG=' + tag, contents)
assert count == 1
contents, count = re.subn(r'(?m)^APP_REVISION=.*$', 'APP_REVISION=' + revision, contents)
assert count == 1
if destination.exists():
    assert destination.read_text() == contents, 'Do not overwrite an unrelated environment.'
else:
    heartbeat()
    fd = os.open(destination, os.O_WRONLY | os.O_CREAT | os.O_EXCL, 0o600)
    with os.fdopen(fd, 'w') as output:
        output.write(contents)
target = recovery.Composition(destination)
assert target.config['name'] == source.config['name']
assert target.settings == source.settings and target.key_hash == source.key_hash
assert target.config['volumes'] == source.config['volumes']
# An identical PostgreSQL image under a new validation tag needs no replacement.
# Keep the healthy database container when its immutable image ID already matches.
postgres_container = run(source, ['ps', '-q', 'postgres']).strip()
postgres_running_image = source.docker(['inspect', '--format', '{{.Image}}', postgres_container]).strip()
postgres_target_image = target.docker(['image', 'inspect', '--format', '{{.Id}}', target.config['services']['postgres']['image']]).strip()
postgres_retained_identical_image = postgres_running_image == postgres_target_image
app_dependencies = ['--no-deps'] if postgres_retained_identical_image else []
run(target, ['up', '-d', '--no-build', *app_dependencies, '--wait', '--wait-timeout', '120', 'app'])
run(target, ['exec', '-T', 'app', 'php', 'artisan', 'migrate', '--force', '--no-interaction'])
after = json.loads(run(target, ['exec', '-T', 'app', 'php', '-r', probe]))
assert {k: v for k, v in after.items() if k != 'migration_count'} == {k: v for k, v in before.items() if k != 'migration_count'}
expected_migrations = len(list((ROOT / 'database/migrations').glob('*.php')))
assert after['migration_count'] == expected_migrations and after['migration_count'] >= before['migration_count']
# Compare the exact tracked application, route, schema, view and configuration files in the new runtime.
files = subprocess.check_output(['git', 'ls-files', '-z', 'app', 'bootstrap', 'config', 'database', 'routes', 'resources', 'composer.json', 'composer.lock', 'artisan'], cwd=ROOT).decode().split('\0')
# The Compose cache tmpfs hides its tracked placeholder in the running container.
files = sorted(name for name in files if name and not name.startswith('bootstrap/cache/') and (ROOT / name).is_file())
expected = {name: hashlib.sha256((ROOT / name).read_bytes()).hexdigest() for name in files}
code = "$files=json_decode(" + json.dumps(json.dumps(files)) + ",true);$hashes=[];foreach($files as $file){$hashes[$file]=hash_file('sha256',$file);}echo json_encode($hashes);"
actual = json.loads(run(target, ['exec', '-T', 'app', 'php', '-r', code]))
assert actual == expected, 'The running image does not contain the exact checked-out application source.'
fixture = bootstrap + r"""if(\App\Models\User::count()!==4||\App\Models\User::where('name','like','بررسی کانتینر %')->count()!==4||\App\Models\ResumeShare::exists()||\App\Models\CoachCredential::exists()){throw new RuntimeException('Use only the owned four-user pre-fixture source.');}$owner=\App\Models\User::where('phone','989121234560')->firstOrFail();$coach=\App\Models\User::where('phone','989121234561')->firstOrFail();$handle=tmpfile();fwrite($handle,"%PDF-1.4\n% SOFTWARE-ONLY-CONTAINER-PRIVATE-CREDENTIAL\n%%EOF\n");$file=new \Illuminate\Http\UploadedFile(stream_get_meta_data($handle)['uri'],'container-software-only.pdf','application/pdf',UPLOAD_ERR_OK,true);$credential=app(\App\Services\CoachCredentials::class)->submit($coach,['title'=>'مدرک صرفاً آزمایشی کانتینر، بدون اعتبار واقعی','issuer'=>'نمونه نرم‌افزاری','consent'=>true,'request_key'=>(string)\Illuminate\Support\Str::uuid()],$file);$builder=app(\App\Services\ResumeBuilder::class);$resume=$builder->create($owner,'اشتراک رزومه صرفاً آزمایشی کانتینر');$builder->save($owner,$resume,1,$resume->title,[...$resume->draft,'name'=>'نام نسخه آزمایشی کانتینر','headline'=>'توسعه‌دهنده نمونه نرم‌افزاری','summary'=>'این متن فقط برای بررسی بازیابی کانتینر است.','phone'=>'09120000099','email'=>'container-private-source@example.test']);$version=$builder->snapshot($owner,$resume,2);app(\App\Services\ResumeSharing::class)->publish($owner,$resume,$version,['revision'=>3,'request_key'=>(string)\Illuminate\Support\Str::uuid(),'duration_days'=>7,'include_contacts'=>false,'reviewed'=>true,'consent'=>true]);echo 'SYNTHETIC_LATEST_FIXTURES_CREATED';"""
if os.environ.get('CONTAINER_UPGRADE_REUSE_LATEST_FIXTURES') == '1':
    retained = bootstrap + r"""$c=\App\Models\CoachCredential::sole();$s=\App\Models\ResumeShare::sole();$m=app(\App\Services\CoachCredentials::class)->metadata($c);$data=app(\App\Services\ResumeSharing::class)->metadata($s);$payload=app(\App\Services\ResumeSharing::class)->publicPayload($data['token']);if($m['title']!=='مدرک صرفاً آزمایشی کانتینر، بدون اعتبار واقعی'||$payload['definition']['name']!=='نام نسخه آزمایشی کانتینر'||hash('sha256',\Illuminate\Support\Facades\Storage::disk('learning')->get($c->file_path))!==$m['sha256']){throw new RuntimeException('Preserved synthetic fixture changed.');}echo 'LATEST_FIXTURES_RETAINED';"""
    assert run(target, ['exec', '-T', 'app', 'php', '-r', retained]).strip() == 'LATEST_FIXTURES_RETAINED'
else:
    assert run(target, ['exec', '-T', 'app', 'php', '-r', fixture]).strip() == 'SYNTHETIC_LATEST_FIXTURES_CREATED'


if os.environ.get('CONTAINER_EXPECT_CURRENT_CONSULTATION_AUTH') == '1':
    assert run(target, ['exec', '-T', 'app', 'php', '-r', CURRENT_CONSULTATION_AUTHORIZATION]).strip() == 'NINE_PRODUCTION_AUTHORIZATION_FIXTURE_ROWS_ROLLED_BACK'
if os.environ.get('CONTAINER_EXPECT_CURRENT_FINANCIAL_AUTH') == '1':
    assert run(target, ['exec', '-T', 'app', 'php', '-r', CURRENT_FINANCIAL_AUTHORIZATION]).strip() == 'TWENTY_TWO_PRODUCTION_FINANCIAL_REFUSALS_FROZEN_PURCHASE_AND_FIXTURE_ROWS_ROLLED_BACK'

if os.environ.get('CONTAINER_EXPECT_CURRENT_COURSE_ORGANIZATION_AUTH') == '1':
    assert run(target, ['exec', '-T', 'app', 'php', '-r', CURRENT_COURSE_ORGANIZATION_AUTHORIZATION]).strip() == 'THIRTY_TWO_PRODUCTION_COURSE_ORGANIZATION_REFUSALS_FROZEN_QUOTE_AND_FIXTURE_ROWS_ROLLED_BACK'

if os.environ.get('CONTAINER_EXPECT_DISCUSSION_AUTH') == '1':
    assert run(target, ['exec', '-T', 'app', 'php', '-r', CURRENT_DISCUSSION_ATTACHMENT_AUTHORIZATION]).strip() == 'TWENTY_SEVEN_PRODUCTION_DISCUSSION_FILE_REFUSALS_THREE_VALIDATIONS_PRIVATE_BYTES_AND_HISTORY_ROLLED_BACK'

if os.environ.get('CONTAINER_EXPECT_COACH_PHOTO_AUTH') == '1':
    assert run(target, ['exec', '-T', 'app', 'php', '-r', CURRENT_COACH_PHOTO_AUTHORIZATION]).strip() == 'TWENTY_FIVE_PRODUCTION_COACH_PHOTO_REFUSALS_FOUR_VALIDATIONS_PRIVATE_BYTES_CONSENT_AND_ROWS_ROLLED_BACK'

run(target, ['up', '-d', '--no-build', '--no-deps', '--wait', '--wait-timeout', '120', 'web', 'worker-default', 'worker-sms', 'scheduler'])
assert target.running() == {'postgres', 'app', 'web', 'worker-default', 'worker-sms', 'scheduler'}
report = {'result': 'passed', 'scope': 'owned_loopback_four_synthetic_users_same_project_volumes_and_keys', 'before_migrations': before['migration_count'], 'upgrade_kind': 'schema_and_code' if after['migration_count'] > before['migration_count'] else 'code_only', 'after_migrations': after['migration_count'], 'tracked_source_files_matched': len(files), 'checks': ['existing_encrypted_lead_ciphertext_and_plaintext_preserved', 'existing_private_support_file_hash_preserved', 'all_pending_migrations_applied_without_reseeding', 'exact_checked_out_application_source_in_non_development_image', 'encrypted_coach_credential_and_original_private_file_checked', 'owner_consented_source_bound_resume_share_checked', 'six_services_started_with_external_effects_disabled']}
report['postgres_identical_image_container_retained'] = postgres_retained_identical_image
report['latest_fixture_mode'] = 'retained' if os.environ.get('CONTAINER_UPGRADE_REUSE_LATEST_FIXTURES') == '1' else 'created'
if os.environ.get('CONTAINER_EXPECT_CURRENT_CONSULTATION_AUTH') == '1':
    report['checks'].append('nine_current_actor_production_authorization_refusals_all_fixture_rows_rolled_back')
if os.environ.get('CONTAINER_EXPECT_CURRENT_FINANCIAL_AUTH') == '1':
    report['checks'].append('twenty_two_current_financial_production_refusals_frozen_purchase_preserved_all_fixture_rows_rolled_back')
if os.environ.get('CONTAINER_EXPECT_CURRENT_COURSE_ORGANIZATION_AUTH') == '1':
    report['checks'].append('thirty_two_current_course_organization_production_refusals_frozen_quote_and_unavailable_future_coach_guard_all_fixture_rows_rolled_back')
if os.environ.get('CONTAINER_EXPECT_DISCUSSION_AUTH') == '1':
    report['checks'].append('twenty_seven_private_discussion_file_refusals_three_specific_validations_original_bytes_history_and_synthetic_rows_rolled_back')
if os.environ.get('CONTAINER_EXPECT_COACH_PHOTO_AUTH') == '1':
    report['checks'].append('twenty_five_current_coach_photo_refusals_four_specific_validations_prepared_bytes_consent_and_probe_rows_rolled_back')
heartbeat()
Path('/tmp/kardorost-container-upgrade-acceptance.json').write_text(json.dumps(report))
print(json.dumps(report))
