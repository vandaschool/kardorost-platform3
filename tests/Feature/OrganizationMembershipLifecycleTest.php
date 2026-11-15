<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Enrollment;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationLicense;
use App\Models\OrganizationSeat;
use App\Models\Role;
use App\Models\Submission;
use App\Models\User;
use App\Services\OrganizationMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OrganizationMembershipLifecycleTest extends TestCase
{
    use RefreshDatabase;

    private Organization $organization;

    private User $student;

    private User $manager;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        $this->organization = Organization::factory()->create();
        $this->student = $this->user('student');
        $this->manager = $this->user('org_admin');
        $this->admin = $this->user('admin');
        $this->organization->members()->attach($this->student->id, ['kind' => 'member']);
        $this->organization->members()->attach($this->manager->id, ['kind' => 'manager']);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('key', $role)->value('id'));

        return $user;
    }

    private function key(?User $user = null): string
    {
        return $this->organization->members()->where('users.id', ($user ?? $this->student)->id)->firstOrFail()->pivot->membership_key;
    }

    private function leave(array $changes = []): void
    {
        $this->actingAs($this->student)->post('/organization-memberships/'.$this->organization->id.'/leave', [...['membership_key' => $this->key(), 'reason' => 'پایان همکاری'], ...$changes])->assertRedirect();
    }

    public function test_every_new_membership_has_unique_generation_from_both_relation_directions(): void
    {
        $this->assertTrue(Str::isUuid($this->key()));
        $this->assertNotSame($this->key(), $this->key($this->manager));
        $other = Organization::factory()->create();
        $this->student->organizations()->attach($other->id, ['kind' => 'member']);
        $key = $other->members()->firstOrFail()->pivot->membership_key;
        $this->assertTrue(Str::isUuid($key));
        $this->assertNotSame($key, $this->key());
    }

    public function test_student_can_leave_and_organization_report_access_is_immediately_revoked(): void
    {
        $this->actingAs($this->manager)->get('/organization/members/'.$this->student->id)->assertOk();
        $this->leave();
        $this->assertDatabaseMissing('organization_user', ['organization_id' => $this->organization->id, 'user_id' => $this->student->id]);
        $this->actingAs($this->manager)->get('/organization/members/'.$this->student->id)->assertForbidden();
        $this->assertSame(1, AuditEvent::where('action', 'organization_membership.leave')->count());
        $this->actingAs($this->student)->get('/organization-memberships')->assertInertia(fn (Assert $page) => $page->has('memberships', 0)->has('history', 1)->where('history.0.reason', 'پایان همکاری'));
    }

    public function test_leaving_preserves_personal_and_sponsored_learning_and_consumed_seat_ledger(): void
    {
        $license = OrganizationLicense::factory()->create(['organization_id' => $this->organization->id]);
        $enrollment = Enrollment::factory()->create(['user_id' => $this->student->id, 'cohort_id' => $license->cohort_id, 'organization_license_id' => $license->id, 'payment_plan' => 'organization']);
        $seat = OrganizationSeat::factory()->create(['organization_license_id' => $license->id, 'enrollment_id' => $enrollment->id, 'user_id' => $this->student->id]);
        $personal = Enrollment::factory()->create(['user_id' => $this->student->id]);
        $this->leave();
        $this->assertSame('active', $enrollment->fresh()->status);
        $this->assertSame('active', $personal->fresh()->status);
        $this->assertNotNull($seat->fresh());
        $this->assertSame(1, $license->seats()->count());
        $this->get('/learning/'.$enrollment->id)->assertOk();
    }

    public function test_private_learning_file_access_is_revoked_for_former_organization_relation(): void
    {
        Storage::fake('learning');
        Storage::disk('learning')->put('private/file.txt', 'private answer');
        $submission = Submission::factory()->create(['file_path' => 'private/file.txt', 'file_name' => 'answer.txt']);
        $submission->enrollment->update(['user_id' => $this->student->id]);
        $this->actingAs($this->manager)->get('/submissions/'.$submission->id.'/file')->assertDownload();
        $this->leave();
        $this->actingAs($this->manager)->get('/submissions/'.$submission->id.'/file')->assertForbidden();
        $this->actingAs($this->student)->get('/submissions/'.$submission->id.'/file')->assertDownload();
    }

    public function test_only_own_memberships_and_history_are_exposed(): void
    {
        $outsider = $this->user('student');
        $this->leave();
        $this->actingAs($outsider)->get('/organization-memberships')->assertInertia(fn (Assert $page) => $page->has('memberships', 0)->has('history', 0));
        $this->get('/admin/organization-managers')->assertForbidden();
    }

    public function test_manager_can_remove_current_member_but_not_other_organizations_member(): void
    {
        $this->actingAs($this->manager)->post('/organization/members/'.$this->student->id.'/remove', ['membership_key' => $this->key(), 'reason' => 'پایان همکاری عضو'])->assertRedirect();
        $this->get('/organization/members/'.$this->student->id)->assertForbidden();
        $other = Organization::factory()->create();
        $peer = $this->user('student');
        $other->members()->attach($peer->id, ['kind' => 'member']);
        $key = $other->members()->firstOrFail()->pivot->membership_key;
        $this->post('/organization/members/'.$peer->id.'/remove', ['membership_key' => $key, 'reason' => 'خارج از محدوده'])->assertSessionHasErrors('invitation');
        $this->assertTrue($other->members()->where('users.id', $peer->id)->exists());
    }

    public function test_manager_cannot_revoke_manager_or_use_admin_assignment_routes(): void
    {
        $this->actingAs($this->manager)->post('/organization/members/'.$this->manager->id.'/remove', ['membership_key' => $this->key($this->manager), 'reason' => 'حذف مدیر'])->assertForbidden();
        $this->post('/admin/organizations/'.$this->organization->id.'/managers', ['phone' => $this->student->phone])->assertForbidden();
        $this->post('/admin/organizations/'.$this->organization->id.'/members/'.$this->student->id.'/remove', ['membership_key' => $this->key(), 'reason' => 'دور زدن', 'action' => 'remove'])->assertForbidden();
    }

    public function test_old_accepted_invitation_cannot_recreate_membership_after_exit(): void
    {
        $invitation = OrganizationInvitation::factory()->create(['organization_id' => $this->organization->id, 'phone' => $this->student->phone, 'status' => 'accepted', 'responded_by' => $this->student->id, 'responded_at' => now(), 'revision' => 2]);
        $this->leave();
        $this->assertSame('membership_ended', $invitation->fresh()->status);
        $this->assertSame(3, $invitation->fresh()->revision);
        $this->post('/organization-invitations/'.$invitation->id.'/respond', ['revision' => 2, 'action' => 'accept', 'consent' => true])->assertSessionHasErrors('invitation');
        $this->assertSame(1, $this->organization->members()->count());
    }

    public function test_rejoining_requires_new_invitation_and_consent_and_rotates_generation(): void
    {
        $oldKey = $this->key();
        $invitation = OrganizationInvitation::factory()->create(['organization_id' => $this->organization->id, 'phone' => $this->student->phone, 'status' => 'accepted', 'responded_by' => $this->student->id, 'responded_at' => now()]);
        $this->leave();
        app(OrganizationMembership::class)->change($this->manager, $invitation->fresh(), 2, 'renew');
        $this->post('/organization-invitations/'.$invitation->id.'/respond', ['revision' => 3, 'action' => 'accept'])->assertSessionHasErrors('consent');
        $this->post('/organization-invitations/'.$invitation->id.'/respond', ['revision' => 3, 'action' => 'accept', 'consent' => true])->assertRedirect();
        $this->assertNotSame($oldKey, $this->key());
        $this->post('/organization-memberships/'.$this->organization->id.'/leave', ['membership_key' => $oldKey, 'reason' => 'فرم قدیمی'])->assertSessionHasErrors('invitation');
        $this->assertTrue($this->organization->members()->where('users.id', $this->student->id)->exists());
    }

    public function test_invalid_generation_missing_reason_and_other_member_key_do_not_remove_membership(): void
    {
        $this->actingAs($this->student);
        foreach ([['membership_key' => 'bad', 'reason' => 'خروج'], ['membership_key' => $this->key(), 'reason' => '']] as $data) {
            $this->post('/organization-memberships/'.$this->organization->id.'/leave', $data)->assertSessionHasErrors();
        }
        $this->post('/organization-memberships/'.$this->organization->id.'/leave', ['membership_key' => $this->key($this->manager), 'reason' => 'کلید عضو دیگر'])->assertSessionHasErrors('invitation');
        $this->assertSame(2, $this->organization->members()->count());
    }

    public function test_duplicate_exit_is_stale_and_does_not_duplicate_audit(): void
    {
        $key = $this->key();
        $this->leave();
        $this->post('/organization-memberships/'.$this->organization->id.'/leave', ['membership_key' => $key, 'reason' => 'خروج دوباره'])->assertSessionHasErrors('invitation');
        $this->assertSame(1, AuditEvent::where('action', 'organization_membership.leave')->count());
    }

    public function test_member_can_leave_inactive_organization_while_manager_cannot_operate_it(): void
    {
        $this->organization->update(['status' => 'inactive']);
        $this->actingAs($this->manager)->post('/organization/members/'.$this->student->id.'/remove', ['membership_key' => $this->key(), 'reason' => 'غیرفعال'])->assertForbidden();
        $this->leave();
        $this->assertSame(1, $this->organization->members()->count());
    }

    public function test_admin_can_add_another_verified_manager_idempotently_with_org_role(): void
    {
        $target = $this->user('coach');
        $url = '/admin/organizations/'.$this->organization->id.'/managers';
        $this->actingAs($this->admin)->post($url, ['phone' => $target->phone])->assertRedirect();
        $this->post($url, ['phone' => $target->phone])->assertRedirect();
        $this->assertSame('manager', $this->organization->members()->where('users.id', $target->id)->firstOrFail()->pivot->kind);
        $this->assertTrue($target->hasRole('org_admin'));
        $this->assertTrue($target->hasRole('coach'));
        $this->assertSame(1, AuditEvent::where('action', 'organization_membership.manager_assigned')->count());
        $this->actingAs($target)->get('/organization')->assertOk();
    }

    public function test_promotion_requires_current_generation_and_rotates_member_key(): void
    {
        $old = $this->key();
        $url = '/admin/organizations/'.$this->organization->id.'/managers';
        $this->actingAs($this->admin)->post($url, ['phone' => $this->student->phone])->assertSessionHasErrors('invitation');
        $this->post($url, ['phone' => $this->student->phone, 'expected_key' => $old])->assertRedirect();
        $this->assertNotSame($old, $this->key());
        $this->actingAs($this->student)->post('/organization-memberships/'.$this->organization->id.'/leave', ['membership_key' => $old, 'reason' => 'تب قدیمی'])->assertSessionHasErrors('invitation');
        $this->assertSame('manager', $this->organization->members()->where('users.id', $this->student->id)->firstOrFail()->pivot->kind);
    }

    public function test_unverified_inactive_and_unknown_manager_targets_are_rejected(): void
    {
        $unverified = $this->user('student');
        $unverified->update(['phone_verified_at' => null]);
        $inactive = $this->user('coach');
        $inactive->forceFill(['is_active' => false])->save();
        $this->actingAs($this->admin);
        foreach ([$unverified->phone, $inactive->phone, '09121111111'] as $phone) {
            $this->post('/admin/organizations/'.$this->organization->id.'/managers', ['phone' => $phone])->assertSessionHasErrors('invitation');
        }
        $this->assertSame(2, $this->organization->members()->count());
    }

    public function test_last_usable_manager_cannot_be_revoked_until_replacement_exists(): void
    {
        $url = '/admin/organizations/'.$this->organization->id.'/members/'.$this->manager->id.'/remove';
        $data = ['action' => 'revoke_manager', 'membership_key' => $this->key($this->manager), 'reason' => 'جایگزینی مدیر'];
        $this->actingAs($this->admin)->post($url, $data)->assertSessionHasErrors('invitation');
        $inactive = $this->user('org_admin');
        $inactive->forceFill(['is_active' => false])->save();
        $this->organization->members()->attach($inactive->id, ['kind' => 'manager']);
        $this->post($url, $data)->assertSessionHasErrors('invitation');
        $replacement = $this->user('org_admin');
        $this->organization->members()->attach($replacement->id, ['kind' => 'manager']);
        $this->post($url, $data)->assertRedirect();
        $this->actingAs($this->manager)->get('/organization/members/'.$this->student->id)->assertForbidden();
        $this->assertTrue($replacement->hasRole('org_admin'));
        $this->assertTrue($this->manager->hasRole('org_admin'));
    }

    public function test_manager_cannot_leave_via_student_exit_even_with_both_roles(): void
    {
        $this->manager->roles()->attach(Role::where('key', 'student')->value('id'));
        $this->actingAs($this->manager)->post('/organization-memberships/'.$this->organization->id.'/leave', ['membership_key' => $this->key($this->manager), 'reason' => 'خروج مدیر'])->assertForbidden();
    }

    public function test_admin_history_is_organization_scoped_and_membership_keys_are_not_exposed_in_history(): void
    {
        $this->leave();
        $other = Organization::factory()->create();
        $this->actingAs($this->admin)->get('/admin/organization-managers?organization='.$other->id)->assertInertia(fn (Assert $page) => $page->has('history', 0));
        $this->get('/admin/organization-managers?organization='.$this->organization->id)->assertInertia(fn (Assert $page) => $page->has('history', 1)->missing('history.0.membership_key')->where('history.0.member_name', $this->student->name));
        $this->assertSame(1, DB::table('organization_user')->where('organization_id', $this->organization->id)->count());
    }

    public function test_organization_creation_cannot_bypass_verified_manager_requirement(): void
    {
        $target = $this->user('student');
        $target->update(['phone_verified_at' => null]);
        $this->actingAs($this->admin)->post('/admin/organizations', ['name' => 'سازمان تازه', 'type' => 'company', 'phone' => $target->phone])->assertSessionHasErrors('phone');
        $this->assertSame(1, Organization::count());
        $this->assertFalse($target->hasRole('org_admin'));
    }

    public function test_member_can_withdraw_consent_after_student_role_is_replaced(): void
    {
        $this->student->roles()->sync([Role::where('key', 'coach')->value('id')]);
        $this->leave();
        $this->assertFalse($this->organization->members()->where('users.id', $this->student->id)->exists());
    }
}
