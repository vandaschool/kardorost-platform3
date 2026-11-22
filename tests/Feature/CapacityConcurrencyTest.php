<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\AnnouncementSchedule;
use App\Models\Assessment;
use App\Models\AssessmentAttempt;
use App\Models\AssessmentVersion;
use App\Models\Assignment;
use App\Models\AuditEvent;
use App\Models\CareerResume;
use App\Models\CertificateRequest;
use App\Models\CertificateVisibilityEvent;
use App\Models\CoachCredential;
use App\Models\CoachProfile;
use App\Models\CoachProfilePublication;
use App\Models\CoachProfileWithdrawal;
use App\Models\Cohort;
use App\Models\Consultation;
use App\Models\ConsultationGrant;
use App\Models\ConsultationGrantUse;
use App\Models\ConsultationProduct;
use App\Models\ConsultationRequest;
use App\Models\ConsultationSlot;
use App\Models\ContactLead;
use App\Models\ContactLeadEvent;
use App\Models\ContentPage;
use App\Models\ContentPublication;
use App\Models\ContentSchedule;
use App\Models\Course;
use App\Models\CourseCertificate;
use App\Models\CourseDiscussion;
use App\Models\CourseSection;
use App\Models\CourseSurvey;
use App\Models\DiscussionPost;
use App\Models\DiscussionPostRevision;
use App\Models\Enrollment;
use App\Models\EnrollmentConsultationBenefit;
use App\Models\EventReminder;
use App\Models\Installment;
use App\Models\InvitationSms;
use App\Models\LearningBadge;
use App\Models\LearningRewardEvent;
use App\Models\LearningRewardPolicy;
use App\Models\LearningRewardVersion;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationInvoice;
use App\Models\OrganizationLicense;
use App\Models\OrganizationPaymentAllocation;
use App\Models\OrganizationSeat;
use App\Models\PackagePurchase;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentReceipt;
use App\Models\ReceiptClaim;
use App\Models\Refund;
use App\Models\RefundEvent;
use App\Models\ResumeCheck;
use App\Models\ResumeShare;
use App\Models\ResumeVersion;
use App\Models\Role;
use App\Models\SmsDelivery;
use App\Models\SmsDeliveryAttempt;
use App\Models\Submission;
use App\Models\SupportTicket;
use App\Models\SurveyPublication;
use App\Models\SurveyResponse;
use App\Models\TicketEvent;
use App\Models\TicketMessage;
use App\Models\User;
use App\Notifications\CoachCredentialChanged;
use App\Notifications\PlatformAnnouncement;
use App\Services\Announcements;
use App\Services\AssessmentService;
use App\Services\BillingService;
use App\Services\Certification;
use App\Services\CoachCredentials;
use App\Services\CoachProfiles;
use App\Services\CohortCapacity;
use App\Services\ConsultationBilling;
use App\Services\ConsultationService;
use App\Services\ContentPublishing;
use App\Services\CourseConsultationBenefits;
use App\Services\CourseDiscussions;
use App\Services\EnrollmentService;
use App\Services\LearningRewards;
use App\Services\OrganizationBilling;
use App\Services\OrganizationEnrollment;
use App\Services\OrganizationMembership;
use App\Services\OrganizationPaymentSchedule;
use App\Services\PackageBilling;
use App\Services\ReceiptService;
use App\Services\RefundService;
use App\Services\ResumeBuilder;
use App\Services\ResumeSharing;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\Process\Process;
use Tests\TestCase;

class CapacityConcurrencyTest extends TestCase
{
    use DatabaseMigrations;

    public function test_two_simultaneous_requests_cannot_reserve_the_last_seat(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Real row-lock concurrency is verified on PostgreSQL.');
        }
        $a = User::factory()->create();
        $b = User::factory()->create();
        $course = Course::create(['title' => 'همزمان', 'slug' => 'concurrency', 'description' => 'ظرفیت', 'price' => 1000000, 'is_published' => true]);
        $cohort = Cohort::create(['course_id' => $course->id, 'title' => 'آخرین صندلی', 'format' => 'live', 'capacity' => 1]);
        $code = <<<'CODE'
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try{app(\App\Services\EnrollmentService::class)->reserve(\App\Models\User::findOrFail((int)$argv[1]),\App\Models\Cohort::findOrFail((int)$argv[2]));echo 'RESERVED';}
catch(\Illuminate\Validation\ValidationException){echo 'FULL';}
CODE;
        $processes = [];
        foreach ([$a, $b] as $user) {
            $processes[] = new Process([PHP_BINARY, '-r', $code, (string) $user->id, (string) $cohort->id], base_path(), null, null, 20);
        }
        foreach ($processes as $process) {
            $process->start();
        }
        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $results[] = $process->getOutput();
        }
        sort($results);
        $this->assertSame(['FULL', 'RESERVED'], $results);
        $this->assertSame(1, Enrollment::count());
    }

    public function test_two_simultaneous_submissions_cannot_replace_the_same_attempt(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Submission row-lock concurrency is verified on PostgreSQL.');
        }
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('key', 'student')->value('id'));
        $enrollment = Enrollment::factory()->create(['user_id' => $user->id]);
        $assignment = Assignment::factory()->create(['cohort_id' => $enrollment->cohort_id]);
        $code = <<<'CODE'
