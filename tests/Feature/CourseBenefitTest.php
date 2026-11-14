<?php

namespace Tests\Feature;

use App\Models\Cohort;
use App\Models\CohortConsultationBenefit;
use App\Models\ConsultationGrant;
use App\Models\ConsultationSlot;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\EnrollmentConsultationBenefit;
use App\Models\Organization;
use App\Models\Payment;
use App\Models\Role;
use App\Models\User;
use App\Services\BillingService;
use App\Services\ConsultationService;
use App\Services\CourseConsultationBenefits;
use App\Services\EnrollmentService;
use App\Services\OrganizationEnrollment;
use App\Services\ReceiptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CourseBenefitTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $student;

    private User $coach;

    private Cohort $cohort;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        $this->travelTo(now()->startOfMinute());
        $this->admin = $this->user('admin');
        $this->student = $this->user('student');
        $this->coach = $this->user('coach');
        $this->cohort = Cohort::factory()->create(['course_id' => Course::factory()->create(['price' => 1000000])->id, 'manual_payment_enabled' => true, 'installment_enabled' => true]);
        $this->cohort->installmentPlan()->createMany([['sequence' => 1, 'amount' => 400000, 'due_date' => now()->toDateString()], ['sequence' => 2, 'amount' => 600000, 'due_date' => now()->addMonth()->toDateString()]]);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('key', $role)->value('id'));

        return $user;
    }

    private function settings(array $changes = []): array
    {
        return [...['revision' => CohortConsultationBenefit::where('cohort_id', $this->cohort->id)->value('revision') ?? 0, 'enabled' => true, 'coach_id' => $this->coach->id, 'title' => 'مشاوره همراه خرید', 'sessions' => 2, 'session_minutes' => 60, 'valid_days' => 90, 'activation' => 'paid_in_full', 'reviewed' => true], ...$changes];
    }

    private function configure(array $changes = []): void
    {
        $this->actingAs($this->admin)->patch('/admin/cohorts/'.$this->cohort->id.'/consultation-benefit', $this->settings($changes))->assertSessionHasNoErrors();
    }

    private function reserve(string $plan = 'full'): Enrollment
    {
        return app(EnrollmentService::class)->reserve($this->student, $this->cohort, $plan);
    }

    private function payment(Enrollment $enrollment, int $sequence = 1): Payment
    {
        return Payment::factory()->create(['installment_id' => $enrollment->installments()->where('billing_version', $enrollment->billing_version)->where('sequence', $sequence)->value('id')]);
    }

    private function pay(Enrollment $enrollment, int $sequence = 1): Payment
    {
        $payment = $this->payment($enrollment, $sequence);
        $this->assertTrue(app(BillingService::class)->capture($payment, 'BANK-'.$payment->id));

        return $payment;
    }

    public function test_configuration_requires_review_active_coach_positive_price_and_current_revision(): void
    {
        $url = '/admin/cohorts/'.$this->cohort->id.'/consultation-benefit';
        foreach ([['reviewed' => false], ['sessions' => 0], ['activation' => 'free'], ['valid_days' => 0]] as $changes) {
            $this->actingAs($this->admin)->patch($url, $this->settings($changes))->assertSessionHasErrors();
        }
        $this->patch($url, $this->settings(['coach_id' => $this->student->id]))->assertSessionHasErrors('benefit');
        $this->cohort->course->update(['price' => 0]);
        $this->patch($url, $this->settings())->assertSessionHasErrors('benefit');
        $this->cohort->course->update(['price' => 1000000]);
        $this->configure();
        $this->patch($url, $this->settings(['revision' => 0]))->assertSessionHasErrors('benefit');
        $this->assertSame(1, CohortConsultationBenefit::first()->revision);
    }

    public function test_only_admin_can_configure_read_cases_or_retry(): void
    {
        $this->configure();
        $this->reserve();
        $contract = EnrollmentConsultationBenefit::firstOrFail();
        foreach (['student', 'coach', 'org_admin'] as $role) {
            $this->actingAs($this->user($role))->patch('/admin/cohorts/'.$this->cohort->id.'/consultation-benefit', $this->settings())->assertForbidden();
            $this->get('/admin/course-benefits')->assertForbidden();
            $this->post('/admin/course-benefits/'.$contract->id.'/issue')->assertForbidden();
        }
    }

    public function test_quote_is_frozen_and_pending_retries_cannot_create_credit(): void
    {
        $this->configure();
        $enrollment = $this->reserve();
        $definition = EnrollmentConsultationBenefit::first()->definition;
        $this->configure(['sessions' => 9, 'coach_id' => $this->user('coach')->id]);
        $this->reserve();
        $contract = EnrollmentConsultationBenefit::first();
        $this->assertSame($definition, $contract->definition);
        $this->assertDatabaseCount('enrollment_consultation_benefits', 1);
        $this->actingAs($this->admin)->post('/admin/course-benefits/'.$contract->id.'/issue')->assertSessionHasNoErrors();
        $this->assertDatabaseCount('consultation_grants', 0);
        $this->assertSame('pending_payment', $enrollment->fresh()->status);
    }

    public function test_full_payment_issues_once_with_frozen_terms_and_never_extends_on_replay(): void
    {
        $this->configure();
        $enrollment = $this->reserve();
        $this->configure(['enabled' => false]);
        $payment = $this->pay($enrollment);
        $grant = ConsultationGrant::firstOrFail();
        $expiry = $grant->valid_until->toDateString();
        $this->assertSame(2, $grant->total_sessions);
        $this->assertSame($this->coach->id, $grant->coach_id);
        $this->assertSame(now()->addDays(90)->toDateString(), $expiry);
        $this->assertSame('issued', EnrollmentConsultationBenefit::first()->status);
        $this->travel(2)->days();
        $this->assertTrue(app(BillingService::class)->capture($payment, 'BANK-'.$payment->id));
        app(CourseConsultationBenefits::class)->retry($this->admin, EnrollmentConsultationBenefit::first());
        $this->assertDatabaseCount('consultation_grants', 1);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('payment_allocations', 1);
        $this->assertSame($expiry, $grant->fresh()->valid_until->toDateString());
        Http::assertNothingSent();
    }

    public function test_paid_in_full_activation_waits_for_all_current_installments(): void
    {
        $this->configure();
        $enrollment = $this->reserve('installments');
        $this->pay($enrollment);
        $this->assertSame('active', $enrollment->fresh()->status);
        $this->assertSame('waiting', EnrollmentConsultationBenefit::first()->status);
        $this->assertDatabaseCount('consultation_grants', 0);
        $this->pay($enrollment, 2);
        $this->assertDatabaseCount('consultation_grants', 1);
    }

    public function test_first_payment_activation_does_not_require_later_installments(): void
    {
        $this->configure(['activation' => 'first_payment']);
        $enrollment = $this->reserve('installments');
        $this->pay($enrollment);
        $this->assertDatabaseCount('consultation_grants', 1);
        $this->assertNull($enrollment->installments()->where('sequence', 2)->first()->paid_at);
        $this->pay($enrollment, 2);
        $this->assertDatabaseCount('consultation_grants', 1);
    }

    public function test_pending_manual_receipt_gives_no_credit_and_approved_receipt_uses_same_issuance_path(): void
    {
        config(['platform.finance.transfer_destination' => '6037991234567890']);
        Storage::fake('receipts');
        $this->configure();
        $enrollment = $this->reserve();
        $receipt = app(ReceiptService::class)->submit($this->student, $enrollment->installments()->first(), UploadedFile::fake()->createWithContent('receipt.pdf', "%PDF-1.4\nCourse benefit fixture"), '12345678', now()->toDateString());
        app(CourseConsultationBenefits::class)->retry($this->admin, EnrollmentConsultationBenefit::first());
        $this->assertDatabaseCount('consultation_grants', 0);
        $this->assertTrue(app(ReceiptService::class)->review($this->admin, $receipt, ['action' => 'approve', 'expected_review_version' => 0, 'verified_amount' => 100000, 'statement_verified' => true]));
        $this->assertDatabaseCount('consultation_grants', 1);
        $this->assertSame('paid', $receipt->payment->fresh()->status);
    }

    public function test_review_required_funds_give_no_credit_until_actual_allocation(): void
    {
        $this->configure();
        $enrollment = $this->reserve();
        $payment = $this->payment($enrollment);
        $this->cohort->update(['capacity' => 0]);
        $this->assertFalse(app(BillingService::class)->capture($payment, 'REVIEW-BANK'));
        $this->assertSame('review_required', $payment->fresh()->status);
        $this->assertDatabaseCount('consultation_grants', 0);
        $this->cohort->update(['capacity' => 10]);
        $this->assertTrue(app(BillingService::class)->resolve($payment, $this->admin));
        $this->assertDatabaseCount('consultation_grants', 1);
    }

    public function test_unavailable_coach_does_not_rollback_tuition_and_admin_can_retry_after_repair(): void
    {
        $this->configure();
        $enrollment = $this->reserve();
        $this->coach->forceFill(['is_active' => false])->save();
        $payment = $this->pay($enrollment);
        $contract = EnrollmentConsultationBenefit::firstOrFail();
        $this->assertSame('blocked', $contract->status);
        $this->assertSame('coach_unavailable', $contract->issue_reason);
        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame('active', $enrollment->fresh()->status);
        $this->assertDatabaseCount('consultation_grants', 0);
        $this->travel(3)->days();
        $this->coach->forceFill(['is_active' => true])->save();
        $this->actingAs($this->admin)->post('/admin/course-benefits/'.$contract->id.'/issue')->assertSessionHasNoErrors();
        $this->assertSame('issued', $contract->fresh()->status);
        $this->assertSame(now()->addDays(90)->toDateString(), ConsultationGrant::first()->valid_until->toDateString());
    }

    public function test_requote_supersedes_old_contract_and_old_invoice_cannot_issue_new_benefit(): void
    {
        $this->configure();
        $enrollment = $this->reserve();
        $oldPayment = $this->payment($enrollment);
        $old = EnrollmentConsultationBenefit::first();
        $this->travel(16)->minutes();
        $this->configure(['sessions' => 3]);
        $renewed = $this->reserve();
        $this->assertSame(2, $renewed->billing_version);
        $this->assertSame('superseded', $old->fresh()->status);
        $this->assertFalse(app(BillingService::class)->capture($oldPayment, 'OLD-QUOTE'));
        $this->assertDatabaseCount('consultation_grants', 0);
        $this->actingAs($this->admin)->post('/admin/course-benefits/'.$old->id.'/issue')->assertSessionHasErrors('benefit');
        $this->pay($renewed);
        $this->assertSame(3, ConsultationGrant::first()->total_sessions);
    }

    public function test_disabling_setting_before_requote_leaves_old_promise_superseded_without_new_one(): void
    {
        $this->configure();
        $this->reserve();
        $this->travel(16)->minutes();
        $this->configure(['enabled' => false]);
        $this->reserve();
        $this->assertDatabaseCount('enrollment_consultation_benefits', 1);
        $this->assertSame('superseded', EnrollmentConsultationBenefit::first()->status);
    }

    public function test_free_course_and_preexisting_purchase_do_not_get_retroactive_benefits(): void
    {
        $enrollment = $this->reserve();
        $this->configure();
        $this->pay($enrollment);
        $this->reserve();
        $this->assertDatabaseCount('enrollment_consultation_benefits', 0);
        $this->assertDatabaseCount('consultation_grants', 0);
        $this->cohort->course->update(['price' => 0]);
        $free = app(EnrollmentService::class)->reserve($this->user('student'), $this->cohort);
        $this->assertSame('active', $free->status);
        $this->assertDatabaseCount('enrollment_consultation_benefits', 0);
    }

    public function test_new_purchase_is_blocked_if_promised_coach_is_unavailable(): void
    {
        $this->configure();
        $this->coach->forceFill(['is_active' => false])->save();
        $this->actingAs($this->student)->post('/cohorts/'.$this->cohort->id.'/enroll')->assertSessionHasErrors('benefit');
        $this->assertDatabaseCount('enrollments', 0);
        $this->assertDatabaseCount('installments', 0);
    }

    public function test_paid_at_without_verified_allocation_cannot_issue_credit(): void
    {
        $this->configure();
        $enrollment = $this->reserve();
        $enrollment->update(['status' => 'active']);
        $enrollment->installments()->update(['paid_at' => now()]);
        app(CourseConsultationBenefits::class)->retry($this->admin, EnrollmentConsultationBenefit::first());
        $this->assertDatabaseCount('consultation_grants', 0);
    }

    public function test_manual_grant_cannot_claim_automatic_source_namespace_or_change_course_grant(): void
    {
        $this->configure();
        $this->pay($this->reserve());
        $grant = ConsultationGrant::first();
        $this->actingAs($this->admin)->patch('/admin/consultation-grants/'.$grant->id, ['revision' => 1, 'total_sessions' => 10, 'valid_until' => now()->addYear()->toDateString(), 'active' => false])->assertSessionHasErrors('package');
        $this->post('/admin/consultation-grants', ['student_phone' => $this->student->phone, 'coach_id' => $this->coach->id, 'title' => 'Manual', 'source_reference' => 'course-benefit-99999', 'total_sessions' => 1, 'session_minutes' => 60, 'valid_until' => now()->addMonth()->toDateString(), 'source_reviewed' => true])->assertSessionHasErrors('package');
        $this->assertSame(2, $grant->fresh()->total_sessions);
        $this->assertTrue($grant->fresh()->active);
        $this->assertDatabaseCount('consultation_grants', 1);
    }

    public function test_existing_conflicting_source_never_reuses_unrelated_grant(): void
    {
        $this->configure();
        $enrollment = $this->reserve();
        $contract = EnrollmentConsultationBenefit::first();
        ConsultationGrant::factory()->create(['student_id' => $this->student->id, 'coach_id' => $this->coach->id, 'source_reference' => 'COURSE-BENEFIT-'.$contract->id]);
        $this->pay($enrollment);
        $this->assertSame('source_conflict', $contract->fresh()->issue_reason);
        $this->assertNull($contract->fresh()->consultation_grant_id);
        $this->assertDatabaseCount('consultation_grants', 1);
    }

    public function test_public_pages_show_terms_origin_and_own_status_without_internal_source_or_admin_data(): void
    {
        $this->configure();
        $this->actingAs($this->student)->get('/courses')->assertInertia(fn (Assert $page) => $page->where('courses.0.cohorts.0.consultation_benefit.sessions', 2)->missing('courses.0.cohorts.0.consultation_benefit.reviewed_by'));
        $enrollment = $this->reserve();
        $this->get('/finance')->assertInertia(fn (Assert $page) => $page->where('enrollments.0.consultation_benefit.status', 'waiting')->missing('enrollments.0.consultation_benefit.reviewed_by')->missing('enrollments.0.consultation_benefit.definition.policy'));
        $this->pay($enrollment);
        $this->get('/consultation-grants')->assertInertia(fn (Assert $page) => $page->where('grants.data.0.source_kind', 'course')->where('grants.data.0.source_course', $this->cohort->course->title)->missing('grants.data.0.source_reference'));
        $this->actingAs($this->user('student'))->get('/finance')->assertInertia(fn (Assert $page) => $page->has('enrollments', 0));
        $this->actingAs($this->admin)->get('/admin/course-benefits')->assertInertia(fn (Assert $page) => $page->where('contracts.data.0.status', 'issued')->where('contracts.data.0.student.id', $this->student->id));
    }

    public function test_sponsored_conversion_supersedes_personal_promise_without_issuing_credit(): void
    {
        $this->configure();
        $enrollment = $this->reserve();
        $old = EnrollmentConsultationBenefit::first();
        $this->travel(16)->minutes();
        $org = Organization::factory()->create(['name' => 'سازمان آزمون مشاوره', 'type' => 'company', 'status' => 'active']);
        $manager = $this->user('org_admin');
        $other = $this->user('student');
        $org->members()->attach($manager->id, ['kind' => 'manager']);
        $org->members()->attach([$this->student->id => ['kind' => 'member'], $other->id => ['kind' => 'member']]);
        $service = app(OrganizationEnrollment::class);
        $license = $service->configure($this->admin, $org, $this->cohort, ['seat_limit' => 2, 'expires_on' => now()->addMonth()->toDateString(), 'active' => true, 'contract_reference' => 'ORG-BENEFIT-TEST']);
        $service->enroll($manager, $org, $license, $license->revision, [$this->student->id, $other->id]);
        $this->assertSame('organization', $enrollment->fresh()->payment_plan);
        $this->assertSame('superseded', $old->fresh()->status);
        $this->assertDatabaseCount('enrollment_consultation_benefits', 1);
        $this->assertDatabaseCount('consultation_grants', 0);
        $this->assertDatabaseCount('organization_seats', 2);
    }

    public function test_inactive_student_blocks_only_benefit_then_uses_frozen_coach_after_repair(): void
    {
        $this->configure();
        $enrollment = $this->reserve();
        $this->configure(['coach_id' => $this->user('coach')->id]);
        $this->student->forceFill(['is_active' => false])->save();
        $this->pay($enrollment);
        $contract = EnrollmentConsultationBenefit::first();
        $this->assertSame('student_unavailable', $contract->issue_reason);
        $this->student->forceFill(['is_active' => true])->save();
        app(CourseConsultationBenefits::class)->retry($this->admin, $contract);
        $grant = ConsultationGrant::firstOrFail();
        $this->assertSame($this->coach->id, $grant->coach_id);
        $slot = ConsultationSlot::factory()->create(['coach_id' => $this->coach->id, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour(), 'price' => 1000000]);
        $booking = app(ConsultationService::class)->book($this->student, $slot, 'جلسه همراه دوره', grantId: $grant->id);
        $this->assertSame('confirmed', $booking->status);
        $this->assertSame(0, $booking->amount);
        $this->assertSame(1, $grant->fresh()->remaining());
        $this->assertDatabaseCount('payments', 1);
    }

    public function test_contracts_prevent_destructive_migration_rollback(): void
    {
        $this->configure();
        $this->reserve();
        $migration = require database_path('migrations/2026_11_14_145344_create_course_consultation_benefits.php');
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }
}
