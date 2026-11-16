<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\CareerResume;
use App\Models\Enrollment;
use App\Models\Organization;
use App\Models\ResumeShare;
use App\Models\ResumeVersion;
use App\Models\Role;
use App\Models\User;
use App\Services\ResumeBuilder;
use App\Services\ResumeSharing;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use RuntimeException;
use Tests\TestCase;

class ResumeSharingTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $admin;

    private CareerResume $resume;

    private ResumeVersion $version;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        $this->owner = $this->user('student');
        $this->admin = $this->user('admin');
        $builder = app(ResumeBuilder::class);
        $this->resume = $builder->create($this->owner, 'PRIVATE INTERNAL RESUME TITLE');
        $builder->save($this->owner, $this->resume, 1, $this->resume->title, [...$this->resume->draft, 'name' => 'نام انتخاب‌شده در نسخه', 'headline' => 'عنوان ثبت‌شده', 'phone' => '09120000099', 'email' => 'source-contact@example.test', 'city' => 'شهر نسخه', 'summary' => 'شرح شخصی واردشده', 'skills' => ['SQL'], 'links' => [['label' => 'نمونه کار', 'url' => 'https://example.test/source-private-link']]]);
        $this->version = $builder->snapshot($this->owner, $this->resume, 2);
        $this->resume->refresh();
        $this->actingAs($this->owner);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('key', $role)->value('id'));

        return $user;
    }

    private function path(?ResumeVersion $version = null): string
    {
        return route('career.shares', ['resume' => $this->resume, 'version' => $version ?? $this->version], false);
    }

    private function input(array $changes = []): array
    {
        return ['revision' => $this->resume->fresh()->revision, 'request_key' => (string) Str::uuid(), 'duration_days' => 7, 'include_contacts' => false, 'reviewed' => true, 'consent' => true, ...$changes];
    }

    private function share(array $changes = []): ResumeShare
    {
        return app(ResumeSharing::class)->publish($this->owner, $this->resume, $this->version, $this->input($changes));
    }

    private function publicPath(ResumeShare $share): string
    {
        return route('public.resume', app(ResumeSharing::class)->metadata($share)['token'], false);
    }

    private function guest(): void
    {
        auth()->forgetGuards();
        $this->app['auth']->guard()->logout();
    }

    public function test_resumes_remain_private_until_owner_publishes_reviewed_consented_snapshot(): void
    {
        $this->get($this->path())->assertInertia(fn (Assert $page) => $page->component('ResumeSharing')->where('can_publish', true)->where('own', true)->has('shares.data', 0)->where('version.definition.name', 'نام انتخاب‌شده در نسخه'));
        $this->assertDatabaseCount('resume_shares', 0);
        $this->guest();
        $this->get($this->path())->assertRedirect('/login');
        $this->get('/resume-shares/'.str_repeat('a', 64))->assertNotFound();
        $this->assertDatabaseCount('resume_shares', 0);
    }

    public function test_consent_review_duration_revision_and_request_identity_are_required(): void
    {
        foreach ([['consent' => false], ['reviewed' => false], ['duration_days' => 0], ['duration_days' => 365], ['revision' => 1], ['include_contacts' => null], ['request_key' => 'not-a-key']] as $invalid) {
            $this->post($this->path(), $this->input($invalid))->assertSessionHasErrors();
        }
        $this->assertDatabaseCount('resume_shares', 0);
        $this->assertSame(0, AuditEvent::where('action', 'resume_share.published')->count());
    }

    public function test_default_public_copy_has_snapshot_text_without_contact_fields_internal_title_or_account_details(): void
    {
        $share = $this->share();
        $url = $this->publicPath($share);
        $this->guest();
        $response = $this->get($url)->assertOk()->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertHeader('Referrer-Policy', 'no-referrer')->assertHeader('X-Frame-Options', 'DENY')->assertHeader('X-Content-Type-Options', 'nosniff');
        foreach (['نام انتخاب‌شده در نسخه', 'شرح شخصی واردشده', 'شهر نسخه', 'SQL', 'نسخه اشتراکی رزومه'] as $visible) {
            $response->assertSee($visible);
        }
        foreach (['09120000099', 'source-contact@example.test', 'source-private-link', 'PRIVATE INTERNAL RESUME TITLE', $this->owner->phone, $share->request_key, $share->token_hash, 'resume_share.published'] as $private) {
            $response->assertDontSee($private, false);
        }
        Http::assertNothingSent();
    }

    public function test_contact_opt_in_includes_only_saved_source_contacts_and_preserves_owner_reviewed_free_text(): void
    {
        $share = $this->share(['include_contacts' => true]);
        $this->get($this->publicPath($share))->assertOk()->assertSee('09120000099')->assertSee('source-contact@example.test')->assertSee('https://example.test/source-private-link', false)->assertDontSee($this->owner->phone, false);
        $this->assertTrue($share->include_contacts);
        $this->assertSame(ResumeSharing::CONSENT, $share->definition['consent_text']);
    }

    public function test_raw_database_token_snapshot_and_revocation_reason_are_encrypted_and_bound_to_source(): void
    {
        $share = $this->share();
        $data = app(ResumeSharing::class)->metadata($share);
        $raw = DB::table('resume_shares')->where('id', $share->id)->first();
        $this->assertStringNotContainsString('نام انتخاب‌شده', $raw->definition);
        $this->assertStringNotContainsString($data['token'], $raw->definition);
        $this->assertSame(hash('sha256', $data['token']), $raw->token_hash);
        $this->assertSame($share->id, $data['share_id']);
        $this->assertSame($this->owner->id, $data['user_id']);
        $this->assertSame($this->version->id, $data['version_id']);
        $this->post('/career/shares/'.$share->id.'/revoke', ['revision' => 1, 'reason' => 'Private owner revocation reason'])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertStringNotContainsString('Private owner', DB::table('resume_shares')->where('id', $share->id)->value('revocation_reason'));
        $this->assertSame('Private owner revocation reason', app(ResumeSharing::class)->reason($share->fresh()));
        foreach (['definition', 'request_key', 'token_hash', 'revocation_reason'] as $hidden) {
            $this->assertArrayNotHasKey($hidden, $share->toArray());
        }
    }

    public function test_same_request_retry_never_duplicates_or_reactivates_revoked_link(): void
    {
        $input = $this->input();
        $service = app(ResumeSharing::class);
        $first = $service->publish($this->owner, $this->resume, $this->version, $input);
        $again = $service->publish($this->owner, $this->resume, $this->version, $input);
        $this->assertSame($first->id, $again->id);
        $url = $this->publicPath($first);
        $service->revoke($this->owner, $first, ['revision' => 1]);
        app(ResumeBuilder::class)->archive($this->owner, $this->resume, 3, true);
        $retry = $service->publish($this->owner, $this->resume, $this->version, $input);
        $this->assertSame($first->id, $retry->id);
        $this->assertNotNull($retry->revoked_at);
        $this->assertDatabaseCount('resume_shares', 1);
        $this->assertSame(1, AuditEvent::where('action', 'resume_share.published')->count());
        $this->get($url)->assertNotFound();
    }

    public function test_used_request_key_cannot_change_duration_contacts_or_selected_version(): void
    {
        $input = $this->input();
        app(ResumeSharing::class)->publish($this->owner, $this->resume, $this->version, $input);
        foreach ([['duration_days' => 30], ['include_contacts' => true]] as $changed) {
            $this->post($this->path(), [...$input, ...$changed])->assertSessionHasErrors('request_key');
        }
        app(ResumeBuilder::class)->save($this->owner, $this->resume, 3, 'Changed title', [...$this->resume->draft, 'headline' => 'Another source']);
        $second = app(ResumeBuilder::class)->snapshot($this->owner, $this->resume, 4);
        $this->post($this->path($second), $input)->assertSessionHasErrors('request_key');
        $this->assertDatabaseCount('resume_shares', 1);
    }

    public function test_each_snapshot_has_one_active_link_and_new_link_after_revocation_has_a_new_token(): void
    {
        $first = $this->share();
        $old = $this->publicPath($first);
        $this->post($this->path(), $this->input())->assertSessionHasErrors('share');
        app(ResumeSharing::class)->revoke($this->owner, $first, ['revision' => 1]);
        $second = $this->share(['duration_days' => 30, 'include_contacts' => true]);
        $new = $this->publicPath($second);
        $this->assertNotSame($old, $new);
        $this->get($old)->assertNotFound();
        $this->get($new)->assertOk();
        $this->assertDatabaseCount('resume_shares', 2);
    }

    public function test_immutable_public_source_survives_new_draft_version_internal_title_and_account_name(): void
    {
        $share = $this->share();
        $url = $this->publicPath($share);
        $before = $this->get($url)->getContent();
        app(ResumeBuilder::class)->save($this->owner, $this->resume, 3, 'Another private title', [...$this->resume->draft, 'name' => 'New snapshot name', 'summary' => 'New private summary']);
        app(ResumeBuilder::class)->snapshot($this->owner, $this->resume, 4);
        $this->owner->update(['name' => 'New account name']);
        $this->assertSame($before, $this->get($url)->assertOk()->getContent());
    }

    public function test_expiry_exact_boundary_and_all_supported_durations_do_not_need_background_jobs(): void
    {
        $this->travelTo(now()->startOfSecond());
        foreach ([7, 30, 90] as $days) {
            $share = $this->share(['duration_days' => $days]);
            $url = $this->publicPath($share);
            $this->assertSame((float) $days, $share->consented_at->diffInDays($share->expires_at));
            $this->travelTo($share->expires_at->copy()->subSecond());
            $this->get($url)->assertOk();
            $this->travelTo($share->expires_at);
            $this->get($url)->assertNotFound();
            $this->assertSame('expired', app(ResumeSharing::class)->status($share->fresh()));
        }
        $this->assertDatabaseCount('resume_shares', 3);
    }

    public function test_owner_revocation_checks_revision_and_preserves_one_terminal_event(): void
    {
        $share = $this->share();
        $url = $this->publicPath($share);
        $this->post('/career/shares/'.$share->id.'/revoke', ['revision' => 2])->assertSessionHasErrors('revision');
        $this->get($url)->assertOk();
        $this->post('/career/shares/'.$share->id.'/revoke', ['revision' => 1])->assertSessionHasNoErrors();
        $this->post('/career/shares/'.$share->id.'/revoke', ['revision' => 1])->assertSessionHasErrors('revision');
        $this->get($url)->assertNotFound();
        $this->assertSame(2, $share->fresh()->revision);
        $this->assertSame(1, AuditEvent::where('action', 'resume_share.revoked')->count());
    }

    public function test_full_admin_can_read_and_revoke_with_reason_but_cannot_supply_owner_publication_consent(): void
    {
        $this->actingAs($this->admin)->get($this->path())->assertInertia(fn (Assert $page) => $page->where('can_publish', false)->where('own', false));
        $this->post($this->path(), $this->input())->assertForbidden();
        $share = $this->share();
        $url = $this->publicPath($share);
        $this->get($this->path())->assertInertia(fn (Assert $page) => $page->has('shares.data', 1));
        $this->post('/career/shares/'.$share->id.'/revoke', ['revision' => 1])->assertSessionHasErrors('reason');
        $this->post('/career/shares/'.$share->id.'/revoke', ['revision' => 1, 'reason' => 'توضیح ادمین برای صاحب'])->assertSessionHasNoErrors();
        $this->get($url)->assertNotFound();
        $this->assertSame($this->admin->id, $share->fresh()->revoked_by);
        $this->actingAs($this->owner)->get($this->path())->assertInertia(fn (Assert $page) => $page->where('shares.data.0.reason', 'توضیح ادمین برای صاحب'));
    }

    public function test_peer_teacher_and_actual_organization_manager_have_no_manage_publish_or_revoke_access(): void
    {
        $share = $this->share();
        $coach = $this->user('coach');
        $manager = $this->user('org_admin');
        $organization = Organization::factory()->create();
        $organization->members()->attach($manager->id, ['kind' => 'manager']);
        $organization->members()->attach($this->owner->id, ['kind' => 'member']);
        $enrollment = Enrollment::factory()->create(['user_id' => $this->owner->id]);
        $enrollment->course->update(['coach_id' => $coach->id]);
        foreach ([$coach, $manager, $this->user('student')] as $outsider) {
            $this->actingAs($outsider)->get($this->path())->assertForbidden();
            $this->post($this->path(), $this->input())->assertForbidden();
            $this->post('/career/shares/'.$share->id.'/revoke', ['revision' => 1, 'reason' => 'Attempt'])->assertForbidden();
        }
        $this->assertNull($share->fresh()->revoked_at);
        $this->assertSame(1, AuditEvent::where('action', 'resume_share.published')->count());
    }

    public function test_all_four_roles_may_consent_for_their_own_source_and_own_rights_survive_role_loss(): void
    {
        foreach (['student', 'coach', 'org_admin', 'admin'] as $role) {
            $owner = $this->user($role);
            $resume = app(ResumeBuilder::class)->create($owner, 'Synthetic share '.$role);
            $version = app(ResumeBuilder::class)->snapshot($owner, $resume, 1);
            $share = app(ResumeSharing::class)->publish($owner, $resume, $version, $this->input(['revision' => 2]));
            $owner->roles()->detach();
            $this->get($this->publicPath($share))->assertOk();
            $this->actingAs($owner)->post('/career/shares/'.$share->id.'/revoke', ['revision' => 1])->assertSessionHasNoErrors();
        }
    }

    public function test_archive_atomically_revokes_links_and_restore_does_not_reactivate_them(): void
    {
        $first = $this->share();
        $url = $this->publicPath($first);
        $builder = app(ResumeBuilder::class);
        $builder->archive($this->owner, $this->resume, 3, true);
        $this->assertNotNull($first->fresh()->revoked_at);
        $this->assertSame('قطع لینک هم‌زمان با بایگانی رزومه', app(ResumeSharing::class)->reason($first->fresh()));
        $builder->archive($this->owner, $this->resume, 4, false);
        $this->get($url)->assertNotFound();
        $second = $this->share();
        $this->get($this->publicPath($second))->assertOk();
    }

    public function test_archive_audit_failure_rolls_back_both_parent_and_all_share_revocations(): void
    {
        $share = $this->share();
        AuditEvent::creating(function ($event): void {
            if ($event->action === 'resume.archived') {
                throw new RuntimeException('Synthetic archive audit failure');
            }
        });
        try {
            app(ResumeBuilder::class)->archive($this->owner, $this->resume, 3, true);
            $this->fail('The synthetic audit failure must abort the whole operation.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Synthetic archive audit failure', $exception->getMessage());
        } finally {
            AuditEvent::flushEventListeners();
        }
        $this->assertFalse($this->resume->fresh()->archived);
        $this->assertSame(3, $this->resume->revision);
        $this->assertNull($share->fresh()->revoked_at);
        $this->assertSame(0, AuditEvent::where('action', 'resume_share.revoked')->count());
        $this->get($this->publicPath($share))->assertOk();
    }

    public function test_cipher_replay_between_links_and_deadline_or_contact_flag_changes_fail_closed(): void
    {
        $first = $this->share();
        app(ResumeSharing::class)->revoke($this->owner, $first, ['revision' => 1]);
        $second = $this->share();
        $url = $this->publicPath($second);
        $original = $second->getRawOriginal('definition');
        DB::table('resume_shares')->where('id', $second->id)->update(['definition' => $first->getRawOriginal('definition')]);
        $this->get($url)->assertNotFound();
        $this->get($this->path())->assertInertia(fn (Assert $page) => $page->where('shares.data.0.url', null));
        DB::table('resume_shares')->where('id', $second->id)->update(['definition' => $original, 'expires_at' => $second->expires_at->addDays(1)]);
        $this->get($url)->assertNotFound();
        $second->refresh()->update(['expires_at' => $first->expires_at, 'include_contacts' => true]);
        $this->get($url)->assertNotFound();
    }

    public function test_corrupt_original_source_disables_public_copy_but_preserves_private_revocation_access(): void
    {
        $share = $this->share();
        $url = $this->publicPath($share);
        DB::table('resume_versions')->where('id', $this->version->id)->update(['definition' => 'invalid cipher']);
        $this->get($url)->assertNotFound();
        $this->get($this->path())->assertOk()->assertInertia(fn (Assert $page) => $page->where('can_publish', false)->where('version.definition', null)->where('shares.data.0.available', false));
        $this->post('/career/shares/'.$share->id.'/revoke', ['revision' => 1])->assertSessionHasNoErrors();
        $this->assertNotNull($share->fresh()->revoked_at);
    }

    public function test_owner_deactivation_or_verification_loss_pauses_public_access_and_stale_publish_is_rejected(): void
    {
        $share = $this->share();
        $url = $this->publicPath($share);
        DB::table('users')->where('id', $this->owner->id)->update(['is_active' => false]);
        $this->get($url)->assertNotFound();
        $this->post($this->path(), $this->input())->assertForbidden();
        DB::table('users')->where('id', $this->owner->id)->update(['is_active' => true, 'phone_verified_at' => null]);
        $this->get($url)->assertNotFound();
        $this->actingAs($this->admin)->get($this->path())->assertOk()->assertInertia(fn (Assert $page) => $page->where('shares.data.0.available', false));
        $this->post('/career/shares/'.$share->id.'/revoke', ['revision' => 1, 'reason' => 'توقف لینک حساب غیرفعال‌شده'])->assertSessionHasNoErrors();
    }

    public function test_public_template_escapes_script_text_and_english_snapshot_keeps_original_language(): void
    {
        $literal = '<script>window.resumeSharedInjected=true</script>';
        app(ResumeBuilder::class)->save($this->owner, $this->resume, 3, 'Hidden title', [...$this->resume->draft, 'locale' => 'en', 'name' => 'Saved English name', 'summary' => $literal]);
        $this->version = app(ResumeBuilder::class)->snapshot($this->owner, $this->resume, 4);
        $share = $this->share();
        $this->guest();
        $this->get($this->publicPath($share))->assertOk()->assertSee('dir="ltr"', false)->assertSee('Shared resume snapshot')->assertSee($literal)->assertDontSee($literal, false);
    }

    public function test_snapshot_parent_boundary_and_bearer_hash_are_not_alternative_access_tokens(): void
    {
        $foreign = ResumeVersion::factory()->create();
        $this->post($this->path($foreign), $this->input())->assertNotFound();
        $share = $this->share();
        $this->get('/resume-shares/'.$share->token_hash)->assertNotFound();
        foreach (['short', str_repeat('G', 64), str_repeat('a', 63)] as $invalid) {
            $this->get('/resume-shares/'.$invalid)->assertNotFound();
        }
    }

    public function test_private_share_history_is_paginated_and_audit_has_no_token_source_contact_or_reason(): void
    {
        ResumeShare::factory()->count(16)->create(['user_id' => $this->owner->id, 'career_resume_id' => $this->resume->id, 'resume_version_id' => $this->version->id, 'revoked_at' => now()]);
        ResumeShare::factory()->create();
        $this->get($this->path().'?page=2')->assertInertia(fn (Assert $page) => $page->where('shares.total', 16)->has('shares.data', 1)->missing('shares.data.0.definition')->missing('shares.data.0.token_hash')->missing('shares.data.0.request_key'));
        $share = $this->share();
        $audit = AuditEvent::where('action', 'resume_share.published')->sole();
        $this->assertSame(['resume_id' => $this->resume->id, 'version_id' => $this->version->id, 'revision' => 1, 'include_contacts' => false, 'duration_days' => 7], $audit->metadata);
        $this->assertStringNotContainsString(app(ResumeSharing::class)->metadata($share)['token'], json_encode($audit->metadata));
        Http::assertNothingSent();
    }

    public function test_unexpected_validly_encrypted_source_change_is_not_silently_published_under_old_consent(): void
    {
        $share = $this->share();
        $url = $this->publicPath($share);
        $replacement = [...$this->version->definition, 'summary' => 'Unexpected replacement outside the snapshot workflow'];
        $this->version->update(['definition' => $replacement, 'content_hash' => hash('sha256', json_encode($replacement, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))]);
        $this->get($url)->assertNotFound();
        $this->get($this->path())->assertInertia(fn (Assert $page) => $page->where('shares.data.0.available', false));
        $this->assertSame('شرح شخصی واردشده', $share->fresh()->definition['snapshot']['summary']);
    }

    public function test_publication_and_privacy_revocation_have_http_budgets_independent_of_other_resume_actions(): void
    {
        for ($number = 1; $number <= 10; $number++) {
            $this->post('/career/resumes', ['title' => 'Synthetic edit-budget draft '.$number])->assertRedirect();
        }
        $this->post('/career/resumes', ['title' => 'No remaining creation budget'])->assertTooManyRequests();
        $this->post($this->path(), $this->input())->assertRedirect();
        $share = ResumeShare::sole();
        for ($number = 1; $number <= 9; $number++) {
            $this->post($this->path(), $this->input())->assertSessionHasErrors('share');
        }
        $this->post($this->path(), $this->input())->assertTooManyRequests();
        $this->post('/career/shares/'.$share->id.'/revoke', ['revision' => 1])->assertRedirect();
        $this->assertNotNull($share->fresh()->revoked_at);
        $this->get($this->publicPath($share))->assertNotFound();
        $this->assertSame(1, AuditEvent::where('action', 'resume_share.published')->count());
        $this->assertSame(1, AuditEvent::where('action', 'resume_share.revoked')->count());
    }
}
