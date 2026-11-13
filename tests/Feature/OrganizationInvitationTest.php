<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationInvitationImport;
use App\Models\Role;
use App\Models\User;
use App\Services\OrganizationMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OrganizationInvitationTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $student;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        $this->manager = $this->user('org_admin');
        $this->student = $this->user('student');
        $this->organization = Organization::factory()->create(['name' => 'سازمان دعوت', 'type' => 'university', 'status' => 'active']);
        $this->organization->members()->attach($this->manager->id, ['kind' => 'manager']);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('key', $role)->value('id'));

        return $user;
    }

    private function invite(?string $phone = null): OrganizationInvitation
    {
        app(OrganizationMembership::class)->invite($this->manager, $this->organization, $phone ?? $this->student->phone);

        return OrganizationInvitation::where('organization_id', $this->organization->id)->latest('id')->firstOrFail();
    }

    private function respond(OrganizationInvitation $invitation, string $action = 'accept', array $override = []): TestResponse
    {
        return $this->actingAs($this->student)->post('/organization-invitations/'.$invitation->id.'/respond', [...['revision' => $invitation->revision, 'action' => $action, 'consent' => true], ...$override]);
    }

    private function preview(string $content = "phone\n09121234567\n09121234568\n"): OrganizationInvitationImport
    {
        return app(OrganizationMembership::class)->preview($this->manager, $this->organization, UploadedFile::fake()->createWithContent('members.csv', $content));
    }

    public function test_unknown_phone_gets_invitation_without_account_membership_or_role_provisioning(): void
    {
        $this->actingAs($this->manager)->post('/organization/invitations', ['phone' => '+۹۸۹۱۲۱۲۳۴۵۶۷'])->assertSessionHasNoErrors();
        $this->assertDatabaseHas('organization_invitations', ['phone' => '989121234567', 'status' => 'pending', 'revision' => 1]);
        $this->assertDatabaseCount('users', 2);
        $this->assertSame(1, $this->organization->members()->count());
        $this->assertDatabaseCount('enrollments', 0);
        $this->assertDatabaseCount('organization_licenses', 0);
        Http::assertNothingSent();
    }

    public function test_legacy_add_member_endpoint_also_waits_for_verified_users_explicit_acceptance(): void
    {
        $this->actingAs($this->manager)->post('/organization/members', ['phone' => $this->student->phone])->assertSessionHasNoErrors();
        $invitation = OrganizationInvitation::firstOrFail();
        $this->get('/organization/members/'.$this->student->id)->assertForbidden();
        $this->respond($invitation)->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->get('/organization/members/'.$this->student->id)->assertOk();
        $this->assertDatabaseHas('organization_user', ['organization_id' => $this->organization->id, 'user_id' => $this->student->id, 'kind' => 'member']);
        $this->assertSame(['student'], $this->student->roleKeys());
        $this->assertDatabaseCount('enrollments', 0);
    }

    public function test_student_sees_only_own_invitations_and_registered_user_gets_internal_notice(): void
    {
        $own = $this->invite();
        OrganizationInvitation::factory()->create();
        $this->actingAs($this->student)->get('/organization-invitations')->assertInertia(fn (Assert $page) => $page->where('organizationInvitationCount', 1)->has('invitations.data', 1)->where('invitations.data.0.id', $own->id)->where('invitations.data.0.organization.name', $this->organization->name));
        $this->assertSame(1, $this->student->notifications()->count());
        $this->actingAs($this->manager)->get('/organization/invitations')->assertInertia(fn (Assert $page) => $page->has('invitations.data', 1)->missing('invitations.data.0.user')->missing('invitations.data.0.responded_by'));
    }

    public function test_invitation_created_before_registration_becomes_visible_after_normal_verified_signup(): void
    {
        $invitation = $this->invite('09121234567');
        $this->withSession(['verification' => ['phone' => $invitation->phone, 'mode' => 'register', 'verified_until' => time() + 600]])->post('/auth/complete', ['name' => 'عضو ثبت‌نام تازه', 'password' => 'Fresh-Only-2026', 'password_confirmation' => 'Fresh-Only-2026'])->assertRedirect('/panel');
        $this->get('/organization-invitations')->assertInertia(fn (Assert $page) => $page->where('organizationInvitationCount', 1)->where('invitations.data.0.id', $invitation->id));
        $this->assertSame(1, $this->organization->members()->count());
    }

    public function test_acceptance_requires_consent_and_replay_does_not_duplicate_membership_or_audit(): void
    {
        $invitation = $this->invite();
        $this->respond($invitation, 'accept', ['consent' => false])->assertSessionHasErrors('consent');
        $this->assertSame('pending', $invitation->fresh()->status);
        $this->respond($invitation)->assertSessionHasNoErrors();
        $this->respond($invitation)->assertSessionHasNoErrors();
        $this->assertSame(2, $this->organization->members()->count());
        $this->assertSame(1, AuditEvent::where('action', 'organization_invitation.accept')->count());
        $this->assertSame(2, $invitation->fresh()->revision);
        $this->assertDatabaseCount('enrollments', 0);
    }

    public function test_decline_needs_no_consent_and_renewal_invalidates_the_old_response_form(): void
    {
        $invitation = $this->invite();
        $this->respond($invitation, 'decline', ['consent' => false])->assertSessionHasNoErrors();
        $this->respond($invitation, 'decline', ['consent' => false])->assertSessionHasNoErrors();
        $this->respond($invitation)->assertSessionHasErrors('invitation');
        $this->assertSame(1, $this->organization->members()->count());
        $this->actingAs($this->manager)->post('/organization/invitations/'.$invitation->id.'/change', ['action' => 'renew', 'revision' => 2])->assertSessionHasNoErrors();
        $this->respond($invitation)->assertSessionHasErrors('invitation');
        $this->respond($invitation->fresh())->assertSessionHasNoErrors();
        $this->assertSame('accepted', $invitation->fresh()->status);
        $this->assertSame(4, $invitation->fresh()->revision);
    }

    public function test_foreign_user_unverified_account_and_nonstudent_cannot_respond(): void
    {
        $invitation = $this->invite();
        $this->actingAs($this->user('student'))->post('/organization-invitations/'.$invitation->id.'/respond', ['action' => 'accept', 'revision' => 1, 'consent' => true])->assertForbidden();
        $this->student->update(['phone_verified_at' => null]);
        $this->respond($invitation)->assertForbidden();
        $this->get('/organization-invitations')->assertForbidden();
        $coach = $this->user('coach');
        $coachInvitation = $this->invite($coach->phone);
        $this->actingAs($coach)->post('/organization-invitations/'.$coachInvitation->id.'/respond', ['action' => 'accept', 'revision' => 1, 'consent' => true])->assertForbidden();
        $this->assertSame(1, $this->organization->members()->count());
    }

    public function test_revoked_expired_and_suspended_org_invitations_cannot_create_membership(): void
    {
        $invitation = $this->invite();
        $this->actingAs($this->manager)->post('/organization/invitations/'.$invitation->id.'/change', ['action' => 'revoke', 'revision' => 1])->assertSessionHasNoErrors();
        $this->respond($invitation)->assertSessionHasErrors('invitation');
        app(OrganizationMembership::class)->change($this->manager, $invitation, 2, 'renew');
        $this->travel(8)->days();
        $this->respond($invitation->fresh())->assertSessionHasErrors('invitation');
        $this->organization->update(['status' => 'inactive']);
        $this->respond($invitation->fresh())->assertForbidden();
        $this->get('/organization-invitations')->assertInertia(fn (Assert $page) => $page->has('invitations.data', 0)->where('organizationInvitationCount', 0));
        $this->assertSame(1, $this->organization->members()->count());
    }

    public function test_expiry_is_enforced_at_exact_boundary_and_renewal_allows_new_response(): void
    {
        $invitation = $this->invite();
        $this->travelTo($invitation->expires_at);
        $this->respond($invitation)->assertSessionHasErrors('invitation');
        app(OrganizationMembership::class)->change($this->manager, $invitation, 1, 'renew');
        $this->respond($invitation->fresh())->assertSessionHasNoErrors();
    }

    public function test_duplicate_invites_do_not_resend_or_overwrite_existing_manager_membership(): void
    {
        $invitation = $this->invite();
        $this->invite();
        $this->assertSame(1, $this->student->notifications()->count());
        $this->assertDatabaseCount('organization_invitations', 1);
        $this->assertFalse(app(OrganizationMembership::class)->invite($this->manager, $this->organization, $this->manager->phone));
        $this->organization->members()->attach($this->student->id, ['kind' => 'manager']);
        $this->respond($invitation)->assertSessionHasNoErrors();
        $this->assertDatabaseHas('organization_user', ['organization_id' => $this->organization->id, 'user_id' => $this->student->id, 'kind' => 'manager']);
        $this->actingAs($this->manager)->post('/organization/invitations/'.$invitation->id.'/change', ['action' => 'revoke', 'revision' => 2])->assertSessionHasErrors('invitation');
    }

    public function test_manager_access_is_scoped_to_current_selected_active_organization(): void
    {
        $invitation = $this->invite();
        $other = Organization::factory()->create(['name' => 'سازمان دیگر', 'type' => 'company', 'status' => 'active']);
        $other->members()->attach($this->manager->id, ['kind' => 'manager']);
        $this->actingAs($this->manager)->withSession(['organization_id' => $other->id])->get('/organization/invitations')->assertInertia(fn (Assert $page) => $page->has('invitations.data', 0));
        $this->post('/organization/invitations/'.$invitation->id.'/change', ['action' => 'revoke', 'revision' => 1])->assertForbidden();
        $this->organization->members()->detach($this->manager->id);
        $this->withSession(['organization_id' => $this->organization->id])->post('/organization/invitations', ['phone' => '09121234567'])->assertForbidden();
        foreach (['student', 'coach', 'org_admin', 'admin'] as $role) {
            $this->actingAs($this->user($role))->get('/organization/invitations')->assertForbidden();
        }
    }

    public function test_csv_preview_normalizes_digits_and_duplicates_without_creating_invites_or_exposing_account_status(): void
    {
        $preview = $this->preview("\xEF\xBB\xBFphone\r\n۰۹۱۲۱۲۳۴۵۶۷\r\n+989121234567\r\n09121234568\r\n");
        $this->assertSame(['989121234567', '989121234568'], $preview->phones);
        $this->assertSame(1, $preview->duplicate_count);
        $this->assertSame(0, $preview->invalid_count);
        $this->assertDatabaseCount('organization_invitations', 0);
        $this->assertDatabaseCount('users', 2);
        $this->actingAs($this->manager)->get('/organization/invitations?preview='.$preview->id)->assertInertia(fn (Assert $page) => $page->where('preview.valid_count', 2)->where('preview.rows.1.status', 'duplicate')->missing('preview.rows.0.user')->missing('preview.rows.0.registered'));
    }

    public function test_csv_confirmation_is_required_and_ignores_client_substitution_of_phone_list(): void
    {
        $preview = $this->preview();
        $this->actingAs($this->manager)->post('/organization/invitation-imports/'.$preview->id.'/commit', [])->assertSessionHasErrors('reviewed');
        $this->post('/organization/invitation-imports/'.$preview->id.'/commit', ['reviewed' => true, 'phones' => ['989199999999'], 'organization_id' => 999])->assertSessionHasNoErrors();
        $this->post('/organization/invitation-imports/'.$preview->id.'/commit', ['reviewed' => true])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('organization_invitations', 2);
        $this->assertDatabaseMissing('organization_invitations', ['phone' => '989199999999']);
        $this->assertSame(['created' => 2, 'skipped' => 0], $preview->fresh()->result);
        $this->assertSame(1, AuditEvent::where('action', 'organization_import.committed')->count());
        $this->assertSame(1, $this->organization->members()->count());
    }

    public function test_invalid_csv_rows_block_whole_commit_instead_of_partially_creating_invites(): void
    {
        $preview = $this->preview("phone\n09121234567\n=HYPERLINK(\"x\")\n1.234E+10\n");
        $this->assertSame(2, $preview->invalid_count);
        $this->actingAs($this->manager)->post('/organization/invitation-imports/'.$preview->id.'/commit', ['reviewed' => true])->assertSessionHasErrors('invitation');
        $this->assertDatabaseCount('organization_invitations', 0);
        $this->assertNull($preview->fresh()->committed_at);
    }

    public function test_preview_is_private_to_creator_selected_org_and_expires_after_fifteen_minutes(): void
    {
        $preview = $this->preview();
        $other = $this->user('org_admin');
        $this->organization->members()->attach($other->id, ['kind' => 'manager']);
        $this->actingAs($other)->get('/organization/invitations?preview='.$preview->id)->assertNotFound();
        $this->post('/organization/invitation-imports/'.$preview->id.'/commit', ['reviewed' => true])->assertForbidden();
        $otherOrg = Organization::factory()->create(['name' => 'سازمان دیگر', 'type' => 'company', 'status' => 'active']);
        $otherOrg->members()->attach($this->manager->id, ['kind' => 'manager']);
        $this->actingAs($this->manager)->withSession(['organization_id' => $otherOrg->id])->post('/organization/invitation-imports/'.$preview->id.'/commit', ['reviewed' => true])->assertForbidden();
        $this->travelTo($preview->expires_at);
        $this->withSession(['organization_id' => $this->organization->id])->post('/organization/invitation-imports/'.$preview->id.'/commit', ['reviewed' => true])->assertSessionHasErrors('invitation');
        $this->assertDatabaseCount('organization_invitations', 0);
    }

    public function test_bulk_commit_skips_existing_members_and_all_existing_invites_without_renewing_declines(): void
    {
        $declined = $this->invite();
        $this->respond($declined, 'decline', ['consent' => false])->assertSessionHasNoErrors();
        $preview = $this->preview("phone\n{$this->student->phone}\n{$this->manager->phone}\n09121234567\n");
        $result = app(OrganizationMembership::class)->commit($this->manager, $preview);
        $this->assertSame(['created' => 1, 'skipped' => 2], $result);
        $this->assertSame('declined', $declined->fresh()->status);
        $this->assertSame(2, $declined->fresh()->revision);
        $this->assertDatabaseCount('organization_invitations', 2);
        $this->assertSame(1, $this->student->notifications()->count());
    }

    public function test_bad_headers_extra_columns_encoding_and_oversized_or_empty_csv_are_rejected(): void
    {
        $this->actingAs($this->manager);
        foreach (['', "name,phone\nTest,09121234567", "phone,role\n09121234567,admin", "phone\n", "phone\n\0", "\xFF\xFEp\x00h\x00"] as $content) {
            $this->post('/organization/invitation-imports', ['file' => UploadedFile::fake()->createWithContent('members.csv', $content)])->assertSessionHasErrors('invitation');
        }
        $this->post('/organization/invitation-imports', ['file' => UploadedFile::fake()->create('large.csv', 1025)])->assertSessionHasErrors('file');
        $this->post('/organization/invitation-imports', ['file' => UploadedFile::fake()->createWithContent('members.xlsx', "phone\n09121234567")])->assertSessionHasErrors('file');
        $this->assertDatabaseCount('organization_invitation_imports', 0);
    }

    public function test_csv_row_limit_and_extra_row_columns_are_enforced(): void
    {
        $this->actingAs($this->manager)->post('/organization/invitation-imports', ['file' => UploadedFile::fake()->createWithContent('members.csv', "phone\n".str_repeat("09121234567\n", 101))])->assertSessionHasErrors('invitation');
        $preview = $this->preview("phone\n09121234567,admin\n");
        $this->assertSame(1, $preview->invalid_count);
        $this->assertSame([], $preview->phones);
    }

    public function test_template_is_private_and_contains_only_header_without_inviting_a_sample_person(): void
    {
        $response = $this->actingAs($this->manager)->get('/organization/invitations/template')->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8');
        $this->assertSame("\xEF\xBB\xBFphone\r\n", $response->getContent());
        $this->actingAs($this->student)->get('/organization/invitations/template')->assertForbidden();
        $this->assertDatabaseCount('organization_invitations', 0);
    }
}
