<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\ConsultationRequest;
use App\Models\ConsultationSlot;
use App\Models\Role;
use App\Models\User;
use App\Services\ConsultationService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ConsultationCurrentAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('key', $role)->value('id'));

        return $user;
    }

    public static function managedWrites(): array
    {
        return array_map(fn ($action) => [$action], ['create', 'resolve', 'cancel', 'publish', 'meeting']);
    }

    #[DataProvider('managedWrites')]
    public function test_disabled_admin_model_cannot_mutate_consultation_state_using_stale_activity(string $action): void
    {
        $actor = $this->user('admin');
        $slot = ConsultationSlot::factory()->create(['published' => false]);
        $booking = Consultation::factory()->create(['consultation_slot_id' => $slot->id]);
        $request = ConsultationRequest::factory()->create();
        $before = [$slot->fresh()->getAttributes(), $booking->fresh()->getAttributes(), $request->fresh()->getAttributes()];
        User::whereKey($actor->id)->update(['is_active' => false]);
        $this->assertTrue($actor->is_active);
        $service = app(ConsultationService::class);
        try {
            match ($action) {
                'create' => $service->createSlot($actor, $slot->coach, CarbonImmutable::now()->addDays(2), 60, 'meet', null),
                'resolve' => $service->resolve($actor, $request, 1, 'Software-only decision'),
                'cancel' => $service->change($actor, $booking, 1, 'cancel', 'Software-only cancellation', null),
                'publish' => $service->publish($actor, $slot, true),
                'meeting' => $service->meeting($actor, $slot, 'https://meet.google.com/xyz-abcd-efg'),
            };
            $this->fail('A disabled account must not commit a consultation decision.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertSame($before, [$slot->fresh()->getAttributes(), $booking->fresh()->getAttributes(), $request->fresh()->getAttributes()]);
        $this->assertDatabaseCount('consultation_slots', 1);
        $this->assertDatabaseCount('audit_events', 0);
        Notification::assertNothingSent();
    }

    public function test_disabled_owner_cannot_cancel_using_loaded_booking_or_close_own_pending_request(): void
    {
        $booking = Consultation::factory()->create();
        $owner = $booking->student;
        $request = ConsultationRequest::factory()->create(['student_id' => $owner->id]);
        User::whereKey($owner->id)->update(['is_active' => false]);
        $this->assertFalse($owner->fresh()->is_active);
        foreach (['cancel', 'resolve'] as $action) {
            try {
                if ($action === 'cancel') {
                    app(ConsultationService::class)->change($owner, $booking, 1, 'cancel', 'Software-only reason', null);
                } else {
                    app(ConsultationService::class)->resolve($owner, $request, 1, 'Software-only reason');
                }
                $this->fail('The owner account is currently disabled.');
            } catch (HttpException $e) {
                $this->assertSame(403, $e->getStatusCode());
            }
        }
        $this->assertSame('confirmed', $booking->fresh()->status);
        $this->assertSame('pending', $request->fresh()->status);
        $this->assertDatabaseCount('audit_events', 0);
        Notification::assertNothingSent();
    }

    public function test_active_owner_keeps_own_cancellation_and_request_withdrawal_after_student_role_loss(): void
    {
        $booking = Consultation::factory()->create();
        $owner = $booking->student;
        $request = ConsultationRequest::factory()->create(['student_id' => $owner->id]);
        $owner->roles()->detach();
        app(ConsultationService::class)->change($owner, $booking, 1, 'cancel', 'Owner withdrawal', null);
        app(ConsultationService::class)->resolve($owner, $request, 1, 'Owner withdrawal');
        $this->assertSame('cancelled', $booking->fresh()->status);
        $this->assertSame('withdrawn', $request->fresh()->status);
        $this->assertSame($owner->id, $request->fresh()->resolved_by);
    }

    public function test_current_coach_can_manage_own_slot_and_current_admin_can_create_a_paid_slot(): void
    {
        $slot = ConsultationSlot::factory()->create(['published' => false]);
        $coach = $slot->coach;
        $service = app(ConsultationService::class);
        $service->publish($coach, $slot, true);
        $service->meeting($coach, $slot, 'https://meet.google.com/xyz-abcd-efg');
        $this->assertTrue($slot->fresh()->published);
        $this->assertSame('https://meet.google.com/xyz-abcd-efg', $slot->fresh()->meeting_url);
        $created = $service->createSlot($this->user('admin'), $coach, CarbonImmutable::now()->addDays(2), 60, 'meet', null, 100000);
        $this->assertSame(100000, $created->price);
        $this->assertSame($coach->id, $created->coach_id);
    }
}
