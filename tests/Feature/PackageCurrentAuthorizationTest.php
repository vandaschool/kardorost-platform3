<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\ConsultationGrant;
use App\Models\ConsultationProduct;
use App\Models\PackagePurchase;
use App\Models\Payment;
use App\Models\PaymentReceipt;
use App\Models\User;
use App\Services\PackageBilling;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class PackageCurrentAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Notification::fake();
        Storage::fake('receipts');
        config(['platform.finance.transfer_destination' => '6037991234567890']);
    }

    private function data(ConsultationProduct $product): array
    {
        $product = $product->fresh();

        return [...$product->only('revision', 'coach_id', 'title', 'description', 'sessions', 'session_minutes', 'valid_days', 'published', 'manual_payment_enabled'), 'price' => 100000];
    }

    private function receipt(PackagePurchase $purchase): PaymentReceipt
    {
        return app(PackageBilling::class)->submitReceipt($purchase->student, $purchase, UploadedFile::fake()->createWithContent('software-only64.pdf', "%PDF-1.4\n% SOFTWARE-ONLY-CURRENT-AUTHORIZATION\n%%EOF\n"), 'SOFTWAREONLY64', now()->toDateString());
    }

    public static function adminOperations(): array
    {
        return array_map(fn ($action) => [$action], ['create_product', 'edit_product', 'allocate', 'allocate_paid', 'approve', 'reject']);
    }

    #[DataProvider('adminOperations')]
    public function test_disabled_admin_cannot_configure_allocate_or_review_using_stale_activity(string $action): void
    {
        $product = ConsultationProduct::factory()->create();
        $admin = User::findOrFail($product->reviewed_by);
        $purchase = PackagePurchase::factory()->create(['consultation_product_id' => $product->id]);
        $service = app(PackageBilling::class);
        $receipt = null;
        $payment = null;
        if (in_array($action, ['approve', 'reject'], true)) {
            $receipt = $this->receipt($purchase);
            $payment = $receipt->payment;
        } elseif (in_array($action, ['allocate', 'allocate_paid'], true)) {
            $payment = Payment::factory()->create(['installment_id' => null, 'enrollment_id' => null, 'user_id' => $purchase->student_id, 'package_purchase_id' => $purchase->id, 'amount' => $purchase->amount, 'method' => 'gateway', 'status' => 'review_required', 'captured_at' => now(), 'paid_at' => now(), 'reference' => 'SOFTWARE-CAPTURED64']);
            if ($action === 'allocate_paid') {
                $this->assertTrue($service->resolve($admin, $payment));
            }
        }
        $snapshot = fn () => hash('sha256', json_encode([$product->fresh()->getAttributes(), $purchase->fresh()->getAttributes(), $payment?->fresh()->getAttributes(), $receipt?->fresh()->getAttributes(), ConsultationGrant::count(), AuditEvent::count()], JSON_THROW_ON_ERROR));
        $before = $snapshot();
        User::whereKey($admin->id)->update(['is_active' => false]);
        $this->assertTrue($admin->is_active);
        try {
            match ($action) {
                'create_product' => $service->configure($admin, $this->data($product)),
                'edit_product' => $service->configure($admin, $this->data($product), $product),
                'allocate', 'allocate_paid' => $service->resolve($admin, $payment),
                'approve', 'reject' => $service->review($admin, $receipt, ['action' => $action, 'expected_review_version' => 0, 'verified_amount' => 100000, 'statement_verified' => true, 'review_note' => 'Software-only current access check']),
            };
            $this->fail('The disabled admin must not perform or acknowledge this package operation.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertSame($before, $snapshot());
        $this->assertDatabaseCount('consultation_products', 1);
        if ($receipt) {
            Storage::disk('receipts')->assertExists($receipt->file_path);
            $this->assertDatabaseCount('receipt_claims', 1);
        }
        Http::assertNothingSent();
    }

    public function test_disabled_student_cannot_reuse_existing_reservation_using_stale_activity(): void
    {
        $purchase = PackagePurchase::factory()->create();
        $student = $purchase->student;
        $product = ConsultationProduct::findOrFail($purchase->consultation_product_id);
        $before = $purchase->fresh()->getAttributes();
        User::whereKey($student->id)->update(['is_active' => false]);
        $this->assertTrue($student->is_active);
        try {
            app(PackageBilling::class)->reserve($student, $product, $product->revision);
            $this->fail('A currently disabled buyer cannot reuse the pending order.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertSame($before, $purchase->fresh()->getAttributes());
        $this->assertDatabaseCount('package_purchases', 1);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('audit_events', 0);
        Http::assertNothingSent();
    }

    public function test_existing_order_reuse_checks_original_coach_and_preserves_frozen_terms(): void
    {
        $purchase = PackagePurchase::factory()->create();
        $product = ConsultationProduct::findOrFail($purchase->consultation_product_id);
        $before = $purchase->fresh()->getAttributes();
        $replacement = User::factory()->create(['is_active' => false]);
        $product->update(['coach_id' => $replacement->id, 'price' => 2000000, 'revision' => $product->revision + 1]);
        $same = app(PackageBilling::class)->reserve($purchase->student, $product->fresh(), $product->revision);
        $this->assertSame($purchase->id, $same->id);
        $this->assertSame($before, $same->getAttributes());
        $this->assertDatabaseCount('package_purchases', 1);
        $this->assertDatabaseCount('payments', 0);
        Http::assertNothingSent();
    }

    public function test_current_admin_can_approve_verified_receipt_and_issue_exactly_one_grant(): void
    {
        $purchase = PackagePurchase::factory()->create();
        $admin = User::findOrFail($purchase->reviewed_by);
        $receipt = $this->receipt($purchase);
        $service = app(PackageBilling::class);
        $data = ['action' => 'approve', 'expected_review_version' => 0, 'verified_amount' => 100000, 'statement_verified' => true];
        $this->assertTrue($service->review($admin, $receipt, $data));
        $this->assertTrue($service->review($admin, $receipt, $data));
        $this->assertSame('paid', $purchase->fresh()->status);
        $this->assertSame(1000000, $receipt->payment->fresh()->amount);
        $this->assertDatabaseCount('consultation_grants', 1);
        Http::assertNothingSent();
    }
}
