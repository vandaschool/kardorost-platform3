<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\ConsultationRequest;
use App\Models\ConsultationSlot;
use App\Models\Role;
use App\Models\User;
use App\Services\ConsultationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class ConsultationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(now()->startOfMinute());
    }

    private function user(string $role = 'student'): User
    {
        $u = User::factory()->create();
        $u->roles()->attach(Role::where('key', $role)->value('id'));

        return $u;
    }

    private function book(?User $u = null, ?ConsultationSlot $s = null): Consultation
    {
        return app(ConsultationService::class)->book($u ?? $this->user(), $s ?? ConsultationSlot::factory()->create(), 'موضوع خصوصی جلسه');
    }

    public function test_coach_can_publish_a_free_slot_using_tehran_time(): void
    {
        $c = $this->user('coach');
        $this->actingAs($c)->post('/consultations/slots', ['coach_id' => $c->id, 'date' => now()->addDay()->toDateString(), 'time' => '14:30', 'minutes' => 60, 'provider' => 'meet', 'meeting_url' => 'https://meet.google.com/abc-defg-hij', 'free_confirmation' => true])->assertSessionHasNoErrors();
        $s = ConsultationSlot::firstOrFail();
        $this->assertSame('11:00', $s->starts_at->utc()->format('H:i'));
        $this->assertSame(60, (int) $s->starts_at->diffInMinutes($s->ends_at));
        $this->actingAs($c)->get('/consultations')->assertOk();
    }

    public function test_coach_cannot_publish_for_another_coach_or_overlap_existing_slot(): void
    {
        $s = ConsultationSlot::factory()->create();
        $data = ['coach_id' => $s->coach_id, 'date' => $s->starts_at->setTimezone('Asia/Tehran')->toDateString(), 'time' => $s->starts_at->setTimezone('Asia/Tehran')->format('H:i'), 'minutes' => 60, 'provider' => 'meet', 'free_confirmation' => true];
        $this->actingAs($this->user('coach'))->post('/consultations/slots', $data)->assertForbidden();
        $this->actingAs($s->coach)->post('/consultations/slots', $data)->assertSessionHasErrors('consultation');
        $this->assertDatabaseCount('consultation_slots', 1);
    }

    public function test_slot_publication_requires_explicit_free_confirmation_and_allowed_url(): void
    {
        $c = $this->user('coach');
        $data = ['coach_id' => $c->id, 'date' => now()->addDay()->toDateString(), 'time' => '14:30', 'minutes' => 60, 'provider' => 'meet', 'meeting_url' => 'https://evil.example/'];
        $this->actingAs($c)->post('/consultations/slots', $data)->assertSessionHasErrors('free_confirmation');
        $this->post('/consultations/slots', [...$data, 'free_confirmation' => true])->assertSessionHasErrors('meeting_url');
        $this->assertDatabaseCount('consultation_slots', 0);
    }

    public function test_public_slots_do_not_leak_links_or_other_students_topics(): void
    {
        $s = ConsultationSlot::factory()->create();
        $b = $this->book();
        $this->actingAs($this->user())->get('/consultations')->assertInertia(fn (Assert $p) => $p->component('Consultations')->has('slots.data', 1)->where('slots.data.0.meeting_url', null)->has('bookings.data', 0)->has('requests.data', 0));
        $this->assertSame(2, \DB::table('notifications')->count());
    }

    public function test_booking_is_exclusive_and_uses_server_student_identity(): void
    {
        $s = ConsultationSlot::factory()->create();
        $u = $this->user();
        $this->actingAs($u)->post('/consultations/slots/'.$s->id.'/book', ['topic' => 'راهنمایی شغلی', 'student_id' => $this->user()->id])->assertSessionHasNoErrors();
        $this->assertSame($u->id, Consultation::first()->student_id);
        $this->actingAs($this->user())->post('/consultations/slots/'.$s->id.'/book', ['topic' => 'موضوع دوم'])->assertSessionHasErrors('consultation');
        $this->assertDatabaseCount('consultations', 1);
    }

    public function test_student_cannot_book_two_overlapping_consultations_with_different_coaches(): void
    {
        $u = $this->user();
        $s = ConsultationSlot::factory()->create();
        $this->book($u, $s);
        $other = ConsultationSlot::factory()->create(['starts_at' => $s->starts_at->addMinutes(30), 'ends_at' => $s->ends_at->addMinutes(30)]);
        $this->actingAs($u)->post('/consultations/slots/'.$other->id.'/book', ['topic' => 'موضوع دوم'])->assertSessionHasErrors('consultation');
        $this->assertFalse($other->fresh()->booked);
    }

    public function test_hidden_past_and_inactive_coach_slots_cannot_be_booked(): void
    {
        $u = $this->user();
        $s = ConsultationSlot::factory()->create(['published' => false]);
        $this->actingAs($u)->post('/consultations/slots/'.$s->id.'/book', ['topic' => 'موضوع دوم'])->assertSessionHasErrors('consultation');
        $s->update(['published' => true, 'starts_at' => now()->subHour(), 'ends_at' => now()]);
        $this->post('/consultations/slots/'.$s->id.'/book', ['topic' => 'موضوع دوم'])->assertSessionHasErrors('consultation');
        $s->update(['starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour()]);
        $s->coach->forceFill(['is_active' => false])->save();
        $this->post('/consultations/slots/'.$s->id.'/book', ['topic' => 'موضوع دوم'])->assertSessionHasErrors('consultation');
        $this->get('/consultations')->assertInertia(fn (Assert $p) => $p->has('slots.data', 0));
    }

    public function test_cancel_releases_slot_but_keeps_history_and_prevents_stale_decisions(): void
    {
        $b = $this->book();
        $this->actingAs($b->student)->patch('/consultations/'.$b->id, ['version' => 1, 'action' => 'cancel', 'reason' => 'زمان دیگری نیاز دارم'])->assertSessionHasNoErrors();
        $this->assertSame('cancelled', $b->fresh()->status);
        $this->assertFalse($b->slot->fresh()->booked);
        $new = $this->book(null, $b->slot);
        $this->assertSame('confirmed', $new->status);
        $this->patch('/consultations/'.$b->id, ['version' => 1, 'action' => 'cancel', 'reason' => 'دوباره لغو شود'])->assertSessionHasErrors('consultation');
        $this->assertTrue($b->slot->fresh()->booked);
        $this->assertDatabaseCount('consultations', 2);
    }

    public function test_only_participants_and_admin_can_change_consultation(): void
    {
        $b = $this->book();
        $payload = ['version' => 1, 'action' => 'cancel', 'reason' => 'زمان دیگری نیاز دارم'];
        foreach (['student', 'coach', 'org_admin'] as $role) {
            $this->actingAs($this->user($role))->patch('/consultations/'.$b->id, $payload)->assertForbidden();
        }
        $this->actingAs($this->user('admin'))->patch('/consultations/'.$b->id, $payload)->assertSessionHasNoErrors();
    }

    public function test_completion_requires_coach_and_finished_session_and_notifies_once(): void
    {
        $b = $this->book();
        $data = ['version' => 1, 'action' => 'completed', 'shared_summary' => 'خلاصه قابل نمایش'];
        $this->actingAs($b->student)->patch('/consultations/'.$b->id, $data)->assertForbidden();
        $this->actingAs($b->slot->coach)->patch('/consultations/'.$b->id, $data)->assertSessionHasErrors('consultation');
        $this->travelTo($b->slot->ends_at->addSecond());
        $this->patch('/consultations/'.$b->id, $data)->assertSessionHasNoErrors();
        $this->assertSame('completed', $b->fresh()->status);
        $this->patch('/consultations/'.$b->id, $data)->assertSessionHasErrors('consultation');
        $this->assertSame(4, \DB::table('notifications')->count());
        $this->actingAs($b->student)->get('/consultations')->assertInertia(fn (Assert $p) => $p->where('bookings.data.0.shared_summary', 'خلاصه قابل نمایش'));
    }

    public function test_join_window_and_identity_are_checked_without_exposing_link_in_student_json(): void
    {
        $b = $this->book();
        $url = '/consultations/'.$b->id.'/join';
        $this->actingAs($b->student)->get($url)->assertForbidden();
        $this->travelTo($b->slot->starts_at->subMinutes(15));
        $this->get($url)->assertRedirect($b->slot->meeting_url);
        $this->actingAs($this->user())->get($url)->assertForbidden();
        $this->actingAs($this->user('org_admin'))->get($url)->assertForbidden();
        $this->actingAs($b->slot->coach)->get($url)->assertRedirect($b->slot->meeting_url);
        $this->travelTo($b->slot->ends_at);
        $this->actingAs($b->student)->get($url)->assertForbidden();
    }

    public function test_cancelled_consultation_never_inherits_new_booking_link_access(): void
    {
        $b = $this->book();
        app(ConsultationService::class)->change($b->student, $b, 1, 'cancel', 'تغییر برنامه', null);
        $this->book(null, $b->slot);
        $this->travelTo($b->slot->starts_at);
        $this->actingAs($b->student)->get('/consultations/'.$b->id.'/join')->assertForbidden();
    }

    public function test_request_is_private_and_only_one_pending_request_is_allowed(): void
    {
        $u = $this->user();
        $d = ['topic' => 'مشاوره انتخاب شغل', 'availability' => 'عصر روزهای زوج'];
        $this->actingAs($u)->post('/consultations/requests', $d)->assertSessionHasNoErrors();
        $this->post('/consultations/requests', $d)->assertSessionHasErrors('consultation');
        $this->assertDatabaseCount('consultation_requests', 1);
        $this->actingAs($this->user())->get('/consultations')->assertInertia(fn (Assert $p) => $p->has('requests.data', 0));
        $this->actingAs($this->user('coach'))->get('/consultations?view=coach')->assertInertia(fn (Assert $p) => $p->where('requests', null));
        $this->actingAs($this->user('org_admin'))->get('/consultations?view=admin')->assertForbidden();
    }

    public function test_admin_can_schedule_pending_request_and_stale_resolution_cannot_undo_it(): void
    {
        $r = ConsultationRequest::factory()->create();
        $s = ConsultationSlot::factory()->create();
        $this->actingAs($this->user('admin'))->post('/consultations/requests/'.$r->id.'/schedule', ['version' => 1, 'slot_id' => $s->id])->assertSessionHasNoErrors();
        $this->assertSame('scheduled', $r->fresh()->status);
        $this->assertSame($r->student_id, Consultation::first()->student_id);
        $this->post('/consultations/requests/'.$r->id.'/resolve', ['version' => 1, 'reason' => 'رد درخواست قدیمی'])->assertSessionHasErrors('consultation');
        $this->assertTrue($s->fresh()->booked);
    }

    public function test_student_cannot_schedule_request_or_resolve_another_students_request(): void
    {
        $r = ConsultationRequest::factory()->create();
        $s = ConsultationSlot::factory()->create();
        $this->actingAs($r->student)->post('/consultations/requests/'.$r->id.'/schedule', ['version' => 1, 'slot_id' => $s->id])->assertForbidden();
        $this->actingAs($this->user())->post('/consultations/requests/'.$r->id.'/resolve', ['version' => 1, 'reason' => 'انصراف از درخواست'])->assertForbidden();
        $this->actingAs($r->student)->post('/consultations/requests/'.$r->id.'/resolve', ['version' => 1, 'reason' => 'انصراف از درخواست'])->assertSessionHasNoErrors();
        $this->assertSame('withdrawn', $r->fresh()->status);
    }

    public function test_taken_slot_does_not_close_human_request(): void
    {
        $b = $this->book();
        $r = ConsultationRequest::factory()->create();
        $this->actingAs($this->user('admin'))->post('/consultations/requests/'.$r->id.'/schedule', ['version' => 1, 'slot_id' => $b->consultation_slot_id])->assertSessionHasErrors('consultation');
        $this->assertSame('pending', $r->fresh()->status);
    }

    public function test_slot_visibility_and_link_edits_are_scoped_and_validated(): void
    {
        $s = ConsultationSlot::factory()->create();
        $this->actingAs($this->user('coach'))->patch('/consultations/slots/'.$s->id, ['published' => false])->assertForbidden();
        $this->patch('/consultations/slots/'.$s->id.'/meeting', ['meeting_url' => 'https://meet.google.com/new-link'])->assertForbidden();
        $this->actingAs($s->coach)->patch('/consultations/slots/'.$s->id.'/meeting', ['meeting_url' => 'https://meet.google.com.evil.example'])->assertSessionHasErrors('meeting_url');
        $this->patch('/consultations/slots/'.$s->id, ['published' => false])->assertSessionHasNoErrors();
        $this->assertFalse($s->fresh()->published);
        $this->patch('/consultations/slots/'.$s->id,['published' => true])->assertSessionHasNoErrors();
        $b = $this->book(null,$s);
        $this->patch('/consultations/slots/'.$s->id,['published' => false])->assertSessionHasErrors('consultation');
    }
}
