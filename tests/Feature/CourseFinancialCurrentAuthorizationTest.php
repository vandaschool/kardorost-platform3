<?php

namespace Tests\Feature;

use App\Models\Cohort;
use App\Models\Course;
use App\Models\EnrollmentConsultationBenefit;
use App\Models\Payment;
use App\Models\Role;
use App\Models\User;
use App\Services\BillingService;
use App\Services\CourseConsultationBenefits;
use App\Services\EnrollmentService;
use App\Services\ReceiptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class CourseFinancialCurrentAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Storage::fake('receipts');
        config(['platform.finance.transfer_destination' => '6037991234567890']);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('key', $role)->value('id'));

        return $user;
    }

    private function definition(User $coach, int $revision = 0): array
    {
        return ['revision' => $revision, 'enabled' => true, 'coach_id' => $coach->id, 'title' => 'Software-only frozen benefit', 'sessions' => 2, 'session_minutes' => 60, 'valid_days' => 30, 'activation' => 'first_payment'];
    }

    private function proof(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('software-only68.pdf', "%PDF-1.4\n% SOFTWARE-ONLY-NO-REAL-FUNDS68\n%%EOF\n");
    }

    private function reviewData(string $action = 'approve'): array
    {
        return ['action' => $action, 'expected_review_version' => 0, 'verified_amount' => 100000, 'statement_verified' => true, 'review_note' => 'Software-only independent review'];
    }

    private function fixture(string $action): array
    {
        $admin = $this->user('admin');
        $student = $this->user('student');
        $coach = $this->user('coach');
        $cohort = Cohort::factory()->create(['course_id' => Course::factory()->state(['price' => 1000000, 'is_published' => true]), 'capacity' => 10, 'manual_payment_enabled' => true]);
        $benefits = app(CourseConsultationBenefits::class);
        if ($action !== 'configure_new') {
            $benefits->configure($admin, $cohort, $this->definition($coach));
        }
        $enrollment = app(EnrollmentService::class)->reserve($student, $cohort);
        $invoice = app(BillingService::class)->current($enrollment)->sole();
        $payment = null;
        $receipt = null;
        if (in_array($action, ['reuse', 'resolve_paid', 'retry_waiting', 'retry_issued', 'capture'], true)) {
            $payment = app(BillingService::class)->attempt($student, $invoice);
        }
        if (in_array($action, ['approve', 'reject', 'review_paid'], true)) {
            $receipt = app(ReceiptService::class)->submit($student, $invoice, $this->proof(), 'SOFTWAREONLY68', now()->toDateString());
            $payment = $receipt->payment;
        }
        if ($action === 'review_paid') {
            app(ReceiptService::class)->review($admin, $receipt, $this->reviewData());
        }
        if ($action === 'resolve') {
            $payment = Payment::factory()->create(['user_id' => $student->id, 'enrollment_id' => $enrollment->id, 'installment_id' => $invoice->id, 'amount' => 1000000, 'status' => 'review_required', 'reference' => 'SOFTWARE-ONLY-CAPTURED68', 'captured_at' => now(), 'paid_at' => now()]);
        }
        if (in_array($action, ['resolve_paid', 'retry_waiting', 'retry_issued'], true)) {
            if ($action === 'retry_waiting') {
                User::whereKey($coach->id)->update(['is_active' => false]);
            }
            app(BillingService::class)->capture($payment, 'SOFTWARE-ONLY-VERIFIED68');
            User::whereKey($coach->id)->update(['is_active' => true]);
        }

        return [$admin, $student, $coach, $cohort, $enrollment, $invoice, $payment, $receipt];
    }

    private function snapshot(): string
    {
        $state = [];
        foreach (['cohort_consultation_benefits', 'enrollment_consultation_benefits', 'consultation_grants', 'enrollments', 'installments', 'payments', 'payment_receipts', 'receipt_claims', 'payment_allocations', 'audit_events', 'notifications'] as $table) {
            $state[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        foreach (Storage::disk('receipts')->allFiles() as $file) {
            $state['files'][$file] = hash_file('sha256', Storage::disk('receipts')->path($file));
        }

        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
    }

    public static function disabledCases(): array
    {
        return array_map(fn ($action) => [$action], ['attempt', 'reuse', 'receipt', 'approve', 'reject', 'resolve', 'resolve_paid', 'review_paid', 'configure_new', 'configure_edit', 'configure_disable', 'retry_waiting', 'retry_issued']);
    }

    #[DataProvider('disabledCases')]
    public function test_disabled_actor_cannot_change_course_finance_or_benefits_using_stale_activity(string $action): void
    {
        [$admin, $student, $coach, $cohort, $enrollment, $invoice, $payment, $receipt] = $this->fixture($action);
        $actor = in_array($action, ['attempt', 'reuse', 'receipt'], true) ? $student : $admin;
        User::whereKey($actor->id)->update(['is_active' => false]);
        $this->assertTrue($actor->is_active);
        $before = $this->snapshot();
        try {
            match ($action) {
                'attempt', 'reuse' => app(BillingService::class)->attempt($actor, $invoice),
                'receipt' => app(ReceiptService::class)->submit($actor, $invoice, $this->proof(), 'SOFTWAREONLY68', now()->toDateString()),
                'approve', 'reject', 'review_paid' => app(ReceiptService::class)->review($actor, $receipt, $this->reviewData($action === 'reject' ? 'reject' : 'approve')),
                'resolve', 'resolve_paid' => app(BillingService::class)->resolve($payment, $actor),
                'configure_new' => app(CourseConsultationBenefits::class)->configure($actor, $cohort, $this->definition($coach)),
                'configure_edit' => app(CourseConsultationBenefits::class)->configure($actor, $cohort, [...$this->definition($coach, 1), 'sessions' => 3]),
                'configure_disable' => app(CourseConsultationBenefits::class)->configure($actor, $cohort, ['revision' => 1, 'enabled' => false]),
                'retry_waiting', 'retry_issued' => app(CourseConsultationBenefits::class)->retry($actor, EnrollmentConsultationBenefit::where('enrollment_id', $enrollment->id)->sole()),
            };
            $this->fail('A disabled actor cannot change course finance or benefits.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame($before, $this->snapshot());
        Http::assertNothingSent();
    }

    public function test_active_owner_can_start_and_reuse_owed_course_payment_after_student_role_loss(): void
    {
        [$admin, $student, $coach, $cohort, $enrollment, $invoice] = $this->fixture('attempt');
        $student->roles()->sync([Role::where('key', 'coach')->value('id')]);
        $payment = app(BillingService::class)->attempt($student, $invoice);
        $this->assertSame($payment->id, app(BillingService::class)->attempt($student, $invoice)->id);
        $this->assertSame(1000000, $payment->amount);
        Http::assertNothingSent();
    }

    public function test_active_owner_can_submit_owed_course_receipt_after_student_role_loss(): void
    {
        [$admin, $student, $coach, $cohort, $enrollment, $invoice] = $this->fixture('receipt');
        $student->roles()->sync([Role::where('key', 'coach')->value('id')]);
        $receipt = app(ReceiptService::class)->submit($student, $invoice, $this->proof(), 'SOFTWAREONLY68', now()->toDateString());
        $this->assertTrue(app(ReceiptService::class)->review($admin, $receipt, $this->reviewData()));
        $this->assertSame('paid', $receipt->payment->fresh()->status);
        $this->assertSame('student_unavailable', EnrollmentConsultationBenefit::where('enrollment_id', $enrollment->id)->sole()->issue_reason);
        Http::assertNothingSent();
    }

    public function test_verified_received_funds_remain_recordable_after_payer_becomes_inactive(): void
    {
        [$admin, $student, $coach, $cohort, $enrollment, $invoice, $payment] = $this->fixture('capture');
        User::whereKey($student->id)->update(['is_active' => false]);
        $this->assertTrue(app(BillingService::class)->capture($payment, 'SOFTWARE-ONLY-VERIFIED68'));
        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame('student_unavailable', EnrollmentConsultationBenefit::where('enrollment_id', $enrollment->id)->sole()->issue_reason);
        $this->assertDatabaseCount('consultation_grants', 0);
        Http::assertNothingSent();
    }

    public function test_current_admin_can_retry_blocked_frozen_benefit_after_coach_recovers(): void
    {
        [$admin, $student, $coach, $cohort, $enrollment] = $this->fixture('retry_waiting');
        $contract = EnrollmentConsultationBenefit::where('enrollment_id', $enrollment->id)->sole();
        $this->assertSame('blocked', $contract->status);
        $this->assertSame('issued', app(CourseConsultationBenefits::class)->retry($admin, $contract)->status);
        $this->assertDatabaseCount('consultation_grants', 1);
        Http::assertNothingSent();
    }
}
