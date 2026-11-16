<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\Role;
use App\Models\User;
use App\Notifications\PlatformAnnouncement;
use App\Services\Announcements;
use App\Services\OrganizationMembership;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OrganizationAnnouncementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    private User $student;

    private User $foreignManager;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        $this->travelTo(now()->startOfMinute());
        $this->admin = $this->user('admin');
        $this->manager = $this->user('org_admin');
        $this->manager->roles()->attach(Role::where('key', 'student')->value('id'));
        $this->student = $this->user('student');
        $this->foreignManager = $this->user('org_admin');
        $this->foreignManager->roles()->attach(Role::where('key', 'student')->value('id'));
        $this->organization = Organization::factory()->create(['name' => 'PRIVATE-ORGANIZATION-NAME', 'status' => 'active']);
        $this->organization->members()->attach($this->manager->id, ['kind' => 'manager']);
        $this->organization->members()->attach([$this->student->id => ['kind' => 'member'], $this->foreignManager->id => ['kind' => 'member']]);
        $other = Organization::factory()->create(['status' => 'active', 'type' => 'university']);
        $other->members()->attach($this->foreignManager->id, ['kind' => 'manager']);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('key', $role)->value('id'));

        return $user;
    }

    private function definition(array $roles = ['student'], ?Organization $organization = null): array
    {
        return ['title' => 'PRIVATE-ORGANIZATION-ANNOUNCEMENT', 'message' => 'متن خصوصی صرفاً آزمایشی برای اعضای مجموعه', 'roles' => $roles, 'audience' => ['type' => 'organization', 'organization_id' => ($organization ?? $this->organization)->id]];
    }

    private function published(array $roles = ['student'], ?Organization $organization = null): Announcement
    {
        $announcement = app(Announcements::class)->save($this->admin, $this->definition($roles, $organization));
        app(Announcements::class)->publish($this->admin, $announcement, 1);

        return $announcement->fresh();
    }

    private function platformCount(?User $user = null): int
    {
        $query = DB::table('notifications')->where('type', PlatformAnnouncement::class);
        if ($user) {
            $query->where('notifiable_id', $user->id);
        }

        return $query->count();
    }

    public function test_scope_is_server_labeled_encrypted_and_all_scope_clears_prior_organization(): void
    {
        $definition = $this->definition();
        $definition['audience']['label'] = 'FORGED-ORG-LABEL';
        $announcement = app(Announcements::class)->save($this->admin, $definition);
        $this->assertSame('PRIVATE-ORGANIZATION-NAME', $announcement->audience['label']);
        $this->assertStringNotContainsString('PRIVATE-ORGANIZATION-NAME', DB::table('announcements')->where('id', $announcement->id)->value('audience'));
        app(Announcements::class)->save($this->admin, [...$definition, 'audience' => ['type' => 'all']], $announcement, 1);
        $this->assertNull($announcement->fresh()->audience);
    }

    public function test_invalid_inactive_organization_and_unsupported_roles_never_fall_back_to_global(): void
    {
        $inactive = Organization::factory()->create(['status' => 'inactive']);
        $valid = $this->definition();
        foreach ([['audience' => ['type' => 'all', 'organization_id' => $this->organization->id]], ['audience' => ['type' => 'unknown']], ['audience' => ['type' => 'organization']], ['audience' => ['type' => 'organization', 'organization_id' => 999999]], ['audience' => ['type' => 'organization', 'organization_id' => $inactive->id]], ['roles' => ['coach']], ['roles' => ['admin']]] as $change) {
            $this->actingAs($this->admin)->postJson('/admin/announcements', ['revision' => 1, 'definition' => [...$valid, ...$change]])->assertUnprocessable();
        }
        $this->assertSame(0, Announcement::count());
    }

    public function test_student_and_manager_audiences_follow_the_selected_organization_kind(): void
    {
        $members = $this->published();
        $this->assertEqualsCanonicalizing([$this->student->id, $this->foreignManager->id], $members->recipients()->pluck('user_id')->all());
        $managers = $this->published(['org_admin']);
        $this->assertSame([$this->manager->id], $managers->recipients()->pluck('user_id')->all());
        $both = $this->published(['student', 'org_admin']);
        $this->assertSame(3, $both->recipients()->count());
        $this->assertSame(3, $both->recipients()->distinct()->count('user_id'));
        $this->assertFalse($managers->recipients()->where('user_id', $this->foreignManager->id)->exists());
    }

    public function test_company_and_university_scopes_are_isolated_and_do_not_create_membership(): void
    {
        $university = Organization::factory()->create(['type' => 'university', 'status' => 'active']);
        $member = $this->user('student');
        $university->members()->attach($member->id, ['kind' => 'member']);
        $before = DB::table('organization_user')->count();
        $announcement = $this->published(['student'], $university);
        $this->assertSame([$member->id], $announcement->recipients()->pluck('user_id')->all());
        $this->assertSame($before, DB::table('organization_user')->count());
        $this->assertSame(0, OrganizationInvitation::count());
    }

    public function test_membership_snapshot_is_encrypted_and_bound_to_recipient_and_source(): void
    {
        $announcement = $this->published();
        $recipient = $announcement->recipients()->where('user_id', $this->student->id)->sole();
        $key = $this->organization->members()->where('users.id', $this->student->id)->firstOrFail()->pivot->membership_key;
        $this->assertSame($key, $recipient->scope_snapshot['membership_key']);
        $this->assertSame($announcement->id, $recipient->scope_snapshot['announcement_id']);
        $this->assertSame($recipient->id, $recipient->scope_snapshot['recipient_id']);
        $this->assertSame($this->student->id, $recipient->scope_snapshot['user_id']);
        $this->assertStringNotContainsString($key, DB::table('announcement_recipients')->where('id', $recipient->id)->value('scope_snapshot'));
    }

    public function test_private_delivered_payload_is_encrypted_and_displayed_only_in_owners_inbox(): void
    {
        $announcement = $this->published();
        $this->assertSame(2, app(Announcements::class)->deliver($announcement->id));
        $stored = DB::table('notifications')->where('type', PlatformAnnouncement::class)->where('notifiable_id', $this->student->id)->sole();
        $raw = $stored->data;
        $this->assertStringNotContainsString('PRIVATE-ORGANIZATION-ANNOUNCEMENT', $raw);
        $this->assertStringNotContainsString('PRIVATE-ORGANIZATION-NAME', $raw);
        $this->actingAs($this->student)->get('/notifications')->assertInertia(fn (Assert $page) => $page->where('notifications.data.0.data.title', 'PRIVATE-ORGANIZATION-ANNOUNCEMENT')->where('notifications.data.0.data.audience_label', 'PRIVATE-ORGANIZATION-NAME')->missing('notifications.data.0.data.private_payload'));
        $this->assertSame($raw, DB::table('notifications')->where('id', $stored->id)->value('data'));
        $this->post('/notifications/'.$stored->id.'/read')->assertRedirect();
        $this->assertSame($raw, DB::table('notifications')->where('id', $stored->id)->value('data'));
        $this->actingAs($this->manager)->get('/notifications')->assertInertia(fn (Assert $page) => $page->has('notifications.data', 0));
    }

    public function test_corrupt_or_cross_recipient_ciphertext_does_not_display_private_text_or_fallback_plaintext(): void
    {
        $announcement = $this->published();
        app(Announcements::class)->deliver($announcement->id);
        $notification = DB::table('notifications')->where('type', PlatformAnnouncement::class)->where('notifiable_id', $this->student->id)->sole();
        $data = json_decode($notification->data, true);
        $this->assertSame('اعلان خصوصی', PlatformAnnouncement::display($data, $this->manager->id)['title']);
        $copyId = (string) Str::uuid();
        DB::table('notifications')->insert(['id' => $copyId, 'type' => PlatformAnnouncement::class, 'notifiable_type' => User::class, 'notifiable_id' => $this->manager->id, 'data' => $notification->data, 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($this->manager)->get('/notifications')->assertInertia(fn (Assert $page) => $page->where('notifications.data.0.data.title', 'اعلان خصوصی')->missing('notifications.data.0.data.private_payload'));
        $data['private_payload'] = 'CORRUPTED';
        $data['title'] = 'UNTRUSTED-PLAINTEXT';
        DB::table('notifications')->where('id', $notification->id)->update(['data' => json_encode($data)]);
        $this->actingAs($this->student)->get('/notifications')->assertInertia(fn (Assert $page) => $page->where('notifications.data.0.data.title', 'اعلان خصوصی')->missing('notifications.data.0.data.private_payload'));
        $this->assertSame(json_encode($data), DB::table('notifications')->where('id', $notification->id)->value('data'));
    }

    public function test_departure_and_explicit_rejoining_do_not_reactivate_old_pending_delivery(): void
    {
        $announcement = $this->published();
        $membership = app(OrganizationMembership::class);
        $old = $this->organization->members()->where('users.id', $this->student->id)->firstOrFail()->pivot->membership_key;
        $membership->endMembership($this->student, $this->organization, $this->student, $old, 'انصراف آزمایشی', 'leave');
        $membership->invite($this->manager, $this->organization, $this->student->phone);
        $invitation = OrganizationInvitation::where('organization_id', $this->organization->id)->where('phone', $this->student->phone)->sole();
        $membership->respond($this->student, $invitation, 1, 'accept');
        $new = $this->organization->members()->where('users.id', $this->student->id)->firstOrFail()->pivot->membership_key;
        $this->assertNotSame($old, $new);
        $this->assertSame(1, app(Announcements::class)->deliver($announcement->id));
        $this->assertSame(0, $this->platformCount($this->student));
        $this->assertSame('scope_changed', $announcement->recipients()->where('user_id', $this->student->id)->sole()->failure_code);
    }

    public function test_manager_revocation_and_reassignment_cannot_reuse_old_pending_manager_delivery(): void
    {
        $announcement = $this->published(['org_admin']);
        $other = $this->user('org_admin');
        $this->organization->members()->attach($other->id, ['kind' => 'manager']);
        $key = $this->organization->members()->where('users.id', $this->manager->id)->firstOrFail()->pivot->membership_key;
        $service = app(OrganizationMembership::class);
        $service->endMembership($this->admin, $this->organization, $this->manager, $key, 'آزمون تغییر مدیر', 'revoke_manager');
        $service->assignManager($this->admin, $this->organization, $this->manager->phone, null);
        $this->assertSame(0, app(Announcements::class)->deliver($announcement->id));
        $this->assertSame(0, $this->platformCount($this->manager));
    }

    public function test_missing_or_replayed_scope_proof_is_never_treated_as_global(): void
    {
        $first = $this->published();
        $second = $this->published();
        $from = $first->recipients()->where('user_id', $this->student->id)->sole();
        $to = $second->recipients()->where('user_id', $this->student->id)->sole();
        $to->update(['scope_snapshot' => $from->scope_snapshot]);
        $second->recipients()->where('user_id', $this->foreignManager->id)->update(['scope_snapshot' => null]);
        $this->assertSame(0, app(Announcements::class)->deliver($second->id));
        $this->assertSame(0, $this->platformCount());
        $first->update(['audience' => null]);
        $this->assertSame(0, app(Announcements::class)->deliver($first->id));
    }

    public function test_inactive_organization_or_current_role_loss_prevents_pending_delivery(): void
    {
        $announcement = $this->published();
        $this->organization->update(['status' => 'inactive']);
        $this->assertSame(0, app(Announcements::class)->deliver($announcement->id));
        $this->organization->update(['status' => 'active']);
        $next = $this->published();
        $this->student->roles()->detach();
        $this->foreignManager->roles()->detach(Role::where('key', 'student')->value('id'));
        $this->assertSame(0, app(Announcements::class)->deliver($next->id));
    }

    public function test_scope_edit_cancels_pending_approval_and_preserves_original_target_label(): void
    {
        $announcement = app(Announcements::class)->save($this->admin, $this->definition());
        $schedule = app(Announcements::class)->schedule($this->admin, $announcement, 1, CarbonImmutable::now()->addMinutes(2), true);
        $other = Organization::factory()->create(['name' => 'NEW-TARGET', 'status' => 'active']);
        app(Announcements::class)->save($this->admin, $this->definition(['student'], $other), $announcement, 2);
        $this->assertSame('cancelled', $schedule->fresh()->status);
        $this->assertSame('PRIVATE-ORGANIZATION-NAME', $schedule->fresh()->definition['audience']['label']);
        $this->assertSame($other->id, $announcement->fresh()->audience['organization_id']);
    }

    public function test_scheduled_org_announcement_uses_members_at_due_and_preserves_approved_name(): void
    {
        $announcement = app(Announcements::class)->save($this->admin, $this->definition());
        $schedule = app(Announcements::class)->schedule($this->admin, $announcement, 1, CarbonImmutable::now()->addMinutes(2), true);
        $this->organization->members()->detach([$this->student->id, $this->foreignManager->id]);
        $new = $this->user('student');
        $this->organization->members()->attach($new->id, ['kind' => 'member']);
        $this->organization->update(['name' => 'NEW-CURRENT-NAME']);
        $this->travelTo($schedule->scheduled_for->copy()->addMinute());
        $this->assertSame('published', app(Announcements::class)->processSchedule($schedule));
        $this->assertSame([$new->id], $announcement->recipients()->pluck('user_id')->all());
        app(Announcements::class)->deliver($announcement->id);
        $this->actingAs($new)->get('/notifications')->assertInertia(fn (Assert $page) => $page->where('notifications.data.0.data.audience_label', 'PRIVATE-ORGANIZATION-NAME'));
    }

    public function test_global_scheduled_approval_and_legacy_plaintext_inbox_remain_compatible(): void
    {
        $announcement = app(Announcements::class)->save($this->admin, ['title' => 'LEGACY-GLOBAL', 'message' => 'پیام عمومی آزمایشی قدیمی', 'roles' => ['student']]);
        $schedule = app(Announcements::class)->schedule($this->admin, $announcement, 1, CarbonImmutable::now()->addMinutes(2), true);
        $this->assertArrayNotHasKey('audience', $schedule->definition);
        $this->travelTo($schedule->scheduled_for->copy()->addMinute());
        $this->assertSame('published', app(Announcements::class)->processSchedule($schedule));
        app(Announcements::class)->deliver($announcement->id);
        $this->actingAs($this->student)->get('/notifications')->assertInertia(fn (Assert $page) => $page->where('notifications.data.0.data.title', 'LEGACY-GLOBAL'));
    }

    public function test_only_admin_can_choose_org_targets_and_no_role_is_granted_by_selection(): void
    {
        foreach ([$this->manager, $this->student, $this->user('coach')] as $user) {
            $this->actingAs($user)->post('/admin/announcements', ['revision' => 1, 'definition' => $this->definition()])->assertForbidden();
            $this->get('/admin/announcements')->assertForbidden();
        }
        $this->actingAs($this->admin)->get('/admin/announcements')->assertInertia(fn (Assert $page) => $page->has('organization_choices', 2));
        $this->assertFalse($this->student->hasRole('org_admin'));
    }

    public function test_rollback_refuses_private_scope_or_inbox_history(): void
    {
        $announcement = $this->published();
        app(Announcements::class)->deliver($announcement->id);
        $announcement->update(['audience' => null]);
        $announcement->recipients()->delete();
        $migration = require database_path('migrations/2026_11_16_085306_add_organization_audience_to_announcements.php');
        try {
            $migration->down();
            $this->fail('Private inbox format must not be removed by rollback.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('preserved', $exception->getMessage());
        }
        $this->assertSame(2, $this->platformCount());
    }

    public function test_stored_unknown_scope_is_rejected_instead_of_becoming_global(): void
    {
        $this->expectException(ValidationException::class);
        app(Announcements::class)->audience(['student'], ['type' => 'all'])->count();
    }

    public function test_large_organization_keeps_all_membership_proofs_and_delivers_batches_once(): void
    {
        $organization = Organization::factory()->create(['status' => 'active']);
        $students = User::factory()->count(205)->create();
        foreach ($students as $student) {
            $student->roles()->attach(Role::where('key', 'student')->value('id'));
            $organization->members()->attach($student->id, ['kind' => 'member']);
        }
        $announcement = $this->published(['student'], $organization);
        $this->assertSame(205, $announcement->recipients()->whereNotNull('scope_snapshot')->count());
        foreach ([100, 100, 5, 0] as $count) {
            $this->assertSame($count, app(Announcements::class)->deliver($announcement->id));
        }
        $this->assertSame(205, $this->platformCount());
        $this->assertSame('completed', $announcement->fresh()->status);
    }
}
