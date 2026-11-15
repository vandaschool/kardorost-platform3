<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\Cohort;
use App\Models\LearningBadge;
use App\Models\LearningRewardEvent;
use App\Models\LearningRewardPolicy;
use App\Models\User;
use App\Services\LearningRewards;
use App\Services\Phone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class LearningRewardController extends Controller
{
    public function policies(): Response
    {
        $cohorts = Cohort::with('course:id,title')->latest('id')->paginate(12)->through(function ($cohort) {
            $policy = LearningRewardPolicy::with('currentVersion')->where('cohort_id', $cohort->id)->first();

            return ['id' => $cohort->id, 'title' => $cohort->title, 'course_title' => $cohort->course->title, 'policy' => $policy ? ['revision' => $policy->revision, 'enabled' => $policy->enabled, 'definition' => $policy->currentVersion?->definition] : null];
        });

        return Inertia::render('LearningRewardPolicies', ['cohorts' => $cohorts]);
    }

    public function configure(Request $request, Cohort $cohort, LearningRewards $service): RedirectResponse
    {
        $service->configure($request->user(), $cohort, $request->only('revision', 'enabled', 'reviewed', 'lesson_points', 'badge_enabled', 'badge_goal', 'badge_title'));

        return back()->with('success', 'قواعد برای تکمیل‌های بعدی ذخیره شد؛ امتیازهای قبلی تغییر نکردند.');
    }

    public function index(Request $request, LearningRewards $service): Response
    {
        $admin = $request->routeIs('admin.learning-rewards');
        $data = $request->validate(['phone' => 'nullable|string|max:30']);
        $user = $admin ? (! empty($data['phone']) ? User::where('phone', Phone::normalize($data['phone']))->first() : null) : $request->user();
        if ($admin && $user) {
            AuditEvent::create(['actor_id' => $request->user()->id, 'action' => 'learning_rewards.admin_viewed', 'subject_type' => 'user', 'subject_id' => $user->id]);
        }
        $events = $user ? LearningRewardEvent::where('user_id', $user->id)->latest('id')->paginate(20)->withQueryString()->through(fn ($event) => $event->only('id', 'enrollment_id', 'cohort_id', 'lesson_id', 'learning_reward_version_id', 'points', 'lesson_title', 'cohort_title', 'earned_at')) : null;
        $badges = $user ? LearningBadge::where('user_id', $user->id)->latest('id')->paginate(12, ['id', 'title', 'goal_points', 'points_at_award', 'earned_at'], 'badges_page')->withQueryString() : null;

        return Inertia::render('LearningRewards', ['admin' => $admin, 'subject' => $user?->only('id', 'name'), 'phone' => $admin ? ($data['phone'] ?? '') : '', 'summary' => $user ? $service->summary($user) : null, 'events' => $events, 'badges' => $badges]);
    }
}
