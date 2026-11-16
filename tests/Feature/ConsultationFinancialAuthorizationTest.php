<?php

namespace Tests\Feature;

use App\Models\Consultation;
use App\Models\ConsultationGrant;
use App\Models\ConsultationSlot;
use App\Models\Role;
use App\Models\User;
use App\Services\ConsultationBilling;
use App\Services\ConsultationPackages;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Notification;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class ConsultationFinancialAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::preventStrayRequests();
        Notification::fake();
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('key', $role)->value('id'));

        return $user;
    }

    private function data(): array
    {
        return ['title' => 'Software-only manual grant', 'source_reference' => 'SOFTWARE-ONLY-62', 'total_sessions' => 3, 'session_minutes' => 60, 'valid_until' => now()->addMonth()->toDateString(), 'revision' => 1, 'active' => false];
    }

    public static function existingRecords(): array
    {
        return [[false], [true]];
    }

    #[DataProvider('existingRecords')]
    public function test_disabled_admin_cannot_create_or_edit_manual_grant_using_stale_activity(bool $existing): void
    {
        $admin = $this->user('admin');
        $grant = $existing ? ConsultationGrant::factory()->create() : null;
        $student = $grant?->student ?? $this->user('student');
        $coach = $grant?->coach ?? $this->user('coach');
        $before = $grant?->fresh()->getAttributes();
        User::whereKey($admin->id)->update(['is_active' => false]);
        $this->assertTrue($admin->is_active);
        try {
            app(ConsultationPackages::class)->configure($admin, $student, $coach, $this->data(), $grant);
            $this->fail('The current disabled admin cannot configure grants.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertDatabaseCount('consultation_grants', $existing ? 1 : 0);
        $this->assertSame($before, $grant?->fresh()->getAttributes());
        $this->assertDatabaseCount('payments', 0);
        $this->assertDatabaseCount('audit_events', 0);
        Notification::assertNothingSent();
        Http::assertNothingSent();
    }

    private function reservation(): Consultation
    {
        return Consultation::factory()->create(['consultation_slot_id' => ConsultationSlot::factory()->state(['price' => 1000000]), 'status' => 'pending_payment', 'amount' => 1000000, 'listed_amount' => 1000000, 'expires_at' => now()->addMinutes(10)]);
    }

    #[DataProvider('existingRecords')]
    public function test_disabled_student_cannot_create_or_reuse_pending_payment_using_stale_activity(bool $existing): void
    {
        $booking = $this->reservation();
        $student = $booking->student;
        $pending = $existing ? app(ConsultationBilling::class)->attempt($student, $booking) : null;
        $before = $booking->fresh()->getAttributes();
        User::whereKey($student->id)->update(['is_active' => false]);
        $this->assertTrue($student->is_active);
        try {
            app(ConsultationBilling::class)->attempt($student, $booking);
            $this->fail('The current disabled student cannot initiate or reuse a charge.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
        $this->assertDatabaseCount('payments', $existing ? 1 : 0);
        $this->assertSame($before, $booking->fresh()->getAttributes());
        if ($pending) {
            $this->assertSame('pending', $pending->fresh()->status);
            $this->assertSame(1000000, $pending->fresh()->amount);
        }
        Http::assertNothingSent();
    }

    public function test_current_admin_can_configure_manual_grant_without_creating_financial_records(): void
    {
        $service = app(ConsultationPackages::class);
        $admin = $this->user('admin');
        $grant = $service->configure($admin, $this->user('student'), $this->user('coach'), $this->data());
        $this->assertSame(3, $grant->total_sessions);
        $service->configure($admin, $grant->student, $grant->coach, [...$this->data(), 'total_sessions' => 4], $grant);
        $this->assertSame(2, $grant->fresh()->revision);
        $this->assertSame(4, $grant->fresh()->total_sessions);
        $this->assertFalse($grant->fresh()->active);
        $this->assertDatabaseCount('payments', 0);
        Http::assertNothingSent();
    }

    public function test_current_student_reuses_one_pending_charge_with_server_booking_amount(): void
    {
        $booking = $this->reservation();
        $service = app(ConsultationBilling::class);
        $first = $service->attempt($booking->student, $booking);
        $second = $service->attempt($booking->student, $booking);
        $this->assertSame($first->id, $second->id);
        $this->assertSame(1000000, $second->amount);
        $this->assertSame($booking->student_id, $second->user_id);
        $this->assertDatabaseCount('payments', 1);
        Http::assertNothingSent();
    }
}
