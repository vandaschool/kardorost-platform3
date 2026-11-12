<?php

namespace Tests\Feature;

use App\Models\Cohort;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\Role;
use App\Models\User;
use App\Services\BillingService;
use App\Services\EnrollmentService;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class BillingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
    }

    private function user(string $role = 'student'): User
    {
        $u = User::factory()->create();
        $u->roles()->attach(Role::where('key', $role)->value('id'));

        return $u;
    }

    private function cohort(int $capacity = 10): Cohort
    {
        $course = Course::factory()->create(['price' => 1000000]);
        $c = Cohort::factory()->create(['course_id' => $course->id, 'capacity' => $capacity, 'installment_enabled' => true]);
        $c->installmentPlan()->createMany([['sequence' => 1, 'amount' => 400000, 'due_date' => now()->toDateString()], ['sequence' => 2, 'amount' => 600000, 'due_date' => now()->addMonth()->toDateString()]]);

        return $c;
    }

    public function test_plan_total_is_verified_and_not_accepted_from_student(): void
    {
        $c = $this->cohort();
        $admin = $this->user('admin');
        $this->actingAs($admin)->patch('/admin/cohorts/'.$c->id.'/billing', ['expected_revision' => 0, 'installment_enabled' => true, 'manual_payment_enabled' => false, 'parts' => [['amount' => 1], ['amount' => 2, 'due_date' => now()->addMonth()->toDateString()]]])->assertSessionHasErrors('parts');
        $student = $this->user();
        $this->actingAs($student)->patch('/admin/cohorts/'.$c->id.'/billing', ['installment_enabled' => false, 'manual_payment_enabled' => true, 'parts' => []])->assertForbidden();
        $this->post('/cohorts/'.$c->id.'/enroll', ['payment_plan' => 'installments', 'amount' => 1, 'parts' => [['amount' => 1]]])->assertSessionHasNoErrors();
        $e = Enrollment::first();
        $this->assertSame(1000000, $e->amount);
        $this->assertSame(1000000, (int) $e->installments()->sum('amount'));
        $this->assertSame(400000, $e->installments()->first()->amount);
    }

    public function test_existing_schedule_is_not_changed_by_admin_plan_edits(): void
    {
        $c = $this->cohort();
        $e = app(EnrollmentService::class)->reserve($this->user(), $c, 'installments');
        $old = $e->installments()->orderBy('sequence')->get()->map(fn ($i) => [$i->amount, $i->due_date->toDateString()])->all();
        $this->actingAs($this->user('admin'))->patch('/admin/cohorts/'.$c->id.'/billing', ['expected_revision' => 0, 'installment_enabled' => true, 'manual_payment_enabled' => false, 'parts' => [['amount' => 50000], ['amount' => 50000, 'due_date' => now()->addMonths(2)->toDateString()]]])->assertSessionHasNoErrors();
        $this->assertSame($old, $e->installments()->orderBy('sequence')->get()->map(fn ($i) => [$i->amount, $i->due_date->toDateString()])->all());
        $next = app(EnrollmentService::class)->reserve($this->user(), $c, 'installments');
        $this->assertSame(500000, $next->installments()->first()->amount);
    }

    public function test_first_installment_activates_course_but_does_not_pay_later_installment(): void
    {
        $u = $this->user();
        $e = app(EnrollmentService::class)->reserve($u, $this->cohort(), 'installments');
        $invoices = $e->installments()->get();
        $p = Payment::factory()->create(['user_id' => $u->id, 'enrollment_id' => $e->id, 'installment_id' => $invoices[0]->id, 'amount' => 400000]);
        $this->assertTrue(app(BillingService::class)->capture($p, 'first-ref'));
        $this->assertSame('active', $e->fresh()->status);
        $this->assertNotNull($invoices[0]->fresh()->paid_at);
        $this->assertNull($invoices[1]->fresh()->paid_at);
        $summary = app(BillingService::class)->summary($e->fresh());
        $this->assertSame(400000, $summary['paid']);
        $this->assertSame(600000, $summary['remaining']);
        $this->assertDatabaseCount('payment_allocations', 1);
    }

    public function test_student_cannot_pay_later_installment_before_first_payment(): void
    {
        $u = $this->user();
        $e = app(EnrollmentService::class)->reserve($u, $this->cohort(), 'installments');
        $second = $e->installments()->where('sequence', 2)->first();
        $this->actingAs($u)->post('/installments/'.$second->id.'/pay')->assertSessionHasErrors('payment');
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_installment_gateway_receives_server_amount_and_repeated_callback_is_idempotent(): void
    {
        $u = $this->user();
        $e = app(EnrollmentService::class)->reserve($u, $this->cohort(), 'installments');
        $i = $e->installments()->first();
        config(['platform.zibal.enabled' => true, 'platform.zibal.merchant' => 'test']);
        Http::fake(['gateway.zibal.ir/v1/request' => Http::response(['result' => 100, 'trackId' => 'i-one'])]);
        $this->actingAs($u)->post('/installments/'.$i->id.'/pay', ['amount' => 1])->assertRedirect();
        $payment = Payment::first();
        Http::assertSent(fn ($r) => $r['amount'] === 400000);
        Http::fake(['gateway.zibal.ir/v1/verify' => Http::response(['result' => 100, 'status' => 1, 'amount' => 400000, 'orderId' => (string) $payment->id, 'refNumber' => 'one-ref'])]);
        $this->get('/payments/callback?trackId=i-one');
        $this->get('/payments/callback?trackId=i-one');
        $this->assertDatabaseCount('payment_allocations', 1);
        $this->assertDatabaseHas('enrollments', ['id' => $e->id, 'status' => 'active']);
    }

    public function test_subsequent_installment_can_be_prepaid_and_overdue_does_not_automatically_lock_course(): void
    {
        $u = $this->user();
        $e = app(EnrollmentService::class)->reserve($u, $this->cohort(), 'installments');
        $first = $e->installments()->first();
        $second = $e->installments()->where('sequence', 2)->first();
        $payment = Payment::factory()->create(['user_id' => $u->id, 'enrollment_id' => $e->id, 'installment_id' => $first->id, 'amount' => $first->amount]);
        app(BillingService::class)->capture($payment, 'first-paid');
        $second->update(['due_date' => now()->subDay()->toDateString()]);
        $this->actingAs($u)->get('/learning/'.$e->id)->assertOk();
        $this->get('/finance')->assertOk()->assertInertia(fn (Assert $p) => $p->where('enrollments.0.summary.overdue', 600000));
        $p = app(BillingService::class)->attempt($u, $second);
        $this->assertSame(600000, $p->amount);
        app(BillingService::class)->capture($p, 'second-paid');
        $this->assertSame(0, app(BillingService::class)->summary($e->fresh())['remaining']);
    }

    public function test_expired_quote_is_voided_and_late_payment_is_not_applied_to_requoted_contract(): void
    {
        $u = $this->user();
        $c = $this->cohort();
        $e = app(EnrollmentService::class)->reserve($u, $c);
        $old = $e->installments()->first();
        $payment = Payment::factory()->create(['user_id' => $u->id, 'enrollment_id' => $e->id, 'installment_id' => $old->id, 'amount' => $old->amount]);
        $e->update(['expires_at' => now()->subSecond()]);
        $c->course->update(['price' => 1200000]);
        $new = app(EnrollmentService::class)->reserve($u, $c);
        $this->assertSame(2, $new->billing_version);
        $this->assertNotNull($old->fresh()->voided_at);
        $this->assertFalse(app(BillingService::class)->capture($payment, 'old-contract-ref'));
        $this->assertSame('obsolete_quote', $payment->fresh()->review_reason);
        $this->assertDatabaseCount('payment_allocations', 0);
    }

    public function test_duplicate_charge_is_preserved_as_unallocated_and_cannot_be_force_allocated(): void
    {
        $u = $this->user();
        $e = app(EnrollmentService::class)->reserve($u, $this->cohort());
        $i = $e->installments()->first();
        $a = Payment::factory()->create(['user_id' => $u->id, 'enrollment_id' => $e->id, 'installment_id' => $i->id, 'amount' => $i->amount]);
        $b = Payment::factory()->create(['user_id' => $u->id, 'enrollment_id' => $e->id, 'installment_id' => $i->id, 'amount' => $i->amount]);
        app(BillingService::class)->capture($a, 'charge-a');
        $this->assertFalse(app(BillingService::class)->capture($b, 'charge-b'));
        $this->assertSame('review_required', $b->fresh()->status);
        $this->assertNotNull($b->fresh()->captured_at);
        $this->actingAs($this->user('admin'))->post('/admin/finance/payments/'.$b->id.'/allocate')->assertSessionHasErrors('payment');
        $this->assertDatabaseCount('payment_allocations', 1);
    }

    public function test_late_payment_can_be_resolved_only_when_a_seat_is_available(): void
    {
        $u = $this->user();
        $c = $this->cohort(1);
        $e = app(EnrollmentService::class)->reserve($u, $c);
        $i = $e->installments()->first();
        $p = Payment::factory()->create(['user_id' => $u->id, 'enrollment_id' => $e->id, 'installment_id' => $i->id, 'amount' => $i->amount]);
        $e->update(['expires_at' => now()->subSecond()]);
        $other = app(EnrollmentService::class)->reserve($this->user(), $c);
        app(BillingService::class)->capture($p, 'late-with-proof');
        $admin = $this->user('admin');
        $this->actingAs($admin)->post('/admin/finance/payments/'.$p->id.'/allocate')->assertSessionHasErrors('payment');
        $other->update(['expires_at' => now()->subSecond()]);
        $this->post('/admin/finance/payments/'.$p->id.'/allocate')->assertSessionHasNoErrors();
        $this->post('/admin/finance/payments/'.$p->id.'/allocate')->assertSessionHasNoErrors();
        $this->assertDatabaseCount('payment_allocations', 1);
        $this->assertSame('active', $e->fresh()->status);
    }

    public function test_previously_verified_gateway_uses_inquiry_to_recover_without_fabricated_success(): void
    {
        $u = $this->user();
        $e = app(EnrollmentService::class)->reserve($u, $this->cohort());
        $i = $e->installments()->first();
        $p = Payment::factory()->create(['user_id' => $u->id, 'enrollment_id' => $e->id, 'installment_id' => $i->id, 'amount' => $i->amount, 'track_id' => 'recover']);
        config(['platform.zibal.enabled' => true, 'platform.zibal.merchant' => 'test']);
        Http::fake(['gateway.zibal.ir/v1/verify' => Http::response(['result' => 201]), 'gateway.zibal.ir/v1/inquiry' => Http::response(['result' => 100, 'status' => 1, 'amount' => $i->amount, 'orderId' => (string) $p->id, 'refNumber' => 'recovered'])]);
        $this->get('/payments/callback?trackId=recover')->assertRedirect('/learning');
        $this->assertSame('paid', $p->fresh()->status);
        Http::assertSentCount(2);
    }

    public function test_disabled_or_invalid_installment_plan_rolls_back_reservation(): void
    {
        $u = $this->user();
        $c = $this->cohort();
        $c->installmentPlan()->where('sequence', 2)->update(['amount' => 1]);
        $this->actingAs($u)->post('/cohorts/'.$c->id.'/enroll', ['payment_plan' => 'installments'])->assertSessionHasErrors('payment_plan');
        $this->assertDatabaseCount('enrollments', 0);
        $this->assertDatabaseCount('installments', 0);
    }

    public function test_stale_admin_form_cannot_overwrite_a_newer_plan(): void
    {
        $c = $this->cohort();
        $data = ['expected_revision' => 0, 'installment_enabled' => true, 'manual_payment_enabled' => false, 'parts' => [['amount' => 40000], ['amount' => 60000, 'due_date' => now()->addMonth()->toDateString()]]];
        $this->actingAs($this->user('admin'))->patch('/admin/cohorts/'.$c->id.'/billing', $data)->assertSessionHasNoErrors();
        $data['parts'][1]['due_date'] = now()->addMonths(2)->toDateString();
        $this->patch('/admin/cohorts/'.$c->id.'/billing', $data)->assertSessionHasErrors('parts');
        $this->assertSame(1, $c->fresh()->billing_revision);
    }

    public function test_temporary_payment_lock_returns_a_retry_message_instead_of_a_server_error(): void
    {
        $u = $this->user();
        $e = app(EnrollmentService::class)->reserve($u, $this->cohort());
        $invoice = $e->installments()->first();
        $lock = \Mockery::mock(Lock::class);
        $lock->shouldReceive('block')->once()->andThrow(new LockTimeoutException);
        $store = Cache::store();
        Cache::shouldReceive('driver')->andReturn($store);
        Cache::shouldReceive('lock')->with('installment-payment:'.$invoice->id, 30)->once()->andReturn($lock);
        $this->actingAs($u)->post('/installments/'.$invoice->id.'/pay')->assertSessionHasErrors('payment');
        $this->assertDatabaseCount('payments', 0);
    }
}
