<?php

namespace Tests\Feature;

use App\Models\Cohort;
use App\Models\Course;
use App\Models\Installment;
use App\Models\PaymentReceipt;
use App\Models\Role;
use App\Models\User;
use App\Services\BillingService;
use App\Services\EnrollmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class ReceiptTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('receipts');
        Http::preventStrayRequests();
        config(['platform.finance.transfer_destination' => '6037991234567890']);
    }

    private function user(string $role = 'student'): User
    {
        $u = User::factory()->create();
        $u->roles()->attach(Role::where('key', $role)->value('id'));

        return $u;
    }

    private function invoice(User $user): Installment
    {
        $c = Cohort::factory()->create(['course_id' => Course::factory()->create(['price' => 1000000])->id, 'manual_payment_enabled' => true]);
        $e = app(EnrollmentService::class)->reserve($user, $c);

        return $e->installments()->first();
    }

    private function file(string $key = 'one'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('receipt.pdf', "%PDF-1.4\nReceipt ".$key);
    }

    private function send(Installment $i, string $ref = '12345678', string $key = 'one'): TestResponse
    {
        return $this->post('/installments/'.$i->id.'/receipts', ['transfer_reference' => $ref, 'transfer_date' => now()->toDateString(), 'file' => $this->file($key)]);
    }

    private function approve(PaymentReceipt $r, array $overrides = []): TestResponse
    {
        return $this->post('/admin/finance/receipts/'.$r->id.'/review', [...['action' => 'approve', 'verified_amount' => 100000, 'statement_verified' => true, 'expected_review_version' => 0], ...$overrides]);
    }

    public function test_upload_is_private_pending_and_does_not_activate_enrollment(): void
    {
        $u = $this->user();
        $i = $this->invoice($u);
        $this->actingAs($u);
        $this->send($i, '۱۲۳۴۵۶۷۸')->assertSessionHasNoErrors();
        $r = PaymentReceipt::first();
        $this->assertSame('12345678', $r->transfer_reference);
        Storage::disk('receipts')->assertExists($r->file_path);
        $this->assertSame('pending_payment', $i->enrollment->fresh()->status);
        $this->assertDatabaseCount('payment_allocations', 0);
        $this->get('/receipts/'.$r->id.'/file')->assertOk();
        $this->actingAs($this->user('coach'))->get('/receipts/'.$r->id.'/file')->assertForbidden();
        $this->actingAs($this->user('org_admin'))->get('/receipts/'.$r->id.'/file')->assertForbidden();
        $this->actingAs($this->user('admin'))->get('/receipts/'.$r->id.'/file')->assertOk();
    }

    public function test_admin_must_confirm_statement_and_amount_and_repeated_approval_is_idempotent(): void
    {
        $u = $this->user();
        $i = $this->invoice($u);
        $this->actingAs($u);
        $this->send($i);
        $r = PaymentReceipt::first();
        $admin = $this->user('admin');
        $this->actingAs($admin);
        $this->approve($r, ['statement_verified' => false])->assertSessionHasErrors('statement_verified');
        $this->approve($r, ['verified_amount' => 1])->assertSessionHasErrors('verified_amount');
        $this->assertDatabaseCount('payment_allocations', 0);
        $this->approve($r)->assertSessionHasNoErrors();
        $this->approve($r)->assertSessionHasNoErrors();
        $this->assertSame('active', $i->enrollment->fresh()->status);
        $this->assertDatabaseCount('payment_allocations', 1);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertNotNull($r->fresh()->reviewed_by);
    }

    public function test_student_cannot_review_own_receipt_or_upload_for_another_student(): void
    {
        $u = $this->user();
        $i = $this->invoice($u);
        $other = $this->invoice($this->user());
        $this->actingAs($u);
        $this->send($i);
        $r = PaymentReceipt::first();
        $this->approve($r)->assertForbidden();
        $this->send($other, '22334455', 'other')->assertForbidden();
        $this->assertDatabaseCount('payment_receipts', 1);
    }

    public function test_rejection_requires_reason_and_releases_claim_without_deleting_history(): void
    {
        $u = $this->user();
        $i = $this->invoice($u);
        $this->actingAs($u);
        $this->send($i);
        $r = PaymentReceipt::first();
        $path = $r->file_path;
        $this->actingAs($this->user('admin'));
        $this->post('/admin/finance/receipts/'.$r->id.'/review', ['action' => 'reject', 'expected_review_version' => 0])->assertSessionHasErrors('review_note');
        $this->post('/admin/finance/receipts/'.$r->id.'/review', ['action' => 'reject', 'expected_review_version' => 0, 'review_note' => 'شماره پیگیری با گردش بانکی مطابقت ندارد.'])->assertSessionHasNoErrors();
        $this->assertSame('rejected', $r->fresh()->status);
        $this->assertDatabaseCount('receipt_claims', 0);
        Storage::disk('receipts')->assertExists($path);
        $this->assertDatabaseCount('notifications', 1);
        $this->assertSame('pending_payment', $i->enrollment->fresh()->status);
    }

    public function test_duplicate_file_and_bank_reference_are_rejected_across_different_invoices(): void
    {
        $a = $this->user();
        $i = $this->invoice($a);
        $this->actingAs($a);
        $this->send($i)->assertSessionHasNoErrors();
        $b = $this->user();
        $j = $this->invoice($b);
        $this->actingAs($b);
        $this->send($j, 'OTHER-REFERENCE')->assertSessionHasErrors('file');
        $this->send($j, '۱۲۳۴-۵۶۷۸', 'different-bytes')->assertSessionHasErrors('file');
        $this->assertDatabaseCount('payment_receipts', 1);
        $this->assertCount(1, Storage::disk('receipts')->allFiles());
    }

    public function test_rejected_receipt_cannot_be_approved_later_from_stale_form(): void
    {
        $u = $this->user();
        $i = $this->invoice($u);
        $this->actingAs($u);
        $this->send($i);
        $r = PaymentReceipt::first();
        $this->actingAs($this->user('admin'))->post('/admin/finance/receipts/'.$r->id.'/review', ['action' => 'reject', 'expected_review_version' => 0, 'review_note' => 'نامعتبر'])->assertSessionHasNoErrors();
        $this->approve($r)->assertSessionHasErrors('receipt');
        $this->assertDatabaseCount('payment_allocations', 0);
    }

    public function test_pending_receipt_prevents_gateway_payment_and_requoting(): void
    {
        $u = $this->user();
        $i = $this->invoice($u);
        $e = $i->enrollment;
        $this->actingAs($u);
        $this->send($i);
        config(['platform.zibal.enabled' => true, 'platform.zibal.merchant' => 'test']);
        $this->post('/installments/'.$i->id.'/pay')->assertSessionHasErrors('payment');
        Http::assertNothingSent();
        $e->update(['expires_at' => now()->subSecond()]);
        $this->post('/cohorts/'.$e->cohort_id.'/enroll')->assertSessionHasErrors('payment');
        $this->assertDatabaseCount('installments', 1);
    }

    public function test_pending_gateway_payment_prevents_manual_upload(): void
    {
        $u = $this->user();
        $i = $this->invoice($u);
        app(BillingService::class)->attempt($u, $i);
        $this->actingAs($u);
        $this->send($i)->assertSessionHasErrors('file');
        $this->assertDatabaseCount('payment_receipts', 0);
        $this->assertSame([], Storage::disk('receipts')->allFiles());
    }

    public function test_missing_destination_and_unsafe_uploads_fail_without_claiming_payment(): void
    {
        $u = $this->user();
        $i = $this->invoice($u);
        $this->actingAs($u);
        $this->post('/installments/'.$i->id.'/receipts', ['transfer_reference' => '12345678', 'transfer_date' => now()->toDateString(), 'file' => UploadedFile::fake()->create('bad.pdf', 10, 'text/html')])->assertSessionHasErrors('file');
        config(['platform.finance.transfer_destination' => null]);
        $this->send($i)->assertSessionHasErrors('file');
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_approval_after_hold_expires_does_not_oversell_and_can_be_resolved_later(): void
    {
        $u = $this->user();
        $i = $this->invoice($u);
        $e = $i->enrollment;
        $cohort = $e->cohort;
        $cohort->update(['capacity' => 1]);
        $this->actingAs($u);
        $this->send($i);
        $r = PaymentReceipt::first();
        $e->update(['expires_at' => now()->subSecond()]);
        $other = app(EnrollmentService::class)->reserve($this->user(), $cohort);
        $admin = $this->user('admin');
        $this->actingAs($admin);
        $this->approve($r)->assertSessionHasNoErrors();
        $this->assertSame('approved', $r->fresh()->status);
        $this->assertSame('review_required', $r->payment->fresh()->status);
        $this->assertDatabaseCount('payment_allocations', 0);
        $other->update(['expires_at' => now()->subSecond()]);
        $this->post('/admin/finance/payments/'.$r->payment_id.'/allocate')->assertSessionHasNoErrors();
        $this->assertDatabaseCount('payment_allocations', 1);
    }

    public function test_same_file_can_be_corrected_and_resubmitted_after_rejection_without_erasing_old_receipt(): void
    {
        $u = $this->user();
        $invoice = $this->invoice($u);
        $this->actingAs($u);
        $this->send($invoice)->assertSessionHasNoErrors();
        $first = PaymentReceipt::first();
        $this->actingAs($this->user('admin'))->post('/admin/finance/receipts/'.$first->id.'/review', ['action' => 'reject', 'expected_review_version' => 0, 'review_note' => 'لطفاً اطلاعات واریز را اصلاح و دوباره ثبت کنید.'])->assertSessionHasNoErrors();
        $e = app(EnrollmentService::class)->reserve($u, $invoice->enrollment->cohort);
        $new = $e->installments()->whereNull('voided_at')->where('billing_version', $e->billing_version)->first();
        $this->actingAs($u);
        $this->send($new)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('payment_receipts',2);
        $this->assertDatabaseCount('receipt_claims',1);
        $this->assertSame('rejected',$first->fresh()->status);
    }
}
