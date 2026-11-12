<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\ConsultationRequest;
use App\Models\ConsultationSlot;
use App\Models\Payment;
use App\Models\Role;
use App\Models\User;
use App\Services\ConsultationBilling;
use App\Services\ConsultationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ConsultationPaymentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(now()->startOfMinute());
        config(['platform.zibal.enabled' => true, 'platform.zibal.merchant' => 'test']);
        Http::preventStrayRequests();
    }

    private function user(string $role = 'student'): User
    {
        $u = User::factory()->create();
        $u->roles()->attach(Role::where('key', $role)->value('id'));

        return $u;
    }

    private function reserve(?User $u = null, ?ConsultationSlot $s = null): Consultation
    {
        return app(ConsultationService::class)->book($u ?? $this->user(), $s ?? ConsultationSlot::factory()->create(['price' => 1500000]), 'موضوع مشاوره');
    }

    private function attempt(Consultation $c): Payment
    {
        return app(ConsultationBilling::class)->attempt($c->student, $c);
    }

    private function verify(Payment $p, array $override = []): void
    {
        Http::fake(['gateway.zibal.ir/v1/verify' => Http::response([...['result' => 100, 'status' => 1, 'amount' => $p->amount, 'orderId' => (string) $p->id, 'refNumber' => 'CONSULT-'.$p->id], ...$override])]);
    }

    public function test_admin_can_publish_paid_tariff_but_coach_cannot(): void
    {
        $coach = $this->user('coach');
        $d = ['coach_id' => $coach->id, 'date' => now()->addDay()->toDateString(), 'time' => '18:00', 'minutes' => 60, 'provider' => 'meet', 'price' => 150000, 'price_confirmation' => true, 'free_confirmation' => false];
        $this->actingAs($coach)->post('/consultations/slots', $d)->assertForbidden();
        $this->actingAs($this->user('admin'))->post('/consultations/slots', [...$d, 'price_confirmation' => false])->assertSessionHasErrors('price_confirmation');
        $this->post('/consultations/slots', $d)->assertSessionHasNoErrors();
        $this->assertSame(1500000, ConsultationSlot::first()->price);
    }

    public function test_paid_booking_is_a_frozen_pending_reservation_and_cannot_join(): void
    {
        $c = $this->reserve();
        $this->assertSame('pending_payment', $c->status);
        $this->assertSame(1500000, $c->amount);
        $this->assertTrue($c->expires_at->equalTo(now()->addMinutes(15)));
        $c->slot->update(['price' => 9000000]);
        $p = $this->attempt($c);
        $this->assertSame(1500000, $p->amount);
        $this->assertNull($p->enrollment_id);
        $this->assertSame($c->id, $p->consultation_id);
        $this->actingAs($c->student)->get('/consultations/'.$c->id.'/join')->assertForbidden();
    }

    public function test_gateway_disabled_blocks_paid_booking_and_preserves_free_booking(): void
    {
        config(['platform.zibal.enabled' => false]);
        $s = ConsultationSlot::factory()->create(['price' => 10]);
        $this->actingAs($this->user())->post('/consultations/slots/'.$s->id.'/book', ['topic' => 'موضوع مشاوره'])->assertSessionHasErrors('consultation');
        $this->assertFalse($s->fresh()->booked);
        $f = ConsultationSlot::factory()->create();
        $this->post('/consultations/slots/'.$f->id.'/book', ['topic' => 'موضوع مشاوره'])->assertSessionHasNoErrors();
        $this->assertSame('confirmed', Consultation::first()->status);
    }

    public function test_gateway_start_uses_consultation_callback_and_reuses_pending_attempt(): void
    {
        $c = $this->reserve();
        Http::fake(['gateway.zibal.ir/v1/request' => Http::response(['result' => 100, 'trackId' => 'consult-track'])]);
        $this->actingAs($c->student)->post('/consultations/'.$c->id.'/pay')->assertRedirect('https://gateway.zibal.ir/start/consult-track');
        $this->post('/consultations/'.$c->id.'/pay')->assertRedirect('https://gateway.zibal.ir/start/consult-track');
        $this->assertDatabaseCount('payments', 1);
        Http::assertSentCount(1);
        Http::assertSent(fn ($r) => $r['amount'] === 1500000 && str_ends_with($r['callbackUrl'], '/consultation-payments/callback') && $r['orderId'] === (string) Payment::first()->id);
    }

    public function test_payment_route_is_owner_only(): void
    {
        $c = $this->reserve();
        foreach (['student', 'coach', 'admin', 'org_admin'] as $role) {
            $this->actingAs($this->user($role))->post('/consultations/'.$c->id.'/pay')->assertForbidden();
        }$this->assertDatabaseCount('payments', 0);
    }

    public function test_failed_start_can_retry_without_creating_a_confirmed_session(): void
    {
        $c = $this->reserve();
        Http::fake(['gateway.zibal.ir/v1/request' => Http::sequence()->push(['result' => 102], 200)->push(['result' => 100, 'trackId' => 'retry'], 200)]);
        $this->actingAs($c->student)->post('/consultations/'.$c->id.'/pay')->assertSessionHasErrors('payment');
        $this->assertSame('failed', Payment::first()->status);
        $this->assertSame('pending_payment', $c->fresh()->status);
        $this->post('/consultations/'.$c->id.'/pay')->assertRedirect('https://gateway.zibal.ir/start/retry');
        $this->assertDatabaseCount('payments', 2);
    }

    public function test_verified_callback_confirms_once_and_notifies_once(): void
    {
        $c = $this->reserve();
        $p = $this->attempt($c);
        $p->update(['track_id' => 'verify']);
        $this->verify($p);
        $this->get('/consultation-payments/callback?trackId=verify&success=0')->assertRedirect('/consultations');
        $this->assertSame('confirmed', $c->fresh()->status);
        $this->assertSame($p->id, $c->fresh()->settled_payment_id);
        $this->assertSame('paid', $p->fresh()->status);
        $this->assertSame(4, DB::table('notifications')->count());
        $this->get('/consultation-payments/callback?trackId=verify')->assertRedirect('/consultations');
        Http::assertSentCount(1);
        $this->assertSame(4, DB::table('notifications')->count());
    }

    public function test_success_query_without_valid_amount_does_not_confirm(): void
    {
        $c = $this->reserve();
        $p = $this->attempt($c);
        $p->update(['track_id' => 'amount']);
        $this->verify($p, ['amount' => 150000]);
        $this->get('/consultation-payments/callback?trackId=amount&success=1');
        $this->assertSame('pending_payment', $c->fresh()->status);
        $this->assertNull($p->fresh()->captured_at);
    }

    public function test_wrong_order_and_provider_unavailability_do_not_confirm(): void
    {
        $c = $this->reserve();
        $p = $this->attempt($c);
        $p->update(['track_id' => 'order']);
        $this->verify($p, ['orderId' => '9999999']);
        $this->get('/consultation-payments/callback?trackId=order');
        $this->assertNull($c->fresh()->settled_payment_id);
        Http::fake(['gateway.zibal.ir/v1/verify' => Http::response([], 503)]);
        $this->get('/consultation-payments/callback?trackId=order')->assertRedirect('/consultations');
        $this->assertNull($p->fresh()->captured_at);
    }

    public function test_expired_hold_can_be_rebooked_but_late_verified_payment_never_takes_the_new_seat(): void
    {
        $c = $this->reserve();
        $p = $this->attempt($c);
        $this->travel(16)->minutes();
        $other = $this->reserve(null, $c->slot);
        $this->assertSame('expired', $c->fresh()->status);
        $this->assertFalse(app(ConsultationBilling::class)->capture($p, 'LATE'));
        $this->assertSame('review_required', $p->fresh()->status);
        $this->assertNotNull($p->fresh()->captured_at);
        $this->assertSame('pending_payment', $other->fresh()->status);
        $this->assertTrue($other->slot->fresh()->booked);
    }

    public function test_unpaid_cancellation_releases_slot_and_late_charge_is_reviewed(): void
    {
        $c = $this->reserve();
        $p = $this->attempt($c);
        app(ConsultationService::class)->change($c->student, $c, 1, 'cancel', 'لغو برنامه', null);
        $this->assertFalse($c->slot->fresh()->booked);
        $this->assertNull($c->fresh()->refund_status);
        $this->assertFalse(app(ConsultationBilling::class)->capture($p, 'CANCELLED'));
        $this->assertSame('cancelled', $c->fresh()->status);
        $this->assertNotNull($p->fresh()->captured_at);
    }

    public function test_paid_cancellation_records_refund_liability_without_claiming_money_returned(): void
    {
        $c = $this->reserve();
        $p = $this->attempt($c);
        app(ConsultationBilling::class)->capture($p, 'PAID-CANCEL');
        $c->refresh();
        app(ConsultationService::class)->change($c->student, $c, $c->version, 'cancel', 'لغو برنامه', null);
        $this->assertSame('pending', $c->fresh()->refund_status);
        $this->assertSame('paid', $p->fresh()->status);
        $this->assertFalse($c->slot->fresh()->booked);
        $this->actingAs($this->user('admin'))->get('/admin/finance?tab=payments')->assertInertia(fn (Assert $a) => $a->where('rows.data.0.refund_status', 'pending')->where('rows.data.0.can_allocate', false));
    }

    public function test_extra_captured_payment_cannot_confirm_twice(): void
    {
        $c = $this->reserve();
        $p = $this->attempt($c);
        $other = Payment::create([...$p->only('user_id', 'consultation_id', 'amount', 'method'), 'status' => 'pending']);
        $this->assertTrue(app(ConsultationBilling::class)->capture($p, 'FIRST'));
        $this->assertFalse(app(ConsultationBilling::class)->capture($other, 'SECOND'));
        $this->assertSame('consultation_duplicate', $other->fresh()->review_reason);
        $this->assertSame($p->id, $c->fresh()->settled_payment_id);
    }

    public function test_same_bank_reference_is_unique_across_course_and_consultation_payments(): void
    {
        $existing = Payment::factory()->create(['reference' => 'SHARED', 'status' => 'paid']);
        $c = $this->reserve();
        $p = $this->attempt($c);
        $this->assertFalse(app(ConsultationBilling::class)->capture($p, 'SHARED'));
        $this->assertNull($p->fresh()->captured_at);
        $this->assertSame('reference_conflict', $p->fresh()->review_reason);
        $this->assertSame('pending_payment', $c->fresh()->status);
    }

    public function test_financial_pages_include_consultation_without_exposing_other_users_data(): void
    {
        $c = $this->reserve();
        $p = $this->attempt($c);
        app(ConsultationBilling::class)->capture($p, 'VISIBLE');
        $this->actingAs($c->student)->get('/finance')->assertInertia(fn (Assert $a) => $a->has('payments.data', 1)->where('payments.data.0.amount', 1500000)->where('payments.data.0.can_allocate', false));
        $this->actingAs($this->user())->get('/finance')->assertInertia(fn (Assert $a) => $a->has('payments.data', 0));
        $this->actingAs($this->user('admin'))->post('/admin/finance/payments/'.$p->id.'/allocate')->assertSessionHasErrors('payment');
    }

    public function test_callbacks_are_scoped_to_payment_purpose(): void
    {
        $c = $this->reserve();
        $p = $this->attempt($c);
        $p->update(['track_id' => 'consultation']);
        $course = Payment::factory()->create(['track_id' => 'course']);
        $this->get('/payments/callback?trackId=consultation')->assertNotFound();
        $this->get('/consultation-payments/callback?trackId=course')->assertNotFound();
        Http::assertNothingSent();
    }

    public function test_expiration_command_releases_holds_and_preserves_attempt_history(): void
    {
        $c = $this->reserve();
        $p = $this->attempt($c);
        $this->travel(16)->minutes();
        $this->artisan('platform:expire-consultations')->assertSuccessful();
        $this->assertSame('expired', $c->fresh()->status);
        $this->assertFalse($c->slot->fresh()->booked);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('consultations', 1);
        $this->actingAs($c->student)->post('/consultations/'.$c->id.'/pay')->assertSessionHasErrors('payment');
    }

    public function test_pending_paid_reservation_blocks_student_overlap_and_unpaid_completion(): void
    {
        $c = $this->reserve();
        $s = ConsultationSlot::factory()->create();
        $this->actingAs($c->student)->post('/consultations/slots/'.$s->id.'/book', ['topic' => 'موضوع دوم'])->assertSessionHasErrors('consultation');
        $this->actingAs($c->slot->coach)->patch('/consultations/'.$c->id, ['action' => 'completed', 'version' => 1])->assertSessionHasErrors('consultation');
    }

    public function test_admin_coordination_for_paid_slot_does_not_waive_payment(): void
    {
        $r = ConsultationRequest::factory()->create();
        $s = ConsultationSlot::factory()->create(['price' => 1500000]);
        $this->actingAs($this->user('admin'))->post('/consultations/requests/'.$r->id.'/schedule', ['version' => 1, 'slot_id' => $s->id])->assertSessionHasNoErrors();
        $this->assertSame('pending_payment', Consultation::first()->status);
        $this->assertSame('scheduled', $r->fresh()->status);
    }

    public function test_a_review_required_payment_blocks_another_booking_attempt(): void
    {
        $c = $this->reserve();
        $p = $this->attempt($c);
        $this->travel(16)->minutes();
        app(ConsultationBilling::class)->capture($p, 'EXPIRED');
        $s = ConsultationSlot::factory()->create();
        $this->actingAs($c->student)->post('/consultations/slots/'.$s->id.'/book', ['topic' => 'موضوع دوم'])->assertSessionHasErrors('consultation');
    }

    public function test_another_students_expired_hold_is_visible_without_waiting_for_scheduler(): void
    {
        $c = $this->reserve();
        $this->travel(16)->minutes();
        $other = $this->user();
        $this->actingAs($other)->get('/consultations')->assertInertia(fn (Assert $a) => $a->has('slots.data', 1));
        $this->post('/consultations/slots/'.$c->consultation_slot_id.'/book', ['topic' => 'رزرو جایگزین'])->assertSessionHasNoErrors();
        $this->assertSame('expired', $c->fresh()->status);
    }

    public function test_hold_deadline_never_extends_past_session_start(): void
    {
        $s = ConsultationSlot::factory()->create(['price' => 1500000, 'starts_at' => now()->addMinutes(5), 'ends_at' => now()->addMinutes(65)]);
        $c = $this->reserve(null,$s);
        $this->assertTrue($c->expires_at->equalTo($s->starts_at));
    }
}
