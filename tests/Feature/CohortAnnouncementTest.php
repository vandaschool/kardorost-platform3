<?php

namespace Tests\Feature;

use App\Models\Announcement;
use App\Models\Cohort;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Notifications\PlatformAnnouncement;
use App\Services\Announcements;
use App\Services\EnrollmentService;
use App\Services\OrganizationMembership;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class CohortAnnouncementTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $coach;

    private Course $course;

    private Cohort $cohort;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Http::preventStrayRequests();
        $this->travelTo(now()->startOfMinute());
        $this->admin = $this->user('admin');
        $this->coach = $this->user('coach');
        $this->course = Course::factory()->create(['coach_id' => $this->coach->id, 'price' => 0, 'is_published' => true]);
        $this->cohort = Cohort::factory()->create(['course_id' => $this->course->id, 'capacity' => 30, 'registration_open' => true]);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('key', $role)->value('id'));

        return $user;
    }

    private function student(?Cohort $cohort = null, string $status = 'active', ?User $user = null): Enrollment
    {
        $user ??= $this->user('student');
        if (! $user->hasRole('student')) {
            $user->roles()->attach(Role::where('key', 'student')->value('id'));
        }

        return Enrollment::factory()->create(['user_id' => $user->id, 'cohort_id' => ($cohort ?? $this->cohort)->id, 'status' => $status])->fresh();
    }

    private function definition(array $roles = ['student'], ?Cohort $cohort = null): array
    {
        return ['title' => 'PRIVATE-COHORT-NOTICE', 'message' => 'پیام خصوصی آزمایشی برای نوبت آموزشی', 'roles' => $roles, 'audience' => ['type' => 'cohort', 'cohort_id' => ($cohort ?? $this->cohort)->id]];
    }

    private function published(array $roles = ['student'], ?Cohort $cohort = null): Announcement
    {
        $announcement = app(Announcements::class)->save($this->admin, $this->definition($roles, $cohort));
        app(Announcements::class)->publish($this->admin, $announcement, 1);

        return $announcement->fresh();
    }

    public function test_scope_derives_course_and_label_on_server_and_rejects_wrong_targets_or_roles(): void
    {
        $definition = $this->definition();
        $definition['audience']['label'] = 'FORGED-LABEL';
        $announcement = app(Announcements::class)->save($this->admin, $definition);
        $this->assertSame($this->course->id, $announcement->audience['course_id']);
        $this->assertSame($this->course->title.' · '.$this->cohort->title, $announcement->audience['label']);
        foreach ([['roles' => ['admin']], ['roles' => ['org_admin']], ['audience' => ['type' => 'cohort', 'cohort_id' => 999999]], ['audience' => ['type' => 'cohort', 'cohort_id' => $this->cohort->id, 'course_id' => 999999]], ['audience' => ['type' => 'all', 'cohort_id' => $this->cohort->id]], ['audience' => ['type' => 'organization', 'organization_id' => 1, 'cohort_id' => $this->cohort->id]]] as $change) {
            $this->actingAs($this->admin)->postJson('/admin/announcements', ['revision' => 1, 'definition' => [...$this->definition(), ...$change]])->assertUnprocessable();
        }
    }

    public function test_only_active_students_of_exact_cohort_are_snapshotted(): void
    {
        $enrollment = $this->student();
        $other = Cohort::factory()->create(['course_id' => $this->course->id]);
        $this->student($other);
        $this->student(status: 'pending_payment');
        $this->student(status: 'expired');
        $this->student(status: 'cancelled');
        $announcement = $this->published();
        $this->assertSame([$enrollment->user_id], $announcement->recipients()->pluck('user_id')->all());
    }

    public function test_only_current_assigned_course_coach_is_a_teacher_recipient(): void
    {
        $other = $this->user('coach');
        Course::factory()->create(['coach_id' => $other->id]);
        $announcement = $this->published(['coach']);
        $this->assertSame([$this->coach->id], $announcement->recipients()->pluck('user_id')->all());
    }

    public function test_multi_role_coach_student_gets_one_source_with_both_original_access_routes(): void
    {
        $enrollment = $this->student(user: $this->coach);
        $announcement = $this->published(['student', 'coach']);
        $recipient = $announcement->recipients()->sole();
        $this->assertSame($enrollment->id, $recipient->scope_snapshot['enrollment_id']);
        $this->assertSame($enrollment->billing_version, $recipient->scope_snapshot['billing_version']);
        $this->assertTrue($recipient->scope_snapshot['coach']);
        $this->assertSame(1, app(Announcements::class)->deliver($announcement->id));
    }

    public function test_reused_enrollment_with_new_billing_version_cannot_receive_old_pending_notice(): void
    {
        $enrollment = $this->student();
        $announcement = $this->published();
        DB::transaction(function () use ($enrollment) {
            Cohort::whereKey($this->cohort->id)->lockForUpdate()->firstOrFail();
            $enrollment->update(['status' => 'expired']);
        });
        $renewed = app(EnrollmentService::class)->reserve($enrollment->user, $this->cohort);
        $this->assertSame($enrollment->id, $renewed->id);
        $this->assertSame(2, $renewed->billing_version);
        $this->assertSame(0, app(Announcements::class)->deliver($announcement->id));
        $this->assertSame('scope_changed', $announcement->recipients()->sole()->failure_code);
    }

    public function test_ended_enrollment_cannot_receive_pending_notice(): void
    {
        $enrollment = $this->student();
        $announcement = $this->published();
        $enrollment->update(['status' => 'expired']);
        $this->assertSame(0, app(Announcements::class)->deliver($announcement->id));
        $this->assertSame('skipped', $announcement->recipients()->sole()->status);
    }

    public function test_reassigned_coach_does_not_inherit_old_notice_or_leave_it_with_previous_coach(): void
    {
        $announcement = $this->published(['coach']);
        $new = $this->user('coach');
        $this->course->update(['coach_id' => $new->id]);
        $this->assertSame(0, app(Announcements::class)->deliver($announcement->id));
        $next = $this->published(['coach']);
        $this->assertSame([$new->id], $next->recipients()->pluck('user_id')->all());
        $this->assertSame(1, app(Announcements::class)->deliver($next->id));
    }

    public function test_an_original_valid_student_route_survives_loss_of_teacher_assignment(): void
    {
        $this->student(user: $this->coach);
        $announcement = $this->published(['student', 'coach']);
        $this->course->update(['coach_id' => $this->user('coach')->id]);
        $this->assertSame(1, app(Announcements::class)->deliver($announcement->id));
        $this->assertSame($this->coach->id, $announcement->recipients()->sole()->user_id);
    }

    public function test_a_new_role_cannot_borrow_the_other_original_access_route(): void
    {
        $enrollment = $this->student();
        $announcement = $this->published(['student', 'coach']);
        $student = $enrollment->user;
        $student->roles()->detach();
        $student->roles()->attach(Role::where('key', 'coach')->value('id'));
        $this->course->update(['coach_id' => $student->id]);
        $this->assertSame(0, app(Announcements::class)->deliver($announcement->id));
    }

    public function test_organization_departure_does_not_cancel_active_personal_learning_notice_access(): void
    {
        $enrollment = $this->student();
        $organization = Organization::factory()->create(['status' => 'active']);
        $organization->members()->attach($enrollment->user_id, ['kind' => 'member']);
        $key = $organization->members()->where('users.id', $enrollment->user_id)->firstOrFail()->pivot->membership_key;
        $announcement = $this->published();
        app(OrganizationMembership::class)->endMembership($enrollment->user, $organization, $enrollment->user, $key, 'خروج آزمایشی از مجموعه', 'leave');
        $this->assertSame('active', $enrollment->fresh()->status);
        $this->assertSame(1, app(Announcements::class)->deliver($announcement->id));
    }

    public function test_private_cohort_inbox_has_encrypted_bound_payload_and_owner_visible_cohort_label(): void
    {
        $enrollment = $this->student();
        $announcement = $this->published();
        $this->assertSame(1, app(Announcements::class)->deliver($announcement->id));
        $raw = DB::table('notifications')->where('type', PlatformAnnouncement::class)->value('data');
        $this->assertStringNotContainsString('PRIVATE-COHORT-NOTICE', $raw);
        $this->actingAs($enrollment->user)->get('/notifications')->assertInertia(fn (Assert $page) => $page->where('notifications.data.0.data.audience_type', 'cohort')->where('notifications.data.0.data.audience_label', $announcement->audience['label'])->missing('notifications.data.0.data.private_payload'));
        $this->assertSame(0, app(Announcements::class)->deliver($announcement->id));
    }

    public function test_scheduled_cohort_audience_is_current_at_due_and_not_extended_after_publication(): void
    {
        $old = $this->student();
        $announcement = app(Announcements::class)->save($this->admin, $this->definition());
        $schedule = app(Announcements::class)->schedule($this->admin, $announcement, 1, CarbonImmutable::now()->addMinutes(2), true);
        $old->update(['status' => 'expired']);
        $current = $this->student();
        $this->travelTo($schedule->scheduled_for->copy()->addMinute());
        $this->assertSame('published', app(Announcements::class)->processSchedule($schedule));
        $late = $this->student();
        $this->assertSame([$current->user_id], $announcement->recipients()->pluck('user_id')->all());
        $this->assertSame(1, app(Announcements::class)->deliver($announcement->id));
        $this->assertSame(0, $late->user->notifications()->count());
    }

    public function test_changing_cohort_closes_pending_approval_and_keeps_original_label(): void
    {
        $announcement = app(Announcements::class)->save($this->admin, $this->definition());
        $schedule = app(Announcements::class)->schedule($this->admin, $announcement, 1, CarbonImmutable::now()->addMinutes(2), true);
        $label = $schedule->definition['audience']['label'];
        $other = Cohort::factory()->create(['course_id' => $this->course->id]);
        app(Announcements::class)->save($this->admin, $this->definition(cohort: $other), $announcement, 2);
        $this->assertSame('cancelled', $schedule->fresh()->status);
        $this->assertSame($label, $schedule->fresh()->definition['audience']['label']);
        $this->assertSame($other->id, $announcement->fresh()->audience['cohort_id']);
    }

    public function test_closed_registration_and_hidden_public_catalog_do_not_erase_active_class_scope(): void
    {
        $enrollment = $this->student();
        $this->cohort->update(['registration_open' => false]);
        $this->course->update(['is_published' => false]);
        $announcement = $this->published();
        $this->assertSame([$enrollment->user_id], $announcement->recipients()->pluck('user_id')->all());
    }

    public function test_stale_or_changed_cohort_course_binding_cannot_retarget_an_approved_notice(): void
    {
        $announcement = $this->published(['coach']);
        $otherCourse = Course::factory()->create(['coach_id' => $this->coach->id]);
        $this->cohort->update(['course_id' => $otherCourse->id]);
        $this->assertSame(0, app(Announcements::class)->deliver($announcement->id));
    }

    public function test_long_legitimate_cohort_label_is_preserved_in_private_payload(): void
    {
        $this->course->update(['title' => str_repeat('a', 160)]);
        $this->cohort->update(['title' => str_repeat('b', 150)]);
        $announcement = $this->published(['coach']);
        $this->assertGreaterThan(255, strlen($announcement->audience['label']));
        app(Announcements::class)->deliver($announcement->id);
        $this->actingAs($this->coach)->get('/notifications')->assertInertia(fn (Assert $page) => $page->where('notifications.data.0.data.audience_label', $announcement->audience['label']));
    }

    public function test_old_organization_envelope_without_audience_type_remains_readable(): void
    {
        $payload = ['engine' => 'organization_announcement_inbox_v1', 'recipient_id' => $this->admin->id, 'announcement_id' => 123, 'title' => 'Legacy private title', 'text' => 'Legacy private body', 'audience_label' => 'Legacy organization'];
        $data = ['announcement_id' => 123, 'private_payload_version' => 1, 'private_payload' => Crypt::encryptString(json_encode($payload))];
        $this->assertSame('organization', PlatformAnnouncement::display($data, $this->admin->id)['audience_type']);
        $this->assertSame('Legacy private title', PlatformAnnouncement::display($data, $this->admin->id)['title']);
    }
}
