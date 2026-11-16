<?php

namespace App\Services;

use App\Models\Announcement;
use App\Models\AnnouncementRecipient;
use App\Models\Cohort;
use App\Models\Course;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\ValidationException;

class AnnouncementAudience
{
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['audience' => $message]);
    }

    public function normalize(?array $input, array $roles, bool $stored = false): ?array
    {
        if ($input === null) {
            return null;
        }
        $data = Validator::make($input, ['type' => 'required|in:all,organization,cohort', 'organization_id' => 'nullable|integer|min:1', 'cohort_id' => 'nullable|integer|min:1', 'course_id' => 'nullable|integer|min:1', 'label' => 'sometimes|string|max:512'])->validate();
        if ($data['type'] === 'all') {
            if ($stored || ! empty($data['organization_id']) || ! empty($data['cohort_id']) || ! empty($data['course_id']) || isset($data['label'])) {
                $this->fail('محدوده عمومی نمی‌تواند مجموعه یا نوبت داشته باشد.');
            }

            return null;
        }
        if ($data['type'] === 'organization') {
            if (empty($data['organization_id']) || ! empty($data['cohort_id']) || ! empty($data['course_id']) || $roles === [] || array_diff($roles, ['student', 'org_admin'])) {
                $this->fail('یک مجموعه و نقش دانشجوی عضو یا مدیر همان مجموعه را انتخاب کنید.');
            }
            $label = $stored ? ($data['label'] ?? '') : Organization::whereKey($data['organization_id'])->where('status', 'active')->value('name');
            $this->label($label, 255);

            return ['type' => 'organization', 'organization_id' => (int) $data['organization_id'], 'label' => trim($label)];
        }
        if (empty($data['cohort_id']) || ! empty($data['organization_id']) || $roles === [] || array_diff($roles, ['student', 'coach'])) {
            $this->fail('یک نوبت و نقش دانشجوی همان نوبت یا مربی دوره را انتخاب کنید.');
        }
        if ($stored) {
            $label = $data['label'] ?? '';
            $courseId = $data['course_id'] ?? null;
        } else {
            $cohort = Cohort::with('course')->find($data['cohort_id']);
            $label = $cohort ? $cohort->course->title.' · '.$cohort->title : '';
            $courseId = $cohort?->course_id;
            if (! empty($data['course_id']) && (int) $data['course_id'] !== $courseId) {
                $this->fail('نوبت با دوره انتخاب‌شده همخوان نیست.');
            }
        }
        if (! $courseId) {
            $this->fail('نوبت آموزشی معتبر انتخاب کنید.');
        }
        $this->label($label, 512);

        return ['type' => 'cohort', 'cohort_id' => (int) $data['cohort_id'], 'course_id' => (int) $courseId, 'label' => trim($label)];
    }

    private function label(mixed $label, int $maximum): void
    {
        if (! is_string($label) || trim($label) === '' || ! mb_check_encoding($label, 'UTF-8') || mb_strlen($label) > $maximum) {
            $this->fail('محدوده معتبر انتخاب کنید.');
        }
    }

    private function targets(?array $audience): array
    {
        $targets = ['organizations' => [], 'courses' => [], 'cohorts' => []];
        if ($audience === null) {
            return $targets;
        }
        $fields = match ($audience['type'] ?? null) {
            'organization' => ['organization_id' => 'organizations'],
            'cohort' => ['course_id' => 'courses', 'cohort_id' => 'cohorts'],
            default => [],
        };
        if ($fields === []) {
            $this->fail('محدوده ذخیره‌شده معتبر نیست؛ ارسال انجام نشد.');
        }
        foreach ($fields as $key => $target) {
            if (! filter_var($audience[$key] ?? null, FILTER_VALIDATE_INT) || (int) $audience[$key] < 1) {
                $this->fail('محدوده ذخیره‌شده معتبر نیست؛ ارسال انجام نشد.');
            }
            $targets[$target][] = (int) $audience[$key];
        }

        return $targets;
    }

    /** Parent order matches membership, learning and enrollment writers. */
    private function lockTargets(array $audiences): void
    {
        $targets = ['organizations' => [], 'courses' => [], 'cohorts' => []];
        foreach ($audiences as $audience) {
            foreach ($this->targets($audience) as $key => $ids) {
                $targets[$key] = array_values(array_unique([...$targets[$key], ...$ids]));
            }
        }
        foreach (['organizations' => Organization::class, 'courses' => Course::class, 'cohorts' => Cohort::class] as $key => $model) {
            if ($targets[$key] !== []) {
                $model::whereIn('id', $targets[$key])->orderBy('id')->sharedLock()->get();
            }
        }
    }

    public function lockForCreate(?array $audience): void
    {
        $this->lockTargets([$audience]);
    }

    public function lock(int $id, ?array $newAudience = null): Announcement
    {
        $before = Announcement::whereKey($id)->firstOrFail()->audience;
        $this->lockTargets([$before, $newAudience]);
        $announcement = Announcement::whereKey($id)->lockForUpdate()->firstOrFail();
        if ($announcement->audience !== $before) {
            $this->fail('محدوده اطلاعیه تغییر کرده است؛ صفحه را تازه کنید.');
        }

        return $announcement;
    }

    public function query(array $roles, ?array $audience = null): Builder
    {
        $audience = $this->normalize($audience, $roles, true);
        $query = User::where('is_active', true)->whereNotNull('phone_verified_at');
        if ($audience === null) {
            return $query->whereHas('roles', fn ($rolesQuery) => $rolesQuery->whereIn('key', $roles));
        }
        if ($audience['type'] === 'cohort') {
            if (! Cohort::whereKey($audience['cohort_id'])->where('course_id', $audience['course_id'])->exists()) {
                return $query->whereRaw('1 = 0');
            }

            return $query->where(function ($query) use ($audience, $roles): void {
                if (in_array('student', $roles, true)) {
                    $query->orWhere(fn ($student) => $student->whereHas('roles', fn ($role) => $role->where('key', 'student'))->whereExists(fn ($enrollment) => $enrollment->selectRaw('1')->from('enrollments')->whereColumn('enrollments.user_id', 'users.id')->where('enrollments.cohort_id', $audience['cohort_id'])->where('enrollments.course_id', $audience['course_id'])->where('enrollments.status', 'active')));
                }
                if (in_array('coach', $roles, true)) {
                    $query->orWhere(fn ($coach) => $coach->whereHas('roles', fn ($role) => $role->where('key', 'coach'))->whereExists(fn ($course) => $course->selectRaw('1')->from('courses')->where('courses.id', $audience['course_id'])->whereColumn('courses.coach_id', 'users.id')));
                }
            });
        }

        return $query->where(function ($query) use ($audience, $roles): void {
            foreach (['student' => 'member', 'org_admin' => 'manager'] as $role => $kind) {
                if (in_array($role, $roles, true)) {
                    $query->orWhere(function ($member) use ($audience, $role, $kind): void {
                        $member->whereHas('roles', fn ($roleQuery) => $roleQuery->where('key', $role))
                            ->whereHas('organizations', fn ($organization) => $organization->where('organizations.id', $audience['organization_id'])->where('organizations.status', 'active')->where('organization_user.kind', $kind));
                    });
                }
            }
        });
    }

    /** Caller holds the scoped parent read locks through publication and delivery. */
    public function freeze(Announcement $announcement): void
    {
        $audience = $this->normalize($announcement->audience, $announcement->roles, true);
        if ($audience === null) {
            return;
        }
        foreach ($announcement->recipients()->orderBy('id')->lazyById(200)->chunk(200) as $recipients) {
            $state = $this->state($announcement, $recipients->pluck('user_id')->all());
            $rows = [];
            foreach ($recipients as $recipient) {
                $proof = ['type' => $audience['type'], 'announcement_id' => $announcement->id, 'recipient_id' => $recipient->id, 'user_id' => $recipient->user_id];
                if ($audience['type'] === 'organization') {
                    $member = $state['members'][$recipient->user_id] ?? null;
                    if (! $member || ! $member->membership_key) {
                        $this->fail('عضویت هنگام ثبت مخاطبان تغییر کرد؛ ارسال انجام نشد.');
                    }
                    $proof += ['organization_id' => $audience['organization_id'], 'membership_key' => $member->membership_key, 'kind' => $member->kind];
                } else {
                    $enrollment = in_array('student', $announcement->roles, true) ? ($state['enrollments'][$recipient->user_id] ?? null) : null;
                    $coach = in_array('coach', $announcement->roles, true) && $state['coach_id'] === $recipient->user_id;
                    if (! $state['valid'] || (! $enrollment && ! $coach)) {
                        $this->fail('دسترسی نوبت هنگام ثبت مخاطبان تغییر کرد؛ ارسال انجام نشد.');
                    }
                    $proof += ['cohort_id' => $audience['cohort_id'], 'course_id' => $audience['course_id'], 'enrollment_id' => $enrollment ? (int) $enrollment->id : null, 'billing_version' => $enrollment ? (int) $enrollment->billing_version : null, 'coach' => $coach];
                }
                $recipient->scope_snapshot = $proof;
                $rows[] = $recipient->getAttributes();
            }
            DB::table('announcement_recipients')->upsert($rows, ['id'], ['scope_snapshot']);
        }
    }

    public function state(Announcement $announcement, array $users): array
    {
        $audience = $this->normalize($announcement->audience, $announcement->roles, true);
        if ($audience === null) {
            return ['type' => 'all'];
        }
        if ($audience['type'] === 'organization') {
            $active = Organization::whereKey($audience['organization_id'])->where('status', 'active')->exists();

            return ['type' => 'organization', 'members' => $active ? DB::table('organization_user')->where('organization_id', $audience['organization_id'])->whereIn('user_id', $users)->get(['user_id', 'membership_key', 'kind'])->keyBy('user_id')->all() : []];
        }
        $cohort = Cohort::whereKey($audience['cohort_id'])->where('course_id', $audience['course_id'])->first();

        $coachId = $cohort?->course->coach_id;
        $coachId = $coachId ? User::whereKey($coachId)->whereHas('roles', fn ($role) => $role->where('key', 'coach'))->value('id') : null;
        $enrollments = $cohort ? DB::table('enrollments')->join('role_user', 'role_user.user_id', '=', 'enrollments.user_id')->join('roles', 'roles.id', '=', 'role_user.role_id')->where('roles.key', 'student')->where('enrollments.cohort_id', $cohort->id)->where('enrollments.course_id', $audience['course_id'])->where('enrollments.status', 'active')->whereIn('enrollments.user_id', $users)->get(['enrollments.id', 'enrollments.user_id', 'enrollments.billing_version'])->keyBy('user_id')->all() : [];

        return ['type' => 'cohort', 'valid' => $cohort !== null, 'coach_id' => $coachId, 'enrollments' => $enrollments];
    }

    public function sameAccess(Announcement $announcement, AnnouncementRecipient $recipient, array $state): bool
    {
        if ($announcement->audience === null) {
            return $recipient->scope_snapshot === null;
        }
        $snapshot = $recipient->scope_snapshot;
        $audience = $announcement->audience;
        if (! is_array($snapshot) || ($snapshot['type'] ?? null) !== $audience['type']
            || ($snapshot['announcement_id'] ?? null) !== $announcement->id
            || ($snapshot['recipient_id'] ?? null) !== $recipient->id
            || ($snapshot['user_id'] ?? null) !== $recipient->user_id) {
            return false;
        }
        if ($audience['type'] === 'organization') {
            $member = $state['members'][$recipient->user_id] ?? null;

            return $member && ($snapshot['organization_id'] ?? null) === $audience['organization_id']
                && ($snapshot['membership_key'] ?? null) === $member->membership_key
                && ($snapshot['kind'] ?? null) === $member->kind;
        }
        if (! $state['valid'] || ($snapshot['cohort_id'] ?? null) !== $audience['cohort_id'] || ($snapshot['course_id'] ?? null) !== $audience['course_id']) {
            return false;
        }
        $enrollment = $state['enrollments'][$recipient->user_id] ?? null;
        $student = in_array('student', $announcement->roles, true) && $enrollment
            && ($snapshot['enrollment_id'] ?? null) === (int) $enrollment->id
            && ($snapshot['billing_version'] ?? null) === (int) $enrollment->billing_version;
        $coach = in_array('coach', $announcement->roles, true) && ($snapshot['coach'] ?? false) === true && $state['coach_id'] === $recipient->user_id;

        return (bool) ($student || $coach);
    }
}
