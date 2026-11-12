<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Cohort;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Submission;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia;
use Tests\TestCase;

class SubmissionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('learning');
    }

    private function user(string $role): User
    {
        $u = User::factory()->create();
        $u->roles()->attach(Role::where('key', $role)->value('id'));

        return $u;
    }

    private function fixture(): array
    {
        $coach = $this->user('coach');
        $student = $this->user('student');
        $course = Course::factory()->create(['coach_id' => $coach->id]);
        $cohort = Cohort::factory()->create(['course_id' => $course->id]);
        $e = Enrollment::factory()->create(['user_id' => $student->id, 'cohort_id' => $cohort->id]);
        $a = Assignment::factory()->create(['cohort_id' => $cohort->id]);

        return [$coach, $student, $e, $a];
    }

    private function url(Enrollment $e, Assignment $a): string
    {
        return '/learning/'.$e->id.'/assignments/'.$a->id.'/submit';
    }

    public function test_file_is_private_and_only_owner_and_assigned_staff_can_download(): void
    {
        [$coach,$student,$e,$a] = $this->fixture();
        $this->actingAs($student)->post($this->url($e, $a), ['body' => 'پاسخ', 'expected_version' => '0', 'file' => UploadedFile::fake()->create('answer.pdf', 20, 'application/pdf')])->assertSessionHasNoErrors();
        $s = Submission::first();
        $this->assertNotNull($s);
        Storage::disk('learning')->assertExists($s->file_path);
        $this->get('/submissions/'.$s->id.'/file')->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->actingAs($coach)->get('/submissions/'.$s->id.'/file')->assertOk();
        $this->actingAs($this->user('student'))->get('/submissions/'.$s->id.'/file')->assertForbidden();
        $this->actingAs($this->user('coach'))->get('/submissions/'.$s->id.'/file')->assertForbidden();
        $this->assertFalse(Storage::disk('public')->exists($s->file_path));
    }

    public function test_submission_requires_active_same_cohort_and_rejects_future_or_closed_assignment(): void
    {
        [$coach,$student,$e,$a] = $this->fixture();
        $foreign = Assignment::factory()->create();
        $this->actingAs($student);
        $this->post($this->url($e, $foreign), ['body' => 'پاسخ', 'expected_version' => 0])->assertForbidden();
        $a->update(['available_at' => now()->addDay()]);
        $this->post($this->url($e, $a), ['body' => 'پاسخ', 'expected_version' => 0])->assertForbidden();
        $a->update(['available_at' => null, 'closes_at' => now()->subSecond()]);
        $this->post($this->url($e, $a), ['body' => 'پاسخ', 'expected_version' => 0])->assertSessionHasErrors('body');
        $a->update(['closes_at' => null]);
        $e->update(['status' => 'pending_payment']);
        $this->post($this->url($e, $a), ['body' => 'پاسخ', 'expected_version' => 0])->assertForbidden();
        $this->assertDatabaseCount('submissions', 0);
    }

    public function test_stale_duplicate_submission_does_not_replace_current_response_or_store_orphan_file(): void
    {
        [$coach,$student,$e,$a] = $this->fixture();
        $this->actingAs($student);
        $this->post($this->url($e, $a), ['body' => 'پاسخ اول', 'expected_version' => 0])->assertSessionHasNoErrors();
        $this->post($this->url($e, $a), ['body' => 'پاسخ قدیمی', 'expected_version' => 0, 'file' => UploadedFile::fake()->create('answer.pdf', 10, 'application/pdf')])->assertSessionHasErrors('body');
        $this->assertDatabaseCount('submissions', 1);
        $this->assertSame([], Storage::disk('learning')->allFiles());
    }

    public function test_feedback_is_versioned_and_creates_one_persistent_notification(): void
    {
        [$coach,$student,$e,$a] = $this->fixture();
        $this->actingAs($student)->post($this->url($e, $a), ['body' => 'پاسخ', 'expected_version' => 0]);
        $s = Submission::first();
        $this->actingAs($coach)->patch('/submissions/'.$s->id.'/review', ['status' => 'reviewed', 'score' => 0, 'feedback' => 'نیازمند تلاش', 'expected_review_version' => '0'])->assertSessionHasNoErrors();
        $this->assertSame('reviewed', $s->fresh()->status);
        $this->assertSame(0, $s->fresh()->score);
        $this->assertDatabaseCount('notifications', 1);
        $this->patch('/submissions/'.$s->id.'/review', ['status' => 'reviewed', 'score' => 90, 'expected_review_version' => 0])->assertSessionHasErrors('score');
        $this->assertDatabaseCount('notifications', 1);
    }

    public function test_revision_creates_new_attempt_preserving_file_and_feedback_history(): void
    {
        [$coach,$student,$e,$a] = $this->fixture();
        $this->actingAs($student)->post($this->url($e, $a), ['body' => 'اول', 'expected_version' => 0, 'file' => UploadedFile::fake()->create('one.pdf', 10, 'application/pdf')]);
        $first = Submission::first();
        $this->actingAs($coach)->patch('/submissions/'.$first->id.'/review', ['status' => 'revision_requested', 'feedback' => 'بخش دوم را اصلاح کنید.', 'expected_review_version' => 0])->assertSessionHasNoErrors();
        $this->actingAs($student)->post($this->url($e, $a), ['body' => 'اصلاح‌شده', 'expected_version' => '1'])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('submissions', 2);
        $this->assertSame('revision_requested', $first->fresh()->status);
        Storage::disk('learning')->assertExists($first->file_path);
        $this->actingAs($coach)->patch('/submissions/'.$first->id.'/review', ['status' => 'reviewed', 'score' => 100, 'expected_review_version' => 1])->assertSessionHasErrors('score');
    }

    public function test_reviewed_response_requires_reopening_before_resubmission(): void
    {
        [$coach,$student,$e,$a] = $this->fixture();
        $s = Submission::factory()->create(['assignment_id' => $a->id, 'enrollment_id' => $e->id, 'status' => 'reviewed', 'score' => 90]);
        $this->actingAs($student)->post($this->url($e, $a), ['body' => 'تغییر بدون اجازه', 'expected_version' => 1])->assertSessionHasErrors('body');
        $this->assertDatabaseCount('submissions', 1);
    }

    public function test_unassigned_coach_and_student_cannot_grade_and_score_cannot_exceed_maximum(): void
    {
        [$coach,$student,$e,$a] = $this->fixture();
        $a->update(['max_score' => 20]);
        $s = Submission::factory()->create(['assignment_id' => $a->id, 'enrollment_id' => $e->id]);
        $data = ['status' => 'reviewed', 'score' => 21, 'expected_review_version' => 0];
        $this->actingAs($this->user('coach'))->patch('/submissions/'.$s->id.'/review', $data)->assertForbidden();
        $this->actingAs($student)->patch('/submissions/'.$s->id.'/review', $data)->assertForbidden();
        $this->actingAs($coach)->patch('/submissions/'.$s->id.'/review', $data)->assertSessionHasErrors('score');
        $this->assertDatabaseCount('notifications', 0);
    }

    public function test_unsafe_files_and_empty_answers_are_rejected(): void
    {
        [$coach,$student,$e,$a] = $this->fixture();
        $this->actingAs($student)->post($this->url($e, $a), ['expected_version' => 0])->assertSessionHasErrors('body');
        $this->post($this->url($e, $a), ['expected_version' => 0, 'file' => UploadedFile::fake()->create('bad.php', 2, 'application/x-httpd-php')])->assertSessionHasErrors('file');
        $this->assertDatabaseCount('submissions', 0);
        $this->assertSame([], Storage::disk('learning')->allFiles());
    }

    public function test_notification_can_only_be_marked_read_by_its_recipient(): void
    {
        [$coach,$student,$e,$a] = $this->fixture();
        $s = Submission::factory()->create(['assignment_id' => $a->id, 'enrollment_id' => $e->id]);
        $this->actingAs($coach)->patch('/submissions/'.$s->id.'/review', ['status' => 'reviewed', 'score' => 10, 'expected_review_version' => 0]);
        $notification = $student->notifications()->first();
        $this->actingAs($this->user('student'))->post('/notifications/'.$notification->id.'/read')->assertNotFound();
        $this->assertNull($notification->fresh()->read_at);
        $this->actingAs($student)->post('/notifications/'.$notification->id.'/read')->assertSessionHasNoErrors();
        $this->assertNotNull($notification->fresh()->read_at);
    }

    public function test_manager_has_full_report_and_file_access_only_for_selected_organization_members(): void
    {
        [$coach,$student,$e,$a] = $this->fixture();
        $this->actingAs($student)->post($this->url($e, $a), ['body' => 'پاسخ گزارش فردی', 'expected_version' => 0, 'file' => UploadedFile::fake()->create('report.pdf', 10, 'application/pdf')]);
        $s = Submission::first();
        $manager = $this->user('org_admin');
        $org = Organization::create(['name' => 'سازمان یک', 'type' => 'company']);
        $other = Organization::create(['name' => 'سازمان دو', 'type' => 'university']);
        $org->members()->attach($manager->id, ['kind' => 'manager']);
        $other->members()->attach($manager->id, ['kind' => 'manager']);
        $org->members()->attach($student->id);
        $this->actingAs($manager)->withSession(['organization_id' => $org->id])->get('/submissions/'.$s->id.'/file')->assertOk();
        $this->get('/organization/members/'.$student->id)->assertInertia(fn (AssertableInertia $p) => $p->where('learningReports.'.$e->id.'.submissions.0.body', 'پاسخ گزارش فردی'));
        $this->withSession(['organization_id' => $other->id])->get('/submissions/'.$s->id.'/file')->assertForbidden();
        $org->members()->detach($student->id);
        $this->withSession(['organization_id' => $org->id])->get('/submissions/'.$s->id.'/file')->assertForbidden();
    }

    public function test_every_feedback_revision_is_preserved_and_reduced_maximum_cannot_invalidate_a_score(): void
    {
        [$coach,$student,$e,$a] = $this->fixture();
        $s = Submission::factory()->create(['assignment_id' => $a->id, 'enrollment_id' => $e->id]);
        $this->actingAs($coach)->patch('/submissions/'.$s->id.'/review', ['status' => 'reviewed', 'score' => 30, 'feedback' => 'بازخورد اول', 'expected_review_version' => 0])->assertSessionHasNoErrors();
        $this->patch('/submissions/'.$s->id.'/review', ['status' => 'reviewed', 'score' => 40, 'feedback' => 'بازخورد اصلاح‌شده', 'expected_review_version' => 1])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('submission_reviews', 2);
        $this->assertDatabaseHas('submission_reviews', ['submission_id' => $s->id, 'revision' => 1, 'feedback' => 'بازخورد اول', 'score' => 30]);
        $this->patch('/teaching/'.$e->course_id.'/cohorts/'.$e->cohort_id.'/assignments/'.$a->id, ['title' => $a->title, 'instructions' => $a->instructions, 'max_score' => 20, 'is_published' => true])->assertSessionHasErrors('max_score');
        $this->assertSame(100, $a->fresh()->max_score);
    }

    public function test_cancelled_student_loses_download_access_and_html_disguised_as_pdf_is_rejected(): void
    {
        [$coach,$student,$e,$a] = $this->fixture();
        $this->actingAs($student)->post($this->url($e, $a), ['expected_version' => 0, 'file' => UploadedFile::fake()->create('fake.pdf', 10, 'text/html')])->assertSessionHasErrors('file');
        $this->post($this->url($e, $a), ['expected_version' => 0, 'file' => UploadedFile::fake()->create('huge.pdf', 10241, 'application/pdf')])->assertSessionHasErrors('file');
        $this->post($this->url($e, $a), ['expected_version' => 0, 'file' => UploadedFile::fake()->create('real.pdf', 10, 'application/pdf')])->assertSessionHasNoErrors();
        $s = Submission::first();
        $e->update(['status' => 'cancelled']);
        $this->get('/submissions/'.$s->id.'/file')->assertForbidden();
    }

    public function test_student_cannot_see_another_students_answer_or_feedback(): void
    {
        [$coach,$student,$e,$a] = $this->fixture();
        $otherEnrollment = Enrollment::factory()->create(['cohort_id' => $e->cohort_id]);
        Submission::factory()->create(['assignment_id' => $a->id, 'enrollment_id' => $otherEnrollment->id, 'body' => 'SECRET_OTHER_STUDENT', 'feedback' => 'SECRET_PRIVATE_FEEDBACK']);
        $this->actingAs($student)->get('/learning/'.$e->id.'?tab=assignments')->assertOk()->assertDontSee('SECRET_OTHER_STUDENT')->assertDontSee('SECRET_PRIVATE_FEEDBACK');
    }

    public function test_staff_can_review_previous_attempts_without_showing_them_as_current_submissions(): void
    {
        [$coach,$student,$e,$a] = $this->fixture();
        Submission::factory()->create(['assignment_id' => $a->id, 'enrollment_id' => $e->id, 'version' => 1, 'body' => 'PREVIOUS_RESPONSE', 'status' => 'revision_requested', 'feedback' => 'بازخورد قبلی']);
        Submission::factory()->create(['assignment_id' => $a->id, 'enrollment_id' => $e->id, 'version' => 2, 'body' => 'CURRENT_RESPONSE']);
        $this->actingAs($coach)->get('/teaching/'.$e->course_id)->assertInertia(fn (AssertableInertia $p) => $p->has('submissions', 1)->where('submissions.0.body', 'CURRENT_RESPONSE')->has('submissions.0.attempt_history', 1)->where('submissions.0.attempt_history.0.body', 'PREVIOUS_RESPONSE'));
        $this->patch('/teaching/'.$e->course_id.'/cohorts/'.$e->cohort_id.'/assignments/'.$a->id, ['title' => $a->title, 'instructions' => $a->instructions, 'max_score' => 200, 'is_published' => true])->assertSessionHasErrors('max_score');
    }
}
