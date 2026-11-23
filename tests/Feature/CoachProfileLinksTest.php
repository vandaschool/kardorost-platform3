<?php

namespace Tests\Feature;

use App\Models\CoachProfile;
use App\Models\Role;
use App\Models\User;
use App\Services\CoachProfiles;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class CoachProfileLinksTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        $this->owner = $this->user('coach');
        $this->admin = $this->user('admin');
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('key', $role)->value('id'));

        return $user;
    }

    private function links(string $suffix = 'first'): array
    {
        return [['label' => 'وب‌سایت آزمایشی۸۱', 'url' => 'https://example.test/software-only81/'.$suffix]];
    }

    private function draft(): array
    {
        return [...CoachProfile::factory()->make()->draft, 'display_name' => 'مربی پیوند صرفاً آزمایشی۸۱'];
    }

    private function published(?array $links = null): CoachProfile
    {
        $profile = app(CoachProfiles::class)->save($this->owner, [...$this->draft(), ...($links !== null ? ['links' => $links] : [])], 0);
        app(CoachProfiles::class)->submit($this->owner, $profile, 1, true);
        app(CoachProfiles::class)->review($this->admin, $profile, 2, 'publish', null);

        return $profile->fresh();
    }

    public function test_saved_links_are_encrypted_and_stay_private_until_same_version_consent_and_admin_review(): void
    {
        $profile = app(CoachProfiles::class)->save($this->owner, [...$this->draft(), 'links' => $this->links()], 0);
        $this->assertStringNotContainsString('example.test', DB::table('coach_profiles')->value('draft'));
        $this->get('/mentors/'.$profile->slug)->assertNotFound();
        $this->actingAs($this->owner)->post('/coach/profile/submit', ['revision' => 1])->assertSessionHasErrors('public_consent');
        $this->post('/coach/profile/submit', ['revision' => 1, 'public_consent' => true])->assertSessionHasNoErrors();
        $this->get('/mentors/'.$profile->slug)->assertNotFound();
        $this->assertStringNotContainsString('example.test', DB::table('coach_profile_submissions')->value('definition'));
        $this->actingAs($this->admin)->post('/admin/coach-profiles/'.$profile->id.'/review', ['revision' => 2, 'action' => 'publish', 'reviewed' => true])->assertSessionHasNoErrors();
        $this->get('/mentors/'.$profile->slug)->assertOk()->assertSee($this->links()[0]['url'], false)->assertSee('target="_blank"', false)->assertSee('rel="noopener noreferrer nofollow"', false)->assertDontSee($this->owner->phone);
        Http::assertNothingSent();
    }

    public function test_edit_and_submit_leave_old_public_links_and_ciphertext_unchanged_until_new_review(): void
    {
        $profile = $this->published($this->links());
        $old = DB::table('coach_profile_publications')->first();
        app(CoachProfiles::class)->save($this->owner, [...$profile->draft, 'links' => $this->links('second')], 3);
        $this->get('/mentors/'.$profile->slug)->assertSee($this->links()[0]['url'], false)->assertDontSee($this->links('second')[0]['url'], false);
        app(CoachProfiles::class)->submit($this->owner, $profile->fresh(), 4, true);
        $this->get('/mentors/'.$profile->slug)->assertSee($this->links()[0]['url'], false)->assertDontSee($this->links('second')[0]['url'], false);
        app(CoachProfiles::class)->review($this->admin, $profile, 5, 'publish', null);
        $this->get('/mentors/'.$profile->slug)->assertSee($this->links('second')[0]['url'], false)->assertDontSee($this->links()[0]['url'], false);
        $this->assertEquals($old, DB::table('coach_profile_publications')->where('id', $old->id)->first());
        $this->assertDatabaseCount('coach_profile_publications', 2);
    }

    public function test_rejection_keeps_prior_public_links_and_note_private(): void
    {
        $profile = $this->published($this->links());
        app(CoachProfiles::class)->save($this->owner, [...$profile->draft, 'links' => $this->links('rejected')], 3);
        app(CoachProfiles::class)->submit($this->owner, $profile->fresh(), 4, true);
        app(CoachProfiles::class)->review($this->admin, $profile, 5, 'reject', 'یادداشت خصوصی بررسی پیوند');
        $this->get('/mentors/'.$profile->slug)->assertSee($this->links()[0]['url'], false)->assertDontSee($this->links('rejected')[0]['url'], false)->assertDontSee('یادداشت خصوصی');
        $this->assertDatabaseCount('coach_profile_publications', 1);
    }

    public function test_removing_links_requires_saved_consented_reviewed_version_and_retains_history(): void
    {
        $profile = $this->published($this->links());
        $old = $profile->currentPublication->getRawOriginal('definition');
        app(CoachProfiles::class)->save($this->owner, [...$profile->draft, 'links' => []], 3);
        $this->assertArrayNotHasKey('links', $profile->fresh()->draft);
        $this->get('/mentors/'.$profile->slug)->assertSee($this->links()[0]['url'], false);
        app(CoachProfiles::class)->submit($this->owner, $profile->fresh(), 4, true);
        app(CoachProfiles::class)->review($this->admin, $profile, 5, 'publish', null);
        $this->get('/mentors/'.$profile->slug)->assertOk()->assertDontSee('data-public-coach-links', false)->assertDontSee($this->links()[0]['url'], false);
        $this->assertSame($old, DB::table('coach_profile_publications')->orderBy('id')->value('definition'));
    }

    public function test_empty_optional_links_keep_exact_legacy_definition_and_hash(): void
    {
        $draft = $this->draft();
        $profile = app(CoachProfiles::class)->save($this->owner, [...$draft, 'links' => []], 0);
        app(CoachProfiles::class)->submit($this->owner, $profile, 1, true);
        $submission = $profile->fresh()->latestSubmission;
        $this->assertSame($draft, $submission->definition);
        $this->assertSame(hash('sha256', json_encode($draft, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES)), $submission->definition_hash);
        $this->assertArrayNotHasKey('links', $submission->definition);
    }

    public function test_http_cannot_bypass_url_rules_or_claim_link_verification_and_keeps_existing_draft(): void
    {
        $profile = $this->published($this->links());
        $before = DB::table('coach_profiles')->first();
        foreach ([[['label' => 'پیوند اجرایی', 'url' => 'javascript:alert(1)']], [['label' => 'ورود نامعتبر', 'url' => 'https://user:password@example.test/']], [['label' => 'عنوان آزمایشی', 'url' => 'https://example.test/', 'verified' => true]]] as $links) {
            $this->actingAs($this->owner)->post('/coach/profile', ['revision' => 3, 'definition' => [...$profile->draft, 'links' => $links]])->assertSessionHasErrors('links');
        }
        $this->assertEquals($before, DB::table('coach_profiles')->first());
    }

    public function test_wrong_role_inactive_or_unverified_owner_cannot_change_profile_links(): void
    {
        $profile = $this->published($this->links());
        foreach (['student', 'org_admin', 'admin'] as $role) {
            $this->actingAs($this->user($role))->post('/coach/profile', ['revision' => 0, 'definition' => [...$this->draft(), 'links' => $this->links()]])->assertForbidden();
        }
        $this->owner->forceFill(['is_active' => false])->save();
        $this->actingAs($this->owner)->post('/coach/profile', ['revision' => 3, 'definition' => [...$profile->draft, 'links' => $this->links('changed')]])->assertRedirect('/login');
        $this->assertGuest();
        $this->owner->forceFill(['is_active' => true, 'phone_verified_at' => null])->save();
        $this->actingAs($this->owner->fresh())->post('/coach/profile', ['revision' => 3, 'definition' => [...$profile->draft, 'links' => $this->links('changed')]])->assertForbidden();
        $this->assertSame($this->links(), $profile->fresh()->draft['links']);
    }

    public function test_stale_editor_cannot_overwrite_a_new_links_draft(): void
    {
        $profile = $this->published($this->links());
        $this->actingAs($this->owner)->post('/coach/profile', ['revision' => 3, 'definition' => [...$profile->draft, 'links' => $this->links('second')]])->assertSessionHasNoErrors();
        $this->post('/coach/profile', ['revision' => 3, 'definition' => [...$profile->draft, 'links' => $this->links('stale')]])->assertSessionHasErrors('profile');
        $this->assertSame($this->links('second'), $profile->fresh()->draft['links']);
    }

    public function test_publication_hash_corruption_or_ciphertext_corruption_is_hidden_from_page_and_directory(): void
    {
        $profile = $this->published($this->links());
        $profile->currentPublication->update(['definition_hash' => str_repeat('f', 64)]);
        $this->get('/mentors/'.$profile->slug)->assertNotFound();
        $this->get('/mentors')->assertOk()->assertDontSee($profile->draft['display_name']);
        DB::table('coach_profile_publications')->where('id', $profile->current_publication_id)->update(['definition' => 'invalid-ciphertext']);
        $this->get('/mentors/'.$profile->slug)->assertNotFound();
        $this->get('/mentors')->assertOk()->assertDontSee($profile->draft['display_name']);
    }

    public function test_foreign_current_publication_cannot_supply_another_owners_public_links(): void
    {
        $profile = $this->published($this->links());
        $peer = CoachProfile::factory()->create(['draft' => [...$this->draft(), 'display_name' => 'همکار آزمایشی۸۱', 'links' => $this->links('peer')]]);
        app(CoachProfiles::class)->submit($peer->owner, $peer, 1, true);
        app(CoachProfiles::class)->review($this->admin, $peer, 2, 'publish', null);
        $profile->update(['current_publication_id' => $peer->fresh()->current_publication_id]);
        $this->get('/mentors/'.$profile->slug)->assertNotFound();
        $this->get('/mentors')->assertOk()->assertDontSee($profile->draft['display_name'])->assertSee($peer->draft['display_name']);
    }

    public function test_even_hash_consistent_unsafe_legacy_links_are_omitted_in_public_rendering(): void
    {
        $profile = $this->published($this->links());
        $definition = [...$profile->currentPublication->definition, 'links' => [['label' => 'پیوند نامعتبر', 'url' => 'javascript:legacyUnsafe()']]];
        $profile->currentPublication->update(['definition' => $definition, 'definition_hash' => hash('sha256', json_encode($definition, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES))]);
        $this->get('/mentors/'.$profile->slug)->assertOk()->assertDontSee('data-public-coach-links', false)->assertDontSee('javascript:', false);
    }

    public function test_plain_html_label_and_query_ampersands_are_escaped_and_profile_withdrawal_stops_public_page(): void
    {
        $link = ['label' => '<img src=x onerror=unsafeLink81()>', 'url' => 'https://example.test/path?x=1&y=2'];
        $profile = $this->published([$link]);
        $this->get('/mentors/'.$profile->slug)->assertOk()->assertSee('&lt;img src=x', false)->assertDontSee('<img src=x', false)->assertSee('href="https://example.test/path?x=1&amp;y=2"', false);
        $snapshot = DB::table('coach_profile_publications')->first();
        app(CoachProfiles::class)->withdraw($this->owner, $profile, 3, null);
        $this->get('/mentors/'.$profile->slug)->assertNotFound();
        $this->assertEquals($snapshot, DB::table('coach_profile_publications')->first());
    }
}
