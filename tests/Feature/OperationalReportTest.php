<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Consultation;
use App\Models\Enrollment;
use App\Models\OrganizationInvoice;
use App\Models\PackagePurchase;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Role;
use App\Models\User;
use App\Services\OperationalReports;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OperationalReportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse('2026-03-10 12:00:00', 'Asia/Tehran'));
        Http::preventStrayRequests();
        $this->admin = User::factory()->create(['created_at' => '2025-01-01 00:00:00']);
        $this->admin->roles()->attach(Role::where('key', 'admin')->value('id'));
    }

    private function url(string $dataset = 'captures', bool $export = false): string
    {
        return '/admin/reports'.($export ? '/export' : '').'?from=2026-03-01&to=2026-03-03&dataset='.$dataset;
    }

    private function captured(int $amount = 100000, string $time = '2026-03-01 12:00:00', array $extra = []): Payment
    {
        return Payment::factory()->create([...['amount' => $amount, 'status' => 'paid', 'captured_at' => $time, 'paid_at' => $time, 'reference' => fake()->unique()->uuid()], ...$extra]);
    }

    public function test_only_current_active_admin_can_view_and_export_reports(): void
    {
        $this->get($this->url())->assertRedirect('/login');
        foreach (['student', 'coach', 'org_admin'] as $role) {
            $user = User::factory()->create();
            $user->roles()->attach(Role::where('key', $role)->value('id'));
            $this->actingAs($user)->get($this->url())->assertForbidden();
            $this->get($this->url('captures', true))->assertForbidden();
        }
        $this->actingAs($this->admin)->get($this->url())->assertOk();
        $this->admin->roles()->detach();
        $this->get($this->url('captures', true))->assertForbidden();
    }

    public function test_capture_time_is_used_instead_of_gateway_creation_or_current_status(): void
    {
        $this->captured(100000, '2026-03-01 00:00:00', ['created_at' => '2026-02-28 12:00:00']);
        $this->captured(200000, '2026-03-02 12:00:00', ['status' => 'refunded']);
        $this->captured(300000, '2026-03-03 23:59:59', ['status' => 'review_required']);
        $this->captured(900000, '2026-03-04 00:00:00');
        $this->captured(900000, '2026-02-28 23:59:59');
        Payment::factory()->create(['created_at' => '2026-03-02 12:00:00', 'status' => 'pending', 'captured_at' => null, 'amount' => 900000]);
        $this->actingAs($this->admin)->get($this->url())->assertInertia(fn (Assert $page) => $page->where('summary.totals.captured_amount', 600000)->where('summary.totals.captured_count', 3)->where('records.total', 3)->has('summary.daily', 3)->where('summary.daily.0.date', '2026-03-01')->where('summary.daily.2.captured_amount', 300000));
    }

    public function test_only_completed_refund_uses_its_completion_time_and_can_make_cash_flow_negative(): void
    {
        $old = $this->captured(500000, '2026-02-20 12:00:00', ['status' => 'refunded']);
        Refund::factory()->create(['payment_id' => $old->id, 'amount' => 500000, 'status' => 'completed', 'completed_at' => '2026-03-02 10:00:00', 'transfer_reference' => 'REPORT-REFUND']);
        Refund::factory()->create(['status' => 'approved', 'completed_at' => null, 'amount' => 900000]);
        Refund::factory()->create(['status' => 'completed', 'completed_at' => '2026-03-04 00:00:00', 'amount' => 900000]);
        $this->actingAs($this->admin)->get($this->url('refunds'))->assertInertia(fn (Assert $page) => $page->where('summary.totals.captured_amount', 0)->where('summary.totals.refunded_amount', 500000)->where('summary.totals.refunded_count', 1)->where('summary.totals.net_cash_flow', -500000)->where('records.total', 1)->where('records.data.0.reference', 'REPORT-REFUND'));
    }

    public function test_request_or_approved_refund_never_reduces_gross_capture(): void
    {
        $payment = $this->captured();
        Refund::factory()->create(['payment_id' => $payment->id, 'amount' => $payment->amount, 'status' => 'approved', 'completed_at' => null]);
        $this->actingAs($this->admin)->get($this->url())->assertInertia(fn (Assert $page) => $page->where('summary.totals.captured_amount', 100000)->where('summary.totals.refunded_amount', 0)->where('summary.totals.net_cash_flow', 100000));
    }

    public function test_all_four_payment_purposes_are_reported_without_counting_them_twice(): void
    {
        $this->captured(100000);
        $user = User::factory()->create();
        $common = ['user_id' => $user->id, 'amount' => 200000, 'status' => 'paid', 'method' => 'gateway', 'captured_at' => '2026-03-01 12:00:00'];
        Payment::create([...$common, 'consultation_id' => Consultation::factory()->create()->id]);
        Payment::create([...$common, 'amount' => 300000, 'organization_invoice_id' => OrganizationInvoice::factory()->create()->id]);
        Payment::create([...$common, 'amount' => 400000, 'package_purchase_id' => PackagePurchase::factory()->create()->id]);
        $this->actingAs($this->admin)->get($this->url())->assertInertia(fn (Assert $page) => $page->where('summary.totals.captured_amount', 1000000)->where('summary.totals.captured_count', 4)->where('summary.purposes.0.captured_amount', 100000)->where('summary.purposes.1.captured_amount', 200000)->where('summary.purposes.2.captured_amount', 300000)->where('summary.purposes.3.captured_amount', 400000)->where('records.total', 4));
    }

    public function test_refunds_are_attributed_to_their_original_payment_purpose(): void
    {
        $invoice = OrganizationInvoice::factory()->create();
        $payment = Payment::create(['user_id' => $this->admin->id, 'organization_invoice_id' => $invoice->id, 'amount' => 500000, 'status' => 'refunded', 'method' => 'manual', 'captured_at' => '2026-02-28 12:00:00']);
        Refund::factory()->create(['payment_id' => $payment->id, 'amount' => 500000, 'status' => 'completed', 'completed_at' => '2026-03-01 12:00:00']);
        $this->actingAs($this->admin)->get($this->url('refunds'))->assertInertia(fn (Assert $page) => $page->where('summary.purposes.2.refunded_amount', 500000)->where('records.data.0.purpose', 'قرارداد سازمان')->where('records.data.0.source_id', $invoice->id));
    }

    public function test_new_enrollments_and_accounts_use_creation_time_with_current_enrollment_status(): void
    {
        $new = User::factory()->create(['created_at' => '2026-03-02 12:00:00']);
        Enrollment::factory()->create(['user_id' => $new->id, 'created_at' => '2026-03-02 12:00:00', 'status' => 'active']);
        Enrollment::factory()->create(['created_at' => '2026-03-03 12:00:00', 'status' => 'cancelled']);
        Enrollment::factory()->create(['created_at' => '2026-02-28 12:00:00', 'status' => 'active']);
        $this->actingAs($this->admin)->get($this->url('enrollments'))->assertInertia(fn (Assert $page) => $page->where('summary.totals.new_accounts', 1)->where('summary.totals.new_enrollments', 2)->where('records.total', 2)->has('summary.enrollment_statuses', 2));
    }

    public function test_default_period_is_current_tehran_month_and_single_bound_is_supported(): void
    {
        $service = app(OperationalReports::class);
        $this->assertSame(['from' => '2026-03-01', 'to' => '2026-03-10', 'dataset' => 'captures'], $service->filters([]));
        $this->assertSame('2026-03-03', $service->filters(['to' => '2026-03-03'])['to']);
        $this->assertSame('2026-03-02', $service->filters(['from' => '2026-03-02'])['from']);
    }

    public function test_invalid_reversed_or_oversized_periods_and_unknown_datasets_are_rejected(): void
    {
        $this->actingAs($this->admin);
        foreach ([['from' => '2026-02-30', 'to' => '2026-03-03'], ['from' => '2026-03-03', 'to' => '2026-03-01'], ['from' => '2024-01-01', 'to' => '2026-03-01'], ['dataset' => 'passwords'], ['from' => '']] as $input) {
            $this->get('/admin/reports?'.http_build_query($input))->assertSessionHasErrors();
            $this->get('/admin/reports/export?'.http_build_query($input))->assertSessionHasErrors();
        }
        $this->assertSame(0, AuditEvent::where('action', 'like', 'operational_report.%')->count());
    }

    public function test_empty_period_returns_zero_metrics_and_every_day_without_fabricated_rows(): void
    {
        $this->actingAs($this->admin)->get($this->url())->assertInertia(fn (Assert $page) => $page->where('summary.totals.captured_amount', 0)->where('summary.totals.refunded_amount', 0)->where('records.total', 0)->has('summary.daily', 3)->where('summary.daily.1.captured_count', 0));
    }

    public function test_records_are_paginated_but_csv_contains_all_filtered_rows_only(): void
    {
        Payment::factory()->count(25)->create(['captured_at' => '2026-03-02 12:00:00', 'status' => 'paid', 'amount' => 100000]);
        $excluded = $this->captured(100000, '2026-03-04 00:00:00', ['reference' => 'OUTSIDE-REPORT']);
        $this->actingAs($this->admin)->get($this->url())->assertInertia(fn (Assert $page) => $page->where('records.total', 25)->has('records.data', 20)->where('summary.totals.captured_amount', 2500000));
        $this->get($this->url().'&page=2')->assertInertia(fn (Assert $page) => $page->has('records.data', 5));
        $response = $this->get($this->url('captures', true))->assertOk();
        $csv = $response->streamedContent();
        $this->assertSame(26, count(array_filter(explode("\n", trim($csv)))));
        $this->assertStringNotContainsString($excluded->reference, $csv);
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $this->assertSame('nosniff', $response->headers->get('X-Content-Type-Options'));
    }

    public function test_csv_preserves_long_references_and_neutralizes_formula_injection(): void
    {
        $this->captured(100000, '2026-03-01 12:00:00', ['reference' => '=HYPERLINK("https://invalid.example")']);
        $this->captured(100000, '2026-03-01 12:00:00', ['reference' => '90000000000000000001']);
        $csv = $this->actingAs($this->admin)->get($this->url('captures', true))->assertOk()->streamedContent();
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringContainsString('90000000000000000001', $csv);
    }

    public function test_refund_and_enrollment_exports_use_same_scope_and_exclude_private_fields(): void
    {
        $payment = $this->captured();
        $refund = Refund::factory()->create(['payment_id' => $payment->id, 'amount' => $payment->amount, 'status' => 'completed', 'completed_at' => '2026-03-03 12:00:00', 'transfer_reference' => 'REPORT-TRANSFER', 'reason' => 'Private refund reason', 'destination' => 'IR820540102680020817909002']);
        $csv = $this->actingAs($this->admin)->get($this->url('refunds', true))->assertOk()->streamedContent();
        $this->assertStringContainsString('REPORT-TRANSFER', $csv);
        $this->assertStringNotContainsString('Private refund reason', $csv);
        $this->assertStringNotContainsString('IR820540102680020817909002', $csv);
        $this->get($this->url('refunds'))->assertInertia(fn (Assert $page) => $page->missing('records.data.0.destination')->missing('records.data.0.reason')->missing('records.data.0.file_path'));
        Enrollment::factory()->create(['created_at' => '2026-03-02 12:00:00', 'status' => 'active']);
        $csv = $this->get($this->url('enrollments', true))->assertOk()->streamedContent();
        $this->assertStringContainsString('ثبت‌نام شخصی', $csv);
        $this->assertStringNotContainsString($refund->destination, $csv);
    }

    public function test_report_view_and_export_are_audited_with_filters_without_provider_calls(): void
    {
        $this->actingAs($this->admin)->get($this->url())->assertOk();
        $this->get($this->url('captures', true))->assertOk()->streamedContent();
        $this->assertSame(2, AuditEvent::where('subject_type', 'operational_report')->count());
        $this->assertSame(['from' => '2026-03-01', 'to' => '2026-03-03', 'dataset' => 'captures'], AuditEvent::where('action', 'operational_report.exported')->sole()->metadata);
        Http::assertNothingSent();
    }
}
