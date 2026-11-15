<?php

namespace App\Http\Controllers;

use App\Models\CoachProfile;
use App\Models\CoachProfileWithdrawal;
use App\Services\CoachProfiles;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CoachProfileController extends Controller
{
    private function data(CoachProfile $profile): array
    {
        $profile->loadMissing('owner:id,name,phone,is_active,phone_verified_at', 'currentPublication', 'latestSubmission');

        return [...$profile->only('id', 'revision', 'state', 'public_enabled', 'public_published_at', 'draft'), 'owner' => $profile->owner->only('id', 'name', 'phone'), 'public_url' => route('public.mentor', $profile->slug), 'published' => $profile->currentPublication?->only('number', 'definition', 'published_at'), 'submission' => $profile->latestSubmission?->only('id', 'profile_revision', 'definition', 'status', 'consented_at', 'note', 'reviewed_at')];
    }

    private function history(CoachProfile $profile): array
    {
        return ['submissions' => $profile->submissions()->latest('id')->paginate(10, ['profile_revision', 'status', 'definition', 'consented_at', 'note', 'reviewed_at', 'created_at'], 'submissions_page')->withQueryString(), 'withdrawals' => CoachProfileWithdrawal::where('coach_profile_id', $profile->id)->latest('id')->limit(20)->get()->map(fn ($row) => ['by_owner' => $row->actor_id === $profile->user_id, 'reason' => $row->reason, 'created_at' => $row->created_at])];
    }

    public function own(Request $request, CoachProfiles $service): Response
    {
        $user = $request->user()->fresh();
        $profile = CoachProfile::where('user_id', $user->id)->first();
        $eligible = $service->eligible($user);
        abort_unless($eligible || $profile, 403);

        return Inertia::render('CoachProfileEditor', ['profile' => $profile ? $this->data($profile) : null, 'can_edit' => $eligible, 'history' => $profile ? $this->history($profile) : null]);
    }

    public function save(Request $request, CoachProfiles $service): RedirectResponse
    {
        $data = $request->validate(['revision' => 'required|integer|min:0', 'definition' => 'required|array']);
        $service->save($request->user(), $data['definition'], $data['revision']);

        return redirect()->route('coach.profile')->with('success', 'پیش‌نویس ذخیره شد؛ نسخه عمومی قبلی تا بررسی نسخه تازه حفظ می‌شود.');
    }

    public function submit(Request $request, CoachProfiles $service): RedirectResponse
    {
        $data = $request->validate(['revision' => 'required|integer|min:1', 'public_consent' => 'required|accepted']);
        $profile = CoachProfile::where('user_id', $request->user()->id)->firstOrFail();
        $service->submit($request->user(), $profile, $data['revision'], true);

        return back()->with('success', 'همین نسخه با رضایت شما برای بررسی مدیریت ثبت شد.');
    }

    public function ownWithdraw(Request $request, CoachProfiles $service): RedirectResponse
    {
        $profile = CoachProfile::where('user_id', $request->user()->id)->firstOrFail();

        return $this->withdraw($request, $profile, $service);
    }

    public function admin(Request $request, CoachProfiles $service, ?CoachProfile $profile = null): Response
    {
        $data = $request->validate(['state' => 'nullable|in:all,draft,submitted,approved,rejected']);
        $state = $data['state'] ?? 'submitted';
        $rows = CoachProfile::with('owner:id,name,phone')->when($state !== 'all', fn ($q) => $q->where('state', $state))->latest('id')->paginate(15)->withQueryString()->through(fn ($row) => [...$row->only('id', 'state', 'public_enabled', 'revision'), 'owner' => $row->owner->only('name', 'phone')]);

        return Inertia::render('AdminCoachProfiles', ['profiles' => $rows, 'state' => $state, 'selected' => $profile ? $this->data($profile) : null, 'eligible' => $profile ? $service->eligible($profile->owner) : false, 'history' => $profile ? $this->history($profile) : null]);
    }

    public function review(Request $request, CoachProfile $profile, CoachProfiles $service): RedirectResponse
    {
        $data = $request->validate(['revision' => 'required|integer|min:1', 'action' => 'required|in:publish,reject', 'reviewed' => 'exclude_unless:action,publish|required|accepted', 'note' => 'required_if:action,reject|nullable|string|min:3|max:2000']);
        $service->review($request->user(), $profile, $data['revision'], $data['action'], $data['note'] ?? null);

        return back()->with('success', 'نتیجه بررسی همین نسخه ثبت شد.');
    }

    public function withdraw(Request $request, CoachProfile $profile, CoachProfiles $service): RedirectResponse
    {
        $data = $request->validate(['revision' => 'required|integer|min:1', 'reason' => 'nullable|string|max:2000']);
        $service->withdraw($request->user(), $profile, $data['revision'], $data['reason'] ?? null);

        return back()->with('success', 'نمایش عمومی و بررسی در انتظار این پروفایل بسته شدند.');
    }
}
