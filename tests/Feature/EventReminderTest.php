<?php

namespace Tests\Feature;

use App\Jobs\SendReminderSms;
use App\Models\Consultation;
use App\Models\ConsultationSlot;
use App\Models\Enrollment;
use App\Models\EventReminder;
use App\Models\LiveSession;
use App\Models\Role;
use App\Models\SmsDelivery;
use App\Models\User;
use App\Services\EventReminders;
use App\Services\MelipayamakClient;
use App\Services\ReminderSmsService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Queue;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class EventReminderTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(now()->startOfMinute());
        Http::preventStrayRequests();
        Queue::fake();
        config(['platform.reminders.enabled' => true, 'platform.reminders.lead_minutes' => 60, 'platform.sms.driver' => 'melipayamak', 'platform.sms.melipayamak.mode' => 'rest', 'platform.sms.melipayamak.username' => 'fixture-reminder-sender', 'platform.sms.melipayamak.api_key' => 'fixture-reminder-secret', 'platform.sms.melipayamak.reminder_body_id' => 123, 'platform.sms.melipayamak.reminders_enabled' => false, 'platform.sms.melipayamak.delivery_enabled' => false]);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('key', $role)->value('id'));

        return $user;
    }

    private function consultation(array $changes = []): Consultation
    {
        $slot = ConsultationSlot::factory()->create(['starts_at' => now()->addMinutes(30), 'ends_at' => now()->addMinutes(90)]);

        return Consultation::factory()->create(['consultation_slot_id' => $slot->id, ...$changes]);
    }

    private function classroom(): array
    {
        $session = LiveSession::factory()->create(['starts_at' => now()->addMinutes(30), 'ends_at' => now()->addMinutes(90)]);
        $coach = $this->user('coach');
        $session->cohort->course->update(['coach_id' => $coach->id]);
        $student = $this->user('student');
        $enrollment = Enrollment::factory()->create(['user_id' => $student->id, 'cohort_id' => $session->cohort_id]);

        return [$session, $student, $coach, $enrollment];
    }

    private function queued(Consultation $consultation): EventReminder
    {
        config(['platform.sms.melipayamak.reminders_enabled' => true]);
        app(EventReminders::class)->prepare('consultation', $consultation->id, $consultation->student_id);

        return EventReminder::where('user_id', $consultation->student_id)->where('source_id', $consultation->id)->firstOrFail();
    }

    private function send(EventReminder $reminder): void
    {
        app(ReminderSmsService::class)->send($reminder->id, app(MelipayamakClient::class), app(EventReminders::class));
    }

    public function test_confirmed_consultation_reminds_each_current_participant_once(): void
    {
        $consultation = $this->consultation();
        $this->assertSame(2, app(EventReminders::class)->generate());
        $this->assertSame(0, app(EventReminders::class)->generate());
        $this->assertSame(2, EventReminder::count());
        $this->assertSame(1, $consultation->student->notifications()->count());
        $this->assertSame(1, $consultation->slot->coach->notifications()->count());
        foreach (EventReminder::get() as $reminder) {
            $this->assertSame('not_requested', $reminder->sms_status);
            $this->assertTrue($reminder->recipient->notifications()->whereKey($reminder->notification_id)->exists());
        }
        Http::assertNothingSent();
    }

    public function test_pending_cancelled_completed_and_out_of_window_consultations_are_not_reminded(): void
    {
        foreach (['pending_payment', 'cancelled', 'completed', 'no_show'] as $status) {
            $this->consultation(['status' => $status]);
        }
        $past = $this->consultation();
        $past->slot->update(['starts_at' => now()->subMinute()]);
        $later = $this->consultation();
        $later->slot->update(['starts_at' => now()->addMinutes(61)]);
        $this->assertSame(0, app(EventReminders::class)->generate());
        $this->assertSame(0, EventReminder::count());
    }

    public function test_published_class_reminds_active_students_and_current_coach_only(): void
    {
        [$session, $student, $coach] = $this->classroom();
        $pending = $this->user('student');
        Enrollment::factory()->create(['cohort_id' => $session->cohort_id, 'user_id' => $pending->id, 'status' => 'pending_payment']);
        $inactive = $this->user('student');
        $inactive->forceFill(['is_active' => false])->save();
        Enrollment::factory()->create(['cohort_id' => $session->cohort_id, 'user_id' => $inactive->id]);
        $this->assertSame(2, app(EventReminders::class)->generate());
        $this->assertEqualsCanonicalizing([$student->id, $coach->id], EventReminder::pluck('user_id')->all());
        $this->assertSame(0, $pending->notifications()->count());
    }

    public function test_draft_classes_unverified_recipients_and_outsiders_are_skipped(): void
    {
        [$session, $student, $coach] = $this->classroom();
        $session->update(['is_published' => false]);
        $this->assertSame(0, app(EventReminders::class)->generate());
        $session->update(['is_published' => true]);
        $student->update(['phone_verified_at' => null]);
        $this->assertFalse(app(EventReminders::class)->prepare('live_session', $session->id, $student->id));
        $this->assertFalse(app(EventReminders::class)->prepare('live_session', $session->id, $this->user('org_admin')->id));
        $this->assertSame(1, app(EventReminders::class)->generate());
        $this->assertSame($coach->id, EventReminder::sole()->user_id);
    }

    public function test_notification_payload_is_generic_and_contains_no_meeting_link_or_topic(): void
    {
        $consultation = $this->consultation(['topic' => 'PRIVATE CONSULTATION TOPIC']);
        app(EventReminders::class)->generate();
        $data = $consultation->student->notifications()->firstOrFail()->data;
        $json = json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        $this->assertStringNotContainsString('PRIVATE CONSULTATION TOPIC', $json);
        $this->assertStringNotContainsString('meet.google.com', $json);
        $this->assertStringContainsString('/reminders/', $data['url']);
        $this->assertArrayHasKey('event_at', $data);
    }

    public function test_preference_is_own_scoped_and_disables_sms_without_suppressing_internal_reminders(): void
    {
        $consultation = $this->consultation();
        $peer = $this->user('student');
        $this->actingAs($consultation->student)->patch('/notification-preferences', ['sms_reminders_enabled' => false, 'user_id' => $peer->id, 'is_active' => false])->assertRedirect();
        $this->assertFalse($consultation->student->fresh()->sms_reminders_enabled);
        $this->assertTrue($consultation->student->fresh()->is_active);
        $this->assertTrue($peer->fresh()->sms_reminders_enabled);
        config(['platform.sms.melipayamak.reminders_enabled' => true]);
        $this->assertSame(2, app(EventReminders::class)->generate());
        $this->assertSame('not_requested', EventReminder::where('user_id', $consultation->student_id)->sole()->sms_status);
        $this->get('/notifications')->assertInertia(fn (Assert $page) => $page->where('sms_reminders_enabled', false)->has('notifications.data', 1));
    }

    public function test_disabling_preference_after_queueing_skips_the_pending_sms(): void
    {
        $consultation = $this->consultation();
        $reminder = $this->queued($consultation);
        $consultation->student->forceFill(['sms_reminders_enabled' => false])->save();
        $this->send($reminder);
        $this->assertSame('skipped', $reminder->fresh()->sms_status);
        Http::assertNothingSent();
    }

    public function test_cancelled_rescheduled_or_started_events_do_not_send_queued_sms(): void
    {
        foreach (['cancel', 'reschedule', 'started'] as $action) {
            $consultation = $this->consultation();
            $reminder = $this->queued($consultation);
            if ($action === 'cancel') {
                $consultation->update(['status' => 'cancelled']);
            } elseif ($action === 'reschedule') {
                $consultation->slot->update(['starts_at' => now()->addMinutes(45)]);
            } else {
                $this->travel(31)->minutes();
            }
            $this->send($reminder);
            $this->assertSame('skipped', $reminder->fresh()->sms_status);
        }
        Http::assertNothingSent();
    }

    public function test_removed_enrollment_and_former_instructor_lose_pending_class_sms_and_deep_link_access(): void
    {
        [$session, $student, $coach, $enrollment] = $this->classroom();
        config(['platform.sms.melipayamak.reminders_enabled' => true]);
        app(EventReminders::class)->generate();
        $studentReminder = EventReminder::where('user_id', $student->id)->sole();
        $coachReminder = EventReminder::where('user_id', $coach->id)->sole();
        $enrollment->update(['status' => 'cancelled']);
        $replacement = $this->user('coach');
        $session->cohort->course->update(['coach_id' => $replacement->id]);
        $this->send($studentReminder);
        $this->send($coachReminder);
        $this->assertSame('skipped', $studentReminder->fresh()->sms_status);
        $this->assertSame('skipped', $coachReminder->fresh()->sms_status);
        $this->actingAs($student)->get('/reminders/'.$studentReminder->id)->assertForbidden();
        $this->actingAs($coach)->get('/reminders/'.$coachReminder->id)->assertForbidden();
        Http::assertNothingSent();
    }

    public function test_sms_uses_two_generic_pattern_arguments_and_links_receipt_tracking_once(): void
    {
        $this->travelTo(CarbonImmutable::parse('2026-11-15 20:15:00 UTC'));
        $consultation = $this->consultation(['topic' => 'PRIVATE TOPIC']);
        $reminder = $this->queued($consultation);
        Http::fake(['https://rest.payamak-panel.com/api/SendSMS/BaseServiceNumber' => Http::response(['RetStatus' => 1, 'Value' => '12345678901234567'])]);
        $this->send($reminder);
        $this->send($reminder);
        $this->assertSame('accepted', $reminder->fresh()->sms_status);
        $this->assertNotNull($reminder->fresh()->sms_delivery_id);
        $this->assertSame('pending', SmsDelivery::sole()->status);
        Http::assertSent(fn ($request) => $request['text'] === 'مشاوره;فردا 00:15' && $request['bodyId'] === 123 && ! str_contains($request['text'], 'PRIVATE TOPIC'));
        Http::assertSentCount(1);
    }

    public function test_ambiguous_send_is_not_automatically_retried_or_marked_delivered(): void
    {
        $reminder = $this->queued($this->consultation());
        $attempts = 0;
        Http::fake(['https://rest.payamak-panel.com/api/SendSMS/BaseServiceNumber' => function () use (&$attempts) {
            $attempts++;
            throw new ConnectionException('fixture-secret must not leak');
        }]);
        $this->send($reminder);
        $this->send($reminder);
        $this->assertSame('unknown', $reminder->fresh()->sms_status);
        $this->assertSame(0, app(ReminderSmsService::class)->dispatchPending());
        $this->assertNull($reminder->fresh()->sms_delivery_id);
        $this->assertStringNotContainsString('fixture-secret', json_encode($reminder->fresh()->toArray()));
        $this->assertSame(1, $attempts);
    }

    public function test_unconfigured_sms_is_recorded_without_removing_internal_notification(): void
    {
        $reminder = $this->queued($this->consultation());
        config(['platform.sms.melipayamak.reminder_body_id' => null]);
        $this->send($reminder);
        $this->assertSame('failed', $reminder->fresh()->sms_status);
        $this->assertSame('unconfigured', $reminder->fresh()->sms_failure_code);
        $this->assertTrue($reminder->recipient->notifications()->whereKey($reminder->notification_id)->exists());
        Http::assertNothingSent();
    }

    public function test_interrupted_sends_remain_unknown_and_are_never_requeued(): void
    {
        $reminder = EventReminder::factory()->create(['sms_status' => 'processing', 'sms_attempted_at' => now()->subMinutes(11)]);
        $this->assertSame(1, app(ReminderSmsService::class)->reconcile());
        $this->assertSame('unknown', $reminder->fresh()->sms_status);
        $this->assertSame('worker_interrupted', $reminder->fresh()->sms_failure_code);
        $this->assertSame(0, app(ReminderSmsService::class)->dispatchPending());
        $this->send($reminder);
        Http::assertNothingSent();
    }

    public function test_dispatch_command_is_idempotent_and_uses_database_sms_queue(): void
    {
        $this->consultation();
        config(['platform.sms.melipayamak.reminders_enabled' => true]);
        $this->artisan('platform:dispatch-reminders')->assertExitCode(0);
        $this->artisan('platform:dispatch-reminders')->assertExitCode(0);
        Queue::assertPushed(SendReminderSms::class, 2);
        Queue::assertPushed(SendReminderSms::class, fn ($job) => $job->connection === 'database' && $job->queue === 'sms');
        $this->assertSame(2, EventReminder::count());
        Http::assertNothingSent();
    }

    public function test_new_occurrence_gets_new_reminder_and_old_queued_occurrence_is_skipped(): void
    {
        $consultation = $this->consultation();
        $old = $this->queued($consultation);
        $consultation->slot->update(['starts_at' => now()->addMinutes(45)]);
        $this->assertTrue(app(EventReminders::class)->prepare('consultation', $consultation->id, $consultation->student_id));
        $this->assertSame(2, EventReminder::where('user_id', $consultation->student_id)->count());
        $this->send($old);
        $this->assertSame('skipped', $old->fresh()->sms_status);
        Http::assertNothingSent();
    }

    public function test_consultation_notification_finds_its_booking_on_the_correct_page(): void
    {
        $consultation = $this->consultation();
        app(EventReminders::class)->prepare('consultation', $consultation->id, $consultation->student_id);
        $reminder = EventReminder::sole();
        Consultation::factory()->count(13)->create(['student_id' => $consultation->student_id]);
        $response = $this->actingAs($consultation->student)->get('/reminders/'.$reminder->id)->assertRedirect();
        $this->assertStringContainsString('bookings_page=2', $response->headers->get('Location'));
        $this->assertStringContainsString('focus='.$consultation->id, $response->headers->get('Location'));
        $this->assertStringContainsString('#consultation-'.$consultation->id, $response->headers->get('Location'));
        $this->get('/consultations?view=student&bookings_page=2')->assertInertia(fn (Assert $page) => $page->has('bookings.data', 2));
    }

    public function test_class_notification_opens_sessions_tab_and_current_scoped_enrollment(): void
    {
        [$session, $student, $coach, $enrollment] = $this->classroom();
        app(EventReminders::class)->prepare('live_session', $session->id, $student->id);
        $reminder = EventReminder::sole();
        $response = $this->actingAs($student)->get('/reminders/'.$reminder->id)->assertRedirect();
        $this->assertStringContainsString('/learning/'.$enrollment->id.'?tab=sessions#live-'.$session->id, $response->headers->get('Location'));
    }

    public function test_notification_resolver_denies_other_recipients_and_allows_full_admin(): void
    {
        $consultation = $this->consultation();
        app(EventReminders::class)->prepare('consultation', $consultation->id, $consultation->student_id);
        $reminder = EventReminder::sole();
        $this->actingAs($consultation->slot->coach)->get('/reminders/'.$reminder->id)->assertForbidden();
        $this->actingAs($this->user('org_admin'))->get('/reminders/'.$reminder->id)->assertForbidden();
        $this->actingAs($this->user('admin'))->get('/reminders/'.$reminder->id)->assertRedirect();
    }

    public function test_global_disable_creates_no_reminders_and_skips_queued_sms(): void
    {
        $reminder = $this->queued($this->consultation());
        config(['platform.reminders.enabled' => false]);
        $this->assertSame(0, app(EventReminders::class)->generate());
        $this->send($reminder);
        $this->assertSame('skipped', $reminder->fresh()->sms_status);
        Http::assertNothingSent();
    }

    public function test_provider_rejection_is_preserved_and_not_retried_automatically(): void
    {
        $reminder = $this->queued($this->consultation());
        Http::fake(['https://rest.payamak-panel.com/api/SendSMS/BaseServiceNumber' => Http::response(['RetStatus' => -1, 'Value' => '-1'])]);
        $this->send($reminder);
        $this->send($reminder);
        $this->assertSame('failed', $reminder->fresh()->sms_status);
        $this->assertSame('provider_rejected', $reminder->fresh()->sms_failure_code);
        $this->assertTrue($reminder->recipient->notifications()->whereKey($reminder->notification_id)->exists());
        Http::assertSentCount(1);
    }

    public function test_console_submission_preserves_id_without_claiming_unsupported_delivery(): void
    {
        config(['platform.sms.melipayamak.mode' => 'console', 'platform.sms.melipayamak.api_key' => 'fixture-console-key']);
        $reminder = $this->queued($this->consultation());
        Http::fake(['https://console.melipayamak.com/api/send/shared/fixture-console-key' => Http::response(['recId' => '12345678901234567'])]);
        $this->send($reminder);
        $this->assertSame('accepted', $reminder->fresh()->sms_status);
        $this->assertSame('12345678901234567', $reminder->fresh()->sms_reference);
        $this->assertSame('console', $reminder->fresh()->delivery->provider_mode);
        $this->assertSame('pending', $reminder->fresh()->delivery->status);
        Http::assertSent(fn ($request) => count($request['args']) === 2 && $request['args'][0] === 'مشاوره');
        Http::assertSentCount(1);
    }
}