require 'vendor/autoload.php';
$app=require 'bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$user=\App\Models\User::findOrFail((int)$argv[1]);
$request=\Illuminate\Http\Request::create('/test-submit','POST',['body'=>$argv[4],'expected_version'=>0]);
$request->setUserResolver(fn()=>$user);
try{app(\App\Http\Controllers\SubmissionController::class)->store($request,\App\Models\Enrollment::findOrFail((int)$argv[2]),\App\Models\Assignment::findOrFail((int)$argv[3]),app(\App\Services\LearningAccess::class));echo 'SAVED';}
catch(\Illuminate\Validation\ValidationException){echo 'STALE';}
CODE;
        $processes = [];
        foreach (['First concurrent answer', 'Second concurrent answer'] as $answer) {
            $processes[] = new Process([PHP_BINARY, '-r', $code, (string) $user->id, (string) $enrollment->id, (string) $assignment->id, $answer], base_path(), null, null, 20);
        }
        foreach ($processes as $process) {
            $process->start();
        }
        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $results[] = $process->getOutput();
        }
        sort($results);
        $this->assertSame(['SAVED', 'STALE'], $results);
        $this->assertSame(1, Submission::count());
    }

    private function parallelFinance(string $code, array $arguments): array
    {
        $processes = [];
        foreach ($arguments as $args) {
            $processes[] = new Process([PHP_BINARY, '-r', $code, ...array_map('strval', $args)], base_path(), null, null, 20);
        }
        foreach ($processes as $process) {
            $process->start();
        }
        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $results[] = $process->getOutput();
        }sort($results);

        return $results;
    }

    private function financialInvoice(User $user): Installment
    {
        $course = Course::factory()->create(['price' => 1000000]);
        $cohort = Cohort::factory()->create(['course_id' => $course->id, 'manual_payment_enabled' => true]);

        return app(EnrollmentService::class)->reserve($user, $cohort)->installments()->first();
    }

    public function test_two_verified_charges_for_one_invoice_allocate_only_once(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Payment allocation concurrency requires PostgreSQL.');
        }
        $user = User::factory()->create();
        $invoice = $this->financialInvoice($user);
        $payments = [];
        foreach (['first', 'second'] as $reference) {
            $payment = Payment::factory()->create(['user_id' => $user->id, 'enrollment_id' => $invoice->enrollment_id, 'installment_id' => $invoice->id, 'amount' => $invoice->amount]);
            $payments[] = [$payment->id, $reference];
        }
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo app(\App\Services\BillingService::class)->capture(\App\Models\Payment::findOrFail((int)$argv[1]),$argv[2])?'PAID':'REVIEW';
CODE;
        $this->assertSame(['PAID', 'REVIEW'], $this->parallelFinance($code, $payments));
        $this->assertSame(1, PaymentAllocation::count());
        $this->assertSame(1, Payment::where('status', 'paid')->count());
        $this->assertSame(1, Payment::where('status', 'review_required')->count());
        $this->assertSame(2000000, (int) Payment::whereNotNull('captured_at')->sum('amount'));
    }

    public function test_same_external_reference_cannot_be_captured_for_two_different_invoices(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Reference collision concurrency requires PostgreSQL.');
        }
        $arguments = [];
        foreach ([1, 2] as $number) {
            $user = User::factory()->create();
            $invoice = $this->financialInvoice($user);
            $p = Payment::factory()->create(['user_id' => $user->id, 'enrollment_id' => $invoice->enrollment_id, 'installment_id' => $invoice->id, 'amount' => $invoice->amount]);
            $arguments[] = [$p->id, 'SHARED-REFERENCE'];
        }
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo app(\App\Services\BillingService::class)->capture(\App\Models\Payment::findOrFail((int)$argv[1]),$argv[2])?'PAID':'REVIEW';
CODE;
        $this->assertSame(['PAID', 'REVIEW'], $this->parallelFinance($code, $arguments));
        $this->assertSame(1, PaymentAllocation::count());
        $this->assertSame(1, Payment::whereNotNull('captured_at')->count());
    }

    public function test_conflicting_receipt_decisions_cannot_both_be_applied(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Receipt decision concurrency requires PostgreSQL.');
        }
        config(['platform.finance.transfer_destination' => '6037991234567890']);
        Storage::fake('receipts');
        $user = User::factory()->create();
        $invoice = $this->financialInvoice($user);
        $receipt = app(ReceiptService::class)->submit($user, $invoice, UploadedFile::fake()->createWithContent('receipt.pdf', "%PDF-1.4\nConcurrent Receipt"), '12345678', now()->toDateString());
        $arguments = [];
        foreach (['approve', 'reject'] as $action) {
            $admin = User::factory()->create();
            $admin->roles()->attach(Role::where('key', 'admin')->value('id'));
            $arguments[] = [$admin->id, $receipt->id, $action];
        }
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try{app(\App\Services\ReceiptService::class)->review(\App\Models\User::findOrFail((int)$argv[1]),\App\Models\PaymentReceipt::findOrFail((int)$argv[2]),['action'=>$argv[3],'expected_review_version'=>0,'verified_amount'=>100000,'statement_verified'=>true,'review_note'=>'Verified in concurrent test']);echo 'DECIDED';}
catch(\Illuminate\Validation\ValidationException){echo 'STALE';}
CODE;
        $this->assertSame(['DECIDED', 'STALE'], $this->parallelFinance($code, $arguments));
        $this->assertSame(1, DB::table('notifications')->count());
        $this->assertSame(1, $receipt->fresh()->review_version);
        $this->assertSame($receipt->fresh()->status === 'approved' ? 1 : 0, PaymentAllocation::count());
    }

    public function test_two_students_cannot_book_the_same_consultation_slot(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Consultation concurrency requires PostgreSQL.');
        }
        $slot = ConsultationSlot::factory()->create();
        $args = [];
        foreach ([1, 2] as $n) {
            $u = User::factory()->create();
            $u->roles()->attach(Role::where('key', 'student')->value('id'));
            $args[] = [$u->id, $slot->id];
        }
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try { app(\App\Services\ConsultationService::class)->book(\App\Models\User::findOrFail((int)$argv[1]),\App\Models\ConsultationSlot::findOrFail((int)$argv[2]),'Concurrent booking');echo 'BOOKED'; }
catch(\Illuminate\Validation\ValidationException) { echo 'UNAVAILABLE'; }
CODE;
        $this->assertSame(['BOOKED', 'UNAVAILABLE'], $this->parallelFinance($code, $args));
        $this->assertSame(1, Consultation::where('status', 'confirmed')->count());
        $this->assertTrue($slot->fresh()->booked);
        $this->assertSame(2, DB::table('notifications')->count());
    }

    public function test_student_cannot_simultaneously_book_overlapping_consultation_slots(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Consultation concurrency requires PostgreSQL.');
        }
        $u = User::factory()->create();
        $u->roles()->attach(Role::where('key', 'student')->value('id'));
        $slots = ConsultationSlot::factory()->count(2)->create();
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try { app(\App\Services\ConsultationService::class)->book(\App\Models\User::findOrFail((int)$argv[1]),\App\Models\ConsultationSlot::findOrFail((int)$argv[2]),'Concurrent overlapping booking');echo 'BOOKED'; }
catch(\Illuminate\Validation\ValidationException) { echo 'UNAVAILABLE'; }
CODE;
        $this->assertSame(['BOOKED', 'UNAVAILABLE'], $this->parallelFinance($code, $slots->map(fn ($s) => [$u->id, $s->id])->all()));
        $this->assertSame(1, Consultation::count());
        $this->assertSame(1, ConsultationSlot::where('booked', true)->count());
    }

    public function test_two_simultaneous_human_requests_create_one_pending_request(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Consultation concurrency requires PostgreSQL.');
        }
        $u = User::factory()->create();
        $u->roles()->attach(Role::where('key', 'student')->value('id'));
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try { app(\App\Services\ConsultationService::class)->request(\App\Models\User::findOrFail((int)$argv[1]),'Concurrent request','Evenings');echo 'CREATED'; }
catch(\Illuminate\Validation\ValidationException) { echo 'DUPLICATE'; }
CODE;
        $this->assertSame(['CREATED', 'DUPLICATE'], $this->parallelFinance($code, [[$u->id], [$u->id]]));
        $this->assertSame(1, ConsultationRequest::where('status', 'pending')->count());
    }

    protected function tearDown(): void
    {
        if (Schema::hasTable('coach_credentials')) {
            DB::table('coach_credential_events')->delete();
            DB::table('coach_credentials')->delete();
        }
        if (Schema::hasColumn('consultation_requests', 'requested_coach_id')) {
            DB::table('consultation_requests')->update(['requested_coach_id' => null, 'requested_mentor' => null]);
        }
        if (Schema::hasTable('contact_leads')) {
            DB::table('contact_lead_events')->delete();
            DB::table('contact_leads')->delete();
        }
        if (Schema::hasTable('coach_profiles')) {
            DB::table('coach_profiles')->update(['current_publication_id' => null, 'latest_submission_id' => null]);
            DB::table('coach_profile_withdrawals')->delete();
            DB::table('coach_profile_publications')->delete();
            DB::table('coach_profile_submissions')->delete();
            DB::table('coach_profiles')->delete();
        }
        if (Schema::hasTable('learning_reward_policies')) {
            DB::table('learning_badges')->delete();
            DB::table('learning_reward_events')->delete();
            DB::table('learning_reward_policies')->update(['current_version_id' => null]);
            DB::table('learning_reward_versions')->delete();
            DB::table('learning_reward_policies')->delete();
        }
        if (Schema::hasTable('announcement_schedules')) {
            DB::table('announcement_schedules')->delete();
        }
        if (Schema::hasTable('announcements')) {
            DB::table('announcement_recipients')->delete();
            DB::table('announcements')->delete();
        }
        if (Schema::hasTable('notifications')) {
            DB::table('notifications')->where('type', PlatformAnnouncement::class)->delete();
        }
        if (Schema::hasTable('sms_deliveries')) {
            DB::table('event_reminders')->delete();
            DB::table('invitation_sms')->update(['sms_delivery_id' => null]);
            DB::table('sms_delivery_attempts')->delete();
            DB::table('sms_deliveries')->delete();
        }
        if (Schema::hasTable('resume_shares')) {
            DB::table('resume_shares')->delete();
        }
        if (Schema::hasTable('career_resumes')) {
            DB::table('resume_checks')->delete();
            DB::table('resume_versions')->delete();
            DB::table('career_resumes')->delete();
        }
        if (Schema::hasTable('content_schedules')) {
            DB::table('content_schedules')->delete();
        }
        if (Schema::hasTable('content_publications')) {
            DB::table('content_publications')->delete();
            DB::table('content_pages')->delete();
        }
        if (Schema::hasTable('support_tickets')) {
            DB::table('ticket_events')->delete();
            DB::table('ticket_messages')->delete();
            DB::table('support_tickets')->delete();
        }
        if (Schema::hasTable('survey_responses')) {
            DB::table('survey_responses')->delete();
        }
        if (Schema::hasTable('course_discussions')) {
            DB::table('course_discussions')->update(['accepted_post_id' => null]);
            DB::table('discussion_post_revisions')->delete();
            DB::table('discussion_posts')->delete();
            DB::table('course_discussions')->delete();
        }
        if (Schema::hasTable('certificate_visibility_events')) {
            DB::table('certificate_visibility_events')->delete();
        }
        if (Schema::hasTable('course_certificates')) {
            DB::table('course_certificates')->delete();
            DB::table('certificate_requests')->delete();
        }
        if (Schema::hasTable('package_purchases')) {
            DB::table('package_purchases')->update(['settled_payment_id' => null]);
            $ids = Payment::whereNotNull('package_purchase_id')->pluck('id');
            $receiptIds = PaymentReceipt::whereIn('payment_id', $ids)->pluck('id');
            ReceiptClaim::whereIn('payment_receipt_id', $receiptIds)->delete();
            PaymentReceipt::whereIn('id', $receiptIds)->delete();
            $refundIds = Refund::whereIn('payment_id', $ids)->pluck('id');
            RefundEvent::whereIn('refund_id', $refundIds)->delete();
            Refund::whereIn('id', $refundIds)->delete();
            Payment::whereIn('id', $ids)->delete();
            DB::table('package_purchases')->delete();
        }
        if (Schema::hasTable('enrollment_consultation_benefits')) {
            DB::table('enrollment_consultation_benefits')->delete();
        }
        if (Schema::hasTable('consultation_grants')) {
            DB::table('consultation_grant_uses')->delete();
            DB::table('consultations')->update(['consultation_grant_id' => null]);
            DB::table('consultation_requests')->update(['consultation_grant_id' => null]);
            DB::table('consultation_grants')->delete();
        }
        if (Schema::hasTable('refunds')) {
            RefundEvent::query()->delete();
            Refund::query()->delete();
        }
        if (Schema::hasColumn('payments', 'organization_invoice_id')) {
            if (Schema::hasTable('organization_payment_allocations')) {
                DB::table('organization_payment_allocations')->delete();
            }
            $ids = Payment::whereNotNull('organization_invoice_id')->pluck('id');
            $receipts = PaymentReceipt::whereIn('payment_id', $ids)->pluck('id');
            ReceiptClaim::whereIn('payment_receipt_id', $receipts)->delete();
            PaymentReceipt::whereIn('id', $receipts)->delete();
            OrganizationInvoice::whereNotNull('settled_payment_id')->update(['settled_payment_id' => null]);
            OrganizationInvoice::whereNotNull('payment_schedule')->update(['payment_schedule' => null]);
            Payment::whereIn('id', $ids)->delete();
        }
        if (Schema::hasColumn('payments', 'consultation_id')) {
            Consultation::whereNotNull('settled_payment_id')->update(['settled_payment_id' => null]);
            Payment::whereNotNull('consultation_id')->delete();
        }
        parent::tearDown();
    }

    private function consultationCharge(): Payment
    {
        config(['platform.zibal.enabled' => true, 'platform.zibal.merchant' => 'test']);
        $u = User::factory()->create();
        $u->roles()->attach(Role::where('key', 'student')->value('id'));
        $slot = ConsultationSlot::factory()->create(['price' => 1500000]);
        $c = app(ConsultationService::class)->book($u, $slot, 'Concurrent consultation');

        return app(ConsultationBilling::class)->attempt($u, $c);
    }

    public function test_two_real_consultation_charges_confirm_once_and_retain_extra_funds_for_review(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Payment races require PostgreSQL.');
        }
        $p = $this->consultationCharge();
        $other = Payment::create([...$p->only('consultation_id', 'user_id', 'amount', 'method'), 'status' => 'pending']);
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo app(\App\Services\ConsultationBilling::class)->capture(\App\Models\Payment::findOrFail((int)$argv[1]),$argv[2])?'PAID':'REVIEW';
CODE;
        $this->assertSame(['PAID', 'REVIEW'], $this->parallelFinance($code, [[$p->id, 'ONE'], [$other->id, 'TWO']]));
        $this->assertSame(1, Payment::whereNotNull('consultation_id')->where('status', 'paid')->count());
        $this->assertSame(3000000, (int) Payment::whereNotNull('consultation_id')->whereNotNull('captured_at')->sum('amount'));
        $this->assertSame('confirmed', $p->consultation->fresh()->status);
    }

    public function test_shared_bank_reference_cannot_fund_course_and_consultation_concurrently(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Payment races require PostgreSQL.');
        }
        $consult = $this->consultationCharge();
        $u = User::factory()->create();
        $invoice = $this->financialInvoice($u);
        $course = Payment::factory()->create(['user_id' => $u->id, 'enrollment_id' => $invoice->enrollment_id, 'installment_id' => $invoice->id, 'amount' => $invoice->amount]);
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$p=\App\Models\Payment::findOrFail((int)$argv[1]);$s=$p->consultation_id?\App\Services\ConsultationBilling::class:\App\Services\BillingService::class;echo app($s)->capture($p,'BANK-SHARED')?'PAID':'REVIEW';
CODE;
        $this->assertSame(['PAID', 'REVIEW'], $this->parallelFinance($code, [[$consult->id], [$course->id]]));
        $this->assertSame(1, Payment::whereNotNull('captured_at')->count());
        $this->assertSame(1, Payment::where('status', 'paid')->count());
    }

    public function test_consultation_cancellation_racing_verified_charge_never_loses_received_money(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Payment races require PostgreSQL.');
        }
        $p = $this->consultationCharge();
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$p=\App\Models\Payment::findOrFail((int)$argv[1]);
if ($argv[2]==='pay') {echo app(\App\Services\ConsultationBilling::class)->capture($p,'CANCEL-RACE')?'PAID':'REVIEW';}
else {try {app(\App\Services\ConsultationService::class)->change($p->consultation->student,$p->consultation,1,'cancel','Cancellation race',null);echo 'CANCELLED';}catch(\Illuminate\Validation\ValidationException){echo 'STALE';}}
CODE;
        $result = $this->parallelFinance($code, [[$p->id, 'pay'], [$p->id, 'cancel']]);
        $this->assertContains($result, [['CANCELLED', 'REVIEW'], ['PAID', 'STALE']]);
        $this->assertNotNull($p->fresh()->captured_at);
        $this->assertSame($p->fresh()->status === 'paid' ? 'confirmed' : 'cancelled', $p->consultation->fresh()->status);
    }

    private function refundableCoursePayment(): Payment
    {
        $u = User::factory()->create();
        $i = $this->financialInvoice($u);

        return Payment::factory()->create(['user_id' => $u->id, 'enrollment_id' => $i->enrollment_id, 'installment_id' => $i->id, 'amount' => $i->amount, 'status' => 'review_required', 'reference' => 'REFUND-'.$u->id, 'captured_at' => now()]);
    }

    public function test_refund_request_racing_allocation_has_exactly_one_outcome(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Refund races require PostgreSQL.');
        }
        $p = $this->refundableCoursePayment();
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('key', 'admin')->value('id'));
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();$p=\App\Models\Payment::findOrFail((int)$argv[1]);
try {if($argv[2]==='request') {app(\App\Services\RefundService::class)->request($p->user,$p,'IR820540102680020817909002','Refund race',0);echo 'REQUESTED';}else{app(\App\Services\BillingService::class)->resolve($p,\App\Models\User::findOrFail((int)$argv[3]));echo 'ALLOCATED';}}
catch(\Illuminate\Validation\ValidationException){echo $argv[2]==='request'?'INELIGIBLE':'BLOCKED';}
CODE;
        $results = $this->parallelFinance($code, [[$p->id, 'request', $admin->id], [$p->id, 'allocate', $admin->id]]);
        $this->assertContains($results, [['ALLOCATED', 'INELIGIBLE'], ['BLOCKED', 'REQUESTED']]);
        $this->assertSame(1, PaymentAllocation::where('payment_id', $p->id)->count() + Refund::where('payment_id', $p->id)->count());
    }

    private function approvedRefund(): Refund
    {
        $p = $this->refundableCoursePayment();
        $f = app(RefundService::class)->request($p->user, $p, 'IR820540102680020817909002', 'Refund race', 0);
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('key', 'admin')->value('id'));
        app(RefundService::class)->review($admin, $f, 1, 'approve', 'Approved for refund');

        return $f->fresh();
    }

    private function refundCompletionCode(): string
    {
        return <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();config(['filesystems.disks.refunds.root'=>$argv[3]]);
try {app(\App\Services\RefundService::class)->complete(\App\Models\User::findOrFail((int)$argv[2]),\App\Models\Refund::findOrFail((int)$argv[1]),['version'=>2,'amount'=>100000,'destination'=>'IR820540102680020817909002','transfer_reference'=>'REFUND-SHARED','transfer_date'=>now()->toDateString(),'statement_verified'=>true,'beneficiary_verified'=>true],\Illuminate\Http\UploadedFile::fake()->createWithContent('proof.pdf',"%PDF-1.4\n".$argv[1].'-'.$argv[2]));echo 'COMPLETED';}
catch(\Illuminate\Validation\ValidationException){echo 'REJECTED';}
CODE;
    }

    public function test_two_admins_cannot_complete_one_refund_twice(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Refund races require PostgreSQL.');
        }
        $f = $this->approvedRefund();
        $dir = sys_get_temp_dir().'/kardorost-refund-'.Str::uuid();
        $args = [];
        foreach ([1, 2] as $n) {
            $a = User::factory()->create();
            $a->roles()->attach(Role::where('key', 'admin')->value('id'));
            $args[] = [$f->id, $a->id, $dir];
        }
        $this->assertSame(['COMPLETED', 'REJECTED'], $this->parallelFinance($this->refundCompletionCode(), $args));
        $this->assertSame('refunded', $f->payment->fresh()->status);
        $this->assertSame(3, $f->fresh()->version);
        $this->assertSame(3, RefundEvent::where('refund_id', $f->id)->count());
        config(['filesystems.disks.refunds.root' => $dir]);
        Storage::forgetDisk('refunds');
        $this->assertSame(1, count(Storage::disk('refunds')->allFiles()));
        Storage::disk('refunds')->deleteDirectory('proofs');
    }

    public function test_two_refunds_cannot_claim_the_same_bank_transfer(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Refund races require PostgreSQL.');
        }
        $dir = sys_get_temp_dir().'/kardorost-refund-'.Str::uuid();
        $args = [];
        foreach ([1, 2] as $n) {
            $f = $this->approvedRefund();
            $a = User::factory()->create();
            $a->roles()->attach(Role::where('key', 'admin')->value('id'));
            $args[] = [$f->id, $a->id, $dir];
        }
        $this->assertSame(['COMPLETED', 'REJECTED'], $this->parallelFinance($this->refundCompletionCode(), $args));
        $this->assertSame(1, Refund::where('status', 'completed')->count());
        $this->assertSame(1, Refund::where('status', 'approved')->count());
        config(['filesystems.disks.refunds.root' => $dir]);
        Storage::forgetDisk('refunds');
        $this->assertSame(1, count(Storage::disk('refunds')->allFiles()));
        Storage::disk('refunds')->deleteDirectory('proofs');
    }

    private function publishedAssessment(): Assessment
    {
        $a = Assessment::factory()->create();
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('key', 'admin')->value('id'));
        $service = app(AssessmentService::class);
        $service->saveDraft($admin, $a, 0, AssessmentVersion::factory()->definition()['definition']);
        $service->publish($admin, $a, 1);

        return $a->fresh();
    }

    public function test_concurrent_assessment_starts_resume_one_versioned_attempt(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Assessment races require PostgreSQL.');
        }
        $a = $this->publishedAssessment();
        $u = User::factory()->create();
        $u->roles()->attach(Role::where('key', 'student')->value('id'));
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo app(\App\Services\AssessmentService::class)->start(\App\Models\User::findOrFail((int)$argv[1]),\App\Models\Assessment::findOrFail((int)$argv[2]))->id;
CODE;
        $r = $this->parallelFinance($code, [[$u->id, $a->id], [$u->id, $a->id]]);
        $this->assertSame($r[0], $r[1]);
        $this->assertSame(1, AssessmentAttempt::count());
    }

    private function assessmentAttempt(): AssessmentAttempt
    {
        $a = $this->publishedAssessment();
        $u = User::factory()->create();
        $u->roles()->attach(Role::where('key', 'student')->value('id'));

        return app(AssessmentService::class)->start($u, $a);
    }

    public function test_stale_browser_tab_cannot_overwrite_simultaneously_saved_answers(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Assessment races require PostgreSQL.');
        }
        $a = $this->assessmentAttempt();
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();$a=\App\Models\AssessmentAttempt::findOrFail((int)$argv[1]);
try{app(\App\Services\AssessmentService::class)->save($a->user,$a,1,['q1'=>(int)$argv[2]]);echo 'SAVED';}catch(\Illuminate\Validation\ValidationException){echo 'STALE';}
CODE;
        $this->assertSame(['SAVED', 'STALE'], $this->parallelFinance($code, [[$a->id, 1], [$a->id, 5]]));
        $this->assertSame(2, $a->fresh()->revision);
        $this->assertContains($a->fresh()->answers['q1'], [1, 5]);
    }

    public function test_concurrent_finalization_creates_one_frozen_report_and_one_audit_event(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Assessment races require PostgreSQL.');
        }
        $a = $this->assessmentAttempt();
        app(AssessmentService::class)->save($a->user, $a, 1, ['q1' => 5, 'q2' => 1]);
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();$a=\App\Models\AssessmentAttempt::findOrFail((int)$argv[1]);
echo app(\App\Services\AssessmentService::class)->finish($a->user,$a,2)->status;
CODE;
        $this->assertSame(['completed', 'completed'], $this->parallelFinance($code, [[$a->id], [$a->id]]));
        $this->assertSame(3, $a->fresh()->revision);
        $this->assertSame(1, AuditEvent::where('action', 'assessment.completed')->count());
        $this->assertSame(10, $a->fresh()->result['dimensions'][0]['sum']);
    }

    private function organizationQuota(int $capacity = 2, int $seats = 1): array
    {
        $org = Organization::factory()->create(['name' => 'Organization race', 'type' => 'company', 'status' => 'active']);
        $c = Cohort::factory()->create(['capacity' => $capacity]);
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('key', 'admin')->value('id'));
        $manager = User::factory()->create();
        $manager->roles()->attach(Role::where('key', 'org_admin')->value('id'));
        $org->members()->attach($manager->id, ['kind' => 'manager']);
        $members = [];
        foreach ([1, 2] as $n) {
            $u = User::factory()->create();
            $u->roles()->attach(Role::where('key', 'student')->value('id'));
            $org->members()->attach($u->id);
            $members[] = $u;
        }
        $l = app(OrganizationEnrollment::class)->configure($admin, $org, $c, ['seat_limit' => $seats, 'expires_on' => now()->addMonth()->toDateString(), 'active' => true, 'contract_reference' => 'Race contract']);

        return [$org, $c, $l, $manager, $members];
    }

    private function organizationEnrollmentCode(): string
    {
        return <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();$l=\App\Models\OrganizationLicense::findOrFail((int)$argv[1]);
try {$r=app(\App\Services\OrganizationEnrollment::class)->enroll(\App\Models\User::findOrFail((int)$argv[2]),$l->organization,$l,1,[(int)$argv[3]]);echo $r['enrolled']?'ENROLLED':'SKIPPED';}catch(\Illuminate\Validation\ValidationException){echo 'REJECTED';}
CODE;
    }

    public function test_two_managers_cannot_consume_one_organization_seat_twice(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Organization capacity races require PostgreSQL.');
        }
        [$org,$c,$l,$manager,$members] = $this->organizationQuota(1);
        $second = User::factory()->create();
        $second->roles()->attach(Role::where('key', 'org_admin')->value('id'));
        $org->members()->attach($second->id, ['kind' => 'manager']);
        $this->assertSame(['ENROLLED', 'REJECTED'], $this->parallelFinance($this->organizationEnrollmentCode(), [[$l->id, $manager->id, $members[0]->id], [$l->id, $second->id, $members[1]->id]]));
        $this->assertSame(1, OrganizationSeat::count());
        $this->assertSame(1, Enrollment::where('status', 'active')->count());
        $this->assertSame(0, app(CohortCapacity::class)->available($c));
    }

    public function test_duplicate_simultaneous_group_requests_consume_one_seat_and_notify_once(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Organization capacity races require PostgreSQL.');
        }
        [$org,$c,$l,$manager,$members] = $this->organizationQuota();
        $args = [$l->id, $manager->id, $members[0]->id];
        $this->assertSame(['ENROLLED', 'SKIPPED'], $this->parallelFinance($this->organizationEnrollmentCode(), [$args, $args]));
        $this->assertSame(1, OrganizationSeat::count());
        $this->assertSame(1, DB::table('notifications')->count());
    }

    public function test_organization_and_personal_registration_share_capacity_without_double_counting(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Organization capacity races require PostgreSQL.');
        }
        [$org,$c,$l,$manager,$members] = $this->organizationQuota(2);
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();$l=\App\Models\OrganizationLicense::findOrFail((int)$argv[1]);
if($argv[2]==='group') {app(\App\Services\OrganizationEnrollment::class)->enroll(\App\Models\User::findOrFail((int)$argv[3]),$l->organization,$l,1,[(int)$argv[4]]);echo 'GROUP';}
else {app(\App\Services\EnrollmentService::class)->reserve(\App\Models\User::findOrFail((int)$argv[4]),$l->cohort);echo 'PERSONAL';}
CODE;
        $this->assertSame(['GROUP', 'PERSONAL'], $this->parallelFinance($code, [[$l->id, 'group', $manager->id, $members[0]->id], [$l->id, 'personal', $manager->id, $members[1]->id]]));
        $this->assertSame(2, Enrollment::where('status', 'active')->count());
        $this->assertSame(1, OrganizationSeat::count());
        $this->assertSame(0, app(CohortCapacity::class)->available($c));
    }

    public function test_quota_issuance_racing_last_personal_seat_has_one_capacity_owner(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Organization capacity races require PostgreSQL.');
        }
        $c = Cohort::factory()->create(['capacity' => 1]);
        $org = Organization::factory()->create(['name' => 'Race org', 'type' => 'company', 'status' => 'active']);
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('key', 'admin')->value('id'));
        $u = User::factory()->create();
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();$c=\App\Models\Cohort::findOrFail((int)$argv[1]);
try {if($argv[2]==='license') {app(\App\Services\OrganizationEnrollment::class)->configure(\App\Models\User::findOrFail((int)$argv[3]),\App\Models\Organization::findOrFail((int)$argv[4]),$c,['seat_limit'=>1,'expires_on'=>now()->addMonth()->toDateString(),'active'=>true,'contract_reference'=>'Race']);echo 'LICENSED';}else {app(\App\Services\EnrollmentService::class)->reserve(\App\Models\User::findOrFail((int)$argv[3]),$c);echo 'RESERVED';}}
catch(\Illuminate\Validation\ValidationException) {echo $argv[2]==='license'?'NO_LICENSE':'NO_SEAT';}
CODE;
        $r = $this->parallelFinance($code, [[$c->id, 'license', $admin->id, $org->id], [$c->id, 'personal', $u->id, $org->id]]);
        $this->assertContains($r, [['LICENSED', 'NO_SEAT'], ['NO_LICENSE', 'RESERVED']]);
        $capacity = app(CohortCapacity::class);
        $this->assertSame(1, $capacity->occupied($c) + $capacity->reserved($c));
    }

    public function test_two_organizations_cannot_reserve_the_same_last_course_capacity(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Organization capacity races require PostgreSQL.');
        }
        $c = Cohort::factory()->create(['capacity' => 1]);
        $args = [];
        foreach ([1, 2] as $n) {
            $org = Organization::factory()->create(['name' => 'Race org '.$n, 'type' => 'company', 'status' => 'active']);
            $a = User::factory()->create();
            $a->roles()->attach(Role::where('key', 'admin')->value('id'));
            $args[] = [$c->id, $a->id, $org->id];
        }
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try {app(\App\Services\OrganizationEnrollment::class)->configure(\App\Models\User::findOrFail((int)$argv[2]),\App\Models\Organization::findOrFail((int)$argv[3]),\App\Models\Cohort::findOrFail((int)$argv[1]),['seat_limit'=>1,'expires_on'=>now()->addMonth()->toDateString(),'active'=>true,'contract_reference'=>'Race']);echo 'LICENSED';}catch(\Illuminate\Validation\ValidationException){echo 'REJECTED';}
CODE;
        $this->assertSame(['LICENSED', 'REJECTED'], $this->parallelFinance($code, $args));
        $this->assertSame(1, OrganizationLicense::count());
    }

    private function organizationInvoiceCharge(): Payment
    {
        config(['platform.finance.transfer_destination' => '6037991234567890', 'platform.finance.transfer_holder' => 'Test holder']);
        $i = OrganizationInvoice::factory()->create();
        $a = User::factory()->create();
        $a->roles()->attach(Role::where('key', 'admin')->value('id'));
        $m = User::factory()->create();
        $m->roles()->attach(Role::where('key', 'org_admin')->value('id'));
        $i->organization->members()->attach($m->id, ['kind' => 'manager']);
        app(OrganizationBilling::class)->issue($a, $i, 1);

        return app(OrganizationBilling::class)->attempt($m, $i->fresh());
    }

    public function test_concurrent_contract_schedule_edit_and_issue_respect_the_same_revision(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Contract schedule races require PostgreSQL.');
        }
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('key', 'admin')->value('id'));
        $invoice = OrganizationInvoice::factory()->create();
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['platform.finance.transfer_destination'=>'6037991234567890','platform.finance.transfer_holder'=>'Fixture holder']);
$actor=\App\Models\User::findOrFail((int)$argv[1]);$invoice=\App\Models\OrganizationInvoice::findOrFail((int)$argv[2]);
try {
 if($argv[3]==='issue') { app(\App\Services\OrganizationBilling::class)->issue($actor,$invoice,1); }
 else { app(\App\Services\OrganizationBilling::class)->draft($actor,$invoice->organization,['title'=>'Schedule race','contract_reference'=>'RACE','due_date'=>$invoice->due_date->toDateString(),'lines'=>[['title'=>'Service','quantity'=>1,'unit_price'=>100000]],'installments'=>[['due_date'=>$invoice->due_date->copy()->subDay()->toDateString(),'amount'=>40000],['due_date'=>$invoice->due_date->toDateString(),'amount'=>60000]]],$invoice,1); }
 echo 'CHANGED';
} catch(\Illuminate\Validation\ValidationException) { echo 'STALE'; }
CODE;
        $this->assertSame(['CHANGED', 'STALE'], $this->parallelFinance($code, [[$admin->id, $invoice->id, 'edit'], [$admin->id, $invoice->id, 'issue']]));
        $invoice->refresh();
        $this->assertSame(2, $invoice->revision);
        $this->assertCount($invoice->status === 'issued' ? 1 : 2, $invoice->payment_schedule['items']);
    }

    public function test_concurrent_partial_contract_payments_project_one_consistent_installment_balance(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Contract schedule races require PostgreSQL.');
        }
        $first = $this->organizationInvoiceCharge();
        $first->update(['amount' => 400000]);
        $invoice = $first->organizationInvoice;
        $definition = app(OrganizationPaymentSchedule::class)->normalize([['due_date' => $invoice->due_date->copy()->subDay()->toDateString(), 'amount' => 40000], ['due_date' => $invoice->due_date->toDateString(), 'amount' => 60000]], $invoice->amount, $invoice->due_date->toDateString());
        $invoice->update(['payment_schedule' => $definition]);
        $second = Payment::create([...$first->only('organization_invoice_id', 'user_id', 'method'), 'amount' => 600000, 'status' => 'pending']);
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo app(\App\Services\OrganizationBilling::class)->capture(\App\Models\Payment::findOrFail((int)$argv[1]),$argv[2])?'PAID':'REVIEW';
CODE;
        $this->assertSame(['PAID', 'PAID'], $this->parallelFinance($code, [[$first->id, 'SCHEDULE-RACE-FIRST'], [$second->id, 'SCHEDULE-RACE-SECOND']]));
        $invoice->refresh();
        $summary = app(OrganizationPaymentSchedule::class)->summary($invoice, $invoice->paidAmount());
        $this->assertSame(['paid', 'paid'], array_column($summary['items'], 'status'));
        $this->assertSame([400000, 600000], array_column($summary['items'], 'paid_amount'));
        $this->assertSame(1000000, $invoice->paidAmount());
        $this->assertSame(2, OrganizationPaymentAllocation::count());
    }

    public function test_two_verified_organization_charges_settle_one_invoice_once(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Organization payment races require PostgreSQL.');
        }
        $p = $this->organizationInvoiceCharge();
        $other = Payment::create([...$p->only('organization_invoice_id', 'user_id', 'amount', 'method'), 'status' => 'pending']);
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo app(\App\Services\OrganizationBilling::class)->capture(\App\Models\Payment::findOrFail((int)$argv[1]),$argv[2])?'PAID':'REVIEW';
CODE;
        $this->assertSame(['PAID', 'REVIEW'], $this->parallelFinance($code, [[$p->id, 'ORG-FIRST'], [$other->id, 'ORG-SECOND']]));
        $this->assertSame(1, Payment::where('status', 'paid')->count());
        $this->assertSame(2000000, (int) Payment::whereNotNull('captured_at')->sum('amount'));
        $this->assertSame('paid', $p->organizationInvoice->fresh()->status);
        $this->assertSame(0, OrganizationLicense::count());
    }

    public function test_organization_receipt_cannot_be_approved_and_rejected_concurrently(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Organization payment races require PostgreSQL.');
        }
        $p = $this->organizationInvoiceCharge();
        $p->update(['status' => 'expired', 'attempt_expires_at' => now()->subMinute()]);
        Storage::fake('receipts');
        $r = app(OrganizationBilling::class)->receipt($p->user, $p->organizationInvoice, UploadedFile::fake()->createWithContent('org.pdf', "%PDF-1.4\nOrg receipt race"), '12345678', now()->toDateString());
        $args = [];
        foreach (['approve', 'reject'] as $action) {
            $a = User::factory()->create();
            $a->roles()->attach(Role::where('key', 'admin')->value('id'));
            $args[] = [$r->id, $a->id, $action];
        }
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try {app(\App\Services\OrganizationBilling::class)->review(\App\Models\User::findOrFail((int)$argv[2]),\App\Models\PaymentReceipt::findOrFail((int)$argv[1]),['action'=>$argv[3],'expected_review_version'=>0,'review_note'=>'Independent bank review','verified_amount'=>100000,'statement_verified'=>true]);echo 'DECIDED';}catch(\Illuminate\Validation\ValidationException){echo 'STALE';}
CODE;
        $this->assertSame(['DECIDED', 'STALE'], $this->parallelFinance($code, $args));
        $this->assertSame(1, $r->fresh()->review_version);
        $this->assertSame($r->fresh()->status === 'approved' ? 'paid' : 'issued', $p->organizationInvoice->fresh()->status);
    }

    public function test_organization_invoice_void_racing_payment_never_loses_captured_funds(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Organization payment races require PostgreSQL.');
        }
        $p = $this->organizationInvoiceCharge();
        $p->update(['attempt_expires_at' => now()->subMinute()]);
        $a = User::factory()->create();
        $a->roles()->attach(Role::where('key', 'admin')->value('id'));
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();$p=\App\Models\Payment::findOrFail((int)$argv[1]);
if($argv[2]==='pay') {echo app(\App\Services\OrganizationBilling::class)->capture($p,'VOID-RACE')?'PAID':'REVIEW';}else{try{app(\App\Services\OrganizationBilling::class)->void(\App\Models\User::findOrFail((int)$argv[3]),$p->organizationInvoice,2,'Invoice entered incorrectly');echo 'VOID';}catch(\Illuminate\Validation\ValidationException){echo 'BLOCKED';}}
CODE;
        $r = $this->parallelFinance($code, [[$p->id, 'pay', $a->id], [$p->id, 'void', $a->id]]);
        $this->assertContains($r, [['BLOCKED', 'PAID'], ['REVIEW', 'VOID']]);
        $this->assertNotNull($p->fresh()->captured_at);
        $this->assertSame($p->fresh()->status === 'paid' ? 'paid' : 'void', $p->organizationInvoice->fresh()->status);
    }

    public function test_organization_and_course_payments_share_one_unique_bank_reference(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Organization payment races require PostgreSQL.');
        }
        $org = $this->organizationInvoiceCharge();
        $u = User::factory()->create();
        $i = $this->financialInvoice($u);
        $course = Payment::factory()->create(['user_id' => $u->id, 'enrollment_id' => $i->enrollment_id, 'installment_id' => $i->id, 'amount' => $i->amount]);
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();$p=\App\Models\Payment::findOrFail((int)$argv[1]);$s=$p->organization_invoice_id?\App\Services\OrganizationBilling::class:\App\Services\BillingService::class;
echo app($s)->capture($p,'SHARED-ORG-COURSE')?'PAID':'REVIEW';
CODE;
        $this->assertSame(['PAID', 'REVIEW'], $this->parallelFinance($code, [[$org->id], [$course->id]]));
        $this->assertSame(1, Payment::whereNotNull('captured_at')->count());
        $this->assertSame(1, Payment::where('status', 'paid')->count());
    }

    public function test_two_partial_organization_captures_can_finish_same_invoice_concurrently(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Organization payment races require PostgreSQL.');
        }
        $first = $this->organizationInvoiceCharge();
        $first->update(['amount' => 400000]);
        $second = Payment::create([...$first->only('organization_invoice_id', 'user_id', 'method'), 'amount' => 600000, 'status' => 'pending']);
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo app(\App\Services\OrganizationBilling::class)->capture(\App\Models\Payment::findOrFail((int)$argv[1]),'PARTIAL-'.$argv[1])?'PAID':'REVIEW';
CODE;
        $this->assertSame(['PAID', 'PAID'], $this->parallelFinance($code, [[$first->id], [$second->id]]));
        $this->assertSame(1000000, (int) DB::table('organization_payment_allocations')->sum('amount'));
        $this->assertSame(2, DB::table('organization_payment_allocations')->count());
        $this->assertSame('paid', $first->organizationInvoice->fresh()->status);
        $this->assertSame(0, $first->organizationInvoice->fresh()->remainingAmount());
        $this->assertSame(4, $first->organizationInvoice->fresh()->revision);
    }

    public function test_overlapping_partial_charges_cannot_allocate_more_than_invoice_total(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Organization payment races require PostgreSQL.');
        }
        $first = $this->organizationInvoiceCharge();
        $first->update(['amount' => 600000]);
        $second = Payment::create([...$first->only('organization_invoice_id', 'user_id', 'amount', 'method'), 'status' => 'pending']);
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo app(\App\Services\OrganizationBilling::class)->capture(\App\Models\Payment::findOrFail((int)$argv[1]),'OVERLAP-'.$argv[1])?'PAID':'REVIEW';
CODE;
        $this->assertSame(['PAID', 'REVIEW'], $this->parallelFinance($code, [[$first->id], [$second->id]]));
        $this->assertSame(600000, (int) DB::table('organization_payment_allocations')->sum('amount'));
        $this->assertSame(1, DB::table('organization_payment_allocations')->count());
        $this->assertSame(1200000, (int) Payment::whereNotNull('captured_at')->sum('amount'));
        $this->assertSame('organization_invoice_excess', Payment::where('status', 'review_required')->sole()->review_reason);
        $this->assertSame(400000, $first->organizationInvoice->fresh()->remainingAmount());
        $this->assertSame('issued', $first->organizationInvoice->fresh()->status);
    }

    public function test_replayed_partial_capture_records_one_allocation_under_concurrent_callbacks(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Organization payment races require PostgreSQL.');
        }
        $payment = $this->organizationInvoiceCharge();
        $payment->update(['amount' => 400000]);
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo app(\App\Services\OrganizationBilling::class)->capture(\App\Models\Payment::findOrFail((int)$argv[1]),'REPLAY-PARTIAL')?'PAID':'REVIEW';
CODE;
        $this->assertSame(['PAID', 'PAID'], $this->parallelFinance($code, [[$payment->id], [$payment->id]]));
        $this->assertSame(1, DB::table('organization_payment_allocations')->count());
        $this->assertSame(400000, (int) DB::table('organization_payment_allocations')->sum('amount'));
        $this->assertSame(600000, $payment->organizationInvoice->fresh()->remainingAmount());
        $this->assertSame(3, $payment->organizationInvoice->fresh()->revision);
    }

    private function unallocatedOrganizationPayment(): Payment
    {
        $payment = $this->organizationInvoiceCharge();
        $payment->update(['attempt_expires_at' => now()->subMinute()]);
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('key', 'admin')->value('id'));
        app(OrganizationBilling::class)->void($admin, $payment->organizationInvoice, 2, 'Contract cancelled before funds arrived');
        app(OrganizationBilling::class)->capture($payment, 'ORG-REFUND-'.$payment->id);

        return $payment->fresh();
    }

    private function approvedOrganizationRefund(): Refund
    {
        $payment = $this->unallocatedOrganizationPayment();
        $refund = app(RefundService::class)->requestOrganization($payment->user, $payment, 'IR820540102680020817909002', 'Organization account holder', 'Late funds after void', 0);
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('key', 'admin')->value('id'));
        app(RefundService::class)->review($admin, $refund, 1, 'approve', 'Organization destination reviewed');

        return $refund->fresh();
    }

    public function test_two_organization_managers_cannot_create_duplicate_refund_requests(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Organization refund races require PostgreSQL.');
        }
        $payment = $this->unallocatedOrganizationPayment();
        $second = User::factory()->create();
        $second->roles()->attach(Role::where('key', 'org_admin')->value('id'));
        $payment->organizationInvoice->organization->members()->attach($second->id, ['kind' => 'manager']);
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try {app(\App\Services\RefundService::class)->requestOrganization(\App\Models\User::findOrFail((int)$argv[2]),\App\Models\Payment::findOrFail((int)$argv[1]),'IR820540102680020817909002','Organization account holder','Duplicate manager request',0);echo 'REQUESTED';}catch(\Illuminate\Validation\ValidationException){echo 'STALE';}
CODE;
        $this->assertSame(['REQUESTED', 'STALE'], $this->parallelFinance($code, [[$payment->id, $payment->user_id], [$payment->id, $second->id]]));
        $this->assertSame(1, Refund::count());
        $this->assertSame(1, RefundEvent::count());
        $this->assertSame('review_required', $payment->fresh()->status);
    }

    public function test_two_admins_cannot_record_organization_refund_completion_twice(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Organization refund races require PostgreSQL.');
        }
        $refund = $this->approvedOrganizationRefund();
        $directory = sys_get_temp_dir().'/kardorost-org-refund-'.Str::uuid();
        $arguments = [];
        foreach ([1, 2] as $number) {
            $admin = User::factory()->create();
            $admin->roles()->attach(Role::where('key', 'admin')->value('id'));
            $arguments[] = [$refund->id, $admin->id, $directory];
        }
        $this->assertSame(['COMPLETED', 'REJECTED'], $this->parallelFinance($this->refundCompletionCode(), $arguments));
        $this->assertSame('refunded', $refund->payment->fresh()->status);
        $this->assertSame(3, $refund->fresh()->version);
        $this->assertSame(3, RefundEvent::where('refund_id', $refund->id)->count());
        $this->assertSame(0, DB::table('organization_payment_allocations')->count());
        config(['filesystems.disks.refunds.root' => $directory]);
        Storage::forgetDisk('refunds');
        $this->assertSame(1, count(Storage::disk('refunds')->allFiles()));
        Storage::disk('refunds')->deleteDirectory('proofs');
    }

    public function test_gateway_replay_racing_organization_refund_completion_cannot_allocate_refunded_funds(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Organization refund races require PostgreSQL.');
        }
        $refund = $this->approvedOrganizationRefund();
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('key', 'admin')->value('id'));
        $directory = sys_get_temp_dir().'/kardorost-org-refund-'.Str::uuid();
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();config(['filesystems.disks.refunds.root'=>$argv[3]]);
$f=\App\Models\Refund::findOrFail((int)$argv[1]);
if($argv[4]==='callback') {echo app(\App\Services\OrganizationBilling::class)->capture($f->payment,$f->payment->reference)?'ALLOCATED':'REVIEW';}
else {app(\App\Services\RefundService::class)->complete(\App\Models\User::findOrFail((int)$argv[2]),$f,['version'=>2,'amount'=>100000,'destination'=>'IR820540102680020817909002','transfer_reference'=>'ORG-REPLAY-REFUND','transfer_date'=>now()->toDateString(),'statement_verified'=>true,'beneficiary_verified'=>true],\Illuminate\Http\UploadedFile::fake()->createWithContent('proof.pdf',"%PDF-1.4\nOrganization refund race"));echo 'REFUNDED';}
CODE;
        $this->assertSame(['REFUNDED', 'REVIEW'], $this->parallelFinance($code, [[$refund->id, $admin->id, $directory, 'complete'], [$refund->id, $admin->id, $directory, 'callback']]));
        $this->assertSame('refunded', $refund->payment->fresh()->status);
        $this->assertSame('void', $refund->payment->organizationInvoice->fresh()->status);
        $this->assertNotNull($refund->payment->fresh()->captured_at);
        $this->assertSame(0, DB::table('organization_payment_allocations')->count());
        config(['filesystems.disks.refunds.root' => $directory]);
        Storage::forgetDisk('refunds');
        Storage::disk('refunds')->deleteDirectory('proofs');
    }

    private function invitationOrganization(): array
    {
        $organization = Organization::factory()->create(['name' => 'Organization invitations', 'type' => 'company', 'status' => 'active']);
        $manager = User::factory()->create();
        $manager->roles()->attach(Role::where('key', 'org_admin')->value('id'));
        $organization->members()->attach($manager->id, ['kind' => 'manager']);
        $student = User::factory()->create();
        $student->roles()->attach(Role::where('key', 'student')->value('id'));

        return [$organization, $manager, $student];
    }

    public function test_two_managers_cannot_duplicate_an_invitation_or_send_duplicate_notifications(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Invitation races require PostgreSQL.');
        }
        [$organization, $manager, $student] = $this->invitationOrganization();
        $second = User::factory()->create();
        $second->roles()->attach(Role::where('key', 'org_admin')->value('id'));
        $organization->members()->attach($second->id, ['kind' => 'manager']);
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo app(\App\Services\OrganizationMembership::class)->invite(\App\Models\User::findOrFail((int)$argv[2]),\App\Models\Organization::findOrFail((int)$argv[1]),$argv[3])?'CREATED':'EXISTING';
CODE;
        $this->assertSame(['CREATED', 'EXISTING'], $this->parallelFinance($code, [[$organization->id, $manager->id, $student->phone], [$organization->id, $second->id, $student->phone]]));
        $this->assertSame(1, OrganizationInvitation::count());
        $this->assertSame(1, $student->notifications()->count());
        $this->assertSame(2, $organization->members()->count());
    }

    public function test_invitation_acceptance_racing_revocation_has_one_membership_outcome(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Invitation races require PostgreSQL.');
        }
        [$organization, $manager, $student] = $this->invitationOrganization();
        app(OrganizationMembership::class)->invite($manager, $organization, $student->phone);
        $invitation = OrganizationInvitation::sole();
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();$i=\App\Models\OrganizationInvitation::findOrFail((int)$argv[1]);$s=app(\App\Services\OrganizationMembership::class);
try {if($argv[3]==='accept') {$s->respond(\App\Models\User::findOrFail((int)$argv[2]),$i,1,'accept');echo 'ACCEPTED';}else{$s->change(\App\Models\User::findOrFail((int)$argv[2]),$i,1,'revoke');echo 'REVOKED';}}catch(\Illuminate\Validation\ValidationException){echo 'STALE';}
CODE;
        $result = $this->parallelFinance($code, [[$invitation->id, $student->id, 'accept'], [$invitation->id, $manager->id, 'revoke']]);
        $this->assertContains($result, [['ACCEPTED', 'STALE'], ['REVOKED', 'STALE']]);
        $this->assertSame($invitation->fresh()->status === 'accepted' ? 2 : 1, $organization->members()->count());
        $this->assertSame(2, $invitation->fresh()->revision);
        $this->assertSame(['student'], $student->roleKeys());
    }

    public function test_two_confirmations_of_one_csv_preview_commit_once(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Invitation races require PostgreSQL.');
        }
        [$organization, $manager, $student] = $this->invitationOrganization();
        $preview = app(OrganizationMembership::class)->preview($manager, $organization, UploadedFile::fake()->createWithContent('members.csv', "phone\n{$student->phone}\n09121234567\n"));
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$r=app(\App\Services\OrganizationMembership::class)->commit(\App\Models\User::findOrFail((int)$argv[2]),\App\Models\OrganizationInvitationImport::findOrFail((int)$argv[1]));echo $r['created'];
CODE;
        $this->assertSame(['2', '2'], $this->parallelFinance($code, [[$preview->id, $manager->id], [$preview->id, $manager->id]]));
        $this->assertSame(2, OrganizationInvitation::count());
        $this->assertSame(1, AuditEvent::where('action', 'organization_import.committed')->count());
        $this->assertSame(1, $student->notifications()->count());
        $this->assertSame(1, $organization->members()->count());
    }

    public function test_overlapping_csv_imports_create_each_organization_invitation_once(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Invitation races require PostgreSQL.');
        }
        [$organization, $manager, $student] = $this->invitationOrganization();
        $second = User::factory()->create();
        $second->roles()->attach(Role::where('key', 'org_admin')->value('id'));
        $organization->members()->attach($second->id, ['kind' => 'manager']);
        $firstPreview = app(OrganizationMembership::class)->preview($manager, $organization, UploadedFile::fake()->createWithContent('members.csv', "phone\n{$student->phone}\n09121234567\n"));
        $secondPreview = app(OrganizationMembership::class)->preview($second, $organization, UploadedFile::fake()->createWithContent('members.csv', "phone\n{$student->phone}\n09121234568\n"));
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$r=app(\App\Services\OrganizationMembership::class)->commit(\App\Models\User::findOrFail((int)$argv[2]),\App\Models\OrganizationInvitationImport::findOrFail((int)$argv[1]));echo $r['created'].'-'.$r['skipped'];
CODE;
        $this->assertSame(['1-1', '2-0'], $this->parallelFinance($code, [[$firstPreview->id, $manager->id], [$secondPreview->id, $second->id]]));
        $this->assertSame(3, OrganizationInvitation::count());
        $this->assertSame(1, $student->notifications()->count());
        $this->assertSame(2, $organization->members()->count());
    }

    private function invitationSms(): InvitationSms
    {
        config(['platform.sms.melipayamak.invitations_enabled' => true]);
        [$organization, $manager, $student] = $this->invitationOrganization();
        app(OrganizationMembership::class)->invite($manager, $organization, $student->phone);

        return InvitationSms::sole();
    }

    private function invitationSmsWorkerCode(): string
    {
        return <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['platform.sms.driver'=>'melipayamak','platform.sms.melipayamak.mode'=>'rest','platform.sms.melipayamak.username'=>'fixture','platform.sms.melipayamak.api_key'=>'fixture-secret','platform.sms.melipayamak.invitation_body_id'=>'456','platform.sms.melipayamak.invitations_enabled'=>true]);
