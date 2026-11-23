<?php

namespace Tests\Feature;

use App\Models\CoachProfile;
use App\Models\CoachProfilePhoto;
use App\Models\Role;
use App\Models\User;
use App\Services\CoachProfilePhotos;
use App\Services\CoachProfiles;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class CoachProfilePhotoConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    private User $owner;

    private User $admin;

    private UploadedFile $file;

    protected function setUp(): void
    {
        parent::setUp();
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Photo account, profile and file lock races require PostgreSQL.');
        }
        Storage::fake('learning');
        $this->owner = User::factory()->create();
        $this->admin = User::factory()->create();
        $this->owner->roles()->attach(Role::where('key', 'coach')->value('id'));
        $this->admin->roles()->attach(Role::where('key', 'admin')->value('id'));
        $chunk = fn ($t, $b) => pack('N', strlen($b)).$t.$b.hash('crc32b', $t.$b, true);
        $bytes = "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', 1, 1, 8, 6, 0, 0, 0)).$chunk('IDAT', gzcompress("\0\x10\x20\x30\xff")).$chunk('IEND', '');
        $this->file = UploadedFile::fake()->createWithContent('software-photo79.png', $bytes);
    }

    protected function tearDown(): void
    {
        if (DB::connection()->getDriverName() === 'pgsql') {
            DB::table('coach_profiles')->update(['current_publication_id' => null, 'latest_submission_id' => null]);
            foreach (['coach_profile_withdrawals', 'coach_profile_publications', 'coach_profile_submissions', 'coach_profiles'] as $table) {
                DB::table($table)->delete();
            }
        }
        parent::tearDown();
    }

    private function photo(): CoachProfilePhoto
    {
        return app(CoachProfilePhotos::class)->upload($this->owner, ['title' => 'Software-only79', 'consent' => true, 'request_key' => (string) Str::uuid()], $this->file)->fresh();
    }

    private function submitted(CoachProfilePhoto $photo): CoachProfile
    {
        $definition = CoachProfile::factory()->make()->draft;
        $definition['photo'] = ['key' => $photo->public_key, 'sha256' => app(CoachProfilePhotos::class)->metadata($photo)['sha256']];
        $profile = app(CoachProfiles::class)->save($this->owner, $definition, 0);
        app(CoachProfiles::class)->submit($this->owner, $profile, 1, true);

        return $profile->fresh();
    }

    private function fingerprint(): string
    {
        $state = [];
        foreach (['users', 'role_user', 'coach_profile_photos', 'coach_profiles', 'coach_profile_submissions', 'coach_profile_publications', 'audit_events', 'notifications'] as $table) {
            $rows = DB::table($table)->get()->map(fn ($row) => json_encode($row, JSON_THROW_ON_ERROR))->all();
            sort($rows, SORT_STRING);
            $state[$table] = $rows;
        }
        $files = [];
        foreach (Storage::disk('learning')->allFiles() as $path) {
            $files[$path] = hash('sha256', Storage::disk('learning')->get($path));
        }
        ksort($files);

        return hash('sha256', json_encode([$state, $files], JSON_THROW_ON_ERROR));
    }

    private function process(string $action, string $barrier, ?CoachProfilePhoto $photo = null, ?CoachProfile $profile = null, ?string $key = null): Process
    {
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();config(['filesystems.disks.learning.root'=>$argv[4]]);$owner=\App\Models\User::findOrFail((int)$argv[1]);$admin=\App\Models\User::findOrFail((int)$argv[2]);$photo=$argv[3]?\App\Models\CoachProfilePhoto::findOrFail((int)$argv[3]):null;$profile=$argv[8]?\App\Models\CoachProfile::findOrFail((int)$argv[8]):null;$action=$argv[6];$barrier=$argv[7];$checked=false;\Illuminate\Support\Facades\DB::connection()->beforeExecuting(function($sql)use(&$checked,$barrier){if(!$checked&&str_contains($sql,'"'.$barrier.'"')&&(str_contains($sql,'for update')||str_contains($sql,'for share'))){$checked=true;echo "CHECKED\n";flush();}});try{$service=app(\App\Services\CoachProfilePhotos::class);if($action==='upload'){$p=$service->upload($owner,['title'=>'Software-only79','consent'=>true,'request_key'=>$argv[9]],new \Illuminate\Http\UploadedFile($argv[5],'software-photo79.png',null,null,true));echo 'UPLOADED:'.$p->id;}elseif($action==='withdraw'||$action==='admin_withdraw'){$service->withdraw($action==='withdraw'?$owner:$admin,$photo,1,$action==='withdraw'?null:'Software review79');echo 'WITHDRAWN';}elseif($action==='review'){app(\App\Services\CoachProfiles::class)->review($admin,$profile,2,'publish',null);echo 'PUBLISHED';}else{$prepared=$service->publicFile($profile->slug,$photo->public_key,$service->metadata($photo)['sha256']);fclose($prepared['stream']);echo 'PUBLIC';}}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){echo 'HTTP:'.$e->getStatusCode();}catch(\Illuminate\Validation\ValidationException){echo 'INVALID';}
CODE;

        return new Process([PHP_BINARY, '-r', $code, (string) $this->owner->id, (string) $this->admin->id, (string) ($photo?->id ?? ''), Storage::disk('learning')->path(''), $this->file->getRealPath(), $action, $barrier, (string) ($profile?->id ?? ''), $key ?? (string) Str::uuid()], base_path(), null, null, 20);
    }

    private function checked(Process $process): void
    {
        $process->start();
        $this->assertTrue($process->waitUntil(fn ($type, $output) => str_contains($output, 'CHECKED')), $process->getErrorOutput());
    }

    private function finish(Process $process): string
    {
        try {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());

            return trim(str_replace('CHECKED', '', $process->getOutput()));
        } finally {
            if ($process->isRunning()) {
                $process->stop();
            }
        }
    }

    public static function permissionCases(): array
    {
        $cases = [];
        foreach (['upload', 'admin_withdraw'] as $action) {
            foreach (['role', 'activity', 'verified'] as $change) {
                if ($action === 'admin_withdraw' && $change === 'verified') {
                    continue;
                }
                $cases[] = [$action, $change];
            }
        }

        return $cases;
    }

    #[DataProvider('permissionCases')]
    public function test_current_upload_or_admin_withdraw_permission_is_checked_after_account_wait(string $action, string $change): void
    {
        $photo = $action === 'upload' ? null : $this->photo();
        $actor = $action === 'upload' ? $this->owner : $this->admin;
        $process = $this->process($action, 'users', $photo);
        $expected = null;
        DB::transaction(function () use ($actor, $change, $process, &$expected): void {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $this->checked($process);
            if ($change === 'role') {
                $actor->roles()->detach();
            } elseif ($change === 'activity') {
                $actor->forceFill(['is_active' => false])->save();
            } else {
                $actor->update(['phone_verified_at' => null]);
            }
            $expected = $this->fingerprint();
        });
        $this->assertSame('HTTP:403', $this->finish($process));
        $this->assertSame($expected, $this->fingerprint());
    }

    public function test_owner_can_withdraw_when_coach_role_is_removed_during_account_wait(): void
    {
        $photo = $this->photo();
        $process = $this->process('withdraw', 'users', $photo);
        DB::transaction(function () use ($process): void {
            User::whereKey($this->owner->id)->lockForUpdate()->firstOrFail();
            $this->checked($process);
            $this->owner->roles()->detach();
        });
        $this->assertSame('WITHDRAWN', $this->finish($process));
        $this->assertNotNull($photo->fresh()->withdrawn_at);
        Storage::disk('learning')->assertExists($photo->file_path);
    }

    public function test_withdrawal_committed_while_review_waits_on_photo_prevents_publication(): void
    {
        $photo = $this->photo();
        $profile = $this->submitted($photo);
        $process = $this->process('review', 'coach_profile_photos', $photo, $profile);
        $expected = null;
        DB::transaction(function () use ($photo, $process, &$expected): void {
            CoachProfilePhoto::whereKey($photo->id)->lockForUpdate()->firstOrFail();
            $this->checked($process);
            $stamp = now()->startOfSecond();
            $photo->update(['withdrawn_at' => $stamp, 'withdrawn_by' => $this->owner->id, 'revision' => 2, 'withdrawal' => ['photo_id' => $photo->id, 'user_id' => $photo->user_id, 'actor_id' => $this->owner->id, 'revision' => 2, 'at' => $stamp->toISOString(), 'reason' => 'Committed earlier withdrawal79']]);
            $expected = $this->fingerprint();
        });
        $this->assertSame('INVALID', $this->finish($process));
        $this->assertSame($expected, $this->fingerprint());
        $this->assertDatabaseCount('coach_profile_publications', 0);
    }

    public function test_public_read_rechecks_owner_role_after_shared_account_lock_wait(): void
    {
        $photo = $this->photo();
        $profile = $this->submitted($photo);
        app(CoachProfiles::class)->review($this->admin, $profile, 2, 'publish', null);
        $process = $this->process('public', 'users', $photo, $profile->fresh());
        DB::transaction(function () use ($process): void {
            User::whereKey($this->owner->id)->lockForUpdate()->firstOrFail();
            $this->checked($process);
            $this->owner->roles()->detach();
        });
        $this->assertSame('HTTP:404', $this->finish($process));
    }

    public static function uploadCases(): array
    {
        return [['same_nonce'], ['last_slot']];
    }

    #[DataProvider('uploadCases')]
    public function test_simultaneous_uploads_preserve_idempotency_and_lifetime_limit(string $scenario): void
    {
        if ($scenario === 'last_slot') {
            CoachProfilePhoto::factory()->count(19)->create(['user_id' => $this->owner->id, 'withdrawn_at' => now()]);
        }
        $key = (string) Str::uuid();
        $processes = [$this->process('upload', 'users', key: $key), $this->process('upload', 'users', key: $scenario === 'same_nonce' ? $key : (string) Str::uuid())];
        DB::transaction(function () use ($processes): void {
            User::whereKey($this->owner->id)->lockForUpdate()->firstOrFail();
            foreach ($processes as $process) {
                $this->checked($process);
            }
        });
        $outputs = array_map(fn ($process) => $this->finish($process), $processes);
        if ($scenario === 'same_nonce') {
            $this->assertSame($outputs[0], $outputs[1]);
            $this->assertStringStartsWith('UPLOADED:', $outputs[0]);
        } else {
            $this->assertCount(1, array_filter($outputs, fn ($o) => $o === 'INVALID'));
            $this->assertCount(1, array_filter($outputs, fn ($o) => str_starts_with($o, 'UPLOADED:')));
        }
        $this->assertDatabaseCount('coach_profile_photos', $scenario === 'same_nonce' ? 1 : 20);
        $this->assertCount(1, Storage::disk('learning')->allFiles());
    }
}
