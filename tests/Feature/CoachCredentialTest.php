<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\CoachCredential;
use App\Models\CoachCredentialEvent;
use App\Models\Role;
use App\Models\User;
use App\Notifications\CoachCredentialChanged;
use App\Services\CoachCredentials;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CoachCredentialTest extends TestCase
{
    use RefreshDatabase;

    private const PDF = "%PDF-1.4\n% SOFTWARE-TEST-ONLY-NOT-A-REAL-CREDENTIAL\n1 0 obj << /Type /Catalog >> endobj\n%%EOF\n";

    private User $coach;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        Storage::fake('learning');
        $this->coach = $this->user('coach');
        $this->admin = $this->user('admin');
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('key', $role)->value('id'));

        return $user;
    }

    private function input(array $changes = []): array
    {
        return ['title' => 'PRIVATE-SOFTWARE-CREDENTIAL', 'issuer' => 'Software fixture, no real credential', 'note' => 'PRIVATE-SELF-DESCRIPTION', 'consent' => true, 'request_key' => (string) Str::uuid(), ...$changes];
    }

    private function file(string $name = 'software-fixture.pdf'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($name, self::PDF);
    }

    private function submit(?User $coach = null, array $changes = []): CoachCredential
    {
        return app(CoachCredentials::class)->submit($coach ?? $this->coach, $this->input($changes), $this->file());
    }

    private function review(CoachCredential $credential, string $action = 'accept', string $reason = 'PRIVATE-ADMIN-REASON'): void
    {
        app(CoachCredentials::class)->change($this->admin, $credential, ['revision' => $credential->fresh()->revision, 'action' => $action, 'reason' => $reason, 'reviewed' => true]);
    }

    public function test_coach_submits_real_private_file_with_bound_encrypted_metadata_and_consent(): void
    {
        $response = $this->actingAs($this->coach)->post('/coach-credentials', [...$this->input(), 'file' => $this->file()])->assertSessionHasNoErrors();
        $credential = CoachCredential::sole();
        $response->assertRedirect('/coach-credentials/'.$credential->id);
        $this->assertSame($this->coach->id, $credential->user_id);
        $this->assertSame('submitted', $credential->status);
        $this->assertSame(1, $credential->revision);
        $this->assertSame($credential->id, $credential->definition['credential_id']);
        $this->assertSame($this->coach->id, $credential->definition['user_id']);
        $this->assertSame('private_internal_review_v1', $credential->definition['consent_version']);
        $this->assertSame(CoachCredentials::CONSENT, $credential->definition['consent_text']);
        $this->assertStringNotContainsString('PRIVATE-SOFTWARE-CREDENTIAL', $credential->getRawOriginal('definition'));
        $this->assertStringNotContainsString('software-fixture.pdf', $credential->getRawOriginal('definition'));
        $this->assertStringNotContainsString('coach-credentials/', $credential->getRawOriginal('file_path'));
        $this->assertStringStartsWith('coach-credentials/'.$this->coach->id.'/'.$credential->id.'/', $credential->file_path);
        Storage::disk('learning')->assertExists($credential->file_path);
        $this->assertSame(self::PDF, Storage::disk('learning')->get($credential->file_path));
        $this->assertSame(1, $credential->events()->count());
        $this->assertSame(0, DB::table('notifications')->count());
    }

    public function test_duplicate_submission_is_idempotent_and_cannot_reactivate_withdrawn_record(): void
    {
        $input = $this->input();
        $first = app(CoachCredentials::class)->submit($this->coach, $input, $this->file());
        $second = app(CoachCredentials::class)->submit($this->coach, $input, $this->file());
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, CoachCredential::count());
        $this->assertSame(1, CoachCredentialEvent::count());
        $this->assertCount(1, Storage::disk('learning')->allFiles());
        app(CoachCredentials::class)->change($this->coach, $first, ['revision' => 1, 'action' => 'withdraw', 'reason' => 'Synthetic archive']);
        $again = app(CoachCredentials::class)->submit($this->coach, $input, $this->file());
        $this->assertSame('withdrawn', $again->status);
        $this->assertSame(2, CoachCredentialEvent::count());
        $this->assertCount(1, Storage::disk('learning')->allFiles());
    }

    public function test_reusing_request_key_with_different_content_is_rejected_without_new_file(): void
    {
        $input = $this->input();
        app(CoachCredentials::class)->submit($this->coach, $input, $this->file());
        $this->actingAs($this->coach)->post('/coach-credentials', [...$input, 'title' => 'Changed submission', 'file' => $this->file()])->assertSessionHasErrors('request_key');
        $this->assertSame(1, CoachCredential::count());
        $this->assertCount(1, Storage::disk('learning')->allFiles());
    }

    public function test_consent_actual_mime_extension_and_size_are_enforced_before_storage(): void
    {
        $spoofed = UploadedFile::fake()->createWithContent('fake.pdf', '<script>not a pdf</script>');
        $actualUpload = new UploadedFile($spoofed->getRealPath(), 'fake.pdf', 'application/pdf', UPLOAD_ERR_OK, true);
        foreach ([['consent' => false, 'file' => $this->file()], ['file' => $actualUpload], ['file' => $this->file('unsafe.php')], ['file' => UploadedFile::fake()->create('large.pdf', 4097, 'application/pdf')], ['file' => UploadedFile::fake()->createWithContent('unsafe.svg', '<svg xmlns="http://www.w3.org/2000/svg"></svg>')]] as $changes) {
            $this->actingAs($this->coach)->postJson('/coach-credentials', [...$this->input(), ...$changes])->assertUnprocessable();
        }
        $this->assertSame(0, CoachCredential::count());
        $this->assertSame([], Storage::disk('learning')->allFiles());
    }

    public function test_only_current_coach_can_upload_and_request_cannot_change_owner_or_force_review(): void
    {
        foreach (['student', 'org_admin', 'admin'] as $role) {
            $this->actingAs($this->user($role))->post('/coach-credentials', [...$this->input(), 'file' => $this->file()])->assertForbidden();
        }
        $other = $this->user('coach');
        $this->actingAs($this->coach)->post('/coach-credentials', [...$this->input(), 'file' => $this->file(), 'user_id' => $other->id, 'status' => 'accepted', 'revision' => 99, 'file_path' => 'public/forged.php'])->assertSessionHasNoErrors();
        $credential = CoachCredential::sole();
        $this->assertSame($this->coach->id, $credential->user_id);
        $this->assertSame('submitted', $credential->status);
        $this->assertSame(1, $credential->revision);
    }

    public function test_other_coach_student_and_organization_manager_cannot_read_file_or_private_details(): void
    {
        $credential = $this->submit();
        $this->get('/coach-credentials/'.$credential->id)->assertRedirect('/login');
        foreach (['coach', 'student', 'org_admin'] as $role) {
            $actor = $this->user($role);
            $this->actingAs($actor)->get('/coach-credentials/'.$credential->id)->assertForbidden()->assertDontSee('PRIVATE-SOFTWARE-CREDENTIAL');
            $this->get('/coach-credentials/'.$credential->id.'/file')->assertForbidden();
            $this->get('/coach-credentials')->assertInertia(fn (Assert $page) => $page->where('credentials.total', 0));
            $this->get('/admin/coach-credentials')->assertForbidden();
        }
    }

    public function test_owner_and_full_admin_download_original_attachment_without_exposing_path_or_ciphertext(): void
    {
        $credential = $this->submit();
        foreach ([$this->coach, $this->admin] as $actor) {
            $this->actingAs($actor)->get('/coach-credentials/'.$credential->id)->assertInertia(fn (Assert $page) => $page->where('selected.title', 'PRIVATE-SOFTWARE-CREDENTIAL')->missing('selected.definition')->missing('selected.file_path')->missing('selected.request_key'));
            $response = $this->get('/coach-credentials/'.$credential->id.'/file')->assertOk()->assertDownload('software-fixture.pdf')->assertHeader('Content-Type', 'application/octet-stream')->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
            $this->assertSame(self::PDF, $response->streamedContent());
        }
        $this->assertSame(2, AuditEvent::where('action', 'coach_credential.file_downloaded')->count());
    }

    public function test_admin_review_requires_explicit_check_and_rejection_reason_and_preserves_encrypted_history(): void
    {
        $credential = $this->submit();
        $url = '/coach-credentials/'.$credential->id.'/change';
        $this->actingAs($this->admin)->postJson($url, ['revision' => 1, 'action' => 'accept', 'reviewed' => false])->assertUnprocessable()->assertJsonValidationErrors('reviewed');
        $this->postJson($url, ['revision' => 1, 'action' => 'reject', 'reviewed' => true])->assertUnprocessable()->assertJsonValidationErrors('reason');
        $this->review($credential);
        $this->assertSame('accepted', $credential->fresh()->status);
        $this->assertSame(2, $credential->fresh()->revision);
        $event = $credential->events()->latest('id')->first();
        $this->assertSame('PRIVATE-ADMIN-REASON', $event->reasonText());
        $this->assertStringNotContainsString('PRIVATE-ADMIN-REASON', $event->getRawOriginal('reason'));
        $this->postJson($url, ['revision' => 1, 'action' => 'reject', 'reviewed' => true, 'reason' => 'Old reviewer'])->assertUnprocessable()->assertJsonValidationErrors('revision');
        $this->assertSame(2, $credential->events()->count());
        $this->assertSame(1, DB::table('notifications')->where('type', CoachCredentialChanged::class)->count());
        $notification = json_decode(DB::table('notifications')->where('type', CoachCredentialChanged::class)->value('data'), true);
        $this->assertStringNotContainsString('PRIVATE-', json_encode($notification));
        $this->assertSame('/coach-credentials/'.$credential->id, $notification['url']);
        $this->review($credential, 'reject', 'A later private correction');
        $this->assertSame('rejected', $credential->fresh()->status);
        $this->assertSame(3, $credential->events()->count());
    }

    public function test_owner_can_withdraw_without_deleting_file_or_history_and_review_cannot_restore_it(): void
    {
        $credential = $this->submit();
        $this->review($credential);
        $this->actingAs($this->coach)->post('/coach-credentials/'.$credential->id.'/change', ['revision' => 2, 'action' => 'withdraw', 'reason' => 'PRIVATE-OWNER-ARCHIVE'])->assertSessionHasNoErrors();
        $this->assertSame('withdrawn', $credential->fresh()->status);
        $this->assertSame(3, $credential->events()->count());
        Storage::disk('learning')->assertExists($credential->file_path);
        $this->actingAs($this->admin)->postJson('/coach-credentials/'.$credential->id.'/change', ['revision' => 3, 'action' => 'accept', 'reviewed' => true])->assertUnprocessable()->assertJsonValidationErrors('revision');
        $this->assertSame('withdrawn', $credential->fresh()->status);
        $this->assertSame(1, DB::table('notifications')->where('type', CoachCredentialChanged::class)->count());
    }

    public function test_former_coach_retains_own_history_download_and_archive_but_cannot_upload(): void
    {
        $credential = $this->submit();
        $this->coach->roles()->sync([Role::where('key', 'student')->value('id')]);
        $this->actingAs($this->coach)->get('/coach-credentials')->assertInertia(fn (Assert $page) => $page->where('can_submit', false)->where('credentials.total', 1));
        $this->get('/account')->assertInertia(fn (Assert $page) => $page->where('has_coach_credentials', true));
        $this->get('/coach-credentials/'.$credential->id.'/file')->assertOk();
        $this->post('/coach-credentials', [...$this->input(), 'file' => $this->file()])->assertForbidden();
        $this->post('/coach-credentials/'.$credential->id.'/change', ['revision' => 1, 'action' => 'withdraw', 'reason' => 'Archive after role change'])->assertSessionHasNoErrors();
        $this->assertFalse($this->coach->hasRole('coach'));
    }

    public function test_missing_or_changed_file_cannot_be_downloaded_or_accepted(): void
    {
        $credential = $this->submit();
        Storage::disk('learning')->put($credential->file_path, 'tampered bytes');
        $this->actingAs($this->coach)->get('/coach-credentials/'.$credential->id.'/file')->assertNotFound();
        $this->actingAs($this->admin)->postJson('/coach-credentials/'.$credential->id.'/change', ['revision' => 1, 'action' => 'accept', 'reviewed' => true])->assertUnprocessable()->assertJsonValidationErrors('file');
        $this->assertSame('submitted', $credential->fresh()->status);
        $this->assertSame(1, $credential->events()->count());
        $this->assertSame(0, DB::table('notifications')->where('type', CoachCredentialChanged::class)->count());
        Storage::disk('learning')->delete($credential->file_path);
        $this->actingAs($this->coach)->get('/coach-credentials/'.$credential->id.'/file')->assertNotFound();
    }

    public function test_copied_metadata_or_file_pointer_cannot_disclose_another_private_record(): void
    {
        $own = $this->submit();
        $foreign = $this->submit($this->user('coach'), ['title' => 'FOREIGN-CREDENTIAL-SECRET']);
        $original = $own->getRawOriginal('file_path');
        DB::table('coach_credentials')->where('id', $own->id)->update(['file_path' => $foreign->getRawOriginal('file_path')]);
        $this->actingAs($this->coach)->get('/coach-credentials/'.$own->id.'/file')->assertNotFound();
        DB::table('coach_credentials')->where('id', $own->id)->update(['file_path' => $original, 'definition' => $foreign->getRawOriginal('definition')]);
        $this->get('/coach-credentials/'.$own->id)->assertInertia(fn (Assert $page) => $page->where('selected.unavailable', true)->where('selected.title', 'پرونده خصوصی'))->assertDontSee('FOREIGN-CREDENTIAL-SECRET');
        $this->get('/coach-credentials/'.$own->id.'/file')->assertNotFound();
    }

    public function test_copied_review_reason_cannot_disclose_another_record_note(): void
    {
        $own = $this->submit();
        $foreign = $this->submit($this->user('coach'));
        $this->review($own, 'reject', 'Own private review');
        $this->review($foreign, 'reject', 'FOREIGN-REVIEW-SECRET');
        $ownEvent = $own->events()->latest('id')->first();
        $foreignEvent = $foreign->events()->latest('id')->first();
        DB::table('coach_credential_events')->where('id', $ownEvent->id)->update(['reason' => $foreignEvent->getRawOriginal('reason')]);
        $this->actingAs($this->coach)->get('/coach-credentials/'.$own->id)->assertInertia(fn (Assert $page) => $page->where('events.data.0.reason', 'یادداشت این رویداد قابل نمایش نیست.'))->assertDontSee('FOREIGN-REVIEW-SECRET');
    }

    public function test_owner_cannot_review_and_another_owner_or_admin_cannot_withdraw_foreign_document(): void
    {
        $credential = $this->submit();
        $this->actingAs($this->coach)->postJson('/coach-credentials/'.$credential->id.'/change', ['revision' => 1, 'action' => 'accept', 'reviewed' => true])->assertForbidden();
        foreach ([$this->user('coach'), $this->admin] as $actor) {
            $this->actingAs($actor)->postJson('/coach-credentials/'.$credential->id.'/change', ['revision' => 1, 'action' => 'withdraw', 'reason' => 'Not owner'])->assertForbidden();
        }
        $this->assertSame('submitted', $credential->fresh()->status);
    }

    public function test_admin_phone_and_status_filters_and_owner_pagination_preserve_private_scope(): void
    {
        for ($index = 0; $index < 21; $index++) {
            $this->submit(changes: ['title' => 'Own software document '.$index]);
        }
        $foreign = $this->submit($this->user('coach'), ['title' => 'FOREIGN-FILTERED-TITLE']);
        $this->review($foreign);
        $this->actingAs($this->coach)->get('/coach-credentials')->assertInertia(fn (Assert $page) => $page->where('credentials.total', 21)->has('credentials.data', 20));
        $this->get('/coach-credentials?page=2')->assertInertia(fn (Assert $page) => $page->has('credentials.data', 1));
        $this->actingAs($this->admin)->get('/admin/coach-credentials?'.http_build_query(['phone' => $this->coach->phone, 'status' => 'submitted']))->assertInertia(fn (Assert $page) => $page->where('credentials.total', 21)->where('filters.phone', $this->coach->phone))->assertDontSee('FOREIGN-FILTERED-TITLE');
        $this->get('/admin/coach-credentials?status=accepted')->assertInertia(fn (Assert $page) => $page->where('credentials.total', 1)->where('credentials.data.0.id', $foreign->id));
    }

    public function test_accepting_private_credential_does_not_publish_profile_or_expose_document_publicly(): void
    {
        $credential = $this->submit();
        $this->review($credential);
        $this->assertSame(0, DB::table('coach_profiles')->count());
        $this->get('/mentors')->assertOk()->assertDontSee('PRIVATE-SOFTWARE-CREDENTIAL');
        auth()->logout();
        $this->get('/coach-credentials/'.$credential->id.'/file')->assertRedirect('/login');
        $this->assertSame('accepted', $credential->fresh()->status);
    }

    public function test_ordinary_database_failure_cleans_up_uncommitted_private_upload(): void
    {
        DB::listen(function (QueryExecuted $query): void {
            if (str_starts_with($query->sql, 'insert into "audit_events"') && in_array('coach_credential.submitted', $query->bindings, true)) {
                throw new \RuntimeException('Synthetic credential audit persistence failure');
            }
        });
        try {
            $this->submit();
            $this->fail('The synthetic persistence failure must abort upload.');
        } catch (\RuntimeException $exception) {
            $this->assertSame('Synthetic credential audit persistence failure', $exception->getMessage());
        }
        $this->assertSame(0, CoachCredential::count());
        $this->assertSame(0, CoachCredentialEvent::count());
        $this->assertSame([], Storage::disk('learning')->allFiles());
    }

    public function test_stale_active_coach_snapshot_cannot_upload_after_deactivation(): void
    {
        $stale = $this->coach->fresh();
        $this->coach->forceFill(['is_active' => false])->save();
        try {
            app(CoachCredentials::class)->submit($stale, $this->input(), $this->file());
            $this->fail('Stale actor must not create a credential.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame(0, CoachCredential::count());
        $this->assertSame([], Storage::disk('learning')->allFiles());
    }

    public function test_png_is_preserved_as_a_private_original_attachment_without_public_preview(): void
    {
        $bytes = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAQAAAC1HAwCAAAAC0lEQVR42mP8/x8AAwMCAO+a6wAAAABJRU5ErkJggg==', true);
        $image = UploadedFile::fake()->createWithContent('software-pixel.png', $bytes);
        $credential = app(CoachCredentials::class)->submit($this->coach, $this->input(), $image);
        $this->assertSame('image/png', $credential->definition['mime']);
        $this->assertStringEndsWith('.png', $credential->file_path);
        $response = $this->actingAs($this->coach)->get('/coach-credentials/'.$credential->id.'/file')->assertOk()->assertDownload('software-pixel.png');
        $this->assertSame($bytes, $response->streamedContent());
    }
}
