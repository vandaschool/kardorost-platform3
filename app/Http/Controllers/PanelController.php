<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Organization;
use App\Models\User;
use App\Services\LearningProgress;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rules\Password;
use Inertia\Inertia;
use Inertia\Response;

class PanelController extends Controller
{
    public function home(Request $request): RedirectResponse
    {
        abort_unless($request->user()->is_active, 403);
        $roles = $request->user()->roleKeys();
        $role = $request->session()->get('panel_role');
        if (! $role || ! in_array($role, $roles, true)) {
            $role = in_array('student', $roles, true) ? 'student' : ($roles[0] ?? null);
        }
        abort_unless($role, 403);

        return redirect()->route('panel.'.$role);
    }

    public function switch(Request $request): RedirectResponse
    {
        $role = $request->validate(['role' => 'required|in:student,coach,org_admin,admin'])['role'];
        abort_unless($request->user()->hasRole($role), 403);
        $request->session()->put('panel_role', $role);

        return redirect()->route('panel.'.$role);
    }

    public function dashboard(Request $request, string $role): Response
    {
        $enrollments = Enrollment::where('user_id', $request->user()->id)->with('course', 'cohort')->latest()->get();

        return Inertia::render('Dashboard', ['role' => $role, 'enrollments' => $role === 'student' ? $enrollments : [],
            'stats' => match ($role) {
                'admin' => ['users' => User::count(), 'courses' => Course::count(), 'organizations' => Organization::count(), 'enrollments' => Enrollment::where('status', 'active')->count()], 'coach' => ['courses' => Course::where('coach_id', $request->user()->id)->count()],default => ['courses' => $enrollments->where('status', 'active')->count()]
            },
            'courses' => $role === 'coach' ? Course::where('coach_id', $request->user()->id)->get() : [],
        ]);
    }

    public function learning(Request $request): Response
    {
        return Inertia::render('Learning', ['enrollments' => Enrollment::where('user_id', $request->user()->id)->with('course', 'cohort')->latest()->get(), 'progress' => $request->user() ? Enrollment::where('user_id', $request->user()->id)->where('status', 'active')->get()->mapWithKeys(fn ($e) => [$e->id => app(LearningProgress::class)->summary($e)]) : [], 'paymentEnabled' => (bool) config('platform.zibal.enabled')]);
    }

    public function profile(Request $request): Response
    {
        return Inertia::render('Profile');
    }

    public function updateProfile(Request $request): RedirectResponse
    {
        $data = $request->validate(['name' => 'required|string|max:100']);
        $request->user()->update($data);

        return back()->with('success', 'نام شما به‌روز شد.');
    }

    public function changePassword(Request $request): RedirectResponse
    {
        $data = $request->validate(['current_password' => ['required', 'current_password:web'], 'password' => ['required', 'confirmed', Password::min(8)]]);
        $request->user()->forceFill(['password' => $data['password'], 'remember_token' => null])->save();
        DB::table('sessions')->where('user_id', $request->user()->id)->where('id', '!=', $request->session()->getId())->delete();
        $request->session()->regenerate();
        AuditEvent::create(['actor_id' => $request->user()->id, 'action' => 'password.changed']);

        return back()->with('success', 'رمز عبور تغییر کرد و نشست‌های دیگر بسته شدند.');
    }

    public function admin(Request $request, string $section = 'courses'): Response
    {
        abort_unless(in_array($section, ['courses', 'users', 'organizations', 'activity']), 404);

        return Inertia::render('Admin', ['section' => $section, 'courses' => $section === 'courses' ? Course::with('cohorts.installmentPlan')->latest()->get() : [],
            'users' => $section === 'users' ? User::with('roles:id,key,name')->latest()->get(['id', 'name', 'phone', 'is_active']) : [],
            'organizations' => $section === 'organizations' ? Organization::withCount('members')->latest()->get() : [],
            'coaches' => $section === 'courses' ? User::where('is_active', true)->whereHas('roles', fn ($q) => $q->where('key', 'coach'))->get(['id', 'name']) : [],
            'events' => $section === 'activity' ? AuditEvent::latest()->limit(100)->get() : []]);
    }
}
