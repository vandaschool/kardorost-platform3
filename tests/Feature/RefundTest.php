<?php

namespace Tests\Feature;

use App\Models\Cohort;
use App\Models\ConsultationSlot;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\RefundEvent;
use App\Models\Role;
use App\Models\User;
use App\Services\BillingService;
use App\Services\ConsultationBilling;
use App\Services\ConsultationService;
use App\Services\EnrollmentService;
use App\Services\RefundService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class RefundTest extends TestCase
{
    use RefreshDatabase;

    private const IBAN = 'IR820540102680020817909002';

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('refunds');
        Http::preventStrayRequests();
        $this->travelTo(now()->startOfMinute());
    }

    private function user(string $role = 'student'): User
    {
        $u = User::factory()->create();
        $u->roles()->attach(Role::where('key', $role)->value('id'));

        return $u;
    }

    private function payment(): Payment
    {
        return Payment::factory()->create(['status' => 'review_required', 'reference' => fake()->unique()->uuid(), 'captured_at' => now(), 'paid_at' => now(), 'amount' => 1000000]);
    }

    private function request(Payment $p): Refund
    {
        return app(RefundService::class)->request($p->user, $p, self::IBAN, 'لطفاً وجه را برگردانید', 0);
    }

    private function approve(Refund $f): void
    {
        app(RefundService::class)->review($this->user('admin'), $f, $f->version, 'approve', 'تطبیق وجه و درخواست انجام شد');
    }

    private function proof(string $content = 'refund'): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('refund.pdf', "%PDF-1.4\n".$content);
    }

    private function data(Refund $f, array $override = []): array
    {
        return [...['version' => $f->fresh()->version, 'amount' => 100000, 'destination' => self::IBAN, 'transfer_reference' => '۱۲۳۴۵۶۷۸۹', 'transfer_date' => now()->toDateString(), 'statement_verified' => true, 'beneficiary_verified' => true], ...$override];
    }

    private function complete(Refund $f, string $content = 'refund'): void
    {
        app(RefundService::class)->complete($this->user('admin'), $f, $this->data($f), $this->proof($content));
    }

    public function test_request_encrypts_iban_and_keeps_server_payment_amount(): void
    {
        $p = $this->payment();
        $this->actingAs($p->user)->post('/payments/'.$p->id.'/refund', ['destination' => 'ir۸۲۰۵۴۰۱۰۲۶۸۰۰۲۰۸۱۷۹۰۹۰۰۲', 'reason' => 'برگشت وجه اضافه', 'version' => 0, 'owner_confirmed' => true, 'amount' => 1])->assertSessionHasNoErrors();
        $f = Refund::firstOrFail();
        $this->assertSame(1000000, $f->amount);
        $this->assertSame(self::IBAN, $f->destination);
        $this->assertNotSame(self::IBAN, DB::table('refunds')->value('destination'));
        $this->assertDatabaseCount('refund_events', 1);
        $this->assertArrayNotHasKey('destination', $f->toArray());
    }

    public function test_iban_checksum_and_ownership_confirmation_are_required(): void
    {
        $p = $this->payment();
        $d = ['destination' => 'IR000540102680020817909002', 'reason' => 'برگشت وجه اضافه', 'version' => 0, 'owner_confirmed' => true];
        $this->actingAs($p->user)->post('/payments/'.$p->id.'/refund', $d)->assertSessionHasErrors('refund');
        $this->post('/payments/'.$p->id.'/refund', [...$d, 'destination' => self::IBAN, 'owner_confirmed' => false])->assertSessionHasErrors('owner_confirmed');
        $this->assertDatabaseCount('refunds', 0);
    }

    public function test_other_users_and_admin_cannot_request_on_behalf_of_payment_owner(): void
    {
        $p = $this->payment();
        foreach (['student', 'coach', 'org_admin', 'admin'] as $role) {
            $this->actingAs($this->user($role))->post('/payments/'.$p->id.'/refund', ['destination' => self::IBAN, 'reason' => 'درخواست غیرمجاز', 'version' => 0, 'owner_confirmed' => true])->assertForbidden();
        }
    }

    public function test_unverified_and_allocated_course_payments_are_ineligible(): void
    {
        $p = $this->payment();
        $p->update(['captured_at' => null]);
        $d = ['destination' => self::IBAN, 'reason' => 'برگشت وجه اضافه', 'version' => 0, 'owner_confirmed' => true];
        $this->actingAs($p->user)->post('/payments/'.$p->id.'/refund', $d)->assertSessionHasErrors('refund');
        $p->update(['captured_at' => now(), 'status' => 'paid']);
        $this->post('/payments/'.$p->id.'/refund', $d)->assertSessionHasErrors('refund');
        $this->assertDatabaseCount('refunds', 0);
    }

    public function test_duplicate_request_does_not_create_duplicate_history(): void
    {
        $p = $this->payment();
        $f = $this->request($p);
        $this->actingAs($p->user)->post('/payments/'.$p->id.'/refund', ['destination' => self::IBAN, 'reason' => 'درخواست تکراری', 'version' => 0, 'owner_confirmed' => true])->assertSessionHasErrors('refund');
        $this->assertDatabaseCount('refunds', 1);
        $this->assertDatabaseCount('refund_events', 1);
    }

    public function test_only_admin_can_review_or_register_completion(): void
    {
        $f = $this->request($this->payment());
        foreach (['student', 'coach', 'org_admin'] as $role) {
            $this->actingAs($this->user($role))->post('/refunds/'.$f->id.'/review', [])->assertForbidden();
            $this->post('/refunds/'.$f->id.'/complete', [])->assertForbidden();
        }
    }

    public function test_approval_is_not_completion_and_stale_rejection_fails(): void
    {
        $f = $this->request($this->payment());
        $admin = $this->user('admin');
        $this->actingAs($admin)->post('/refunds/'.$f->id.'/review', ['version' => 1, 'action' => 'approve', 'note' => 'وجه و درخواست بررسی شد'])->assertSessionHasNoErrors();
        $this->assertSame('approved', $f->fresh()->status);
        $this->assertNull($f->fresh()->completed_at);
        $this->assertSame('review_required', $f->payment->fresh()->status);
        $this->post('/refunds/'.$f->id.'/review', ['version' => 1, 'action' => 'reject', 'note' => 'فرم قدیمی مدیر دوم'])->assertSessionHasErrors('refund');
        $this->assertDatabaseCount('refund_events', 2);
    }

    public function test_rejected_request_can_be_corrected_with_history_and_expected_version(): void
    {
        $f = $this->request($this->payment());
        app(RefundService::class)->review($this->user('admin'), $f, 1, 'reject', 'مدرک تکمیلی لازم است');
        $this->actingAs($f->payment->user)->post('/payments/'.$f->payment_id.'/refund', ['destination' => self::IBAN, 'reason' => 'توضیح تکمیلی صاحب پرداخت', 'version' => 2, 'owner_confirmed' => true])->assertSessionHasNoErrors();
        $this->assertSame('requested', $f->fresh()->status);
        $this->assertSame(3, $f->fresh()->version);
        $this->assertSame(['requested', 'rejected', 'requested'], RefundEvent::orderBy('version')->pluck('status')->all());
    }

    public function test_full_manual_refund_records_private_evidence_and_retains_original_charge(): void
    {
        $p = $this->payment();
        $f = $this->request($p);
        $this->approve($f);
        $this->complete($f);
        $this->assertSame('completed', $f->fresh()->status);
        $this->assertSame('refunded', $p->fresh()->status);
        $this->assertNotNull($p->fresh()->captured_at);
        $this->assertSame(1000000, $p->fresh()->amount);
        $this->assertSame('123456789', $f->fresh()->transfer_reference);
        $this->assertDatabaseCount('refund_events', 3);
        $this->assertSame(1, count(Storage::disk('refunds')->allFiles()));
        Http::assertNothingSent();
        $this->actingAs($p->user)->get('/refunds/'.$f->id.'/file')->assertOk()->assertHeader('X-Content-Type-Options', 'nosniff');
        foreach (['student', 'coach', 'org_admin'] as $role) {
            $this->actingAs($this->user($role))->get('/refunds/'.$f->id.'/file')->assertForbidden();
        }
        $this->actingAs($this->user('admin'))->get('/refunds/'.$f->id.'/file')->assertOk();
    }

    public function test_completion_requires_exact_amount_destination_and_independent_confirmations(): void
    {
        $f = $this->request($this->payment());
        $this->approve($f);
        $admin = $this->user('admin');
        foreach ([['amount' => 1], ['destination' => 'IR820540102680020817909003'], ['statement_verified' => false], ['beneficiary_verified' => false]] as $override) {
            $this->actingAs($admin)->post('/refunds/'.$f->id.'/complete', [...$this->data($f, $override), 'file' => $this->proof()])->assertSessionHasErrors();
            $this->assertSame('approved', $f->fresh()->status);
        }
        $this->assertSame([], Storage::disk('refunds')->allFiles());
        $this->assertDatabaseCount('refund_events', 2);
    }

    public function test_proof_date_cannot_precede_charge_or_be_in_future(): void
    {
        $f = $this->request($this->payment());
        $this->approve($f);
        foreach ([now()->subDay()->toDateString(), now()->addDay()->toDateString()] as $date) {
            $this->actingAs($this->user('admin'))->post('/refunds/'.$f->id.'/complete', [...$this->data($f, ['transfer_date' => $date]), 'file' => $this->proof()])->assertSessionHasErrors();
        }$this->assertSame([], Storage::disk('refunds')->allFiles());
    }

    public function test_wrong_file_type_and_oversized_evidence_are_rejected(): void
    {
        $f = $this->request($this->payment());
        $this->approve($f);
        foreach ([UploadedFile::fake()->create('proof.exe', 1), UploadedFile::fake()->create('proof.pdf', 5121, 'application/pdf')] as $file) {
            $this->actingAs($this->user('admin'))->post('/refunds/'.$f->id.'/complete', [...$this->data($f), 'file' => $file])->assertSessionHasErrors('file');
        }$this->assertSame([], Storage::disk('refunds')->allFiles());
    }

    public function test_duplicate_completion_preserves_single_event_and_file(): void
    {
        $f = $this->request($this->payment());
        $this->approve($f);
        $data = $this->data($f);
        $this->complete($f);
        $this->actingAs($this->user('admin'))->post('/refunds/'.$f->id.'/complete', [...$data, 'file' => $this->proof()])->assertSessionHasErrors('refund');
        $this->assertDatabaseCount('refund_events', 3);
        $this->assertSame(1, count(Storage::disk('refunds')->allFiles()));
    }

    public function test_same_transfer_reference_or_proof_cannot_complete_two_refunds(): void
    {
        $f = $this->request($this->payment());
        $this->approve($f);
        $this->complete($f);
        $other = $this->request($this->payment());
        $this->approve($other);
        $this->actingAs($this->user('admin'))->post('/refunds/'.$other->id.'/complete', [...$this->data($other), 'file' => $this->proof('different')])->assertSessionHasErrors('refund');
        $this->post('/refunds/'.$other->id.'/complete', [...$this->data($other, ['transfer_reference' => 'DIFFERENT']), 'file' => $this->proof()])->assertSessionHasErrors('refund');
        $this->assertSame('approved', $other->fresh()->status);
        $this->assertSame(1, count(Storage::disk('refunds')->allFiles()));
    }

    public function test_owner_dto_masks_iban_and_hides_file_paths_and_other_users_requests(): void
    {
        $f = $this->request($this->payment());
        $this->actingAs($f->payment->user)->get('/refunds')->assertInertia(fn (Assert $a) => $a->where('rows.data.0.destination', null)->where('rows.data.0.destination_mask', 'IR ···· 9002')->missing('rows.data.0.file_path')->missing('rows.data.0.file_hash'));
        $this->actingAs($this->user())->get('/refunds')->assertInertia(fn (Assert $a) => $a->has('rows.data', 0));
        $this->get('/refunds?payment='.$f->payment_id)->assertNotFound();
        $this->get('/refunds?view=admin')->assertForbidden();
        $this->actingAs($this->user('admin'))->get('/refunds?view=admin')->assertInertia(fn (Assert $a) => $a->where('rows.data.0.destination', self::IBAN));
    }

    public function test_course_allocation_is_blocked_while_refund_is_requested_or_approved(): void
    {
        $p = $this->payment();
        $f = $this->request($p);
        $this->actingAs($this->user('admin'))->post('/admin/finance/payments/'.$p->id.'/allocate')->assertSessionHasErrors('payment');
        $this->approve($f);
        $this->post('/admin/finance/payments/'.$p->id.'/allocate')->assertSessionHasErrors('payment');
        $this->assertDatabaseCount('payment_allocations', 0);
    }

    private function consultation(): Payment
    {
        config(['platform.zibal.enabled' => true, 'platform.zibal.merchant' => 'test']);
        $u = $this->user();
        $s = ConsultationSlot::factory()->create(['price' => 1000000]);
        $c = app(ConsultationService::class)->book($u, $s, 'درخواست مشاوره');
        $p = app(ConsultationBilling::class)->attempt($u, $c);
        app(ConsultationBilling::class)->capture($p, 'CONSULTATION');

        return $p->fresh();
    }

    public function test_active_consultation_is_ineligible_but_cancellation_can_be_refunded_and_booking_unblocked(): void
    {
        $p = $this->consultation();
        $this->assertFalse(app(RefundService::class)->eligible($p));
        $c = $p->consultation;
        app(ConsultationService::class)->change($p->user, $c, $c->version, 'cancel', 'لغو جلسه پیش از شروع', null);
        $p->refresh();
        $f = $this->request($p);
        $this->approve($f);
        $this->complete($f);
        $this->assertSame('completed', $c->fresh()->refund_status);
        $new = app(ConsultationService::class)->book($p->user, $c->slot, 'مشاوره تازه');
        $this->assertSame('pending_payment', $new->status);
    }

    public function test_refunded_consultation_callback_never_reactivates_old_session(): void
    {
        $p = $this->consultation();
        $c = $p->consultation;
        app(ConsultationService::class)->change($p->user, $c, $c->version, 'cancel', 'لغو جلسه پیش از شروع', null);
        $f = $this->request($p);
        $this->approve($f);
        $this->complete($f);
        $p->update(['track_id' => 'refunded-consult']);
        $this->get('/consultation-payments/callback?trackId=refunded-consult')->assertRedirect('/refunds');
        $this->assertFalse(app(ConsultationBilling::class)->capture($p, 'CONSULTATION'));
        $this->assertSame('cancelled', $c->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_refunded_course_charge_does_not_reactivate_enrollment_and_requote_is_allowed(): void
    {
        $u = $this->user();
        $course = Course::factory()->create(['price' => 1000000]);
        $cohort = Cohort::factory()->create(['course_id' => $course->id, 'capacity' => 1]);
        $e = app(EnrollmentService::class)->reserve($u, $cohort);
        $i = $e->installments()->first();
        $p = Payment::factory()->create(['user_id' => $u->id, 'enrollment_id' => $e->id, 'installment_id' => $i->id, 'amount' => 1000000]);
        $this->travel(16)->minutes();
        $blocker = Enrollment::factory()->create(['cohort_id' => $cohort->id, 'course_id' => $course->id, 'status' => 'active']);
        app(BillingService::class)->capture($p, 'LATE-COURSE');
        $f = $this->request($p->fresh());
        $this->approve($f);
        $this->complete($f);
        $p->update(['track_id' => 'refunded-course']);
        $this->get('/payments/callback?trackId=refunded-course')->assertRedirect('/refunds');
        $this->assertFalse(app(BillingService::class)->capture($p,'LATE-COURSE'));
        $blocker->update(['status' => 'cancelled']);
        $new = app(EnrollmentService::class)->reserve($u,$cohort);
        $this->assertSame(2,$new->billing_version);
        $this->assertSame('pending_payment',$new->status);
    }
}
