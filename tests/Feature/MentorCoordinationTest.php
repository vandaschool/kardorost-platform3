<?php

namespace Tests\Feature;

use App\Models\CoachProfile;
use App\Models\Consultation;
use App\Models\ConsultationGrant;
use App\Models\ConsultationRequest;
use App\Models\ConsultationSlot;
use App\Models\Role;
use App\Models\User;
use App\Services\CoachProfiles;
use App\Services\ConsultationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class MentorCoordinationTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private User $admin;

    private CoachProfile $profile;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        $this->student = $this->user('student');
        $this->admin = $this->user('admin');
        $this->profile = CoachProfile::factory()->create();
        app(CoachProfiles::class)->submit($this->profile->owner, $this->profile, 1, true);
        app(CoachProfiles::class)->review($this->admin, $this->profile->fresh(), 2, 'publish', null);
        $this->profile->refresh();
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('key', $role)->value('id'));

        return $user;
    }

    private function requestMentor(?int $grant = null): ConsultationRequest
    {
        return app(ConsultationService::class)->request($this->student, 'موضوع خصوصی هماهنگی', 'زمان مناسب بعد از ظهر', $grant, $this->profile->slug);
    }

    private function schedule(ConsultationRequest $request, ConsultationSlot $slot): void
    {
        $this->actingAs($this->admin)->post('/consultations/requests/'.$request->id.'/schedule', ['version' => $request->version, 'slot_id' => $slot->id])->assertSessionHasNoErrors();
    }

    public function test_student_selects_server_resolved_mentor_with_encrypted_historical_snapshot(): void
    {
        $before = DB::table('notifications')->count();
        $this->actingAs($this->student)->post('/consultations/requests', ['topic' => 'هماهنگی آزمایشی', 'availability' => 'عصر روزهای هفته', 'mentor' => $this->profile->slug, 'requested_coach_id' => $this->admin->id, 'requested_mentor' => ['display_name' => 'forged'], 'student_id' => $this->admin->id])->assertSessionHasNoErrors();
        $request = ConsultationRequest::sole();
        $this->assertSame($this->student->id, $request->student_id);
        $this->assertSame($this->profile->user_id, $request->requested_coach_id);
        $this->assertSame($this->profile->currentPublication->definition['display_name'], $request->requested_mentor['display_name']);
        $this->assertSame($this->profile->current_publication_id, $request->requested_mentor['publication_id']);
        $raw = DB::table('consultation_requests')->value('requested_mentor');
        $this->assertStringNotContainsString($this->profile->slug, $raw);
        $this->assertSame($before, DB::table('notifications')->count());
        $this->assertDatabaseCount('consultations', 0);
        Http::assertNothingSent();
    }

    public function test_plain_coordination_remains_optional_and_can_use_any_eligible_coach(): void
    {
        $request = app(ConsultationService::class)->request($this->student, 'هماهنگی عمومی', 'فردا بعد از ظهر');
        $this->assertNull($request->requested_coach_id);
        $this->assertNull($request->requested_mentor);
        $this->schedule($request, ConsultationSlot::factory()->create());
        $this->assertSame('scheduled', $request->fresh()->status);
    }

    public function test_admin_cannot_substitute_another_coach_even_by_bypassing_the_selector(): void
    {
        $request = $this->requestMentor();
        $wrong = ConsultationSlot::factory()->create();
        $this->actingAs($this->admin)->post('/consultations/requests/'.$request->id.'/schedule', ['version' => 1, 'slot_id' => $wrong->id])->assertSessionHasErrors('consultation');
        $this->assertSame('pending', $request->fresh()->status);
        $this->assertSame(1, $request->fresh()->version);
        $this->assertFalse($wrong->fresh()->booked);
        $this->assertDatabaseCount('consultations', 0);
        $right = ConsultationSlot::factory()->create(['coach_id' => $this->profile->user_id]);
        $this->schedule($request, $right);
        $this->assertSame($right->id, Consultation::sole()->consultation_slot_id);
        $this->assertSame('scheduled', $request->fresh()->status);
    }

    public function test_public_profile_withdrawal_keeps_existing_private_choice_and_does_not_cancel_consultation_access(): void
    {
        $request = $this->requestMentor();
        $snapshot = $request->requested_mentor;
        app(CoachProfiles::class)->withdraw($this->profile->owner, $this->profile, $this->profile->revision, null);
        $this->get('/mentors/'.$this->profile->slug)->assertNotFound();
        $this->assertSame($snapshot, $request->fresh()->requested_mentor);
        $this->schedule($request, ConsultationSlot::factory()->create(['coach_id' => $this->profile->user_id]));
        $this->assertSame('confirmed', Consultation::sole()->status);
    }

    public function test_new_request_cannot_target_unknown_private_or_no_longer_eligible_profile(): void
    {
        $draft = CoachProfile::factory()->create();
        $this->actingAs($this->student);
        foreach ([$draft->slug, (string) Str::uuid()] as $slug) {
            $this->post('/consultations/requests', ['topic' => 'موضوع جدید', 'availability' => 'فردا عصر', 'mentor' => $slug])->assertNotFound();
        }
        $owner = $this->profile->owner;
        $owner->forceFill(['is_active' => false])->save();
        $this->post('/consultations/requests', ['topic' => 'موضوع جدید', 'availability' => 'فردا عصر', 'mentor' => $this->profile->slug])->assertNotFound();
        $owner->forceFill(['is_active' => true, 'phone_verified_at' => null])->save();
        $this->post('/consultations/requests', ['topic' => 'موضوع جدید', 'availability' => 'فردا عصر', 'mentor' => $this->profile->slug])->assertNotFound();
        $this->assertDatabaseCount('consultation_requests', 0);
    }

    public function test_invalid_mentor_query_cannot_be_used_as_a_raw_account_identifier(): void
    {
        $this->actingAs($this->student)->post('/consultations/requests', ['topic' => 'موضوع جدید', 'availability' => 'فردا عصر', 'mentor' => $this->profile->user_id])->assertSessionHasErrors('mentor');
        $this->assertDatabaseCount('consultation_requests', 0);
    }

    public function test_student_cannot_request_themself_as_a_coach(): void
    {
        $owner = $this->profile->owner;
        $owner->roles()->attach(Role::where('key', 'student')->value('id'));
        $this->actingAs($owner)->post('/consultations/requests', ['topic' => 'موضوع جدید', 'availability' => 'فردا عصر', 'mentor' => $this->profile->slug])->assertSessionHasErrors('consultation');
        $this->assertDatabaseCount('consultation_requests', 0);
    }

    public function test_foreign_coach_package_cannot_change_selected_mentor_or_reserve_credits(): void
    {
        $grant = ConsultationGrant::factory()->create(['student_id' => $this->student->id]);
        try {
            $this->requestMentor($grant->id);
            $this->fail('Incompatible package accepted.');
        } catch (ValidationException) {
            $this->assertDatabaseCount('consultation_requests', 0);
            $this->assertDatabaseCount('consultation_grant_uses', 0);
        }
    }

    public function test_matching_package_is_not_spent_until_valid_actual_booking(): void
    {
        $grant = ConsultationGrant::factory()->create(['student_id' => $this->student->id, 'coach_id' => $this->profile->user_id]);
        $request = $this->requestMentor($grant->id);
        $this->assertSame(2, $grant->remaining());
        $slot = ConsultationSlot::factory()->create(['coach_id' => $this->profile->user_id, 'price' => 1000000]);
        $this->schedule($request, $slot);
        $this->assertSame(1, $grant->remaining());
        $this->assertSame(0, Consultation::sole()->amount);
        $this->assertSame($grant->id, Consultation::sole()->consultation_grant_id);
        $this->assertDatabaseCount('consultation_grant_uses', 1);
        $this->assertDatabaseCount('payments', 0);
    }

    public function test_admin_selected_request_filter_is_paginated_and_finds_only_compatible_future_slots(): void
    {
        $request = $this->requestMentor();
        ConsultationSlot::factory()->count(13)->create(['coach_id' => $this->profile->user_id]);
        ConsultationSlot::factory()->create();
        ConsultationSlot::factory()->create(['coach_id' => $this->profile->user_id, 'published' => false]);
        ConsultationSlot::factory()->create(['coach_id' => $this->profile->user_id, 'booked' => true]);
        ConsultationSlot::factory()->create(['coach_id' => $this->profile->user_id, 'starts_at' => now()->subHour(), 'ends_at' => now()]);
        $url = '/consultations?view=admin&coordination_request='.$request->id;
        $this->actingAs($this->admin)->get($url)->assertInertia(fn (Assert $p) => $p->where('coordination_filter.id', $request->id)->has('slots.data', 12)->where('slots.total', 13)->where('slots.next_page_url', fn ($url) => str_contains($url, 'coordination_request='.$request->id)));
        $this->get($url.'&slots_page=2')->assertInertia(fn (Assert $p) => $p->has('slots.data', 1)->where('slots.data.0.coach_id', $this->profile->user_id));
    }

    public function test_package_filter_excludes_free_wrong_coach_and_wrong_duration_slots(): void
    {
        $grant = ConsultationGrant::factory()->create(['student_id' => $this->student->id, 'coach_id' => $this->profile->user_id]);
        $request = $this->requestMentor($grant->id);
        $matching = ConsultationSlot::factory()->create(['coach_id' => $this->profile->user_id, 'price' => 1000000]);
        ConsultationSlot::factory()->create(['coach_id' => $this->profile->user_id, 'price' => 0]);
        ConsultationSlot::factory()->create(['coach_id' => $this->profile->user_id, 'price' => 1000000, 'ends_at' => now()->addDay()->addMinutes(30)]);
        ConsultationSlot::factory()->create(['price' => 1000000]);
        $this->actingAs($this->admin)->get('/consultations?view=admin&coordination_request='.$request->id)->assertInertia(fn (Assert $p) => $p->has('slots.data', 1)->where('slots.data.0.id', $matching->id));
    }

    public function test_resolved_request_filter_cannot_offer_new_bookings(): void
    {
        $request = $this->requestMentor();
        app(ConsultationService::class)->resolve($this->student, $request, 1, 'انصراف دانشجو');
        ConsultationSlot::factory()->create(['coach_id' => $this->profile->user_id]);
        $this->actingAs($this->admin)->get('/consultations?view=admin&coordination_request='.$request->id)->assertInertia(fn (Assert $p) => $p->has('slots.data', 0));
    }

    public function test_request_selection_is_owner_admin_only_and_not_a_coach_access_grant(): void
    {
        $request = $this->requestMentor();
        $this->actingAs($this->student)->get('/consultations')->assertInertia(fn (Assert $p) => $p->where('requests.data.0.requested_mentor.slug', $this->profile->slug)->missing('requests.data.0.requested_mentor.publication_id'));
        $this->actingAs($this->user('student'))->get('/consultations?coordination_request='.$request->id)->assertInertia(fn (Assert $p) => $p->where('coordination_filter', null)->has('requests.data', 0));
        $this->actingAs($this->profile->owner)->get('/consultations?coordination_request='.$request->id)->assertInertia(fn (Assert $p) => $p->where('coordination_filter', null)->where('requests', null));
        $this->actingAs($this->admin)->get('/consultations?view=admin&coordination_request=999999')->assertNotFound();
    }

    public function test_closed_request_keeps_original_choice_and_a_new_request_requires_its_own_mentor_selection(): void
    {
        $first = $this->requestMentor();
        app(ConsultationService::class)->resolve($this->student, $first, 1, 'انتخاب روش دیگر');
        $second = app(ConsultationService::class)->request($this->student, 'درخواست مستقل بعدی', 'فردا عصر');
        $this->assertNull($second->requested_coach_id);
        $this->assertSame($this->profile->user_id, $first->fresh()->requested_coach_id);
        $this->assertSame('withdrawn', $first->fresh()->status);
    }

    public function test_inactive_selected_coach_cannot_be_booked_from_an_old_request(): void
    {
        $request = $this->requestMentor();
        $slot = ConsultationSlot::factory()->create(['coach_id' => $this->profile->user_id]);
        $this->profile->owner->forceFill(['is_active' => false])->save();
        $this->actingAs($this->admin)->post('/consultations/requests/'.$request->id.'/schedule', ['version' => 1, 'slot_id' => $slot->id])->assertSessionHasErrors('consultation');
        $this->assertSame('pending', $request->fresh()->status);
        $this->assertFalse($slot->fresh()->booked);
    }

    public function test_only_current_student_can_open_new_coordination_after_account_state_change(): void
    {
        $this->student->forceFill(['is_active' => false])->save();
        $this->expectException(HttpException::class);
        $this->requestMentor();
    }

    public function test_rollback_cannot_erase_student_mentor_choices(): void
    {
        $this->requestMentor();
        $migration = require database_path('migrations/2026_11_15_231741_add_requested_mentor_to_consultation_requests.php');
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }

    public function test_admin_selected_request_stays_visible_beyond_general_request_pagination(): void
    {
        $request = $this->requestMentor();
        for ($i = 0; $i < 13; $i++) {
            app(ConsultationService::class)->request($this->user('student'), 'درخواست دیگر آزمایشی', 'فردا بعد از ظهر');
        }
        $this->actingAs($this->admin)->get('/consultations?view=admin&coordination_request='.$request->id.'&requests_page=2')->assertInertia(fn (Assert $p) => $p->has('requests.data', 1)->where('requests.data.0.id', $request->id)->where('requests.current_page', 1));
    }
}
