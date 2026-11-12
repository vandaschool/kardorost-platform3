<?php

namespace Tests\Feature;

use App\Models\Cohort;
use App\Models\Organization;
use App\Models\OrganizationInvoice;
use App\Models\OrganizationLicense;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Models\Role;
use App\Models\User;
use App\Services\EnrollmentService;
use App\Services\OrganizationBilling;
use App\Services\ReceiptService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OrganizationInvoiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('receipts');
        Http::preventStrayRequests();
        config(['platform.finance.transfer_destination' => '6037991234567890', 'platform.finance.transfer_holder' => 'دارنده حساب آزمایشی', 'platform.zibal.enabled' => true, 'platform.zibal.merchant' => 'test']);
    }

    private function user(string $role = 'admin'): User
    {
        $u = User::factory()->create();
        $u->roles()->attach(Role::where('key', $role)->value('id'));

        return $u;
    }

    private function manager(Organization $org): User
    {
        $u = $this->user('org_admin');
        $org->members()->attach($u->id, ['kind' => 'manager']);

        return $u;
    }

    private function draft(): OrganizationInvoice
    {
        return OrganizationInvoice::factory()->create();
    }

    private function issued(): OrganizationInvoice
    {
        $i = $this->draft();
        app(OrganizationBilling::class)->issue($this->user(), $i, 1);

        return $i->fresh();
    }

    private function fields(): array
    {
        return ['title' => 'قرارداد آزمایشی', 'contract_reference' => 'CONTRACT-1', 'due_date' => now()->addWeek()->toDateString(), 'organization_license_id' => null, 'lines' => [['title' => 'خدمات آموزش', 'quantity' => 2, 'unit_price' => 50000]]];
    }

    private function file(string $content = 'proof'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('invoice.pdf', "%PDF-1.4\n".$content);
    }

    private function receipt(OrganizationInvoice $i, ?User $manager = null, string $ref = '12345678', string $content = 'proof'): PaymentReceipt
    {
        return app(OrganizationBilling::class)->receipt($manager ?? $this->manager($i->organization), $i, $this->file($content), $ref, now()->toDateString());
    }

    private function review(PaymentReceipt $r, array $override = []): void
    {
        $this->actingAs($this->user())->post('/admin/finance/receipts/'.$r->id.'/review', [...['action' => 'approve', 'expected_review_version' => 0, 'review_note' => 'در گردش حساب بررسی شد', 'verified_amount' => 100000, 'statement_verified' => true], ...$override])->assertSessionHasNoErrors();
    }

    public function test_admin_draft_calculates_rial_total_from_lines_and_issue_freezes_recipient(): void
    {
        $org = Organization::factory()->create(['name' => 'سازمان قرارداد', 'type' => 'company', 'status' => 'active']);
        $this->actingAs($this->user())->post('/admin/organization-invoices', ['organization_id' => $org->id, 'definition' => $this->fields()])->assertSessionHasNoErrors();
        $i = OrganizationInvoice::firstOrFail();
        $this->assertSame(1000000, $i->amount);
        $this->assertSame(500000, $i->lines[0]['unit_price']);
        $this->post('/admin/organization-invoices/'.$i->id.'/issue', ['revision' => 1, 'reviewed' => true])->assertSessionHasNoErrors();
        $i->refresh();
        $this->assertSame('issued', $i->status);
        $this->assertNotNull($i->number);
        config(['platform.finance.transfer_destination' => '111111111111']);
        $this->assertSame('6037991234567890', $i->fresh()->destination);
    }

    public function test_only_admin_can_create_issue_edit_or_void_invoice(): void
    {
        $i = $this->draft();
        foreach (['student', 'coach', 'org_admin'] as $role) {
            $this->actingAs($this->user($role))->post('/admin/organization-invoices', [])->assertForbidden();
            $this->post('/admin/organization-invoices/'.$i->id.'/issue', [])->assertForbidden();
            $this->patch('/admin/organization-invoices/'.$i->id, [])->assertForbidden();
            $this->post('/admin/organization-invoices/'.$i->id.'/void', [])->assertForbidden();
        }
    }

    public function test_draft_is_invisible_to_manager_and_issue_requires_bank_configuration_and_review(): void
    {
        $i = $this->draft();
        $u = $this->manager($i->organization);
        $this->actingAs($u)->get('/organization/invoices')->assertInertia(fn (Assert $p) => $p->has('invoices.data', 0));
        $this->get('/organization/invoices/'.$i->id)->assertForbidden();
        $this->actingAs($this->user())->post('/admin/organization-invoices/'.$i->id.'/issue', ['revision' => 1])->assertSessionHasErrors('reviewed');
        config(['platform.finance.transfer_holder' => null]);
        $this->post('/admin/organization-invoices/'.$i->id.'/issue', ['revision' => 1, 'reviewed' => true])->assertSessionHasErrors('invoice');
        $this->assertSame('draft', $i->fresh()->status);
    }

    public function test_issued_price_and_rows_cannot_be_edited_and_stale_draft_is_rejected(): void
    {
        $i = $this->draft();
        $admin = $this->user();
        $this->actingAs($admin)->patch('/admin/organization-invoices/'.$i->id, ['revision' => 99, 'definition' => $this->fields()])->assertSessionHasErrors('invoice');
        app(OrganizationBilling::class)->issue($admin, $i, 1);
        $this->patch('/admin/organization-invoices/'.$i->id, ['revision' => 2, 'definition' => $this->fields()])->assertSessionHasErrors('invoice');
        $this->assertSame(1000000, $i->fresh()->amount);
    }

    public function test_zero_negative_and_excessive_invoice_totals_are_rejected(): void
    {
        $i = $this->draft();
        $admin = $this->user();
        foreach ([0, -1, 1000000000] as $price) {
            $d = $this->fields();
            $d['lines'][0]['unit_price'] = $price;
            $d['lines'][0]['quantity'] = 10000;
            $this->actingAs($admin)->patch('/admin/organization-invoices/'.$i->id, ['revision' => 1, 'definition' => $d])->assertSessionHasErrors();
        }$this->assertSame(1, $i->fresh()->revision);
    }

    public function test_manager_cannot_access_other_org_invoice_or_change_selected_org_scope(): void
    {
        $i = $this->issued();
        $u = $this->manager($i->organization);
        $other = Organization::factory()->create(['name' => 'سازمان دیگر', 'type' => 'company', 'status' => 'active']);
        $other->members()->attach($u->id, ['kind' => 'manager']);
        $this->actingAs($u)->withSession(['organization_id' => $other->id])->get('/organization/invoices/'.$i->id)->assertForbidden();
        $this->post('/organization/invoices/'.$i->id.'/pay')->assertForbidden();
        $this->withSession(['organization_id' => $i->organization_id])->get('/organization/invoices/'.$i->id)->assertOk();
        $i->organization->members()->detach($u->id);
        $this->get('/organization/invoices/'.$i->id)->assertForbidden();
    }

    public function test_receipt_upload_never_marks_invoice_paid_and_is_private(): void
    {
        $i = $this->issued();
        $u = $this->manager($i->organization);
        $r = $this->receipt($i, $u);
        $this->assertSame('issued', $i->fresh()->status);
        $this->assertSame('pending_review', $r->payment->status);
        $this->assertNull($r->payment->captured_at);
        $this->assertDatabaseCount('receipt_claims', 1);
        $this->actingAs($u)->get('/receipts/'.$r->id.'/file')->assertOk();
        $second = $this->manager($i->organization);
        $this->actingAs($second)->get('/receipts/'.$r->id.'/file')->assertOk();
        $i->organization->members()->detach($u->id);
        $this->actingAs($u)->get('/receipts/'.$r->id.'/file')->assertForbidden();
        $this->actingAs($this->user('org_admin'))->get('/receipts/'.$r->id.'/file')->assertForbidden();
        $this->actingAs($this->user())->get('/receipts/'.$r->id.'/file')->assertOk();
    }

    public function test_statement_and_exact_amount_are_required_to_settle_receipt(): void
    {
        $i = $this->issued();
        $r = $this->receipt($i);
        $admin = $this->user();
        foreach ([['verified_amount' => 10000], ['statement_verified' => false]] as $override) {
            $this->actingAs($admin)->post('/admin/finance/receipts/'.$r->id.'/review', [...['action' => 'approve', 'expected_review_version' => 0, 'review_note' => 'بررسی واریز', 'verified_amount' => 100000, 'statement_verified' => true], ...$override])->assertSessionHasErrors('invoice');
        }$this->assertSame('pending', $r->fresh()->status);
        $this->review($r);
        $this->assertSame('paid', $i->fresh()->status);
        $this->assertSame($r->payment_id, $i->fresh()->settled_payment_id);
        $this->assertSame('paid', $r->payment->fresh()->status);
        $this->assertDatabaseCount('organization_licenses', 0);
        $this->assertDatabaseCount('enrollments', 0);
    }

    public function test_stale_receipt_review_does_not_reject_a_previously_approved_charge(): void
    {
        $i = $this->issued();
        $r = $this->receipt($i);
        $this->review($r);
        $this->actingAs($this->user())->post('/admin/finance/receipts/'.$r->id.'/review', ['action' => 'reject', 'expected_review_version' => 0, 'review_note' => 'فرم قدیمی'])->assertSessionHasErrors('invoice');
        $this->assertSame('approved', $r->fresh()->status);
        $this->assertSame('paid', $i->fresh()->status);
    }

    public function test_rejected_proof_can_be_corrected_and_payment_remains_unsettled(): void
    {
        $i = $this->issued();
        $u = $this->manager($i->organization);
        $r = $this->receipt($i, $u);
        $this->review($r, ['action' => 'reject', 'review_note' => 'گردش حساب تطبیق ندارد']);
        $this->assertDatabaseCount('receipt_claims', 0);
        $new = $this->receipt($i, $u);
        $this->assertNotSame($r->id, $new->id);
        $this->assertSame('issued', $i->fresh()->status);
        $this->assertSame(2, count(Storage::disk('receipts')->allFiles()));
    }

    public function test_same_receipt_or_transfer_cannot_fund_two_invoices(): void
    {
        $i = $this->issued();
        $r = $this->receipt($i);
        $other = $this->issued();
        $u = $this->manager($other->organization);
        $this->actingAs($u)->withSession(['organization_id' => $other->organization_id])->post('/organization/invoices/'.$other->id.'/receipts', ['file' => $this->file(), 'transfer_reference' => '98765432', 'transfer_date' => now()->toDateString()])->assertSessionHasErrors('invoice');
        $this->post('/organization/invoices/'.$other->id.'/receipts', ['file' => $this->file('other'), 'transfer_reference' => '۱۲۳۴۵۶۷۸', 'transfer_date' => now()->toDateString()])->assertSessionHasErrors('invoice');
        $this->assertDatabaseCount('payment_receipts', 1);
        $this->assertSame(1, count(Storage::disk('receipts')->allFiles()));
    }

    public function test_receipt_claims_are_shared_with_individual_course_receipts(): void
    {
        $u = $this->user('student');
        $c = Cohort::factory()->create(['manual_payment_enabled' => true]);
        $c->course->update(['price' => 1000000]);
        $e = app(EnrollmentService::class)->reserve($u, $c);
        $invoice = $e->installments()->first();
        app(ReceiptService::class)->submit($u, $invoice, $this->file(), '12345678', now()->toDateString());
        $i = $this->issued();
        $manager = $this->manager($i->organization);
        $this->actingAs($manager)->post('/organization/invoices/'.$i->id.'/receipts', ['file' => $this->file('new'), 'transfer_reference' => '12345678', 'transfer_date' => now()->toDateString()])->assertSessionHasErrors('invoice');
        $this->assertDatabaseCount('payment_receipts', 1);
    }

    public function test_gateway_payment_uses_own_callback_and_reuses_pending_attempt(): void
    {
        $i = $this->issued();
        $u = $this->manager($i->organization);
        Http::fake(['gateway.zibal.ir/v1/request' => Http::response(['result' => 100, 'trackId' => 'org-track'])]);
        $this->actingAs($u)->post('/organization/invoices/'.$i->id.'/pay')->assertRedirect('https://gateway.zibal.ir/start/org-track');
        $this->post('/organization/invoices/'.$i->id.'/pay')->assertRedirect('https://gateway.zibal.ir/start/org-track');
        $this->assertDatabaseCount('payments', 1);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r['amount'] === 1000000 && str_ends_with($r['callbackUrl'], '/organization-payments/callback'));
    }

    private function gatewayCharge(OrganizationInvoice $i): Payment
    {
        $u = $this->manager($i->organization);
        $p = app(OrganizationBilling::class)->attempt($u, $i);
        $p->update(['track_id' => 'org-'.$p->id]);

        return $p;
    }

    private function verify(Payment $p, array $override = []): void
    {
        Http::fake(['gateway.zibal.ir/v1/verify' => Http::response([...['result' => 100, 'status' => 1, 'amount' => $p->amount, 'orderId' => (string) $p->id, 'refNumber' => 'BANK-'.$p->id], ...$override])]);
    }

    public function test_verified_callback_is_idempotent_and_never_uses_success_flag(): void
    {
        $i = $this->issued();
        $p = $this->gatewayCharge($i);
        $this->verify($p);
        $this->get('/organization-payments/callback?trackId='.$p->track_id.'&success=0')->assertRedirect('/organization/invoices/'.$i->id);
        $this->assertSame('paid', $i->fresh()->status);
        $this->assertNotNull($p->fresh()->captured_at);
        $this->get('/organization-payments/callback?trackId='.$p->track_id);
        Http::assertSentCount(1);
        $this->assertDatabaseCount('organization_licenses', 0);
    }

    public function test_wrong_order_or_amount_does_not_settle_invoice(): void
    {
        $cases = [];
        $responses = [];
        foreach ([['amount' => 1], ['orderId' => 'other']] as $override) {
            $i = $this->issued();
            $p = $this->gatewayCharge($i);
            $cases[] = [$i, $p];
            $responses[$p->track_id] = [...['result' => 100, 'status' => 1, 'amount' => $p->amount, 'orderId' => (string) $p->id, 'refNumber' => 'BANK-'.$p->id], ...$override];
        }
        Http::fake(fn ($r) => Http::response($responses[$r['trackId']]));
        foreach ($cases as [$i,$p]) {
            $this->get('/organization-payments/callback?trackId='.$p->track_id.'&success=1');
            $this->assertSame('issued', $i->fresh()->status);
            $this->assertNull($p->fresh()->captured_at);
        }
    }

    public function test_void_is_versioned_and_late_verified_funds_are_preserved_for_review(): void
    {
        $i = $this->issued();
        $p = $this->gatewayCharge($i);
        $this->actingAs($this->user())->post('/admin/organization-invoices/'.$i->id.'/void', ['revision' => 2, 'reason' => 'قرارداد اشتباه صادر شده'])->assertSessionHasErrors('invoice');
        $this->travel(16)->minutes();
        $this->post('/admin/organization-invoices/'.$i->id.'/void', ['revision' => 2, 'reason' => 'قرارداد اشتباه صادر شده'])->assertSessionHasNoErrors();
        $this->assertFalse(app(OrganizationBilling::class)->capture($p, 'LATE-BANK'));
        $this->assertSame('void', $i->fresh()->status);
        $this->assertSame('review_required', $p->fresh()->status);
        $this->assertNotNull($p->fresh()->captured_at);
    }

    public function test_two_verified_charges_for_one_invoice_keep_extra_money_in_review(): void
    {
        $i = $this->issued();
        $p = $this->gatewayCharge($i);
        $other = Payment::create([...$p->only('organization_invoice_id', 'user_id', 'amount', 'method'), 'status' => 'pending']);
        $this->assertTrue(app(OrganizationBilling::class)->capture($p, 'FIRST'));
        $this->assertFalse(app(OrganizationBilling::class)->capture($other, 'SECOND'));
        $this->assertSame($p->id, $i->fresh()->settled_payment_id);
        $this->assertSame('organization_invoice_duplicate', $other->fresh()->review_reason);
    }

    public function test_callback_routes_cannot_confuse_course_or_consultation_with_organization_payment(): void
    {
        $i = $this->issued();
        $p = $this->gatewayCharge($i);
        $this->get('/payments/callback?trackId='.$p->track_id)->assertNotFound();
        $this->get('/consultation-payments/callback?trackId='.$p->track_id)->assertNotFound();
        $personal = Payment::factory()->create(['track_id' => 'personal']);
        $this->get('/organization-payments/callback?trackId=personal')->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_corporate_bank_reference_cannot_reuse_personal_received_charge(): void
    {
        Payment::factory()->create(['reference' => 'SHARED-BANK', 'captured_at' => now(), 'status' => 'paid']);
        $i = $this->issued();
        $p = $this->gatewayCharge($i);
        $this->assertFalse(app(OrganizationBilling::class)->capture($p, 'SHARED-BANK'));
        $this->assertNull($p->fresh()->captured_at);
        $this->assertSame('issued', $i->fresh()->status);
    }

    public function test_corporate_payments_are_not_personal_debt_or_personal_refund_candidates(): void
    {
        $i = $this->issued();
        $p = $this->gatewayCharge($i);
        app(OrganizationBilling::class)->capture($p, 'PAID-ORG');
        $this->actingAs($p->user)->get('/finance')->assertInertia(fn (Assert $a) => $a->has('payments.data', 0));
        $this->get('/refunds')->assertInertia(fn (Assert $a) => $a->has('candidates.data', 0));
        $this->actingAs($this->user())->get('/admin/finance?tab=payments')->assertInertia(fn (Assert $a) => $a->where('rows.data.0.organization_invoice_id', $i->id)->where('rows.data.0.can_allocate', false)->where('rows.data.0.can_request_refund', false));
    }

    public function test_gateway_and_manual_submission_cannot_run_at_the_same_time(): void
    {
        $i = $this->issued();
        $p = $this->gatewayCharge($i);
        $this->actingAs($p->user)->post('/organization/invoices/'.$i->id.'/receipts', ['file' => $this->file(), 'transfer_reference' => '12345678', 'transfer_date' => now()->toDateString()])->assertSessionHasErrors('invoice');
        $this->assertSame([], Storage::disk('receipts')->allFiles());
        $this->travel(16)->minutes();
        $r = $this->receipt($i, $p->user);
        $this->post('/organization/invoices/'.$i->id.'/pay')->assertSessionHasErrors('invoice');
        $this->assertSame('pending_review', $r->payment->status);
    }

    public function test_frozen_payer_name_does_not_reveal_later_profile_changes_to_an_organization(): void
    {
        $i = $this->issued();
        $p = $this->gatewayCharge($i);
        $old = $p->user->name;
        $p->user->update(['name' => 'نام تازه خارج سازمان']);
        $another = $this->manager($i->organization);
        $this->actingAs($another)->get('/organization/invoices/'.$i->id)->assertInertia(fn (Assert $a) => $a->where('payments.data.0.payer_name_snapshot', $old));
    }

    public function test_foreign_license_cannot_be_attached_to_contract_invoice(): void
    {
        $i = $this->draft();
        $l = OrganizationLicense::factory()->create();
        $d = $this->fields();
        $d['organization_license_id'] = $l->id;
        $this->actingAs($this->user())->patch('/admin/organization-invoices/'.$i->id, ['revision' => 1, 'definition' => $d])->assertSessionHasErrors('invoice');
        $this->assertNull($i->fresh()->organization_license_id);
    }

    public function test_invalid_upload_and_future_transfer_date_leave_no_payment_records(): void
    {
        $i = $this->issued();
        $u = $this->manager($i->organization);
        $this->actingAs($u)->post('/organization/invoices/'.$i->id.'/receipts', ['file' => UploadedFile::fake()->create('proof.exe',1), 'transfer_reference' => '12345678', 'transfer_date' => now()->toDateString()])->assertSessionHasErrors('file');
        $this->post('/organization/invoices/'.$i->id.'/receipts',['file' => $this->file(), 'transfer_reference' => '12345678', 'transfer_date' => now()->addDay()->toDateString()])->assertSessionHasErrors('transfer_date');
        $this->assertDatabaseCount('payments',0);
    }
}
