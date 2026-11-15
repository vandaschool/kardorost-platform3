<?php

namespace Tests\Feature;

use App\Models\Cohort;
use App\Models\CourseSection;
use App\Models\Enrollment;
use App\Models\LearningBadge;
use App\Models\LearningRewardEvent;
use App\Models\LearningRewardPolicy;
use App\Models\LearningRewardVersion;
use App\Models\Lesson;
use App\Models\Role;
use App\Models\User;
use App\Services\LearningRewards;
use App\Services\LessonProgressRecorder;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\TestCase;

class LearningRewardTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $student;

    private Enrollment $enrollment;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->travelTo(CarbonImmutable::parse('2026-06-01 12:00:00', 'Asia/Tehran'));
        $this->admin = $this->user('admin');
        $this->student = $this->user('student');
        $this->enrollment = Enrollment::factory()->create(['user_id' => $this->student->id]);
    }

    private function user(string $role): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::where('key', $role)->value('id'));

        return $user;
    }

    private function lesson(?Enrollment $enrollment = null): Lesson
    {
        return Lesson::factory()->create(['course_section_id' => CourseSection::factory()->create(['course_id' => ($enrollment ?? $this->enrollment)->course_id])->id]);
    }

    private function configure(array $extra = [], ?Cohort $cohort = null): void
    {
        $cohort ??= $this->enrollment->cohort;
        $revision = LearningRewardPolicy::where('cohort_id', $cohort->id)->value('revision') ?? 0;
        app(LearningRewards::class)->configure($this->admin, $cohort, [...['revision' => $revision, 'enabled' => true, 'lesson_points' => 10, 'badge_enabled' => true, 'badge_goal' => 20, 'badge_title' => 'نشان هدف آزمایشی', 'reviewed' => true], ...$extra]);
    }

    private function complete(?Lesson $lesson = null, ?Enrollment $enrollment = null): void
    {
        app(LessonProgressRecorder::class)->record($this->student, $enrollment ?? $this->enrollment, $lesson ?? $this->lesson($enrollment), ['complete' => true]);
    }

    public function test_rewards_are_disabled_without_explicit_admin_policy_and_no_demo_points_are_created(): void
    {
        $this->complete();
        $this->assertDatabaseCount('learning_reward_policies', 0);
        $this->assertDatabaseCount('learning_reward_events', 0);
        $this->assertDatabaseCount('learning_badges', 0);
        $this->actingAs($this->student)->get('/learning-rewards')->assertInertia(fn (Assert $page) => $page->where('summary.points', 0)->where('summary.current_streak', 0)->has('events.data', 0));
    }

    public function test_classroom_shows_current_reward_terms_before_completion(): void
    {
        $this->configure();
        $this->actingAs($this->student)->get('/learning/'.$this->enrollment->id)->assertInertia(fn (Assert $page) => $page->where('learning_rewards.lesson_points', 10)->where('learning_rewards.badge_goal', 20)->where('learning_rewards.badge_title', 'نشان هدف آزمایشی'));
        $this->configure(['enabled' => false]);
        $this->get('/learning/'.$this->enrollment->id)->assertInertia(fn (Assert $page) => $page->where('learning_rewards', null));
    }

    public function test_first_completion_earns_once_and_autosave_never_earns_points(): void
    {
        $this->configure();
        $lesson = $this->lesson();
        app(LessonProgressRecorder::class)->record($this->student, $this->enrollment, $lesson, ['position_seconds' => 60]);
        $this->assertDatabaseCount('learning_reward_events', 0);
        $this->complete($lesson);
        $this->complete($lesson);
        $this->assertDatabaseCount('learning_reward_events', 1);
        $this->assertSame(10, LearningRewardEvent::sole()->points);
        $this->assertSame(10, app(LearningRewards::class)->summary($this->student)['points']);
    }

    public function test_enabling_policy_does_not_retroactively_award_already_completed_lessons(): void
    {
        $old = $this->lesson();
        $this->complete($old);
        $this->configure();
        $this->complete($old);
        $this->assertDatabaseCount('learning_reward_events', 0);
        $this->complete();
        $this->assertDatabaseCount('learning_reward_events', 1);
    }

    public function test_rule_edits_freeze_prior_points_and_create_immutable_versions_for_new_events(): void
    {
        $this->configure();
        $this->complete();
        $first = LearningRewardEvent::sole();
        $version = LearningRewardVersion::findOrFail($first->learning_reward_version_id);
        $this->configure(['lesson_points' => 25]);
        $this->complete();
        $this->assertSame(10, $first->fresh()->points);
        $this->assertSame(10, $version->fresh()->definition['lesson_points']);
        $this->assertSame([10, 25], LearningRewardEvent::orderBy('id')->pluck('points')->all());
        $this->assertSame(2, LearningRewardVersion::count());
        $this->assertSame(35, app(LearningRewards::class)->summary($this->student)['points']);
    }

    public function test_disabling_and_reenabling_do_not_change_history_or_award_disabled_period_completions(): void
    {
        $this->configure();
        $this->complete();
        $this->configure(['enabled' => false]);
        $disabledLesson = $this->lesson();
        $this->complete($disabledLesson);
        $this->assertDatabaseCount('learning_reward_events', 1);
        $this->configure(['lesson_points' => 15]);
        $this->complete($disabledLesson);
        $this->complete();
        $this->assertSame([10, 15], LearningRewardEvent::orderBy('id')->pluck('points')->all());
    }

    public function test_repeating_the_same_lesson_in_another_cohort_does_not_farm_points(): void
    {
        $this->configure();
        $lesson = $this->lesson();
        $this->complete($lesson);
        $cohort = Cohort::factory()->create(['course_id' => $this->enrollment->course_id]);
        $enrollment = Enrollment::factory()->create(['course_id' => $cohort->course_id, 'cohort_id' => $cohort->id, 'user_id' => $this->student->id]);
        $this->configure(['lesson_points' => 100], $cohort);
        $this->complete($lesson, $enrollment);
        $this->assertDatabaseCount('learning_reward_events', 1);
        $this->assertSame(10, app(LearningRewards::class)->summary($this->student)['points']);
    }

    public function test_badge_is_awarded_once_on_goal_and_keeps_original_title_and_threshold_after_edits(): void
    {
        $this->configure();
        $this->complete();
        $this->assertDatabaseCount('learning_badges', 0);
        $this->complete();
        $badge = LearningBadge::sole();
        $this->assertSame(20, $badge->goal_points);
        $this->assertSame(20, $badge->points_at_award);
        $this->configure(['badge_goal' => 10, 'badge_title' => 'عنوان تازه']);
        $this->complete();
        $this->assertDatabaseCount('learning_badges', 1);
        $this->assertSame('نشان هدف آزمایشی', $badge->fresh()->title);
        $this->assertSame(20, $badge->fresh()->goal_points);
    }

    public function test_policy_without_badge_only_awards_points(): void
    {
        $this->configure(['badge_enabled' => false]);
        $this->complete();
        $this->complete();
        $this->assertDatabaseCount('learning_badges', 0);
        $this->assertSame(20, app(LearningRewards::class)->summary($this->student)['points']);
    }

    public function test_cohort_policy_does_not_grant_rewards_in_another_cohort(): void
    {
        $this->configure();
        $other = Enrollment::factory()->create(['user_id' => $this->student->id]);
        $this->complete(null, $other);
        $this->assertDatabaseCount('learning_reward_events', 0);
    }

    public function test_reward_history_and_badge_titles_are_encrypted_at_rest(): void
    {
        $this->configure(['badge_goal' => 10]);
        $lesson = $this->lesson();
        $this->complete($lesson);
        $raw = DB::table('learning_reward_events')->first();
        $this->assertStringNotContainsString($lesson->title, $raw->lesson_title);
        $this->assertNotSame($this->enrollment->cohort->title, $raw->cohort_title);
        $this->assertNotSame('نشان هدف آزمایشی', DB::table('learning_badges')->value('title'));
        $this->assertSame($lesson->title, LearningRewardEvent::sole()->lesson_title);
    }

    public function test_streak_counts_distinct_tehran_reward_days_and_expires_after_missing_yesterday(): void
    {
        $this->configure();
        foreach (['2026-06-01', '2026-06-02', '2026-06-04', '2026-06-05', '2026-06-05'] as $date) {
            $this->travelTo(CarbonImmutable::parse($date.' 12:00:00', 'Asia/Tehran'));
            $this->complete();
        }
        $summary = app(LearningRewards::class)->summary($this->student);
        $this->assertSame(4, $summary['active_reward_days']);
        $this->assertSame(2, $summary['best_streak']);
        $this->assertSame(2, $summary['current_streak']);
        $this->travelTo(CarbonImmutable::parse('2026-06-07 12:00:00', 'Asia/Tehran'));
        $summary = app(LearningRewards::class)->summary($this->student);
        $this->assertSame(0, $summary['current_streak']);
        $this->assertSame(2, $summary['best_streak']);
    }

    public function test_streak_uses_tehran_midnight_instead_of_utc_day(): void
    {
        $this->configure();
        $this->travelTo(CarbonImmutable::parse('2026-06-01 20:25:00', 'UTC'));
        $this->complete();
        $this->travelTo(CarbonImmutable::parse('2026-06-01 20:35:00', 'UTC'));
        $this->complete();
        $this->assertSame(2, app(LearningRewards::class)->summary($this->student)['current_streak']);
        $this->assertSame(2, app(LearningRewards::class)->summary($this->student)['active_reward_days']);
    }

    public function test_only_current_admin_can_configure_or_inspect_someone_elses_history(): void
    {
        foreach (['student', 'coach', 'org_admin'] as $role) {
            $user = $this->user($role);
            $this->actingAs($user)->get('/admin/learning-reward-policies')->assertForbidden();
            $this->get('/admin/learning-rewards?phone='.$this->student->phone)->assertForbidden();
            $this->patch('/admin/cohorts/'.$this->enrollment->cohort_id.'/learning-rewards', [])->assertForbidden();
        }
        $this->admin->roles()->detach();
        $this->actingAs($this->admin)->get('/admin/learning-rewards')->assertForbidden();
    }

    public function test_own_history_ignores_foreign_user_and_phone_parameters_and_admin_lookup_is_scoped(): void
    {
        $this->configure();
        $this->complete();
        $peer = $this->user('student');
        $this->actingAs($peer)->get('/learning-rewards?user_id='.$this->student->id.'&phone='.$this->student->phone)->assertInertia(fn (Assert $page) => $page->where('subject.id', $peer->id)->where('summary.points', 0)->has('events.data', 0));
        $this->actingAs($this->admin)->get('/admin/learning-rewards?phone='.$this->student->phone)->assertInertia(fn (Assert $page) => $page->where('subject.id', $this->student->id)->where('summary.points', 10)->has('events.data', 1));
        $this->assertDatabaseHas('audit_events', ['action' => 'learning_rewards.admin_viewed', 'subject_id' => $this->student->id]);
    }

    public function test_history_remains_available_after_student_role_is_replaced(): void
    {
        $this->configure();
        $this->complete();
        $this->student->roles()->sync([Role::where('key', 'coach')->value('id')]);
        $this->actingAs($this->student)->get('/learning-rewards')->assertInertia(fn (Assert $page) => $page->where('summary.points', 10)->has('events.data', 1));
    }

    public function test_stale_policy_and_unreviewed_or_invalid_definitions_do_not_overwrite_snapshot(): void
    {
        $this->configure();
        $url = '/admin/cohorts/'.$this->enrollment->cohort_id.'/learning-rewards';
        $base = ['revision' => 1, 'enabled' => true, 'lesson_points' => 10, 'badge_enabled' => false, 'reviewed' => true];
        $this->actingAs($this->admin);
        foreach ([['revision' => 0], ['reviewed' => false], ['lesson_points' => 0], ['lesson_points' => 101], ['lesson_points' => '2.5'], ['badge_enabled' => true, 'badge_goal' => 0, 'badge_title' => 'بدون هدف']] as $bad) {
            $this->patch($url, [...$base, ...$bad])->assertSessionHasErrors();
        }
        $this->assertSame(1, LearningRewardVersion::count());
        $this->assertSame(1, LearningRewardPolicy::sole()->revision);
    }

    public function test_other_owner_and_unpublished_lesson_cannot_generate_reward_events(): void
    {
        $this->configure();
        $peer = $this->user('student');
        $lesson = $this->lesson();
        $url = '/learning/'.$this->enrollment->id.'/lessons/'.$lesson->id.'/progress';
        $this->actingAs($peer)->post($url, ['complete' => true])->assertForbidden();
        $lesson->update(['is_published' => false]);
        $this->actingAs($this->student)->post($url, ['complete' => true])->assertForbidden();
        $this->assertDatabaseCount('learning_reward_events', 0);
        $this->assertDatabaseCount('learning_badges', 0);
    }

    public function test_reward_migration_preserves_policies_and_earned_history_on_rollback(): void
    {
        $this->configure();
        $migration = require database_path('migrations/2026_11_15_181534_create_learning_rewards.php');
        $this->expectException(\RuntimeException::class);
        $migration->down();
    }
}
