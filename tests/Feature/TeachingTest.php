<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\Cohort;
use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LiveSession;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class TeachingTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->roles()->attach(Role::where('key', $role)->value('id'));

        return $u;
    }

    private function lessonData(CourseSection $s, array $overrides = []): array
    {
        return [...['course_section_id' => $s->id, 'title' => 'درس', 'body' => 'متن', 'media_kind' => 'none', 'media_url' => null, 'duration_seconds' => 300, 'position' => 1, 'is_published' => true, 'available_at' => null], ...$overrides];
    }

    private function sessionData(): array
    {
        return ['title' => 'جلسه', 'provider' => 'meet', 'meeting_url' => 'https://meet.google.com/abc-defg-hij', 'starts_at' => now()->addDay()->toIso8601String(), 'ends_at' => now()->addDay()->addHour()->toIso8601String(), 'is_published' => true, 'recording_kind' => 'none', 'recording_url' => null];
    }

    public function test_admin_and_assigned_coach_can_manage_and_others_cannot(): void
    {
        $coach = $this->user('coach');
        $course = Course::factory()->create(['coach_id' => $coach->id]);
        $this->actingAs($coach)->get('/teaching/'.$course->id)->assertOk()->assertInertia(fn (Assert $p) => $p->component('Teaching'));
        $this->actingAs($this->user('coach'))->get('/teaching/'.$course->id)->assertForbidden();
        $this->actingAs($this->user('student'))->post('/teaching/'.$course->id.'/sections', ['title' => 'خارج از دسترسی', 'position' => 0])->assertForbidden();
        $this->actingAs($this->user('admin'))->post('/teaching/'.$course->id.'/sections', ['title' => 'سرفصل', 'position' => 0])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('course_sections', 1);
    }

    public function test_section_injection_cannot_move_lesson_to_another_course(): void
    {
        $course = Course::factory()->create();
        $section = CourseSection::factory()->create(['course_id' => $course->id]);
        $foreign = CourseSection::factory()->create();
        $this->actingAs($this->user('admin'))->post('/teaching/'.$course->id.'/lessons', $this->lessonData($foreign))->assertUnprocessable();
        $this->assertDatabaseCount('lessons', 0);
        $this->post('/teaching/'.$course->id.'/lessons', $this->lessonData($section))->assertSessionHasNoErrors();
        $l = Lesson::first();
        $this->patch('/teaching/'.$foreign->course_id.'/lessons/'.$l->id, $this->lessonData($foreign))->assertNotFound();
    }

    public function test_media_validation_rejects_arbitrary_embed_and_non_https_links(): void
    {
        $course = Course::factory()->create();
        $s = CourseSection::factory()->create(['course_id' => $course->id]);
        $this->actingAs($this->user('admin'));
        foreach ([['embed', 'https://evil.example/player'], ['mp4', 'javascript:alert(1)'], ['hls', 'http://cdn.example/a.m3u8'], ['mp4', 'https://user:password@cdn.example/a.mp4']] as [$kind,$url]) {
            $this->post('/teaching/'.$course->id.'/lessons', $this->lessonData($s, ['media_kind' => $kind, 'media_url' => $url]))->assertSessionHasErrors('media_url');
        }
        $this->post('/teaching/'.$course->id.'/lessons', $this->lessonData($s, ['media_kind' => 'embed', 'media_url' => 'https://player.arvancloud.ir/index.html?config=test']))->assertSessionHasNoErrors();
        $this->assertDatabaseCount('lessons', 1);
    }

    public function test_meeting_provider_must_match_host_and_dates_are_normalized_to_tehran(): void
    {
        $course = Course::factory()->create();
        $cohort = Cohort::factory()->create(['course_id' => $course->id]);
        $this->actingAs($this->user('admin'));
        $data = $this->sessionData();
        $data['meeting_url'] = 'https://meet.google.com.evil.example/steal';
        $this->post('/teaching/'.$course->id.'/cohorts/'.$cohort->id.'/sessions', $data)->assertSessionHasErrors('meeting_url');
        $data = $this->sessionData();
        $data['starts_at'] = '2026-12-10T10:00:00Z';
        $data['ends_at'] = '2026-12-10T11:00:00Z';
        $this->post('/teaching/'.$course->id.'/cohorts/'.$cohort->id.'/sessions', $data)->assertSessionHasNoErrors();
        $this->assertSame('13:30', LiveSession::first()->starts_at->format('H:i'));
    }

    public function test_attendance_only_accepts_active_enrollment_of_same_cohort(): void
    {
        $course = Course::factory()->create();
        $cohort = Cohort::factory()->create(['course_id' => $course->id]);
        $s = LiveSession::factory()->create(['cohort_id' => $cohort->id]);
        $e = Enrollment::factory()->create(['cohort_id' => $cohort->id]);
        $foreign = Enrollment::factory()->create();
        $this->actingAs($this->user('admin'))->post('/teaching/'.$course->id.'/sessions/'.$s->id.'/attendance', ['enrollment_id' => $foreign->id, 'status' => 'present'])->assertUnprocessable();
        $this->post('/teaching/'.$course->id.'/sessions/'.$s->id.'/attendance', ['enrollment_id' => $e->id, 'status' => 'present'])->assertSessionHasNoErrors();
        $this->post('/teaching/'.$course->id.'/sessions/'.$s->id.'/attendance', ['enrollment_id' => $e->id, 'status' => 'excused'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('attendances', 1);
        $this->assertSame('excused', Attendance::first()->status);
    }

    public function test_cannot_create_assignment_in_foreign_cohort_or_invert_deadlines(): void
    {
        $course = Course::factory()->create();
        $foreign = Cohort::factory()->create();
        $own = Cohort::factory()->create(['course_id' => $course->id]);
        $data = ['title' => 'تمرین', 'instructions' => 'توضیح', 'max_score' => 20, 'is_published' => true, 'due_at' => now()->addDay()->toIso8601String(), 'closes_at' => now()->toIso8601String()];
        $this->actingAs($this->user('admin'))->post('/teaching/'.$course->id.'/cohorts/'.$foreign->id.'/assignments', $data)->assertNotFound();
        $this->post('/teaching/'.$course->id.'/cohorts/'.$own->id.'/assignments', $data)->assertSessionHasErrors('closes_at');
        $this->assertDatabaseCount('assignments', 0);
    }

    public function test_reassigning_coach_immediately_removes_teaching_access(): void
    {
        $coach = $this->user('coach');
        $course = Course::factory()->create(['coach_id' => $coach->id]);
        $this->actingAs($coach)->get('/teaching/'.$course->id)->assertOk();
        $course->update(['coach_id' => null]);
        $this->get('/teaching/'.$course->id)->assertForbidden();
    }

    public function test_empty_published_lesson_is_rejected_but_draft_is_allowed(): void
    {
        $course = Course::factory()->create();
        $section = CourseSection::factory()->create(['course_id' => $course->id]);
        $data = $this->lessonData($section, ['body' => '', 'is_published' => true]);
        $this->actingAs($this->user('admin'))->post('/teaching/'.$course->id.'/lessons', $data)->assertSessionHasErrors('body');
        $data['is_published'] = false;
        $this->post('/teaching/'.$course->id.'/lessons', $data)->assertSessionHasNoErrors();
        $this->assertDatabaseCount('lessons', 1);
    }

    public function test_cohort_selector_and_member_roster_are_scoped_to_course(): void
    {
        $coach = $this->user('coach');
        $course = Course::factory()->create(['coach_id' => $coach->id]);
        $cohort = Cohort::factory()->create(['course_id' => $course->id]);
        $foreign = Cohort::factory()->create();
        $own = Enrollment::factory()->create(['cohort_id' => $cohort->id]);
        $other = Enrollment::factory()->create(['cohort_id' => $foreign->id]);
        $this->actingAs($coach)->get('/teaching/'.$course->id.'?cohort='.$foreign->id)->assertNotFound();
        $this->get('/teaching/'.$course->id.'?cohort='.$cohort->id)->assertInertia(fn (Assert $p) => $p->has('members', 1)->where('members.0.enrollment_id', $own->id));
    }
}
