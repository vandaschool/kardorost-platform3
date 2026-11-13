<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationInvoice;
use App\Models\OrganizationPaymentAllocation;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Role;
use App\Models\User;
use App\Services\OrganizationBilling;
use App\Services\RefundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OrganizationRefundTest extends TestCase
{
    use RefreshDatabase;

    private const IBAN = 'IR820540102680020817909002';

    private OrganizationInvoice $invoice;

    private Payment $excess;

    private User $manager;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('refunds');
        Http::preventStrayRequests();
        config(['platform.finance.transfer_destination' => '6037991234567890', 'platform.finance.transfer_holder' => 'حساب آزمایشی']);
        $this->admin = $this->user('admin');
        $this->manager = $this->user('org_admin');
        $this->invoice = OrganizationInvoice::factory()->create();
        $this->invoice->organization->members()->attach($this->manager->id, ['kind' => 'manager']);
        $billing = app(OrganizationBilling::class);
        $billing->issue($this->admin, $this->invoice, 1);
        $this->invoice->refresh();
        $this->excess = $billing->attempt($this->manager, $this->invoice, 80000);
        $this->travel(16)->minutes();
        $partial = $billing->attempt($this->manager, $this->invoice, 40000);
        $billing->capture($partial, 'PARTIAL-'.$partial->id);
        $billing->capture($this->excess, 'EXCESS-'.$this->excess->id);
        $this->excess->refresh();
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('key', $role)->value('id'));

        return $user;
    }

    private function url(?Payment $payment = null, ?OrganizationInvoice $invoice = null): string
    {
        return '/organization/invoices/'.($invoice ?? $this->invoice)->id.'/payments/'.($payment ?? $this->excess)->id.'/refund';
    }

    private function fields(array $override = []): array
    {
        return [...['version' => 0, 'destination' => self::IBAN, 'beneficiary_name' => 'حساب مجاز سازمان آزمایشی', 'reason' => 'برگشت کامل واریز اضافه قرارداد', 'organization_confirmed' => true], ...$override];
    }

    private function requestRefund(?User $actor = null): Refund
    {
        $this->actingAs($actor ?? $this->manager)->post($this->url(), $this->fields())->assertSessionHasNoErrors();

        return Refund::where('payment_id', $this->excess->id)->firstOrFail();
    }

    private function approve(Refund $refund): void
    {
        $this->actingAs($this->admin)->post('/refunds/'.$refund->id.'/review', ['version' => $refund->fresh()->version, 'action' => 'approve', 'note' => 'با واریز دریافتی و درخواست سازمان تطبیق شد'])->assertSessionHasNoErrors();
    }

    private function completion(Refund $refund, array $override = []): array
    {
        return [...['version' => $refund->fresh()->version, 'destination' => self::IBAN, 'amount' => 80000, 'transfer_reference' => 'RETURN123456', 'transfer_date' => now()->toDateString(), 'statement_verified' => true, 'beneficiary_verified' => true, 'file' => UploadedFile::fake()->createWithContent('return.pdf', "%PDF-1.4\nOrganization refund evidence")], ...$override];
    }

    private function complete(Refund $refund): void
    {
        $this->actingAs($this->admin)->post('/refunds/'.$refund->id.'/complete', $this->completion($refund))->assertSessionHasNoErrors();
    }

    public function test_current_manager_can_request_another_managers_payment_with_encrypted_bank_details(): void
    {
        $other = $this->user('org_admin');
        $this->invoice->organization->members()->attach($other->id, ['kind' => 'manager']);
        $notificationCount = $this->manager->notifications()->count();
        $this->invoice->organization->members()->detach($this->manager->id);
        $refund = $this->requestRefund($other);
        $this->assertSame(800000, $refund->amount);
        $this->assertSame(self::IBAN, $refund->destination);
        $this->assertSame('حساب مجاز سازمان آزمایشی', $refund->beneficiary_name);
        $this->assertNotSame(self::IBAN, DB::table('refunds')->value('destination'));
        $this->assertNotSame($refund->beneficiary_name, DB::table('refunds')->value('beneficiary_name'));
        $this->assertArrayNotHasKey('beneficiary_name', $refund->toArray());
        $this->assertDatabaseHas('refund_events', ['refund_id' => $refund->id, 'actor_id' => $other->id, 'status' => 'requested', 'version' => 1]);
        $this->get('/organization/invoices/'.$this->invoice->id)->assertInertia(fn (Assert $page) => $page->where('blocked', true)->where('payments.data', function ($rows) use ($refund) {
            $payment = collect($rows)->firstWhere('id', $this->excess->id);

            return $payment['refund']['destination'] === null && $payment['refund']['beneficiary_name'] === $refund->beneficiary_name;
        }));
        $this->assertSame($notificationCount, $this->manager->notifications()->count());
        $this->assertSame(1, $other->notifications()->get()->where('data.title', 'وضعیت بازپرداخت سازمان به‌روز شد')->count());
    }

    public function test_request_requires_valid_iban_beneficiary_and_organization_confirmation(): void
    {
        $this->actingAs($this->manager);
        $this->post($this->url(), $this->fields(['destination' => 'IR000540102680020817909002']))->assertSessionHasErrors('refund');
        $this->post($this->url(), $this->fields(['beneficiary_name' => '']))->assertSessionHasErrors('beneficiary_name');
        $this->post($this->url(), $this->fields(['organization_confirmed' => false]))->assertSessionHasErrors('organization_confirmed');
        $this->assertDatabaseCount('refunds', 0);
    }

    public function test_allocated_organization_funds_are_not_refundable(): void
    {
        $payment = $this->invoice->payments()->where('status', 'paid')->sole();
        $this->actingAs($this->manager)->post($this->url($payment), $this->fields())->assertSessionHasErrors('refund');
        $this->assertDatabaseCount('refunds', 0);
        $this->assertSame(400000, $this->invoice->fresh()->paidAmount());
    }

    public function test_uncaptured_or_mixed_purpose_payments_cannot_be_refunded(): void
    {
        $this->excess->update(['captured_at' => null]);
        $this->actingAs($this->manager)->post($this->url(), $this->fields())->assertSessionHasErrors('refund');
        $this->excess->update(['captured_at' => now(), 'reference' => null]);
        $this->post($this->url(), $this->fields())->assertSessionHasErrors('refund');
        $personal = Payment::factory()->create();
        $this->excess->update(['reference' => 'MIXED', 'enrollment_id' => $personal->enrollment_id]);
        $this->post($this->url(), $this->fields())->assertSessionHasErrors('refund');
        $this->assertDatabaseCount('refunds', 0);
    }

    public function test_foreign_roles_selected_organization_and_payment_substitution_are_blocked(): void
    {
        foreach (['student', 'coach', 'org_admin', 'admin'] as $role) {
            $this->actingAs($this->user($role))->post($this->url(), $this->fields())->assertForbidden();
        }
        $other = Organization::factory()->create(['name' => 'سازمان دیگر', 'type' => 'company', 'status' => 'active']);
        $other->members()->attach($this->manager->id, ['kind' => 'manager']);
        $this->actingAs($this->manager)->withSession(['organization_id' => $other->id])->post($this->url(), $this->fields())->assertForbidden();
        $foreignInvoice = OrganizationInvoice::factory()->create(['organization_id' => $other->id, 'status' => 'issued']);
        $this->post($this->url($this->excess, $foreignInvoice), $this->fields())->assertNotFound();
        $this->invoice->organization->update(['status' => 'inactive']);
        $this->withSession(['organization_id' => $this->invoice->organization_id])->post($this->url(), $this->fields())->assertForbidden();
        $this->assertDatabaseCount('refunds', 0);
    }

    public function test_personal_refund_endpoint_and_lists_exclude_organization_funds_even_for_original_payer(): void
    {
        $this->actingAs($this->manager)->post('/payments/'.$this->excess->id.'/refund', ['destination' => self::IBAN, 'version' => 0, 'reason' => 'درخواست در مسیر شخصی', 'owner_confirmed' => true])->assertSessionHasErrors('refund');
        $refund = $this->requestRefund();
        $this->get('/refunds')->assertInertia(fn (Assert $page) => $page->has('rows.data', 0)->has('candidates.data', 0));
        $this->get('/refunds?payment='.$this->excess->id)->assertNotFound();
        $this->get('/finance')->assertInertia(fn (Assert $page) => $page->has('payments.data', 0));
        $this->actingAs($this->admin)->get('/refunds?view=admin&payment='.$this->excess->id)->assertInertia(fn (Assert $page) => $page->where('rows.data.0.id', $refund->id)->where('rows.data.0.organization_invoice_id', $this->invoice->id)->where('rows.data.0.destination', self::IBAN));
    }

    public function test_rejected_request_can_be_revised_by_current_manager_with_versioned_history(): void
    {
        $refund = $this->requestRefund();
        $this->post($this->url(), $this->fields())->assertSessionHasErrors('refund');
        $this->actingAs($this->admin)->post('/refunds/'.$refund->id.'/review', ['version' => 1, 'action' => 'reject', 'note' => 'نام صاحب حساب نیاز به اصلاح دارد'])->assertSessionHasNoErrors();
        $this->actingAs($this->manager)->post($this->url(), $this->fields(['version' => 1]))->assertSessionHasErrors('refund');
        $this->post($this->url(), $this->fields(['version' => 2, 'beneficiary_name' => 'نام اصلاح‌شده حساب سازمان']))->assertSessionHasNoErrors();
        $this->assertSame(3, $refund->fresh()->version);
        $this->assertSame('نام اصلاح‌شده حساب سازمان', $refund->fresh()->beneficiary_name);
        $this->assertDatabaseCount('refund_events', 3);
        $this->assertDatabaseCount('refunds', 1);
    }

    public function test_review_is_admin_only_versioned_and_does_not_invent_a_transfer(): void
    {
        $refund = $this->requestRefund();
        $data = ['version' => 1, 'action' => 'approve', 'note' => 'درخواست بررسی شد'];
        $this->post('/refunds/'.$refund->id.'/review', $data)->assertForbidden();
        $this->approve($refund);
        $this->actingAs($this->admin)->post('/refunds/'.$refund->id.'/review', [...$data, 'action' => 'reject'])->assertSessionHasErrors('refund');
        $this->assertSame('approved', $refund->fresh()->status);
        $this->assertSame('review_required', $this->excess->fresh()->status);
        $this->assertNull($refund->fresh()->file_path);
        $this->assertSame(600000, $this->invoice->fresh()->remainingAmount());
    }

    public function test_completion_requires_whole_deposit_exact_destination_and_independent_checks(): void
    {
        $refund = $this->requestRefund();
        $this->approve($refund);
        foreach ([['amount' => 60000], ['destination' => 'IR060170000000100324200001']] as $override) {
            $this->post('/refunds/'.$refund->id.'/complete', $this->completion($refund, $override))->assertSessionHasErrors('refund');
        }
        foreach (['beneficiary_verified', 'statement_verified'] as $field) {
            $this->post('/refunds/'.$refund->id.'/complete', $this->completion($refund, [$field => false]))->assertSessionHasErrors($field);
        }
        $this->post('/refunds/'.$refund->id.'/complete', $this->completion($refund, ['transfer_date' => now()->subDay()->toDateString()]))->assertSessionHasErrors('refund');
        $this->assertSame([], Storage::disk('refunds')->allFiles());
        $this->assertSame('approved', $refund->fresh()->status);
    }

    public function test_full_refund_preserves_received_funds_invoice_and_allocations_and_unblocks_remaining_payment(): void
    {
        $before = $this->invoice->fresh()->only('amount', 'lines', 'revision', 'status', 'paid_at');
        $captured = $this->excess->only('amount', 'reference');
        $capturedAt = $this->excess->captured_at->toDateTimeString();
        $paidAt = $this->excess->paid_at->toDateTimeString();
        $refund = $this->requestRefund();
        $this->approve($refund);
        $this->complete($refund);
        $this->assertSame('refunded', $this->excess->fresh()->status);
        $this->assertSame($captured, $this->excess->fresh()->only(array_keys($captured)));
        $this->assertSame($capturedAt, $this->excess->fresh()->captured_at->toDateTimeString());
        $this->assertSame($paidAt, $this->excess->fresh()->paid_at->toDateTimeString());
        $this->assertSame($before, $this->invoice->fresh()->only(array_keys($before)));
        $this->assertSame(400000, (int) OrganizationPaymentAllocation::sum('amount'));
        $this->assertSame(600000, $this->invoice->fresh()->remainingAmount());
        $this->assertFalse(app(OrganizationBilling::class)->capture($this->excess, $this->excess->reference));
        $this->post('/refunds/'.$refund->id.'/complete', $this->completion($refund))->assertSessionHasErrors('refund');
        $this->assertSame(1, count(Storage::disk('refunds')->allFiles()));
        $this->assertDatabaseCount('refund_events', 3);
        $this->get('/admin/finance?tab=payments')->assertInertia(fn (Assert $page) => $page->where('stats.unallocated', 0)->where('stats.allocated', 400000));
        $this->actingAs($this->manager)->get('/organization/invoices/'.$this->invoice->id)->assertInertia(fn (Assert $page) => $page->where('blocked', false)->where('can_pay', true));
        $next = app(OrganizationBilling::class)->attempt($this->manager, $this->invoice, 60000);
        $this->assertSame(600000, $next->amount);
    }

    public function test_private_proof_requires_current_selected_org_management_instead_of_original_payment_ownership(): void
    {
        $refund = $this->requestRefund();
        $this->approve($refund);
        $this->complete($refund);
        $otherManager = $this->user('org_admin');
        $this->invoice->organization->members()->attach($otherManager->id, ['kind' => 'manager']);
        $this->actingAs($otherManager)->get('/refunds/'.$refund->id.'/file')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
        $otherOrg = Organization::factory()->create(['name' => 'سازمان دیگر', 'type' => 'company', 'status' => 'active']);
        $otherOrg->members()->attach($otherManager->id, ['kind' => 'manager']);
        $this->withSession(['organization_id' => $otherOrg->id])->get('/refunds/'.$refund->id.'/file')->assertForbidden();
        $this->invoice->organization->members()->detach($this->manager->id);
        $this->actingAs($this->manager)->withSession(['organization_id' => $this->invoice->organization_id])->get('/refunds/'.$refund->id.'/file')->assertForbidden();
        $this->get('/refunds')->assertInertia(fn (Assert $page) => $page->has('rows.data', 0));
        $this->actingAs($this->admin)->get('/refunds/'.$refund->id.'/file')->assertOk();
    }

    public function test_large_corporate_deposit_can_be_fully_refunded_within_invoice_amount_limits(): void
    {
        $this->excess->update(['amount' => 20000000000]);
        $refund = $this->requestRefund();
        $this->approve($refund);
        $this->post('/refunds/'.$refund->id.'/complete', $this->completion($refund, ['amount' => 2000000000]))->assertSessionHasNoErrors();
        $this->assertSame('completed', $refund->fresh()->status);
    }

    public function test_corporate_refund_proof_and_transfer_keys_cannot_reuse_personal_refund_evidence(): void
    {
        $personalPayment = Payment::factory()->create(['status' => 'review_required', 'reference' => 'PERSONAL-FUNDS', 'captured_at' => now(), 'amount' => 800000]);
        $service = app(RefundService::class);
        $personal = $service->request($personalPayment->user, $personalPayment, self::IBAN, 'درخواست شخصی', 0);
        $this->approve($personal);
        $this->complete($personal);
        $corporate = $this->requestRefund();
        $this->approve($corporate);
        $this->post('/refunds/'.$corporate->id.'/complete', $this->completion($corporate, ['transfer_reference' => 'NEW-REFERENCE']))->assertSessionHasErrors('refund');
        $this->post('/refunds/'.$corporate->id.'/complete', $this->completion($corporate, ['file' => UploadedFile::fake()->createWithContent('other.pdf', "%PDF-1.4\nOther transfer")]))->assertSessionHasErrors('refund');
        $this->assertSame(1, count(Storage::disk('refunds')->allFiles()));
        $this->assertSame('approved', $corporate->fresh()->status);
    }

    public function test_migration_cannot_drop_existing_encrypted_organization_beneficiaries(): void
    {
        $this->requestRefund();
        $migration = require database_path('migrations/2026_11_13_214544_add_beneficiary_name_to_refunds_table.php');
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }
}
