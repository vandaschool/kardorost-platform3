<?php

namespace Tests\Feature;

use App\Models\CourseSection;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\Role;
use App\Models\User;
use App\Services\LessonProgressRecorder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class LessonProgressIntegrityTest extends TestCase
{
    use RefreshDatabase;

    private User $student;

    private Enrollment $enrollment;

    private Lesson $lesson;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->student = User::factory()->create();
        $this->student->roles()->attach(Role::where('key', 'student')->value('id'));
        $this->enrollment = Enrollment::factory()->create(['user_id' => $this->student->id]);
        $this->lesson = Lesson::factory()->create(['course_section_id' => CourseSection::factory()->create(['course_id' => $this->enrollment->course_id])->id, 'duration_seconds' => 300]);
    }

    private function record(array $input = ['complete' => true]): LessonProgress
    {
        return app(LessonProgressRecorder::class)->record($this->student, $this->enrollment, $this->lesson, $input);
    }

    private function denied(): void
    {
        try {
            $this->record();
            $this->fail('A stale model bypassed current access checks.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
            $this->assertDatabaseCount('lesson_progress', 0);
        }
    }

    public function test_preloaded_lesson_cannot_record_progress_after_unpublication(): void
    {
        Lesson::whereKey($this->lesson->id)->update(['is_published' => false]);
        $this->assertTrue($this->lesson->is_published);
        $this->denied();
    }

    public function test_preloaded_lesson_cannot_record_progress_after_release_is_moved_to_future(): void
    {
        Lesson::whereKey($this->lesson->id)->update(['available_at' => now()->addHour()]);
        $this->denied();
    }

    public function test_preloaded_enrollment_cannot_record_progress_after_cancellation(): void
    {
        Enrollment::whereKey($this->enrollment->id)->update(['status' => 'cancelled']);
        $this->assertSame('active', $this->enrollment->status);
        $this->denied();
    }

    public function test_preloaded_account_cannot_record_progress_after_deactivation_or_role_loss(): void
    {
        User::whereKey($this->student->id)->update(['is_active' => false]);
        $this->assertTrue($this->student->is_active);
        $this->denied();
        User::whereKey($this->student->id)->update(['is_active' => true]);
        $this->student->roles()->detach();
        $this->denied();
    }

    public function test_preloaded_duration_is_not_used_after_instructor_shortens_a_video(): void
    {
        Lesson::whereKey($this->lesson->id)->update(['duration_seconds' => 120]);
        $progress = $this->record(['position_seconds' => 299]);
        $this->assertSame(120, $progress->position_seconds);
        $this->assertNull($progress->completed_at);
    }

    public function test_wrong_course_section_and_wrong_owner_are_rejected_using_current_rows(): void
    {
        $other = CourseSection::factory()->create();
        Lesson::whereKey($this->lesson->id)->update(['course_section_id' => $other->id]);
        $this->denied();
        Lesson::whereKey($this->lesson->id)->update(['course_section_id' => $this->lesson->course_section_id]);
        Enrollment::whereKey($this->enrollment->id)->update(['user_id' => User::factory()->create()->id]);
        $this->denied();
    }

    public function test_completion_is_one_way_and_repeated_autosave_preserves_first_completion_time(): void
    {
        $first = $this->record();
        $completed = $first->completed_at;
        $this->travel(2)->minutes();
        $second = $this->record(['position_seconds' => 50, 'complete' => false, 'completed_at' => '2000-01-01', 'enrollment_id' => 0]);
        $this->assertSame($first->id, $second->id);
        $this->assertTrue($completed->equalTo($second->completed_at));
        $this->assertSame(50, $second->position_seconds);
        $this->assertDatabaseCount('lesson_progress', 1);
    }

    public function test_invalid_progress_input_cannot_create_or_modify_a_record(): void
    {
        foreach ([['position_seconds' => -1], ['position_seconds' => 864001], ['position_seconds' => '1.5'], ['complete' => 'yes']] as $input) {
            try {
                $this->record($input);
                $this->fail('Invalid progress was accepted.');
            } catch (ValidationException) {
                $this->assertDatabaseCount('lesson_progress', 0);
            }
        }
    }

    public function test_public_json_autosave_stays_a_small_no_content_response(): void
    {
        $this->actingAs($this->student)->postJson('/learning/'.$this->enrollment->id.'/lessons/'.$this->lesson->id.'/progress', ['position_seconds' => 60])->assertNoContent();
        $this->assertSame(60, LessonProgress::sole()->position_seconds);
        $this->assertNull(LessonProgress::sole()->completed_at);
    }
}
