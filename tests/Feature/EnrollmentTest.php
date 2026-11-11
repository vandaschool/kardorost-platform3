<?php

namespace Tests\Feature;

use App\Models\Cohort;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\Role;
use App\Models\User;
use App\Services\EnrollmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class EnrollmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
    }

    private function student(): User
    {
        $u = User::factory()->create();
        $u->roles()->attach(Role::where('key', 'student')->value('id'));

        return $u;
    }

    private function cohort(int $price = 1000000, int $capacity = 1): Cohort
    {
        $course = Course::create(['title' => 'خودشناسی', 'slug' => 'test-'.fake()->uuid(), 'description' => 'آموزش', 'price' => $price, 'is_published' => true]);

        return Cohort::create(['course_id' => $course->id, 'title' => 'نوبت اول', 'format' => 'hybrid', 'capacity' => $capacity]);
    }

    public function test_capacity_cannot_be_oversold_and_repeated_request_is_idempotent(): void
    {
        $cohort = $this->cohort();
        $u = $this->student();
        $this->actingAs($u)->post('/cohorts/'.$cohort->id.'/enroll')->assertRedirect('/learning');
        $this->post('/cohorts/'.$cohort->id.'/enroll')->assertRedirect('/learning');
        $this->assertDatabaseCount('enrollments', 1);
        $this->actingAs($this->student())->post('/cohorts/'.$cohort->id.'/enroll')->assertSessionHasErrors('cohort');
        $this->assertDatabaseCount('enrollments', 1);
    }

    public function test_expired_reservation_releases_capacity_and_free_course_activates_without_gateway(): void
    {
        $c = $this->cohort();
        $a = $this->student();
        $service = app(EnrollmentService::class);
        $e = $service->reserve($a, $c);
        $e->update(['expires_at' => now()->subSecond()]);
        $service->reserve($this->student(), $c);
        $this->assertDatabaseCount('enrollments', 2);
        $free = $service->reserve($a, $this->cohort(0));
        $this->assertSame('active', $free->status);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_success_query_parameter_alone_cannot_activate_enrollment(): void
    {
        $u = $this->student();
        $c = $this->cohort();
        $e = app(EnrollmentService::class)->reserve($u, $c);
        $p = Payment::create(['user_id' => $u->id, 'enrollment_id' => $e->id, 'amount' => 1000000, 'track_id' => '123']);
        config(['platform.zibal.enabled' => true, 'platform.zibal.merchant' => 'test']);
        Http::fake(['gateway.zibal.ir/*' => Http::response(['result' => 202], 200)]);
        $this->get('/payments/callback?trackId=123&success=1')->assertRedirect('/learning');
        $this->assertSame('pending_payment', $e->fresh()->status);
        $this->assertSame('pending', $p->fresh()->status);
    }

    public function test_verified_payment_is_processed_only_once(): void
    {
        $u = $this->student();
        $e = app(EnrollmentService::class)->reserve($u, $this->cohort());
        $p = Payment::create(['user_id' => $u->id, 'enrollment_id' => $e->id, 'amount' => 1000000, 'track_id' => '123']);
        config(['platform.zibal.enabled' => true, 'platform.zibal.merchant' => 'test']);
        Http::fake(['gateway.zibal.ir/*' => Http::response(['result' => 100, 'status' => 1, 'amount' => 1000000, 'orderId' => (string) $p->id, 'refNumber' => '999'], 200)]);
        $this->get('/payments/callback?trackId=123')->assertRedirect('/learning');
        $this->get('/payments/callback?trackId=123')->assertRedirect('/learning');
        $this->assertSame('active', $e->fresh()->status);
        $this->assertSame('paid', $p->fresh()->status);
        $this->assertDatabaseCount('payments', 1);
        $this->assertDatabaseCount('audit_events', 2);
        Http::assertSentCount(1);
    }

    public function test_gateway_amount_mismatch_is_rejected(): void
    {
        $u = $this->student();
        $e = app(EnrollmentService::class)->reserve($u, $this->cohort());
        $p = Payment::create(['user_id' => $u->id, 'enrollment_id' => $e->id, 'amount' => 1000000, 'track_id' => '123']);
        config(['platform.zibal.enabled' => true, 'platform.zibal.merchant' => 'test']);
        Http::fake(['gateway.zibal.ir/*' => Http::response(['result' => 100, 'status' => 1, 'amount' => 1, 'orderId' => (string) $p->id, 'refNumber' => '999'], 200)]);
        $this->get('/payments/callback?trackId=123&success=1');
        $this->assertSame('pending_payment', $e->fresh()->status);
    }

    public function test_user_cannot_pay_another_users_enrollment(): void
    {
        $e = app(EnrollmentService::class)->reserve($this->student(), $this->cohort());
        $this->actingAs($this->student())->post('/enrollments/'.$e->id.'/pay')->assertForbidden();
    }

    public function test_closed_or_unpublished_course_cannot_be_enrolled(): void
    {
        $c = $this->cohort();
        $c->update(['registration_open' => false]);
        $this->actingAs($this->student())->post('/cohorts/'.$c->id.'/enroll')->assertSessionHasErrors('cohort');
        $c->update(['registration_open' => true]);
        $c->course->update(['is_published' => false]);
        $this->post('/cohorts/'.$c->id.'/enroll')->assertSessionHasErrors('cohort');
    }

    public function test_missing_gateway_configuration_does_not_activate_enrollment(): void
    {
        $u = $this->student();
        $e = app(EnrollmentService::class)->reserve($u, $this->cohort());
        $this->actingAs($u)->post('/enrollments/'.$e->id.'/pay')->assertSessionHasErrors('payment');
        $this->assertSame('pending_payment', $e->fresh()->status);
    }

    public function test_price_is_snapshotted_when_the_seat_is_reserved(): void
    {
        $cohort = $this->cohort(1000000);
        $u = $this->student();
        $e = app(EnrollmentService::class)->reserve($u, $cohort);
        $cohort->course->update(['price' => 5000000]);
        config(['platform.zibal.enabled' => true, 'platform.zibal.merchant' => 'test']);
        Http::fake(['gateway.zibal.ir/*' => Http::response(['result' => 100, 'trackId' => '555'], 200)]);
        $this->actingAs($u)->post('/enrollments/'.$e->id.'/pay')->assertStatus(302);
        $this->assertDatabaseHas('payments', ['enrollment_id' => $e->id, 'amount' => 1000000]);
        Http::assertSent(fn ($r) => $r['amount'] === 1000000);
    }

    public function test_late_payment_cannot_take_another_users_reserved_seat(): void
    {
        $c = $this->cohort();
        $service = app(EnrollmentService::class);
        $a = $this->student();
        $first = $service->reserve($a, $c);
        $first->update(['expires_at' => now()->subSecond()]);
        $second = $service->reserve($this->student(), $c);
        $p = Payment::create(['user_id' => $a->id, 'enrollment_id' => $first->id, 'amount' => 1000000, 'track_id' => 'late']);
        $this->assertFalse($service->settle($p, 'late-ref'));
        $this->assertSame('review_required', $p->fresh()->status);
        $this->assertSame('pending_payment', $second->fresh()->status);
        $this->assertSame(0, Enrollment::where('status', 'active')->count());
    }

    public function test_pending_gateway_request_is_reused_instead_of_charging_twice(): void
    {
        $u = $this->student();
        $e = app(EnrollmentService::class)->reserve($u, $this->cohort());
        config(['platform.zibal.enabled' => true, 'platform.zibal.merchant' => 'test']);
        Http::fake(['gateway.zibal.ir/*' => Http::response(['result' => 100, 'trackId' => 'reuse'], 200)]);
        $this->actingAs($u)->post('/enrollments/'.$e->id.'/pay');
        $this->post('/enrollments/'.$e->id.'/pay');
        $this->assertDatabaseCount('payments', 1);
        Http::assertSentCount(1);
    }
}
