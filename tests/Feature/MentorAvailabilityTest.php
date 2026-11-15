<?php

namespace Tests\Feature;

use App\Models\CoachProfile;
use App\Models\Consultation;
use App\Models\ConsultationSlot;
use App\Models\Role;
use App\Models\User;
use App\Services\CoachProfiles;
use App\Services\ConsultationService;
use App\Services\SmsSender;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class MentorAvailabilityTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        $this->admin = $this->user('admin');
        $this->student = $this->user('student');
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('key', $role)->value('id'));

        return $user;
    }

    private function profile(): CoachProfile
    {
        $profile = CoachProfile::factory()->create();
        app(CoachProfiles::class)->submit($profile->owner, $profile, 1, true);
        app(CoachProfiles::class)->review($this->admin, $profile->fresh(), 2, 'publish', null);

        return $profile->fresh();
    }

    private function url(CoachProfile $profile): string
    {
        return '/consultations?mentor='.$profile->slug;
    }

    public function test_public_profile_links_to_its_own_opaque_mentor_filter(): void
    {
        $profile = $this->profile();
        $this->get('/mentors/'.$profile->slug)->assertOk()->assertSee('href="'.url($this->url($profile)).'"', false)->assertSee('مشاوره این مربی')->assertDontSee($profile->owner->phone)->assertDontSee('mentor='.$profile->user_id.'"', false);
    }

    public function test_password_login_returns_to_the_original_mentor_selection(): void
    {
        $profile = $this->profile();
        $this->get($this->url($profile))->assertRedirect('/login');
        $this->post('/login', ['phone' => $this->student->phone, 'password' => 'password'])->assertRedirect($this->url($profile));
        $this->assertAuthenticatedAs($this->student);
    }

    public function test_verified_new_registration_preserves_the_same_intended_filter(): void
    {
        $profile = $this->profile();
        $this->get($this->url($profile))->assertRedirect('/login');
        $code = null;
        $this->mock(SmsSender::class, function ($mock) use (&$code) {
            $mock->shouldReceive('sendCode')->once()->andReturnUsing(function ($phone, $value) use (&$code) {
                $code = $value;
            });
        });
        $this->post('/auth/send-code', ['phone' => '09121112222', 'mode' => 'register'])->assertRedirect('/register');
        $this->post('/auth/verify-code', ['code' => $code])->assertRedirect('/register');
        $this->post('/auth/complete', ['name' => 'کاربر جدید آزمایشی', 'password' => 'secret123', 'password_confirmation' => 'secret123'])->assertRedirect($this->url($profile));
        $this->assertSame(['student'], auth()->user()->roleKeys());
    }

    public function test_only_selected_mentors_future_published_unbooked_slots_are_listed(): void
    {
        $profile = $this->profile();
        $slot = ConsultationSlot::factory()->create(['coach_id' => $profile->user_id]);
        ConsultationSlot::factory()->create();
        ConsultationSlot::factory()->create(['coach_id' => $profile->user_id, 'published' => false]);
        ConsultationSlot::factory()->create(['coach_id' => $profile->user_id, 'starts_at' => now()->subHour(), 'ends_at' => now()]);
        ConsultationSlot::factory()->create(['coach_id' => $profile->user_id, 'booked' => true]);
        $this->actingAs($this->student)->get($this->url($profile))->assertOk()->assertInertia(fn (Assert $p) => $p->where('role', 'student')->where('mentor_filter', ['slug' => $profile->slug, 'display_name' => $profile->currentPublication->definition['display_name']])->has('slots.data', 1)->where('slots.data.0.id', $slot->id)->where('slots.data.0.coach_name', $profile->currentPublication->definition['display_name'])->where('slots.data.0.meeting_url', null)->missing('mentor_filter.phone')->missing('mentor_filter.draft'));
        Http::assertNothingSent();
    }

    public function test_slot_pagination_preserves_mentor_and_never_includes_other_coaches(): void
    {
        $profile = $this->profile();
        ConsultationSlot::factory()->count(13)->create(['coach_id' => $profile->user_id]);
        ConsultationSlot::factory()->create();
        $this->actingAs($this->student)->get($this->url($profile))->assertInertia(fn (Assert $p) => $p->has('slots.data', 12)->where('slots.total', 13)->where('slots.next_page_url', fn ($url) => str_contains($url, 'mentor='.$profile->slug) && str_contains($url, 'slots_page=2')));
        $this->get($this->url($profile).'&slots_page=2')->assertInertia(fn (Assert $p) => $p->has('slots.data', 1)->where('slots.data.0.coach_id', $profile->user_id));
    }

    public function test_filter_does_not_hide_own_other_coach_bookings_or_coordination_history(): void
    {
        $profile = $this->profile();
        $booking = app(ConsultationService::class)->book($this->student, ConsultationSlot::factory()->create(), 'موضوع جلسه قبلی');
        $request = app(ConsultationService::class)->request($this->student, 'موضوع هماهنگی قبلی', 'زمان مناسب آزمایشی');
        $this->actingAs($this->student)->get($this->url($profile))->assertInertia(fn (Assert $p) => $p->has('slots.data', 0)->has('bookings.data', 1)->where('bookings.data.0.id', $booking->id)->has('requests.data', 1)->where('requests.data.0.id', $request->id));
    }

    public function test_empty_target_does_not_fall_back_to_another_coach(): void
    {
        $profile = $this->profile();
        ConsultationSlot::factory()->create();
        $this->actingAs($this->student)->get($this->url($profile))->assertInertia(fn (Assert $p) => $p->has('slots.data', 0)->where('mentor_filter.slug', $profile->slug));
        $this->get('/consultations')->assertInertia(fn (Assert $p) => $p->has('slots.data', 1)->where('mentor_filter', null));
    }

    public function test_private_withdrawn_or_unknown_profiles_never_resolve_as_filters(): void
    {
        $draft = CoachProfile::factory()->create();
        $this->actingAs($this->student)->get($this->url($draft))->assertNotFound();
        $this->get('/consultations?mentor='.Str::uuid())->assertNotFound();
        $profile = $this->profile();
        ConsultationSlot::factory()->create(['coach_id' => $profile->user_id]);
        app(CoachProfiles::class)->withdraw($profile->owner, $profile, $profile->revision, null);
        $this->get($this->url($profile))->assertNotFound();
        $this->get('/consultations')->assertInertia(fn (Assert $p) => $p->has('slots.data', 1));
    }

    public function test_current_activity_verified_phone_and_coach_role_gate_saved_filters(): void
    {
        $profile = $this->profile();
        $owner = $profile->owner;
        $this->actingAs($this->student);
        $owner->forceFill(['is_active' => false])->save();
        $this->get($this->url($profile))->assertNotFound();
        $owner->forceFill(['is_active' => true, 'phone_verified_at' => null])->save();
        $this->get($this->url($profile))->assertNotFound();
        $owner->forceFill(['phone_verified_at' => now()])->save();
        $owner->roles()->detach();
        $this->get($this->url($profile))->assertNotFound();
    }

    public function test_invalid_filter_is_validated_without_exposing_or_changing_private_data(): void
    {
        $this->actingAs($this->student);
        foreach (['1', '<script>x</script>', ['nested']] as $value) {
            $this->getJson('/consultations?'.http_build_query(['mentor' => $value]))->assertUnprocessable()->assertJsonValidationErrors('mentor');
        }
        $this->assertDatabaseCount('consultations', 0);
    }

    public function test_staff_views_keep_their_existing_scope_without_forced_student_access(): void
    {
        $profile = $this->profile();
        $own = ConsultationSlot::factory()->create(['coach_id' => $profile->user_id]);
        ConsultationSlot::factory()->create();
        $this->actingAs($profile->owner)->get($this->url($profile))->assertInertia(fn (Assert $p) => $p->where('role', 'coach')->where('mentor_filter', null)->has('slots.data', 1)->where('slots.data.0.id', $own->id));
        $this->get($this->url($profile).'&view=student')->assertForbidden();
        $this->actingAs($this->admin)->get($this->url($profile))->assertInertia(fn (Assert $p) => $p->where('role', 'admin')->where('mentor_filter', null)->has('slots.data', 2));
    }

    public function test_filtered_booking_uses_existing_server_capacity_price_and_meeting_guards(): void
    {
        $profile = $this->profile();
        $slot = ConsultationSlot::factory()->create(['coach_id' => $profile->user_id]);
        $this->actingAs($this->student)->from($this->url($profile))->post('/consultations/slots/'.$slot->id.'/book', ['topic' => 'جلسه آزمایشی با مربی منتخب', 'mentor' => 'forged', 'coach_id' => $this->admin->id, 'price' => -1])->assertRedirect($this->url($profile))->assertSessionHasNoErrors();
        $booking = Consultation::sole();
        $this->assertSame($slot->id, $booking->consultation_slot_id);
        $this->assertSame($this->student->id, $booking->student_id);
        $this->assertTrue($slot->fresh()->booked);
        $this->get($this->url($profile))->assertInertia(fn (Assert $p) => $p->has('slots.data', 0)->has('bookings.data', 1));
        $this->get('/consultations/'.$booking->id.'/join')->assertForbidden();
    }
}
