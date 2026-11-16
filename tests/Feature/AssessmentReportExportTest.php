<?php

namespace Tests\Feature;

use App\Models\Assessment;
use App\Models\AssessmentAttempt;
use App\Models\AssessmentVersion;
use App\Models\AuditEvent;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Services\AssessmentReportExports;
use App\Services\AssessmentService;
use App\Services\OrganizationMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class AssessmentReportExportTest extends TestCase
{
    use RefreshDatabase;

    private User $owner;

    private User $admin;

    private Assessment $assessment;

    private AssessmentAttempt $attempt;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        $this->owner = $this->user('student');
        $this->admin = $this->user('admin');
        $this->assessment = Assessment::factory()->create();
        $definition = AssessmentVersion::factory()->definition()['definition'];
        $definition['questions'][0]['text'] = 'PRIVATE-QUESTION-TEXT';
        $service = app(AssessmentService::class);
        $service->saveDraft($this->admin, $this->assessment, 0, $definition);
        $service->publish($this->admin, $this->assessment, 1);
        $this->attempt = $service->start($this->owner, $this->assessment->fresh());
        $service->save($this->owner, $this->attempt, 1, ['q1' => 5, 'q2' => 5]);
        $this->attempt = $service->finish($this->owner, $this->attempt, 2);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('key', $role)->value('id'));

        return $user;
    }

    private function url(?AssessmentAttempt $attempt = null): string
    {
        return '/assessment-attempts/'.($attempt ?? $this->attempt)->id.'/export';
    }

    private function rows(string $text): array
    {
        $this->assertStringStartsWith("\xEF\xBB\xBF", $text);
        $stream = fopen('php://temp', 'w+');
        fwrite($stream, substr($text, 3));
        rewind($stream);
        $rows = [];
        while (($row = fgetcsv($stream, 0, ',', '"', '')) !== false) {
            $rows[] = $row;
        }
        fclose($stream);

        return $rows;
    }

    private function organization(User $manager): Organization
    {
        $organization = Organization::factory()->create(['status' => 'active']);
        $organization->members()->attach($manager->id, ['kind' => 'manager']);
        $organization->members()->attach($this->owner->id, ['kind' => 'member']);

        return $organization;
    }

    public function test_owner_exports_actual_finalized_reverse_scored_snapshot_without_answers_or_questions(): void
    {
        $before = $this->attempt->fresh()->getRawOriginal();
        $response = $this->actingAs($this->owner)->get($this->url())->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertDownload('kardorost-assessment-report-'.$this->attempt->id.'.csv');
        $text = $response->streamedContent();
        $rows = $this->rows($text);
        $this->assertCount(2, $rows);
        $this->assertCount(14, $rows[0]);
        $this->assertSame($this->owner->name, $rows[1][0]);
        $this->assertSame('1', $rows[1][2]);
        $this->assertSame('likert_sum_v1', $rows[1][4]);
        $this->assertSame(['interest', 'بعد آزمایشی', '6', '2', '2', '10', '50'], array_slice($rows[1], 6, 7));
        $this->assertStringContainsString('رتبه جامعه', $rows[1][13]);
        foreach (['PRIVATE-QUESTION-TEXT', 'q1', 'q2', $this->owner->phone, $this->owner->email, $this->attempt->getRawOriginal('answers'), $this->attempt->getRawOriginal('result')] as $private) {
            $this->assertStringNotContainsString($private, $text);
        }
        $this->assertSame($before, $this->attempt->fresh()->getRawOriginal());
        $this->get('/assessment-attempts/'.$this->attempt->id.'/report')->assertInertia(fn (Assert $page) => $page->where('export_url', url($this->url())));
    }

    public function test_only_owner_full_admin_or_selected_current_organization_manager_can_export(): void
    {
        $this->get($this->url())->assertRedirect('/login');
        foreach (['student', 'coach', 'org_admin'] as $role) {
            $this->actingAs($this->user($role))->get($this->url())->assertForbidden();
        }
        $this->actingAs($this->admin)->get($this->url())->assertOk();
        $manager = $this->user('org_admin');
        $this->organization($manager);
        $this->actingAs($manager)->get($this->url())->assertOk();
    }

    public function test_selected_organization_and_actual_manager_kind_are_required(): void
    {
        $manager = $this->user('org_admin');
        $allowed = $this->organization($manager);
        $other = Organization::factory()->create(['status' => 'active']);
        $other->members()->attach($manager->id, ['kind' => 'manager']);
        $this->actingAs($manager)->withSession(['organization_id' => $other->id])->get($this->url())->assertForbidden();
        $this->withSession(['organization_id' => $allowed->id])->get($this->url())->assertOk();
        $foreign = $this->user('org_admin');
        $allowed->members()->attach($foreign->id, ['kind' => 'member']);
        $other->members()->attach($foreign->id, ['kind' => 'manager']);
        $this->actingAs($foreign)->withSession(['organization_id' => $allowed->id])->get($this->url())->assertForbidden();
    }

    public function test_departure_revokes_manager_export_but_full_admin_retains_independent_report_access(): void
    {
        $manager = $this->user('org_admin');
        $organization = $this->organization($manager);
        $key = $organization->members()->where('users.id', $this->owner->id)->firstOrFail()->pivot->membership_key;
        app(OrganizationMembership::class)->endMembership($this->owner, $organization, $this->owner, $key, 'Synthetic departure before assessment export', 'leave');
        $this->actingAs($manager)->get($this->url())->assertForbidden();
        $this->actingAs($this->admin)->get($this->url())->assertOk();
        $this->actingAs($this->owner)->get($this->url())->assertOk();
    }

    public function test_completed_owner_keeps_own_report_after_account_role_changes(): void
    {
        $this->owner->roles()->sync([Role::where('key', 'coach')->value('id')]);
        $this->actingAs($this->owner)->get($this->url())->assertOk();
    }

    public function test_new_publication_does_not_recompute_or_replace_old_report_export(): void
    {
        $before = $this->actingAs($this->owner)->get($this->url())->streamedContent();
        $definition = $this->attempt->edition->definition;
        $definition['title'] = 'A new software-only publication';
        $definition['source'] = 'A later synthetic source';
        $definition['questions'][0]['reverse'] = true;
        $service = app(AssessmentService::class);
        $service->saveDraft($this->admin, $this->assessment->fresh(), 2, $definition);
        $service->publish($this->admin, $this->assessment->fresh(), 3);
        $this->assertSame($before, $this->get($this->url())->streamedContent());
        $this->assertSame(2, $this->assessment->fresh()->current_version);
    }

    public function test_in_progress_and_missing_finalization_time_are_not_exported_as_final_reports(): void
    {
        foreach (['in_progress', 'completed'] as $status) {
            $owner = $this->user('student');
            $attempt = AssessmentAttempt::factory()->create(['user_id' => $owner->id, 'assessment_version_id' => $this->attempt->assessment_version_id, 'status' => $status, 'result' => $this->attempt->result, 'completed_at' => null]);
            $this->actingAs($owner)->get($this->url($attempt))->assertNotFound();
        }
        $this->assertSame(0, AuditEvent::where('action', 'assessment.report_exported')->count());
    }

    public function test_invalid_stored_method_shape_math_or_ciphertext_never_falls_back_to_raw_answers(): void
    {
        $valid = $this->attempt->result;
        $invalid = [null, ['engine' => 'unconfigured_method', 'dimensions' => []], ['engine' => 'likert_sum_v1', 'dimensions' => []], [...$valid, 'dimensions' => [[...$valid['dimensions'][0], 'sum' => 999]]], [...$valid, 'dimensions' => [[...$valid['dimensions'][0], 'range_percent' => 99]]]];
        foreach ($invalid as $result) {
            $owner = $this->user('student');
            $attempt = AssessmentAttempt::factory()->create(['user_id' => $owner->id, 'assessment_version_id' => $this->attempt->assessment_version_id, 'status' => 'completed', 'result' => $result, 'completed_at' => now(), 'answers' => ['PRIVATE-RAW-ANSWER' => 4]]);
            $this->actingAs($owner)->getJson($this->url($attempt))->assertUnprocessable()->assertJsonValidationErrors('report')->assertDontSee('PRIVATE-RAW-ANSWER');
        }
        $owner = $this->user('student');
        $attempt = AssessmentAttempt::factory()->create(['user_id' => $owner->id, 'assessment_version_id' => $this->attempt->assessment_version_id, 'status' => 'completed', 'result' => $valid, 'completed_at' => now()]);
        DB::table('assessment_attempts')->where('id', $attempt->id)->update(['result' => 'broken-ciphertext']);
        $this->actingAs($owner)->getJson($this->url($attempt))->assertUnprocessable()->assertJsonValidationErrors('report');
        $this->assertSame('broken-ciphertext', DB::table('assessment_attempts')->where('id', $attempt->id)->value('result'));
        $this->assertSame(0, AuditEvent::where('action', 'assessment.report_exported')->count());
    }

    public function test_current_owner_name_and_snapshot_titles_receive_csv_formula_and_newline_protection(): void
    {
        $this->owner->update(['name' => "\u{3000}=SYNTHETIC"]);
        $result = $this->attempt->result;
        $result['dimensions'][0]['title'] = "@Synthetic\n\"quoted\"";
        $this->attempt->update(['result' => $result]);
        $rows = $this->rows($this->actingAs($this->owner)->get($this->url())->streamedContent());
        $this->assertSame("'\u{3000}=SYNTHETIC", $rows[1][0]);
        $this->assertSame("'@Synthetic\n\"quoted\"", $rows[1][7]);
    }

    public function test_export_uses_only_whitelisted_result_fields_and_metadata_only_audit(): void
    {
        $result = $this->attempt->result;
        $result['raw_answers'] = 'PRIVATE-EXTRA-RESULT-FIELD';
        $result['dimensions'][0]['private_note'] = 'PRIVATE-DIMENSION-NOTE';
        $this->attempt->update(['result' => $result]);
        $text = $this->actingAs($this->owner)->get($this->url())->streamedContent();
        $this->assertStringNotContainsString('PRIVATE-EXTRA-RESULT-FIELD', $text);
        $this->assertStringNotContainsString('PRIVATE-DIMENSION-NOTE', $text);
        $this->assertSame(['organization_id' => null, 'assessment_version_id' => $this->attempt->assessment_version_id, 'dimensions' => 1, 'scope' => 'owner'], AuditEvent::where('action', 'assessment.report_exported')->sole()->metadata);
    }

    public function test_stale_active_owner_snapshot_is_rechecked_under_account_lock(): void
    {
        $stale = $this->owner->fresh();
        $this->owner->forceFill(['is_active' => false])->save();
        try {
            app(AssessmentReportExports::class)->prepare($stale, $this->attempt);
            $this->fail('Inactive owner must not export from an old account snapshot.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->assertSame(0, AuditEvent::where('action', 'assessment.report_exported')->count());
    }

    public function test_all_dimensions_export_independently_without_a_fabricated_combined_total(): void
    {
        $definition = $this->attempt->edition->definition;
        $definition['dimensions'][] = ['key' => 'focus', 'title' => 'Second software-only dimension', 'description' => 'Synthetic fixture'];
        $definition['questions'][1]['dimension'] = 'focus';
        $service = app(AssessmentService::class);
        $service->saveDraft($this->admin, $this->assessment->fresh(), 2, $definition);
        $service->publish($this->admin, $this->assessment->fresh(), 3);
        $attempt = $service->start($this->owner, $this->assessment->fresh());
        $service->save($this->owner, $attempt, 1, ['q1' => 5, 'q2' => 5]);
        $attempt = $service->finish($this->owner, $attempt, 2);
        $rows = $this->rows($this->actingAs($this->owner)->get($this->url($attempt))->streamedContent());
        $this->assertCount(3, $rows);
        $this->assertSame(['interest', 'focus'], array_column(array_slice($rows, 1), 6));
        $this->assertSame(['5', '1'], array_column(array_slice($rows, 1), 8));
        $this->assertSame(['100', '0'], array_column(array_slice($rows, 1), 12));
    }

    public function test_suspended_organization_or_missing_manager_membership_does_not_grant_report_export(): void
    {
        $manager = $this->user('org_admin');
        $organization = $this->organization($manager);
        $organization->update(['status' => 'suspended']);
        $this->actingAs($manager)->get($this->url())->assertForbidden();
        $organization->update(['status' => 'active']);
        $organization->members()->detach($manager->id);
        $this->get($this->url())->assertForbidden();
        $this->actingAs($this->owner)->get($this->url())->assertOk();
    }
}
