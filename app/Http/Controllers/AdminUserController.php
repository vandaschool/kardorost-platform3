<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\Course;
use App\Models\Role;
use App\Models\User;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class AdminUserController extends Controller
{
    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate(['roles' => 'required|array|min:1|max:4', 'roles.*' => 'required|distinct|in:student,coach,org_admin,admin', 'is_active' => 'required|boolean']);
        if ($user->id === $request->user()->id && (! in_array('admin', $data['roles'], true) || ! $data['is_active'])) {
            throw ValidationException::withMessages(['roles' => 'نمی‌توانید دسترسی مدیریت یا فعالیت حساب خودتان را حذف کنید.']);
        }
        DB::transaction(function () use ($user, $data, $request) {
            $user->roles()->sync(Role::whereIn('key', $data['roles'])->pluck('id'));
            $user->forceFill(['is_active' => $data['is_active']])->save();
            if (! $data['is_active']) {
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }
            AuditEvent::create(['actor_id' => $request->user()->id, 'action' => 'user.access_updated', 'subject_type' => 'user', 'subject_id' => $user->id, 'metadata' => $data]);
        });

        return back()->with('success', 'دسترسی و وضعیت کاربر به‌روز شد.');
    }

    public function assignCoach(Request $request, Course $course): RedirectResponse
    {
        $data = $request->validate(['coach_id' => 'nullable|integer|exists:users,id']);
        if (! empty($data['coach_id'])) {
            $coach = User::findOrFail($data['coach_id']);
            if (! $coach->is_active || ! $coach->hasRole('coach')) {
                throw ValidationException::withMessages(['coach_id' => 'کاربر انتخاب‌شده باید مربی فعال باشد.']);
            }
        }
        $course->update(['coach_id' => $data['coach_id']]);
        AuditEvent::create(['actor_id' => $request->user()->id, 'action' => 'course.coach_assigned', 'subject_type' => 'course', 'subject_id' => $course->id, 'metadata' => $data]);

        return back()->with('success', 'مربی دوره تعیین شد.');
    }
}
