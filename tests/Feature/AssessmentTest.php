<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentAttempt;
use App\Models\AssessmentVersion;
use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Services\AssessmentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class AssessmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
    }

    private function user(string $role = 'student'): User
    {
        $u = User::factory()->create();
        $u->roles()->attach(Role::where('key', $role)->value('id'));

        return $u;
    }

    private function definition(): array
    {
        return AssessmentVersion::factory()->definition()['definition'];
    }

    private function published(): Assessment
    {
        $a = Assessment::factory()->create();
        $admin = $this->user('admin');
        app(AssessmentService::class)->saveDraft($admin, $a, 0, $this->definition());
        app(AssessmentService::class)->publish($admin, $a, 1);

        return $a->fresh();
    }

    private function attempt(?User $u = null, ?Assessment $a = null): AssessmentAttempt
    {
        return app(AssessmentService::class)->start($u ?? $this->user(), $a ?? $this->published());
    }

    private function complete(AssessmentAttempt $a): AssessmentAttempt
    {
        app(AssessmentService::class)->save($a->user, $a, 1, ['q1' => 5, 'q2' => 5]);

        return app(AssessmentService::class)->finish($a->user, $a, 2);
    }

    public function test_admin_can_save_and_publish_after_explicit_review(): void
    {
        $a = Assessment::factory()->create();
        $this->actingAs($this->user('admin'))->patch('/admin/assessments/'.$a->id.'/draft', ['revision' => 0, 'definition' => $this->definition()])->assertSessionHasNoErrors();
        $this->post('/admin/assessments/'.$a->id.'/publish', ['revision' => 1])->assertSessionHasErrors('reviewed');
        $this->post('/admin/assessments/'.$a->id.'/publish', ['revision' => 1, 'reviewed' => true])->assertSessionHasNoErrors();
        $this->assertSame(1, $a->fresh()->current_version);
        $this->assertTrue($a->fresh()->available);
        $this->assertDatabaseCount('assessment_versions', 1);
    }

    public function test_non_admin_cannot_change_or_publish_questionnaires(): void
    {
        $a = Assessment::factory()->create();
        foreach (['student', 'coach', 'org_admin'] as $role) {
            $this->actingAs($this->user($role))->patch('/admin/assessments/'.$a->id.'/draft', [])->assertForbidden();
            $this->post('/admin/assessments/'.$a->id.'/publish', [])->assertForbidden();
            $this->post('/admin/assessments', [])->assertForbidden();
        }
    }

    public function test_validation_rejects_duplicate_keys_unknown_dimensions_and_wrong_choice_count(): void
    {
        $a = Assessment::factory()->create();
        $admin = $this->user('admin');
        foreach (['duplicate', 'dimension', 'options', 'empty_dimension'] as $case) {
            $d = $this->definition();
            if ($case === 'duplicate') {
                $d['questions'][1]['key'] = 'q1';
            }if ($case === 'dimension') {
                $d['questions'][0]['dimension'] = 'missing';
            }if ($case === 'options') {
                $d['options'][] = 'six';
            }if ($case === 'empty_dimension') {
                $d['dimensions'][] = ['key' => 'unused', 'title' => 'Unused'];
            }
            $this->actingAs($admin)->patch('/admin/assessments/'.$a->id.'/draft', ['revision' => 0, 'definition' => $d])->assertSessionHasErrors();
        }$this->assertNull($a->fresh()->draft);
    }

    public function test_stale_admin_form_and_double_publish_do_not_overwrite_versions(): void
    {
        $a = $this->published();
        $admin = $this->user('admin');
        $d = $this->definition();
        $d['title'] = 'ویرایش تازه';
        $this->actingAs($admin)->patch('/admin/assessments/'.$a->id.'/draft', ['revision' => 0, 'definition' => $d])->assertSessionHasErrors('assessment');
        $this->post('/admin/assessments/'.$a->id.'/publish', ['revision' => 2, 'reviewed' => true])->assertSessionHasErrors('assessment');
        $this->assertDatabaseCount('assessment_versions', 1);
    }

    public function test_students_never_see_drafts_and_start_requires_acknowledgement(): void
    {
        $a = Assessment::factory()->create(['draft' => $this->definition()]);
        $u = $this->user();
        $this->actingAs($u)->get('/assessments')->assertInertia(fn (Assert $p) => $p->has('assessments', 0));
        $this->post('/assessments/'.$a->id.'/start', ['acknowledged' => true])->assertSessionHasErrors('assessment');
        $a = $this->published();
        $this->post('/assessments/'.$a->id.'/start', [])->assertSessionHasErrors('acknowledged');
        $this->assertDatabaseCount('assessment_attempts', 0);
    }

    public function test_double_start_resumes_one_attempt_for_each_published_version(): void
    {
        $a = $this->published();
        $u = $this->user();
        $first = $this->attempt($u, $a);
        $second = $this->attempt($u, $a);
        $this->assertSame($first->id, $second->id);
        $this->assertDatabaseCount('assessment_attempts', 1);
    }

    public function test_room_hides_scoring_keys_and_other_users_answers(): void
    {
        $a = $this->attempt();
        $this->actingAs($a->user)->get('/assessment-attempts/'.$a->id)->assertInertia(fn (Assert $p) => $p->has('questions', 2)->missing('questions.0.reverse')->missing('questions.0.dimension')->missing('attempt.answers')->missing('attempt.result')->missing('definition'));
        $this->actingAs($this->user())->get('/assessment-attempts/'.$a->id)->assertForbidden();
        $this->post('/assessment-attempts/'.$a->id.'/answers', ['revision' => 1, 'answers' => ['q1' => 1], 'next_page' => 1])->assertForbidden();
    }

    public function test_partial_answers_resume_and_are_encrypted_at_rest(): void
    {
        $a = $this->attempt();
        $this->actingAs($a->user)->post('/assessment-attempts/'.$a->id.'/answers', ['revision' => 1, 'answers' => ['q1' => 4], 'next_page' => 1])->assertSessionHasNoErrors();
        $a->refresh();
        $this->assertSame(['q1' => 4], $a->answers);
        $this->assertSame(2, $a->revision);
        $this->assertStringNotContainsString('q1', DB::table('assessment_attempts')->where('id', $a->id)->value('answers'));
        $this->get('/assessment-attempts/'.$a->id)->assertInertia(fn (Assert $p) => $p->where('answers.q1', 4)->where('answered', 1));
    }

    public function test_unknown_question_invalid_type_and_out_of_range_answers_are_rejected_atomically(): void
    {
        $a = $this->attempt();
        foreach ([['unknown' => 2], ['q1' => 6], ['q1' => 0], ['q1' => 3.0], ['q1' => '3.0'], ['q1' => true], ['q1' => ['nested' => 2]]] as $answers) {
            $this->actingAs($a->user)->post('/assessment-attempts/'.$a->id.'/answers', ['revision' => 1, 'answers' => $answers, 'next_page' => 1])->assertSessionHasErrors('assessment');
        }$this->assertSame([], $a->fresh()->answers);
        $this->assertSame(1, $a->fresh()->revision);
    }

    public function test_stale_answer_form_cannot_replace_newer_answers(): void
    {
        $a = $this->attempt();
        $this->actingAs($a->user)->post('/assessment-attempts/'.$a->id.'/answers', ['revision' => 1, 'answers' => ['q1' => 2], 'next_page' => 1])->assertSessionHasNoErrors();
        $this->post('/assessment-attempts/'.$a->id.'/answers', ['revision' => 1, 'answers' => ['q1' => 5], 'next_page' => 1])->assertSessionHasErrors('assessment');
        $this->assertSame(['q1' => 2], $a->fresh()->answers);
    }

    public function test_finish_requires_every_answer_from_the_same_edition(): void
    {
        $a = $this->attempt();
        $this->actingAs($a->user)->post('/assessment-attempts/'.$a->id.'/finish', ['revision' => 1, 'answers' => ['q1' => 5, 'q2' => 1]])->assertSessionHasErrors('assessment');
        $this->assertSame('in_progress', $a->fresh()->status);
        $this->assertNull($a->fresh()->result);
    }

    public function test_reverse_score_minimum_maximum_and_midpoint_are_exact(): void
    {
        $d = $this->definition();
        foreach ([[['q1' => 1, 'q2' => 5], 2, 0], [['q1' => 5, 'q2' => 1], 10, 100], [['q1' => 3, 'q2' => 3], 6, 50]] as [$answers,$sum,$percent]) {
            $r = app(AssessmentService::class)->score($d, $answers);
            $this->assertSame($sum, $r['dimensions'][0]['sum']);
            $this->assertEquals($percent, $r['dimensions'][0]['range_percent']);
            $this->assertSame(2, $r['dimensions'][0]['min']);
            $this->assertSame(10, $r['dimensions'][0]['max']);
            $this->assertArrayNotHasKey('overall_score', $r);
        }
    }

    public function test_dimensions_are_scored_independently_despite_different_item_counts(): void
    {
        $d = $this->definition();
        $d['dimensions'][] = ['key' => 'other', 'title' => 'بعد دوم', 'description' => ''];
        $d['questions'][] = ['key' => 'q3', 'text' => 'Other question', 'dimension' => 'other', 'reverse' => false];
        $r = app(AssessmentService::class)->score($d, ['q1' => 5, 'q2' => 1, 'q3' => 1]);
        $this->assertEquals(100, $r['dimensions'][0]['range_percent']);
        $this->assertEquals(0, $r['dimensions'][1]['range_percent']);
    }

    public function test_finalization_is_idempotent_immutable_and_result_is_encrypted(): void
    {
        $a = $this->complete($this->attempt());
        $this->assertSame('completed', $a->status);
        $this->assertSame(6, $a->result['dimensions'][0]['sum']);
        $this->assertStringNotContainsString('dimensions', DB::table('assessment_attempts')->where('id', $a->id)->value('result'));
        $this->actingAs($a->user)->post('/assessment-attempts/'.$a->id.'/finish', ['revision' => 2])->assertRedirect('/assessment-attempts/'.$a->id.'/report');
        $this->assertSame(1, AuditEvent::where('action', 'assessment.completed')->where('subject_id', $a->id)->count());
        $this->post('/assessment-attempts/'.$a->id.'/answers', ['revision' => 3, 'answers' => ['q1' => 1], 'next_page' => 1])->assertSessionHasErrors('assessment');
        $this->assertSame(5, $a->fresh()->answers['q1']);
    }

    public function test_new_publication_never_changes_old_attempt_or_report(): void
    {
        $a = $this->published();
        $old = $this->complete($this->attempt(null, $a));
        $admin = $this->user('admin');
        $d = $this->definition();
        $d['title'] = 'عنوان نسخه دوم';
        $d['questions'][0]['reverse'] = true;
        app(AssessmentService::class)->saveDraft($admin, $a, 2, $d);
        app(AssessmentService::class)->publish($admin, $a, 3);
        $new = $this->attempt($old->user, $a);
        $this->assertNotSame($old->assessment_version_id, $new->assessment_version_id);
        $this->assertSame(1, $old->edition->number);
        $this->assertSame(6, $old->fresh()->result['dimensions'][0]['sum']);
        $this->assertSame(2, $new->edition->number);
    }

    public function test_unpublished_draft_title_does_not_leak_into_student_catalog(): void
    {
        $a = $this->published();
        $d = $this->definition();
        $d['title'] = 'عنوان محرمانه پیش‌نویس';
        app(AssessmentService::class)->saveDraft($this->user('admin'), $a, 2, $d);
        $this->actingAs($this->user())->get('/assessments')->assertInertia(fn (Assert $p) => $p->where('assessments.0.title', $this->definition()['title']));
    }

    public function test_closing_new_starts_preserves_existing_attempt_completion(): void
    {
        $a = $this->published();
        $item = $this->attempt(null, $a);
        app(AssessmentService::class)->availability($this->user('admin'), $a, 2, false);
        $this->actingAs($this->user())->post('/assessments/'.$a->id.'/start', ['acknowledged' => true])->assertSessionHasErrors('assessment');
        $this->complete($item);
        $this->actingAs($item->user)->get('/assessment-attempts/'.$item->id.'/report')->assertOk();
    }

    public function test_report_is_owner_admin_or_selected_organization_member_only(): void
    {
        $a = $this->complete($this->attempt());
        $org = Organization::factory()->create(['name' => 'سازمان آزمایشی', 'type' => 'company', 'status' => 'active']);
        $manager = $this->user('org_admin');
        $org->members()->attach([$manager->id => ['kind' => 'manager'], $a->user_id => ['kind' => 'member']]);
        $this->actingAs($manager)->get('/assessment-attempts/'.$a->id.'/report')->assertInertia(fn (Assert $p) => $p->where('role', 'org_admin')->missing('answers')->where('result.dimensions.0.sum', 6));
        $this->assertSame(1, AuditEvent::where('action', 'assessment.report_viewed')->count());
        $org->members()->detach($a->user_id);
        $this->get('/assessment-attempts/'.$a->id.'/report')->assertForbidden();
        foreach (['student', 'coach', 'org_admin'] as $role) {
            $this->actingAs($this->user($role))->get('/assessment-attempts/'.$a->id.'/report')->assertForbidden();
        }$this->actingAs($this->user('admin'))->get('/assessment-attempts/'.$a->id.'/report')->assertOk();
    }

    public function test_org_manager_cannot_switch_to_unmanaged_org_or_view_unfinished_answers(): void
    {
        $a = $this->attempt();
        $org = Organization::factory()->create(['name' => 'سازمان آزمایشی', 'type' => 'company', 'status' => 'active']);
        $manager = $this->user('org_admin');
        $org->members()->attach([$manager->id => ['kind' => 'manager'], $a->user_id => ['kind' => 'member']]);
        $this->actingAs($manager)->get('/assessment-attempts/'.$a->id.'/report')->assertNotFound();
        $this->get('/assessment-attempts/'.$a->id)->assertForbidden();
        $org->update(['status' => 'inactive']);
        $this->get('/assessment-attempts/'.$a->id.'/report')->assertForbidden();
    }

    public function test_pagination_does_not_expose_unrequested_question_keys(): void
    {
        $d = $this->definition();
        for ($i = 3; $i <= 12; $i++) {
            $d['questions'][] = ['key' => 'q'.$i, 'text' => 'پرسش آزمایشی '.$i, 'dimension' => 'interest', 'reverse' => false];
        }$a = Assessment::factory()->create();
        $admin = $this->user('admin');
        app(AssessmentService::class)->saveDraft($admin, $a, 0, $d);
        app(AssessmentService::class)->publish($admin, $a, 1);
        $item = $this->attempt(null, $a);
        $this->actingAs($item->user)->get('/assessment-attempts/'.$item->id.'?page=2')->assertInertia(fn (Assert $p) => $p->has('questions', 2)->where('questions.0.key', 'q11')->where('last', 2));
    }

    public function test_selected_organization_bounds_report_access_even_for_a_multi_org_manager(): void
    {
        $a = $this->complete($this->attempt());
        $manager = $this->user('org_admin');
        $first = Organization::factory()->create(['name' => 'First', 'type' => 'company', 'status' => 'active']);
        $second = Organization::factory()->create(['name' => 'Second', 'type' => 'company', 'status' => 'active']);
        $first->members()->attach($manager->id, ['kind' => 'manager']);
        $second->members()->attach([$manager->id => ['kind' => 'manager'], $a->user_id => ['kind' => 'member']]);
        $this->actingAs($manager)->withSession(['organization_id' => $first->id])->get('/assessment-attempts/'.$a->id.'/report')->assertForbidden();
        $this->withSession(['organization_id' => $second->id])->get('/assessment-attempts/'.$a->id.'/report')->assertOk();
        $this->get('/organization/members/'.$a->user_id)->assertInertia(fn (Assert $p) => $p->has('assessmentReports', 1));
    }

    public function test_empty_page_can_be_saved_without_fabricating_answers(): void
    {
        $a = $this->attempt();
        $this->actingAs($a->user)->post('/assessment-attempts/'.$a->id.'/answers',['revision' => 1, 'answers' => [], 'next_page' => 1])->assertSessionHasNoErrors();
        $this->assertSame([],$a->fresh()->answers);
        $this->assertSame('in_progress',$a->fresh()->status);
    }
}
