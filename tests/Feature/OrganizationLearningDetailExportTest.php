<?php

namespace Tests\Feature;

use App\Models\Assignment;
use App\Models\Attendance;
use App\Models\AuditEvent;
use App\Models\Cohort;
use App\Models\Enrollment;
use App\Models\LiveSession;
use App\Models\Organization;
use App\Models\Role;
use App\Models\Submission;
use App\Models\User;
use App\Services\OrganizationMembership;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class OrganizationLearningDetailExportTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $member;

    private Organization $organization;

    private Enrollment $enrollment;

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
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('key', $role)->value('id'));

        return $user;
    }

    private function url(string $dataset, bool $admin = false): string
    {
        return ($admin ? '/admin/organizations/'.$this->organization->id : '/organization').'/members/'.$this->member->id.'/learning-export?dataset='.$dataset;
    }

    private function rows(string $dataset, bool $admin = false): array
    {
        $response = $this->get($this->url($dataset, $admin))->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Robots-Tag', 'noindex, nofollow');
        $response->assertDownload('kardorost-organization-'.$this->organization->id.'-member-'.$this->member->id.'-'.$dataset.'.csv');
        $text = $response->streamedContent();
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

    private function attendance(string $status = 'present', array $attributes = []): Attendance
    {
        $session = LiveSession::factory()->create(['cohort_id' => $this->enrollment->cohort_id, 'meeting_url' => 'https://meet.google.com/PRIVATE-JOIN-TOKEN', ...$attributes]);

        return Attendance::factory()->create(['enrollment_id' => $this->enrollment->id, 'live_session_id' => $session->id, 'status' => $status]);
    }

    private function assignment(array $attributes = []): Assignment
    {
        return Assignment::factory()->create(['cohort_id' => $this->enrollment->cohort_id, 'instructions' => 'PRIVATE-ASSIGNMENT-INSTRUCTIONS', ...$attributes]);
    }

    private function submission(Assignment $assignment, int $version = 1, array $attributes = []): Submission
    {
        return Submission::factory()->create(['enrollment_id' => $this->enrollment->id, 'assignment_id' => $assignment->id, 'version' => $version, 'body' => 'PRIVATE-SUBMISSION-BODY', 'feedback' => 'PRIVATE-SUBMISSION-FEEDBACK', 'file_path' => 'private/PRIVATE-STORAGE-PATH', 'file_name' => 'PRIVATE-EVIDENCE-NAME.pdf', ...$attributes]);
    }

    public function test_attendance_export_contains_only_recorded_status_rows_and_tehran_session_times(): void
    {
        $this->attendance('present', ['title' => 'First recorded session', 'starts_at' => CarbonImmutable::parse('2026-03-01 23:30:00', 'UTC')->timezone(config('app.timezone'))]);
        $this->attendance('absent');
        $this->attendance('excused');
        LiveSession::factory()->create(['cohort_id' => $this->enrollment->cohort_id, 'title' => 'UNRECORDED-SESSION']);
        $this->actingAs($this->manager);
        $rows = $this->rows('attendance');
        $this->assertCount(4, $rows);
        $this->assertCount(12, $rows[0]);
        $this->assertSame('First recorded session', $rows[1][8]);
        $this->assertSame('2026-03-02T03:00:00+03:30', $rows[1][9]);
        $this->assertSame(['حاضر', 'غایب', 'غیبت موجه'], array_column(array_slice($rows, 1), 10));
        $this->assertStringNotContainsString('UNRECORDED-SESSION', json_encode($rows));
        $this->assertStringNotContainsString('PRIVATE-JOIN-TOKEN', json_encode($rows));
        $this->assertStringNotContainsString($this->member->phone, json_encode($rows));
    }

    public function test_submission_export_preserves_all_versions_current_status_zero_grade_and_null_grade(): void
    {
        $assignment = $this->assignment(['title' => 'Structured history', 'max_score' => 80, 'is_published' => false]);
        $first = $this->submission($assignment, 1, ['status' => 'revision_requested', 'score' => 0, 'reviewed_at' => now()]);
        $second = $this->submission($assignment, 2, ['status' => 'submitted', 'score' => null, 'reviewed_at' => null]);
        $this->actingAs($this->manager);
        $rows = $this->rows('submissions');
        $this->assertCount(3, $rows);
        $this->assertCount(16, $rows[0]);
        $this->assertSame([(string) $assignment->id, 'Structured history', (string) $first->id, '1', 'نیازمند اصلاح', '0', '80'], array_slice($rows[1], 7, 7));
        $this->assertSame([(string) $second->id, '2', 'منتظر بازخورد', '', '80'], array_slice($rows[2], 9, 5));
        $this->assertSame('', $rows[2][15]);
        foreach (['PRIVATE-SUBMISSION-BODY', 'PRIVATE-SUBMISSION-FEEDBACK', 'PRIVATE-STORAGE-PATH', 'PRIVATE-EVIDENCE-NAME', 'PRIVATE-ASSIGNMENT-INSTRUCTIONS', $this->member->phone] as $private) {
            $this->assertStringNotContainsString($private, json_encode($rows));
        }
    }

    public function test_foreign_cohort_source_records_are_excluded_from_exports_and_member_page(): void
    {
        $foreign = Cohort::factory()->create();
        $session = LiveSession::factory()->create(['cohort_id' => $foreign->id, 'title' => 'FOREIGN-PRIVATE-SESSION']);
        Attendance::factory()->create(['enrollment_id' => $this->enrollment->id, 'live_session_id' => $session->id]);
        $assignment = Assignment::factory()->create(['cohort_id' => $foreign->id, 'title' => 'FOREIGN-PRIVATE-ASSIGNMENT']);
        $this->submission($assignment);
        $this->actingAs($this->manager);
        $this->assertCount(1, $this->rows('attendance'));
        $this->assertCount(1, $this->rows('submissions'));
        $this->get('/organization/members/'.$this->member->id)->assertInertia(fn (Assert $page) => $page->has('learningReports.'.$this->enrollment->id.'.attendance', 0)->has('learningReports.'.$this->enrollment->id.'.submissions', 0));
        $summary = $this->get('/organization/members/'.$this->member->id.'/learning-export')->streamedContent();
        $this->assertStringNotContainsString('FOREIGN-PRIVATE', $summary);
    }

    public function test_other_members_records_are_not_exported_even_for_same_course_and_cohort(): void
    {
        $other = $this->user('student');
        $enrollment = Enrollment::factory()->create(['user_id' => $other->id, 'cohort_id' => $this->enrollment->cohort_id]);
        $session = LiveSession::factory()->create(['cohort_id' => $this->enrollment->cohort_id]);
        Attendance::factory()->create(['enrollment_id' => $enrollment->id, 'live_session_id' => $session->id]);
        Submission::factory()->create(['enrollment_id' => $enrollment->id, 'assignment_id' => $this->assignment()->id]);
        $this->actingAs($this->manager);
        $this->assertCount(1, $this->rows('attendance'));
        $this->assertCount(1, $this->rows('submissions'));
    }

    public function test_full_admin_can_export_both_detail_types_without_organization_membership(): void
    {
        $this->attendance();
        $this->submission($this->assignment());
        $this->actingAs($this->user('admin'));
        $this->assertCount(2, $this->rows('attendance', true));
        $this->assertCount(2, $this->rows('submissions', true));
    }

    public function test_foreign_manager_other_panels_and_departure_cannot_export_detail_types(): void
    {
        $foreign = $this->user('org_admin');
        $other = Organization::factory()->create(['status' => 'active']);
        $other->members()->attach($foreign->id, ['kind' => 'manager']);
        foreach (['attendance', 'submissions'] as $dataset) {
            $this->actingAs($foreign)->get($this->url($dataset))->assertForbidden();
            $this->actingAs($this->member)->get($this->url($dataset))->assertForbidden();
            $this->actingAs($this->manager)->get($this->url($dataset, true))->assertForbidden();
        }
        $key = $this->organization->members()->where('users.id', $this->member->id)->firstOrFail()->pivot->membership_key;
        app(OrganizationMembership::class)->endMembership($this->member, $this->organization, $this->member, $key, 'Synthetic departure before detailed export', 'leave');
        foreach (['attendance', 'submissions'] as $dataset) {
            $this->actingAs($this->manager)->get($this->url($dataset))->assertForbidden();
        }
    }

    public function test_detail_exports_span_multiple_batches_without_dropping_versions_or_sessions(): void
    {
        $assignment = $this->assignment();
        for ($index = 1; $index <= 205; $index++) {
            $this->attendance();
            $this->submission($assignment, $index);
        }
        $this->actingAs($this->manager);
        $attendance = $this->rows('attendance');
        $submissions = $this->rows('submissions');
        $this->assertCount(206, $attendance);
        $this->assertCount(206, $submissions);
        $this->assertCount(205, array_unique(array_column(array_slice($attendance, 1), 7)));
        $this->assertSame(array_map('strval', range(1, 205)), array_column(array_slice($submissions, 1), 10));
    }

    public function test_detail_csv_guards_formula_titles_and_preserves_newlines_and_quotes(): void
    {
        $this->attendance('present', ['title' => "\u{200B}=SUM(1,2)"]);
        $this->submission($this->assignment(['title' => "@Synthetic\n\"quoted\""]));
        $this->actingAs($this->manager);
        $attendance = $this->rows('attendance');
        $submissions = $this->rows('submissions');
        $this->assertSame("'\u{200B}=SUM(1,2)", $attendance[1][8]);
        $this->assertSame("'@Synthetic\n\"quoted\"", $submissions[1][8]);
    }

    public function test_unknown_or_array_dataset_is_rejected_instead_of_selecting_arbitrary_tables(): void
    {
        foreach (['unknown', 'payments', 'attendance&dataset[]=summary'] as $dataset) {
            $this->actingAs($this->manager)->getJson($this->url($dataset))->assertUnprocessable();
        }
        $this->assertSame(0, AuditEvent::where('action', 'organization.learning_exported')->count());
    }

    public function test_detail_audit_records_only_dataset_row_count_and_organization(): void
    {
        $this->attendance();
        $this->submission($this->assignment());
        $this->actingAs($this->manager);
        $this->rows('attendance');
        $this->rows('submissions');
        $events = AuditEvent::where('action', 'organization.learning_exported')->orderBy('id')->get();
        $this->assertSame(['organization_id' => $this->organization->id, 'dataset' => 'learning_attendance_v1', 'rows' => 1], $events[0]->metadata);
        $this->assertSame(['organization_id' => $this->organization->id, 'dataset' => 'learning_submissions_v1', 'rows' => 1], $events[1]->metadata);
    }
}
