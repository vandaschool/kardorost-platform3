<?php

namespace Tests\Feature;

use App\Models\OrganizationInvoice;
use App\Models\OrganizationPaymentAllocation;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Models\Role;
use App\Models\User;
use App\Services\OrganizationBilling;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OrganizationPartialPaymentTest extends TestCase
{
    use RefreshDatabase;

    private OrganizationInvoice $invoice;

    private User $admin;

    private User $manager;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('receipts');
        Http::preventStrayRequests();
        config(['platform.finance.transfer_destination' => '6037991234567890', 'platform.finance.transfer_holder' => 'حساب آزمایشی', 'platform.zibal.enabled' => true, 'platform.zibal.merchant' => 'test']);
        $this->admin = User::factory()->create();
        $this->admin->roles()->attach(Role::where('key', 'admin')->value('id'));
        $this->manager = User::factory()->create();
        $this->manager->roles()->attach(Role::where('key', 'org_admin')->value('id'));
        $this->invoice = OrganizationInvoice::factory()->create();
        $this->invoice->organization->members()->attach($this->manager->id, ['kind' => 'manager']);
        app(OrganizationBilling::class)->issue($this->admin, $this->invoice, 1);
        $this->invoice->refresh();
    }

    private function charge(int $amountToman): Payment
    {
        return app(OrganizationBilling::class)->attempt($this->manager, $this->invoice, $amountToman);
    }

    private function capture(Payment $payment): bool
    {
        return app(OrganizationBilling::class)->capture($payment, 'BANK-'.$payment->id);
    }

    private function receiptData(int $amountToman, string $reference = 'FIRST123'): array
    {
        return ['amount' => $amountToman, 'file' => UploadedFile::fake()->createWithContent('proof.pdf', "%PDF-1.4\n".$reference), 'transfer_reference' => $reference, 'transfer_date' => now()->toDateString()];
    }

    private function approve(PaymentReceipt $receipt): void
    {
        $this->actingAs($this->admin)->post('/admin/finance/receipts/'.$receipt->id.'/review', ['action' => 'approve', 'expected_review_version' => 0, 'statement_verified' => true, 'verified_amount' => $receipt->payment->amount / 10, 'review_note' => 'با گردش مستقل حساب تطبیق داده شد'])->assertSessionHasNoErrors();
    }

    public function test_verified_partial_gateway_callback_allocates_only_requested_amount_and_replay_is_idempotent(): void
    {
        Http::fake([
            'gateway.zibal.ir/v1/request' => Http::response(['result' => 100, 'trackId' => 'partial-track']),
        ]);
        $this->actingAs($this->manager)->post('/organization/invoices/'.$this->invoice->id.'/pay', ['amount' => 40000])->assertRedirect('https://gateway.zibal.ir/start/partial-track');
        Http::assertSent(fn ($request) => str_ends_with($request->url(), '/request') && $request['amount'] === 400000);
        $payment = Payment::firstOrFail();
        Http::fake(['gateway.zibal.ir/v1/verify' => Http::response(['result' => 100, 'status' => 1, 'orderId' => (string) $payment->id, 'amount' => 400000, 'refNumber' => 'PARTIAL-BANK'])]);
        $this->get('/organization-payments/callback?trackId=partial-track&success=0')->assertRedirect();
        $this->get('/organization-payments/callback?trackId=partial-track&success=1')->assertRedirect();
        $this->assertSame(400000, $payment->fresh()->amount);
        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame('issued', $this->invoice->fresh()->status);
        $this->assertSame(600000, $this->invoice->fresh()->remainingAmount());
        $this->assertNull($this->invoice->fresh()->paid_at);
        $this->assertNull($this->invoice->fresh()->settled_payment_id);
        $this->assertDatabaseCount('organization_payment_allocations', 1);
        $this->get('/organization/invoices/'.$this->invoice->id)->assertInertia(fn (Assert $page) => $page->where('invoice.paid_amount', 400000)->where('invoice.remaining_amount', 600000)->where('payments.data.0.allocated_amount', 400000)->where('can_pay', true));
    }

    public function test_two_independently_verified_manual_deposits_settle_without_changing_original_lines(): void
    {
        $original = $this->invoice->only('amount', 'lines', 'destination', 'contract_reference');
        foreach ([40000, 60000] as $index => $amount) {
            $this->actingAs($this->manager)->post('/organization/invoices/'.$this->invoice->id.'/receipts', $this->receiptData($amount, 'DEPOSIT'.$index))->assertSessionHasNoErrors();
            $receipt = PaymentReceipt::latest('id')->firstOrFail();
            $this->assertSame($index === 0 ? 1000000 : 600000, $this->invoice->fresh()->remainingAmount());
            $this->approve($receipt);
        }
        $invoice = $this->invoice->fresh();
        $this->assertSame('paid', $invoice->status);
        $this->assertSame(0, $invoice->remainingAmount());
        $this->assertSame(1000000, $invoice->paidAmount());
        $this->assertSame(4, $invoice->revision);
        $this->assertSame($original, $invoice->only(array_keys($original)));
        $this->assertSame($receipt->payment_id, $invoice->settled_payment_id);
        $this->assertDatabaseCount('organization_payment_allocations', 2);
        $this->actingAs($this->manager)->get('/organization/invoices')->assertInertia(fn (Assert $page) => $page->where('invoices.data.0.remaining_amount', 0)->where('invoices.data.0.paid_amount', 1000000));
    }

    public function test_payment_amount_is_required_integer_positive_and_cannot_exceed_remaining(): void
    {
        $this->actingAs($this->manager);
        foreach ([null, 0, -1, 1.5, 'abc', 100000000001] as $amount) {
            $this->travel(61)->seconds();
            $this->post('/organization/invoices/'.$this->invoice->id.'/pay', ['amount' => $amount])->assertSessionHasErrors('amount');
            $this->post('/organization/invoices/'.$this->invoice->id.'/receipts', [...$this->receiptData(1), 'amount' => $amount])->assertSessionHasErrors('amount');
        }
        $this->post('/organization/invoices/'.$this->invoice->id.'/pay', ['amount' => 100001])->assertSessionHasErrors('invoice');
        $this->post('/organization/invoices/'.$this->invoice->id.'/receipts', $this->receiptData(100001))->assertSessionHasErrors('invoice');
        $this->assertDatabaseCount('payments', 0);
        $this->assertSame([], Storage::disk('receipts')->allFiles());
        Http::assertNothingSent();
    }

    public function test_gateway_attempt_can_be_reused_only_with_same_amount(): void
    {
        $first = $this->charge(40000);
        $this->assertSame($first->id, $this->charge(40000)->id);
        $this->actingAs($this->manager)->get('/organization/invoices/'.$this->invoice->id)->assertInertia(fn (Assert $page) => $page->where('pending_payment_amount', 400000));
        try {
            $this->charge(60000);
            $this->fail('Changing a live attempt amount must be rejected.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('payments', 1);
        }
        $this->travel(16)->minutes();
        $this->get('/organization/invoices/'.$this->invoice->id)->assertInertia(fn (Assert $page) => $page->where('pending_payment_amount', null));
        $second = $this->charge(60000);
        $this->assertNotSame($first->id, $second->id);
        $this->assertSame('expired', $first->fresh()->status);
        $this->assertSame(600000, $second->amount);
    }

    public function test_stale_form_cannot_create_payment_larger_than_new_balance(): void
    {
        $this->capture($this->charge(40000));
        $this->actingAs($this->manager)->post('/organization/invoices/'.$this->invoice->id.'/receipts', $this->receiptData(100000))->assertSessionHasErrors('invoice');
        $this->post('/organization/invoices/'.$this->invoice->id.'/pay', ['amount' => 100000])->assertSessionHasErrors('invoice');
        $this->assertDatabaseCount('payments', 1);
        $this->assertSame([], Storage::disk('receipts')->allFiles());
        Http::assertNothingSent();
    }

    public function test_late_charge_exceeding_remaining_is_preserved_without_partial_allocation(): void
    {
        $old = $this->charge(80000);
        $this->travel(16)->minutes();
        $this->capture($this->charge(40000));
        $this->assertFalse($this->capture($old));
        $this->assertFalse($this->capture($old));
        $this->assertSame('review_required', $old->fresh()->status);
        $this->assertSame('organization_invoice_excess', $old->fresh()->review_reason);
        $this->assertNotNull($old->fresh()->captured_at);
        $this->assertSame(600000, $this->invoice->fresh()->remainingAmount());
        $this->assertSame(400000, (int) OrganizationPaymentAllocation::sum('amount'));
        $this->actingAs($this->manager)->get('/organization/invoices/'.$this->invoice->id)->assertInertia(fn (Assert $page) => $page->where('blocked', true)->where('can_pay', false));
        $this->actingAs($this->admin)->get('/admin/finance?tab=payments')->assertInertia(fn (Assert $page) => $page->where('stats.allocated', 400000)->where('stats.unallocated', 800000));
    }

    public function test_late_verified_charge_fitting_balance_can_finish_invoice(): void
    {
        $old = $this->charge(40000);
        $this->travel(16)->minutes();
        $this->capture($this->charge(60000));
        $this->assertTrue($this->capture($old));
        $this->assertSame('paid', $this->invoice->fresh()->status);
        $this->assertSame(1000000, $this->invoice->fresh()->paidAmount());
        $this->assertDatabaseCount('organization_payment_allocations', 2);
    }

    public function test_manual_review_checks_deposit_amount_rather_than_invoice_total(): void
    {
        $this->actingAs($this->manager)->post('/organization/invoices/'.$this->invoice->id.'/receipts', $this->receiptData(25000))->assertSessionHasNoErrors();
        $receipt = PaymentReceipt::firstOrFail();
        $this->actingAs($this->admin)->post('/admin/finance/receipts/'.$receipt->id.'/review', ['action' => 'approve', 'expected_review_version' => 0, 'statement_verified' => true, 'verified_amount' => 100000, 'review_note' => 'مبلغ کل صورتحساب'])->assertSessionHasErrors('invoice');
        $this->assertSame('pending', $receipt->fresh()->status);
        $this->approve($receipt);
        $this->assertSame(750000, $this->invoice->fresh()->remainingAmount());
    }

    public function test_approved_receipt_overtaken_by_late_gateway_funds_stays_in_review(): void
    {
        $old = $this->charge(60000);
        $this->travel(16)->minutes();
        $this->actingAs($this->manager)->post('/organization/invoices/'.$this->invoice->id.'/receipts', $this->receiptData(80000))->assertSessionHasNoErrors();
        $receipt = PaymentReceipt::firstOrFail();
        $this->capture($old);
        $this->approve($receipt);
        $this->assertSame('approved', $receipt->fresh()->status);
        $this->assertSame('review_required', $receipt->payment->fresh()->status);
        $this->assertNotNull($receipt->payment->fresh()->captured_at);
        $this->assertSame(400000, $this->invoice->fresh()->remainingAmount());
        $this->assertDatabaseCount('organization_payment_allocations', 1);
    }

    public function test_partially_paid_invoice_cannot_be_voided_even_with_fresh_revision(): void
    {
        $this->capture($this->charge(40000));
        $this->actingAs($this->admin)->post('/admin/organization-invoices/'.$this->invoice->id.'/void', ['revision' => 3, 'reason' => 'درخواست ابطال'])->assertSessionHasErrors('invoice');
        $this->assertSame('issued', $this->invoice->fresh()->status);
        $this->assertSame(600000, $this->invoice->fresh()->remainingAmount());
    }

    public function test_partial_provider_amount_mismatch_does_not_record_funds(): void
    {
        $payment = $this->charge(40000);
        $payment->update(['track_id' => 'wrong-partial']);
        Http::fake(['gateway.zibal.ir/v1/verify' => Http::response(['result' => 100, 'status' => 1, 'orderId' => (string) $payment->id, 'amount' => 1000000, 'refNumber' => 'WRONG-BANK'])]);
        $this->get('/organization-payments/callback?trackId=wrong-partial')->assertRedirect();
        $this->assertNull($payment->fresh()->captured_at);
        $this->assertDatabaseCount('organization_payment_allocations', 0);
        $this->assertSame(1000000, $this->invoice->fresh()->remainingAmount());
    }

    public function test_allocation_migration_preserves_prior_full_settlement_and_blocks_ledger_rollback(): void
    {
        $migration = require database_path('migrations/2026_11_13_151038_create_organization_payment_allocations_table.php');
        $migration->down();
        $payment = Payment::create(['organization_invoice_id' => $this->invoice->id, 'enrollment_id' => null, 'user_id' => $this->manager->id, 'amount' => 1000000, 'method' => 'gateway', 'status' => 'paid', 'reference' => 'LEGACY-FULL', 'captured_at' => now(), 'paid_at' => now()]);
        $this->invoice->update(['status' => 'paid', 'settled_payment_id' => $payment->id, 'paid_at' => now()]);
        $migration->up();
        $this->assertDatabaseHas('organization_payment_allocations', ['payment_id' => $payment->id, 'organization_invoice_id' => $this->invoice->id, 'amount' => 1000000]);
        $this->assertSame(0, $this->invoice->fresh()->remainingAmount());
        $this->assertDatabaseCount('payments', 1);
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    public function test_allocation_migration_refuses_inconsistent_prior_settlement(): void
    {
        $migration = require database_path('migrations/2026_11_13_151038_create_organization_payment_allocations_table.php');
        $migration->down();
        $payment = Payment::create(['organization_invoice_id' => $this->invoice->id, 'enrollment_id' => null, 'user_id' => $this->manager->id, 'amount' => 400000, 'method' => 'gateway', 'status' => 'paid', 'captured_at' => now()]);
        $this->invoice->update(['status' => 'paid', 'settled_payment_id' => $payment->id]);
        $this->expectException(\RuntimeException::class);
        $migration->up();
    }

    public function test_allocation_migration_refuses_orphaned_paid_organization_charge(): void
    {
        $migration = require database_path('migrations/2026_11_13_151038_create_organization_payment_allocations_table.php');
        $migration->down();
        Payment::create(['organization_invoice_id' => $this->invoice->id, 'enrollment_id' => null, 'user_id' => $this->manager->id, 'amount' => 1000000, 'method' => 'gateway', 'status' => 'paid', 'captured_at' => now()]);
        $this->expectException(\RuntimeException::class);
        $migration->up();
    }
}
