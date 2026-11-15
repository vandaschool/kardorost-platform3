<?php

namespace Tests\Feature;

use App\Models\AuditEvent;
use App\Models\CourseSurvey;
use App\Models\Enrollment;
use App\Models\Role;
use App\Models\SurveyPublication;
use App\Models\SurveyResponse;
use App\Models\User;
use App\Services\CourseSurveys;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CourseSurveyTest extends TestCase
{
    use RefreshDatabase;

    private CourseSurvey $survey;

    private User $admin;

    private User $student;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        $this->travelTo(now()->startOfMinute());
        $this->survey = CourseSurvey::factory()->create();
        $this->admin = $this->user('admin');
        $this->student = $this->member();
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('key', $role)->value('id'));

        return $user;
    }

    private function member(): User
    {
        $user = $this->user('student');
        Enrollment::factory()->create(['user_id' => $user->id, 'cohort_id' => $this->survey->cohort_id]);

        return $user;
    }

    private function publish(array $changes = []): SurveyPublication
    {
        $service = app(CourseSurveys::class);
        $definition = [...$this->survey->fresh()->draft, ...$changes];
        $service->draft($this->admin, $this->survey, $this->survey->fresh()->revision, $definition);

        return $service->publish($this->admin, $this->survey, $this->survey->fresh()->revision, null, null);
    }

    private function submit(SurveyPublication $publication, ?User $user = null, array $answers = ['quality' => 4, 'comment' => 'بازخورد آموزشی']): SurveyResponse
    {
        $user ??= $this->student;
        $response = app(CourseSurveys::class)->start($user, $publication);
        app(CourseSurveys::class)->save($user, $response, $response->fresh()->revision, $answers, true);

        return $response->fresh();
    }

    public function test_only_admin_can_create_edit_publish_and_change_receiving(): void
    {
        $publication = $this->publish();
        foreach ([$this->student, $this->survey->cohort->course->coach_id ? User::findOrFail($this->survey->cohort->course->coach_id) : $this->user('coach'), $this->user('org_admin')] as $user) {
            $this->actingAs($user)->post('/admin/surveys', ['title' => 'غیرمجاز', 'cohort_id' => $this->survey->cohort_id])->assertForbidden();
            $this->patch('/admin/surveys/'.$this->survey->id.'/draft', ['revision' => 1, 'definition' => $this->survey->draft])->assertForbidden();
            $this->post('/admin/surveys/'.$this->survey->id.'/publish', ['revision' => 1, 'reviewed' => true])->assertForbidden();
            $this->post('/admin/survey-publications/'.$publication->id.'/receiving', ['revision' => 1, 'accepting' => false])->assertForbidden();
        }
    }

    public function test_definition_validates_question_keys_types_threshold_and_ordered_lists(): void
    {
        $url = '/admin/surveys/'.$this->survey->id.'/draft';
        foreach ([['mode' => 'anonymous'], ['minimum_responses' => 1], ['questions' => []], ['options' => ['one']], ['questions' => [['key' => 'x', 'type' => 'rating', 'text' => 'پرسش', 'required' => true], ['key' => 'x', 'type' => 'rating', 'text' => 'تکرار', 'required' => true]]]] as $changes) {
            $this->actingAs($this->admin)->patch($url, ['revision' => 1, 'definition' => [...$this->survey->draft, ...$changes]])->assertSessionHasErrors();
        }
        $this->assertSame(1, $this->survey->fresh()->revision);
    }

    public function test_publications_are_immutable_and_duplicate_or_stale_publish_is_rejected(): void
    {
        $publication = $this->publish();
        $definition = $publication->definition;
        $this->actingAs($this->admin)->post('/admin/surveys/'.$this->survey->id.'/publish', ['revision' => $this->survey->fresh()->revision, 'reviewed' => false])->assertSessionHasErrors('reviewed');
        $this->post('/admin/surveys/'.$this->survey->id.'/publish', ['revision' => $this->survey->fresh()->revision, 'reviewed' => true])->assertSessionHasErrors('survey');
        $second = $this->publish(['title' => 'عنوان نسخه جدید', 'mode' => 'identified']);
        $this->assertSame($definition, $publication->fresh()->definition);
        $this->assertSame(2, $second->number);
        $this->patch('/admin/surveys/'.$this->survey->id.'/draft', ['revision' => 1, 'definition' => $definition])->assertSessionHasErrors('survey');
    }

    public function test_only_verified_active_members_of_exact_cohort_can_start(): void
    {
        $publication = $this->publish();
        foreach ([$this->user('student'), $this->user('coach'), $this->user('org_admin')] as $user) {
            $this->actingAs($user)->post('/survey-publications/'.$publication->id.'/start')->assertForbidden();
        }
        Enrollment::where('user_id', $this->student->id)->update(['status' => 'pending_payment']);
        $this->actingAs($this->student)->post('/survey-publications/'.$publication->id.'/start')->assertForbidden();
        Enrollment::where('user_id', $this->student->id)->update(['status' => 'active']);
        $this->student->update(['phone_verified_at' => null]);
        $this->post('/survey-publications/'.$publication->id.'/start')->assertForbidden();
        $this->assertDatabaseCount('survey_responses', 0);
    }

    public function test_response_start_is_idempotent_and_identity_scope_is_frozen_before_answering(): void
    {
        $publication = $this->publish();
        $one = app(CourseSurveys::class)->start($this->student, $publication);
        $two = app(CourseSurveys::class)->start($this->student, $publication);
        $this->assertSame($one->id, $two->id);
        $this->publish(['mode' => 'identified']);
        $this->actingAs($this->student)->get('/survey-responses/'.$one->id)->assertInertia(fn (Assert $page) => $page->where('publication.definition.mode', 'aggregate'));
        $this->assertDatabaseCount('survey_responses', 1);
    }

    public function test_draft_save_merges_answers_rejects_stale_form_and_encrypts_at_rest(): void
    {
        $publication = $this->publish();
        $response = app(CourseSurveys::class)->start($this->student, $publication);
        $service = app(CourseSurveys::class);
        $service->save($this->student, $response, 1, ['quality' => '3'], false);
        $service->save($this->student, $response, 2, ['comment' => 'متن خصوصی رمزگذاری‌شده'], false);
        $this->assertSame(['quality' => 3, 'comment' => 'متن خصوصی رمزگذاری‌شده'], $response->fresh()->answers);
        $raw = DB::table('survey_responses')->where('id', $response->id)->value('answers');
        $this->assertStringNotContainsString('متن خصوصی', $raw);
        $this->assertArrayNotHasKey('answers', $response->fresh()->toArray());
        $this->actingAs($this->student)->post('/survey-responses/'.$response->id.'/save', ['revision' => 1, 'answers' => ['quality' => 1]])->assertSessionHasErrors('survey');
    }

    public function test_invalid_rating_unknown_question_and_empty_final_do_not_submit(): void
    {
        $publication = $this->publish();
        $response = app(CourseSurveys::class)->start($this->student, $publication);
        foreach ([['quality' => 0], ['quality' => 6], ['quality' => true], ['unknown' => 'x'], ['comment' => str_repeat('x', 2001)], []] as $answers) {
            $this->actingAs($this->student)->post('/survey-responses/'.$response->id.'/submit', ['revision' => 1, 'answers' => $answers])->assertSessionHasErrors('survey');
        }
        $this->assertSame('in_progress', $response->fresh()->status);
    }

    public function test_final_submission_is_immutable_and_replay_never_rewrites_it(): void
    {
        $publication = $this->publish(['mode' => 'identified']);
        $response = $this->submit($publication);
        $name = $this->student->name;
        app(CourseSurveys::class)->save($this->student, $response, 1, ['quality' => 1, 'comment' => 'تغییر نهایی غیرمجاز'], true);
        $this->assertSame(4, $response->fresh()->answers['quality']);
        $this->assertSame($name, $response->respondent_name);
        $this->assertSame(1, AuditEvent::where('action', 'survey.submitted')->count());
        $this->actingAs($this->student)->post('/survey-responses/'.$response->id.'/save', ['revision' => 2, 'answers' => ['quality' => 1]])->assertSessionHasErrors('survey');
    }

    public function test_closing_publication_blocks_unfinished_response_but_preserves_saved_answers(): void
    {
        $publication = $this->publish();
        $response = app(CourseSurveys::class)->start($this->student, $publication);
        app(CourseSurveys::class)->save($this->student, $response, 1, ['quality' => 5], false);
        app(CourseSurveys::class)->receiving($this->admin, $publication, 1, false);
        $this->actingAs($this->student)->post('/survey-responses/'.$response->id.'/submit', ['revision' => 2, 'answers' => ['quality' => 5]])->assertSessionHasErrors('survey');
        $this->get('/survey-responses/'.$response->id)->assertInertia(fn (Assert $page) => $page->where('response.writable', false)->where('response.answers.quality', 5));
        app(CourseSurveys::class)->receiving($this->admin, $publication->fresh(), 2, true);
        $this->post('/survey-responses/'.$response->id.'/submit', ['revision' => 2, 'answers' => ['quality' => 5]])->assertSessionHasNoErrors();
    }

    public function test_start_availability_does_not_block_existing_draft_and_new_version_does_not_change_it(): void
    {
        $publication = $this->publish();
        $response = app(CourseSurveys::class)->start($this->student, $publication);
        app(CourseSurveys::class)->availability($this->admin, $this->survey, $this->survey->fresh()->revision, false);
        app(CourseSurveys::class)->save($this->student, $response, 1, ['quality' => 4], true);
        $this->assertSame('submitted', $response->fresh()->status);
        $this->actingAs($this->member())->post('/survey-publications/'.$publication->id.'/start')->assertSessionHasErrors('survey');
    }

    public function test_opening_and_deadline_are_enforced_for_start_and_submit(): void
    {
        $publication = $this->publish();
        $publication->update(['opens_at' => now()->addHour(), 'closes_at' => now()->addHours(2)]);
        $this->actingAs($this->student)->post('/survey-publications/'.$publication->id.'/start')->assertSessionHasErrors('survey');
        $this->travel(61)->minutes();
        $response = app(CourseSurveys::class)->start($this->student, $publication->fresh());
        $this->travel(60)->minutes();
        $this->post('/survey-responses/'.$response->id.'/submit', ['revision' => 1, 'answers' => ['quality' => 4]])->assertSessionHasErrors('survey');
    }

    public function test_response_owner_is_enforced_even_for_admin_and_drafts_never_appear_in_reports(): void
    {
        $publication = $this->publish(['mode' => 'identified']);
        $response = app(CourseSurveys::class)->start($this->student, $publication);
        app(CourseSurveys::class)->save($this->student, $response, 1, ['quality' => 5, 'comment' => 'متن پیش‌نویس'], false);
        foreach ([$this->admin, $this->member()] as $user) {
            $this->actingAs($user)->get('/survey-responses/'.$response->id)->assertForbidden();
        }
        $this->actingAs($this->admin)->get('/survey-publications/'.$publication->id.'/report')->assertInertia(fn (Assert $page) => $page->where('report.count', 0)->where('report.unlocked', false)->where('identified_rows', null));
    }

    public function test_aggregate_report_suppresses_data_before_minimum_without_individual_identifiers(): void
    {
        $publication = $this->publish();
        $this->submit($publication);
        $this->submit($publication, $this->member());
        $this->actingAs($this->admin)->get('/survey-publications/'.$publication->id.'/report')->assertInertia(fn (Assert $page) => $page->where('report.count', 2)->where('report.unlocked', false)->has('report.questions', 0)->where('identified_rows', null)->where('comments.total', 0));
        $this->submit($publication, $this->member(), ['quality' => 1, 'comment' => 'نظر سوم']);
        $this->get('/survey-publications/'.$publication->id.'/report')->assertInertia(fn (Assert $page) => $page->where('report.questions.0.average', 3)->where('report.questions.0.distribution.3.count', 2)->where('identified_rows', null)->has('comments.data', 3)->missing('comments.data.0.respondent_name')->missing('comments.data.0.id')->missing('comments.data.0.submitted_at'));
        $this->assertNull(SurveyResponse::first()->respondent_name);
    }

    public function test_optional_question_requires_its_own_minimum_before_any_values_are_revealed(): void
    {
        $publication = $this->publish(['questions' => [['key' => 'quality', 'text' => 'امتیاز', 'type' => 'rating', 'required' => false], ['key' => 'comment', 'text' => 'نظر', 'type' => 'text', 'required' => true]]]);
        $this->submit($publication, $this->student, ['quality' => 1, 'comment' => 'نظر اول']);
        $this->submit($publication, $this->member(), ['comment' => 'نظر دوم']);
        $this->submit($publication, $this->member(), ['comment' => 'نظر سوم']);
        $this->actingAs($this->admin)->get('/survey-publications/'.$publication->id.'/report')->assertInertia(fn (Assert $page) => $page->where('report.unlocked', true)->where('report.questions.0.unlocked', false)->missing('report.questions.0.average')->missing('report.questions.0.distribution')->where('report.questions.1.unlocked', true));
    }

    public function test_identified_report_includes_only_final_snapshot_name_and_no_phone_or_enrollment_id(): void
    {
        $publication = $this->publish(['mode' => 'identified']);
        $name = $this->student->name;
        $this->submit($publication);
        $this->student->update(['name' => 'نام تازه حساب']);
        $this->actingAs($this->admin)->get('/survey-publications/'.$publication->id.'/report')->assertInertia(fn (Assert $page) => $page->where('identified_rows.data.0.respondent_name', $name)->where('identified_rows.data.0.answers.quality', 4)->missing('identified_rows.data.0.phone')->missing('identified_rows.data.0.enrollment_id'));
    }

    public function test_current_assigned_coach_and_admin_can_report_but_peers_and_organization_cannot(): void
    {
        $publication = $this->publish();
        $coach = User::findOrFail($this->survey->cohort->course->coach_id);
        $this->actingAs($coach)->get('/survey-publications/'.$publication->id.'/report')->assertOk();
        $this->get('/cohorts/'.$this->survey->cohort_id.'/survey-reports')->assertOk();
        foreach ([$this->student, $this->user('coach'), $this->user('org_admin')] as $user) {
            $this->actingAs($user)->get('/survey-publications/'.$publication->id.'/report')->assertForbidden();
        }
        $this->survey->cohort->course->update(['coach_id' => $this->user('coach')->id]);
        $this->actingAs($coach)->get('/survey-publications/'.$publication->id.'/report')->assertForbidden();
    }

    public function test_student_catalog_shows_scope_before_start_and_only_owned_history(): void
    {
        $publication = $this->publish(['mode' => 'identified']);
        $this->submit($publication);
        $this->actingAs($this->student)->get('/surveys')->assertInertia(fn (Assert $page) => $page->where('offers.0.mode', 'identified')->has('responses.data', 1));
        $this->actingAs($this->user('student'))->get('/surveys')->assertInertia(fn (Assert $page) => $page->has('offers', 0)->has('responses.data', 0));
    }

    public function test_aggregate_text_is_withheld_until_that_question_reaches_minimum(): void
    {
        $publication = $this->publish();
        $this->submit($publication, $this->student, ['quality' => 5, 'comment' => 'این نظر تک‌نفره نباید گزارش شود']);
        $this->submit($publication, $this->member(), ['quality' => 4]);
        $this->submit($publication, $this->member(), ['quality' => 3]);
        $this->actingAs($this->admin)->get('/survey-publications/'.$publication->id.'/report')->assertInertia(fn (Assert $page) => $page->where('report.unlocked', true)->where('report.questions.1.unlocked', false)->has('comments.data', 0)->where('comments.total', 0));
    }

    public function test_publish_allows_deadline_without_start_and_rejects_inverted_dates(): void
    {
        $url = '/admin/surveys/'.$this->survey->id.'/publish';
        $this->actingAs($this->admin)->post($url, ['revision' => 1, 'reviewed' => true, 'opens_at' => null, 'closes_at' => now()->addDay()->toIso8601String()])->assertSessionHasNoErrors();
        $this->assertDatabaseCount('survey_publications', 1);
        $this->post($url, ['revision' => $this->survey->fresh()->revision, 'reviewed' => true, 'opens_at' => now()->addDays(2)->toIso8601String(), 'closes_at' => now()->addDay()->toIso8601String()])->assertSessionHasErrors('closes_at');
    }

    public function test_deadline_extension_is_versioned_and_preserves_frozen_question_definition(): void
    {
        $publication = $this->publish();
        $definition = $publication->definition;
        $url = '/admin/survey-publications/'.$publication->id.'/receiving';
        $this->actingAs($this->admin)->post($url, ['revision' => 1, 'accepting' => true, 'opens_at' => null, 'closes_at' => now()->addDays(2)->toIso8601String()])->assertSessionHasNoErrors();
        $this->assertSame($definition, $publication->fresh()->definition);
        $this->post($url, ['revision' => 1, 'accepting' => false])->assertSessionHasErrors('survey');
        $this->post($url, ['revision' => 2, 'accepting' => true, 'opens_at' => now()->addDays(3)->toIso8601String(), 'closes_at' => now()->addDay()->toIso8601String()])->assertSessionHasErrors('survey');
        $this->assertSame(2, $publication->fresh()->revision);
    }

    public function test_migration_guard_preserves_collected_feedback(): void
    {
        $this->submit($this->publish());
        $migration = require database_path('migrations/2026_11_15_133419_create_course_surveys.php');
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }
}
