<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Cohort;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\LearningBadge;
use App\Models\LearningRewardEvent;
use App\Models\LearningRewardPolicy;
use App\Models\LearningRewardVersion;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class LearningRewards
{
    public function configure(User $actor, Cohort $cohort, array $input): void
    {
        $actor = $actor->fresh();
        abort_unless($actor->is_active && $actor->hasRole('admin'), 403);
        $data = Validator::make($input, [
            'revision' => 'required|integer|min:0', 'enabled' => 'required|boolean',
            'reviewed' => 'required|accepted',
            'lesson_points' => 'exclude_unless:enabled,true|required|integer|between:1,100',
            'badge_enabled' => 'exclude_unless:enabled,true|required|boolean',
            'badge_goal' => 'exclude_unless:enabled,true|exclude_unless:badge_enabled,true|required|integer|between:1,100000',
            'badge_title' => 'exclude_unless:enabled,true|exclude_unless:badge_enabled,true|required|string|min:3|max:100',
        ])->validate();
        $enabled = (bool) $data['enabled'];
        $badge = $enabled && (bool) $data['badge_enabled'];
        $definition = ['method' => 'self_reported_lesson_v1', 'enabled' => $enabled, 'lesson_points' => $enabled ? (int) $data['lesson_points'] : 0, 'badge_goal' => $badge ? (int) $data['badge_goal'] : null, 'badge_title' => $badge ? $data['badge_title'] : null];
        DB::transaction(function () use ($actor, $cohort, $data, $enabled, $definition) {
            Course::whereKey($cohort->course_id)->lockForUpdate()->firstOrFail();
            Cohort::whereKey($cohort->id)->lockForUpdate()->firstOrFail();
            $policy = LearningRewardPolicy::firstOrCreate(['cohort_id' => $cohort->id]);
            if ($policy->revision !== (int) $data['revision']) {
                throw ValidationException::withMessages(['policy' => 'قواعد تغییر کرده‌اند؛ صفحه را تازه کنید.']);
            }
            $revision = $policy->revision + 1;
            $version = LearningRewardVersion::create(['learning_reward_policy_id' => $policy->id, 'revision' => $revision, 'definition' => $definition]);
            $policy->update(['revision' => $revision, 'enabled' => $enabled, 'current_version_id' => $version->id, 'updated_by' => $actor->id]);
            AuditEvent::create(['actor_id' => $actor->id, 'action' => 'learning_rewards.configured', 'subject_type' => 'learning_reward_policy', 'subject_id' => $policy->id, 'metadata' => ['revision' => $revision, 'enabled' => $enabled]]);
        }, 3);
    }

    /** Called only on first completion while the recorder holds current parent/account locks. */
    public function completed(User $actor, Enrollment $enrollment, Lesson $lesson, LessonProgress $progress): void
    {
        if (DB::transactionLevel() < 1 || ! $progress->completed_at || $progress->enrollment_id !== $enrollment->id || $progress->lesson_id !== $lesson->id) {
            throw new \LogicException('A locked, persisted first completion is required.');
        }
        $policy = LearningRewardPolicy::with('currentVersion')->where('cohort_id', $enrollment->cohort_id)->where('enabled', true)->first();
        if (! $policy?->currentVersion) {
            return;
        }
        $version = $policy->currentVersion;
        $definition = $version->definition;
        $event = LearningRewardEvent::firstOrCreate(['user_id' => $actor->id, 'lesson_id' => $lesson->id], ['enrollment_id' => $enrollment->id, 'cohort_id' => $enrollment->cohort_id, 'learning_reward_version_id' => $version->id, 'points' => $definition['lesson_points'], 'lesson_title' => $lesson->title, 'cohort_title' => $enrollment->cohort->title, 'earned_at' => now()]);
        if (! $event->wasRecentlyCreated) {
            return;
        }
        AuditEvent::create(['actor_id' => $actor->id, 'action' => 'learning_rewards.earned', 'subject_type' => 'learning_reward_event', 'subject_id' => $event->id, 'metadata' => ['points' => $event->points, 'version_id' => $version->id]]);
        if ($definition['badge_goal']) {
            $points = (int) LearningRewardEvent::where('user_id', $actor->id)->where('cohort_id', $enrollment->cohort_id)->sum('points');
            if ($points >= $definition['badge_goal']) {
                LearningBadge::firstOrCreate(['user_id' => $actor->id, 'cohort_id' => $enrollment->cohort_id], ['learning_reward_version_id' => $version->id, 'title' => $definition['badge_title'], 'goal_points' => $definition['badge_goal'], 'points_at_award' => $points, 'earned_at' => now()]);
            }
        }
    }

    public function terms(int $cohortId): ?array
    {
        $policy = LearningRewardPolicy::with('currentVersion')->where('cohort_id', $cohortId)->where('enabled', true)->first();

        return $policy?->currentVersion?->definition;
    }

    public function summary(User $user): array
    {
        $query = LearningRewardEvent::where('user_id', $user->id);
        $days = (clone $query)->selectRaw('DATE(earned_at) as day')->groupByRaw('DATE(earned_at)')->orderBy('day')->pluck('day')->all();
        $best = 0;
        $run = 0;
        $previous = null;
        foreach ($days as $day) {
            $date = CarbonImmutable::parse($day, 'Asia/Tehran');
            $run = $previous?->addDay()->equalTo($date) ? $run + 1 : 1;
            $best = max($best, $run);
            $previous = $date;
        }
        $today = CarbonImmutable::now('Asia/Tehran')->startOfDay();
        $current = $previous && ($previous->equalTo($today) || $previous->equalTo($today->subDay())) ? $run : 0;

        return ['points' => (int) (clone $query)->sum('points'), 'rewarded_lessons' => (clone $query)->count(), 'badges' => LearningBadge::where('user_id', $user->id)->count(), 'active_reward_days' => count($days), 'current_streak' => $current, 'best_streak' => $best];
    }
}
