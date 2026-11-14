<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\ConsultationGrant;
use App\Models\ConsultationGrantUse;
use App\Models\ConsultationRequest;
use App\Models\ConsultationSlot;
use App\Models\Payment;
use App\Models\Role;
use App\Models\User;
use App\Services\ConsultationBilling;
use App\Services\ConsultationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ConsultationGrantTest extends TestCase
{
    use RefreshDatabase;

    private ConsultationGrant $grant;

    private User $admin;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        $this->travelTo(now()->startOfMinute());
        $this->admin = $this->user('admin');
        $this->grant = ConsultationGrant::factory()->create();
        $this->student = $this->grant->student;
        config(['platform.zibal.enabled' => false, 'platform.zibal.merchant' => null]);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('key', $role)->value('id'));

        return $user;
    }

    private function slot(int $day = 1, array $override = []): ConsultationSlot
    {
        return ConsultationSlot::factory()->create([...['coach_id' => $this->grant->coach_id, 'starts_at' => now()->addDays($day), 'ends_at' => now()->addDays($day)->addHour(), 'price' => 1000000], ...$override]);
    }

    private function book(?ConsultationSlot $slot = null): Consultation
    {
        return app(ConsultationService::class)->book($this->student, $slot ?? $this->slot(), 'موضوع جلسه پکیج', grantId: $this->grant->id);
    }

    private function creation(array $override = []): array
    {
        return [...['student_phone' => $this->student->phone, 'coach_id' => $this->grant->coach_id, 'title' => 'پکیج تازه', 'source_reference' => 'ORDER-NEW-1', 'total_sessions' => 3, 'session_minutes' => 60, 'valid_until' => now()->addMonth()->toDateString(), 'source_reviewed' => true], ...$override];
    }

    private function update(array $override = []): array
    {
        return [...['revision' => $this->grant->fresh()->revision, 'total_sessions' => 2, 'valid_until' => now()->addMonth()->toDateString(), 'active' => true], ...$override];
    }

    public function test_admin_grant_requires_reviewed_source_and_creates_no_payment(): void
    {
        $this->actingAs($this->admin)->post('/admin/consultation-grants', $this->creation(['student_phone' => '09129998877']))->assertSessionHasErrors('student_phone');
        $this->actingAs($this->admin)->post('/admin/consultation-grants', $this->creation(['source_reviewed' => false]))->assertSessionHasErrors('source_reviewed');
        $this->post('/admin/consultation-grants', $this->creation())->assertSessionHasNoErrors();
        $this->post('/admin/consultation-grants', $this->creation())->assertSessionHasErrors('package');
        $this->assertDatabaseCount('consultation_grants', 2);
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseHas('consultation_grants', ['student_id' => $this->student->id, 'source_reference' => 'ORDER-NEW-1', 'total_sessions' => 3]);
    }

    public function test_only_admin_can_create_edit_or_view_other_students_grants(): void
    {
        foreach (['student', 'coach', 'org_admin'] as $role) {
            $this->actingAs($this->user($role))->post('/admin/consultation-grants', $this->creation())->assertForbidden();
            $this->patch('/admin/consultation-grants/'.$this->grant->id, $this->update())->assertForbidden();
            $this->get('/admin/consultation-grants')->assertForbidden();
        }
        $this->actingAs($this->user('student'))->get('/consultation-grants')->assertInertia(fn (Assert $page) => $page->has('grants.data', 0));
        $this->actingAs($this->student)->get('/consultation-grants')->assertInertia(fn (Assert $page) => $page->where('grants.data.0.remaining', 2)->missing('grants.data.0.source_reference')->missing('grants.data.0.student'));
    }

    public function test_package_booking_is_confirmed_without_gateway_and_keeps_listed_price_snapshot(): void
    {
        $slot = $this->slot();
        $this->actingAs($this->student)->post('/consultations/slots/'.$slot->id.'/book', ['topic' => 'جلسه پکیج', 'grant_id' => $this->grant->id, 'amount' => 1])->assertSessionHasNoErrors();
        $item = Consultation::firstOrFail();
        $this->assertSame('confirmed', $item->status);
        $this->assertSame(0, $item->amount);
        $this->assertSame(1000000, $item->listed_amount);
        $this->assertSame(1000000, $slot->fresh()->price);
        $this->assertNull($item->expires_at);
        $this->assertNull($item->settled_payment_id);
        $this->assertSame(1, $this->grant->fresh()->remaining());
        $this->assertDatabaseCount('payments', 0);
        $this->get('/consultations')->assertInertia(fn (Assert $page) => $page->where('bookings.data.0.package_title', $this->grant->title)->where('bookings.data.0.can_pay', false));
        Http::assertNothingSent();
    }

    public function test_foreign_inactive_expired_wrong_coach_duration_or_free_slot_cannot_consume_credit(): void
    {
        $slot = $this->slot();
        $this->actingAs($this->user('student'))->post('/consultations/slots/'.$slot->id.'/book', ['topic' => 'غیرمجاز', 'grant_id' => $this->grant->id])->assertNotFound();
        foreach ([['active' => false], ['valid_until' => now()->subDay()->toDateString()]] as $attributes) {
            $this->grant->update($attributes);
            $this->actingAs($this->student)->post('/consultations/slots/'.$slot->id.'/book', ['topic' => 'درخواست رزرو', 'grant_id' => $this->grant->id])->assertSessionHasErrors('package');
            $this->grant->update(['active' => true, 'valid_until' => now()->addMonth()->toDateString()]);
        }
        foreach ([$this->slot(2, ['coach_id' => $this->user('coach')->id]), $this->slot(3, ['ends_at' => now()->addDays(3)->addMinutes(30)]), $this->slot(4, ['price' => 0])] as $invalid) {
            $this->post('/consultations/slots/'.$invalid->id.'/book', ['topic' => 'درخواست رزرو', 'grant_id' => $this->grant->id])->assertSessionHasErrors('package');
        }
        $this->assertDatabaseCount('consultation_grant_uses', 0);
        $this->assertDatabaseCount('consultations', 0);
    }

    public function test_session_must_finish_before_entitlement_expiry_date_in_tehran(): void
    {
        $this->grant->update(['valid_until' => now()->addDay()->toDateString()]);
        $start = now()->addDay()->endOfDay()->subMinutes(30)->startOfMinute();
        $slot = $this->slot(1, ['starts_at' => $start, 'ends_at' => $start->copy()->addHour()]);
        $this->actingAs($this->student)->post('/consultations/slots/'.$slot->id.'/book', ['topic' => 'عبور از مهلت', 'grant_id' => $this->grant->id])->assertSessionHasErrors('package');
        $this->assertSame(2, $this->grant->fresh()->remaining());
    }

    public function test_last_credit_cannot_be_used_on_another_slot_after_consumption(): void
    {
        $this->grant->update(['total_sessions' => 1]);
        $this->book();
        $slot = $this->slot(2);
        $this->actingAs($this->student)->post('/consultations/slots/'.$slot->id.'/book', ['topic' => 'رزرو دوم', 'grant_id' => $this->grant->id])->assertSessionHasErrors('package');
        $this->assertDatabaseCount('consultation_grant_uses', 1);
        $this->assertFalse($slot->fresh()->booked);
    }

    public function test_cancellation_before_start_returns_credit_once_without_bank_refund(): void
    {
        $item = $this->book();
        $this->actingAs($this->student)->patch('/consultations/'.$item->id, ['version' => 1, 'action' => 'cancel', 'reason' => 'تغییر زمان برنامه'])->assertSessionHasNoErrors();
        $this->patch('/consultations/'.$item->id, ['version' => 1, 'action' => 'cancel', 'reason' => 'تکرار فرم'])->assertSessionHasErrors('consultation');
        $this->assertSame(2, $this->grant->fresh()->remaining());
        $use = ConsultationGrantUse::firstOrFail();
        $this->assertNotNull($use->released_at);
        $this->assertSame($this->student->id, $use->released_by);
        $this->assertNull($item->fresh()->refund_status);
        $this->assertDatabaseCount('consultation_grant_uses', 1);
        $this->assertDatabaseCount('refunds', 0);
    }

    public function test_completion_and_no_show_both_keep_credit_consumed(): void
    {
        $completed = $this->book();
        $noShow = $this->book($this->slot(2));
        $this->travel(3)->days();
        app(ConsultationService::class)->change($this->admin, $completed, 1, 'completed', null, 'جلسه برگزار شد');
        app(ConsultationService::class)->change($this->admin, $noShow, 1, 'no_show', null, 'عدم حضور');
        $this->assertSame(0, $this->grant->fresh()->remaining());
        $this->assertSame(2, ConsultationGrantUse::whereNull('released_at')->count());
        $this->actingAs($this->student)->patch('/consultations/'.$completed->id, ['version' => 2, 'action' => 'cancel', 'reason' => 'لغو پس از پایان'])->assertSessionHasErrors('consultation');
    }

    public function test_grant_update_is_versioned_and_cannot_drop_total_below_used_credit_or_rewrite_source(): void
    {
        $this->book();
        $this->book($this->slot(2));
        $this->actingAs($this->admin)->patch('/admin/consultation-grants/'.$this->grant->id, $this->update(['total_sessions' => 1]))->assertSessionHasErrors('package');
        $this->patch('/admin/consultation-grants/'.$this->grant->id, $this->update(['revision' => 99]))->assertSessionHasErrors('package');
        $this->patch('/admin/consultation-grants/'.$this->grant->id, $this->update(['total_sessions' => 3, 'coach_id' => $this->user('coach')->id, 'source_reference' => 'OVERRIDE', 'title' => 'تغییر گذشته']))->assertSessionHasNoErrors();
        $this->assertSame($this->grant->coach_id, $this->grant->fresh()->coach_id);
        $this->assertSame($this->grant->source_reference, $this->grant->fresh()->source_reference);
        $this->assertSame($this->grant->title, $this->grant->fresh()->title);
        $this->assertSame(1, $this->grant->fresh()->remaining());
    }

    public function test_expiring_or_disabling_grant_does_not_cancel_existing_booking_or_extend_returned_credit(): void
    {
        $item = $this->book();
        $this->actingAs($this->admin)->patch('/admin/consultation-grants/'.$this->grant->id, $this->update(['active' => false, 'valid_until' => now()->subDay()->toDateString()]))->assertSessionHasNoErrors();
        $this->assertSame('confirmed', $item->fresh()->status);
        app(ConsultationService::class)->change($this->student, $item, 1, 'cancel', 'لغو با سهمیه منقضی', null);
        $this->assertSame(2, $this->grant->fresh()->remaining());
        $this->assertFalse($this->grant->fresh()->active);
        $this->assertSame(now()->subDay()->toDateString(), $this->grant->fresh()->valid_until->toDateString());
    }

    public function test_human_request_remembers_students_package_without_reserving_credit(): void
    {
        $this->actingAs($this->student)->post('/consultations/requests', ['topic' => 'هماهنگی جلسه', 'availability' => 'عصرها مناسب است', 'grant_id' => $this->grant->id])->assertSessionHasNoErrors();
        $request = ConsultationRequest::firstOrFail();
        $this->assertSame($this->grant->id, $request->consultation_grant_id);
        $this->assertSame(2, $this->grant->fresh()->remaining());
        $slot = $this->slot();
        $this->actingAs($this->admin)->post('/consultations/requests/'.$request->id.'/schedule', ['version' => 1, 'slot_id' => $slot->id])->assertSessionHasNoErrors();
        $this->assertSame($this->grant->id, Consultation::first()->consultation_grant_id);
        $this->assertSame('scheduled', $request->fresh()->status);
    }

    public function test_coordination_cannot_fall_back_to_charging_when_requested_package_runs_out(): void
    {
        $this->grant->update(['total_sessions' => 1]);
        $request = app(ConsultationService::class)->request($this->student, 'موضوع هماهنگی', 'عصرها مناسب است', $this->grant->id);
        $this->book();
        config(['platform.zibal.enabled' => true, 'platform.zibal.merchant' => 'test']);
        $slot = $this->slot(2);
        $this->actingAs($this->admin)->post('/consultations/requests/'.$request->id.'/schedule', ['version' => 1, 'slot_id' => $slot->id, 'grant_id' => null])->assertSessionHasErrors('package');
        $this->assertSame('pending', $request->fresh()->status);
        $this->assertFalse($slot->fresh()->booked);
        $this->assertDatabaseCount('consultations', 1);
    }

    public function test_admin_cannot_inject_a_package_into_coordination_not_selected_by_student(): void
    {
        $request = app(ConsultationService::class)->request($this->student, 'موضوع هماهنگی', 'عصرها مناسب است');
        config(['platform.zibal.enabled' => true, 'platform.zibal.merchant' => 'test']);
        $this->actingAs($this->admin)->post('/consultations/requests/'.$request->id.'/schedule', ['version' => 1, 'slot_id' => $this->slot()->id, 'grant_id' => $this->grant->id])->assertSessionHasNoErrors();
        $this->assertNull(Consultation::first()->consultation_grant_id);
        $this->assertSame('pending_payment', Consultation::first()->status);
        $this->assertSame(2, $this->grant->fresh()->remaining());
    }

    public function test_package_cannot_be_paid_again_and_unexpected_received_money_is_retained_for_review(): void
    {
        $item = $this->book();
        $this->actingAs($this->student)->post('/consultations/'.$item->id.'/pay')->assertSessionHasErrors('payment');
        $payment = Payment::create(['user_id' => $this->student->id, 'enrollment_id' => null, 'consultation_id' => $item->id, 'amount' => 1000000, 'method' => 'gateway', 'status' => 'pending']);
        $this->assertFalse(app(ConsultationBilling::class)->capture($payment, 'UNEXPECTED-BANK'));
        $this->assertSame('review_required', $payment->fresh()->status);
        $this->assertSame('consultation_package_payment', $payment->fresh()->review_reason);
        $this->assertNotNull($payment->fresh()->captured_at);
        $this->assertNull($item->fresh()->settled_payment_id);
        $this->assertSame('confirmed', $item->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_overlapping_student_booking_rolls_back_without_consuming_package(): void
    {
        $slot = $this->slot();
        $other = ConsultationSlot::factory()->create(['starts_at' => $slot->starts_at, 'ends_at' => $slot->ends_at]);
        app(ConsultationService::class)->book($this->student, $other, 'جلسه رایگان قبلی');
        $this->actingAs($this->student)->post('/consultations/slots/'.$slot->id.'/book', ['topic' => 'تداخل با جلسه قبلی', 'grant_id' => $this->grant->id])->assertSessionHasErrors('consultation');
        $this->assertSame(2, $this->grant->fresh()->remaining());
        $this->assertDatabaseCount('consultation_grant_uses', 0);
        $this->assertFalse($slot->fresh()->booked);
    }

    public function test_existing_gateway_hold_cannot_silently_convert_to_package_booking(): void
    {
        config(['platform.zibal.enabled' => true, 'platform.zibal.merchant' => 'test']);
        $slot = $this->slot();
        app(ConsultationService::class)->book($this->student, $slot, 'رزرو با پرداخت');
        $this->actingAs($this->student)->post('/consultations/slots/'.$slot->id.'/book', ['topic' => 'تغییر روش رزرو', 'grant_id' => $this->grant->id])->assertSessionHasErrors('consultation');
        $this->assertSame(2, $this->grant->fresh()->remaining());
        $this->assertDatabaseCount('consultation_grant_uses', 0);
    }

    public function test_booking_options_only_include_matching_owned_packages_and_rollback_guard_preserves_entitlements(): void
    {
        $slot = $this->slot();
        ConsultationGrant::factory()->create();
        $this->actingAs($this->student)->get('/consultations')->assertInertia(fn (Assert $page) => $page->has('grants', 1)->where('slots.data.0.grant_ids.0', $this->grant->id)->missing('grants.0.source_reference'));
        $migration = require database_path('migrations/2026_11_14_105024_create_consultation_grants_table.php');
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }
}
