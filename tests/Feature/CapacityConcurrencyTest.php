<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentAttempt;
use App\Models\AssessmentVersion;
use App\Models\Assignment;
use App\Models\AuditEvent;
use App\Models\Cohort;
use App\Models\Consultation;
use App\Models\ConsultationGrant;
use App\Models\ConsultationGrantUse;
use App\Models\ConsultationRequest;
use App\Models\ConsultationSlot;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\EnrollmentConsultationBenefit;
use App\Models\Installment;
use App\Models\InvitationSms;
use App\Models\Organization;
use App\Models\OrganizationInvitation;
use App\Models\OrganizationInvoice;
use App\Models\OrganizationLicense;
use App\Models\OrganizationSeat;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\PaymentReceipt;
use App\Models\ReceiptClaim;
use App\Models\Refund;
use App\Models\RefundEvent;
use App\Models\Role;
use App\Models\Submission;
use App\Models\User;
use App\Services\AssessmentService;
use App\Services\BillingService;
use App\Services\CohortCapacity;
use App\Services\ConsultationBilling;
use App\Services\ConsultationService;
use App\Services\CourseConsultationBenefits;
use App\Services\EnrollmentService;
use App\Services\OrganizationBilling;
use App\Services\OrganizationEnrollment;
use App\Services\OrganizationMembership;
use App\Services\ReceiptService;
use App\Services\RefundService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
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
}
