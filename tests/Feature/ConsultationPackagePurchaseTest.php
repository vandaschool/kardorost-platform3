<?php

namespace Tests\Feature;

use App\Models\ConsultationGrant;
use App\Models\ConsultationProduct;
use App\Models\ConsultationSlot;
use App\Models\PackagePurchase;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Models\Role;
use App\Models\User;
use App\Services\BillingService;
use App\Services\ConsultationBilling;
use App\Services\ConsultationService;
use App\Services\OrganizationBilling;
use App\Services\PackageBilling;
use App\Services\RefundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ConsultationPackagePurchaseTest extends TestCase
{
    use RefreshDatabase;

    private ConsultationProduct $product;

    private User $student;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(now()->startOfMinute());
        Http::preventStrayRequests();
        Storage::fake('receipts');
        Storage::fake('refunds');
        config(['platform.finance.transfer_destination' => '6037991234567890', 'platform.zibal.enabled' => true, 'platform.zibal.merchant' => 'test']);
        $this->product = ConsultationProduct::factory()->create();
        $this->admin = User::findOrFail($this->product->reviewed_by);
        $this->student = $this->user('student');
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('key', $role)->value('id'));

        return $user;
    }

    private function reserve(): PackagePurchase
    {
        return app(PackageBilling::class)->reserve($this->student, $this->product->fresh(), $this->product->fresh()->revision);
    }

    private function payment(PackagePurchase $purchase, array $changes = []): Payment
    {
        return Payment::create([...['package_purchase_id' => $purchase->id, 'enrollment_id' => null, 'user_id' => $purchase->student_id, 'amount' => $purchase->amount, 'method' => 'gateway', 'status' => 'pending'], ...$changes]);
    }

    private function configData(array $changes = []): array
    {
        return [...$this->product->fresh()->only('revision', 'coach_id', 'title', 'description', 'sessions', 'session_minutes', 'valid_days', 'published', 'manual_payment_enabled'), 'price' => 100000, 'reviewed' => true, ...$changes];
    }

    private function receipt(PackagePurchase $purchase): PaymentReceipt
    {
        return app(PackageBilling::class)->submitReceipt($this->student, $purchase, UploadedFile::fake()->createWithContent('package.pdf', "%PDF-1.4\nPackage purchase fixture"), 'PACKAGE12345', now()->toDateString());
    }

    private function reviewData(array $changes = []): array
    {
        return [...['action' => 'approve', 'expected_review_version' => 0, 'verified_amount' => 100000, 'statement_verified' => true], ...$changes];
    }

    public function test_admin_configuration_requires_review_positive_amount_active_coach_and_current_version(): void
    {
        $url = '/admin/consultation-packages/'.$this->product->id;
        $this->actingAs($this->admin)->patch($url, $this->configData(['reviewed' => false]))->assertSessionHasErrors('reviewed');
        $this->patch($url, $this->configData(['price' => 0]))->assertSessionHasErrors('price');
        $this->patch($url, $this->configData(['coach_id' => $this->student->id]))->assertSessionHasErrors('package');
        $this->patch($url, $this->configData(['revision' => 999]))->assertSessionHasErrors('package');
        $this->patch($url, $this->configData(['price' => 200000]))->assertSessionHasNoErrors();
        $this->assertSame(2000000, $this->product->fresh()->price);
        $this->assertSame(2, $this->product->fresh()->revision);
        $this->post('/admin/consultation-packages', $this->configData(['title' => 'پکیج تازه', 'revision' => 0]))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('consultation_products', 2);
    }

    public function test_only_admin_manages_products_and_only_students_purchase(): void
    {
        foreach (['student', 'coach', 'org_admin'] as $role) {
            $user = $this->user($role);
            $this->actingAs($user)->post('/admin/consultation-packages', $this->configData())->assertForbidden();
            $this->patch('/admin/consultation-packages/'.$this->product->id, $this->configData())->assertForbidden();
            $this->get('/admin/consultation-packages')->assertForbidden();
            if ($role !== 'student') {
                $this->post('/consultation-packages/'.$this->product->id.'/purchase', ['revision' => 1, 'accepted' => true])->assertForbidden();
            }
        }
    }

    public function test_checkout_requires_acceptance_current_revision_and_reuses_open_order(): void
    {
        $url = '/consultation-packages/'.$this->product->id.'/purchase';
        $this->actingAs($this->student)->post($url, ['revision' => 1, 'accepted' => false])->assertSessionHasErrors('accepted');
        $this->post($url, ['revision' => 99, 'accepted' => true])->assertSessionHasErrors('package');
        $this->post($url, ['revision' => 1, 'accepted' => true, 'amount' => 1, 'coach_id' => $this->student->id])->assertRedirect();
        $this->post($url, ['revision' => 1, 'accepted' => true])->assertRedirect();
        $this->assertDatabaseCount('package_purchases', 1);
        $this->assertSame(1000000, PackagePurchase::first()->amount);
        $this->assertDatabaseCount('consultation_grants', 0);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_product_edits_or_unpublication_do_not_rewrite_existing_purchase(): void
    {
        $purchase = $this->reserve();
        $definition = $purchase->definition;
        $this->actingAs($this->admin)->patch('/admin/consultation-packages/'.$this->product->id, $this->configData(['sessions' => 8, 'price' => 300000, 'coach_id' => $this->user('coach')->id, 'published' => false]))->assertSessionHasNoErrors();
        $this->assertSame($definition, $purchase->fresh()->definition);
        $this->actingAs($this->student)->get('/consultation-packages')->assertInertia(fn (Assert $page) => $page->has('products.data', 0)->has('purchases.data', 1));
        $payment = $this->payment($purchase);
        $this->assertTrue(app(PackageBilling::class)->capture($payment, 'FROZEN-BANK'));
        $grant = ConsultationGrant::firstOrFail();
        $this->assertSame(2, $grant->total_sessions);
        $this->assertSame($purchase->coach_id, $grant->coach_id);
    }

    public function test_gateway_uses_frozen_amount_dedicated_callback_and_reuses_track(): void
    {
        $purchase = $this->reserve();
        Http::fake(['gateway.zibal.ir/v1/request' => Http::response(['result' => 100, 'trackId' => 'package-track'])]);
        $this->actingAs($this->student)->post('/package-purchases/'.$purchase->id.'/pay')->assertRedirect('https://gateway.zibal.ir/start/package-track');
        $this->post('/package-purchases/'.$purchase->id.'/pay')->assertRedirect('https://gateway.zibal.ir/start/package-track');
        $this->assertDatabaseCount('payments', 1);
        Http::assertSentCount(1);
        Http::assertSent(fn ($request) => $request['amount'] === 1000000 && $request['callbackUrl'] === route('package-payments.callback') && $request['orderId'] === (string) Payment::first()->id);
        $this->assertDatabaseCount('consultation_grants', 0);
    }

    public function test_verified_callback_issues_once_and_replay_does_not_verify_or_extend_again(): void
    {
        $purchase = $this->reserve();
        $payment = $this->payment($purchase, ['track_id' => 'owned-package']);
        Http::fake(['gateway.zibal.ir/v1/verify' => Http::response(['result' => 100, 'status' => 1, 'amount' => 1000000, 'orderId' => (string) $payment->id, 'refNumber' => 'PACKAGE-BANK'])]);
        $this->get('/package-payments/callback?trackId=owned-package')->assertRedirect('/package-purchases/'.$purchase->id);
        $grant = ConsultationGrant::firstOrFail();
        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame($payment->id, $purchase->fresh()->settled_payment_id);
        $expiry = $grant->valid_until->toDateString();
        $this->travel(2)->days();
        $this->get('/package-payments/callback?trackId=owned-package')->assertRedirect();
        Http::assertSentCount(1);
        $this->assertDatabaseCount('consultation_grants', 1);
        $this->assertSame($expiry, $grant->fresh()->valid_until->toDateString());
        $this->assertDatabaseCount('payment_allocations', 0);
        $this->assertDatabaseCount('enrollments', 0);
    }

    public function test_gateway_callback_never_trusts_query_success_or_wrong_amount(): void
    {
        $purchase = $this->reserve();
        $payment = $this->payment($purchase, ['track_id' => 'bad-package']);
        Http::fake(['gateway.zibal.ir/v1/verify' => Http::response(['result' => 100, 'status' => 1, 'amount' => 1, 'orderId' => (string) $payment->id, 'refNumber' => 'WRONG-AMOUNT'])]);
        $this->get('/package-payments/callback?trackId=bad-package&success=1')->assertRedirect();
        $this->assertSame('pending', $payment->fresh()->status);
        $this->assertDatabaseCount('consultation_grants', 0);
        $this->assertNull($payment->fresh()->captured_at);
    }

    public function test_other_callback_routes_and_capture_services_reject_package_funds(): void
    {
        $payment = $this->payment($this->reserve(), ['track_id' => 'purpose-package']);
        foreach (['payments', 'consultation-payments', 'organization-payments'] as $path) {
            $this->get('/'.$path.'/callback?trackId=purpose-package')->assertNotFound();
        }
        foreach ([BillingService::class, ConsultationBilling::class, OrganizationBilling::class] as $service) {
            try {
                app($service)->capture($payment, 'WRONG-PURPOSE');
                $this->fail('Wrong financial service accepted package funds.');
            } catch (HttpException $exception) {
                $this->assertSame(422, $exception->getStatusCode());
            }
        }
        Http::assertNothingSent();
    }

    public function test_receipt_needs_independent_approval_before_creating_grant_and_is_private(): void
    {
        $purchase = $this->reserve();
        $receipt = $this->receipt($purchase);
        $this->assertDatabaseCount('consultation_grants', 0);
        $this->actingAs($this->user('student'))->get('/receipts/'.$receipt->id.'/file')->assertForbidden();
        $this->actingAs($this->student)->get('/receipts/'.$receipt->id.'/file')->assertOk();
        $url = '/admin/finance/receipts/'.$receipt->id.'/review';
        $this->actingAs($this->admin)->post($url, $this->reviewData(['statement_verified' => false]))->assertSessionHasErrors('statement_verified');
        $this->post($url, $this->reviewData(['verified_amount' => 1]))->assertSessionHasErrors('package');
        $this->post($url, $this->reviewData())->assertSessionHasNoErrors();
        $this->post($url, $this->reviewData())->assertSessionHasNoErrors();
        $this->assertDatabaseCount('consultation_grants', 1);
        $this->assertSame('paid', $purchase->fresh()->status);
        $this->assertDatabaseCount('receipt_claims', 1);
    }

    public function test_rejected_receipt_releases_claim_but_never_creates_credit(): void
    {
        $purchase = $this->reserve();
        $receipt = $this->receipt($purchase);
        app(PackageBilling::class)->review($this->admin, $receipt, $this->reviewData(['action' => 'reject', 'review_note' => 'واریز در گردش حساب نیست']));
        $this->assertDatabaseCount('receipt_claims', 0);
        $this->assertDatabaseCount('consultation_grants', 0);
        $this->assertSame('failed', $receipt->payment->fresh()->status);
        $this->assertSame('pending_payment', $purchase->fresh()->status);
    }

    public function test_pending_gateway_or_receipt_blocks_parallel_manual_deposit(): void
    {
        $purchase = $this->reserve();
        app(PackageBilling::class)->attempt($this->student, $purchase);
        $this->actingAs($this->student)->post('/package-purchases/'.$purchase->id.'/receipts', ['transfer_reference' => '12345678', 'transfer_date' => now()->toDateString(), 'file' => UploadedFile::fake()->createWithContent('r.pdf', '%PDF-1.4 concurrent')])->assertSessionHasErrors('package');
        $this->assertDatabaseCount('payment_receipts', 0);
    }

    public function test_late_funds_are_preserved_for_explicit_admin_fulfillment(): void
    {
        $purchase = $this->reserve();
        $payment = $this->payment($purchase);
        $this->travel(16)->minutes();
        $this->assertFalse(app(PackageBilling::class)->capture($payment, 'LATE-PACKAGE'));
        $this->assertSame('review_required', $payment->fresh()->status);
        $this->assertNotNull($payment->fresh()->captured_at);
        $this->assertDatabaseCount('consultation_grants', 0);
        $this->actingAs($this->admin)->post('/admin/finance/payments/'.$payment->id.'/allocate')->assertSessionHasNoErrors();
        $this->assertSame('paid', $purchase->fresh()->status);
        $this->assertDatabaseCount('consultation_grants', 1);
    }

    public function test_unavailable_coach_preserves_money_and_restoration_uses_original_terms(): void
    {
        $purchase = $this->reserve();
        $payment = $this->payment($purchase);
        $coach = $this->product->coach;
        $coach->forceFill(['is_active' => false])->save();
        $this->assertFalse(app(PackageBilling::class)->capture($payment, 'COACH-UNAVAILABLE'));
        $this->assertSame('package_account_unavailable', $payment->fresh()->review_reason);
        $this->assertDatabaseCount('consultation_grants', 0);
        $coach->forceFill(['is_active' => true])->save();
        $this->travel(3)->days();
        app(PackageBilling::class)->resolve($this->admin, $payment);
        $this->assertSame(now()->addDays(90)->toDateString(), ConsultationGrant::first()->valid_until->toDateString());
    }

    public function test_duplicate_captured_funds_are_refundable_without_an_extra_grant(): void
    {
        $purchase = $this->reserve();
        $one = $this->payment($purchase);
        $two = $this->payment($purchase);
        $this->assertTrue(app(PackageBilling::class)->capture($one, 'PACKAGE-ONE'));
        $this->assertFalse(app(PackageBilling::class)->capture($two, 'PACKAGE-TWO'));
        $this->assertSame('package_duplicate', $two->fresh()->review_reason);
        $this->assertDatabaseCount('consultation_grants', 1);
        $this->assertFalse(app(RefundService::class)->eligible($one->fresh()));
        $this->assertTrue(app(RefundService::class)->eligible($two->fresh()));
        $this->actingAs($this->student)->get('/refunds?payment='.$two->id)->assertOk();
    }

    public function test_reference_conflict_does_not_claim_unverified_funds_or_issue_credit(): void
    {
        $purchase = $this->reserve();
        $one = $this->payment($purchase);
        app(PackageBilling::class)->capture($one, 'SHARED-PACKAGE-REF');
        $next = $this->reserve();
        $two = $this->payment($next);
        $this->assertFalse(app(PackageBilling::class)->capture($two, 'SHARED-PACKAGE-REF'));
        $this->assertNull($two->fresh()->captured_at);
        $this->assertSame('reference_conflict', $two->fresh()->review_reason);
        $this->assertFalse(app(RefundService::class)->eligible($two->fresh()));
        $this->assertDatabaseCount('consultation_grants', 1);
    }

    public function test_pending_refund_prevents_admin_fulfillment(): void
    {
        $purchase = $this->reserve();
        $payment = $this->payment($purchase);
        $this->travel(16)->minutes();
        app(PackageBilling::class)->capture($payment, 'REFUND-PACKAGE');
        app(RefundService::class)->request($this->student, $payment->fresh(), 'IR062960000000100324200001', 'بازپرداخت وجه سفارش', 0);
        $this->actingAs($this->admin)->post('/admin/finance/payments/'.$payment->id.'/allocate')->assertSessionHasErrors('package');
        $this->assertDatabaseCount('consultation_grants', 0);
    }

    public function test_completed_refund_closes_unfulfilled_order_and_permits_new_purchase(): void
    {
        $purchase = $this->reserve();
        $payment = $this->payment($purchase);
        $this->travel(16)->minutes();
        app(PackageBilling::class)->capture($payment, 'REFUND-COMPLETE');
        $service = app(RefundService::class);
        $refund = $service->request($this->student, $payment->fresh(), 'IR062960000000100324200001', 'بازپرداخت وجه سفارش', 0);
        $service->review($this->admin, $refund, 1, 'approve', 'گردش حساب بررسی شد');
        $service->complete($this->admin, $refund->fresh(), ['version' => 2, 'amount' => 100000, 'destination' => 'IR062960000000100324200001', 'transfer_reference' => 'REFUND-PACKAGE-123', 'transfer_date' => now()->toDateString(), 'statement_verified' => true, 'beneficiary_verified' => true], UploadedFile::fake()->createWithContent('refund.pdf', "%PDF-1.4\nRefund package proof"));
        $this->assertSame('refunded', $payment->fresh()->status);
        $this->assertSame('cancelled', $purchase->fresh()->status);
        $this->assertFalse(app(PackageBilling::class)->capture($payment->fresh(), 'REFUND-COMPLETE'));
        $this->assertNotSame($purchase->id, $this->reserve()->id);
        $this->assertDatabaseCount('consultation_grants', 0);
    }

    public function test_owner_scopes_order_payment_receipt_and_finance_history(): void
    {
        $purchase = $this->reserve();
        $this->actingAs($this->user('student'))->get('/package-purchases/'.$purchase->id)->assertForbidden();
        $this->post('/package-purchases/'.$purchase->id.'/pay')->assertForbidden();
        $this->post('/package-purchases/'.$purchase->id.'/receipts')->assertForbidden();
        $this->get('/consultation-packages')->assertInertia(fn (Assert $page) => $page->has('purchases.data', 0)->missing('products.data.0.reviewed_by'));
        $this->actingAs($this->student)->get('/package-purchases/'.$purchase->id)->assertInertia(fn (Assert $page) => $page->missing('purchase.student')->where('purchase.amount', 1000000));
    }

    public function test_purchased_grant_uses_existing_booking_ledger_and_cannot_be_manually_rewritten(): void
    {
        $purchase = $this->reserve();
        app(PackageBilling::class)->capture($this->payment($purchase), 'USABLE-PACKAGE');
        $grant = ConsultationGrant::first();
        $slot = ConsultationSlot::factory()->create(['coach_id' => $purchase->coach_id, 'price' => 1000000, 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour()]);
        $booking = app(ConsultationService::class)->book($this->student, $slot, 'استفاده پکیج خریداری‌شده', grantId: $grant->id);
        $this->assertSame(0, $booking->amount);
        $this->assertSame(1, $grant->fresh()->remaining());
        $this->actingAs($this->admin)->patch('/admin/consultation-grants/'.$grant->id, ['revision' => 1, 'total_sessions' => 100, 'valid_until' => now()->addYear()->toDateString(), 'active' => true])->assertSessionHasErrors('package');
        $this->actingAs($this->student)->get('/consultation-grants')->assertInertia(fn (Assert $page) => $page->where('grants.data.0.source_kind', 'package')->missing('grants.data.0.source_reference'));
        $this->get('/finance')->assertInertia(fn (Assert $page) => $page->where('payments.data.0.package_purchase_id', $purchase->id));
        $this->actingAs($this->admin)->get('/admin/finance?tab=payments')->assertInertia(fn (Assert $page) => $page->where('stats.allocated', 1000000));
    }

    public function test_purchase_migration_prevents_destructive_rollback(): void
    {
        $this->reserve();
        $migration = require database_path('migrations/2026_11_14_194511_create_consultation_package_purchases.php');
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }
}
