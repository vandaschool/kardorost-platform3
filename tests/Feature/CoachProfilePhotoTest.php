<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\CoachProfile;
use App\Models\CoachProfilePhoto;
use App\Models\Role;
use App\Models\User;
use App\Services\CoachProfilePhotos;
use App\Services\CoachProfiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

class CoachProfilePhotoTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $admin;

    private User $peer;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        Storage::fake('learning');
        $this->owner = $this->user('coach');
        $this->peer = $this->user('coach');
        $this->admin = $this->user('admin');
        $this->student = $this->user('student');
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('key', $role)->value('id'));

        return $user;
    }

    private function file(string $pixel = "\x10\x20\x30\xff"): UploadedFile
    {
        $chunk = fn ($t, $b) => pack('N', strlen($b)).$t.$b.hash('crc32b', $t.$b, true);
        $bytes = "\x89PNG\r\n\x1a\n".$chunk('IHDR', pack('NNCCCCC', 1, 1, 8, 6, 0, 0, 0)).$chunk('tEXt', "GPS\0PRIVATE-SOFTWARE-METADATA").$chunk('IDAT', gzcompress("\0".$pixel)).$chunk('IEND', '');

        return UploadedFile::fake()->createWithContent('private-original79.png', $bytes);
    }

    private function input(?string $key = null): array
    {
        return ['title' => 'عنوان خصوصی عکس آزمایشی۷۹', 'consent' => true, 'request_key' => $key ?? (string) Str::uuid()];
    }

    private function photo(?User $owner = null): CoachProfilePhoto
    {
        return app(CoachProfilePhotos::class)->upload($owner ?? $this->owner, $this->input(), $this->file())->fresh();
    }

    private function ref(CoachProfilePhoto $photo): array
    {
        return ['key' => $photo->public_key, 'sha256' => app(CoachProfilePhotos::class)->metadata($photo)['sha256']];
    }

    private function draft(CoachProfilePhoto $photo): CoachProfile
    {
        $draft = CoachProfile::factory()->make()->draft;

        return app(CoachProfiles::class)->save($this->owner, [...$draft, 'photo' => $this->ref($photo)], 0)->fresh();
    }

    private function publish(CoachProfilePhoto $photo): CoachProfile
    {
        $profile = $this->draft($photo);
        app(CoachProfiles::class)->submit($this->owner, $profile, 1, true);
        app(CoachProfiles::class)->review($this->admin, $profile, 2, 'publish', null);

        return $profile->fresh();
    }

    private function url(CoachProfile $profile, CoachProfilePhoto $photo): string
    {
        return '/mentor-photos/'.$profile->slug.'/'.$photo->public_key.'/'.$this->ref($photo)['sha256'];
    }

    private function invalid(callable $operation): void
    {
        try {
            $operation();
            $this->fail('Expected validation refusal.');
        } catch (ValidationException) {
            $this->assertTrue(true);
        }
    }

    public function test_upload_is_private_prepared_source_bound_encrypted_and_only_owner_or_current_admin_can_preview(): void
    {
        $photo = $this->photo();
        $metadata = app(CoachProfilePhotos::class)->metadata($photo);
        $bytes = Storage::disk('learning')->get($photo->file_path);
        $this->assertStringNotContainsString('PRIVATE-SOFTWARE-METADATA', $bytes);
        $this->assertSame(hash('sha256', $bytes), $metadata['sha256']);
        $this->assertSame($photo->id, $metadata['photo_id']);
        $this->assertSame(CoachProfilePhotos::CONSENT, $metadata['consent_text']);
        $this->assertStringNotContainsString($metadata['title'], DB::table('coach_profile_photos')->value('definition'));
        foreach ([$this->owner, $this->admin] as $actor) {
            $response = $this->actingAs($actor)->get('/coach/profile-photos/'.$photo->public_key.'/preview')->assertOk()->assertHeader('Content-Type', 'image/png')->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
            $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
            $this->assertSame($bytes, $response->streamedContent());
            $this->assertStringNotContainsString('private-original', $response->headers->get('Content-Disposition'));
        }
        foreach ([$this->peer, $this->student] as $actor) {
            $this->actingAs($actor)->get('/coach/profile-photos/'.$photo->public_key.'/preview')->assertForbidden();
        }
        $this->assertDatabaseCount('coach_profiles', 0);
        $this->assertCount(1, Storage::disk('learning')->allFiles());
    }

    public function test_only_selected_consented_admin_reviewed_current_profile_photo_is_public(): void
    {
        $photo = $this->photo();
        $profile = $this->draft($photo);
        $url = $this->url($profile, $photo);
        $this->get($url)->assertNotFound();
        $this->invalid(fn () => app(CoachProfiles::class)->submit($this->owner, $profile, 1, false));
        app(CoachProfiles::class)->submit($this->owner, $profile, 1, true);
        $this->get($url)->assertNotFound();
        app(CoachProfiles::class)->review($this->admin, $profile, 2, 'publish', null);
        $this->get($url)->assertOk()->assertHeader('Content-Length', (string) app(CoachProfilePhotos::class)->metadata($photo)['size']);
        foreach (['/mentors', '/mentors/'.$profile->slug] as $page) {
            $this->get($page)->assertOk()->assertSee($url, false)->assertDontSee('private-original79')->assertDontSee($this->owner->phone)->assertDontSee('عنوان خصوصی عکس');
        }
        $this->assertSame($url, parse_url(app(CoachProfilePhotos::class)->listing($this->owner, $this->owner->id)[0]['public_url'], PHP_URL_PATH));
    }

    public function test_draft_replacement_and_new_review_switch_public_photo_without_rewriting_old_consent_or_bytes(): void
    {
        $first = $this->photo();
        $profile = $this->publish($first);
        $oldPublication = DB::table('coach_profile_publications')->where('id', $profile->current_publication_id)->first();
        $second = $this->photo();
        $next = [...$profile->draft, 'photo' => $this->ref($second)];
        app(CoachProfiles::class)->save($this->owner, $next, 3);
        $this->get($this->url($profile, $first))->assertOk();
        $this->get($this->url($profile, $second))->assertNotFound();
        app(CoachProfiles::class)->submit($this->owner, $profile->fresh(), 4, true);
        $this->get($this->url($profile, $second))->assertNotFound();
        app(CoachProfiles::class)->review($this->admin, $profile, 5, 'publish', null);
        $this->get($this->url($profile, $first))->assertNotFound();
        $this->get($this->url($profile, $second))->assertOk();
        $this->assertEquals($oldPublication, DB::table('coach_profile_publications')->where('id', $oldPublication->id)->first());
        $this->assertCount(2, Storage::disk('learning')->allFiles());
    }

    public function test_owner_can_withdraw_after_role_loss_retaining_frozen_publication_original_metadata_and_file(): void
    {
        $photo = $this->photo();
        $profile = $this->publish($photo);
        $ciphertext = DB::table('coach_profile_photos')->value('definition');
        $publication = DB::table('coach_profile_publications')->first();
        $bytes = Storage::disk('learning')->get($photo->file_path);
        $this->owner->roles()->detach();
        $this->actingAs($this->owner)->get('/coach/profile-photos')->assertOk();
        $this->post('/coach/profile-photos', [...$this->input(), 'file' => $this->file()])->assertForbidden();
        $this->post('/coach/profile-photos/'.$photo->id.'/withdraw', ['revision' => 1])->assertSessionHasNoErrors();
        $this->get('/coach/profile-photos/'.$photo->public_key.'/preview')->assertNotFound();
        $this->get($this->url($profile, $photo))->assertNotFound();
        $this->assertSame($bytes, Storage::disk('learning')->get($photo->file_path));
        $this->assertSame($ciphertext, DB::table('coach_profile_photos')->value('definition'));
        $this->assertEquals($publication, DB::table('coach_profile_publications')->first());
        $this->assertNotNull(app(CoachProfilePhotos::class)->withdrawal($photo->fresh()));
        $this->assertDatabaseCount('notifications', 1); // Profile review only; own withdrawal sends no notification.
        $this->invalid(fn () => app(CoachProfilePhotos::class)->withdraw($this->owner, $photo, 2, null));
    }

    public function test_withdrawing_pending_photo_blocks_review_and_keeps_snapshot_and_prior_publication(): void
    {
        $first = $this->photo();
        $profile = $this->publish($first);
        $second = $this->photo();
        app(CoachProfiles::class)->save($this->owner, [...$profile->draft, 'photo' => $this->ref($second)], 3);
        app(CoachProfiles::class)->submit($this->owner, $profile->fresh(), 4, true);
        $snapshot = DB::table('coach_profile_submissions')->latest('id')->first();
        app(CoachProfilePhotos::class)->withdraw($this->owner, $second, 1, null);
        $this->invalid(fn () => app(CoachProfiles::class)->review($this->admin, $profile, 5, 'publish', null));
        $this->assertEquals($snapshot, DB::table('coach_profile_submissions')->latest('id')->first());
        $this->get($this->url($profile, $first))->assertOk();
        $this->assertDatabaseCount('coach_profile_publications', 1);
    }

    public function test_admin_withdrawal_requires_nonblank_reason_and_sends_only_database_notification(): void
    {
        $photo = $this->photo();
        foreach ([null, '   '] as $reason) {
            $this->invalid(fn () => app(CoachProfilePhotos::class)->withdraw($this->admin, $photo, 1, $reason));
        }
        app(CoachProfilePhotos::class)->withdraw($this->admin, $photo, 1, 'دلیل خصوصی مدیریت');
        $withdrawal = app(CoachProfilePhotos::class)->withdrawal($photo->fresh());
        $this->assertSame($this->admin->id, $withdrawal['actor_id']);
        $this->assertSame('دلیل خصوصی مدیریت', $withdrawal['reason']);
        $notification = $this->owner->notifications()->sole();
        $this->assertStringNotContainsString('دلیل خصوصی', json_encode($notification->data));
        $this->assertDatabaseCount('notifications', 1);
        $this->assertStringNotContainsString('دلیل خصوصی', DB::table('coach_profile_photos')->value('withdrawal'));
    }

    public function test_nonce_retry_returns_same_prepared_copy_even_after_withdrawal_and_never_reopens_it(): void
    {
        $input = $this->input();
        $service = app(CoachProfilePhotos::class);
        $photo = $service->upload($this->owner, $input, $this->file());
        $this->assertSame($photo->id, $service->upload($this->owner, $input, $this->file())->id);
        $this->invalid(fn () => $service->upload($this->owner, [...$input, 'title' => 'عنوان دیگر'], $this->file()));
        $this->invalid(fn () => $service->upload($this->owner, $input, $this->file("\x80\x20\x30\xff")));
        $service->withdraw($this->owner, $photo, 1, null);
        $this->assertNotNull($service->upload($this->owner, $input, $this->file())->withdrawn_at);
        $this->assertDatabaseCount('coach_profile_photos', 1);
        $this->assertCount(1, Storage::disk('learning')->allFiles());
    }

    public function test_lifetime_limit_counts_withdrawn_rows_and_failed_audit_deletes_only_its_new_file(): void
    {
        $photo = $this->photo();
        $original = Storage::disk('learning')->get($photo->file_path);
        AuditEvent::creating(fn () => throw new RuntimeException('Intentional audit failure'));
        try {
            app(CoachProfilePhotos::class)->upload($this->owner, $this->input(), $this->file());
            $this->fail('Expected audit failure');
        } catch (RuntimeException $e) {
            $this->assertSame('Intentional audit failure', $e->getMessage());
        } finally {
            AuditEvent::flushEventListeners();
        }
        $this->assertDatabaseCount('coach_profile_photos', 1);
        $this->assertSame($original, Storage::disk('learning')->get($photo->file_path));
        $this->assertCount(1, Storage::disk('learning')->allFiles());
        CoachProfilePhoto::factory()->count(19)->create(['user_id' => $this->owner->id, 'withdrawn_at' => now()]);
        $this->invalid(fn () => $this->photo());
        $this->assertDatabaseCount('coach_profile_photos', 20);
        $this->assertCount(1, Storage::disk('learning')->allFiles());
    }

    public function test_foreign_unknown_or_malformed_photo_references_cannot_be_saved(): void
    {
        $photo = $this->photo($this->peer);
        $draft = CoachProfile::factory()->make()->draft;
        foreach ([$this->ref($photo), ['key' => (string) Str::uuid(), 'sha256' => str_repeat('a', 64)], ['key' => $photo->public_key, 'sha256' => 'bad'], [...$this->ref($photo), 'user_id' => $this->owner->id], '<img>'] as $ref) {
            $this->invalid(fn () => app(CoachProfiles::class)->save($this->owner, [...$draft, 'photo' => $ref], 0));
        }
        $this->assertDatabaseCount('coach_profiles', 0);
    }

    public static function corruptionCases(): array
    {
        return array_map(fn ($x) => [$x], ['ciphertext', 'path', 'bytes', 'missing', 'consent_time']);
    }

    #[DataProvider('corruptionCases')]
    public function test_invalid_original_or_source_binding_fails_closed_for_private_and_public_receiving(string $case): void
    {
        $photo = $this->photo();
        $profile = $this->publish($photo);
        $url = $this->url($profile, $photo);
        if ($case === 'ciphertext') {
            $other = $this->photo();
            DB::table('coach_profile_photos')->where('id', $photo->id)->update(['definition' => DB::table('coach_profile_photos')->where('id', $other->id)->value('definition')]);
        } elseif ($case === 'path') {
            $photo->update(['file_path' => 'other.png']);
        } elseif ($case === 'bytes') {
            Storage::disk('learning')->put($photo->file_path, 'tampered');
        } elseif ($case === 'missing') {
            Storage::disk('learning')->delete($photo->file_path);
        } else {
            $photo->update(['consented_at' => now()->addDay()]);
        }
        $this->actingAs($this->owner)->get('/coach/profile-photos/'.$photo->public_key.'/preview')->assertNotFound();
        $this->get($url)->assertNotFound();
    }

    public static function parentCases(): array
    {
        return array_map(fn ($x) => [$x], ['inactive', 'unverified', 'role', 'disabled', 'hash', 'foreign_publication']);
    }

    #[DataProvider('parentCases')]
    public function test_public_file_rechecks_current_parent_and_owner_instead_of_leaking_historical_photo(string $case): void
    {
        $photo = $this->photo();
        $profile = $this->publish($photo);
        $url = $this->url($profile, $photo);
        if ($case === 'inactive') {
            $this->owner->forceFill(['is_active' => false])->save();
        } elseif ($case === 'unverified') {
            $this->owner->update(['phone_verified_at' => null]);
        } elseif ($case === 'role') {
            $this->owner->roles()->detach();
        } elseif ($case === 'disabled') {
            $profile->update(['public_enabled' => false]);
        } elseif ($case === 'hash') {
            $profile->currentPublication->update(['definition_hash' => str_repeat('f', 64)]);
        } else {
            $peer = CoachProfile::factory()->create(['user_id' => $this->peer->id]);
            app(CoachProfiles::class)->submit($this->peer, $peer, 1, true);
            app(CoachProfiles::class)->review($this->admin, $peer, 2, 'publish', null);
            $profile->update(['current_publication_id' => $peer->fresh()->current_publication_id]);
        }
        $this->get($url)->assertNotFound();
        $this->assertNull(app(CoachProfilePhotos::class)->publicImage($profile->fresh()));
    }

    public function test_active_owner_withdrawal_removes_public_image_without_hiding_profile_or_altering_history(): void
    {
        $photo = $this->photo();
        $profile = $this->publish($photo);
        $before = DB::table('coach_profile_publications')->first();
        app(CoachProfilePhotos::class)->withdraw($this->owner, $photo, 1, null);
        $this->get($this->url($profile, $photo))->assertNotFound();
        $this->get('/mentors/'.$profile->slug)->assertOk()->assertDontSee('data-public-coach-photo', false);
        $this->assertEquals($before, DB::table('coach_profile_publications')->first());
        Storage::disk('learning')->assertExists($photo->file_path);
        $this->actingAs($this->admin)->get('/coach/profile-photos/'.$photo->public_key.'/preview')->assertNotFound();
    }

    public function test_legacy_profile_without_photo_keeps_the_same_definition_and_hash(): void
    {
        $draft = CoachProfile::factory()->make()->draft;
        $profile = app(CoachProfiles::class)->save($this->owner, [...$draft, 'photo' => null], 0);
        app(CoachProfiles::class)->submit($this->owner, $profile, 1, true);
        $submission = $profile->fresh()->latestSubmission;
        $this->assertSame($draft, $submission->definition);
        $this->assertSame(hash('sha256', json_encode($draft, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), $submission->definition_hash);
        $this->assertArrayNotHasKey('photo', $submission->definition);
    }

    public function test_upload_requires_owner_coach_current_activity_verification_and_explicit_consent(): void
    {
        $this->actingAs($this->student)->post('/coach/profile-photos', [...$this->input(), 'file' => $this->file()])->assertForbidden();
        $this->actingAs($this->admin)->post('/coach/profile-photos', [...$this->input(), 'file' => $this->file()])->assertForbidden();
        $this->actingAs($this->owner)->post('/coach/profile-photos', [...$this->input(), 'consent' => false, 'file' => $this->file()])->assertSessionHasErrors('consent');
        $this->owner->update(['phone_verified_at' => null]);
        $this->post('/coach/profile-photos', [...$this->input(), 'file' => $this->file()])->assertForbidden();
        $this->assertDatabaseCount('coach_profile_photos', 0);
    }
}
