<?php

namespace Tests\Feature;

use App\Models\OrganizationInvoice;
use App\Models\Role;
use App\Models\User;
use App\Services\OrganizationBilling;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class OrganizationBillingCurrentAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Storage::fake('receipts');
        config(['platform.finance.transfer_destination' => '6037991234567890', 'platform.finance.transfer_holder' => 'Software-only account']);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('key', $role)->value('id'));

        return $user;
    }

    private function definition(): array
    {
        return ['title' => 'Software-only contract', 'contract_reference' => 'SOFTWARE-ONLY66', 'due_date' => now()->addWeek()->toDateString(), 'organization_license_id' => null, 'lines' => [['title' => 'Software-only service', 'quantity' => 1, 'unit_price' => 100000]]];
    }

    private function proof(): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('software-only66.pdf', "%PDF-1.4\n% SOFTWARE-ONLY-NO-REAL-FUNDS66\n%%EOF\n");
    }

    private function reviewData(string $action = 'approve'): array
    {
        return ['action' => $action, 'expected_review_version' => 0, 'verified_amount' => 100000, 'statement_verified' => true, 'review_note' => 'Software-only independent review'];
    }

    private function fixture(string $action): array
    {
        $admin = $this->user('admin');
        $manager = $this->user('org_admin');
        $invoice = OrganizationInvoice::factory()->create();
        $invoice->organization->members()->attach($manager->id, ['kind' => 'manager']);
        $service = app(OrganizationBilling::class);
        if (in_array($action, ['attempt', 'reuse', 'receipt', 'approve', 'reject', 'capture'], true)) {
            $service->issue($admin, $invoice, 1);
            $invoice = $invoice->fresh();
        }
        $payment = in_array($action, ['reuse', 'capture'], true) ? $service->attempt($manager, $invoice) : null;
        $receipt = in_array($action, ['approve', 'reject'], true) ? $service->receipt($manager, $invoice, $this->proof(), 'SOFTWAREONLY66', now()->toDateString()) : null;

        return [$admin, $manager, $invoice, $payment, $receipt];
    }

    private function snapshot(): string
    {
        $state = [];
        foreach (['organization_invoices', 'payments', 'payment_receipts', 'receipt_claims', 'organization_payment_allocations', 'audit_events', 'notifications'] as $table) {
            $state[$table] = DB::table($table)->orderBy('id')->get()->toJson();
        }
        foreach (Storage::disk('receipts')->allFiles() as $file) {
            $state['files'][$file] = hash_file('sha256', Storage::disk('receipts')->path($file));
        }

        return hash('sha256', json_encode($state, JSON_THROW_ON_ERROR));
    }

    public static function disabledCases(): array
    {
        return array_map(fn ($action) => [$action], ['create', 'edit', 'issue', 'void', 'approve', 'reject', 'attempt', 'reuse', 'receipt']);
    }

    #[DataProvider('disabledCases')]
    public function test_disabled_actor_cannot_write_using_stale_activity(string $action): void
    {
        [$admin, $manager, $invoice, $payment, $receipt] = $this->fixture($action);
        $actor = in_array($action, ['attempt', 'reuse', 'receipt'], true) ? $manager : $admin;
        User::whereKey($actor->id)->update(['is_active' => false]);
        $before = $this->snapshot();
        $this->assertTrue($actor->is_active);
        $service = app(OrganizationBilling::class);
        try {
            match ($action) {
                'create' => $service->draft($actor, $invoice->organization, $this->definition()),
                'edit' => $service->draft($actor, $invoice->organization, $this->definition(), $invoice, 1),
                'issue' => $service->issue($actor, $invoice, 1),
                'void' => $service->void($actor, $invoice, 1, 'Software-only cancellation'),
                'approve', 'reject' => $service->review($actor, $receipt, $this->reviewData($action)),
                'attempt', 'reuse' => $service->attempt($actor, $invoice),
                'receipt' => $service->receipt($actor, $invoice, $this->proof(), 'SOFTWAREONLY66', now()->toDateString()),
            };
            $this->fail('A disabled actor cannot change organization billing.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame($before, $this->snapshot());
        Http::assertNothingSent();
    }

    public function test_current_admin_can_create_edit_issue_and_void_without_changing_contract_rules(): void
    {
        [$admin, $manager, $source] = $this->fixture('create');
        $service = app(OrganizationBilling::class);
        $invoice = $service->draft($admin, $source->organization, $this->definition());
        $service->draft($admin, $source->organization, [...$this->definition(), 'title' => 'Software-only revised contract'], $invoice, 1);
        $service->issue($admin, $invoice->fresh(), 2);
        $this->assertSame(1000000, $invoice->fresh()->amount);
        $this->assertSame('issued', $invoice->fresh()->status);
        $service->void($admin, $invoice->fresh(), 3, 'Software-only cancellation');
        $this->assertSame('void', $invoice->fresh()->status);
        $this->assertSame(4, $invoice->fresh()->revision);
        Http::assertNothingSent();
    }

    public function test_current_participants_can_submit_and_review_existing_full_manual_receipt(): void
    {
        [$admin, $manager, $invoice, $payment, $receipt] = $this->fixture('approve');
        $this->assertTrue(app(OrganizationBilling::class)->review($admin, $receipt, $this->reviewData()));
        $this->assertSame('paid', $invoice->fresh()->status);
        $this->assertSame(1000000, $invoice->fresh()->paidAmount());
        Storage::disk('receipts')->assertExists($receipt->file_path);
        Http::assertNothingSent();
    }

    public function test_verified_received_funds_remain_recordable_after_payer_loses_activity_and_role(): void
    {
        [$admin, $manager, $invoice, $payment] = $this->fixture('capture');
        User::whereKey($manager->id)->update(['is_active' => false]);
        $manager->roles()->detach();
        $this->assertTrue(app(OrganizationBilling::class)->capture($payment, 'SOFTWARE-VERIFIED-CAPTURE66'));
        $this->assertSame('paid', $payment->fresh()->status);
        $this->assertSame(1000000, $invoice->fresh()->paidAmount());
        Http::assertNothingSent();
    }

    public function test_another_current_manager_can_reuse_original_pending_attempt_without_rewriting_payer_or_amount(): void
    {
        [$admin, $manager, $invoice, $payment] = $this->fixture('reuse');
        $other = $this->user('org_admin');
        $invoice->organization->members()->attach($other->id, ['kind' => 'manager']);
        $before = $payment->fresh()->getAttributes();
        $again = app(OrganizationBilling::class)->attempt($other, $invoice);
        $this->assertSame($payment->id, $again->id);
        $this->assertSame($before, $payment->fresh()->getAttributes());
        Http::assertNothingSent();
    }
}
