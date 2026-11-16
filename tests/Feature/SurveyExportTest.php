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
use App\Services\SurveyExports;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class SurveyExportTest extends TestCase
{
    use RefreshDatabase;

    private CourseSurvey $survey;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        $this->survey = CourseSurvey::factory()->create();
        $this->admin = $this->user('admin');
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('key', $role)->value('id'));

        return $user;
    }

    private function publish(array $changes = []): SurveyPublication
    {
        $service = app(CourseSurveys::class);
        $service->draft($this->admin, $this->survey, $this->survey->fresh()->revision, [...$this->survey->draft, ...$changes]);

        return $service->publish($this->admin, $this->survey, $this->survey->fresh()->revision, null, null);
    }

    private function answer(SurveyPublication $publication, array $answers = ['quality' => 4, 'comment' => 'نظر آموزشی'], bool $final = true): SurveyResponse
    {
        $user = $this->user('student');
        Enrollment::factory()->create(['user_id' => $user->id, 'cohort_id' => $this->survey->cohort_id]);
        $service = app(CourseSurveys::class);
        $response = $service->start($user, $publication);
        $service->save($user, $response, 1, $answers, $final);

        return $response->fresh();
    }

    private function url(SurveyPublication $publication, string $dataset = 'summary'): string
    {
        return '/survey-publications/'.$publication->id.'/export?dataset='.$dataset;
    }

    private function rows(string $csv): array
    {
        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, substr($csv, 3));
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, null, ',', '"', '')) !== false) {
            $rows[] = $row;
        }
        fclose($stream);

        return $rows;
    }

    public function test_all_exports_require_overall_threshold_and_failed_exports_have_no_audit(): void
    {
        $publication = $this->publish();
        $this->answer($publication);
        $this->answer($publication);
        foreach (['summary', 'comments&question=comment', 'responses'] as $dataset) {
            $this->actingAs($this->admin)->getJson($this->url($publication, $dataset))->assertUnprocessable()->assertJsonValidationErrors('survey');
        }
        $this->assertSame(0, AuditEvent::where('action', 'survey.exported')->count());
    }

    public function test_summary_exports_correct_stats_but_no_individual_data_or_drafts(): void
    {
        $publication = $this->publish();
        $responses = collect([1, 3, 5])->map(fn ($rating) => $this->answer($publication, ['quality' => $rating, 'comment' => 'PRIVATE-COMMENT-'.$rating]));
        $this->answer($publication, ['quality' => 5, 'comment' => 'SECRET-DRAFT'], false);
        $response = $this->actingAs($this->admin)->get($this->url($publication).'&page=99');
        $response->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->assertHeader('X-Content-Type-Options', 'nosniff')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $this->assertStringContainsString('no-store', $response->headers->get('Cache-Control'));
        $csv = $response->streamedContent();
        $rows = $this->rows($csv);
        $this->assertCount(3, $rows);
        $this->assertSame(['3', 'quality'], array_slice($rows[1], 4, 2));
        $this->assertSame(['3', 'مجاز', '3', '1', '0', '1', '0', '1'], array_slice($rows[1], 8));
        foreach ($responses as $item) {
            $this->assertStringNotContainsString($item->enrollment->user->name, $csv);
            $this->assertStringNotContainsString($item->enrollment->user->phone, $csv);
        }
        $this->assertStringNotContainsString('PRIVATE-COMMENT', $csv);
        $this->assertStringNotContainsString('SECRET-DRAFT', $csv);
        $audit = AuditEvent::where('action', 'survey.exported')->sole();
        $this->assertSame(['dataset' => 'summary', 'question' => null, 'rows' => 2], $audit->metadata);
    }

    public function test_sparse_optional_question_has_no_distribution_or_text_export(): void
    {
        $publication = $this->publish(['questions' => [['key' => 'quality', 'text' => 'امتیاز', 'type' => 'rating', 'required' => false], ['key' => 'comment', 'text' => 'نظر', 'type' => 'text', 'required' => false]]]);
        $this->answer($publication, ['quality' => 1, 'comment' => 'WITHHELD-TEXT']);
        $this->answer($publication, ['quality' => 3]);
        $this->answer($publication, ['quality' => 5]);
        $rows = $this->rows($this->actingAs($this->admin)->get($this->url($publication))->streamedContent());
        $this->assertSame(['1', 'کمتر از حداقل پاسخ', '', '', '', '', '', ''], array_slice($rows[2], 8));
        $this->getJson($this->url($publication, 'comments&question=comment'))->assertUnprocessable()->assertJsonValidationErrors('survey');
    }

    public function test_comments_export_all_final_rows_from_one_question_without_individual_fields(): void
    {
        $publication = $this->publish(['questions' => [...$this->survey->draft['questions'], ['key' => 'private_other', 'text' => 'متن دیگر', 'type' => 'text', 'required' => false]]]);
        $responses = collect(range(1, 15))->map(fn ($index) => $this->answer($publication, ['quality' => 4, 'comment' => 'نظر شماره '.$index, 'private_other' => 'UNSELECTED-TEXT']));
        $this->answer($publication, ['quality' => 4, 'comment' => 'SECRET-DRAFT'], false);
        $csv = $this->actingAs($this->admin)->get($this->url($publication, 'comments&question=comment').'&page=2')->streamedContent();
        $rows = $this->rows($csv);
        $this->assertCount(16, $rows);
        $this->assertCount(7, $rows[0]);
        $this->assertSame('نظر شماره 15', $rows[15][6]);
        foreach ($responses as $response) {
            $this->assertStringNotContainsString($response->enrollment->user->name, $csv);
            $this->assertStringNotContainsString($response->enrollment->user->phone, $csv);
            $this->assertStringNotContainsString($response->submitted_at->format('Y-m-d'), $csv);
        }
        $this->assertStringNotContainsString('UNSELECTED-TEXT', $csv);
        $this->assertStringNotContainsString('SECRET-DRAFT', $csv);
    }

    public function test_export_kind_cannot_change_frozen_disclosure_mode_and_question_must_be_text(): void
    {
        $aggregate = $this->publish();
        $identified = $this->publish(['mode' => 'identified']);
        foreach ([$this->url($aggregate, 'responses'), $this->url($aggregate, 'comments'), $this->url($aggregate, 'comments&question=quality'), $this->url($aggregate, 'comments&question=unknown'), $this->url($identified, 'comments&question=comment')] as $url) {
            $this->actingAs($this->admin)->getJson($url)->assertUnprocessable()->assertJsonValidationErrors('survey');
        }
        $this->getJson($this->url($aggregate, 'invalid'))->assertUnprocessable()->assertJsonValidationErrors('dataset');
    }

    public function test_identified_export_preserves_submission_name_and_tehran_time_without_account_identifiers(): void
    {
        $publication = $this->publish(['mode' => 'identified', 'minimum_responses' => 1]);
        $this->travelTo(now()->setTimezone('UTC')->setTime(8, 15));
        $answer = $this->answer($publication);
        $name = $answer->respondent_name;
        $user = $answer->enrollment->user;
        $user->update(['name' => 'CURRENT-NAME-MUST-NOT-REPLACE-SNAPSHOT']);
        $rows = $this->rows($this->actingAs($this->admin)->get($this->url($publication, 'responses'))->streamedContent());
        $this->assertCount(2, $rows);
        $this->assertCount(8, $rows[0]);
        $this->assertSame([$name, $answer->submitted_at->copy()->setTimezone('Asia/Tehran')->format('Y-m-d H:i:s'), '4', 'نظر آموزشی'], array_slice($rows[1], 4));
        $this->assertNotContains($user->phone, $rows[1]);
    }

    public function test_csv_escapes_formulas_in_labels_names_and_multiline_comma_answers(): void
    {
        $publication = $this->publish(['title' => '=TITLE()', 'mode' => 'identified', 'minimum_responses' => 1]);
        $answer = $this->answer($publication, ['quality' => 5, 'comment' => "\u{200B} =HYPERLINK(\"x\",\"y\")\nمتن, فارسی"]);
        $answer->update(['respondent_name' => '+NAME()']);
        $rows = $this->rows($this->actingAs($this->admin)->get($this->url($publication, 'responses'))->streamedContent());
        $this->assertSame("'=TITLE()", $rows[1][0]);
        $this->assertSame("'+NAME()", $rows[1][4]);
        $this->assertSame("'\u{200B} =HYPERLINK(\"x\",\"y\")\nمتن, فارسی", $rows[1][7]);
    }

    public function test_only_active_current_assigned_coach_and_admin_can_export(): void
    {
        $publication = $this->publish(['minimum_responses' => 1, 'mode' => 'identified']);
        $this->answer($publication);
        $coach = User::findOrFail($this->survey->cohort->course->coach_id);
        $this->actingAs($coach)->get($this->url($publication))->assertOk();
        foreach ([$this->user('student'), $this->user('org_admin'), $this->user('coach')] as $user) {
            $this->actingAs($user)->getJson($this->url($publication))->assertForbidden();
        }
        $this->survey->cohort->course->update(['coach_id' => $this->user('coach')->id]);
        $this->actingAs($coach)->getJson($this->url($publication))->assertForbidden();
        User::whereKey($this->admin->id)->update(['is_active' => false]);
        $this->actingAs($this->admin)->getJson($this->url($publication))->assertForbidden();
    }

    public function test_service_reloads_actor_roles_instead_of_trusting_stale_cached_admin(): void
    {
        $publication = $this->publish(['minimum_responses' => 1, 'mode' => 'identified']);
        $this->answer($publication);
        $this->admin->load('roles');
        $this->admin->roles()->detach();
        $this->expectException(HttpException::class);
        app(SurveyExports::class)->prepare($this->admin, $publication, 'responses');
    }

    public function test_export_uses_only_selected_immutable_publication_after_new_version(): void
    {
        $first = $this->publish(['title' => 'FIRST-VERSION', 'mode' => 'identified', 'minimum_responses' => 1]);
        $this->answer($first, ['quality' => 2, 'comment' => 'FIRST-ANSWER']);
        $second = $this->publish(['title' => 'SECOND-VERSION', 'mode' => 'identified', 'minimum_responses' => 1]);
        $this->answer($second, ['quality' => 5, 'comment' => 'SECOND-ANSWER']);
        $csv = $this->actingAs($this->admin)->get($this->url($first, 'responses'))->streamedContent();
        $this->assertStringContainsString('FIRST-VERSION', $csv);
        $this->assertStringContainsString('FIRST-ANSWER', $csv);
        $this->assertStringNotContainsString('SECOND-VERSION', $csv);
        $this->assertStringNotContainsString('SECOND-ANSWER', $csv);
    }
}