\Illuminate\Support\Facades\Http::preventStrayRequests();
\Illuminate\Support\Facades\Http::fake(function() {\App\Models\AuditEvent::create(['action'=>'fixture.sms_provider_call','subject_type'=>'invitation_sms','subject_id'=>1]);usleep(200000);return \Illuminate\Support\Facades\Http::response(['RetStatus'=>1,'Value'=>'123456789012345678901']);});
$s=\App\Models\InvitationSms::findOrFail((int)$argv[1]);
if(($argv[2]??'send')==='revoke') {app(\App\Services\OrganizationMembership::class)->change(\App\Models\User::findOrFail((int)$argv[3]),$s->invitation,1,'revoke');echo 'REVOKED';}
else {app(\App\Services\InvitationSmsService::class)->send($s->id,app(\App\Services\SmsSender::class));echo strtoupper($s->fresh()->status);}
CODE;
    }

    public function test_duplicate_sms_workers_cannot_contact_provider_twice(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('SMS outbox races require PostgreSQL.');
        }
        $sms = $this->invitationSms();
        $this->parallelFinance($this->invitationSmsWorkerCode(), [[$sms->id], [$sms->id]]);
        $this->assertSame(1, AuditEvent::where('action', 'fixture.sms_provider_call')->count());
        $this->assertSame('accepted', $sms->fresh()->status);
        $this->assertSame('123456789012345678901', $sms->fresh()->provider_reference);
        $this->assertSame(1, AuditEvent::where('action', 'invitation_sms.accepted')->count());
    }

    public function test_two_sms_dispatchers_schedule_one_initial_job(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('SMS outbox races require PostgreSQL.');
        }
        $sms = $this->invitationSms();
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo app(\App\Services\InvitationSmsService::class)->dispatchPending();
CODE;
        $this->assertSame(['0', '1'], $this->parallelFinance($code, [[], []]));
        $this->assertSame(1, DB::table('jobs')->where('queue', 'sms')->count());
        $this->assertSame('queued', $sms->fresh()->status);
        $this->assertNotNull($sms->fresh()->dispatched_at);
    }

    public function test_sms_send_racing_revoke_never_reactivates_an_invitation(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('SMS outbox races require PostgreSQL.');
        }
        $sms = $this->invitationSms();
        $managerId = $sms->invitation->created_by;
        $result = $this->parallelFinance($this->invitationSmsWorkerCode(), [[$sms->id, 'send', $managerId], [$sms->id, 'revoke', $managerId]]);
        $this->assertContains($result, [['ACCEPTED', 'REVOKED'], ['REVOKED', 'SKIPPED']]);
        $this->assertSame('revoked', $sms->invitation->fresh()->status);
        $this->assertSame(1, $sms->invitation->organization->members()->count());
        $this->assertSame($sms->fresh()->status === 'accepted' ? 1 : 0, AuditEvent::where('action', 'fixture.sms_provider_call')->count());
    }

    private function packageSlot(ConsultationGrant $grant, int $day): ConsultationSlot
    {
        return ConsultationSlot::factory()->create(['coach_id' => $grant->coach_id, 'price' => 1000000, 'starts_at' => now()->addDays($day), 'ends_at' => now()->addDays($day)->addHour()]);
    }

    private function packageBookingCode(): string
    {
        return <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try {app(\App\Services\ConsultationService::class)->book(\App\Models\User::findOrFail((int)$argv[1]),\App\Models\ConsultationSlot::findOrFail((int)$argv[2]),'Package race',grantId:(int)$argv[3]);echo 'BOOKED';}catch(\Illuminate\Validation\ValidationException){echo 'BLOCKED';}
CODE;
    }

    public function test_one_package_credit_cannot_book_two_different_slots_concurrently(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Consultation grant races require PostgreSQL.');
        }
        $grant = ConsultationGrant::factory()->create(['total_sessions' => 1]);
        $first = $this->packageSlot($grant, 1);
        $second = $this->packageSlot($grant, 2);
        $this->assertSame(['BLOCKED', 'BOOKED'], $this->parallelFinance($this->packageBookingCode(), [[$grant->student_id, $first->id, $grant->id], [$grant->student_id, $second->id, $grant->id]]));
        $this->assertSame(1, ConsultationGrantUse::count());
        $this->assertSame(0, $grant->fresh()->remaining());
        $this->assertSame(1, Consultation::where('status', 'confirmed')->count());
        $this->assertSame(0, Payment::count());
    }

    public function test_competing_packages_share_one_consultation_slot_without_consuming_losing_credit(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Consultation grant races require PostgreSQL.');
        }
        $first = ConsultationGrant::factory()->create(['total_sessions' => 1]);
        $second = ConsultationGrant::factory()->create(['coach_id' => $first->coach_id, 'total_sessions' => 1]);
        $slot = $this->packageSlot($first, 1);
        $this->assertSame(['BLOCKED', 'BOOKED'], $this->parallelFinance($this->packageBookingCode(), [[$first->student_id, $slot->id, $first->id], [$second->student_id, $slot->id, $second->id]]));
        $this->assertSame(1, ConsultationGrantUse::count());
        $this->assertSame(1, $first->fresh()->remaining() + $second->fresh()->remaining());
        $this->assertSame(1, Consultation::count());
    }

    public function test_package_cancellation_racing_new_booking_preserves_exact_credit_balance(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Consultation grant races require PostgreSQL.');
        }
        $grant = ConsultationGrant::factory()->create(['total_sessions' => 1]);
        $booked = app(ConsultationService::class)->book($grant->student, $this->packageSlot($grant, 1), 'Initial package booking', grantId: $grant->id);
        $next = $this->packageSlot($grant, 2);
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();$g=\App\Models\ConsultationGrant::findOrFail((int)$argv[1]);$s=app(\App\Services\ConsultationService::class);
if($argv[2]==='cancel') {$s->change($g->student,\App\Models\Consultation::findOrFail((int)$argv[3]),1,'cancel','Cancellation race',null);echo 'CANCELLED';}
else {try {$s->book($g->student,\App\Models\ConsultationSlot::findOrFail((int)$argv[3]),'New booking',grantId:$g->id);echo 'BOOKED';}catch(\Illuminate\Validation\ValidationException){echo 'BLOCKED';}}
CODE;
        $result = $this->parallelFinance($code, [[$grant->id, 'cancel', $booked->id], [$grant->id, 'book', $next->id]]);
        $this->assertContains($result, [['BLOCKED', 'CANCELLED'], ['BOOKED', 'CANCELLED']]);
        $this->assertNotNull(ConsultationGrantUse::where('consultation_id', $booked->id)->sole()->released_at);
        $this->assertSame(in_array('BOOKED', $result, true) ? 0 : 1, $grant->fresh()->remaining());
        $this->assertSame('cancelled', $booked->fresh()->status);
    }

    public function test_coordinated_and_direct_booking_cannot_spend_same_last_package_credit(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Consultation grant races require PostgreSQL.');
        }
        $grant = ConsultationGrant::factory()->create(['total_sessions' => 1]);
        $request = app(ConsultationService::class)->request($grant->student, 'Coordinated package', 'Evenings', $grant->id);
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('key', 'admin')->value('id'));
        $first = $this->packageSlot($grant, 1);
        $second = $this->packageSlot($grant, 2);
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();$g=\App\Models\ConsultationGrant::findOrFail((int)$argv[1]);$s=app(\App\Services\ConsultationService::class);$slot=\App\Models\ConsultationSlot::findOrFail((int)$argv[3]);
try {if($argv[2]==='coordinate') {$s->book($g->student,$slot,'Coordinated',\App\Models\ConsultationRequest::findOrFail((int)$argv[4]),\App\Models\User::findOrFail((int)$argv[5]),1);}else{$s->book($g->student,$slot,'Direct',grantId:$g->id);}echo 'BOOKED';}catch(\Illuminate\Validation\ValidationException){echo 'BLOCKED';}
CODE;
        $this->assertSame(['BLOCKED', 'BOOKED'], $this->parallelFinance($code, [[$grant->id, 'coordinate', $first->id, $request->id, $admin->id], [$grant->id, 'direct', $second->id, $request->id, $admin->id]]));
        $this->assertSame(1, ConsultationGrantUse::count());
        $this->assertSame(0, $grant->fresh()->remaining());
        $this->assertSame(Consultation::sole()->consultation_slot_id === $first->id ? 'scheduled' : 'pending', $request->fresh()->status);
        $this->assertSame(0, Payment::count());
    }

    private function courseBenefitInvoice(bool $installments = false): array
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('key', 'admin')->value('id'));
        $coach = User::factory()->create();
        $coach->roles()->attach(Role::where('key', 'coach')->value('id'));
        $student = User::factory()->create();
        $student->roles()->attach(Role::where('key', 'student')->value('id'));
        $cohort = Cohort::factory()->create(['course_id' => Course::factory()->create(['price' => 1000000])->id, 'installment_enabled' => $installments]);
        if ($installments) {
            $cohort->installmentPlan()->createMany([['sequence' => 1, 'amount' => 300000, 'due_date' => now()->toDateString()], ['sequence' => 2, 'amount' => 300000, 'due_date' => now()->addMonth()->toDateString()], ['sequence' => 3, 'amount' => 400000, 'due_date' => now()->addMonths(2)->toDateString()]]);
        }
        app(CourseConsultationBenefits::class)->configure($admin, $cohort, ['revision' => 0, 'enabled' => true, 'coach_id' => $coach->id, 'title' => 'Course benefit race', 'sessions' => 2, 'session_minutes' => 60, 'valid_days' => 90, 'activation' => 'paid_in_full']);
        $enrollment = app(EnrollmentService::class)->reserve($student, $cohort, $installments ? 'installments' : 'full');

        return [$enrollment, $admin];
    }

    public function test_concurrent_last_course_installments_issue_exactly_one_consultation_grant(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Automatic course grant races require PostgreSQL.');
        }
        [$enrollment] = $this->courseBenefitInvoice(true);
        $invoices = $enrollment->installments()->orderBy('sequence')->get();
        $first = Payment::factory()->create(['installment_id' => $invoices[0]->id]);
        app(BillingService::class)->capture($first, 'FIRST-RACE');
        $arguments = [];
        foreach ([$invoices[1], $invoices[2]] as $invoice) {
            $payment = Payment::factory()->create(['installment_id' => $invoice->id]);
            $arguments[] = [$payment->id, 'LAST-RACE-'.$payment->id];
        }
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo app(\App\Services\BillingService::class)->capture(\App\Models\Payment::findOrFail((int)$argv[1]),$argv[2])?'PAID':'REVIEW';
CODE;
        $this->assertSame(['PAID', 'PAID'], $this->parallelFinance($code, $arguments));
        $this->assertSame(1, ConsultationGrant::count());
        $this->assertSame(3, PaymentAllocation::count());
        $this->assertSame('issued', EnrollmentConsultationBenefit::first()->status);
    }

    public function test_callback_racing_course_benefit_retry_never_duplicates_or_extends_credit(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Automatic course grant races require PostgreSQL.');
        }
        [$enrollment, $admin] = $this->courseBenefitInvoice();
        $payment = Payment::factory()->create(['installment_id' => $enrollment->installments()->first()->id]);
        $contract = EnrollmentConsultationBenefit::first();
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
if($argv[1]==='capture'){app(\App\Services\BillingService::class)->capture(\App\Models\Payment::findOrFail((int)$argv[2]),'RETRY-RACE');}
else{app(\App\Services\CourseConsultationBenefits::class)->retry(\App\Models\User::findOrFail((int)$argv[3]),\App\Models\EnrollmentConsultationBenefit::findOrFail((int)$argv[4]));}echo 'DONE';
CODE;
        $arguments = [['capture', $payment->id, $admin->id, $contract->id], ['retry', $payment->id, $admin->id, $contract->id]];
        $this->assertSame(['DONE', 'DONE'], $this->parallelFinance($code, $arguments));
        $expiry = ConsultationGrant::firstOrFail()->valid_until->toDateString();
        $this->assertSame(['DONE', 'DONE'], $this->parallelFinance($code, $arguments));
        $this->assertSame(1, ConsultationGrant::count());
        $this->assertSame(1, PaymentAllocation::count());
        $this->assertSame($expiry, ConsultationGrant::first()->valid_until->toDateString());
    }

    public function test_concurrent_course_benefit_repairs_issue_only_one_grant(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Automatic course grant races require PostgreSQL.');
        }
        [$enrollment, $admin] = $this->courseBenefitInvoice();
        $contract = EnrollmentConsultationBenefit::first();
        $coach = User::findOrFail($contract->coach_id);
        $coach->forceFill(['is_active' => false])->save();
        $payment = Payment::factory()->create(['installment_id' => $enrollment->installments()->first()->id]);
        app(BillingService::class)->capture($payment, 'BLOCKED-REPAIR-RACE');
        $this->assertSame('blocked', $contract->fresh()->status);
        $coach->forceFill(['is_active' => true])->save();
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo app(\App\Services\CourseConsultationBenefits::class)->retry(\App\Models\User::findOrFail((int)$argv[1]),\App\Models\EnrollmentConsultationBenefit::findOrFail((int)$argv[2]))->status;
CODE;
        $this->assertSame(['issued', 'issued'], $this->parallelFinance($code, [[$admin->id, $contract->id], [$admin->id, $contract->id]]));
        $this->assertSame(1, ConsultationGrant::count());
        $this->assertSame(1, AuditEvent::where('action', 'course_benefit.issued')->count());
    }

    public function test_simultaneous_package_checkout_creates_one_open_order(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Standalone package races require PostgreSQL.');
        }
        $product = ConsultationProduct::factory()->create();
        $student = User::factory()->create();
        $student->roles()->attach(Role::where('key', 'student')->value('id'));
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo app(\App\Services\PackageBilling::class)->reserve(\App\Models\User::findOrFail((int)$argv[1]),\App\Models\ConsultationProduct::findOrFail((int)$argv[2]),1)->id;
CODE;
        $result = $this->parallelFinance($code, [[$student->id, $product->id], [$student->id, $product->id]]);
        $this->assertSame($result[0], $result[1]);
        $this->assertSame(1, PackagePurchase::count());
    }

    public function test_two_captured_package_payments_issue_one_grant_and_preserve_extra_funds(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Standalone package races require PostgreSQL.');
        }
        $purchase = PackagePurchase::factory()->create();
        $arguments = [];
        foreach ([1, 2] as $number) {
            $payment = Payment::create(['package_purchase_id' => $purchase->id, 'enrollment_id' => null, 'user_id' => $purchase->student_id, 'amount' => $purchase->amount, 'method' => 'gateway', 'status' => 'pending']);
            $arguments[] = [$payment->id, 'PACKAGE-DOUBLE-'.$number];
        }
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo app(\App\Services\PackageBilling::class)->capture(\App\Models\Payment::findOrFail((int)$argv[1]),$argv[2])?'PAID':'REVIEW';
CODE;
        $this->assertSame(['PAID', 'REVIEW'], $this->parallelFinance($code, $arguments));
        $this->assertSame(1, ConsultationGrant::count());
        $this->assertSame(2, Payment::whereNotNull('captured_at')->count());
        $this->assertSame(1, Payment::where('review_reason', 'package_duplicate')->count());
        $this->assertSame(0, PaymentAllocation::count());
    }

    public function test_two_package_repair_requests_cannot_duplicate_grant(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Standalone package races require PostgreSQL.');
        }
        $purchase = PackagePurchase::factory()->create(['expires_at' => now()->subMinute()]);
        $payment = Payment::create(['package_purchase_id' => $purchase->id, 'enrollment_id' => null, 'user_id' => $purchase->student_id, 'amount' => $purchase->amount, 'method' => 'gateway', 'status' => 'pending']);
        app(PackageBilling::class)->capture($payment, 'PACKAGE-REPAIR');
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo app(\App\Services\PackageBilling::class)->resolve(\App\Models\User::findOrFail((int)$argv[1]),\App\Models\Payment::findOrFail((int)$argv[2]))?'PAID':'REVIEW';
CODE;
        $this->assertSame(['PAID', 'PAID'], $this->parallelFinance($code, [[$purchase->reviewed_by, $payment->id], [$purchase->reviewed_by, $payment->id]]));
        $this->assertSame(1, ConsultationGrant::count());
        $this->assertSame(1, AuditEvent::where('action', 'package_purchase.paid')->count());
    }

    public function test_package_refund_request_racing_fulfillment_cannot_both_succeed(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Standalone package races require PostgreSQL.');
        }
        $purchase = PackagePurchase::factory()->create(['expires_at' => now()->subMinute()]);
        $payment = Payment::create(['package_purchase_id' => $purchase->id, 'enrollment_id' => null, 'user_id' => $purchase->student_id, 'amount' => $purchase->amount, 'method' => 'gateway', 'status' => 'pending']);
        app(PackageBilling::class)->capture($payment, 'PACKAGE-REFUND-RACE');
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();$payment=\App\Models\Payment::findOrFail((int)$argv[1]);
try {if($argv[2]==='refund'){app(\App\Services\RefundService::class)->request($payment->user,$payment,'IR062960000000100324200001','Package refund race',0);}else{app(\App\Services\PackageBilling::class)->resolve(\App\Models\User::findOrFail((int)$argv[3]),$payment);}echo 'DONE';}catch(\Illuminate\Validation\ValidationException){echo 'BLOCKED';}
CODE;
        $this->assertSame(['BLOCKED', 'DONE'], $this->parallelFinance($code, [[$payment->id, 'refund', $purchase->reviewed_by], [$payment->id, 'fulfill', $purchase->reviewed_by]]));
        $this->assertSame(1, Refund::count() + ConsultationGrant::count());
        $this->assertSame(Refund::exists() ? 'review_required' : 'paid', $payment->fresh()->status);
    }

    private function certificationRequest(): array
    {
        $request = CertificateRequest::factory()->create();
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('key', 'admin')->value('id'));
        foreach ($request->definition['lessons'] as $lesson) {
            LessonProgress::create(['enrollment_id' => $request->enrollment_id, 'lesson_id' => $lesson['id'], 'completed_at' => now()]);
        }

        return [$request, $admin];
    }

    public function test_concurrent_certificate_requests_keep_one_pending_application(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Certificate lifecycle races require PostgreSQL.');
        }
        [$request] = $this->certificationRequest();
        $enrollment = $request->enrollment;
        $request->delete();
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();$enrollment=\App\Models\Enrollment::findOrFail((int)$argv[1]);
echo app(\App\Services\Certification::class)->request($enrollment->user,$enrollment,1,$enrollment->user->name)->id;
CODE;
        $result = $this->parallelFinance($code, [[$enrollment->id], [$enrollment->id]]);
        $this->assertSame($result[0], $result[1]);
        $this->assertSame(1, CertificateRequest::count());
        $this->assertSame(1, AuditEvent::where('action', 'certificate.requested')->count());
    }

    public function test_simultaneous_certificate_approvals_issue_one_document_and_token(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Certificate lifecycle races require PostgreSQL.');
        }
        [$request, $admin] = $this->certificationRequest();
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo app(\App\Services\Certification::class)->review(\App\Models\User::findOrFail((int)$argv[1]),\App\Models\CertificateRequest::findOrFail((int)$argv[2]),1,'issue','Educational evidence reviewed')->verification_token;
CODE;
        $result = $this->parallelFinance($code, [[$admin->id, $request->id], [$admin->id, $request->id]]);
        $this->assertSame($result[0], $result[1]);
        $this->assertSame(1, CourseCertificate::count());
        $this->assertSame(1, AuditEvent::where('action', 'certificate.issued')->count());
    }

    public function test_certificate_issue_and_grade_review_share_one_enrollment_lock(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Certificate lifecycle races require PostgreSQL.');
        }
        [$request, $admin] = $this->certificationRequest();
        $assignment = Assignment::factory()->create(['cohort_id' => $request->enrollment->cohort_id]);
        $submission = Submission::factory()->create(['assignment_id' => $assignment->id, 'enrollment_id' => $request->enrollment_id, 'status' => 'reviewed', 'score' => 100, 'reviewed_at' => now()]);
        $definition = $request->definition;
        $definition['assignments'] = [$assignment->only('id', 'title', 'max_score')];
        $request->update(['definition' => $definition]);
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();$request=\App\Models\CertificateRequest::findOrFail((int)$argv[1]);
if($argv[2]==='issue'){try{app(\App\Services\Certification::class)->review(\App\Models\User::findOrFail((int)$argv[3]),$request,1,'issue','Reviewed educational evidence');echo 'ISSUED';}catch(\Illuminate\Validation\ValidationException){echo 'BLOCKED';}}
else{$review=\Illuminate\Http\Request::create('/submissions/'.$argv[4].'/review','PATCH',['status'=>'reviewed','score'=>0,'feedback'=>'Regraded educational evidence','expected_review_version'=>0]);$review->setUserResolver(fn()=>\App\Models\User::findOrFail((int)$argv[3]));app(\App\Http\Controllers\SubmissionController::class)->review($review,\App\Models\Submission::findOrFail((int)$argv[4]),app(\App\Services\LearningAccess::class));echo 'CHANGED';}
CODE;
        $result = $this->parallelFinance($code, [[$request->id, 'issue', $admin->id, $submission->id], [$request->id, 'grade', $admin->id, $submission->id]]);
        $this->assertContains('CHANGED', $result);
        $this->assertSame(0, $submission->fresh()->score);
        $this->assertSame(in_array('ISSUED', $result, true) ? 1 : 0, CourseCertificate::count());
        if (CourseCertificate::exists()) {
            $this->assertTrue(CourseCertificate::first()->evidence['eligible']);
        } else {
            $this->assertSame('pending', $request->fresh()->status);
        }
    }

    public function test_concurrent_certificate_revocations_record_one_terminal_change(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Certificate lifecycle races require PostgreSQL.');
        }
        [$request, $admin] = $this->certificationRequest();
        $certificate = app(Certification::class)->review($admin, $request, 1, 'issue', 'Evidence reviewed');
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try{app(\App\Services\Certification::class)->revoke(\App\Models\User::findOrFail((int)$argv[1]),\App\Models\CourseCertificate::findOrFail((int)$argv[2]),1,'Reviewed revocation');echo 'REVOKED';}catch(\Illuminate\Validation\ValidationException){echo 'STALE';}
CODE;
        $this->assertSame(['REVOKED', 'STALE'], $this->parallelFinance($code, [[$admin->id, $certificate->id], [$admin->id, $certificate->id]]));
        $this->assertSame('revoked', $certificate->fresh()->status);
        $this->assertSame(1, AuditEvent::where('action', 'certificate.revoked')->count());
    }

    private function discussionFixture(): array
    {
        $coach = User::factory()->create();
        $coach->roles()->attach(Role::where('key', 'coach')->value('id'));
        $student = User::factory()->create();
        $student->roles()->attach(Role::where('key', 'student')->value('id'));
        $cohort = Cohort::factory()->create(['course_id' => Course::factory()->create(['coach_id' => $coach->id])->id]);
        Enrollment::factory()->create(['cohort_id' => $cohort->id, 'user_id' => $student->id]);

        return [$student, $coach, $cohort];
    }

    public function test_simultaneous_discussion_creation_is_one_topic_post_and_notification(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Discussion lifecycle races require PostgreSQL.');
        }
        [$student, $coach, $cohort] = $this->discussionFixture();
        $key = (string) Str::uuid();
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo app(\App\Services\CourseDiscussions::class)->create(\App\Models\User::findOrFail((int)$argv[1]),\App\Models\Cohort::findOrFail((int)$argv[2]),['title'=>'Concurrent question','body'=>'Shared classroom message','kind'=>'question','request_key'=>$argv[3]])->id;
CODE;
        $result = $this->parallelFinance($code, [[$student->id, $cohort->id, $key], [$student->id, $cohort->id, $key]]);
        $this->assertSame($result[0], $result[1]);
        $this->assertSame(1, CourseDiscussion::count());
        $this->assertSame(1, DiscussionPost::count());
        $this->assertSame(1, $coach->notifications()->count());
    }

    public function test_simultaneous_discussion_reply_retries_create_one_post_and_revision(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Discussion lifecycle races require PostgreSQL.');
        }
        [$student, $coach, $cohort] = $this->discussionFixture();
        $discussion = app(CourseDiscussions::class)->create($student, $cohort, ['title' => 'Question', 'body' => 'Question body', 'kind' => 'question', 'request_key' => (string) Str::uuid()]);
        $key = (string) Str::uuid();
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo app(\App\Services\CourseDiscussions::class)->reply(\App\Models\User::findOrFail((int)$argv[1]),\App\Models\CourseDiscussion::findOrFail((int)$argv[2]),'One coach reply',$argv[3])->id;
CODE;
        $result = $this->parallelFinance($code, [[$coach->id, $discussion->id, $key], [$coach->id, $discussion->id, $key]]);
        $this->assertSame($result[0], $result[1]);
        $this->assertSame(2, DiscussionPost::count());
        $this->assertSame(2, DiscussionPostRevision::count());
        $this->assertSame(1, $student->notifications()->count());
    }

    public function test_competing_discussion_body_edits_preserve_one_winning_version(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Discussion lifecycle races require PostgreSQL.');
        }
        [$student, $coach, $cohort] = $this->discussionFixture();
        $discussion = app(CourseDiscussions::class)->create($student, $cohort, ['title' => 'Question', 'body' => 'Original body', 'kind' => 'question', 'request_key' => (string) Str::uuid()]);
        $post = $discussion->posts()->first();
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try{app(\App\Services\CourseDiscussions::class)->edit(\App\Models\User::findOrFail((int)$argv[1]),\App\Models\DiscussionPost::findOrFail((int)$argv[2]),1,$argv[3]);echo 'EDITED';}catch(\Illuminate\Validation\ValidationException){echo 'STALE';}
CODE;
        $this->assertSame(['EDITED', 'STALE'], $this->parallelFinance($code, [[$student->id, $post->id, 'First edit'], [$student->id, $post->id, 'Second edit']]));
        $this->assertSame(2, $post->fresh()->revision);
        $this->assertSame(2, $post->revisions()->count());
        $this->assertSame('Original body', $post->revisions()->where('revision', 1)->value('body'));
    }

    public function test_hiding_a_reply_racing_answer_selection_never_leaves_hidden_accepted_answer(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Discussion lifecycle races require PostgreSQL.');
        }
        [$student, $coach, $cohort] = $this->discussionFixture();
        $discussion = app(CourseDiscussions::class)->create($student, $cohort, ['title' => 'Question', 'body' => 'Question body', 'kind' => 'question', 'request_key' => (string) Str::uuid()]);
        $post = app(CourseDiscussions::class)->reply($coach, $discussion, 'Coach answer', (string) Str::uuid());
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();$service=app(\App\Services\CourseDiscussions::class);$user=\App\Models\User::findOrFail((int)$argv[1]);$discussion=\App\Models\CourseDiscussion::findOrFail((int)$argv[2]);$post=\App\Models\DiscussionPost::findOrFail((int)$argv[3]);
if($argv[4]==='hide'){$service->moderatePost($user,$post,1,true,'Reviewed hide');echo 'HIDDEN';}else{try{$service->accept($user,$discussion,1,$post->id);echo 'SELECTED';}catch(\Illuminate\Validation\ValidationException){echo 'BLOCKED';}}
CODE;
        $result = $this->parallelFinance($code, [[$coach->id, $discussion->id, $post->id, 'hide'], [$student->id, $discussion->id, $post->id, 'accept']]);
        $this->assertContains('HIDDEN', $result);
        $this->assertTrue($post->fresh()->hidden);
        $this->assertNull($discussion->fresh()->accepted_post_id);
    }

    public function test_discussion_lock_racing_reply_has_a_consistent_final_state(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Discussion lifecycle races require PostgreSQL.');
        }
        [$student, $coach, $cohort] = $this->discussionFixture();
        $discussion = app(CourseDiscussions::class)->create($student, $cohort, ['title' => 'Question', 'body' => 'Question body', 'kind' => 'question', 'request_key' => (string) Str::uuid()]);
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();$service=app(\App\Services\CourseDiscussions::class);$user=\App\Models\User::findOrFail((int)$argv[1]);$discussion=\App\Models\CourseDiscussion::findOrFail((int)$argv[2]);
if($argv[3]==='lock'){$service->moderate($user,$discussion,['revision'=>1,'hidden'=>false,'pinned'=>false,'locked'=>true,'reason'=>'Close classroom topic']);echo 'LOCKED';}else{try{$service->reply($user,$discussion,'Concurrent reply',$argv[4]);echo 'POSTED';}catch(\Illuminate\Validation\ValidationException){echo 'BLOCKED';}}
CODE;
        $result = $this->parallelFinance($code, [[$coach->id, $discussion->id, 'lock', (string) Str::uuid()], [$student->id, $discussion->id, 'reply', (string) Str::uuid()]]);
        $this->assertContains('LOCKED', $result);
        $this->assertTrue($discussion->fresh()->locked);
        $this->assertSame(in_array('POSTED', $result, true) ? 2 : 1, DiscussionPost::count());
    }

    public function test_concurrent_survey_start_returns_one_enrollment_response(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Survey lifecycle races require PostgreSQL.');
        }
        $response = SurveyResponse::factory()->create();
        $publication = $response->publication;
        $student = $response->enrollment->user;
        $response->delete();
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo app(\App\Services\CourseSurveys::class)->start(\App\Models\User::findOrFail((int)$argv[1]),\App\Models\SurveyPublication::findOrFail((int)$argv[2]))->id;
CODE;
        $result = $this->parallelFinance($code, [[$student->id, $publication->id], [$student->id, $publication->id]]);
        $this->assertSame($result[0], $result[1]);
        $this->assertSame(1, SurveyResponse::count());
    }

    public function test_competing_survey_draft_saves_cannot_overwrite_newer_response(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Survey lifecycle races require PostgreSQL.');
        }
        $response = SurveyResponse::factory()->create();
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();$response=\App\Models\SurveyResponse::findOrFail((int)$argv[1]);
try{app(\App\Services\CourseSurveys::class)->save($response->enrollment->user,$response,1,['quality'=>(int)$argv[2]],false);echo 'SAVED';}catch(\Illuminate\Validation\ValidationException){echo 'STALE';}
CODE;
        $this->assertSame(['SAVED', 'STALE'], $this->parallelFinance($code, [[$response->id, 1], [$response->id, 5]]));
        $this->assertSame(2, $response->fresh()->revision);
        $this->assertContains($response->fresh()->answers['quality'], [1, 5]);
    }

    public function test_competing_survey_publications_keep_one_immutable_version(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Survey lifecycle races require PostgreSQL.');
        }
        $survey = CourseSurvey::factory()->create();
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('key', 'admin')->value('id'));
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try{app(\App\Services\CourseSurveys::class)->publish(\App\Models\User::findOrFail((int)$argv[1]),\App\Models\CourseSurvey::findOrFail((int)$argv[2]),1,null,null);echo 'PUBLISHED';}catch(\Illuminate\Validation\ValidationException){echo 'STALE';}
CODE;
        $this->assertSame(['PUBLISHED', 'STALE'], $this->parallelFinance($code, [[$admin->id, $survey->id], [$admin->id, $survey->id]]));
        $this->assertSame(1, SurveyPublication::count());
        $this->assertSame(1, $survey->fresh()->current_version);
    }

    public function test_survey_submission_racing_closure_has_one_consistent_outcome(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Survey lifecycle races require PostgreSQL.');
        }
        $response = SurveyResponse::factory()->create();
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('key', 'admin')->value('id'));
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();$response=\App\Models\SurveyResponse::findOrFail((int)$argv[1]);$service=app(\App\Services\CourseSurveys::class);
if($argv[2]==='close'){$service->receiving(\App\Models\User::findOrFail((int)$argv[3]),$response->publication,1,false);echo 'CLOSED';}else{try{$service->save($response->enrollment->user,$response,1,['quality'=>4],true);echo 'SUBMITTED';}catch(\Illuminate\Validation\ValidationException){echo 'BLOCKED';}}
CODE;
        $result = $this->parallelFinance($code, [[$response->id, 'close', $admin->id], [$response->id, 'submit', $admin->id]]);
        $this->assertContains('CLOSED', $result);
        $this->assertFalse($response->publication->fresh()->accepting);
        $this->assertSame(in_array('SUBMITTED', $result, true) ? 'submitted' : 'in_progress', $response->fresh()->status);
    }

    public function test_simultaneous_support_creation_with_same_uuid_creates_one_private_thread(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Private support concurrency requires PostgreSQL.');
        }
        $owner = User::factory()->create();
        $key = (string) Str::uuid();
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo app(\App\Services\SupportDesk::class)->create(\App\Models\User::findOrFail((int)$argv[1]),['subject'=>'Private question','category'=>'technical','priority'=>'normal','body'=>'Same content','request_key'=>$argv[2]],null)->id;
CODE;
        $results = $this->parallelFinance($code, [[$owner->id, $key], [$owner->id, $key]]);
        $this->assertSame($results[0], $results[1]);
        $this->assertSame(1, SupportTicket::count());
        $this->assertSame(1, TicketMessage::count());
    }

    public function test_simultaneous_private_replies_with_same_key_create_one_message(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Private support concurrency requires PostgreSQL.');
        }
        $ticket = SupportTicket::factory()->create();
        $key = (string) Str::uuid();
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();$ticket=\App\Models\SupportTicket::findOrFail((int)$argv[1]);
app(\App\Services\SupportDesk::class)->reply($ticket->owner,$ticket,['body'=>'Concurrent private reply','request_key'=>$argv[2]],null);echo 'SENT';
CODE;
        $this->assertSame(['SENT', 'SENT'], $this->parallelFinance($code, [[$ticket->id, $key], [$ticket->id, $key]]));
        $this->assertSame(1, TicketMessage::count());
        $this->assertSame(2, $ticket->fresh()->revision);
    }

    public function test_support_reply_racing_closure_has_consistent_status_and_history(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Private support concurrency requires PostgreSQL.');
        }
        $ticket = SupportTicket::factory()->create();
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('key', 'admin')->value('id'));
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();$ticket=\App\Models\SupportTicket::findOrFail((int)$argv[1]);$service=app(\App\Services\SupportDesk::class);
try{if($argv[2]==='close'){$service->change(\App\Models\User::findOrFail((int)$argv[3]),$ticket,['revision'=>1,'action'=>'close','reason'=>'Resolved by staff']);echo 'CLOSED';}else{$service->reply($ticket->owner,$ticket,['body'=>'Owner reply','request_key'=>$argv[4]],null);echo 'SENT';}}catch(\Illuminate\Validation\ValidationException){echo 'BLOCKED';}
CODE;
        $results = $this->parallelFinance($code, [[$ticket->id, 'close', $admin->id, (string) Str::uuid()], [$ticket->id, 'reply', $admin->id, (string) Str::uuid()]]);
        $this->assertContains('BLOCKED', $results);
        $this->assertSame(in_array('CLOSED', $results, true) ? 'closed' : 'waiting_staff', $ticket->fresh()->status);
        $this->assertSame(in_array('SENT', $results, true) ? 1 : 0, TicketMessage::count());
        $this->assertSame(in_array('CLOSED', $results, true) ? 1 : 0, TicketEvent::count());
    }

    public function test_competing_support_assignments_cannot_overwrite_newer_revision(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Private support concurrency requires PostgreSQL.');
        }
        $ticket = SupportTicket::factory()->create();
        $admins = User::factory()->count(2)->create();
        foreach ($admins as $admin) {
            $admin->roles()->attach(Role::where('key', 'admin')->value('id'));
        }
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();$ticket=\App\Models\SupportTicket::findOrFail((int)$argv[1]);
try{app(\App\Services\SupportDesk::class)->change(\App\Models\User::findOrFail((int)$argv[2]),$ticket,['revision'=>1,'action'=>'assign','assigned_to'=>(int)$argv[2],'reason'=>'Private routing']);echo 'ASSIGNED';}catch(\Illuminate\Validation\ValidationException){echo 'STALE';}
CODE;
        $this->assertSame(['ASSIGNED', 'STALE'], $this->parallelFinance($code, [[$ticket->id, $admins[0]->id], [$ticket->id, $admins[1]->id]]));
        $this->assertSame(2, $ticket->fresh()->revision);
        $this->assertSame(1, TicketEvent::count());
    }

    public function test_simultaneous_membership_exit_records_one_departure(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Membership lifecycle concurrency requires PostgreSQL.');
        }
        $organization = Organization::factory()->create();
        $student = User::factory()->create();
        $student->roles()->attach(Role::where('key', 'student')->value('id'));
        $organization->members()->attach($student->id, ['kind' => 'member']);
        $key = $organization->members()->firstOrFail()->pivot->membership_key;
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();$user=\App\Models\User::findOrFail((int)$argv[1]);
try{app(\App\Services\OrganizationMembership::class)->endMembership($user,\App\Models\Organization::findOrFail((int)$argv[2]),$user,$argv[3],'Exit consent','leave');echo 'LEFT';}catch(\Illuminate\Validation\ValidationException){echo 'STALE';}
CODE;
        $this->assertSame(['LEFT', 'STALE'], $this->parallelFinance($code, [[$student->id, $organization->id, $key], [$student->id, $organization->id, $key]]));
        $this->assertSame(0, $organization->members()->count());
        $this->assertSame(1, AuditEvent::where('action', 'organization_membership.leave')->count());
    }

    public function test_competing_manager_revocations_preserve_one_current_usable_manager(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Membership lifecycle concurrency requires PostgreSQL.');
        }
        $organization = Organization::factory()->create();
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('key', 'admin')->value('id'));
        $managers = User::factory()->count(2)->create();
        foreach ($managers as $manager) {
            $manager->roles()->attach(Role::where('key', 'org_admin')->value('id'));
            $organization->members()->attach($manager->id, ['kind' => 'manager']);
        }
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try{app(\App\Services\OrganizationMembership::class)->endMembership(\App\Models\User::findOrFail((int)$argv[1]),\App\Models\Organization::findOrFail((int)$argv[2]),\App\Models\User::findOrFail((int)$argv[3]),$argv[4],'Replace manager','revoke_manager');echo 'REVOKED';}catch(\Illuminate\Validation\ValidationException){echo 'BLOCKED';}
CODE;
        $args = $organization->members()->get()->map(fn ($manager) => [$admin->id, $organization->id, $manager->id, $manager->pivot->membership_key])->all();
        $this->assertSame(['BLOCKED', 'REVOKED'], $this->parallelFinance($code, $args));
        $this->assertSame(1, $organization->members()->wherePivot('kind', 'manager')->count());
        $this->assertSame(1, AuditEvent::where('action', 'organization_membership.revoke_manager')->count());
    }

    public function test_group_enrollment_racing_departure_never_restores_membership_or_loses_ledger(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Membership lifecycle concurrency requires PostgreSQL.');
        }
        $license = OrganizationLicense::factory()->create();
        $organization = $license->organization;
        $manager = User::factory()->create();
        $manager->roles()->attach(Role::where('key', 'org_admin')->value('id'));
        $student = User::factory()->create();
        $student->roles()->attach(Role::where('key', 'student')->value('id'));
        $organization->members()->attach($manager->id, ['kind' => 'manager']);
        $organization->members()->attach($student->id, ['kind' => 'member']);
        $key = $organization->members()->where('users.id', $student->id)->firstOrFail()->pivot->membership_key;
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();$license=\App\Models\OrganizationLicense::findOrFail((int)$argv[1]);$student=\App\Models\User::findOrFail((int)$argv[2]);
if($argv[3]==='leave'){app(\App\Services\OrganizationMembership::class)->endMembership($student,$license->organization,$student,$argv[5],'Leave organization','leave');echo 'LEFT';}else{try{app(\App\Services\OrganizationEnrollment::class)->enroll(\App\Models\User::findOrFail((int)$argv[4]),$license->organization,$license,1,[$student->id]);echo 'ENROLLED';}catch(\Symfony\Component\HttpKernel\Exception\HttpException|\Illuminate\Validation\ValidationException){echo 'BLOCKED';}}
CODE;
        $result = $this->parallelFinance($code, [[$license->id, $student->id, 'leave', $manager->id, $key], [$license->id, $student->id, 'enroll', $manager->id, $key]]);
        $this->assertContains('LEFT', $result);
        $this->assertFalse($organization->members()->where('users.id', $student->id)->exists());
        $this->assertSame(in_array('ENROLLED', $result, true) ? 1 : 0, OrganizationSeat::count());
        $this->assertSame(OrganizationSeat::count(), Enrollment::where('user_id', $student->id)->where('status', 'active')->count());
    }

    private function contentAdmin(): User
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('key', 'admin')->value('id'));

        return $admin;
    }

    public function test_concurrent_content_publication_creates_one_immutable_snapshot(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Content publication concurrency requires PostgreSQL.');
        }
        $admin = $this->contentAdmin();
        $page = ContentPage::factory()->create();
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try{app(\App\Services\ContentPublishing::class)->publish(\App\Models\User::findOrFail((int)$argv[1]),\App\Models\ContentPage::findOrFail((int)$argv[2]),1);echo 'PUBLISHED';}catch(\Illuminate\Validation\ValidationException){echo 'STALE';}
CODE;
        $this->assertSame(['PUBLISHED', 'STALE'], $this->parallelFinance($code, [[$admin->id, $page->id], [$admin->id, $page->id]]));
        $this->assertSame(1, ContentPublication::count());
        $this->assertSame(1, $page->fresh()->current_version);
    }

    public function test_competing_content_drafts_cannot_overwrite_a_newer_revision(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Content publication concurrency requires PostgreSQL.');
        }
        $admin = $this->contentAdmin();
        $page = ContentPage::factory()->create();
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();$page=\App\Models\ContentPage::findOrFail((int)$argv[2]);
try{app(\App\Services\ContentPublishing::class)->draft(\App\Models\User::findOrFail((int)$argv[1]),$page,1,[...$page->draft,'title'=>$argv[3]]);echo 'SAVED';}catch(\Illuminate\Validation\ValidationException){echo 'STALE';}
CODE;
        $this->assertSame(['SAVED', 'STALE'], $this->parallelFinance($code, [[$admin->id, $page->id, 'First draft'], [$admin->id, $page->id, 'Second draft']]));
        $this->assertSame(2, $page->fresh()->revision);
        $this->assertContains($page->fresh()->draft['title'], ['First draft', 'Second draft']);
    }

    public function test_publication_racing_withdrawal_has_one_explicit_winning_revision(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Content publication concurrency requires PostgreSQL.');
        }
        $admin = $this->contentAdmin();
        $page = ContentPage::factory()->create();
        $publishing = app(ContentPublishing::class);
        $publishing->publish($admin, $page, 1);
        $publishing->draft($admin, $page, 2, [...$page->draft, 'title' => 'New public version']);
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();$page=\App\Models\ContentPage::findOrFail((int)$argv[2]);$admin=\App\Models\User::findOrFail((int)$argv[1]);$service=app(\App\Services\ContentPublishing::class);
try{if($argv[3]==='publish'){$service->publish($admin,$page,3);echo 'PUBLISHED';}else{$service->availability($admin,$page,3,false);echo 'WITHDRAWN';}}catch(\Illuminate\Validation\ValidationException){echo 'STALE';}
CODE;
        $result = $this->parallelFinance($code, [[$admin->id, $page->id, 'publish'], [$admin->id, $page->id, 'withdraw']]);
        $this->assertContains('STALE', $result);
        $published = in_array('PUBLISHED', $result, true);
        $this->assertSame($published, $page->fresh()->available);
        $this->assertSame($published ? 2 : 1, $page->publications()->count());
        $this->assertSame(4, $page->fresh()->revision);
    }

    public function test_simultaneous_content_creation_uses_one_slug_without_partial_audits(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Content publication concurrency requires PostgreSQL.');
        }
        $admin = $this->contentAdmin();
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try{app(\App\Services\ContentPublishing::class)->create(\App\Models\User::findOrFail((int)$argv[1]),'article','unique-public-article',['title'=>'Article','excerpt'=>'Summary','body'=>'Public content']);echo 'CREATED';}catch(\Illuminate\Validation\ValidationException){echo 'DUPLICATE';}
CODE;
        $this->assertSame(['CREATED', 'DUPLICATE'], $this->parallelFinance($code, [[$admin->id], [$admin->id]]));
        $this->assertSame(1, ContentPage::count());
        $this->assertSame(1, AuditEvent::where('action', 'content.created')->count());
    }

    public function test_competing_resume_saves_cannot_overwrite_newer_private_data(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Career resume concurrency requires PostgreSQL.');
        }
        $resume = CareerResume::factory()->create();
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();$resume=\App\Models\CareerResume::findOrFail((int)$argv[1]);
try{app(\App\Services\ResumeBuilder::class)->save($resume->owner,$resume,1,$resume->title,[...$resume->draft,'headline'=>$argv[2]]);echo 'SAVED';}catch(\Illuminate\Validation\ValidationException){echo 'STALE';}
CODE;
        $this->assertSame(['SAVED', 'STALE'], $this->parallelFinance($code, [[$resume->id, 'First role'], [$resume->id, 'Second role']]));
        $this->assertSame(2, $resume->fresh()->revision);
        $this->assertContains($resume->fresh()->draft['headline'], ['First role', 'Second role']);
    }

    public function test_simultaneous_resume_snapshot_requests_create_one_numbered_version(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Career resume concurrency requires PostgreSQL.');
        }
        $resume = CareerResume::factory()->create();
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();$resume=\App\Models\CareerResume::findOrFail((int)$argv[1]);
try{app(\App\Services\ResumeBuilder::class)->snapshot($resume->owner,$resume,1);echo 'CREATED';}catch(\Illuminate\Validation\ValidationException){echo 'STALE';}
CODE;
        $this->assertSame(['CREATED', 'STALE'], $this->parallelFinance($code, [[$resume->id], [$resume->id]]));
        $this->assertSame(1, ResumeVersion::count());
        $this->assertSame(1, $resume->fresh()->current_version);
    }

    public function test_duplicate_resume_reviews_share_one_encrypted_analysis_and_audit(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Career resume concurrency requires PostgreSQL.');
        }
        $version = ResumeVersion::factory()->create();
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();$version=\App\Models\ResumeVersion::findOrFail((int)$argv[1]);
echo app(\App\Services\ResumeTextReview::class)->check($version->resume->owner,$version,'Laravel SQL employer')->id;
CODE;
        $result = $this->parallelFinance($code, [[$version->id], [$version->id]]);
        $this->assertSame($result[0], $result[1]);
        $this->assertSame(1, ResumeCheck::count());
        $this->assertSame(1, AuditEvent::where('action', 'resume.text_reviewed')->count());
    }

    public function test_resume_archiving_racing_snapshot_has_one_consistent_winning_revision(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Career resume concurrency requires PostgreSQL.');
        }
        $resume = CareerResume::factory()->create();
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();$resume=\App\Models\CareerResume::findOrFail((int)$argv[1]);$service=app(\App\Services\ResumeBuilder::class);
try{if($argv[2]==='archive'){$service->archive($resume->owner,$resume,1,true);echo 'ARCHIVED';}else{$service->snapshot($resume->owner,$resume,1);echo 'CREATED';}}catch(\Illuminate\Validation\ValidationException){echo 'STALE';}
CODE;
        $result = $this->parallelFinance($code, [[$resume->id, 'archive'], [$resume->id, 'snapshot']]);
        $this->assertContains('STALE', $result);
        $this->assertSame(in_array('ARCHIVED', $result, true), $resume->fresh()->archived);
        $this->assertSame(in_array('CREATED', $result, true) ? 1 : 0, ResumeVersion::count());
        $this->assertSame(2, $resume->fresh()->revision);
    }

    public function test_concurrent_delivery_workers_perform_one_authenticated_status_query(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Communication concurrency requires PostgreSQL.');
        }
        config(['platform.sms.driver' => 'melipayamak', 'platform.sms.melipayamak.mode' => 'rest', 'platform.sms.melipayamak.username' => 'race-fixture-sender', 'platform.sms.melipayamak.api_key' => 'race-fixture-key']);
        $delivery = SmsDelivery::factory()->create();
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
config(['platform.sms.driver'=>'melipayamak','platform.sms.melipayamak.mode'=>'rest','platform.sms.melipayamak.username'=>'race-fixture-sender','platform.sms.melipayamak.api_key'=>'race-fixture-key','platform.sms.melipayamak.delivery_enabled'=>true]);
\Illuminate\Support\Facades\Http::preventStrayRequests();\Illuminate\Support\Facades\Http::fake(['https://rest.payamak-panel.com/api/SendSMS/GetDeliveries2'=>function(){usleep(150000);return \Illuminate\Support\Facades\Http::response(['RetStatus'=>1,'Value'=>'1']);}]);
app(\App\Services\SmsDeliveryService::class)->poll((int)$argv[1],app(\App\Services\MelipayamakClient::class));echo 'CHECKED';
CODE;
        $this->assertSame(['CHECKED', 'CHECKED'], $this->parallelFinance($code, [[$delivery->id], [$delivery->id]]));
        $this->assertSame(1, SmsDeliveryAttempt::count());
        $this->assertSame('delivered', $delivery->fresh()->status);
        $this->assertSame(1, $delivery->fresh()->attempts);
    }

    public function test_concurrent_reminder_generators_create_one_notification_per_occurrence(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Communication concurrency requires PostgreSQL.');
        }
        $consultation = Consultation::factory()->create(['consultation_slot_id' => ConsultationSlot::factory()->state(['starts_at' => now()->addMinutes(30), 'ends_at' => now()->addMinutes(90)])]);
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();config(['platform.reminders.enabled'=>true,'platform.reminders.lead_minutes'=>60,'platform.sms.melipayamak.reminders_enabled'=>false]);
echo app(\App\Services\EventReminders::class)->prepare('consultation',(int)$argv[1],(int)$argv[2])?'CREATED':'EXISTING';
CODE;
        $this->assertSame(['CREATED', 'EXISTING'], $this->parallelFinance($code, [[$consultation->id, $consultation->student_id], [$consultation->id, $consultation->student_id]]));
        $this->assertSame(1, EventReminder::count());
        $this->assertSame(1, $consultation->student->notifications()->count());
    }

    public function test_concurrent_reminder_workers_attempt_one_sms_submission(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Communication concurrency requires PostgreSQL.');
        }
        $reminder = EventReminder::factory()->create();
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();config(['platform.reminders.enabled'=>true,'platform.reminders.lead_minutes'=>60,'platform.sms.driver'=>'melipayamak','platform.sms.melipayamak.mode'=>'rest','platform.sms.melipayamak.username'=>'race-fixture-sender','platform.sms.melipayamak.api_key'=>'race-fixture-key','platform.sms.melipayamak.reminder_body_id'=>123,'platform.sms.melipayamak.reminders_enabled'=>true]);
\Illuminate\Support\Facades\Http::preventStrayRequests();\Illuminate\Support\Facades\Http::fake(['https://rest.payamak-panel.com/api/SendSMS/BaseServiceNumber'=>function() use($argv){\App\Models\AuditEvent::create(['action'=>'fixture.sms_http_attempt','subject_type'=>'event_reminder','subject_id'=>(int)$argv[1]]);usleep(150000);return \Illuminate\Support\Facades\Http::response(['RetStatus'=>1,'Value'=>'12345678901234567']);}]);
app(\App\Services\ReminderSmsService::class)->send((int)$argv[1],app(\App\Services\MelipayamakClient::class),app(\App\Services\EventReminders::class));echo 'DONE';
CODE;
        $this->assertSame(['DONE', 'DONE'], $this->parallelFinance($code, [[$reminder->id], [$reminder->id]]));
        $this->assertSame(1, AuditEvent::where('action', 'fixture.sms_http_attempt')->count());
        $this->assertSame('accepted', $reminder->fresh()->sms_status);
        $this->assertSame(1, SmsDelivery::count());
    }

    public function test_duplicate_provider_reference_registration_cannot_bind_two_message_origins(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Communication concurrency requires PostgreSQL.');
        }
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try{app(\App\Services\SmsDeliveryService::class)->register('invitation',(int)$argv[1],'12345678901234567',['mode'=>'rest','fingerprint'=>hash('sha256','same-test-sender')]);echo 'REGISTERED';}catch(\App\Exceptions\SmsFailure){echo 'CONFLICT';}
CODE;
        $this->assertSame(['CONFLICT', 'REGISTERED'], $this->parallelFinance($code, [[1], [2]]));
        $this->assertSame(1, SmsDelivery::count());
    }

    public function test_two_announcement_publications_create_one_immutable_recipient_snapshot(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Announcement publication races require PostgreSQL.');
        }
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('key', 'admin')->value('id'));
        $student = User::factory()->create();
        $student->roles()->attach(Role::where('key', 'student')->value('id'));
        $announcement = Announcement::factory()->create(['created_by' => $admin->id]);
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try { app(\App\Services\Announcements::class)->publish(\App\Models\User::findOrFail((int)$argv[1]),\App\Models\Announcement::findOrFail((int)$argv[2]),1);echo 'PUBLISHED'; } catch(\Illuminate\Validation\ValidationException) { echo 'STALE'; }
CODE;
        $this->assertSame(['PUBLISHED', 'STALE'], $this->parallelFinance($code, [[$admin->id, $announcement->id], [$admin->id, $announcement->id]]));
        $this->assertSame(1, $announcement->recipients()->count());
        $this->assertSame(1, $announcement->fresh()->recipient_count);
    }

    public function test_two_announcement_workers_deliver_one_notification_for_each_recipient(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Announcement delivery races require PostgreSQL.');
        }
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('key', 'admin')->value('id'));
        $student = User::factory()->create();
        $student->roles()->attach(Role::where('key', 'student')->value('id'));
        $announcement = Announcement::factory()->create(['created_by' => $admin->id]);
        app(Announcements::class)->publish($admin, $announcement, 1);
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
echo app(\App\Services\Announcements::class)->deliver((int)$argv[1]);
CODE;
        $this->assertSame(['0', '1'], $this->parallelFinance($code, [[$announcement->id], [$announcement->id]]));
        $this->assertSame(1, $student->notifications()->count());
        $this->assertSame(1, $announcement->recipients()->where('status', 'delivered')->count());
        $this->assertSame('completed', $announcement->fresh()->status);
    }

    private function progressAfterConcurrentChange(callable $change, string $expected = 'DENIED'): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Lesson access races require PostgreSQL row locks.');
        }
        $student = User::factory()->create();
        $student->roles()->attach(Role::where('key', 'student')->value('id'));
        $enrollment = Enrollment::factory()->create(['user_id' => $student->id]);
        $section = CourseSection::factory()->create(['course_id' => $enrollment->course_id]);
        $lesson = Lesson::factory()->create(['course_section_id' => $section->id, 'duration_seconds' => 300]);
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$user=\App\Models\User::findOrFail((int)$argv[1]);$enrollment=\App\Models\Enrollment::findOrFail((int)$argv[2]);$lesson=\App\Models\Lesson::with('section')->findOrFail((int)$argv[3]);
echo "READY\n";flush();
try { $progress=app(\App\Services\LessonProgressRecorder::class)->record($user,$enrollment,$lesson,['complete'=>true,'position_seconds'=>290]);echo 'SAVED:'.$progress->position_seconds; }
catch(\Symfony\Component\HttpKernel\Exception\HttpException $exception) { echo $exception->getStatusCode()===403?'DENIED':'UNEXPECTED'; }
CODE;
        $process = new Process([PHP_BINARY, '-r', $code, (string) $student->id, (string) $enrollment->id, (string) $lesson->id], base_path(), null, null, 20);
        DB::transaction(function () use ($process, $change, $student, $enrollment, $lesson) {
            Course::whereKey($enrollment->course_id)->lockForUpdate()->firstOrFail();
            $process->start();
            $this->assertTrue($process->waitUntil(fn ($type, $output) => str_contains($output, 'READY')), 'Worker did not preload its models.');
            $change($student, $enrollment, $lesson);
        });
        $process->wait();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertSame("READY\n".$expected, $process->getOutput());
        if ($expected === 'DENIED') {
            $this->assertSame(0, LessonProgress::count());
        }
    }

    public function test_waiting_progress_cannot_complete_a_concurrently_unpublished_lesson(): void
    {
        $this->progressAfterConcurrentChange(fn ($user, $enrollment, $lesson) => $lesson->update(['is_published' => false]));
    }

    public function test_waiting_progress_cannot_complete_a_lesson_concurrently_rescheduled_to_future(): void
    {
        $this->progressAfterConcurrentChange(fn ($user, $enrollment, $lesson) => $lesson->update(['available_at' => now()->addHour()]));
    }

    public function test_waiting_progress_cannot_write_after_enrollment_cancellation(): void
    {
        $this->progressAfterConcurrentChange(fn ($user, $enrollment, $lesson) => $enrollment->update(['status' => 'cancelled']));
    }

    public function test_waiting_progress_cannot_write_after_account_deactivation(): void
    {
        $this->progressAfterConcurrentChange(fn ($user, $enrollment, $lesson) => $user->forceFill(['is_active' => false])->save());
    }

    public function test_waiting_progress_cannot_write_after_student_role_revocation(): void
    {
        $this->progressAfterConcurrentChange(fn ($user, $enrollment, $lesson) => $user->roles()->detach());
    }

    public function test_waiting_progress_uses_the_current_video_duration_after_instructor_edit(): void
    {
        $this->progressAfterConcurrentChange(fn ($user, $enrollment, $lesson) => $lesson->update(['duration_seconds' => 120]), 'SAVED:120');
        $this->assertSame(120, LessonProgress::sole()->position_seconds);
        $this->assertNotNull(LessonProgress::sole()->completed_at);
    }

    public function test_progress_can_save_while_another_reader_holds_the_course_shared_lock(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Shared lesson read locks require PostgreSQL.');
        }
        $student = User::factory()->create();
        $student->roles()->attach(Role::where('key', 'student')->value('id'));
        $enrollment = Enrollment::factory()->create(['user_id' => $student->id]);
        $section = CourseSection::factory()->create(['course_id' => $enrollment->course_id]);
        $lesson = Lesson::factory()->create(['course_section_id' => $section->id]);
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
app(\App\Services\LessonProgressRecorder::class)->record(\App\Models\User::findOrFail((int)$argv[1]),\App\Models\Enrollment::findOrFail((int)$argv[2]),\App\Models\Lesson::findOrFail((int)$argv[3]),['complete'=>true]);echo 'SAVED';
CODE;
        DB::transaction(function () use ($code, $student, $enrollment, $lesson) {
            Course::whereKey($enrollment->course_id)->sharedLock()->firstOrFail();
            $process = new Process([PHP_BINARY, '-r', $code, (string) $student->id, (string) $enrollment->id, (string) $lesson->id], base_path(), null, null, 10);
            $process->run();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $this->assertSame('SAVED', $process->getOutput());
        });
        $this->assertSame(1, LessonProgress::count());
    }

    public function test_two_progress_completion_writes_keep_one_record_and_completion(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Concurrent completion requires PostgreSQL.');
        }
        $student = User::factory()->create();
        $student->roles()->attach(Role::where('key', 'student')->value('id'));
        $enrollment = Enrollment::factory()->create(['user_id' => $student->id]);
        $section = CourseSection::factory()->create(['course_id' => $enrollment->course_id]);
        $lesson = Lesson::factory()->create(['course_section_id' => $section->id]);
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
app(\App\Services\LessonProgressRecorder::class)->record(\App\Models\User::findOrFail((int)$argv[1]),\App\Models\Enrollment::findOrFail((int)$argv[2]),\App\Models\Lesson::findOrFail((int)$argv[3]),['complete'=>true]);echo 'SAVED';
CODE;
        $arguments = [$student->id, $enrollment->id, $lesson->id];
        $this->assertSame(['SAVED', 'SAVED'], $this->parallelFinance($code, [$arguments, $arguments]));
        $this->assertSame(1, LessonProgress::count());
        $this->assertNotNull(LessonProgress::sole()->completed_at);
    }

    private function rewardFixture(int $goal = 20): array
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('key', 'admin')->value('id'));
        $student = User::factory()->create();
        $student->roles()->attach(Role::where('key', 'student')->value('id'));
        $enrollment = Enrollment::factory()->create(['user_id' => $student->id]);
        $section = CourseSection::factory()->create(['course_id' => $enrollment->course_id]);
        $lessons = Lesson::factory()->count(2)->create(['course_section_id' => $section->id]);
        app(LearningRewards::class)->configure($admin, $enrollment->cohort, ['revision' => 0, 'enabled' => true, 'lesson_points' => 10, 'badge_enabled' => true, 'badge_goal' => $goal, 'badge_title' => 'نشان رقابت آزمایشی', 'reviewed' => true]);

        return [$admin, $student, $enrollment, $lessons];
    }

    public function test_two_lesson_completions_award_one_goal_badge_and_exact_points(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Reward ledger races require PostgreSQL.');
        }
        [$admin, $student, $enrollment, $lessons] = $this->rewardFixture();
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
app(\App\Services\LessonProgressRecorder::class)->record(\App\Models\User::findOrFail((int)$argv[1]),\App\Models\Enrollment::findOrFail((int)$argv[2]),\App\Models\Lesson::findOrFail((int)$argv[3]),['complete'=>true]);echo 'SAVED';
CODE;
        $this->assertSame(['SAVED', 'SAVED'], $this->parallelFinance($code, [[$student->id, $enrollment->id, $lessons[0]->id], [$student->id, $enrollment->id, $lessons[1]->id]]));
        $this->assertSame(2, LearningRewardEvent::count());
        $this->assertSame(20, (int) LearningRewardEvent::sum('points'));
        $this->assertSame(1, LearningBadge::count());
        $this->assertSame(20, LearningBadge::sole()->points_at_award);
    }

    public function test_concurrent_reward_policy_edits_keep_one_next_version(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Reward policy races require PostgreSQL.');
        }
        [$admin, $student, $enrollment, $lessons] = $this->rewardFixture();
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try { app(\App\Services\LearningRewards::class)->configure(\App\Models\User::findOrFail((int)$argv[1]),\App\Models\Cohort::findOrFail((int)$argv[2]),['revision'=>1,'enabled'=>true,'lesson_points'=>(int)$argv[3],'badge_enabled'=>false,'reviewed'=>true]);echo 'SAVED'; }catch(\Illuminate\Validation\ValidationException){echo 'STALE';}
CODE;
        $this->assertSame(['SAVED', 'STALE'], $this->parallelFinance($code, [[$admin->id, $enrollment->cohort_id, 20], [$admin->id, $enrollment->cohort_id, 30]]));
        $this->assertSame(2, LearningRewardVersion::count());
        $this->assertSame(2, LearningRewardPolicy::sole()->revision);
        $this->assertSame(10, LearningRewardVersion::oldest('id')->firstOrFail()->definition['lesson_points']);
    }

    public function test_same_user_cannot_farm_one_lesson_through_two_concurrent_cohort_completions(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Cross-enrollment reward races require PostgreSQL.');
        }
        [$admin, $student, $first, $lessons] = $this->rewardFixture(10);
        $cohort = Cohort::factory()->create(['course_id' => $first->course_id]);
        $second = Enrollment::factory()->create(['user_id' => $student->id, 'course_id' => $first->course_id, 'cohort_id' => $cohort->id]);
        app(LearningRewards::class)->configure($admin, $cohort, ['revision' => 0, 'enabled' => true, 'lesson_points' => 10, 'badge_enabled' => true, 'badge_goal' => 10, 'badge_title' => 'نشان رقابت دوم', 'reviewed' => true]);
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
app(\App\Services\LessonProgressRecorder::class)->record(\App\Models\User::findOrFail((int)$argv[1]),\App\Models\Enrollment::findOrFail((int)$argv[2]),\App\Models\Lesson::findOrFail((int)$argv[3]),['complete'=>true]);echo 'SAVED';
CODE;
        $this->assertSame(['SAVED', 'SAVED'], $this->parallelFinance($code, [[$student->id, $first->id, $lessons[0]->id], [$student->id, $second->id, $lessons[0]->id]]));
        $this->assertSame(2, LessonProgress::count());
        $this->assertSame(1, LearningRewardEvent::count());
        $this->assertSame(10, (int) LearningRewardEvent::sum('points'));
        $this->assertSame(1, LearningBadge::count());
    }

    public function test_two_certificate_withdrawals_preserve_one_terminal_consent_event(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Certificate withdrawal races require PostgreSQL.');
        }
        [$request] = $this->certificationRequest();
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();$request=\App\Models\CertificateRequest::findOrFail((int)$argv[1]);
try {app(\App\Services\Certification::class)->withdraw($request->enrollment->user,$request,1);echo 'WITHDRAWN';}catch(\Illuminate\Validation\ValidationException){echo 'STALE';}
CODE;
        $this->assertSame(['STALE', 'WITHDRAWN'], $this->parallelFinance($code, [[$request->id], [$request->id]]));
        $this->assertSame('withdrawn', $request->fresh()->status);
        $this->assertSame(2, $request->fresh()->revision);
        $this->assertSame(1, AuditEvent::where('action', 'certificate.request_withdrawn')->count());
        $this->assertSame(0, CourseCertificate::count());
    }

    public function test_certificate_withdrawal_and_admin_issuance_cannot_both_finish(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Certificate withdrawal races require PostgreSQL.');
        }
        [$request, $admin] = $this->certificationRequest();
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();$request=\App\Models\CertificateRequest::findOrFail((int)$argv[1]);
try {if($argv[2]==='withdraw'){app(\App\Services\Certification::class)->withdraw($request->enrollment->user,$request,1);}else{app(\App\Services\Certification::class)->review(\App\Models\User::findOrFail((int)$argv[3]),$request,1,'issue','Reviewed fixture');}echo 'DONE';}catch(\Illuminate\Validation\ValidationException){echo 'STALE';}
CODE;
        $this->assertSame(['DONE', 'STALE'], $this->parallelFinance($code, [[$request->id, 'withdraw', $admin->id], [$request->id, 'issue', $admin->id]]));
        $request->refresh();
        $this->assertContains($request->status, ['issued', 'withdrawn']);
        $this->assertSame(2, $request->revision);
        $this->assertSame($request->status === 'issued' ? 1 : 0, CourseCertificate::count());
        $this->assertSame($request->status === 'withdrawn', $request->withdrawn_at !== null);
    }

    public function test_two_certificate_privacy_updates_keep_one_visibility_history_event(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Certificate visibility races require PostgreSQL.');
        }
        [$request, $admin] = $this->certificationRequest();
        $certificate = app(Certification::class)->review($admin, $request, 1, 'issue', 'Reviewed fixture');
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();$certificate=\App\Models\CourseCertificate::findOrFail((int)$argv[1]);
try{app(\App\Services\Certification::class)->visibility($certificate->enrollment->user,$certificate,1,false,false);echo 'CLOSED';}catch(\Illuminate\Validation\ValidationException){echo 'STALE';}
CODE;
        $this->assertSame(['CLOSED', 'STALE'], $this->parallelFinance($code, [[$certificate->id], [$certificate->id]]));
        $this->assertFalse($certificate->fresh()->public_enabled);
        $this->assertSame(2, $certificate->fresh()->revision);
        $this->assertSame(1, CertificateVisibilityEvent::count());
        $this->assertSame('issued', $certificate->fresh()->status);
    }

    public function test_certificate_public_privacy_and_revocation_respect_the_same_revision(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Certificate privacy/revocation races require PostgreSQL.');
        }
        [$request, $admin] = $this->certificationRequest();
        $certificate = app(Certification::class)->review($admin, $request, 1, 'issue', 'Reviewed fixture');
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();$certificate=\App\Models\CourseCertificate::findOrFail((int)$argv[1]);
try{if($argv[2]==='close'){app(\App\Services\Certification::class)->visibility($certificate->enrollment->user,$certificate,1,false,false);}else{app(\App\Services\Certification::class)->revoke(\App\Models\User::findOrFail((int)$argv[3]),$certificate,1,'Reviewed fixture');}echo 'DONE';}catch(\Illuminate\Validation\ValidationException){echo 'STALE';}
CODE;
        $this->assertSame(['DONE', 'STALE'], $this->parallelFinance($code, [[$certificate->id, 'close', $admin->id], [$certificate->id, 'revoke', $admin->id]]));
        $certificate->refresh();
        $this->assertSame(2, $certificate->revision);
        $this->assertSame($certificate->status === 'revoked', $certificate->public_enabled);
        $this->assertSame($certificate->status === 'issued' ? 1 : 0, CertificateVisibilityEvent::count());
    }

    private function coachProfileFixture(): array
    {
        $profile = CoachProfile::factory()->create();
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('key', 'admin')->value('id'));
        app(CoachProfiles::class)->submit($profile->owner, $profile, 1, true);

        return [$profile->fresh(), $admin];
    }

    public function test_two_coach_profile_reviews_publish_one_consented_snapshot(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Coach profile publication races require PostgreSQL.');
        }
        [$profile, $admin] = $this->coachProfileFixture();
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try{app(\App\Services\CoachProfiles::class)->review(\App\Models\User::findOrFail((int)$argv[1]),\App\Models\CoachProfile::findOrFail((int)$argv[2]),2,'publish',null);echo 'PUBLISHED';}catch(\Illuminate\Validation\ValidationException){echo 'STALE';}
CODE;
        $this->assertSame(['PUBLISHED', 'STALE'], $this->parallelFinance($code, [[$admin->id, $profile->id], [$admin->id, $profile->id]]));
        $this->assertSame(1, CoachProfilePublication::count());
        $this->assertSame(3, $profile->fresh()->revision);
        $this->assertSame('approved', $profile->fresh()->state);
    }

    public function test_coach_profile_edit_and_publication_cannot_overwrite_the_same_revision(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Coach profile edit/review races require PostgreSQL.');
        }
        [$profile, $admin] = $this->coachProfileFixture();
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();$profile=\App\Models\CoachProfile::findOrFail((int)$argv[1]);
try{if($argv[2]==='edit'){app(\App\Services\CoachProfiles::class)->save($profile->owner,[...$profile->draft,'headline'=>'Edited fixture headline'],2);}else{app(\App\Services\CoachProfiles::class)->review(\App\Models\User::findOrFail((int)$argv[3]),$profile,2,'publish',null);}echo 'DONE';}catch(\Illuminate\Validation\ValidationException){echo 'STALE';}
CODE;
        $this->assertSame(['DONE', 'STALE'], $this->parallelFinance($code, [[$profile->id, 'edit', $admin->id], [$profile->id, 'publish', $admin->id]]));
        $profile->refresh();
        $this->assertSame(3, $profile->revision);
        $this->assertSame($profile->state === 'approved' ? 1 : 0, CoachProfilePublication::count());
        $this->assertTrue($profile->state !== 'approved' || $profile->currentPublication->definition['headline'] !== 'Edited fixture headline');
    }

    public function test_coach_profile_consent_withdrawal_and_publication_cannot_both_finish(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Coach profile consent/publication races require PostgreSQL.');
        }
        [$profile, $admin] = $this->coachProfileFixture();
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();$profile=\App\Models\CoachProfile::findOrFail((int)$argv[1]);
try{if($argv[2]==='withdraw'){app(\App\Services\CoachProfiles::class)->withdraw($profile->owner,$profile,2,null);}else{app(\App\Services\CoachProfiles::class)->review(\App\Models\User::findOrFail((int)$argv[3]),$profile,2,'publish',null);}echo 'DONE';}catch(\Illuminate\Validation\ValidationException){echo 'STALE';}
CODE;
        $this->assertSame(['DONE', 'STALE'], $this->parallelFinance($code, [[$profile->id, 'withdraw', $admin->id], [$profile->id, 'publish', $admin->id]]));
        $profile->refresh();
        $this->assertSame(3, $profile->revision);
        $this->assertSame($profile->public_enabled ? 1 : 0, CoachProfilePublication::count());
        $this->assertSame($profile->public_enabled ? 0 : 1, CoachProfileWithdrawal::count());
    }

    public function test_simultaneous_public_contact_retries_preserve_one_initial_request(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Contact idempotency races require PostgreSQL.');
        }
        $key = (string) Str::uuid();
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
$lead=app(\App\Services\ContactLeads::class)->receive(['request_key'=>$argv[1],'name'=>'Contact fixture','phone'=>'09121112222','audience'=>'individual','subject'=>'training','message'=>$argv[2],'consent'=>true]);echo (string)$lead->id;
CODE;
        $results = $this->parallelFinance($code, [[$key, 'First independent concurrent message'], [$key, 'Second independent concurrent message']]);
        $this->assertSame($results[0], $results[1]);
        $this->assertSame(1, ContactLead::count());
        $this->assertSame(1, ContactLeadEvent::count());
        $this->assertSame(1, AuditEvent::where('action', 'contact_lead.received')->count());
        $this->assertContains(ContactLead::sole()->message, ['First independent concurrent message', 'Second independent concurrent message']);
    }

    public function test_two_admin_contact_follow_ups_cannot_overwrite_the_same_revision(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Contact follow-up races require PostgreSQL.');
        }
        $lead = ContactLead::factory()->create();
        $admins = User::factory()->count(2)->create();
        foreach ($admins as $admin) {
            $admin->roles()->attach(Role::where('key', 'admin')->value('id'));
        }
        $original = $lead->only('name', 'phone', 'message', 'consented_at');
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();
try{app(\App\Services\ContactLeads::class)->followUp(\App\Models\User::findOrFail((int)$argv[1]),\App\Models\ContactLead::findOrFail((int)$argv[2]),['revision'=>1,'status'=>$argv[3],'note'=>'Concurrent follow-up fixture']);echo 'SAVED';}catch(\Illuminate\Validation\ValidationException){echo 'STALE';}
CODE;
        $this->assertSame(['SAVED', 'STALE'], $this->parallelFinance($code, [[$admins[0]->id, $lead->id, 'working'], [$admins[1]->id, $lead->id, 'qualified']]));
        $this->assertSame(2, $lead->fresh()->revision);
        $this->assertSame(1, ContactLeadEvent::count());
        $this->assertSame(1, AuditEvent::where('action', 'contact_lead.follow_up')->count());
        $this->assertEquals($original, $lead->fresh()->only('name', 'phone', 'message', 'consented_at'));
    }

    public function test_two_due_content_processes_publish_only_one_approved_snapshot(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Scheduled publication races require PostgreSQL.');
        }
        $schedule = ContentSchedule::factory()->create(['scheduled_for' => now()->subMinute()]);
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();echo app(\App\Services\ContentPublishing::class)->processSchedule(\App\Models\ContentSchedule::findOrFail((int)$argv[1]));
CODE;
        $this->assertSame(['already_handled', 'published'], $this->parallelFinance($code, [[$schedule->id], [$schedule->id]]));
        $this->assertSame(1, ContentPublication::count());
        $this->assertSame('published', $schedule->fresh()->status);
        $this->assertSame($schedule->definition, ContentPublication::sole()->definition);
        $this->assertSame(3, $schedule->page->fresh()->revision);
    }

    private function concurrentScheduledContentChange(string $change): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Scheduled publication change races require PostgreSQL.');
        }
        $schedule = ContentSchedule::factory()->create(['scheduled_for' => now()->subMinute()]);
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();$schedule=\App\Models\ContentSchedule::findOrFail((int)$argv[1]);$service=app(\App\Services\ContentPublishing::class);
if($argv[2]==='process'){echo $service->processSchedule($schedule);}else{try{if($argv[2]==='edit'){$service->draft($schedule->author,$schedule->page,2,[...$schedule->page->draft,'title'=>'Concurrent changed draft']);}else{$service->cancelSchedule($schedule->author,$schedule->page,2,$schedule);}echo 'changed';}catch(\Illuminate\Validation\ValidationException){echo 'stale';}}
CODE;
        $results = $this->parallelFinance($code, [[$schedule->id, 'process'], [$schedule->id, $change]]);
        $this->assertContains($results, [['already_handled', 'changed'], ['published', 'stale']]);
        $schedule->refresh();
        $this->assertSame($schedule->status === 'published' ? 1 : 0, ContentPublication::count());
        $this->assertSame(3, $schedule->page->revision);
        if ($schedule->status === 'published') {
            $this->assertSame($schedule->definition, ContentPublication::sole()->definition);
        } else {
            $this->assertSame('cancelled', $schedule->status);
        }
    }

    public function test_due_content_publication_and_draft_edit_cannot_overwrite_the_same_revision(): void
    {
        $this->concurrentScheduledContentChange('edit');
    }

    public function test_due_content_publication_and_cancellation_cannot_both_finish(): void
    {
        $this->concurrentScheduledContentChange('cancel');
    }

    private function scheduledContentAfterAccountChange(bool $revoke): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Scheduled publication authorization races require PostgreSQL.');
        }
        $schedule = ContentSchedule::factory()->create(['scheduled_for' => now()->subMinute()]);
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();$schedule=\App\Models\ContentSchedule::findOrFail((int)$argv[1]);echo "READY\n";flush();echo app(\App\Services\ContentPublishing::class)->processSchedule($schedule);
CODE;
        $process = new Process([PHP_BINARY, '-r', $code, (string) $schedule->id], base_path(), null, null, 20);
        DB::transaction(function () use ($schedule, $process, $revoke) {
            $author = User::whereKey($schedule->created_by)->lockForUpdate()->firstOrFail();
            $process->start();
            $this->assertTrue($process->waitUntil(fn ($type, $output) => str_contains($output, 'READY')));
            if ($revoke) {
                $author->roles()->detach();
            } else {
                $author->forceFill(['is_active' => false])->save();
            }
        });
        $process->wait();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertSame("READY\nblocked", $process->getOutput());
        $this->assertSame('author_ineligible', $schedule->fresh()->reason);
        $this->assertSame(0, ContentPublication::count());
    }

    public function test_waiting_scheduled_content_rechecks_concurrent_admin_role_revocation(): void
    {
        $this->scheduledContentAfterAccountChange(true);
    }

    public function test_waiting_scheduled_content_rechecks_concurrent_author_deactivation(): void
    {
        $this->scheduledContentAfterAccountChange(false);
    }

    private function mentorCoordinationFixture(): array
    {
        [$profile, $admin] = $this->coachProfileFixture();
        app(CoachProfiles::class)->review($admin, $profile, 2, 'publish', null);
        $student = User::factory()->create();
        $student->roles()->attach(Role::where('key', 'student')->value('id'));

        return [$profile->fresh(), $admin, $student];
    }

    public function test_concurrent_selected_mentor_requests_create_only_one_pending_request(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Mentor coordination races require PostgreSQL.');
        }
        [$profile, $admin, $student] = $this->mentorCoordinationFixture();
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();try{app(\App\Services\ConsultationService::class)->request(\App\Models\User::findOrFail((int)$argv[1]),'Private coordination topic','Tomorrow afternoon',null,$argv[2]);echo 'CREATED';}catch(\Illuminate\Validation\ValidationException){echo 'DUPLICATE';}
CODE;
        $this->assertSame(['CREATED', 'DUPLICATE'], $this->parallelFinance($code, [[$student->id, $profile->slug], [$student->id, $profile->slug]]));
        $this->assertSame(1, ConsultationRequest::count());
        $this->assertSame($profile->user_id, ConsultationRequest::sole()->requested_coach_id);
        $this->assertSame(0, Consultation::count());
    }

    private function coordinationAfterConcurrentChange(bool $withdrawProfile): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Mentor coordination access races require PostgreSQL.');
        }
        [$profile, $admin, $student] = $this->mentorCoordinationFixture();
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();$student=\App\Models\User::findOrFail((int)$argv[1]);echo "READY\n";flush();try{app(\App\Services\ConsultationService::class)->request($student,'Private coordination topic','Tomorrow afternoon',null,$argv[2]);echo 'CREATED';}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){echo (string)$e->getStatusCode();}catch(\Illuminate\Database\Eloquent\ModelNotFoundException){echo '404';}
CODE;
        $process = new Process([PHP_BINARY, '-r', $code, (string) $student->id, $profile->slug], base_path(), null, null, 20);
        DB::transaction(function () use ($student, $profile, $process, $withdrawProfile) {
            User::whereIn('id', [$student->id, $profile->user_id])->orderBy('id')->lockForUpdate()->get();
            $process->start();
            $this->assertTrue($process->waitUntil(fn ($type, $output) => str_contains($output, 'READY')));
            if ($withdrawProfile) {
                app(CoachProfiles::class)->withdraw($profile->owner, $profile, $profile->revision, null);
            } else {
                $student->roles()->detach();
            }
        });
        $process->wait();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertSame("READY\n".($withdrawProfile ? '404' : '403'), $process->getOutput());
        $this->assertSame(0, ConsultationRequest::count());
    }

    public function test_waiting_coordination_rechecks_concurrent_profile_withdrawal(): void
    {
        $this->coordinationAfterConcurrentChange(true);
    }

    public function test_waiting_coordination_rechecks_current_student_role(): void
    {
        $this->coordinationAfterConcurrentChange(false);
    }

    public function test_waiting_admin_booking_rechecks_role_after_the_first_authorization_check(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Booking authorization races require PostgreSQL.');
        }
        [$profile, $admin, $student] = $this->mentorCoordinationFixture();
        $request = app(ConsultationService::class)->request($student, 'Private topic', 'Tomorrow afternoon', null, $profile->slug);
        $slot = ConsultationSlot::factory()->create(['coach_id' => $profile->user_id]);
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();$request=\App\Models\ConsultationRequest::findOrFail((int)$argv[1]);$admin=\App\Models\User::findOrFail((int)$argv[2]);$slot=\App\Models\ConsultationSlot::findOrFail((int)$argv[3]);
\Illuminate\Support\Facades\DB::listen(function($query){if(str_contains($query->sql,'roles') && in_array('admin',$query->bindings,true)){echo "CHECKED\n";flush();}});
try{app(\App\Services\ConsultationService::class)->book($request->student,$slot,$request->topic,$request,$admin,1);echo 'BOOKED';}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){echo (string)$e->getStatusCode();}
CODE;
        $process = new Process([PHP_BINARY, '-r', $code, (string) $request->id, (string) $admin->id, (string) $slot->id], base_path(), null, null, 20);
        DB::transaction(function () use ($admin, $process) {
            User::whereKey($admin->id)->lockForUpdate()->firstOrFail();
            $process->start();
            $this->assertTrue($process->waitUntil(fn ($type, $output) => str_contains($output, 'CHECKED')));
            $admin->roles()->detach();
        });
        $process->wait();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertStringEndsWith('403', $process->getOutput());
        $this->assertSame(0, Consultation::count());
        $this->assertFalse($slot->fresh()->booked);
        $this->assertSame('pending', $request->fresh()->status);
    }

    private function surveyExportAfterAccessChange(bool $changeCoach): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Survey export access races require PostgreSQL.');
        }
        $response = SurveyResponse::factory()->create();
        $publication = $response->publication;
        $publication->update(['definition' => [...$publication->definition, 'mode' => 'identified', 'minimum_responses' => 1]]);
        $response->update(['status' => 'submitted', 'submitted_at' => now(), 'respondent_name' => 'Synthetic snapshot', 'answers' => ['quality' => 4]]);
        $course = $publication->survey->cohort->course;
        $actor = $changeCoach ? User::findOrFail($course->coach_id) : User::factory()->create();
        if (! $changeCoach) {
            $actor->roles()->attach(Role::where('key', 'admin')->value('id'));
        }
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();$actor=\App\Models\User::findOrFail((int)$argv[1]);$publication=\App\Models\SurveyPublication::findOrFail((int)$argv[2]);echo "READY\n";flush();try{$prepared=app(\App\Services\SurveyExports::class)->prepare($actor,$publication,'responses');fclose($prepared['stream']);echo 'EXPORTED';}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){echo (string)$e->getStatusCode();}
CODE;
        $process = new Process([PHP_BINARY, '-r', $code, (string) $actor->id, (string) $publication->id], base_path(), null, null, 20);
        DB::transaction(function () use ($actor, $course, $process, $changeCoach) {
            if ($changeCoach) {
                Course::whereKey($course->id)->lockForUpdate()->firstOrFail();
            } else {
                User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            }
            $process->start();
            $this->assertTrue($process->waitUntil(fn ($type, $output) => str_contains($output, 'READY')));
            if ($changeCoach) {
                $course->update(['coach_id' => null]);
            } else {
                $actor->roles()->detach();
            }
        });
        $process->wait();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertSame("READY\n403", $process->getOutput());
        $this->assertSame(0, AuditEvent::where('action', 'survey.exported')->count());
    }

    public function test_waiting_survey_export_rechecks_revoked_admin_role(): void
    {
        $this->surveyExportAfterAccessChange(false);
    }

    public function test_waiting_survey_export_rechecks_reassigned_course_coach(): void
    {
        $this->surveyExportAfterAccessChange(true);
    }

    private function scheduledAnnouncementFixture(): array
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Scheduled announcement races require PostgreSQL.');
        }
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('key', 'admin')->value('id'));
        $student = User::factory()->create();
        $student->roles()->attach(Role::where('key', 'student')->value('id'));
        $announcement = Announcement::factory()->create(['created_by' => $admin->id]);
        $schedule = app(Announcements::class)->schedule($admin, $announcement, 1, CarbonImmutable::now()->addMinutes(2), true);

        return [$admin, $announcement->fresh(), $schedule];
    }

    public function test_two_concurrent_announcement_schedules_keep_one_pending_review(): void
    {
        [$admin, $announcement, $old] = $this->scheduledAnnouncementFixture();
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();try{app(\App\Services\Announcements::class)->schedule(\App\Models\User::findOrFail((int)$argv[1]),\App\Models\Announcement::findOrFail((int)$argv[2]),2,\Carbon\CarbonImmutable::now()->addMinutes(3),true);echo 'SCHEDULED';}catch(\Illuminate\Validation\ValidationException){echo 'STALE';}
CODE;
        $this->assertSame(['SCHEDULED', 'STALE'], $this->parallelFinance($code, [[$admin->id, $announcement->id], [$admin->id, $announcement->id]]));
        $this->assertSame('replaced', $old->fresh()->reason);
        $this->assertSame(1, AnnouncementSchedule::where('status', 'pending')->count());
        $this->assertSame(0, $announcement->recipients()->count());
    }

    public function test_concurrent_due_announcement_processors_create_one_recipient_snapshot(): void
    {
        [$admin, $announcement, $schedule] = $this->scheduledAnnouncementFixture();
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();\Illuminate\Support\Carbon::setTestNow($argv[2]);echo app(\App\Services\Announcements::class)->processSchedule(\App\Models\AnnouncementSchedule::findOrFail((int)$argv[1]));
CODE;
        $args = [$schedule->id, $schedule->scheduled_for->copy()->addMinute()->toIso8601String()];
        $this->assertSame(['already_handled', 'published'], $this->parallelFinance($code, [$args, $args]));
        $this->assertSame(1, $announcement->recipients()->count());
        $this->assertSame(0, DB::table('notifications')->count());
        $this->assertSame('published', $schedule->fresh()->status);
    }

    private function announcementScheduleLifecycleRace(bool $edit): void
    {
        [$admin, $announcement, $schedule] = $this->scheduledAnnouncementFixture();
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();\Illuminate\Support\Carbon::setTestNow($argv[4]);$service=app(\App\Services\Announcements::class);$schedule=\App\Models\AnnouncementSchedule::findOrFail((int)$argv[1]);if($argv[3]==='due'){echo $service->processSchedule($schedule);}else{try{if($argv[3]==='edit'){$service->save(\App\Models\User::findOrFail((int)$argv[2]),['title'=>'Edited announcement','message'=>'Edited private message','roles'=>['student']],$schedule->announcement,2);echo 'EDITED';}else{$service->cancelSchedule(\App\Models\User::findOrFail((int)$argv[2]),$schedule->announcement,2,$schedule);echo 'CANCELLED';}}catch(\Illuminate\Validation\ValidationException){echo 'STALE';}}
CODE;
        $time = $schedule->scheduled_for->copy()->addMinute()->toIso8601String();
        $result = $this->parallelFinance($code, [[$schedule->id, $admin->id, 'due', $time], [$schedule->id, $admin->id, $edit ? 'edit' : 'cancel', $time]]);
        $this->assertTrue(in_array($result, [[$edit ? 'EDITED' : 'CANCELLED', 'already_handled'], ['STALE', 'published']], true));
        $published = $schedule->fresh()->status === 'published';
        $this->assertSame($published ? 1 : 0, $announcement->recipients()->count());
        $this->assertSame($published ? 'sending' : 'draft', $announcement->fresh()->status);
    }

    public function test_due_announcement_racing_draft_edit_has_one_consistent_outcome(): void
    {
        $this->announcementScheduleLifecycleRace(true);
    }

    public function test_due_announcement_racing_cancellation_has_one_consistent_outcome(): void
    {
        $this->announcementScheduleLifecycleRace(false);
    }

    public function test_waiting_due_announcement_rechecks_approver_role(): void
    {
        [$admin, $announcement, $schedule] = $this->scheduledAnnouncementFixture();
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();\Illuminate\Support\Carbon::setTestNow($argv[2]);$schedule=\App\Models\AnnouncementSchedule::findOrFail((int)$argv[1]);echo "READY\n";flush();echo app(\App\Services\Announcements::class)->processSchedule($schedule);
CODE;
        $process = new Process([PHP_BINARY, '-r', $code, (string) $schedule->id, $schedule->scheduled_for->copy()->addMinute()->toIso8601String()], base_path(), null, null, 20);
        DB::transaction(function () use ($admin, $process) {
            User::whereKey($admin->id)->lockForUpdate()->firstOrFail();
            $process->start();
            $this->assertTrue($process->waitUntil(fn ($type, $output) => str_contains($output, 'READY')));
            $admin->roles()->detach();
        });
        $process->wait();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertSame("READY\nblocked", $process->getOutput());
        $this->assertSame('author_ineligible', $schedule->fresh()->reason);
        $this->assertSame(0, $announcement->recipients()->count());
    }

    public function test_waiting_immediate_announcement_publication_rechecks_role_after_initial_check(): void
    {
        [$admin, $announcement, $schedule] = $this->scheduledAnnouncementFixture();
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();$actor=\App\Models\User::findOrFail((int)$argv[1]);$announcement=\App\Models\Announcement::findOrFail((int)$argv[2]);\Illuminate\Support\Facades\DB::listen(function($query){if(str_contains($query->sql,'roles')&&in_array('admin',$query->bindings,true)){echo "CHECKED\n";flush();}});try{app(\App\Services\Announcements::class)->publish($actor,$announcement,2);echo 'PUBLISHED';}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){echo (string)$e->getStatusCode();}
CODE;
        $process = new Process([PHP_BINARY, '-r', $code, (string) $admin->id, (string) $announcement->id], base_path(), null, null, 20);
        DB::transaction(function () use ($admin, $announcement, $process) {
            Announcement::whereKey($announcement->id)->lockForUpdate()->firstOrFail();
            $process->start();
            $this->assertTrue($process->waitUntil(fn ($type, $output) => str_contains($output, 'CHECKED')));
            $admin->roles()->detach();
        });
        $process->wait();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertStringEndsWith('403', $process->getOutput());
        $this->assertSame('pending', $schedule->fresh()->status);
        $this->assertSame(0, $announcement->recipients()->count());
    }

    private function organizationAnnouncementFixture(bool $publish = true): array
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Organization announcement races require PostgreSQL.');
        }
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('key', 'admin')->value('id'));
        $manager = User::factory()->create();
        $manager->roles()->attach(Role::where('key', 'org_admin')->value('id'));
        $student = User::factory()->create();
        $student->roles()->attach(Role::where('key', 'student')->value('id'));
        $organization = Organization::factory()->create(['status' => 'active']);
        $organization->members()->attach($manager->id, ['kind' => 'manager']);
        $organization->members()->attach($student->id, ['kind' => 'member']);
        $announcement = app(Announcements::class)->save($admin, ['title' => 'Private organization notice', 'message' => 'Private synthetic organization announcement', 'roles' => ['student'], 'audience' => ['type' => 'organization', 'organization_id' => $organization->id]]);
        if ($publish) {
            app(Announcements::class)->publish($admin, $announcement, 1);
        }

        return [$admin, $manager, $student, $organization, $announcement->fresh()];
    }

    public function test_two_scoped_announcement_workers_create_one_private_notification(): void
    {
        [$admin, $manager, $student, $organization, $announcement] = $this->organizationAnnouncementFixture();
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();echo app(\App\Services\Announcements::class)->deliver((int)$argv[1]);
CODE;
        $this->assertSame(['0', '1'], $this->parallelFinance($code, [[$announcement->id], [$announcement->id]]));
        $this->assertSame(1, DB::table('notifications')->where('type', PlatformAnnouncement::class)->count());
        $this->assertSame('completed', $announcement->fresh()->status);
    }

    private function organizationDeliveryAfterMembershipChange(string $change): void
    {
        [$admin, $manager, $student, $organization, $announcement] = $this->organizationAnnouncementFixture();
        $key = $organization->members()->where('users.id', $student->id)->firstOrFail()->pivot->membership_key;
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();\Illuminate\Support\Facades\DB::listen(function($query){if(str_contains($query->sql,'"announcements"')&&str_starts_with($query->sql,'select')&&!str_contains($query->sql,'for update')){echo "PEEKED\n";flush();}});echo app(\App\Services\Announcements::class)->deliver((int)$argv[1]);
CODE;
        $process = new Process([PHP_BINARY, '-r', $code, (string) $announcement->id], base_path(), null, null, 20);
        DB::transaction(function () use ($manager, $student, $organization, $process, $key, $change) {
            Organization::whereKey($organization->id)->lockForUpdate()->firstOrFail();
            $process->start();
            $this->assertTrue($process->waitUntil(fn ($type, $output) => str_contains($output, 'PEEKED')));
            if ($change === 'inactive') {
                $organization->update(['status' => 'inactive']);
            } else {
                $membership = app(OrganizationMembership::class);
                $membership->endMembership($student, $organization, $student, $key, 'Synthetic concurrent departure', 'leave');
                if ($change === 'rejoin') {
                    $membership->invite($manager, $organization, $student->phone);
                    $invitation = OrganizationInvitation::where('organization_id', $organization->id)->where('phone', $student->phone)->firstOrFail();
                    $membership->respond($student, $invitation, 1, 'accept');
                }
            }
        });
        $process->wait();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertStringEndsWith("\n0", $process->getOutput());
        $this->assertSame(0, DB::table('notifications')->where('type', PlatformAnnouncement::class)->count());
        $this->assertSame('skipped', $announcement->recipients()->firstOrFail()->status);
    }

    public function test_waiting_organization_delivery_rechecks_departure(): void
    {
        $this->organizationDeliveryAfterMembershipChange('leave');
    }

    public function test_waiting_organization_delivery_rechecks_rejoined_membership_generation(): void
    {
        $this->organizationDeliveryAfterMembershipChange('rejoin');
    }

    public function test_waiting_organization_delivery_rechecks_organization_deactivation(): void
    {
        $this->organizationDeliveryAfterMembershipChange('inactive');
    }

    public function test_waiting_org_announcement_publication_does_not_snapshot_departed_members(): void
    {
        [$admin, $manager, $student, $organization, $announcement] = $this->organizationAnnouncementFixture(false);
        $key = $organization->members()->where('users.id', $student->id)->firstOrFail()->pivot->membership_key;
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();$actor=\App\Models\User::findOrFail((int)$argv[1]);$announcement=\App\Models\Announcement::findOrFail((int)$argv[2]);\Illuminate\Support\Facades\DB::listen(function($query){if(str_contains($query->sql,'"announcements"')&&str_starts_with($query->sql,'select')&&!str_contains($query->sql,'for update')){echo "PEEKED\n";flush();}});try{app(\App\Services\Announcements::class)->publish($actor,$announcement,1);echo 'PUBLISHED';}catch(\Illuminate\Validation\ValidationException){echo 'NO_AUDIENCE';}
CODE;
        $process = new Process([PHP_BINARY, '-r', $code, (string) $admin->id, (string) $announcement->id], base_path(), null, null, 20);
        DB::transaction(function () use ($student, $organization, $key, $process) {
            Organization::whereKey($organization->id)->lockForUpdate()->firstOrFail();
            $process->start();
            $this->assertTrue($process->waitUntil(fn ($type, $output) => str_contains($output, 'PEEKED')));
            app(OrganizationMembership::class)->endMembership($student, $organization, $student, $key, 'Synthetic publication departure', 'leave');
        });
        $process->wait();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertStringEndsWith('NO_AUDIENCE', $process->getOutput());
        $this->assertSame('draft', $announcement->fresh()->status);
        $this->assertSame(0, $announcement->recipients()->count());
    }

    public function test_org_target_edit_racing_publication_cannot_publish_a_different_scope(): void
    {
        [$admin, $manager, $student, $organization, $announcement] = $this->organizationAnnouncementFixture(false);
        $other = Organization::factory()->create(['status' => 'active']);
        $otherStudent = User::factory()->create();
        $otherStudent->roles()->attach(Role::where('key', 'student')->value('id'));
        $other->members()->attach($otherStudent->id, ['kind' => 'member']);
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();$service=app(\App\Services\Announcements::class);$actor=\App\Models\User::findOrFail((int)$argv[1]);$announcement=\App\Models\Announcement::findOrFail((int)$argv[2]);try{if($argv[3]==='publish'){$service->publish($actor,$announcement,1);echo 'PUBLISHED';}else{$service->save($actor,['title'=>'Changed target','message'=>'Changed scoped private message','roles'=>['student'],'audience'=>['type'=>'organization','organization_id'=>(int)$argv[4]]],$announcement,1);echo 'EDITED';}}catch(\Illuminate\Validation\ValidationException){echo 'STALE';}
CODE;
        $result = $this->parallelFinance($code, [[$admin->id, $announcement->id, 'publish', $other->id], [$admin->id, $announcement->id, 'edit', $other->id]]);
        $this->assertTrue(in_array($result, [['EDITED', 'STALE'], ['PUBLISHED', 'STALE']], true));
        $published = in_array('PUBLISHED', $result, true);
        $this->assertSame($published ? $organization->id : $other->id, $announcement->fresh()->audience['organization_id']);
        $this->assertSame($published ? [$student->id] : [], $announcement->recipients()->pluck('user_id')->all());
    }

    private function cohortAnnouncementFixture(bool $publish = true, bool $coachOnly = false): array
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Cohort announcement races require PostgreSQL.');
        }
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('key', 'admin')->value('id'));
        $coach = User::factory()->create();
        $coach->roles()->attach(Role::where('key', 'coach')->value('id'));
        $course = Course::factory()->create(['coach_id' => $coach->id, 'price' => 0, 'is_published' => true]);
        $cohort = Cohort::factory()->create(['course_id' => $course->id, 'capacity' => 20, 'registration_open' => true]);
        $student = User::factory()->create();
        $student->roles()->attach(Role::where('key', 'student')->value('id'));
        $enrollment = Enrollment::factory()->create(['user_id' => $student->id, 'cohort_id' => $cohort->id, 'status' => 'active']);
        $announcement = app(Announcements::class)->save($admin, ['title' => 'Private cohort notice', 'message' => 'Private synthetic cohort message', 'roles' => [$coachOnly ? 'coach' : 'student'], 'audience' => ['type' => 'cohort', 'cohort_id' => $cohort->id]]);
        if ($publish) {
            app(Announcements::class)->publish($admin, $announcement, 1);
        }

        return [$admin, $coach, $student, $course, $cohort, $enrollment, $announcement->fresh()];
    }

    public function test_two_cohort_announcement_workers_deliver_one_encrypted_notification(): void
    {
        [$admin, $coach, $student, $course, $cohort, $enrollment, $announcement] = $this->cohortAnnouncementFixture();
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();echo app(\App\Services\Announcements::class)->deliver((int)$argv[1]);
CODE;
        $this->assertSame(['0', '1'], $this->parallelFinance($code, [[$announcement->id], [$announcement->id]]));
        $this->assertSame(1, DB::table('notifications')->where('type', PlatformAnnouncement::class)->count());
        $this->assertSame('completed', $announcement->fresh()->status);
    }

    private function cohortDeliveryAfterAccessChange(string $change): void
    {
        [$admin, $coach, $student, $course, $cohort, $enrollment, $announcement] = $this->cohortAnnouncementFixture(coachOnly: $change === 'coach');
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();\Illuminate\Support\Facades\DB::listen(function($query){if(str_contains($query->sql,'"announcements"')&&str_starts_with($query->sql,'select')&&!str_contains($query->sql,'for update')){echo "PEEKED\n";flush();}});echo app(\App\Services\Announcements::class)->deliver((int)$argv[1]);
CODE;
        $process = new Process([PHP_BINARY, '-r', $code, (string) $announcement->id], base_path(), null, null, 20);
        DB::transaction(function () use ($student, $course, $cohort, $enrollment, $process, $change) {
            Course::whereKey($course->id)->lockForUpdate()->firstOrFail();
            Cohort::whereKey($cohort->id)->lockForUpdate()->firstOrFail();
            $process->start();
            $this->assertTrue($process->waitUntil(fn ($type, $output) => str_contains($output, 'PEEKED')));
            if ($change === 'coach') {
                $course->update(['coach_id' => null]);
            } else {
                $enrollment->update(['status' => 'expired']);
                if ($change === 'renew') {
                    $renewed = app(EnrollmentService::class)->reserve($student, $cohort);
                    $this->assertSame(2, $renewed->billing_version);
                }
            }
        });
        $process->wait();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertStringEndsWith("\n0", $process->getOutput());
        $this->assertSame(0, DB::table('notifications')->where('type', PlatformAnnouncement::class)->count());
        $this->assertSame('skipped', $announcement->recipients()->sole()->status);
    }

    public function test_waiting_cohort_delivery_rechecks_ended_enrollment(): void
    {
        $this->cohortDeliveryAfterAccessChange('expire');
    }

    public function test_waiting_cohort_delivery_rechecks_real_renewal_billing_generation(): void
    {
        $this->cohortDeliveryAfterAccessChange('renew');
    }

    public function test_waiting_cohort_delivery_rechecks_teacher_reassignment(): void
    {
        $this->cohortDeliveryAfterAccessChange('coach');
    }

    public function test_waiting_cohort_publication_excludes_concurrently_ended_enrollment(): void
    {
        [$admin, $coach, $student, $course, $cohort, $enrollment, $announcement] = $this->cohortAnnouncementFixture(false);
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();$actor=\App\Models\User::findOrFail((int)$argv[1]);$announcement=\App\Models\Announcement::findOrFail((int)$argv[2]);\Illuminate\Support\Facades\DB::listen(function($query){if(str_contains($query->sql,'"announcements"')&&str_starts_with($query->sql,'select')&&!str_contains($query->sql,'for update')){echo "PEEKED\n";flush();}});try{app(\App\Services\Announcements::class)->publish($actor,$announcement,1);echo 'PUBLISHED';}catch(\Illuminate\Validation\ValidationException){echo 'NO_AUDIENCE';}
CODE;
        $process = new Process([PHP_BINARY, '-r', $code, (string) $admin->id, (string) $announcement->id], base_path(), null, null, 20);
        DB::transaction(function () use ($cohort, $enrollment, $process) {
            Cohort::whereKey($cohort->id)->lockForUpdate()->firstOrFail();
            $process->start();
            $this->assertTrue($process->waitUntil(fn ($type, $output) => str_contains($output, 'PEEKED')));
            $enrollment->update(['status' => 'expired']);
        });
        $process->wait();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertStringEndsWith('NO_AUDIENCE', $process->getOutput());
        $this->assertSame('draft', $announcement->fresh()->status);
        $this->assertSame(0, $announcement->recipients()->count());
    }

    public function test_cohort_to_organization_edit_racing_publication_cannot_change_frozen_target(): void
    {
        [$admin, $coach, $student, $course, $cohort, $enrollment, $announcement] = $this->cohortAnnouncementFixture(false);
        $organization = Organization::factory()->create(['status' => 'active']);
        $member = User::factory()->create();
        $member->roles()->attach(Role::where('key', 'student')->value('id'));
        $organization->members()->attach($member->id, ['kind' => 'member']);
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();$service=app(\App\Services\Announcements::class);$actor=\App\Models\User::findOrFail((int)$argv[1]);$announcement=\App\Models\Announcement::findOrFail((int)$argv[2]);try{if($argv[3]==='publish'){$service->publish($actor,$announcement,1);echo 'PUBLISHED';}else{$service->save($actor,['title'=>'Changed organization target','message'=>'Changed private audience','roles'=>['student'],'audience'=>['type'=>'organization','organization_id'=>(int)$argv[4]]],$announcement,1);echo 'EDITED';}}catch(\Illuminate\Validation\ValidationException){echo 'STALE';}
CODE;
        $result = $this->parallelFinance($code, [[$admin->id, $announcement->id, 'publish', $organization->id], [$admin->id, $announcement->id, 'edit', $organization->id]]);
        $this->assertTrue(in_array($result, [['EDITED', 'STALE'], ['PUBLISHED', 'STALE']], true));
        $published = in_array('PUBLISHED', $result, true);
        $this->assertSame($published ? 'cohort' : 'organization', $announcement->fresh()->audience['type']);
        $this->assertSame($published ? [$student->id] : [], $announcement->recipients()->pluck('user_id')->all());
    }

    private function adminMutationAfterConcurrentChange(bool $assignment, string $change, bool $changeCoach = false): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Admin access mutation races require PostgreSQL.');
        }
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('key', 'admin')->value('id'));
        $student = User::factory()->create();
        $student->roles()->attach(Role::where('key', 'student')->value('id'));
        $coach = User::factory()->create();
        $coach->roles()->attach(Role::where('key', 'coach')->value('id'));
        $course = Course::factory()->create(['coach_id' => null]);
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();$actor=\App\Models\User::findOrFail((int)$argv[1]);$assignment=$argv[4]==='assign';$data=$assignment?['coach_id'=>(int)$argv[5]]:['revision'=>\App\Models\User::findOrFail((int)$argv[2])->access_revision,'roles'=>['student','coach'],'is_active'=>true];$request=\Illuminate\Http\Request::create('/admin/synthetic','PATCH',$data);$request->setUserResolver(fn()=>$actor);try{(new \App\Http\Middleware\EnsurePanelRole)->handle($request,function($request)use($assignment,$argv){echo "READY\n";flush();$controller=app(\App\Http\Controllers\AdminUserController::class);return $assignment?$controller->assignCoach($request,\App\Models\Course::findOrFail((int)$argv[3])):$controller->update($request,\App\Models\User::findOrFail((int)$argv[2]));},'admin');echo 'UPDATED';}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){echo (string)$e->getStatusCode();}catch(\Illuminate\Validation\ValidationException){echo 'INVALID_COACH';}
CODE;
        $process = new Process([PHP_BINARY, '-r', $code, (string) $admin->id, (string) $student->id, (string) $course->id, $assignment ? 'assign' : 'access', (string) $coach->id], base_path(), null, null, 20);
        DB::transaction(function () use ($admin, $coach, $course, $process, $assignment, $change, $changeCoach) {
            if ($assignment) {
                Course::whereKey($course->id)->lockForUpdate()->firstOrFail();
            }
            $changed = User::whereKey($changeCoach ? $coach->id : $admin->id)->lockForUpdate()->firstOrFail();
            $process->start();
            $this->assertTrue($process->waitUntil(fn ($type, $output) => str_contains($output, 'READY')));
            if ($change === 'role') {
                $changed->roles()->detach();
            } else {
                $changed->forceFill(['is_active' => false])->save();
            }
        });
        $process->wait();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertSame("READY\n".($changeCoach ? 'INVALID_COACH' : '403'), $process->getOutput());
        $this->assertNull($course->fresh()->coach_id);
        $this->assertFalse($student->hasRole('coach'));
        $this->assertSame(0, AuditEvent::count());
    }

    public function test_waiting_admin_user_access_update_rechecks_revoked_actor_role(): void
    {
        $this->adminMutationAfterConcurrentChange(false, 'role');
    }

    public function test_waiting_admin_user_access_update_rechecks_deactivated_actor(): void
    {
        $this->adminMutationAfterConcurrentChange(false, 'inactive');
    }

    public function test_waiting_admin_coach_assignment_rechecks_revoked_actor_role(): void
    {
        $this->adminMutationAfterConcurrentChange(true, 'role');
    }

    public function test_waiting_admin_coach_assignment_rechecks_candidate_coach_role(): void
    {
        $this->adminMutationAfterConcurrentChange(true, 'role', true);
    }

    public function test_waiting_admin_coach_assignment_rechecks_candidate_coach_activity(): void
    {
        $this->adminMutationAfterConcurrentChange(true, 'inactive', true);
    }

    public function test_two_previously_authorized_admins_cannot_concurrently_revoke_each_other(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Cross-admin revocation races require PostgreSQL.');
        }
        $first = User::factory()->create();
        $second = User::factory()->create();
        foreach ([$first, $second] as $admin) {
            $admin->roles()->attach(Role::where('key', 'admin')->value('id'));
        }
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();$actor=\App\Models\User::findOrFail((int)$argv[1]);$request=\Illuminate\Http\Request::create('/admin/synthetic','PATCH',['revision'=>\App\Models\User::findOrFail((int)$argv[2])->access_revision,'roles'=>['student'],'is_active'=>true]);$request->setUserResolver(fn()=>$actor);try{(new \App\Http\Middleware\EnsurePanelRole)->handle($request,function($request)use($argv){echo "READY\n";flush();return app(\App\Http\Controllers\AdminUserController::class)->update($request,\App\Models\User::findOrFail((int)$argv[2]));},'admin');echo 'UPDATED';}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){echo (string)$e->getStatusCode();}
CODE;
        $processes = [new Process([PHP_BINARY, '-r', $code, (string) $first->id, (string) $second->id], base_path(), null, null, 20), new Process([PHP_BINARY, '-r', $code, (string) $second->id, (string) $first->id], base_path(), null, null, 20)];
        DB::transaction(function () use ($first, $second, $processes) {
            User::whereIn('id', [$first->id, $second->id])->orderBy('id')->lockForUpdate()->get();
            foreach ($processes as $process) {
                $process->start();
            }
            foreach ($processes as $process) {
                $this->assertTrue($process->waitUntil(fn ($type, $output) => str_contains($output, 'READY')));
            }
        });
        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $results[] = $process->getOutput();
        }
        sort($results);
        $this->assertSame(["READY\n403", "READY\nUPDATED"], $results);
        $this->assertSame(1, User::where('is_active', true)->whereHas('roles', fn ($roles) => $roles->where('key', 'admin'))->count());
        $this->assertSame(1, AuditEvent::where('action', 'user.access_updated')->count());
    }

    private function organizationLearningExportAfterAccessChange(bool $departMember, bool $asAdmin = false, ?string $dataset = null): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Organization learning export races require PostgreSQL.');
        }
        $actor = User::factory()->create();
        $actor->roles()->attach(Role::where('key', $asAdmin ? 'admin' : 'org_admin')->value('id'));
        $member = User::factory()->create();
        $member->roles()->attach(Role::where('key', 'student')->value('id'));
        $organization = Organization::factory()->create(['status' => 'active']);
        if (! $asAdmin) {
            $organization->members()->attach($actor->id, ['kind' => 'manager']);
        }
        $organization->members()->attach($member->id, ['kind' => 'member']);
        $key = $organization->members()->where('users.id', $member->id)->firstOrFail()->pivot->membership_key;
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();$actor=\App\Models\User::findOrFail((int)$argv[1]);$organization=\App\Models\Organization::findOrFail((int)$argv[2]);$member=\App\Models\User::findOrFail((int)$argv[3]);echo "READY\n";flush();try{$prepared=app(\App\Services\OrganizationLearningExports::class)->prepare($actor,$organization,$member,$argv[4]==='admin',$argv[5]);fclose($prepared['stream']);echo 'EXPORTED';}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){echo (string)$e->getStatusCode();}
CODE;
        $process = new Process([PHP_BINARY, '-r', $code, (string) $actor->id, (string) $organization->id, (string) $member->id, $asAdmin ? 'admin' : 'manager', $dataset ?? ($departMember || $asAdmin ? 'attendance' : 'submissions')], base_path(), null, null, 20);
        DB::transaction(function () use ($actor, $member, $organization, $key, $departMember, $process) {
            if ($departMember) {
                Organization::whereKey($organization->id)->lockForUpdate()->firstOrFail();
            } else {
                User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            }
            $process->start();
            $this->assertTrue($process->waitUntil(fn ($type, $output) => str_contains($output, 'READY')));
            if ($departMember) {
                app(OrganizationMembership::class)->endMembership($member, $organization, $member, $key, 'Synthetic departure during export preparation', 'leave');
            } else {
                $actor->roles()->detach();
            }
        });
        $process->wait();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertSame("READY\n403", $process->getOutput());
        $this->assertSame(0, AuditEvent::where('action', 'organization.learning_exported')->count());
    }

    public function test_waiting_organization_learning_export_rechecks_member_departure(): void
    {
        $this->organizationLearningExportAfterAccessChange(true);
    }

    public function test_waiting_organization_learning_export_rechecks_manager_role_revocation(): void
    {
        $this->organizationLearningExportAfterAccessChange(false);
    }

    public function test_waiting_organization_learning_export_rechecks_admin_role_revocation(): void
    {
        $this->organizationLearningExportAfterAccessChange(false, true);
    }

    private function concurrentAccountRevisionWrites(bool $consoleGrant): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Account access revision races require PostgreSQL.');
        }
        $first = User::factory()->create();
        $second = User::factory()->create();
        foreach ([$first, $second] as $admin) {
            $admin->roles()->attach(Role::where('key', 'admin')->value('id'));
        }
        $target = User::factory()->create();
        $target->roles()->attach(Role::where('key', 'student')->value('id'));
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();$actor=\App\Models\User::findOrFail((int)$argv[1]);$target=\App\Models\User::findOrFail((int)$argv[2]);if($argv[3]==='grant'){echo "READY\n";flush();$result=\Illuminate\Support\Facades\Artisan::call('platform:grant-role',['phone'=>$target->phone,'role'=>'coach']);echo $result===0?'GRANTED':'FAILED';return;}$request=\Illuminate\Http\Request::create('/admin/synthetic','PATCH',['revision'=>1,'roles'=>$argv[3]==='coach'?['student','coach']:['student'],'is_active'=>$argv[3]!=='deactivate']);$request->setUserResolver(fn()=>$actor);try{(new \App\Http\Middleware\EnsurePanelRole)->handle($request,function($request)use($target){echo "READY\n";flush();return app(\App\Http\Controllers\AdminUserController::class)->update($request,$target);},'admin');echo 'UPDATED';}catch(\Illuminate\Validation\ValidationException $e){echo array_key_exists('revision',$e->errors())?'STALE':'INVALID';}
CODE;
        $processes = [new Process([PHP_BINARY, '-r', $code, (string) $first->id, (string) $target->id, $consoleGrant ? 'grant' : 'coach'], base_path(), null, null, 20), new Process([PHP_BINARY, '-r', $code, (string) $second->id, (string) $target->id, 'deactivate'], base_path(), null, null, 20)];
        DB::transaction(function () use ($first, $second, $target, $processes) {
            User::whereIn('id', [$first->id, $second->id, $target->id])->orderBy('id')->lockForUpdate()->get();
            foreach ($processes as $process) {
                $process->start();
            }
            foreach ($processes as $process) {
                $this->assertTrue($process->waitUntil(fn ($type, $output) => str_contains($output, 'READY')));
            }
        });
        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $results[] = $process->getOutput();
        }
        sort($results);
        if ($consoleGrant) {
            $this->assertContains($results, [["READY\nGRANTED", "READY\nSTALE"], ["READY\nGRANTED", "READY\nUPDATED"]]);
            $adminSaved = in_array("READY\nUPDATED", $results, true);
            $this->assertTrue($target->hasRole('coach'));
            $this->assertSame($adminSaved ? 3 : 2, $target->fresh()->access_revision);
            $this->assertSame(! $adminSaved, $target->fresh()->is_active);
            $this->assertSame($adminSaved ? 1 : 0, AuditEvent::where('action', 'user.access_updated')->count());
            $this->assertSame(1, AuditEvent::where('action', 'role.granted')->count());
        } else {
            $this->assertSame(["READY\nSTALE", "READY\nUPDATED"], $results);
            $this->assertSame(2, $target->fresh()->access_revision);
            $this->assertSame($target->hasRole('coach'), $target->fresh()->is_active);
            $this->assertSame(1, AuditEvent::where('action', 'user.access_updated')->count());
        }
    }

    public function test_two_stale_admin_access_forms_apply_only_one_revision(): void
    {
        $this->concurrentAccountRevisionWrites(false);
    }

    public function test_console_role_grant_racing_stale_admin_form_is_never_silently_removed(): void
    {
        $this->concurrentAccountRevisionWrites(true);
    }

    private function assessmentExportAfterAccessChange(string $mode): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Assessment report export races require PostgreSQL.');
        }
        $owner = User::factory()->create();
        $owner->roles()->attach(Role::where('key', 'student')->value('id'));
        $actor = $mode === 'owner' ? $owner : User::factory()->create();
        if ($mode !== 'owner') {
            $actor->roles()->attach(Role::where('key', $mode === 'admin' ? 'admin' : 'org_admin')->value('id'));
        }
        $organization = $mode === 'organization' ? Organization::factory()->create(['status' => 'active']) : null;
        if ($organization) {
            $organization->members()->attach($actor->id, ['kind' => 'manager']);
            $organization->members()->attach($owner->id, ['kind' => 'member']);
        }
        $version = AssessmentVersion::factory()->create();
        $result = app(AssessmentService::class)->score($version->definition, ['q1' => 5, 'q2' => 5]);
        $attempt = AssessmentAttempt::factory()->create(['user_id' => $owner->id, 'assessment_version_id' => $version->id, 'status' => 'completed', 'result' => $result, 'completed_at' => now()]);
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();$actor=\App\Models\User::findOrFail((int)$argv[1]);$attempt=\App\Models\AssessmentAttempt::findOrFail((int)$argv[2]);$organization=$argv[3]!=='0'?\App\Models\Organization::findOrFail((int)$argv[3]):null;\Illuminate\Support\Facades\DB::listen(function($query)use($argv){if($argv[4]==='admin'&&str_contains($query->sql,'roles')&&in_array('admin',$query->bindings,true)){echo "CHECKED
";flush();}elseif($argv[4]!=='admin'&&str_contains($query->sql,'"assessment_attempts"')&&!str_contains($query->sql,'for share')){echo "PEEKED
";flush();}});try{$prepared=app(\App\Services\AssessmentReportExports::class)->prepare($actor,$attempt,$organization);fclose($prepared['stream']);echo 'EXPORTED';}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){echo (string)$e->getStatusCode();}
CODE;
        $process = new Process([PHP_BINARY, '-r', $code, (string) $actor->id, (string) $attempt->id, (string) ($organization?->id ?? 0), $mode], base_path(), null, null, 20);
        DB::transaction(function () use ($actor, $owner, $organization, $mode, $process) {
            if ($organization) {
                Organization::whereKey($organization->id)->lockForUpdate()->firstOrFail();
            } else {
                User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            }
            $process->start();
            $marker = $mode === 'admin' ? 'CHECKED' : 'PEEKED';
            $this->assertTrue($process->waitUntil(fn ($type, $output) => str_contains($output, $marker)));
            if ($organization) {
                $key = $organization->members()->where('users.id', $owner->id)->firstOrFail()->pivot->membership_key;
                app(OrganizationMembership::class)->endMembership($owner, $organization, $owner, $key, 'Synthetic departure during private assessment export', 'leave');
            } elseif ($mode === 'admin') {
                $actor->roles()->detach();
            } else {
                $actor->forceFill(['is_active' => false])->save();
            }
        });
        $process->wait();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertStringEndsWith('403', $process->getOutput());
        $this->assertSame(0, AuditEvent::where('action', 'assessment.report_exported')->count());
    }

    public function test_waiting_assessment_export_rechecks_owner_deactivation(): void
    {
        $this->assessmentExportAfterAccessChange('owner');
    }

    public function test_waiting_assessment_export_rechecks_previously_authorized_admin_role(): void
    {
        $this->assessmentExportAfterAccessChange('admin');
    }

    public function test_waiting_assessment_export_rechecks_current_organization_membership(): void
    {
        $this->assessmentExportAfterAccessChange('organization');
    }

    private function credentialRaceFixture(): array
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Private credential races require PostgreSQL.');
        }
        Storage::fake('learning');
        $coach = User::factory()->create();
        $coach->roles()->attach(Role::where('key', 'coach')->value('id'));
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('key', 'admin')->value('id'));
        $file = UploadedFile::fake()->createWithContent('software-race.pdf', "%PDF-1.4\n% SOFTWARE-FIXTURE-ONLY\n%%EOF\n");
        $input = ['title' => 'Software credential race fixture', 'issuer' => 'Not a real credential', 'consent' => true, 'request_key' => (string) Str::uuid()];

        return [$coach, $admin, $file, $input, Storage::disk('learning')->path('')];
    }

    public function test_waiting_private_credential_review_rechecks_revoked_admin_role(): void
    {
        [$coach, $admin, $file, $input, $root] = $this->credentialRaceFixture();
        $credential = app(CoachCredentials::class)->submit($coach, $input, $file);
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();config(['filesystems.disks.learning.root'=>$argv[3]]);$actor=\App\Models\User::findOrFail((int)$argv[1]);$credential=\App\Models\CoachCredential::findOrFail((int)$argv[2]);echo 'READY'.PHP_EOL;flush();try{app(\App\Services\CoachCredentials::class)->change($actor,$credential,['revision'=>1,'action'=>'accept','reviewed'=>true]);echo 'REVIEWED';}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){echo (string)$e->getStatusCode();}
CODE;
        $process = new Process([PHP_BINARY, '-r', $code, (string) $admin->id, (string) $credential->id, $root], base_path(), null, null, 20);
        DB::transaction(function () use ($admin, $process) {
            User::whereKey($admin->id)->lockForUpdate()->firstOrFail();
            $process->start();
            $this->assertTrue($process->waitUntil(fn ($type, $output) => str_contains($output, 'READY')));
            $admin->roles()->detach();
        });
        $process->wait();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertStringEndsWith('403', $process->getOutput());
        $this->assertSame('submitted', $credential->fresh()->status);
        $this->assertSame(1, $credential->events()->count());
        $this->assertSame(0, DB::table('notifications')->where('type', CoachCredentialChanged::class)->count());
    }

    public function test_concurrent_private_credential_review_and_owner_archive_have_one_revision_winner(): void
    {
        [$coach, $admin, $file, $input, $root] = $this->credentialRaceFixture();
        $credential = app(CoachCredentials::class)->submit($coach, $input, $file);
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();config(['filesystems.disks.learning.root'=>$argv[3]]);$actor=\App\Models\User::findOrFail((int)$argv[1]);$credential=\App\Models\CoachCredential::findOrFail((int)$argv[2]);echo 'READY'.PHP_EOL;flush();try{app(\App\Services\CoachCredentials::class)->change($actor,$credential,['revision'=>1,'action'=>$argv[4]==='review'?'accept':'withdraw','reason'=>'Synthetic concurrent decision','reviewed'=>true]);echo $argv[4]==='review'?'REVIEWED':'WITHDRAWN';}catch(\Illuminate\Validation\ValidationException){echo 'STALE';}
CODE;
        $processes = [new Process([PHP_BINARY, '-r', $code, (string) $admin->id, (string) $credential->id, $root, 'review'], base_path(), null, null, 20), new Process([PHP_BINARY, '-r', $code, (string) $coach->id, (string) $credential->id, $root, 'withdraw'], base_path(), null, null, 20)];
        DB::transaction(function () use ($coach, $admin, $processes) {
            User::whereIn('id', [$coach->id, $admin->id])->orderBy('id')->lockForUpdate()->get();
            foreach ($processes as $process) {
                $process->start();
            }
            foreach ($processes as $process) {
                $this->assertTrue($process->waitUntil(fn ($type, $output) => str_contains($output, 'READY')));
            }
        });
        $results = [];
        foreach ($processes as $process) {
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $results[] = trim(str_replace('READY', '', $process->getOutput()));
        }
        sort($results);
        $this->assertContains($results, [['REVIEWED', 'STALE'], ['STALE', 'WITHDRAWN']]);
        $this->assertSame(2, $credential->fresh()->revision);
        $this->assertSame(2, $credential->events()->count());
        $this->assertSame(in_array('REVIEWED', $results, true) ? 'accepted' : 'withdrawn', $credential->fresh()->status);
        $this->assertSame(in_array('REVIEWED', $results, true) ? 1 : 0, DB::table('notifications')->where('type', CoachCredentialChanged::class)->count());
        Storage::disk('learning')->assertExists($credential->file_path);
    }

    public function test_waiting_credential_upload_rechecks_coach_role_after_initial_middleware(): void
    {
        [$coach, $admin, $file, $input, $root] = $this->credentialRaceFixture();
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();config(['filesystems.disks.learning.root'=>$argv[3]]);$actor=\App\Models\User::findOrFail((int)$argv[1]);$request=\Illuminate\Http\Request::create('/coach-credentials','POST');$request->setUserResolver(fn()=>$actor);try{(new \App\Http\Middleware\EnsurePanelRole)->handle($request,function()use($actor,$argv){echo 'READY'.PHP_EOL;flush();$file=new \Illuminate\Http\UploadedFile($argv[2],'software-race.pdf','application/pdf',UPLOAD_ERR_OK,true);app(\App\Services\CoachCredentials::class)->submit($actor,['title'=>'Software upload race','issuer'=>'No real credential','consent'=>true,'request_key'=>$argv[4]],$file);return new \Symfony\Component\HttpFoundation\Response('UPLOADED');},'coach');echo 'UPLOADED';}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){echo (string)$e->getStatusCode();}
CODE;
        $process = new Process([PHP_BINARY, '-r', $code, (string) $coach->id, $file->getRealPath(), $root, $input['request_key']], base_path(), null, null, 20);
        DB::transaction(function () use ($coach, $process) {
            User::whereKey($coach->id)->lockForUpdate()->firstOrFail();
            $process->start();
            $this->assertTrue($process->waitUntil(fn ($type, $output) => str_contains($output, 'READY')));
            $coach->roles()->detach();
        });
        $process->wait();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertStringEndsWith('403', $process->getOutput());
        $this->assertSame(0, CoachCredential::count());
        $this->assertSame([], Storage::disk('learning')->allFiles());
    }

    private function linkedInGuideAfterAccessChange(bool $asAdmin): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Private LinkedIn guide access races require PostgreSQL.');
        }
        $owner = User::factory()->create();
        $owner->roles()->attach(Role::where('key', 'student')->value('id'));
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('key', 'admin')->value('id'));
        $builder = app(ResumeBuilder::class);
        $resume = $builder->create($owner, 'Synthetic LinkedIn access race');
        $version = $builder->snapshot($owner, $resume, 1);
        $actor = $asAdmin ? $admin : $owner;
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();$actor=\App\Models\User::findOrFail((int)$argv[1]);$resume=\App\Models\CareerResume::findOrFail((int)$argv[2]);$version=\App\Models\ResumeVersion::findOrFail((int)$argv[3]);$request=\Illuminate\Http\Request::create('/career/resumes/'.$resume->id.'/versions/'.$version->id.'/linkedin/download','GET');$request->setUserResolver(fn()=>$actor);try{(new \App\Http\Middleware\EnsureAccountActive)->handle($request,function()use($actor,$resume,$version){echo 'READY'.PHP_EOL;flush();app(\App\Services\LinkedInGuide::class)->prepare($actor,$resume,$version,true);return new \Symfony\Component\HttpFoundation\Response('EXPORTED');});echo 'EXPORTED';}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){echo (string)$e->getStatusCode();}
CODE;
        $process = new Process([PHP_BINARY, '-r', $code, (string) $actor->id, (string) $resume->id, (string) $version->id], base_path(), null, null, 20);
        DB::transaction(function () use ($actor, $asAdmin, $process) {
            User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            $process->start();
            $this->assertTrue($process->waitUntil(fn ($type, $output) => str_contains($output, 'READY')));
            if ($asAdmin) {
                $actor->roles()->detach();
            } else {
                $actor->forceFill(['is_active' => false])->save();
                $this->assertFalse($actor->fresh()->is_active);
            }
        });
        $process->wait();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertStringEndsWith('403', $process->getOutput());
        $this->assertSame(0, AuditEvent::where('action', 'like', 'resume.linkedin_%')->count());
    }

    public function test_waiting_linkedin_guide_export_rechecks_owner_deactivation_after_middleware(): void
    {
        $this->linkedInGuideAfterAccessChange(false);
    }

    public function test_waiting_linkedin_guide_export_rechecks_admin_role_after_middleware(): void
    {
        $this->linkedInGuideAfterAccessChange(true);
    }

    public function test_waiting_organization_review_history_export_rechecks_member_departure(): void
    {
        $this->organizationLearningExportAfterAccessChange(true, false, 'reviews');
    }

    public function test_waiting_organization_review_history_export_rechecks_manager_role(): void
    {
        $this->organizationLearningExportAfterAccessChange(false, false, 'reviews');
    }

    public function test_waiting_organization_review_history_export_rechecks_admin_role(): void
    {
        $this->organizationLearningExportAfterAccessChange(false, true, 'reviews');
    }

    private function resumeShareRaceFixture(): array
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Resume sharing races require PostgreSQL.');
        }
        $owner = User::factory()->create();
        $owner->roles()->attach(Role::where('key', 'student')->value('id'));
        $builder = app(ResumeBuilder::class);
        $resume = $builder->create($owner, 'Synthetic consented sharing race');
        $version = $builder->snapshot($owner, $resume, 1);

        return [$owner, $resume->fresh(), $version];
    }

    public function test_concurrent_resume_share_publications_create_one_current_link(): void
    {
        [$owner, $resume, $version] = $this->resumeShareRaceFixture();
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();$actor=\App\Models\User::findOrFail((int)$argv[1]);$resume=\App\Models\CareerResume::findOrFail((int)$argv[2]);$version=\App\Models\ResumeVersion::findOrFail((int)$argv[3]);try{app(\App\Services\ResumeSharing::class)->publish($actor,$resume,$version,['revision'=>2,'request_key'=>$argv[4],'duration_days'=>7,'include_contacts'=>false,'reviewed'=>true,'consent'=>true]);echo 'PUBLISHED';}catch(\Illuminate\Validation\ValidationException){echo 'BLOCKED';}
CODE;
        $results = $this->parallelFinance($code, [[$owner->id, $resume->id, $version->id, (string) Str::uuid()], [$owner->id, $resume->id, $version->id, (string) Str::uuid()]]);
        $this->assertSame(['BLOCKED', 'PUBLISHED'], $results);
        $this->assertDatabaseCount('resume_shares', 1);
        $this->assertSame(1, AuditEvent::where('action', 'resume_share.published')->count());
        $share = ResumeShare::sole();
        $token = app(ResumeSharing::class)->metadata($share)['token'];
        $this->assertSame(1, app(ResumeSharing::class)->publicPayload($token)['version']);
    }

    public function test_concurrent_resume_archive_and_publication_never_leave_a_visible_link(): void
    {
        [$owner, $resume, $version] = $this->resumeShareRaceFixture();
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();$actor=\App\Models\User::findOrFail((int)$argv[1]);$resume=\App\Models\CareerResume::findOrFail((int)$argv[2]);$version=\App\Models\ResumeVersion::findOrFail((int)$argv[3]);try{if($argv[4]==='archive'){app(\App\Services\ResumeBuilder::class)->archive($actor,$resume,2,true);echo 'ARCHIVED';}else{app(\App\Services\ResumeSharing::class)->publish($actor,$resume,$version,['revision'=>2,'request_key'=>$argv[5],'duration_days'=>7,'include_contacts'=>false,'reviewed'=>true,'consent'=>true]);echo 'PUBLISHED';}}catch(\Illuminate\Validation\ValidationException){echo 'BLOCKED';}
CODE;
        $results = $this->parallelFinance($code, [[$owner->id, $resume->id, $version->id, 'archive', (string) Str::uuid()], [$owner->id, $resume->id, $version->id, 'publish', (string) Str::uuid()]]);
        $this->assertContains($results, [['ARCHIVED', 'BLOCKED'], ['ARCHIVED', 'PUBLISHED']]);
        $this->assertTrue($resume->fresh()->archived);
        $this->assertSame(0, ResumeShare::whereNull('revoked_at')->count());
        $this->assertSame(ResumeShare::count(), AuditEvent::where('action', 'resume_share.revoked')->count());
    }

    public function test_waiting_resume_share_admin_revocation_rechecks_lost_role(): void
    {
        [$owner, $resume, $version] = $this->resumeShareRaceFixture();
        $share = app(ResumeSharing::class)->publish($owner, $resume, $version, ['revision' => 2, 'request_key' => (string) Str::uuid(), 'duration_days' => 7, 'include_contacts' => false, 'reviewed' => true, 'consent' => true]);
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('key', 'admin')->value('id'));
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();$actor=\App\Models\User::findOrFail((int)$argv[1]);$share=\App\Models\ResumeShare::findOrFail((int)$argv[2]);$request=\Illuminate\Http\Request::create('/career/shares/'.$share->id.'/revoke','POST');$request->setUserResolver(fn()=>$actor);try{(new \App\Http\Middleware\EnsurePanelRole)->handle($request,function()use($actor,$share){echo 'READY'.PHP_EOL;flush();app(\App\Services\ResumeSharing::class)->revoke($actor,$share,['revision'=>1,'reason'=>'Synthetic admin decision']);return new \Symfony\Component\HttpFoundation\Response('REVOKED');},'admin');echo 'REVOKED';}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){echo (string)$e->getStatusCode();}
CODE;
        $process = new Process([PHP_BINARY, '-r', $code, (string) $admin->id, (string) $share->id], base_path(), null, null, 20);
        DB::transaction(function () use ($admin, $process) {
            User::whereKey($admin->id)->lockForUpdate()->firstOrFail();
            $process->start();
            $this->assertTrue($process->waitUntil(fn ($type, $output) => str_contains($output, 'READY')));
            $admin->roles()->detach();
        });
        $process->wait();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertStringEndsWith('403', $process->getOutput());
        $this->assertNull($share->fresh()->revoked_at);
        $this->assertSame(0, AuditEvent::where('action', 'resume_share.revoked')->count());
    }

    public function test_waiting_public_resume_share_read_rechecks_owner_deactivation(): void
    {
        [$owner, $resume, $version] = $this->resumeShareRaceFixture();
        $share = app(ResumeSharing::class)->publish($owner, $resume, $version, ['revision' => 2, 'request_key' => (string) Str::uuid(), 'duration_days' => 7, 'include_contacts' => false, 'reviewed' => true, 'consent' => true]);
        $token = app(ResumeSharing::class)->metadata($share)['token'];
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();$share=\App\Models\ResumeShare::findOrFail((int)$argv[1]);$token=app(\App\Services\ResumeSharing::class)->metadata($share)['token'];echo 'READY'.PHP_EOL;flush();try{app(\App\Services\ResumeSharing::class)->publicPayload($token);echo 'VISIBLE';}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){echo (string)$e->getStatusCode();}
CODE;
        $process = new Process([PHP_BINARY, '-r', $code, (string) $share->id], base_path(), null, null, 20);
        DB::transaction(function () use ($owner, $process) {
            User::whereKey($owner->id)->lockForUpdate()->firstOrFail();
            $process->start();
            $this->assertTrue($process->waitUntil(fn ($type, $output) => str_contains($output, 'READY')));
            $owner->forceFill(['is_active' => false])->save();
        });
        $process->wait();
        $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
        $this->assertStringEndsWith('404', $process->getOutput());
        $this->assertNull($share->fresh()->revoked_at);
    }

    private function consultationWriteAfterAccessChange(string $action, string $identity = 'admin', bool $deactivate = false): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Consultation authorization row-lock races require PostgreSQL.');
        }
        $slot = ConsultationSlot::factory()->create(['published' => false]);
        $booking = $action === 'cancel' ? Consultation::factory()->create(['consultation_slot_id' => $slot->id]) : null;
        $request = $action === 'resolve' ? ConsultationRequest::factory()->create() : null;
        $actor = match ($identity) {
            'coach' => $slot->coach,
            'owner' => $booking->student,
            default => User::factory()->create(),
        };
        if ($identity === 'admin' || $action === 'create_paid') {
            $actor->roles()->attach(Role::where('key', 'admin')->value('id'));
        }
        $checkRole = $identity === 'owner' ? 'owner' : ($action === 'create_paid' ? 'admin' : $identity);
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();$action=$argv[1];$actor=\App\Models\User::findOrFail((int)$argv[2]);$slot=\App\Models\ConsultationSlot::findOrFail((int)$argv[3]);$booking=(int)$argv[4]?\App\Models\Consultation::findOrFail((int)$argv[4]):null;$request=(int)$argv[5]?\App\Models\ConsultationRequest::findOrFail((int)$argv[5]):null;$role=$argv[6];$checks=0;$required=$action==='create_paid'?2:1;
\Illuminate\Support\Facades\DB::listen(function($query)use($role,$required,&$checks){if(str_contains($query->sql,'roles')&&in_array($role,$query->bindings,true)&&++$checks===$required){echo "CHECKED\n";flush();}});
if($role==='owner'){echo "CHECKED\n";flush();}
try{$service=app(\App\Services\ConsultationService::class);match($action){'create','create_paid'=>$service->createSlot($actor,$slot->coach,\Carbon\CarbonImmutable::now()->addDays(2),60,'meet',null,$action==='create_paid'?100000:0),'resolve'=>$service->resolve($actor,$request,1,'Software-only access race'),'cancel'=>$service->change($actor,$booking,1,'cancel','Software-only access race',null),'publish'=>$service->publish($actor,$slot,true),'meeting'=>$service->meeting($actor,$slot,'https://meet.google.com/xyz-abcd-efg')};echo 'COMMITTED';}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){echo (string)$e->getStatusCode();}
CODE;
        $process = new Process([PHP_BINARY, '-r', $code, $action, (string) $actor->id, (string) $slot->id, (string) ($booking?->id ?? 0), (string) ($request?->id ?? 0), $checkRole], base_path(), null, null, 20);
        try {
            DB::transaction(function () use ($actor, $process, $deactivate) {
                User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
                $process->start();
                $this->assertTrue($process->waitUntil(fn ($type, $output) => str_contains($output, 'CHECKED')), $process->getErrorOutput());
                if ($deactivate) {
                    User::whereKey($actor->id)->update(['is_active' => false]);
                } else {
                    $actor->roles()->detach(Role::where('key', 'admin')->value('id'));
                }
            });
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $this->assertStringEndsWith('403', $process->getOutput());
        } finally {
            if ($process->isRunning()) {
                $process->stop();
            }
        }
        $this->assertDatabaseCount('consultation_slots', 1);
        $this->assertFalse($slot->fresh()->published);
        $this->assertSame('https://meet.google.com/abc-defg-hij', $slot->fresh()->meeting_url);
        $this->assertDatabaseCount('audit_events', 0);
        $this->assertDatabaseCount('notifications', 0);
        if ($booking) {
            $this->assertSame('confirmed', $booking->fresh()->status);
            $this->assertSame(1, $booking->fresh()->version);
        }
        if ($request) {
            $this->assertSame('pending', $request->fresh()->status);
            $this->assertSame(1, $request->fresh()->version);
        }
    }

    public function test_waiting_consultation_slot_creation_rechecks_current_admin_role(): void
    {
        $this->consultationWriteAfterAccessChange('create');
    }

    public function test_waiting_consultation_request_close_rechecks_current_admin_role(): void
    {
        $this->consultationWriteAfterAccessChange('resolve');
    }

    public function test_waiting_consultation_cancellation_rechecks_current_admin_role(): void
    {
        $this->consultationWriteAfterAccessChange('cancel');
    }

    public function test_waiting_consultation_publication_rechecks_current_admin_role(): void
    {
        $this->consultationWriteAfterAccessChange('publish');
    }

    public function test_waiting_consultation_meeting_link_rechecks_current_admin_role(): void
    {
        $this->consultationWriteAfterAccessChange('meeting');
    }

    public function test_waiting_own_paid_slot_creation_rechecks_admin_price_permission_even_if_coach_role_remains(): void
    {
        $this->consultationWriteAfterAccessChange('create_paid', 'coach');
    }

    public function test_waiting_coach_meeting_link_rechecks_current_activity(): void
    {
        $this->consultationWriteAfterAccessChange('meeting', 'coach', true);
    }

    public function test_waiting_owner_cancellation_rechecks_current_activity(): void
    {
        $this->consultationWriteAfterAccessChange('cancel', 'owner', true);
    }

    private function consultationFinancialAfterAccessChange(bool $grantOperation, bool $existing, bool $deactivate = false): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Consultation financial authorization races require PostgreSQL.');
        }
        $record = $grantOperation ? ConsultationGrant::factory()->create() : Consultation::factory()->create(['consultation_slot_id' => ConsultationSlot::factory()->state(['price' => 1000000]), 'status' => 'pending_payment', 'amount' => 1000000, 'listed_amount' => 1000000, 'expires_at' => now()->addMinutes(10)]);
        $actor = $grantOperation ? User::factory()->create() : $record->student;
        if ($grantOperation) {
            $actor->roles()->attach(Role::where('key', 'admin')->value('id'));
        } elseif ($existing) {
            app(ConsultationBilling::class)->attempt($actor, $record);
        }
        $before = $record->fresh()->getAttributes();
        $role = $grantOperation ? 'admin' : 'student';
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();$grantOperation=$argv[1]==='grant';$actor=\App\Models\User::findOrFail((int)$argv[2]);$record=$grantOperation?\App\Models\ConsultationGrant::findOrFail((int)$argv[3]):\App\Models\Consultation::findOrFail((int)$argv[3]);$existing=$argv[4]==='1';$role=$argv[5];$checked=false;
\Illuminate\Support\Facades\DB::listen(function($query)use($role,&$checked){if(!$checked&&str_contains($query->sql,'roles')&&in_array($role,$query->bindings,true)){$checked=true;echo "CHECKED\n";flush();}});
try{if($grantOperation){$data=['title'=>'Software-only grant race','source_reference'=>'SOFTWARE-ONLY-RACE62','total_sessions'=>5,'session_minutes'=>60,'valid_until'=>now()->addMonth()->toDateString(),'revision'=>1,'active'=>false];app(\App\Services\ConsultationPackages::class)->configure($actor,$record->student,$record->coach,$data,$existing?$record:null);}else{app(\App\Services\ConsultationBilling::class)->attempt($actor,$record);}echo 'COMMITTED';}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){echo (string)$e->getStatusCode();}
CODE;
        $process = new Process([PHP_BINARY, '-r', $code, $grantOperation ? 'grant' : 'payment', (string) $actor->id, (string) $record->id, $existing ? '1' : '0', $role], base_path(), null, null, 20);
        try {
            DB::transaction(function () use ($actor, $process, $deactivate, $role) {
                User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
                $process->start();
                $this->assertTrue($process->waitUntil(fn ($type, $output) => str_contains($output, 'CHECKED')), $process->getErrorOutput());
                if ($deactivate) {
                    User::whereKey($actor->id)->update(['is_active' => false]);
                } else {
                    $actor->roles()->detach(Role::where('key', $role)->value('id'));
                }
            });
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $this->assertStringEndsWith('403', $process->getOutput());
        } finally {
            if ($process->isRunning()) {
                $process->stop();
            }
        }
        $this->assertSame($before, $record->fresh()->getAttributes());
        $this->assertDatabaseCount('payments', ! $grantOperation && $existing ? 1 : 0);
        $this->assertDatabaseCount('audit_events', 0);
        $this->assertDatabaseCount('notifications', 0);
        if ($grantOperation) {
            $this->assertDatabaseCount('consultation_grants', 1);
        } elseif ($existing) {
            $this->assertSame('pending', Payment::sole()->status);
            $this->assertSame(1000000, Payment::sole()->amount);
        }
    }

    public function test_waiting_manual_consultation_grant_creation_rechecks_admin_role(): void
    {
        $this->consultationFinancialAfterAccessChange(true, false);
    }

    public function test_waiting_manual_consultation_grant_edit_rechecks_admin_role(): void
    {
        $this->consultationFinancialAfterAccessChange(true, true);
    }

    public function test_waiting_manual_consultation_grant_edit_rechecks_admin_activity(): void
    {
        $this->consultationFinancialAfterAccessChange(true, true, true);
    }

    public function test_waiting_consultation_payment_creation_rechecks_student_role(): void
    {
        $this->consultationFinancialAfterAccessChange(false, false);
    }

    public function test_waiting_consultation_payment_reuse_rechecks_student_role(): void
    {
        $this->consultationFinancialAfterAccessChange(false, true);
    }

    public function test_waiting_consultation_payment_creation_rechecks_student_activity(): void
    {
        $this->consultationFinancialAfterAccessChange(false, false, true);
    }

    private function packageManagementAfterAccessChange(string $action, bool $deactivate = false, bool $changeCoach = false, bool $unverify = false): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Package management authorization row-lock races require PostgreSQL.');
        }
        $product = ConsultationProduct::factory()->create();
        $purchase = PackagePurchase::factory()->create(['consultation_product_id' => $product->id]);
        $actor = $action === 'reserve' ? $purchase->student : User::findOrFail($product->reviewed_by);
        $payment = null;
        $receipt = null;
        if (in_array($action, ['approve', 'reject'], true)) {
            Storage::fake('receipts');
            config(['platform.finance.transfer_destination' => '6037991234567890']);
            $receipt = app(PackageBilling::class)->submitReceipt($purchase->student, $purchase, UploadedFile::fake()->createWithContent('software-only-race64.pdf', "%PDF-1.4\n% SOFTWARE-ONLY-ACCESS-RACE\n%%EOF\n"), 'SOFTWAREONLYRACE64', now()->toDateString());
            $payment = $receipt->payment;
        } elseif ($action === 'allocate') {
            $payment = Payment::factory()->create(['installment_id' => null, 'enrollment_id' => null, 'user_id' => $purchase->student_id, 'package_purchase_id' => $purchase->id, 'amount' => $purchase->amount, 'method' => 'gateway', 'status' => 'review_required', 'captured_at' => now(), 'paid_at' => now(), 'reference' => 'SOFTWARE-CAPTURED-RACE64']);
        }
        $snapshot = fn () => hash('sha256', json_encode([$product->fresh()->getAttributes(), $purchase->fresh()->getAttributes(), $payment?->fresh()->getAttributes(), $receipt?->fresh()->getAttributes(), ConsultationGrant::count(), AuditEvent::count()], JSON_THROW_ON_ERROR));
        $before = $snapshot();
        $target = $changeCoach ? User::findOrFail($product->coach_id) : $actor;
        $role = $changeCoach ? 'coach' : ($action === 'reserve' ? 'student' : 'admin');
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();$action=$argv[1];$actor=\App\Models\User::findOrFail((int)$argv[2]);$product=\App\Models\ConsultationProduct::findOrFail((int)$argv[3]);$purchase=\App\Models\PackagePurchase::findOrFail((int)$argv[4]);$payment=(int)$argv[5]?\App\Models\Payment::findOrFail((int)$argv[5]):null;$receipt=(int)$argv[6]?\App\Models\PaymentReceipt::findOrFail((int)$argv[6]):null;$barrier=$action==='reserve'?'student':'admin';$checked=false;
\Illuminate\Support\Facades\DB::listen(function($query)use($barrier,&$checked){if(!$checked&&str_contains($query->sql,'roles')&&in_array($barrier,$query->bindings,true)){$checked=true;echo "CHECKED\n";flush();}});
try{$service=app(\App\Services\PackageBilling::class);$data=[...$product->only('revision','coach_id','title','description','sessions','session_minutes','valid_days','published'),'price'=>100000,'manual_payment_enabled'=>false];match($action){'create_product'=>$service->configure($actor,$data),'edit_product'=>$service->configure($actor,$data,$product),'allocate'=>$service->resolve($actor,$payment),'approve','reject'=>$service->review($actor,$receipt,['action'=>$action,'expected_review_version'=>0,'verified_amount'=>100000,'statement_verified'=>true]),'reserve'=>$service->reserve($actor,$product,$product->revision)};echo 'COMMITTED';}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){echo (string)$e->getStatusCode();}catch(\Illuminate\Validation\ValidationException){echo '422';}
CODE;
        $process = new Process([PHP_BINARY, '-r', $code, $action, (string) $actor->id, (string) $product->id, (string) $purchase->id, (string) ($payment?->id ?? 0), (string) ($receipt?->id ?? 0)], base_path(), null, null, 20);
        try {
            DB::transaction(function () use ($target, $process, $deactivate, $unverify, $role) {
                User::whereKey($target->id)->lockForUpdate()->firstOrFail();
                $process->start();
                $this->assertTrue($process->waitUntil(fn ($type, $output) => str_contains($output, 'CHECKED')), $process->getErrorOutput());
                if ($deactivate) {
                    User::whereKey($target->id)->update(['is_active' => false]);
                } elseif ($unverify) {
                    User::whereKey($target->id)->update(['phone_verified_at' => null]);
                } else {
                    $target->roles()->detach(Role::where('key', $role)->value('id'));
                }
            });
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $this->assertStringEndsWith($changeCoach ? '422' : '403', $process->getOutput());
        } finally {
            if ($process->isRunning()) {
                $process->stop();
            }
        }
        $this->assertSame($before, $snapshot());
        $this->assertDatabaseCount('consultation_products', 1);
        $this->assertDatabaseCount('package_purchases', 1);
        $this->assertDatabaseCount('notifications', 0);
        if ($receipt) {
            Storage::disk('receipts')->assertExists($receipt->file_path);
            $this->assertDatabaseCount('receipt_claims', 1);
        }
    }

    public function test_waiting_package_product_creation_rechecks_current_admin_role(): void
    {
        $this->packageManagementAfterAccessChange('create_product');
    }

    public function test_waiting_package_product_edit_rechecks_current_admin_role(): void
    {
        $this->packageManagementAfterAccessChange('edit_product');
    }

    public function test_waiting_package_allocation_rechecks_current_admin_role(): void
    {
        $this->packageManagementAfterAccessChange('allocate');
    }

    public function test_waiting_package_receipt_approval_rechecks_current_admin_role(): void
    {
        $this->packageManagementAfterAccessChange('approve');
    }

    public function test_waiting_package_receipt_rejection_rechecks_current_admin_role(): void
    {
        $this->packageManagementAfterAccessChange('reject');
    }

    public function test_waiting_package_receipt_approval_rechecks_current_admin_activity(): void
    {
        $this->packageManagementAfterAccessChange('approve', true);
    }

    public function test_waiting_package_reservation_reuse_rechecks_current_student_role(): void
    {
        $this->packageManagementAfterAccessChange('reserve');
    }

    public function test_waiting_package_product_publication_rechecks_current_coach_role(): void
    {
        $this->packageManagementAfterAccessChange('edit_product', false, true);
    }

    public function test_waiting_package_reservation_reuse_rechecks_current_student_activity(): void
    {
        $this->packageManagementAfterAccessChange('reserve', true);
    }

    public function test_waiting_package_reservation_reuse_rechecks_current_phone_verification(): void
    {
        $this->packageManagementAfterAccessChange('reserve', false, false, true);
    }

    public static function refundAccessRaces(): array
    {
        $cases = [];
        foreach (['course', 'consultation', 'package', 'organization'] as $kind) {
            foreach (['approve', 'complete'] as $action) {
                $cases[$kind.'_'.$action] = [$kind, $action, 'role'];
            }
            if ($kind !== 'organization') {
                $cases[$kind.'_request'] = [$kind, 'request', 'activity'];
            }
        }
        foreach (['activity', 'role', 'membership'] as $change) {
            $cases['organization_request_'.$change] = ['organization', 'request', $change];
        }

        return $cases;
    }

    #[DataProvider('refundAccessRaces')]
    public function test_waiting_refund_write_rechecks_current_authorization(string $kind, string $action, string $change): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Refund authorization row-lock races require PostgreSQL.');
        }
        Storage::fake('refunds');
        $base = ['status' => 'review_required', 'reference' => 'SOFTWARE-ONLY-REFUND-RACE65', 'captured_at' => now(), 'paid_at' => now(), 'amount' => 1000000];
        if ($kind === 'course') {
            $payment = Payment::factory()->create($base);
        } else {
            if ($kind === 'organization') {
                $invoice = OrganizationInvoice::factory()->create();
                $owner = User::factory()->create();
                $owner->roles()->attach(Role::where('key', 'org_admin')->value('id'));
                $invoice->organization->members()->attach($owner->id, ['kind' => 'manager']);
                $parent = ['organization_invoice_id' => $invoice->id, 'user_id' => $owner->id];
            } elseif ($kind === 'package') {
                $purchase = PackagePurchase::factory()->create();
                $parent = ['package_purchase_id' => $purchase->id, 'user_id' => $purchase->student_id];
            } else {
                $booking = Consultation::factory()->create(['amount' => 1000000]);
                $parent = ['consultation_id' => $booking->id, 'user_id' => $booking->student_id];
            }
            $payment = Payment::factory()->create([...$base, 'installment_id' => null, 'enrollment_id' => null, ...$parent]);
        }
        $service = app(RefundService::class);
        $refund = null;
        if ($action !== 'request') {
            $refund = $kind === 'organization'
                ? $service->requestOrganization($payment->user, $payment, 'IR820540102680020817909002', 'Software-only beneficiary', 'Software-only refund race', 0)
                : $service->request($payment->user, $payment, 'IR820540102680020817909002', 'Software-only refund race', 0);
            $actor = User::factory()->create();
            $actor->roles()->attach(Role::where('key', 'admin')->value('id'));
            if ($action === 'complete') {
                $service->review($actor, $refund, 1, 'approve', 'Software-only approval');
            }
        } else {
            $actor = $payment->user;
        }
        $snapshot = fn () => hash('sha256', json_encode([$payment->fresh()->getAttributes(), $refund?->fresh()->getAttributes(), Refund::count(), RefundEvent::count(), AuditEvent::count(), DB::table('notifications')->count()], JSON_THROW_ON_ERROR));
        $before = $snapshot();
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();$action=$argv[1];$actor=\App\Models\User::findOrFail((int)$argv[2]);$payment=\App\Models\Payment::findOrFail((int)$argv[3]);$refund=(int)$argv[4]?\App\Models\Refund::findOrFail((int)$argv[4]):null;$membership=$argv[5]==='membership';config(['filesystems.disks.refunds.root'=>$argv[6]]);$checked=false;
\Illuminate\Support\Facades\DB::listen(function($query)use($action,$payment,$membership,&$checked){$matches=$membership?str_contains($query->sql,'organization_user'):(str_contains($query->sql,'roles')&&in_array($action==='request'?'org_admin':'admin',$query->bindings,true));if(!$checked&&$matches){$checked=true;echo "CHECKED\n";flush();}});
if($action==='request'&&!$payment->organization_invoice_id){echo "CHECKED\n";flush();}
try{$service=app(\App\Services\RefundService::class);if($action==='request'){if($payment->organization_invoice_id){$service->requestOrganization($actor,$payment,'IR820540102680020817909002','Software-only beneficiary','Software-only refund race',0);}else{$service->request($actor,$payment,'IR820540102680020817909002','Software-only refund race',0);}}elseif($action==='complete'){$service->complete($actor,$refund,['version'=>$refund->version,'amount'=>100000,'destination'=>'IR820540102680020817909002','transfer_reference'=>'SOFTWAREONLYRACE65','transfer_date'=>now()->toDateString(),'statement_verified'=>true,'beneficiary_verified'=>true],\Illuminate\Http\UploadedFile::fake()->createWithContent('software-only-race65.pdf',"%PDF-1.4\n% SOFTWARE-ONLY-NO-REAL-TRANSFER\n%%EOF\n"));}else{$service->review($actor,$refund,$refund->version,'approve','Software-only refund race');}echo 'COMMITTED';}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){echo (string)$e->getStatusCode();}
CODE;
        $process = new Process([PHP_BINARY, '-r', $code, $action, (string) $actor->id, (string) $payment->id, (string) ($refund?->id ?? 0), $change, Storage::disk('refunds')->path('')], base_path(), null, null, 20);
        try {
            DB::transaction(function () use ($actor, $payment, $process, $action, $change) {
                if ($change === 'membership') {
                    Organization::whereKey($payment->organizationInvoice->organization_id)->lockForUpdate()->firstOrFail();
                } else {
                    User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
                }
                $process->start();
                $this->assertTrue($process->waitUntil(fn ($type, $output) => str_contains($output, 'CHECKED')), $process->getErrorOutput());
                if ($change === 'activity') {
                    User::whereKey($actor->id)->update(['is_active' => false]);
                } elseif ($change === 'membership') {
                    $payment->organizationInvoice->organization->members()->detach($actor->id);
                } else {
                    $actor->roles()->detach(Role::where('key', $action === 'request' ? 'org_admin' : 'admin')->value('id'));
                }
            });
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $this->assertStringEndsWith('403', $process->getOutput());
        } finally {
            if ($process->isRunning()) {
                $process->stop();
            }
        }
        $this->assertSame($before, $snapshot());
        $this->assertSame([], Storage::disk('refunds')->allFiles());
    }

    public static function organizationBillingAccessRaces(): array
    {
        $cases = [];
        foreach (['create', 'edit', 'issue', 'void', 'approve', 'reject'] as $action) {
            $cases['admin_'.$action] = [$action, 'role'];
        }
        foreach (['attempt', 'reuse', 'receipt'] as $action) {
            foreach (['role', 'activity', 'membership'] as $change) {
                $cases['manager_'.$action.'_'.$change] = [$action, $change];
            }
        }

        return $cases;
    }

    #[DataProvider('organizationBillingAccessRaces')]
    public function test_waiting_organization_billing_write_rechecks_current_authorization(string $action, string $change): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Organization billing authorization row-lock races require PostgreSQL.');
        }
        Storage::fake('receipts');
        config(['platform.finance.transfer_destination' => '6037991234567890', 'platform.finance.transfer_holder' => 'Software-only account']);
        $invoice = OrganizationInvoice::factory()->create();
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('key', 'admin')->value('id'));
        $manager = User::factory()->create();
        $manager->roles()->attach(Role::where('key', 'org_admin')->value('id'));
        $invoice->organization->members()->attach($manager->id, ['kind' => 'manager']);
        $managerOperation = in_array($action, ['attempt', 'reuse', 'receipt'], true);
        $actor = $managerOperation ? $manager : $admin;
        $service = app(OrganizationBilling::class);
        if ($managerOperation || in_array($action, ['approve', 'reject'], true)) {
            $service->issue($admin, $invoice, 1);
            $invoice = $invoice->fresh();
        }
        if ($action === 'reuse') {
            $service->attempt($manager, $invoice);
        }
        $receipt = in_array($action, ['approve', 'reject'], true)
            ? $service->receipt($manager, $invoice, UploadedFile::fake()->createWithContent('software-only-race66.pdf', "%PDF-1.4\n% SOFTWARE-ONLY-NO-REAL-FUNDS66\n%%EOF\n"), 'SOFTWAREONLYRACE66', now()->toDateString())
            : null;
        $snapshot = function () {
            $state = [];
            foreach (['organization_invoices', 'payments', 'payment_receipts', 'receipt_claims', 'organization_payment_allocations', 'audit_events', 'notifications'] as $table) {
                $state[$table] = DB::table($table)->orderBy('id')->get()->toJson();
            }
            foreach (Storage::disk('receipts')->allFiles() as $file) {
                $state['files'][$file] = hash_file('sha256', Storage::disk('receipts')->path($file));
            }

            return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
        };
        $before = $snapshot();
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();$action=$argv[1];$actor=\App\Models\User::findOrFail((int)$argv[2]);$invoice=\App\Models\OrganizationInvoice::findOrFail((int)$argv[3]);$receipt=(int)$argv[4]?\App\Models\PaymentReceipt::findOrFail((int)$argv[4]):null;$manager=in_array($action,['attempt','reuse','receipt'],true);config(['filesystems.disks.receipts.root'=>$argv[5],'platform.finance.transfer_destination'=>'6037991234567890','platform.finance.transfer_holder'=>'Software-only account']);$checked=false;
\Illuminate\Support\Facades\DB::listen(function($query)use(&$checked){if(!$checked&&str_contains($query->sql,'roles')&&in_array('admin',$query->bindings,true)){$checked=true;echo "CHECKED\n";flush();}});if($manager){echo "CHECKED\n";flush();}
try{$service=app(\App\Services\OrganizationBilling::class);$definition=['title'=>'Software-only contract','contract_reference'=>'SOFTWARE-ONLY-RACE66','due_date'=>now()->addWeek()->toDateString(),'organization_license_id'=>null,'lines'=>[['title'=>'Software-only service','quantity'=>1,'unit_price'=>100000]]];match($action){'create'=>$service->draft($actor,$invoice->organization,$definition),'edit'=>$service->draft($actor,$invoice->organization,$definition,$invoice,1),'issue'=>$service->issue($actor,$invoice,1),'void'=>$service->void($actor,$invoice,1,'Software-only cancellation'),'attempt','reuse'=>$service->attempt($actor,$invoice),'receipt'=>$service->receipt($actor,$invoice,\Illuminate\Http\UploadedFile::fake()->createWithContent('software-only-new-race66.pdf',"%PDF-1.4\n% SOFTWARE-ONLY-NEW-RECEIPT66\n%%EOF\n"),'SOFTWAREONLYNEW66',now()->toDateString()),'approve','reject'=>$service->review($actor,$receipt,['action'=>$action,'expected_review_version'=>0,'verified_amount'=>100000,'statement_verified'=>true,'review_note'=>'Software-only review'])};echo 'COMMITTED';}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){echo (string)$e->getStatusCode();}
CODE;
        $process = new Process([PHP_BINARY, '-r', $code, $action, (string) $actor->id, (string) $invoice->id, (string) ($receipt?->id ?? 0), Storage::disk('receipts')->path('')], base_path(), null, null, 20);
        try {
            DB::transaction(function () use ($invoice, $actor, $process, $change, $managerOperation) {
                if ($managerOperation && $change !== 'activity') {
                    Organization::whereKey($invoice->organization_id)->lockForUpdate()->firstOrFail();
                } else {
                    User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
                }
                $process->start();
                $this->assertTrue($process->waitUntil(fn ($type, $output) => str_contains($output, 'CHECKED')), $process->getErrorOutput());
                if ($change === 'activity') {
                    User::whereKey($actor->id)->update(['is_active' => false]);
                } elseif ($change === 'membership') {
                    $invoice->organization->members()->detach($actor->id);
                } else {
                    $actor->roles()->detach(Role::where('key', $managerOperation ? 'org_admin' : 'admin')->value('id'));
                }
            });
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $this->assertStringEndsWith('403', $process->getOutput());
        } finally {
            if ($process->isRunning()) {
                $process->stop();
            }
        }
        $this->assertSame($before, $snapshot());
    }

    public static function courseFinancialAccessRaces(): array
    {
        $cases = [];
        foreach (['approve', 'reject', 'resolve', 'resolve_paid', 'review_paid', 'configure_new', 'configure_edit', 'configure_disable', 'retry_waiting', 'retry_issued'] as $action) {
            $cases['admin_'.$action] = [$action, 'admin_role'];
        }
        foreach (['attempt', 'reuse', 'receipt'] as $action) {
            $cases['owner_'.$action] = [$action, 'owner_activity'];
        }
        $cases['new_benefit_coach_role'] = ['configure_new', 'coach_role'];
        $cases['edited_benefit_coach_activity'] = ['configure_edit', 'coach_activity'];

        return $cases;
    }

    private function courseFinancialRaceFixture(string $action): array
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::where('key', 'admin')->value('id'));
        $student = User::factory()->create();
        $student->roles()->attach(Role::where('key', 'student')->value('id'));
        $coach = User::factory()->create();
        $coach->roles()->attach(Role::where('key', 'coach')->value('id'));
        $cohort = Cohort::factory()->create(['course_id' => Course::factory()->state(['price' => 1000000, 'is_published' => true]), 'capacity' => 10, 'manual_payment_enabled' => true]);
        $definition = ['revision' => 0, 'enabled' => true, 'coach_id' => $coach->id, 'title' => 'Software-only frozen race68', 'sessions' => 2, 'session_minutes' => 60, 'valid_days' => 30, 'activation' => 'first_payment'];
        if ($action !== 'configure_new') {
            app(CourseConsultationBenefits::class)->configure($admin, $cohort, $definition);
        }
        $enrollment = app(EnrollmentService::class)->reserve($student, $cohort);
        $invoice = app(BillingService::class)->current($enrollment)->sole();
        $payment = null;
        $receipt = null;
        if (in_array($action, ['reuse', 'resolve_paid', 'retry_waiting', 'retry_issued'], true)) {
            $payment = app(BillingService::class)->attempt($student, $invoice);
        }
        if (in_array($action, ['approve', 'reject', 'review_paid'], true)) {
            $receipt = app(ReceiptService::class)->submit($student, $invoice, UploadedFile::fake()->createWithContent('software-only-race68.pdf', "%PDF-1.4\n% SOFTWARE-ONLY-NO-REAL-FUNDS-RACE68\n%%EOF\n"), 'SOFTWAREONLYRACE68', now()->toDateString());
            $payment = $receipt->payment;
        }
        if ($action === 'review_paid') {
            app(ReceiptService::class)->review($admin, $receipt, ['action' => 'approve', 'expected_review_version' => 0, 'verified_amount' => 100000, 'statement_verified' => true, 'review_note' => 'Software-only independent review']);
        }
        if ($action === 'resolve') {
            $payment = Payment::factory()->create(['user_id' => $student->id, 'enrollment_id' => $enrollment->id, 'installment_id' => $invoice->id, 'amount' => 1000000, 'status' => 'review_required', 'reference' => 'SOFTWARE-ONLY-CAPTURED-RACE68', 'captured_at' => now(), 'paid_at' => now()]);
        }
        if (in_array($action, ['resolve_paid', 'retry_waiting', 'retry_issued'], true)) {
            if ($action === 'retry_waiting') {
                User::whereKey($coach->id)->update(['is_active' => false]);
            }
            app(BillingService::class)->capture($payment, 'SOFTWARE-ONLY-VERIFIED-RACE68');
            User::whereKey($coach->id)->update(['is_active' => true]);
        }

        return [$admin, $student, $coach, $cohort, $enrollment, $invoice, $payment, $receipt];
    }

    #[DataProvider('courseFinancialAccessRaces')]
    public function test_waiting_course_financial_write_rechecks_current_authorization(string $action, string $change): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Course financial authorization row-lock races require PostgreSQL.');
        }
        Storage::fake('receipts');
        config(['platform.finance.transfer_destination' => '6037991234567890']);
        [$admin, $student, $coach, $cohort, $enrollment, $invoice, $payment, $receipt] = $this->courseFinancialRaceFixture($action);
        $actor = $change === 'owner_activity' ? $student : $admin;
        $target = str_starts_with($change, 'coach_') ? $coach : $actor;
        $snapshot = function () {
            $state = [];
            foreach (['cohort_consultation_benefits', 'enrollment_consultation_benefits', 'consultation_grants', 'enrollments', 'installments', 'payments', 'payment_receipts', 'receipt_claims', 'payment_allocations', 'audit_events', 'notifications'] as $table) {
                $state[$table] = DB::table($table)->orderBy('id')->get()->toJson();
            }
            foreach (Storage::disk('receipts')->allFiles() as $file) {
                $state['files'][$file] = hash_file('sha256', Storage::disk('receipts')->path($file));
            }

            return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
        };
        $before = $snapshot();
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();$action=$argv[1];$actor=\App\Models\User::findOrFail((int)$argv[2]);$coach=\App\Models\User::findOrFail((int)$argv[3]);$cohort=\App\Models\Cohort::findOrFail((int)$argv[4]);$enrollment=\App\Models\Enrollment::findOrFail((int)$argv[5]);$invoice=\App\Models\Installment::findOrFail((int)$argv[6]);$payment=(int)$argv[7]?\App\Models\Payment::findOrFail((int)$argv[7]):null;$receipt=(int)$argv[8]?\App\Models\PaymentReceipt::findOrFail((int)$argv[8]):null;config(['filesystems.disks.receipts.root'=>$argv[9],'platform.finance.transfer_destination'=>'6037991234567890']);$owner=in_array($action,['attempt','reuse','receipt'],true);$checked=false;
\Illuminate\Support\Facades\DB::listen(function($query)use(&$checked){if(!$checked&&str_contains($query->sql,'roles')&&in_array('admin',$query->bindings,true)){$checked=true;echo "CHECKED\n";flush();}});if($owner){echo "CHECKED\n";flush();}
try{$definition=['revision'=>$action==='configure_new'?0:1,'enabled'=>$action!=='configure_disable','coach_id'=>$coach->id,'title'=>'Software-only edited race68','sessions'=>3,'session_minutes'=>60,'valid_days'=>30,'activation'=>'first_payment'];match($action){'attempt','reuse'=>app(\App\Services\BillingService::class)->attempt($actor,$invoice),'receipt'=>app(\App\Services\ReceiptService::class)->submit($actor,$invoice,\Illuminate\Http\UploadedFile::fake()->createWithContent('software-only-new-race68.pdf',"%PDF-1.4\n% SOFTWARE-ONLY-NEW-RECEIPT68\n%%EOF\n"),'SOFTWAREONLYNEW68',now()->toDateString()),'approve','reject','review_paid'=>app(\App\Services\ReceiptService::class)->review($actor,$receipt,['action'=>$action==='reject'?'reject':'approve','expected_review_version'=>0,'verified_amount'=>100000,'statement_verified'=>true,'review_note'=>'Software-only review']),'resolve','resolve_paid'=>app(\App\Services\BillingService::class)->resolve($payment,$actor),'configure_new','configure_edit','configure_disable'=>app(\App\Services\CourseConsultationBenefits::class)->configure($actor,$cohort,$definition),'retry_waiting','retry_issued'=>app(\App\Services\CourseConsultationBenefits::class)->retry($actor,\App\Models\EnrollmentConsultationBenefit::where('enrollment_id',$enrollment->id)->sole())};echo 'COMMITTED';}catch(\Symfony\Component\HttpKernel\Exception\HttpException $e){echo (string)$e->getStatusCode();}catch(\Illuminate\Validation\ValidationException){echo '422';}
CODE;
        $process = new Process([PHP_BINARY, '-r', $code, $action, (string) $actor->id, (string) $coach->id, (string) $cohort->id, (string) $enrollment->id, (string) $invoice->id, (string) ($payment?->id ?? 0), (string) ($receipt?->id ?? 0), Storage::disk('receipts')->path('')], base_path(), null, null, 20);
        try {
            DB::transaction(function () use ($target, $process, $change) {
                User::whereKey($target->id)->lockForUpdate()->firstOrFail();
                $process->start();
                $this->assertTrue($process->waitUntil(fn ($type, $output) => str_contains($output, 'CHECKED')), $process->getErrorOutput());
                if (str_ends_with($change, '_activity')) {
                    User::whereKey($target->id)->update(['is_active' => false]);
                } else {
                    $target->roles()->detach(Role::where('key', $change === 'coach_role' ? 'coach' : 'admin')->value('id'));
                }
            });
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $this->assertStringEndsWith(str_starts_with($change, 'coach_') ? '422' : '403', $process->getOutput());
        } finally {
            if ($process->isRunning()) {
                $process->stop();
            }
        }
        $this->assertSame($before, $snapshot());
    }

    public function test_waiting_owned_course_payment_retains_active_owner_rights_after_student_role_loss(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Course payment ownership row-lock race requires PostgreSQL.');
        }
        [$admin, $student, $coach, $cohort, $enrollment, $invoice] = $this->courseFinancialRaceFixture('attempt');
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();$owner=\App\Models\User::findOrFail((int)$argv[1]);$invoice=\App\Models\Installment::findOrFail((int)$argv[2]);echo "CHECKED\n";flush();$payment=app(\App\Services\BillingService::class)->attempt($owner,$invoice);echo 'PENDING:'.$payment->amount;
CODE;
        $process = new Process([PHP_BINARY, '-r', $code, (string) $student->id, (string) $invoice->id], base_path(), null, null, 20);
        try {
            DB::transaction(function () use ($student, $process) {
                User::whereKey($student->id)->lockForUpdate()->firstOrFail();
                $process->start();
                $this->assertTrue($process->waitUntil(fn ($type, $output) => str_contains($output, 'CHECKED')), $process->getErrorOutput());
                $student->roles()->sync([Role::where('key', 'coach')->value('id')]);
            });
            $process->wait();
            $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
            $this->assertStringEndsWith('PENDING:1000000', $process->getOutput());
        } finally {
            if ($process->isRunning()) {
                $process->stop();
            }
        }
        $this->assertSame($student->id, Payment::sole()->user_id);
        $this->assertNull($invoice->fresh()->paid_at);
        $this->assertDatabaseCount('consultation_grants', 0);
    }

    public function test_concurrent_reciprocal_admin_coach_receipt_reviews_finish_without_account_lock_cycle(): void
    {
        if (DB::connection()->getDriverName() !== 'pgsql') {
            $this->markTestSkipped('Reciprocal financial participant row locks require PostgreSQL.');
        }
        Storage::fake('receipts');
        config(['platform.finance.transfer_destination' => '6037991234567890']);
        $actors = User::factory()->count(2)->create();
        foreach ($actors as $actor) {
            $actor->roles()->sync(Role::whereIn('key', ['admin', 'coach'])->pluck('id'));
        }
        $student = User::factory()->create();
        $student->roles()->attach(Role::where('key', 'student')->value('id'));
        $fixtures = [];
        foreach ($actors as $index => $actor) {
            $coach = $actors[1 - $index];
            $cohort = Cohort::factory()->create(['course_id' => Course::factory()->state(['price' => 1000000, 'is_published' => true]), 'capacity' => 10, 'manual_payment_enabled' => true]);
            app(CourseConsultationBenefits::class)->configure($actor, $cohort, ['revision' => 0, 'enabled' => true, 'coach_id' => $coach->id, 'title' => 'Software-only reciprocal benefit', 'sessions' => 2, 'session_minutes' => 60, 'valid_days' => 30, 'activation' => 'first_payment']);
            $enrollment = app(EnrollmentService::class)->reserve($student, $cohort);
            $invoice = app(BillingService::class)->current($enrollment)->sole();
            $receipt = app(ReceiptService::class)->submit($student, $invoice, UploadedFile::fake()->createWithContent('reciprocal68.pdf', "%PDF-1.4\n% SOFTWARE-ONLY-RECIPROCAL68-".$index."\n%%EOF\n"), 'SOFTWAREONLYRECIPROCAL68'.$index, now()->toDateString());
            $fixtures[] = [$actor, $cohort, $receipt];
        }
        $code = <<<'CODE'
require 'vendor/autoload.php';$app=require 'bootstrap/app.php';$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();$actor=\App\Models\User::findOrFail((int)$argv[1]);$receipt=\App\Models\PaymentReceipt::findOrFail((int)$argv[2]);config(['filesystems.disks.receipts.root'=>$argv[3]]);$checked=false;\Illuminate\Support\Facades\DB::listen(function($query)use(&$checked){if(!$checked&&str_contains($query->sql,'roles')&&in_array('admin',$query->bindings,true)){$checked=true;echo "CHECKED\n";flush();}});echo app(\App\Services\ReceiptService::class)->review($actor,$receipt,['action'=>'approve','expected_review_version'=>0,'verified_amount'=>100000,'statement_verified'=>true,'review_note'=>'Software-only reciprocal review'])?'PAID':'UNALLOCATED';
CODE;
        $processes = [];
        foreach ($fixtures as [$actor, $cohort, $receipt]) {
            $processes[] = new Process([PHP_BINARY, '-r', $code, (string) $actor->id, (string) $receipt->id, Storage::disk('receipts')->path('')], base_path(), null, null, 20);
        }
        try {
            DB::transaction(function () use ($fixtures, $processes) {
                Cohort::whereIn('id', array_map(fn ($item) => $item[1]->id, $fixtures))->orderBy('id')->lockForUpdate()->get();
                foreach ($processes as $process) {
                    $process->start();
                }
                foreach ($processes as $process) {
                    $this->assertTrue($process->waitUntil(fn ($type, $output) => str_contains($output, 'CHECKED')), $process->getErrorOutput());
                }
            });
            foreach ($processes as $process) {
                $process->wait();
                $this->assertTrue($process->isSuccessful(), $process->getErrorOutput());
                $this->assertStringEndsWith('PAID', $process->getOutput());
            }
        } finally {
            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $process->stop();
                }
            }
        }
        $this->assertSame(2, Payment::where('status', 'paid')->count());
        $this->assertSame(2000000, (int) PaymentAllocation::sum('amount'));
        $this->assertDatabaseCount('consultation_grants', 2);
        foreach ($fixtures as [$actor, $cohort, $receipt]) {
            Storage::disk('receipts')->assertExists($receipt->file_path);
        }
    }
}
