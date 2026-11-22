<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\Consultation;
use App\Models\OrganizationInvoice;
use App\Models\PackagePurchase;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\RefundEvent;
use App\Models\Role;
use App\Models\User;
use App\Services\RefundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class RefundCurrentAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    private const IBAN = 'IR820540102680020817909002';

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Notification::fake();
        Storage::fake('refunds');
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('key', $role)->value('id'));

        return $user;
    }

    private function payment(string $kind): Payment
    {
        $base = ['status' => 'review_required', 'reference' => fake()->unique()->uuid(), 'captured_at' => now(), 'paid_at' => now(), 'amount' => 1000000];
        if ($kind === 'course') {
            return Payment::factory()->create($base);
        }
        if ($kind === 'organization') {
            $invoice = OrganizationInvoice::factory()->create();
            $owner = $this->user('org_admin');
            $invoice->organization->members()->attach($owner->id, ['kind' => 'manager']);
            $parent = ['organization_invoice_id' => $invoice->id, 'user_id' => $owner->id];
        } elseif ($kind === 'package') {
            $purchase = PackagePurchase::factory()->create();
            $parent = ['package_purchase_id' => $purchase->id, 'user_id' => $purchase->student_id];
        } else {
            $booking = Consultation::factory()->create(['amount' => 1000000]);
            $parent = ['consultation_id' => $booking->id, 'user_id' => $booking->student_id];
        }

        return Payment::factory()->create([...$base, 'installment_id' => null, 'enrollment_id' => null, ...$parent]);
    }

    private function request(Payment $payment): Refund
    {
        $service = app(RefundService::class);

        return $payment->organization_invoice_id
            ? $service->requestOrganization($payment->user, $payment, self::IBAN, 'Software-only organization beneficiary', 'Software-only unallocated receipt', 0)
            : $service->request($payment->user, $payment, self::IBAN, 'Software-only unallocated receipt', 0);
    }

    private function proof(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('software-only65.pdf', "%PDF-1.4\n% SOFTWARE-ONLY-NO-REAL-BANK-TRANSFER\n%%EOF\n");
    }

    private function completion(Refund $refund): array
    {
        return ['version' => $refund->fresh()->version, 'amount' => 100000, 'destination' => self::IBAN, 'transfer_reference' => 'SOFTWAREONLY65', 'transfer_date' => now()->toDateString(), 'statement_verified' => true, 'beneficiary_verified' => true];
    }

    public static function kinds(): array
    {
        return array_map(fn ($kind) => [$kind], ['course', 'consultation', 'package', 'organization']);
    }

    public static function personalKinds(): array
    {
        return array_map(fn ($kind) => [$kind], ['course', 'consultation', 'package']);
    }

    public static function adminCases(): array
    {
        $cases = [];
        foreach (self::kinds() as [$kind]) {
            foreach (['approve', 'reject', 'complete'] as $action) {
                $cases[$kind.'_'.$action] = [$kind, $action];
            }
        }

        return $cases;
    }

    #[DataProvider('adminCases')]
    public function test_disabled_admin_cannot_review_or_complete_with_stale_activity_for_any_parent(string $kind, string $action): void
    {
        $payment = $this->payment($kind);
        $refund = $this->request($payment);
        $admin = $this->user('admin');
        $service = app(RefundService::class);
        if ($action === 'complete') {
            $service->review($admin, $refund, 1, 'approve', 'Software-only approved request');
        }
        $snapshot = fn () => hash('sha256', json_encode([$refund->fresh()->getAttributes(), $payment->fresh()->getAttributes(), RefundEvent::count(), AuditEvent::count()], JSON_THROW_ON_ERROR));
        $before = $snapshot();
        User::whereKey($admin->id)->update(['is_active' => false]);
        $this->assertTrue($admin->is_active);
        try {
            if ($action === 'complete') {
                $service->complete($admin, $refund, $this->completion($refund), $this->proof());
            } else {
                $service->review($admin, $refund, 1, $action, 'Software-only access decision');
            }
            $this->fail('The disabled admin cannot change this refund.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertSame($before, $snapshot());
        $this->assertSame([], Storage::disk('refunds')->allFiles());
        Http::assertNothingSent();
    }

    #[DataProvider('personalKinds')]
    public function test_disabled_personal_owner_cannot_request_refund_using_stale_activity(string $kind): void
    {
        $payment = $this->payment($kind);
        $owner = $payment->user;
        User::whereKey($owner->id)->update(['is_active' => false]);
        try {
            app(RefundService::class)->request($owner, $payment, self::IBAN, 'Software-only request', 0);
            $this->fail('The disabled owner cannot create a refund request.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertDatabaseCount('refunds', 0);
        $this->assertDatabaseCount('refund_events', 0);
        $this->assertDatabaseCount('audit_events', 0);
        $this->assertSame('review_required', $payment->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_disabled_organization_manager_cannot_request_using_stale_activity(): void
    {
        $payment = $this->payment('organization');
        $manager = $payment->user;
        User::whereKey($manager->id)->update(['is_active' => false]);
        try {
            app(RefundService::class)->requestOrganization($manager, $payment, self::IBAN, 'Software-only beneficiary', 'Software-only request', 0);
            $this->fail('The disabled organization manager cannot request a refund.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertDatabaseCount('refunds', 0);
        $this->assertDatabaseCount('refund_events', 0);
        $this->assertDatabaseCount('audit_events', 0);
        Http::assertNothingSent();
    }

    #[DataProvider('personalKinds')]
    public function test_active_personal_owner_retains_money_request_after_role_change(string $kind): void
    {
        $payment = $this->payment($kind);
        $payment->user->roles()->sync([Role::where('key', 'coach')->value('id')]);
        $refund = $this->request($payment);
        $this->assertSame(1000000, $refund->amount);
        $this->assertSame('requested', $refund->status);
        Http::assertNothingSent();
    }

    #[DataProvider('kinds')]
    public function test_current_participants_can_finish_the_existing_full_manual_refund_workflow(string $kind): void
    {
        $payment = $this->payment($kind);
        $refund = $this->request($payment);
        $admin = $this->user('admin');
        $service = app(RefundService::class);
        $service->review($admin, $refund, 1, 'approve', 'Software-only approved request');
        $service->complete($admin, $refund, $this->completion($refund), $this->proof());
        $this->assertSame('completed', $refund->fresh()->status);
        $this->assertSame(1000000, $refund->fresh()->amount);
        $this->assertSame('refunded', $payment->fresh()->status);
        Storage::disk('refunds')->assertExists($refund->fresh()->file_path);
        Http::assertNothingSent();
    }
}
