<?php

namespace App\Services;

use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Organization;
use App\Models\Submission;
use App\Models\User;

class LearningAccess
{
    public function canManage(User $user, Course $course): bool
    {
        return $user->is_active && ($user->hasRole('admin') || ($user->hasRole('coach') && $course->coach_id === $user->id));
    }

    public function manage(User $user, Course $course): void
    {
        abort_unless($this->canManage($user, $course), 403);
    }

    public function learn(User $user, Enrollment $enrollment): void
    {
        abort_unless($user->is_active && $user->hasRole('student') && $enrollment->user_id === $user->id && $enrollment->status === 'active', 403);
    }

    public function download(User $user, Submission $submission, ?Organization $organization = null): void
    {
        $enrollment = $submission->enrollment;
        abort_unless($user->is_active, 403);
        if ($this->canManage($user, $enrollment->course)) {
            return;
        }
        if ($organization && $organization->status === 'active' && $user->hasRole('org_admin') && $organization->members()->where('users.id', $user->id)->wherePivot('kind', 'manager')->exists() && $organization->members()->where('users.id', $enrollment->user_id)->exists()) {
            return;
        }
        $this->learn($user, $enrollment);
    }
}
