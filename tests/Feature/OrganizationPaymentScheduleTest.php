<?php

namespace Tests\Feature;

use App\Models\Organization;
use App\Models\OrganizationInvoice;
use App\Models\OrganizationPaymentAllocation;
use App\Models\PaymentReceipt;
use App\Models\Role;
use App\Models\User;
use App\Services\OrganizationBilling;
use App\Services\OrganizationPaymentSchedule;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OrganizationPaymentScheduleTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $manager;

    private Organization $organization;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse('2026-06-01 20:45:00', 'UTC'));
        Storage::fake('receipts');
        Http::preventStrayRequests();
        config(['platform.finance.transfer_destination' => '6037991234567890', 'platform.finance.transfer_holder' => 'حساب آزمایشی']);
        $this->admin = $this->user('admin');
        $this->manager = $this->user('org_admin');
        $this->organization = Organization::factory()->create(['status' => 'active']);
        $this->organization->members()->attach($this->manager->id, ['kind' => 'manager']);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('key', $role)->value('id'));

        return $user;
    }

    private function fields(): array
    {
        return ['title' => 'قرارداد اقساطی آزمایشی', 'contract_reference' => 'SCHEDULE-TEST', 'due_date' => '2026-06-30', 'lines' => [['title' => 'خدمات', 'quantity' => 1, 'unit_price' => 100000]], 'installments' => [
            ['due_date' => '2026-06-01', 'amount' => 40000],
            ['due_date' => '2026-06-02', 'amount' => 30000],
            ['due_date' => '2026-06-30', 'amount' => 30000],
        ]];
    }

    private function draft(?array $input = null): OrganizationInvoice
    {
        return app(OrganizationBilling::class)->draft($this->admin, $this->organization, $input ?? $this->fields());
    }

    private function issued(): OrganizationInvoice
    {
        $invoice = $this->draft();
        app(OrganizationBilling::class)->issue($this->admin, $invoice, $invoice->revision);

        return $invoice->fresh();
    }

    private function pay(OrganizationInvoice $invoice, int $toman, string $reference): void
    {
        $payment = app(OrganizationBilling::class)->attempt($this->manager, $invoice, $toman);
        $this->assertTrue(app(OrganizationBilling::class)->capture($payment, $reference));
    }

    public function test_draft_converts_toman_once_and_issue_freezes_method_order_and_dates(): void
    {
        $invoice = $this->draft();
        $this->assertSame(400000, $invoice->payment_schedule['items'][0]['amount']);
        $this->assertSame([1, 2, 3], array_column($invoice->payment_schedule['items'], 'sequence'));
        app(OrganizationBilling::class)->issue($this->admin, $invoice, 1);
        $this->assertSame('oldest_due_first_v1', $invoice->fresh()->payment_schedule['method']);
        $this->actingAs($this->admin)->get('/admin/organization-invoices/'.$invoice->id)->assertInertia(fn (Assert $page) => $page->where('invoice.schedule.overdue_amount', 400000)->where('invoice.schedule.due_today_amount', 300000)->where('invoice.schedule.items.2.status', 'upcoming'));
    }

    public function test_schedule_rejects_wrong_totals_last_date_order_duplicate_dates_and_malformed_rows(): void
    {
        $good = $this->fields()['installments'];
        $variants = [
            [], 'invalid', ['bad' => $good[0]],
            [['due_date' => '2026-06-30', 'amount' => 99999]],
            [['due_date' => '2026-06-29', 'amount' => 100000]],
            [$good[1], $good[0], $good[2]],
            [$good[0], ['due_date' => '2026-06-01', 'amount' => 30000], $good[2]],
            [['due_date' => '2026-02-30', 'amount' => 100000]],
            [['due_date' => '2026-06-30', 'amount' => 0]],
            [['due_date' => '2026-06-30', 'amount' => -1]],
            [['due_date' => '2026-06-30', 'amount' => '100000.5']],
            array_fill(0, 25, $good[0]),
        ];
        foreach ($variants as $rows) {
            try {
                $this->draft([...$this->fields(), 'installments' => $rows]);
                $this->fail('Invalid schedule was accepted.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('organization_invoices', 0);
            }
        }
    }

    public function test_client_sequence_and_paid_status_cannot_be_injected_into_schedule(): void
    {
        $fields = $this->fields();
        $fields['installments'][0] += ['sequence' => 99, 'paid_amount' => 400000, 'status' => 'paid'];
        $invoice = $this->draft($fields);
        $this->assertSame(['sequence' => 1, 'due_date' => '2026-06-01', 'amount' => 400000], $invoice->payment_schedule['items'][0]);
    }

    public function test_unpaid_installment_can_be_overdue_before_final_invoice_due_date(): void
    {
        $invoice = $this->issued();
        $this->actingAs($this->manager)->get('/organization/invoices')->assertInertia(fn (Assert $page) => $page->where('invoices.data.0.overdue', true)->where('invoices.data.0.schedule.overdue_amount', 400000));
        $this->assertSame('2026-06-30', $invoice->due_date->toDateString());
    }

    public function test_partial_verified_payments_cover_oldest_due_then_today_then_future(): void
    {
        $invoice = $this->issued();
        $this->pay($invoice, 25000, 'SCHEDULE-FIRST');
        $this->actingAs($this->manager)->get('/organization/invoices/'.$invoice->id)->assertInertia(fn (Assert $page) => $page->where('invoice.schedule.overdue_amount', 150000)->where('invoice.schedule.items.0.paid_amount', 250000)->where('invoice.schedule.items.1.paid_amount', 0));
        $this->pay($invoice, 35000, 'SCHEDULE-SECOND');
        $this->actingAs($this->manager)->get('/organization/invoices/'.$invoice->id)->assertInertia(fn (Assert $page) => $page->where('invoice.schedule.overdue_amount', 0)->where('invoice.schedule.due_today_amount', 100000)->where('invoice.schedule.items.0.status', 'paid')->where('invoice.schedule.items.1.paid_amount', 200000)->where('invoice.remaining_amount', 400000));
        $this->assertSame(600000, (int) OrganizationPaymentAllocation::sum('amount'));
    }

    public function test_future_installments_allow_full_early_settlement_without_new_obligations(): void
    {
        $invoice = $this->issued();
        $this->pay($invoice, 100000, 'SCHEDULE-FULL');
        $summary = app(OrganizationPaymentSchedule::class)->summary($invoice->fresh(), $invoice->fresh()->paidAmount());
        $this->assertSame(['paid', 'paid', 'paid'], array_column($summary['items'], 'status'));
        $this->assertNull($summary['next_due_date']);
        $this->assertSame(0, $summary['overdue_amount']);
        $this->assertDatabaseCount('organization_payment_allocations', 1);
        $this->assertDatabaseCount('enrollments', 0);
        $this->assertDatabaseCount('organization_licenses', 0);
    }

    public function test_unreviewed_receipt_does_not_reduce_installment_debt(): void
    {
        $invoice = $this->issued();
        app(OrganizationBilling::class)->receipt($this->manager, $invoice, UploadedFile::fake()->createWithContent('proof.pdf', "%PDF-1.4\nreceipt"), 'SCHEDULE-RECEIPT', '2026-06-01', 40000);
        $this->assertDatabaseCount('payment_receipts', 1);
        $this->actingAs($this->manager)->get('/organization/invoices/'.$invoice->id)->assertInertia(fn (Assert $page) => $page->where('invoice.schedule.overdue_amount', 400000)->where('invoice.paid_amount', 0)->where('blocked', true));
    }

    public function test_approved_receipt_covers_installments_only_after_verified_capture(): void
    {
        $invoice = $this->issued();
        $receipt = app(OrganizationBilling::class)->receipt($this->manager, $invoice, UploadedFile::fake()->createWithContent('proof.pdf', "%PDF-1.4\napproved"), 'SCHEDULE-APPROVED', '2026-06-01', 40000);
        app(OrganizationBilling::class)->review($this->admin, $receipt, ['action' => 'approve', 'expected_review_version' => 0, 'statement_verified' => true, 'verified_amount' => 40000, 'review_note' => 'تطبیق گردش حساب']);
        $summary = app(OrganizationPaymentSchedule::class)->summary($invoice->fresh(), $invoice->fresh()->paidAmount());
        $this->assertSame(0, $summary['overdue_amount']);
        $this->assertSame('paid', $summary['items'][0]['status']);
        $this->assertSame('approved', PaymentReceipt::findOrFail($receipt->id)->status);
        Http::assertNothingSent();
    }

    public function test_draft_edits_use_revision_and_stale_or_issued_forms_cannot_rewrite_schedule(): void
    {
        $invoice = $this->draft();
        $changed = [...$this->fields(), 'installments' => [['due_date' => '2026-06-30', 'amount' => 100000]]];
        $this->actingAs($this->admin)->patch('/admin/organization-invoices/'.$invoice->id, ['revision' => 1, 'definition' => $changed])->assertSessionHasNoErrors();
        $this->patch('/admin/organization-invoices/'.$invoice->id, ['revision' => 1, 'definition' => $this->fields()])->assertSessionHasErrors('invoice');
        $this->assertCount(1, $invoice->fresh()->payment_schedule['items']);
        app(OrganizationBilling::class)->issue($this->admin, $invoice, 2);
        $this->patch('/admin/organization-invoices/'.$invoice->id, ['revision' => 3, 'definition' => $this->fields()])->assertSessionHasErrors('invoice');
        $this->assertCount(1, $invoice->fresh()->payment_schedule['items']);
    }

    public function test_changing_lines_requires_resubmitting_a_matching_schedule(): void
    {
        $invoice = $this->draft();
        $fields = $this->fields();
        $fields['lines'][0]['unit_price'] = 200000;
        $this->actingAs($this->admin)->patch('/admin/organization-invoices/'.$invoice->id, ['revision' => 1, 'definition' => $fields])->assertSessionHasErrors('invoice');
        $this->assertSame(1000000, $invoice->fresh()->amount);
        $this->assertSame(1, $invoice->fresh()->revision);
    }

    public function test_legacy_issued_invoice_has_one_date_without_modifying_its_ledger(): void
    {
        $invoice = OrganizationInvoice::factory()->create(['organization_id' => $this->organization->id, 'status' => 'issued', 'due_date' => '2026-06-01', 'payment_schedule' => null]);
        $this->pay($invoice, 25000, 'SCHEDULE-LEGACY');
        $this->actingAs($this->manager)->get('/organization/invoices/'.$invoice->id)->assertInertia(fn (Assert $page) => $page->has('invoice.schedule.items', 1)->where('invoice.schedule.overdue_amount', 750000));
        $this->assertNull($invoice->fresh()->payment_schedule);
        $this->assertDatabaseCount('organization_payment_allocations', 1);
    }

    public function test_draft_and_voided_schedule_do_not_appear_as_collectible_overdue_debt(): void
    {
        $invoice = $this->draft();
        $schedule = app(OrganizationPaymentSchedule::class);
        $this->assertSame(0, $schedule->summary($invoice, 0)['overdue_amount']);
        app(OrganizationBilling::class)->void($this->admin, $invoice, 1, 'عدم صدور قرارداد');
        $this->assertSame(['void', 'void', 'void'], array_column($schedule->summary($invoice->fresh(), 0)['items'], 'status'));
        $this->assertSame(0, $schedule->summary($invoice->fresh(), 0)['overdue_amount']);
    }

    public function test_schedule_dates_use_tehran_midnight_instead_of_utc_day(): void
    {
        $invoice = $this->issued();
        $summary = app(OrganizationPaymentSchedule::class)->summary($invoice, 0);
        $this->assertSame('overdue', $summary['items'][0]['status']);
        $this->assertSame('due', $summary['items'][1]['status']);
        $this->travelTo(CarbonImmutable::parse('2026-06-01 20:29:00', 'UTC'));
        $summary = app(OrganizationPaymentSchedule::class)->summary($invoice, 0);
        $this->assertSame('due', $summary['items'][0]['status']);
        $this->assertSame('upcoming', $summary['items'][1]['status']);
    }

    public function test_schedule_scope_follows_selected_organization_and_current_manager_membership(): void
    {
        $invoice = $this->issued();
        $peer = $this->user('org_admin');
        $other = Organization::factory()->create(['status' => 'active']);
        $other->members()->attach($peer->id, ['kind' => 'manager']);
        $this->actingAs($peer)->get('/organization/invoices/'.$invoice->id)->assertForbidden();
        $this->actingAs($this->user('student'))->get('/organization/invoices/'.$invoice->id)->assertForbidden();
        $this->actingAs($this->manager)->patch('/admin/organization-invoices/'.$invoice->id, ['revision' => 2, 'definition' => $this->fields()])->assertForbidden();
        $this->organization->members()->detach($this->manager->id);
        $this->actingAs($this->manager)->get('/organization/invoices/'.$invoice->id)->assertForbidden();
    }

    public function test_schedule_rollback_refuses_to_destroy_authored_contract_terms(): void
    {
        $this->draft();
        $migration = require database_path('migrations/2026_11_15_173229_add_payment_schedule_to_organization_invoices.php');
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }
}
