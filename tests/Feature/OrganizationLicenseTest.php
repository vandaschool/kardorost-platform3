<?php

namespace Tests\Feature;

use App\Models\Cohort;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Organization;
use App\Models\OrganizationLicense;
use App\Models\OrganizationSeat;
use App\Models\Payment;
use App\Models\Role;
use App\Models\User;
use App\Services\BillingService;
use App\Services\CohortCapacity;
use App\Services\EnrollmentService;
use App\Services\OrganizationEnrollment;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OrganizationLicenseTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function user(string $role = 'student'): User
    {
        $u = User::factory()->create();
        $u->roles()->attach(Role::where('key', $role)->value('id'));

        return $u;
    }

    private function org(): Organization
    {
        return Organization::factory()->create(['name' => 'سازمان آزمایشی', 'type' => 'company', 'status' => 'active']);
    }

    private function cohort(int $capacity = 5, int $price = 1000000): Cohort
    {
        $c = Course::factory()->create(['price' => $price]);

        return Cohort::factory()->create(['course_id' => $c->id, 'capacity' => $capacity]);
    }

    private function license(?Organization $org = null, ?Cohort $cohort = null, int $seats = 2): OrganizationLicense
    {
        return app(OrganizationEnrollment::class)->configure($this->user('admin'), $org ?? $this->org(), $cohort ?? $this->cohort(), ['seat_limit' => $seats, 'expires_on' => now()->addMonth()->toDateString(), 'active' => true, 'contract_reference' => 'CONTRACT-TEST']);
    }

    private function manager(Organization $org): User
    {
        $m = $this->user('org_admin');
        $org->members()->attach($m->id, ['kind' => 'manager']);

        return $m;
    }

    private function member(Organization $org): User
    {
        $m = $this->user();
        $org->members()->attach($m->id, ['kind' => 'member']);

        return $m;
    }

    private function register(User $m, OrganizationLicense $l, array $ids): void
    {
        $this->actingAs($m)->withSession(['organization_id' => $l->organization_id])->post('/organization/licenses/'.$l->id.'/enroll', ['revision' => $l->revision, 'member_ids' => $ids, 'confirmed' => true])->assertSessionHasNoErrors();
    }

    private function config(OrganizationLicense $l, array $override = []): array
    {
        return [...$l->only('seat_limit', 'active', 'contract_reference', 'revision'), 'expires_on' => $l->expires_on->toDateString(), 'authorized' => true, ...$override];
    }

    public function test_admin_allocation_reserves_capacity_and_requires_authorization(): void
    {
        $org = $this->org();
        $c = $this->cohort(3);
        $d = ['organization_id' => $org->id, 'cohort_id' => $c->id, 'seat_limit' => 2, 'expires_on' => now()->addMonth()->toDateString(), 'active' => true, 'contract_reference' => 'CONTRACT'];
        $this->actingAs($this->user('admin'))->post('/admin/organization-licenses', $d)->assertSessionHasErrors('authorized');
        $this->post('/admin/organization-licenses', [...$d, 'authorized' => true])->assertSessionHasNoErrors();
        $this->assertSame(1, app(CohortCapacity::class)->available($c));
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_managers_cannot_create_or_expand_licenses(): void
    {
        $l = $this->license();
        foreach (['student', 'coach', 'org_admin'] as $role) {
            $this->actingAs($this->user($role))->post('/admin/organization-licenses', [])->assertForbidden();
            $this->patch('/admin/organization-licenses/'.$l->id, [])->assertForbidden();
        }
    }

    public function test_grant_cannot_consume_existing_reservations_or_other_org_quota(): void
    {
        $c = $this->cohort(2);
        $this->license(null, $c, 1);
        app(EnrollmentService::class)->reserve($this->user(), $c);
        $org = $this->org();
        $this->actingAs($this->user('admin'))->post('/admin/organization-licenses', ['organization_id' => $org->id, 'cohort_id' => $c->id, 'seat_limit' => 1, 'expires_on' => now()->addMonth()->toDateString(), 'active' => true, 'contract_reference' => 'TEST', 'authorized' => true])->assertSessionHasErrors('license');
        $this->assertDatabaseCount('organization_licenses', 1);
    }

    public function test_group_enrollment_activates_learning_without_personal_invoices(): void
    {
        $l = $this->license();
        $m = $this->manager($l->organization);
        $a = $this->member($l->organization);
        $b = $this->member($l->organization);
        $this->register($m, $l, [$a->id, $b->id]);
        $this->assertSame(2, OrganizationSeat::count());
        $this->assertSame(2, Enrollment::where('status', 'active')->where('payment_plan', 'organization')->where('amount', 0)->count());
        $this->assertDatabaseCount('installments', 0);
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame(2, DB::table('notifications')->count());
        $this->assertSame(3, app(CohortCapacity::class)->available($l->cohort));
        $this->actingAs($a)->get('/learning')->assertInertia(fn (Assert $p) => $p->where('enrollments.0.organization_license.organization.name', $l->organization->name)->missing('enrollments.0.organization_license.contract_reference'));
        $this->get('/learning/'.Enrollment::where('user_id', $a->id)->value('id'))->assertOk();
    }

    public function test_bulk_replay_skips_existing_enrollments_without_reusing_quota_or_notifications(): void
    {
        $l = $this->license();
        $m = $this->manager($l->organization);
        $a = $this->member($l->organization);
        $this->register($m, $l, [$a->id]);
        $this->register($m, $l, [$a->id]);
        $this->assertDatabaseCount('organization_seats', 1);
        $this->assertDatabaseCount('enrollments', 1);
        $this->assertSame(1, DB::table('notifications')->count());
    }

    public function test_group_is_atomic_if_remaining_quota_is_insufficient(): void
    {
        $l = $this->license(null, null, 1);
        $m = $this->manager($l->organization);
        $a = $this->member($l->organization);
        $b = $this->member($l->organization);
        $this->actingAs($m)->post('/organization/licenses/'.$l->id.'/enroll', ['revision' => 1, 'member_ids' => [$a->id, $b->id], 'confirmed' => true])->assertSessionHasErrors('license');
        $this->assertDatabaseCount('organization_seats', 0);
        $this->assertDatabaseCount('enrollments', 0);
        $this->assertSame(0, DB::table('notifications')->count());
    }

    public function test_one_foreign_member_rejects_entire_group_without_leaking_data(): void
    {
        $l = $this->license();
        $m = $this->manager($l->organization);
        $a = $this->member($l->organization);
        $foreign = $this->user();
        $this->actingAs($m)->post('/organization/licenses/'.$l->id.'/enroll', ['revision' => 1, 'member_ids' => [$a->id, $foreign->id], 'confirmed' => true])->assertForbidden();
        $this->assertDatabaseCount('enrollments', 0);
    }

    public function test_inactive_unverified_or_non_student_member_cannot_use_a_seat(): void
    {
        $l = $this->license();
        $m = $this->manager($l->organization);
        $a = $this->member($l->organization);
        $a->forceFill(['is_active' => false])->save();
        $d = ['revision' => 1, 'member_ids' => [$a->id], 'confirmed' => true];
        $this->actingAs($m)->post('/organization/licenses/'.$l->id.'/enroll', $d)->assertSessionHasErrors('license');
        $a->forceFill(['is_active' => true, 'phone_verified_at' => null])->save();
        $this->post('/organization/licenses/'.$l->id.'/enroll', $d)->assertSessionHasErrors('license');
        $a->forceFill(['phone_verified_at' => now()])->save();
        $a->roles()->sync(Role::where('key', 'coach')->pluck('id'));
        $this->post('/organization/licenses/'.$l->id.'/enroll', $d)->assertSessionHasErrors('license');
        $this->assertDatabaseCount('organization_seats', 0);
    }

    public function test_selected_organization_and_manager_membership_are_enforced(): void
    {
        $l = $this->license();
        $other = $this->org();
        $m = $this->manager($l->organization);
        $other->members()->attach($m->id, ['kind' => 'manager']);
        $this->actingAs($m)->withSession(['organization_id' => $other->id])->get('/organization/licenses/'.$l->id)->assertForbidden();
        $this->get('/organization/licenses/'.$l->id.'/export')->assertForbidden();
        $this->withSession(['organization_id' => $l->organization_id])->get('/organization/licenses/'.$l->id)->assertOk();
        $l->organization->members()->detach($m->id);
        $this->get('/organization/licenses/'.$l->id)->assertForbidden();
    }

    public function test_expiry_suspension_and_closed_registration_block_new_use_without_cutting_learning(): void
    {
        $l = $this->license();
        $m = $this->manager($l->organization);
        $a = $this->member($l->organization);
        $b = $this->member($l->organization);
        $this->register($m, $l, [$a->id]);
        $l->update(['active' => false]);
        $this->actingAs($m)->post('/organization/licenses/'.$l->id.'/enroll', ['revision' => 1, 'member_ids' => [$b->id], 'confirmed' => true])->assertSessionHasErrors('license');
        $this->assertSame(4, app(CohortCapacity::class)->available($l->cohort));
        $l->update(['active' => true, 'expires_on' => now()->subDay()->toDateString()]);
        $this->post('/organization/licenses/'.$l->id.'/enroll', ['revision' => 1, 'member_ids' => [$b->id], 'confirmed' => true])->assertSessionHasErrors('license');
        $this->actingAs($a)->get('/learning/'.Enrollment::where('user_id', $a->id)->value('id'))->assertOk();
        $l->update(['expires_on' => now()->addMonth()->toDateString()]);
        $l->cohort->update(['registration_open' => false]);
        $this->actingAs($m)->post('/organization/licenses/'.$l->id.'/enroll', ['revision' => 1, 'member_ids' => [$b->id], 'confirmed' => true])->assertSessionHasErrors('license');
    }

    public function test_stale_admin_form_cannot_replace_newer_contract_or_lower_below_consumed_seats(): void
    {
        $l = $this->license();
        $m = $this->manager($l->organization);
        $a = $this->member($l->organization);
        $this->register($m, $l, [$a->id]);
        $this->actingAs($this->user('admin'))->patch('/admin/organization-licenses/'.$l->id, $this->config($l, ['seat_limit' => 3]))->assertSessionHasNoErrors();
        $this->patch('/admin/organization-licenses/'.$l->id, $this->config($l, ['seat_limit' => 4]))->assertSessionHasErrors('license');
        $this->assertSame(3, $l->fresh()->seat_limit);
        $b = $this->member($l->organization);
        $this->register($m, $l->fresh(), [$b->id]);
        $this->actingAs($this->user('admin'))->patch('/admin/organization-licenses/'.$l->id, $this->config($l->fresh(), ['seat_limit' => 1]))->assertSessionHasErrors('license');
    }

    public function test_personal_registration_cannot_take_reserved_organization_seats(): void
    {
        $c = $this->cohort(1, 0);
        $l = $this->license(null, $c, 1);
        $this->actingAs($this->user())->post('/cohorts/'.$c->id.'/enroll')->assertSessionHasErrors('cohort');
        $this->assertDatabaseCount('enrollments', 0);
        $this->get('/courses')->assertInertia(fn (Assert $p) => $p->where('courses.0.cohorts.0.available_seats', 0));
        $m = $this->manager($l->organization);
        $a = $this->member($l->organization);
        $this->register($m, $l, [$a->id]);
        $this->assertSame(0, app(CohortCapacity::class)->available($c));
    }

    public function test_late_personal_charge_does_not_take_a_seat_reserved_after_its_hold_expired(): void
    {
        $c = $this->cohort(1);
        $u = $this->user();
        $e = app(EnrollmentService::class)->reserve($u, $c);
        $i = $e->installments()->first();
        $p = Payment::factory()->create(['user_id' => $u->id, 'enrollment_id' => $e->id, 'installment_id' => $i->id, 'amount' => $i->amount]);
        $this->travel(16)->minutes();
        $this->license(null, $c, 1);
        $this->assertFalse(app(BillingService::class)->capture($p, 'LATE-SPONSORED'));
        $this->assertSame('capacity_unavailable', $p->fresh()->review_reason);
        $this->assertNotNull($p->fresh()->captured_at);
        $this->assertSame('pending_payment', $e->fresh()->status);
    }

    public function test_active_personal_enrollment_is_not_rebilled_or_converted_to_sponsorship(): void
    {
        $c = $this->cohort(3, 0);
        $l = $this->license(null, $c, 1);
        $a = $this->member($l->organization);
        $e = app(EnrollmentService::class)->reserve($a, $c);
        $this->register($this->manager($l->organization), $l, [$a->id]);
        $this->assertNull($e->fresh()->organization_license_id);
        $this->assertDatabaseCount('organization_seats', 0);
    }

    public function test_live_personal_hold_blocks_group_and_preserves_every_members_existing_state(): void
    {
        $l = $this->license();
        $m = $this->manager($l->organization);
        $a = $this->member($l->organization);
        $b = $this->member($l->organization);
        app(EnrollmentService::class)->reserve($b, $l->cohort);
        $this->actingAs($m)->post('/organization/licenses/'.$l->id.'/enroll', ['revision' => 1, 'member_ids' => [$a->id, $b->id], 'confirmed' => true])->assertSessionHasErrors('license');
        $this->assertDatabaseCount('organization_seats', 0);
        $this->assertDatabaseCount('enrollments', 1);
    }

    public function test_expired_unpaid_quote_can_be_replaced_without_leaving_payable_invoices(): void
    {
        $l = $this->license();
        $m = $this->manager($l->organization);
        $a = $this->member($l->organization);
        $e = app(EnrollmentService::class)->reserve($a, $l->cohort);
        $i = $e->installments()->first();
        $this->travel(16)->minutes();
        $this->register($m, $l, [$a->id]);
        $this->assertSame('active', $e->fresh()->status);
        $this->assertSame(2, $e->fresh()->billing_version);
        $this->assertNotNull($i->fresh()->voided_at);
        $this->assertSame(0, $e->fresh()->amount);
    }

    public function test_unresolved_captured_funds_block_conversion_to_organization_coverage(): void
    {
        $l = $this->license();
        $m = $this->manager($l->organization);
        $a = $this->member($l->organization);
        $e = app(EnrollmentService::class)->reserve($a, $l->cohort);
        $i = $e->installments()->first();
        Payment::factory()->create(['user_id' => $a->id, 'enrollment_id' => $e->id, 'installment_id' => $i->id, 'status' => 'review_required', 'captured_at' => now(), 'reference' => 'UNRESOLVED']);
        $this->travel(16)->minutes();
        $this->actingAs($m)->post('/organization/licenses/'.$l->id.'/enroll', ['revision' => 1, 'member_ids' => [$a->id], 'confirmed' => true])->assertSessionHasErrors('license');
        $this->assertNull($e->fresh()->organization_license_id);
    }

    public function test_sponsored_participant_in_two_orgs_does_not_consume_a_second_seat(): void
    {
        $c = $this->cohort();
        $one = $this->license(null, $c, 1);
        $two = $this->license(null, $c, 1);
        $a = $this->member($one->organization);
        $two->organization->members()->attach($a->id);
        $this->register($this->manager($one->organization), $one, [$a->id]);
        $this->register($this->manager($two->organization), $two, [$a->id]);
        $this->assertSame(1, OrganizationSeat::count());
        $this->assertSame(0, $two->seats()->count());
    }

    public function test_export_is_scoped_private_and_neutralizes_spreadsheet_formulas(): void
    {
        $l = $this->license();
        $m = $this->manager($l->organization);
        $a = $this->member($l->organization);
        $a->update(['name' => '=HYPERLINK("https://example.test")']);
        $this->register($m, $l, [$a->id]);
        $response = $this->actingAs($m)->get('/organization/licenses/'.$l->id.'/export')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        $this->assertStringContainsString("'=HYPERLINK", $response->streamedContent());
        $this->assertStringContainsString($a->phone, $response->streamedContent());
        $this->actingAs($this->user('org_admin'))->get('/organization/licenses/'.$l->id.'/export')->assertForbidden();
    }

    public function test_ledger_uses_registration_snapshot_and_cannot_leak_new_contact_details(): void
    {
        $l = $this->license();
        $m = $this->manager($l->organization);
        $a = $this->member($l->organization);
        $old = $a->name;
        $this->register($m, $l, [$a->id]);
        $a->update(['name' => 'نام جدید خارج سازمان']);
        $l->organization->members()->detach($a->id);
        $this->actingAs($m)->get('/organization/licenses/'.$l->id)->assertInertia(fn (Assert $p) => $p->where('seats.data.0.member_name', $old)->has('members.data', 1));
    }

    public function test_duplicate_licenses_members_and_stale_batch_forms_are_rejected(): void
    {
        $l = $this->license();
        $m = $this->manager($l->organization);
        $a = $this->member($l->organization);
        $this->actingAs($m)->post('/organization/licenses/'.$l->id.'/enroll', ['revision' => 1, 'member_ids' => [$a->id, $a->id], 'confirmed' => true])->assertSessionHasErrors();
        $l->update(['revision' => 2]);
        $this->post('/organization/licenses/'.$l->id.'/enroll', ['revision' => 1, 'member_ids' => [$a->id], 'confirmed' => true])->assertSessionHasErrors('license');
        $this->actingAs($this->user('admin'))->post('/admin/organization-licenses', [...$this->config($l), 'organization_id' => $l->organization_id, 'cohort_id' => $l->cohort_id])->assertSessionHasErrors('license');
    }

    public function test_expired_gateway_attempt_can_be_replaced_but_late_verified_money_stays_in_review(): void
    {
        $l = $this->license();
        $m = $this->manager($l->organization);
        $a = $this->member($l->organization);
        $e = app(EnrollmentService::class)->reserve($a, $l->cohort);
        $i = $e->installments()->first();
        $p = app(BillingService::class)->attempt($a, $i);
        $p->update(['track_id' => 'EXPIRED-PERSONAL']);
        $this->travel(16)->minutes();
        $this->register($m, $l, [$a->id]);
        $this->assertSame('expired', $p->fresh()->status);
        $this->assertFalse(app(BillingService::class)->capture($p, 'LATE-AFTER-SPONSOR'));
        $this->assertSame('obsolete_quote', $p->fresh()->review_reason);
        $this->assertSame($l->id, $e->fresh()->organization_license_id);
        $this->assertSame('active', $e->fresh()->status);
        $this->assertNotNull($p->fresh()->captured_at);
    }
}
