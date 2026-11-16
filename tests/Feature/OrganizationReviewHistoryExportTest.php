<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\AuditEvent;
use App\Models\Cohort;
use App\Models\Enrollment;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Submission;
use App\Models\SubmissionReview;
use App\Models\User;
use App\Services\OrganizationLearningExports;
use App\Services\OrganizationMembership;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class OrganizationReviewHistoryExportTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $member;

    private Organization $organization;

    private Enrollment $enrollment;

    private Assignment $assignment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        $this->manager = $this->user('org_admin');
        $this->member = $this->user('student');
        $this->organization = Organization::factory()->create(['status' => 'active']);
        $this->organization->members()->attach($this->manager->id, ['kind' => 'manager']);
        $this->organization->members()->attach($this->member->id, ['kind' => 'member']);
        $this->enrollment = Enrollment::factory()->create(['user_id' => $this->member->id]);
        $this->assignment = Assignment::factory()->create(['cohort_id' => $this->enrollment->cohort_id, 'max_score' => 80, 'instructions' => 'PRIVATE-INSTRUCTIONS']);
        $this->actingAs($this->manager);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('key', $role)->value('id'));

        return $user;
    }

    private function url(bool $admin = false): string
    {
        return ($admin ? '/admin/organizations/'.$this->organization->id : '/organization').'/members/'.$this->member->id.'/learning-export?dataset=reviews';
    }

    private function submission(int $version = 1, ?Enrollment $enrollment = null, ?Assignment $assignment = null): Submission
    {
        return Submission::factory()->create(['enrollment_id' => ($enrollment ?? $this->enrollment)->id, 'assignment_id' => ($assignment ?? $this->assignment)->id, 'version' => $version, 'body' => 'PRIVATE-SUBMISSION-BODY', 'file_name' => 'PRIVATE-FILE-NAME.pdf', 'file_path' => 'private/file.pdf']);
    }

    private function review(Submission $submission, int $revision = 1, array $changes = []): SubmissionReview
    {
        return SubmissionReview::factory()->create(['submission_id' => $submission->id, 'reviewer_id' => $this->manager->id, 'revision' => $revision, ...$changes]);
    }

    private function rows(bool $admin = false): array
    {
        $response = $this->get($this->url($admin))->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertHeader('X-Content-Type-Options', 'nosniff');
        $response->assertDownload('kardorost-organization-'.$this->organization->id.'-member-'.$this->member->id.'-reviews.csv');
        $text = $response->streamedContent();
        $this->assertStringStartsWith("\xEF\xBB\xBF", $text);
        $output = fopen('php://temp', 'w+');
        fwrite($output, substr($text, 3));
        rewind($output);
        $rows = [];
        while (($row = fgetcsv($output, 0, ',', '"', '')) !== false) {
            $rows[] = $row;
        }
        fclose($output);

        return $rows;
    }

    public function test_each_review_keeps_its_recorded_score_status_and_feedback_not_current_submission_state(): void
    {
        $submission = $this->submission();
        $first = $this->review($submission, 1, ['score' => 0, 'feedback' => "بازخورد اول\nخط دوم", 'created_at' => CarbonImmutable::parse('2026-03-01 23:30:00', 'UTC')->timezone(config('app.timezone'))]);
        $second = $this->review($submission, 2, ['status' => 'revision_requested', 'score' => null, 'feedback' => 'بازخورد اصلاح']);
        $submission->update(['status' => 'reviewed', 'score' => 70, 'feedback' => 'CURRENT-SUBMISSION-FEEDBACK', 'review_version' => 3]);
        $third = $this->review($submission, 3, ['score' => 70, 'feedback' => 'بازخورد سوم']);
        $this->assignment->update(['max_score' => 60]);
        $rows = $this->rows();
        $this->assertCount(4, $rows);
        $this->assertCount(19, $rows[0]);
        $this->assertSame('سقف امتیاز جاری تمرین', $rows[0][15]);
        $this->assertSame(array_map('strval', [$first->id, $second->id, $third->id]), array_column(array_slice($rows, 1), 11));
        $this->assertSame(['1', '2', '3'], array_column(array_slice($rows, 1), 12));
        $this->assertSame(['بررسی‌شده', 'نیازمند اصلاح', 'بررسی‌شده'], array_column(array_slice($rows, 1), 13));
        $this->assertSame(['0', '', '70'], array_column(array_slice($rows, 1), 14));
        $this->assertSame(['60', '60', '60'], array_column(array_slice($rows, 1), 15));
        $this->assertSame(["بازخورد اول\nخط دوم", 'بازخورد اصلاح', 'بازخورد سوم'], array_column(array_slice($rows, 1), 16));
        $this->assertSame('2026-03-02T03:00:00+03:30', $rows[1][18]);
    }

    public function test_real_teacher_review_revisions_are_exported_with_original_feedback_after_new_review(): void
    {
        $coach = $this->user('coach');
        $this->enrollment->course->update(['coach_id' => $coach->id]);
        $submission = $this->submission();
        $this->actingAs($coach)->patch('/submissions/'.$submission->id.'/review', ['status' => 'revision_requested', 'feedback' => 'لطفاً همین بخش را اصلاح کنید', 'score' => null, 'expected_review_version' => 0])->assertSessionHasNoErrors()->assertRedirect();
        $this->patch('/submissions/'.$submission->id.'/review', ['status' => 'reviewed', 'feedback' => 'توضیح بررسی دوم', 'score' => 0, 'expected_review_version' => 1])->assertSessionHasNoErrors()->assertRedirect();
        $this->actingAs($this->manager);
        $rows = $this->rows();
        $this->assertCount(3, $rows);
        $this->assertSame(['', '0'], array_column(array_slice($rows, 1), 14));
        $this->assertSame(['لطفاً همین بخش را اصلاح کنید', 'توضیح بررسی دوم'], array_column(array_slice($rows, 1), 16));
        $this->assertSame(2, $submission->fresh()->review_version);
    }

    public function test_all_submission_versions_and_personal_enrollments_are_included_without_contact_body_or_file_data(): void
    {
        $first = $this->submission();
        $second = $this->submission(2);
        $another = Enrollment::factory()->create(['user_id' => $this->member->id, 'status' => 'expired']);
        $assignment = Assignment::factory()->create(['cohort_id' => $another->cohort_id]);
        $third = $this->submission(1, $another, $assignment);
        foreach ([$first, $second, $third] as $submission) {
            $this->review($submission, changes: ['feedback' => 'مجاز برای مدیر همین عضو']);
        }
        $rows = $this->rows();
        $this->assertCount(4, $rows);
        $this->assertSame(['1', '2', '1'], array_column(array_slice($rows, 1), 10));
        $this->assertSame('منقضی‌شده', $rows[3][6]);
        $text = json_encode($rows, JSON_UNESCAPED_UNICODE);
        foreach (['PRIVATE-SUBMISSION-BODY', 'PRIVATE-FILE-NAME', 'private/file.pdf', 'PRIVATE-INSTRUCTIONS', $this->member->phone, $this->member->email, $this->manager->phone] as $private) {
            if ($private !== null && $private !== '') {
                $this->assertStringNotContainsString($private, $text);
            }
        }
        Http::assertNothingSent();
    }

    public function test_no_record_is_invented_for_unreviewed_submission_or_member_without_enrollments(): void
    {
        $this->submission();
        $this->assertCount(1, $this->rows());
        $this->member = $this->user('student');
        $this->organization->members()->attach($this->member->id, ['kind' => 'member']);
        $this->assertCount(1, $this->rows());
    }

    public function test_malformed_other_cohort_source_and_other_member_reviews_are_excluded(): void
    {
        $foreign = Assignment::factory()->create(['cohort_id' => Cohort::factory()]);
        $bad = $this->submission(1, assignment: $foreign);
        $this->review($bad, changes: ['feedback' => 'FOREIGN-COHORT-PRIVATE-FEEDBACK']);
        $otherEnrollment = Enrollment::factory()->create(['cohort_id' => $this->enrollment->cohort_id]);
        $other = $this->submission(2, $otherEnrollment);
        $this->review($other, changes: ['feedback' => 'FOREIGN-MEMBER-PRIVATE-FEEDBACK']);
        $this->assertCount(1, $this->rows());
        $this->assertSame(2, SubmissionReview::count());
    }

    public function test_full_admin_has_independent_access_for_member_of_suspended_organization(): void
    {
        $this->review($this->submission());
        $this->organization->update(['status' => 'suspended']);
        $this->get($this->url())->assertForbidden();
        $this->actingAs($this->user('admin'));
        $this->assertCount(2, $this->rows(true));
        $this->assertFalse($this->organization->members()->where('users.id', auth()->id())->exists());
    }

    public function test_selected_organization_membership_and_manager_kind_are_required_and_admin_route_is_separate(): void
    {
        $this->review($this->submission());
        $foreign = Organization::factory()->create(['status' => 'active']);
        $foreign->members()->attach($this->manager->id, ['kind' => 'manager']);
        $this->withSession(['organization_id' => $foreign->id])->get($this->url())->assertForbidden();
        $this->withSession(['organization_id' => $this->organization->id]);
        $this->get($this->url(true))->assertForbidden();
        $this->organization->members()->updateExistingPivot($this->manager->id, ['kind' => 'member']);
        $this->get($this->url())->assertForbidden();
        $this->actingAs($this->member)->get($this->url())->assertForbidden();
        $this->actingAs($this->user('coach'))->get($this->url())->assertForbidden();
    }

    public function test_actual_member_departure_revokes_both_manager_and_full_admin_exports(): void
    {
        $this->review($this->submission());
        $key = $this->organization->members()->where('users.id', $this->member->id)->firstOrFail()->pivot->membership_key;
        app(OrganizationMembership::class)->endMembership($this->member, $this->organization, $this->member, $key, 'Synthetic review export departure', 'leave');
        $this->get($this->url())->assertForbidden();
        $this->actingAs($this->user('admin'))->get($this->url(true))->assertForbidden();
        $this->assertSame(1, SubmissionReview::count());
    }

    public function test_two_hundred_five_review_revisions_cross_batches_once_without_losing_zero_or_null(): void
    {
        $submission = $this->submission();
        for ($revision = 1; $revision <= 205; $revision++) {
            $this->review($submission, $revision, ['score' => $revision % 2 === 0 ? 0 : null]);
        }
        $rows = $this->rows();
        $this->assertCount(206, $rows);
        $this->assertSame(array_map('strval', range(1, 205)), array_column(array_slice($rows, 1), 12));
        $this->assertCount(205, array_unique(array_column(array_slice($rows, 1), 11)));
        $this->assertSame('', $rows[1][14]);
        $this->assertSame('0', $rows[2][14]);
    }

    public function test_unicode_formula_feedback_quotes_and_newlines_are_guarded_without_losing_text(): void
    {
        $this->organization->update(['name' => '=FORMULA_ORG()']);
        $this->member->update(['name' => "+Name\n\"quoted\""]);
        $this->assignment->update(['title' => '@Assignment']);
        $this->review($this->submission(), changes: ['feedback' => "\u{200B}\u{00A0}=HYPERLINK(\"invalid\")\nخط بعد"]);
        $rows = $this->rows();
        $this->assertSame("'=FORMULA_ORG()", $rows[1][0]);
        $this->assertSame("'+Name\n\"quoted\"", $rows[1][1]);
        $this->assertSame("'@Assignment", $rows[1][8]);
        $this->assertSame("'\u{200B}\u{00A0}=HYPERLINK(\"invalid\")\nخط بعد", $rows[1][16]);
    }

    public function test_audit_records_only_dataset_organization_and_row_count_with_no_feedback(): void
    {
        $this->review($this->submission(), changes: ['feedback' => 'PRIVATE-REVIEW-FEEDBACK']);
        $this->rows();
        $audit = AuditEvent::where('action', 'organization.learning_exported')->sole();
        $this->assertSame(['organization_id' => $this->organization->id, 'dataset' => 'learning_reviews_v1', 'rows' => 1], $audit->metadata);
        $this->assertStringNotContainsString('PRIVATE-REVIEW-FEEDBACK', json_encode($audit->metadata));
    }

    public function test_stale_loaded_manager_role_is_checked_again_before_preparing_any_output(): void
    {
        $this->review($this->submission());
        DB::table('role_user')->where('user_id', $this->manager->id)->delete();
        $this->expectException(HttpException::class);
        app(OrganizationLearningExports::class)->prepare($this->manager, $this->organization, $this->member, false, 'reviews');
    }
}
