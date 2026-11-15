<?php

namespace Tests\Feature;

use App\Models\CoachProfile;
use App\Models\CoachProfilePublication;
use App\Models\CoachProfileSubmission;
use App\Models\CoachProfileWithdrawal;
use App\Models\Role;
use App\Models\User;
use App\Services\CoachProfiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CoachProfileTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        $this->admin = $this->user('admin');
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('key', $role)->value('id'));

        return $user;
    }

    private function submitted(): CoachProfile
    {
        $profile = CoachProfile::factory()->create();
        app(CoachProfiles::class)->submit($profile->owner, $profile, 1, true);

        return $profile->fresh();
    }

    private function published(): CoachProfile
    {
        $profile = $this->submitted();
        app(CoachProfiles::class)->review($this->admin, $profile, 2, 'publish', null);

        return $profile->fresh();
    }

    private function url(CoachProfile $profile): string
    {
        return '/mentors/'.$profile->slug;
    }

    public function test_public_directory_is_empty_without_fabricated_people_or_accounts(): void
    {
        $this->get('/mentors')->assertOk()->assertSee('هنوز پروفایلی برای نمایش منتشر نشده است.');
        $this->assertDatabaseCount('coach_profiles', 0);
        $this->get('/sitemap.xml')->assertSee('/mentors');
    }

    public function test_only_own_active_verified_coach_can_save_a_private_draft(): void
    {
        $profile = CoachProfile::factory()->create();
        foreach (['student', 'org_admin', 'admin'] as $role) {
            $peer = $this->user($role);
            $this->actingAs($peer)->get('/coach/profile')->assertForbidden();
            $this->post('/coach/profile', ['revision' => 0, 'definition' => $profile->draft])->assertForbidden();
        }
        $owner = $profile->owner;
        $this->actingAs($owner)->post('/coach/profile', ['revision' => 1, 'definition' => $profile->draft])->assertSessionHasNoErrors();
        $owner->update(['phone_verified_at' => null]);
        $this->post('/coach/profile', ['revision' => 2, 'definition' => $profile->draft])->assertForbidden();
    }

    public function test_draft_is_encrypted_and_not_public_or_in_sitemap(): void
    {
        $profile = CoachProfile::factory()->create();
        $this->assertStringNotContainsString('مربی صرفاً آزمایشی', DB::table('coach_profiles')->value('draft'));
        $this->get($this->url($profile))->assertNotFound();
        $this->get('/mentors')->assertDontSee($profile->draft['display_name']);
        $this->get('/sitemap.xml')->assertDontSee($profile->slug);
    }

    public function test_save_normalizes_fields_and_discards_unknown_owner_or_publication_fields(): void
    {
        $owner = $this->user('coach');
        $fixture = CoachProfile::factory()->make();
        $data = [...$fixture->draft, 'display_name' => '  نام عمومی آزمایشی  ', 'user_id' => $this->admin->id, 'public_enabled' => true, 'current_publication_id' => 1];
        $this->actingAs($owner)->post('/coach/profile', ['revision' => 0, 'definition' => $data])->assertSessionHasNoErrors();
        $profile = CoachProfile::where('user_id', $owner->id)->sole();
        $this->assertSame('نام عمومی آزمایشی', $profile->draft['display_name']);
        $this->assertArrayNotHasKey('user_id', $profile->draft);
        $this->assertFalse($profile->public_enabled);
        $this->assertNull($profile->current_publication_id);
    }

    public function test_submit_requires_fresh_explicit_consent_and_remains_private_until_review(): void
    {
        $profile = CoachProfile::factory()->create();
        $this->actingAs($profile->owner)->post('/coach/profile/submit', ['revision' => 1])->assertSessionHasErrors('public_consent');
        $this->post('/coach/profile/submit', ['revision' => 1, 'public_consent' => true])->assertSessionHasNoErrors();
        $this->assertSame('submitted', $profile->fresh()->state);
        $this->assertNotNull(CoachProfileSubmission::sole()->consented_at);
        $this->assertStringNotContainsString('مربی صرفاً آزمایشی', DB::table('coach_profile_submissions')->value('definition'));
        $this->get($this->url($profile))->assertNotFound();
    }

    public function test_only_current_admin_can_review_exact_submitted_snapshot(): void
    {
        $profile = $this->submitted();
        $this->actingAs($profile->owner)->post('/admin/coach-profiles/'.$profile->id.'/review', ['revision' => 2, 'action' => 'publish', 'reviewed' => true])->assertForbidden();
        $this->actingAs($this->admin)->get('/admin/coach-profiles/'.$profile->id)->assertInertia(fn (Assert $page) => $page->where('eligible', true)->where('selected.submission.status', 'pending'));
        $this->post('/admin/coach-profiles/'.$profile->id.'/review', ['revision' => 2, 'action' => 'publish'])->assertSessionHasErrors('reviewed');
        $this->post('/admin/coach-profiles/'.$profile->id.'/review', ['revision' => 2, 'action' => 'publish', 'reviewed' => true])->assertSessionHasNoErrors();
        $this->assertSame(1, CoachProfilePublication::count());
        $this->assertTrue($profile->fresh()->public_enabled);
        $this->get($this->url($profile))->assertOk()->assertSee($profile->draft['display_name']);
    }

    public function test_published_profile_has_server_rendered_metadata_and_no_private_phone_or_notes(): void
    {
        $profile = $this->published();
        $this->get($this->url($profile))->assertOk()->assertSee('name="description"', false)->assertSee('rel="canonical"', false)->assertSee($profile->draft['headline'])->assertDontSee($profile->owner->phone)->assertDontSee('definition_hash');
        $this->get('/sitemap.xml')->assertSee($profile->slug);
        $this->get('/robots.txt')->assertDontSee('Disallow: /mentors');
    }

    public function test_public_plain_text_escapes_html_and_script_in_all_definition_fields(): void
    {
        $profile = CoachProfile::factory()->create();
        $definition = [...$profile->draft, 'display_name' => '<script>unsafeName()</script>', 'headline' => '<img src=x onerror=unsafeTitle()>', 'bio' => '<script>unsafeBio()</script> متن کافی برای بررسی نمایش ساده'];
        app(CoachProfiles::class)->save($profile->owner, $definition, 1);
        app(CoachProfiles::class)->submit($profile->owner, $profile->fresh(), 2, true);
        app(CoachProfiles::class)->review($this->admin, $profile, 3, 'publish', null);
        $this->get($this->url($profile))->assertOk()->assertSee('&lt;script&gt;unsafeName()', false)->assertDontSee('<script>unsafeName()', false)->assertDontSee('<img src=x onerror=', false)->assertDontSee('<script>unsafeBio()', false);
    }

    public function test_saving_new_draft_keeps_prior_public_copy_and_invalidates_pending_review(): void
    {
        $profile = $this->published();
        $original = $profile->currentPublication;
        $data = [...$profile->draft, 'display_name' => 'نام جدید خصوصی'];
        app(CoachProfiles::class)->save($profile->owner, $data, 3);
        app(CoachProfiles::class)->submit($profile->owner, $profile->fresh(), 4, true);
        app(CoachProfiles::class)->save($profile->owner, [...$data, 'display_name' => 'نام سوم خصوصی'], 5);
        $this->actingAs($this->admin)->post('/admin/coach-profiles/'.$profile->id.'/review', ['revision' => 5, 'action' => 'publish', 'reviewed' => true])->assertSessionHasErrors('profile');
        $this->get($this->url($profile))->assertOk()->assertSee($original->definition['display_name'])->assertDontSee('نام جدید خصوصی')->assertDontSee('نام سوم خصوصی');
        $this->assertSame('withdrawn', $profile->fresh()->latestSubmission->status);
        $this->assertSame(1, CoachProfilePublication::count());
    }

    public function test_second_reviewed_publication_preserves_earlier_snapshot_and_consent(): void
    {
        $profile = $this->published();
        $old = $profile->currentPublication;
        app(CoachProfiles::class)->save($profile->owner, [...$profile->draft, 'display_name' => 'نام عمومی نسخه دوم'], 3);
        $this->travel(1)->minutes();
        app(CoachProfiles::class)->submit($profile->owner, $profile->fresh(), 4, true);
        app(CoachProfiles::class)->review($this->admin, $profile, 5, 'publish', null);
        $this->assertSame(2, CoachProfilePublication::count());
        $this->assertSame('مربی صرفاً آزمایشی', $old->fresh()->definition['display_name']);
        $this->assertTrue($profile->fresh()->currentPublication->consented_at->gt($old->consented_at));
        $this->get($this->url($profile))->assertSee('نام عمومی نسخه دوم')->assertDontSee('مربی صرفاً آزمایشی');
    }

    public function test_rejection_note_is_private_and_prior_publication_remains_unchanged(): void
    {
        $profile = $this->published();
        app(CoachProfiles::class)->save($profile->owner, [...$profile->draft, 'headline' => 'عنوان خصوصی اصلاحی'], 3);
        app(CoachProfiles::class)->submit($profile->owner, $profile->fresh(), 4, true);
        app(CoachProfiles::class)->review($this->admin, $profile, 5, 'reject', 'توضیح خصوصی بررسی');
        $this->get($this->url($profile))->assertOk()->assertDontSee('عنوان خصوصی اصلاحی')->assertDontSee('توضیح خصوصی بررسی');
        $this->assertStringNotContainsString('توضیح خصوصی بررسی', DB::table('coach_profile_submissions')->latest('id')->value('note'));
        $this->actingAs($profile->owner)->get('/coach/profile')->assertInertia(fn (Assert $page) => $page->where('profile.submission.note', 'توضیح خصوصی بررسی'));
    }

    public function test_current_activity_phone_and_coach_role_hide_public_profile_and_sitemap_immediately(): void
    {
        $profile = $this->published();
        $owner = $profile->owner;
        $owner->forceFill(['is_active' => false])->save();
        $this->get($this->url($profile))->assertNotFound();
        $owner->forceFill(['is_active' => true, 'phone_verified_at' => null])->save();
        $this->get($this->url($profile))->assertNotFound();
        $owner->update(['phone_verified_at' => now()]);
        $owner->roles()->detach();
        $this->get($this->url($profile))->assertNotFound();
        $this->get('/sitemap.xml')->assertDontSee($profile->slug);
    }

    public function test_admin_cannot_publish_after_owner_loses_coach_role_but_can_reject_pending_review(): void
    {
        $profile = $this->submitted();
        $profile->owner->roles()->detach();
        $this->actingAs($this->admin)->post('/admin/coach-profiles/'.$profile->id.'/review', ['revision' => 2, 'action' => 'publish', 'reviewed' => true])->assertForbidden();
        $this->post('/admin/coach-profiles/'.$profile->id.'/review', ['revision' => 2, 'action' => 'reject', 'note' => 'نقش مربی فعلی لازم است'])->assertSessionHasNoErrors();
        $this->assertSame('rejected', $profile->fresh()->state);
    }

    public function test_owner_can_close_public_and_pending_versions_after_role_replacement(): void
    {
        $profile = $this->published();
        $owner = $profile->owner;
        $owner->roles()->sync([Role::where('key', 'student')->value('id')]);
        $this->actingAs($owner)->get('/coach/profile')->assertInertia(fn (Assert $page) => $page->where('can_edit', false));
        $this->post('/coach/profile/withdraw', ['revision' => 3, 'reason' => 'نه'])->assertSessionHasNoErrors();
        $this->get($this->url($profile))->assertNotFound();
        $this->assertFalse($profile->fresh()->public_enabled);
        $this->assertDatabaseCount('coach_profile_withdrawals', 1);
        $this->assertSame('نه', CoachProfileWithdrawal::sole()->reason);
    }

    public function test_admin_withdrawal_requires_reason_and_preserves_immutable_publications_and_history(): void
    {
        $profile = $this->published();
        $this->actingAs($this->admin)->post('/admin/coach-profiles/'.$profile->id.'/withdraw', ['revision' => 3])->assertSessionHasErrors('reason');
        $this->post('/admin/coach-profiles/'.$profile->id.'/withdraw', ['revision' => 3, 'reason' => 'دلیل خصوصی توقف'])->assertSessionHasNoErrors();
        $this->get($this->url($profile))->assertNotFound();
        $this->assertSame(1, CoachProfilePublication::count());
        $this->assertStringNotContainsString('دلیل خصوصی توقف', DB::table('coach_profile_withdrawals')->value('reason'));
        $this->assertSame('دلیل خصوصی توقف', CoachProfileWithdrawal::sole()->reason);
        $this->assertSame(2, $profile->owner->notifications()->count());
    }

    public function test_other_coach_cannot_change_or_withdraw_foreign_profile_through_own_routes(): void
    {
        $profile = $this->published();
        $peer = $this->user('coach');
        $this->actingAs($peer)->post('/coach/profile/withdraw', ['revision' => 3, 'id' => $profile->id])->assertNotFound();
        $this->get('/admin/coach-profiles/'.$profile->id)->assertForbidden();
        $this->assertTrue($profile->fresh()->public_enabled);
    }

    public function test_republication_after_withdrawal_needs_fresh_consent_and_admin_review(): void
    {
        $profile = $this->published();
        app(CoachProfiles::class)->withdraw($profile->owner, $profile, 3, null);
        $this->actingAs($profile->owner)->post('/coach/profile/submit', ['revision' => 4])->assertSessionHasErrors('public_consent');
        $this->post('/coach/profile/submit', ['revision' => 4, 'public_consent' => true])->assertSessionHasNoErrors();
        $this->get($this->url($profile))->assertNotFound();
        app(CoachProfiles::class)->review($this->admin, $profile, 5, 'publish', null);
        $this->get($this->url($profile))->assertOk();
        $this->assertSame(2, CoachProfilePublication::count());
    }

    public function test_definition_tampering_after_submission_cannot_bypass_hash_check(): void
    {
        $profile = $this->submitted();
        $submission = $profile->latestSubmission;
        $submission->update(['definition' => [...$submission->definition, 'display_name' => 'نسخه دستکاری‌شده']]);
        $this->actingAs($this->admin)->post('/admin/coach-profiles/'.$profile->id.'/review', ['revision' => 2, 'action' => 'publish', 'reviewed' => true])->assertSessionHasErrors('profile');
        $this->assertDatabaseCount('coach_profile_publications', 0);
    }

    public function test_invalid_or_whitespace_duplicate_definitions_and_stale_drafts_are_rejected(): void
    {
        $profile = CoachProfile::factory()->create();
        $this->actingAs($profile->owner);
        foreach ([['focus_areas' => ['موضوع', ' موضوع ']], ['display_name' => ' a '], ['languages' => []], ['bio' => 'کوتاه'], ['focus_areas' => array_fill(0, 13, 'موضوع')]] as $bad) {
            $this->post('/coach/profile', ['revision' => 1, 'definition' => [...$profile->draft, ...$bad]])->assertSessionHasErrors();
        }
        $this->post('/coach/profile', ['revision' => 99, 'definition' => $profile->draft])->assertSessionHasErrors('profile');
        $this->assertSame(1, $profile->fresh()->revision);
    }

    public function test_migration_refuses_to_destroy_coach_consent_and_content_history(): void
    {
        CoachProfile::factory()->create();
        $migration = require database_path('migrations/2026_11_15_214612_create_coach_profile_publications.php');
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }
}
