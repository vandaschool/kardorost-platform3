<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Cohort;
use App\Models\CourseSection;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\LiveSession;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LearningAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function student(): User
    {
        $u = User::factory()->create();
        $u->roles()->attach(Role::where('key', 'student')->value('id'));

        return $u;
    }

    private function lesson(Enrollment $e, array $values = []): Lesson
    {
        return Lesson::factory()->create([...$values, 'course_section_id' => CourseSection::factory()->create(['course_id' => $e->course_id])->id]);
    }

    public function test_only_active_owner_can_access_the_classroom(): void
    {
        $owner = $this->student();
        $e = Enrollment::factory()->create(['user_id' => $owner->id]);
        $this->actingAs($this->student())->get('/learning/'.$e->id)->assertForbidden();
        $this->actingAs($owner)->get('/learning/'.$e->id)->assertOk()->assertInertia(fn (Assert $p) => $p->component('CourseRoom'));
        $e->update(['status' => 'pending_payment']);
        $this->get('/learning/'.$e->id)->assertForbidden();
    }

    public function test_draft_and_future_content_and_meeting_urls_do_not_leak(): void
    {
        $u = $this->student();
        $e = Enrollment::factory()->create(['user_id' => $u->id]);
        $this->lesson($e, ['title' => 'پیش‌نویس محرمانه', 'body' => 'draft body', 'media_url' => 'https://cdn.example.com/draft.mp4', 'is_published' => false]);
        $future = $this->lesson($e, ['title' => 'درس بعد', 'body' => 'future secret', 'media_url' => 'https://cdn.example.com/future.mp4', 'available_at' => now()->addDay()]);
        LiveSession::factory()->create(['cohort_id' => $e->cohort_id, 'meeting_url' => 'https://meet.google.com/secret', 'recording_url' => 'https://cdn.example.com/private.mp4']);
        Assignment::factory()->create(['cohort_id' => $e->cohort_id, 'is_published' => false, 'instructions' => 'hidden assignment']);
        $response = $this->actingAs($u)->get('/learning/'.$e->id);
        $response->assertOk()->assertInertia(fn (Assert $p) => $p->where('selected', null)->has('sections', 1)->where('sections.0.lessons.0.locked', true)->has('assignments', 0));
        foreach (['پیش‌نویس محرمانه', 'draft body', 'future secret', 'secret', 'future.mp4', 'private.mp4', 'hidden assignment'] as $hidden) {
            $response->assertDontSee($hidden);
        }
        $this->get('/learning/'.$e->id.'?lesson='.$future->id)->assertForbidden();
    }

    public function test_published_lesson_returns_video_and_completion_is_idempotent(): void
    {
        $u = $this->student();
        $e = Enrollment::factory()->create(['user_id' => $u->id]);
        $l = $this->lesson($e, ['media_kind' => 'hls', 'media_url' => 'https://cdn.example.com/a.m3u8', 'duration_seconds' => 500]);
        $this->actingAs($u)->get('/learning/'.$e->id)->assertInertia(fn (Assert $p) => $p->where('selected.media_url', $l->media_url)->where('progress.percent', 0));
        $url = '/learning/'.$e->id.'/lessons/'.$l->id.'/progress';
        $this->post($url, ['position_seconds' => 999, 'complete' => true])->assertSessionHasNoErrors();
        $completedAt = LessonProgress::first()->completed_at;
        $this->travel(1)->minutes();
        $this->post($url, ['complete' => true])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('lesson_progress', 1);
        $this->assertSame(500, LessonProgress::first()->position_seconds);
        $this->assertTrue($completedAt->equalTo(LessonProgress::first()->completed_at));
        $this->get('/learning/'.$e->id)->assertInertia(fn (Assert $p) => $p->where('progress.percent', 100));
    }

    public function test_progress_rejects_lesson_from_another_course_and_future_lesson(): void
    {
        $u = $this->student();
        $e = Enrollment::factory()->create(['user_id' => $u->id]);
        $foreign = Lesson::factory()->create();
        $future = $this->lesson($e, ['available_at' => now()->addDay()]);
        $this->actingAs($u);
        foreach ([$foreign, $future] as $l) {
            $this->post('/learning/'.$e->id.'/lessons/'.$l->id.'/progress', ['complete' => true])->assertForbidden();
        }
        $this->assertDatabaseCount('lesson_progress', 0);
    }

    public function test_progress_does_not_claim_completion_when_video_position_is_saved(): void
    {
        $u = $this->student();
        $e = Enrollment::factory()->create(['user_id' => $u->id]);
        $l = $this->lesson($e);
        $this->actingAs($u)->post('/learning/'.$e->id.'/lessons/'.$l->id.'/progress', ['position_seconds' => 90])->assertSessionHasNoErrors();
        $this->assertNull(LessonProgress::first()->completed_at);
    }

    public function test_class_join_requires_correct_cohort_and_time_and_never_marks_attendance(): void
    {
        $u = $this->student();
        $e = Enrollment::factory()->create(['user_id' => $u->id]);
        $s = LiveSession::factory()->create(['cohort_id' => $e->cohort_id, 'starts_at' => now()->addMinutes(10), 'ends_at' => now()->addHour()]);
        $other = LiveSession::factory()->create();
        $this->actingAs($u)->get('/learning/'.$e->id.'/sessions/'.$other->id.'/join')->assertForbidden();
        $this->get('/learning/'.$e->id.'/sessions/'.$s->id.'/join')->assertRedirect($s->meeting_url);
        $this->assertDatabaseCount('attendances', 0);
        $this->travel(2)->hours();
        $this->get('/learning/'.$e->id.'/sessions/'.$s->id.'/join')->assertSessionHas('error');
    }

    public function test_recording_is_available_only_for_finished_published_session_in_same_cohort(): void
    {
        $u = $this->student();
        $e = Enrollment::factory()->create(['user_id' => $u->id]);
        $s = LiveSession::factory()->create(['cohort_id' => $e->cohort_id, 'starts_at' => now()->subHours(2), 'ends_at' => now()->subHour(), 'recording_kind' => 'mp4', 'recording_url' => 'https://cdn.example.com/done.mp4']);
        $this->actingAs($u)->get('/learning/'.$e->id.'?recording='.$s->id)->assertInertia(fn (Assert $p) => $p->where('recording.media_url', $s->recording_url));
        $s->update(['is_published' => false]);
        $this->get('/learning/'.$e->id.'?recording='.$s->id)->assertNotFound();
    }

    public function test_lesson_release_at_exact_current_time_is_available(): void
    {
        $this->freezeTime();
        $u = $this->student();
        $e = Enrollment::factory()->create(['user_id' => $u->id]);
        $l = $this->lesson($e, ['available_at' => now()]);
        $this->actingAs($u)->get('/learning/'.$e->id.'?lesson='.$l->id)->assertOk();
    }

    public function test_position_autosave_is_a_small_json_response_and_unpublishing_updates_denominator(): void
    {
        $u = $this->student();
        $e = Enrollment::factory()->create(['user_id' => $u->id]);
        $l = $this->lesson($e);
        $other = $this->lesson($e);
        $this->actingAs($u)->postJson('/learning/'.$e->id.'/lessons/'.$l->id.'/progress', ['position_seconds' => 30])->assertNoContent();
        $this->post('/learning/'.$e->id.'/lessons/'.$l->id.'/progress', ['complete' => true]);
        $this->get('/learning/'.$e->id)->assertInertia(fn (Assert $p) => $p->where('progress.percent', 50));
        $other->update(['is_published' => false]);
        $this->get('/learning/'.$e->id)->assertInertia(fn (Assert $p) => $p->where('progress.percent', 100)->where('progress.total', 1));
    }

    public function test_cohort_cannot_see_another_cohorts_recording_or_assignment(): void
    {
        $u = $this->student();
        $e = Enrollment::factory()->create(['user_id' => $u->id]);
        $other = Cohort::factory()->create(['course_id' => $e->course_id]);
        $s = LiveSession::factory()->create(['cohort_id' => $other->id, 'ends_at' => now()->subHour(), 'recording_kind' => 'mp4', 'recording_url' => 'https://cdn.example.com/PRIVATE_OTHER_COHORT.mp4']);
        Assignment::factory()->create(['cohort_id' => $other->id, 'instructions' => 'OTHER_COHORT_ASSIGNMENT']);
        $this->actingAs($u)->get('/learning/'.$e->id)->assertOk()->assertDontSee('OTHER_COHORT_ASSIGNMENT')->assertDontSee('PRIVATE_OTHER_COHORT');
        $this->get('/learning/'.$e->id.'?recording='.$s->id)->assertNotFound();
    }
}
