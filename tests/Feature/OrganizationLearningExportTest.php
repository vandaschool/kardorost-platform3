<?php

namespace Tests\Feature;

use App\Models\Attendance;
use App\Models\AuditEvent;
use App\Models\Cohort;
use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\LiveSession;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Services\LearningProgress;
use App\Services\OrganizationLearningExports;
use App\Services\OrganizationMembership;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

class OrganizationLearningExportTest extends TestCase
{
    use RefreshDatabase;

    private User $manager;

    private User $member;

    private Organization $organization;

    private Course $course;

    private Cohort $cohort;

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
        $this->course = Course::factory()->create(['title' => 'Learning summary course']);
        $this->cohort = Cohort::factory()->create(['course_id' => $this->course->id]);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('key', $role)->value('id'));

        return $user;
    }

    private function enrollment(?Cohort $cohort = null, string $status = 'active'): Enrollment
    {
        return Enrollment::factory()->create(['user_id' => $this->member->id, 'cohort_id' => ($cohort ?? $this->cohort)->id, 'status' => $status]);
    }

    private function url(?User $member = null): string
    {
        return '/organization/members/'.($member ?? $this->member)->id.'/learning-export';
    }

    private function rows(string $text): array
    {
        $this->assertStringStartsWith("\xEF\xBB\xBF", $text);
        $input = fopen('php://temp', 'w+');
        fwrite($input, substr($text, 3));
        rewind($input);
        $rows = [];
        while (($row = fgetcsv($input, 0, ',', '"', '')) !== false) {
            $rows[] = $row;
        }
        fclose($input);

        return $rows;
    }

    public function test_export_matches_published_self_reported_progress_and_recorded_attendance_without_private_content(): void
    {
        $enrollment = $this->enrollment();
        $section = CourseSection::factory()->create(['course_id' => $this->course->id]);
        $published = Lesson::factory()->count(2)->create(['course_section_id' => $section->id, 'body' => 'PRIVATE-LESSON-BODY', 'is_published' => true]);
        $draft = Lesson::factory()->create(['course_section_id' => $section->id, 'is_published' => false]);
        foreach ([$published[0], $draft] as $lesson) {
            LessonProgress::factory()->create(['enrollment_id' => $enrollment->id, 'lesson_id' => $lesson->id, 'completed_at' => now()]);
        }
        $otherLesson = Lesson::factory()->create();
        LessonProgress::factory()->create(['enrollment_id' => $enrollment->id, 'lesson_id' => $otherLesson->id, 'completed_at' => now()]);
        foreach (['present', 'absent', 'excused'] as $status) {
            $session = LiveSession::factory()->create(['cohort_id' => $this->cohort->id, 'meeting_url' => 'https://meet.google.com/private-session-token']);
            Attendance::factory()->create(['live_session_id' => $session->id, 'enrollment_id' => $enrollment->id, 'status' => $status]);
        }
        $response = $this->actingAs($this->manager)->get($this->url())->assertOk()->assertHeader('Content-Type', 'text/csv; charset=UTF-8')->assertHeader('Cache-Control', 'no-store, private')->assertHeader('X-Robots-Tag', 'noindex, nofollow')->assertDownload('kardorost-organization-'.$this->organization->id.'-member-'.$this->member->id.'-learning.csv');
        $text = $response->streamedContent();
        $rows = $this->rows($text);
        $this->assertCount(2, $rows);
        $this->assertSame([$this->organization->name, $this->member->name], array_slice($rows[1], 0, 2));
        $this->assertSame([(string) $enrollment->id, $this->course->title, $this->cohort->title, 'فعال', '2', '1', '50', '1', '1', '1', 'درخواستی ثبت نشده'], array_slice($rows[1], 3));
        $this->assertSame(['total' => 2, 'completed' => 1, 'percent' => 50], app(LearningProgress::class)->summary($enrollment));
        foreach (['PRIVATE-LESSON-BODY', 'private-session-token', $this->member->phone, $this->member->email] as $private) {
            $this->assertStringNotContainsString($private, $text);
        }
        $event = AuditEvent::where('action', 'organization.learning_exported')->sole();
        $this->assertSame(['organization_id' => $this->organization->id, 'dataset' => 'learning_summary_v1', 'rows' => 1], $event->metadata);
    }

    public function test_member_page_exposes_real_download_url_and_correct_ended_enrollment_status(): void
    {
        $this->enrollment(status: 'expired');
        $this->actingAs($this->manager)->get('/organization/members/'.$this->member->id)->assertInertia(fn (Assert $page) => $page->where('learning_export_url', url($this->url()))->where('enrollments.0.status', 'expired'));
        $rows = $this->rows($this->get($this->url())->streamedContent());
        $this->assertSame('منقضی‌شده', $rows[1][6]);
    }

    public function test_full_admin_can_export_current_members_without_becoming_an_organization_manager(): void
    {
        $this->enrollment();
        $admin = $this->user('admin');
        $this->organization->update(['status' => 'suspended']);
        $url = '/admin/organizations/'.$this->organization->id.'/members/'.$this->member->id.'/learning-export';
        $this->actingAs($admin)->get($url)->assertOk();
        $this->actingAs($this->manager)->get($url)->assertForbidden();
        $this->get($this->url())->assertForbidden();
    }

    public function test_guest_other_panels_and_foreign_organization_manager_cannot_export_member(): void
    {
        $this->get($this->url())->assertRedirect('/login');
        foreach (['student', 'coach'] as $role) {
            $this->actingAs($this->user($role))->get($this->url())->assertForbidden();
        }
        $other = Organization::factory()->create(['status' => 'active']);
        $foreign = $this->user('org_admin');
        $other->members()->attach($foreign->id, ['kind' => 'manager']);
        $this->organization->members()->attach($foreign->id, ['kind' => 'member']);
        $this->actingAs($foreign)->withSession(['organization_id' => $this->organization->id])->get($this->url())->assertForbidden();
        $this->withSession(['organization_id' => $other->id])->get($this->url())->assertForbidden();
        $this->assertSame(0, AuditEvent::where('action', 'organization.learning_exported')->count());
    }

    public function test_departed_member_loses_export_access_and_no_other_member_is_substituted(): void
    {
        $this->enrollment();
        $key = $this->organization->members()->where('users.id', $this->member->id)->firstOrFail()->pivot->membership_key;
        app(OrganizationMembership::class)->endMembership($this->member, $this->organization, $this->member, $key, 'Synthetic membership departure', 'leave');
        $this->actingAs($this->manager)->get($this->url())->assertForbidden();
        $this->assertSame(0, AuditEvent::where('action', 'organization.learning_exported')->count());
    }

    public function test_selected_organization_is_respected_for_a_manager_with_multiple_organizations(): void
    {
        $this->enrollment();
        $other = Organization::factory()->create(['status' => 'active']);
        $other->members()->attach($this->manager->id, ['kind' => 'manager']);
        $this->actingAs($this->manager)->withSession(['organization_id' => $other->id])->get($this->url())->assertForbidden();
        $this->withSession(['organization_id' => $this->organization->id])->get($this->url())->assertOk();
    }

    public function test_empty_member_history_has_only_headers_and_no_fabricated_learning_row(): void
    {
        $rows = $this->rows($this->actingAs($this->manager)->get($this->url())->streamedContent());
        $this->assertCount(1, $rows);
        $this->assertCount(14, $rows[0]);
        $this->assertSame(0, AuditEvent::where('action', 'organization.learning_exported')->sole()->metadata['rows']);
    }

    public function test_more_than_one_batch_and_personal_historical_enrollments_are_included_only_for_selected_member(): void
    {
        $ids = [];
        for ($index = 0; $index < 205; $index++) {
            $cohort = Cohort::factory()->create(['course_id' => $this->course->id]);
            $ids[] = (string) $this->enrollment($cohort, $index === 0 ? 'cancelled' : 'active')->id;
        }
        $outsider = $this->user('student');
        Enrollment::factory()->create(['user_id' => $outsider->id, 'cohort_id' => $this->cohort->id]);
        $rows = $this->rows($this->actingAs($this->manager)->get($this->url())->streamedContent());
        $this->assertCount(206, $rows);
        $this->assertSame($ids, array_column(array_slice($rows, 1), 3));
        $this->assertSame('لغوشده', $rows[1][6]);
        $this->assertSame(205, AuditEvent::where('action', 'organization.learning_exported')->sole()->metadata['rows']);
    }

    public function test_csv_preserves_quotes_newlines_and_guards_formula_cells_with_unicode_whitespace(): void
    {
        $this->organization->update(['name' => "\u{3000}=SUM(1,2)"]);
        $this->member->update(['name' => "\t@Synthetic"]);
        $this->course->update(['title' => "first, \"quoted\"\nsecond"]);
        $this->cohort->update(['title' => '+SYNTHETIC']);
        $this->enrollment();
        $rows = $this->rows($this->actingAs($this->manager)->get($this->url())->streamedContent());
        $this->assertSame("'\u{3000}=SUM(1,2)", $rows[1][0]);
        $this->assertSame("'\t@Synthetic", $rows[1][1]);
        $this->assertSame("first, \"quoted\"\nsecond", $rows[1][4]);
        $this->assertSame("'+SYNTHETIC", $rows[1][5]);
    }

    public function test_preparation_rechecks_stale_manager_activity_and_role_and_admin_mode(): void
    {
        $stale = $this->manager->fresh();
        $this->manager->forceFill(['is_active' => false])->save();
        try {
            app(OrganizationLearningExports::class)->prepare($stale, $this->organization, $this->member);
            $this->fail('Inactive stale manager must not export.');
        } catch (HttpException $exception) {
            $this->assertSame(403, $exception->getStatusCode());
        }
        $this->manager->forceFill(['is_active' => true])->save();
        $this->manager->roles()->detach();
        foreach ([false, true] as $adminMode) {
            try {
                app(OrganizationLearningExports::class)->prepare($stale, $this->organization, $this->member, $adminMode);
                $this->fail('Missing current role must not export.');
            } catch (HttpException $exception) {
                $this->assertSame(403, $exception->getStatusCode());
            }
        }
        $this->assertSame(0, AuditEvent::where('action', 'organization.learning_exported')->count());
    }
}
