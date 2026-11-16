<?php

namespace App\Http\Controllers;

use App\Models\AuditEvent;
use App\Models\Course;
use App\Models\Role;
use App\Models\User;
use App\Services\Phone;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class AdminUserController extends Controller
{
    public function index(Request $request): Response
    {
        $data = $request->validate([
            'q' => 'nullable|string|max:150',
            'phone' => 'nullable|string|max:40',
            'user_id' => 'nullable|integer|min:1',
            'role' => 'nullable|in:student,coach,org_admin,admin',
            'status' => 'nullable|in:all,active,inactive',
            'verification' => 'nullable|in:all,verified,unverified',
            'page' => 'nullable|integer|min:1|max:1000000',
        ]);
        $filters = [
            'q' => trim($data['q'] ?? ''),
            'phone' => trim($data['phone'] ?? '') === '' ? '' : Phone::normalize($data['phone']),
            'user_id' => isset($data['user_id']) ? (int) $data['user_id'] : '',
            'role' => $data['role'] ?? '',
            'status' => $data['status'] ?? 'all',
            'verification' => $data['verification'] ?? 'all',
        ];
        $query = User::query()->with('roles:id,key,name');
        if ($filters['q'] !== '') {
            $literal = str_replace(['!', '%', '_'], ['!!', '!%', '!_'], mb_strtolower($filters['q']));
            $query->whereRaw("LOWER(name) LIKE ? ESCAPE '!'", ['%'.$literal.'%']);
        }
        foreach (['phone' => 'phone', 'user_id' => 'id'] as $filter => $column) {
            if ($filters[$filter] !== '') {
                $query->where($column, $filters[$filter]);
            }
        }
        if ($filters['role'] !== '') {
            $query->whereHas('roles', fn ($role) => $role->where('key', $filters['role']));
        }
        if ($filters['status'] !== 'all') {
            $query->where('is_active', $filters['status'] === 'active');
        }
        if ($filters['verification'] !== 'all') {
            $query->whereNull('phone_verified_at', not: $filters['verification'] === 'verified');
        }
        $users = $query->orderByDesc('id')->paginate(25, ['id', 'name', 'phone', 'is_active', 'phone_verified_at', 'access_revision'])->appends(array_filter($filters, fn ($value) => $value !== '' && $value !== 'all'))->through(fn ($user) => [
            'id' => $user->id, 'name' => $user->name, 'phone' => $user->phone, 'is_active' => $user->is_active, 'phone_verified' => $user->phone_verified_at !== null, 'access_revision' => $user->access_revision,
            'roles' => $user->roles->map(fn ($role) => $role->only('key', 'name'))->all(),
        ]);

        return Inertia::render('AdminUsers', ['filters' => $filters, 'users' => $users]);
    }

    /** The caller holds the actor account lock through the mutation. */
    private function authorizeAdmin(?User $actor): void
    {
        abort_unless($actor?->is_active && $actor->hasRole('admin'), 403);
    }

    public function update(Request $request, User $user): RedirectResponse
    {
        $data = $request->validate(['revision' => 'required|integer|min:1', 'roles' => 'required|array|min:1|max:4', 'roles.*' => 'required|distinct|in:student,coach,org_admin,admin', 'is_active' => 'required|boolean']);
        $actorId = $request->user()?->id;
        abort_unless($actorId, 403);
        DB::transaction(function () use ($user, $data, $actorId) {
            $accounts = User::whereIn('id', [$actorId, $user->id])->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $this->authorizeAdmin($accounts->get($actorId));
            $user = $accounts->get($user->id);
            abort_unless($user, 404);
            if ($user->access_revision !== (int) $data['revision']) {
                throw ValidationException::withMessages(['revision' => 'دسترسی این حساب تغییر کرده است؛ فهرست را تازه کنید و دوباره بررسی کنید.']);
            }
            if ($user->id === $actorId && (! in_array('admin', $data['roles'], true) || ! $data['is_active'])) {
                throw ValidationException::withMessages(['roles' => 'نمی‌توانید دسترسی مدیریت یا فعالیت حساب خودتان را حذف کنید.']);
            }
            $user->roles()->sync(Role::whereIn('key', $data['roles'])->pluck('id'));
            $user->forceFill(['is_active' => $data['is_active'], 'access_revision' => $user->access_revision + 1])->save();
            if (! $data['is_active']) {
                DB::table('sessions')->where('user_id', $user->id)->delete();
            }
            AuditEvent::create(['actor_id' => $actorId, 'action' => 'user.access_updated', 'subject_type' => 'user', 'subject_id' => $user->id, 'metadata' => ['roles' => $data['roles'], 'is_active' => $data['is_active'], 'access_revision' => $user->access_revision]]);
        }, 3);

        return back()->with('success', 'دسترسی و وضعیت کاربر به‌روز شد.');
    }

    public function assignCoach(Request $request, Course $course): RedirectResponse
    {
        $data = $request->validate(['coach_id' => 'present|nullable|integer|exists:users,id']);
        $actorId = $request->user()?->id;
        abort_unless($actorId, 403);
        DB::transaction(function () use ($course, $data, $actorId) {
            $course = Course::whereKey($course->id)->lockForUpdate()->firstOrFail();
            $accounts = User::whereIn('id', array_filter([$actorId, $data['coach_id']]))->orderBy('id')->lockForUpdate()->get()->keyBy('id');
            $this->authorizeAdmin($accounts->get($actorId));
            if ($data['coach_id'] !== null) {
                $coach = $accounts->get($data['coach_id']);
                if (! $coach?->is_active || ! $coach->hasRole('coach')) {
                    throw ValidationException::withMessages(['coach_id' => 'کاربر انتخاب‌شده باید مربی فعال باشد.']);
                }
            }
            $course->update(['coach_id' => $data['coach_id']]);
            AuditEvent::create(['actor_id' => $actorId, 'action' => 'course.coach_assigned', 'subject_type' => 'course', 'subject_id' => $course->id, 'metadata' => $data]);
        }, 3);

        return back()->with('success', 'مربی دوره تعیین شد.');
    }
}
