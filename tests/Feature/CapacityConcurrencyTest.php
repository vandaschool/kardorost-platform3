<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Cohort;
use App\Models\Consultation;
use App\Models\ConsultationRequest;
use App\Models\ConsultationSlot;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Installment;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\Role;
use App\Models\Submission;
use App\Models\User;
use App\Services\ConsultationBilling;
use App\Services\ConsultationService;
use App\Services\EnrollmentService;
use App\Services\ReceiptService;
use Illuminate\Foundation\Testing\DatabaseMigrations;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
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
}
