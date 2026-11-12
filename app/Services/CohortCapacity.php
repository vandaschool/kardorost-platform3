<?php

namespace App\Services;

use App\Models\Cohort;
use App\Models\Enrollment;
use App\Models\OrganizationLicense;

class CohortCapacity
{
    public function occupied(Cohort $cohort, ?int $exceptEnrollment = null): int
    {
        return Enrollment::where('cohort_id', $cohort->id)->when($exceptEnrollment, fn ($q) => $q->whereKeyNot($exceptEnrollment))->where(fn ($q) => $q->where('status', 'active')->orWhere(fn ($q) => $q->where('status', 'pending_payment')->where('expires_at', '>', now())))->count();
    }

    public function reserved(Cohort $cohort, ?int $exceptLicense = null): int
    {
        return (int) OrganizationLicense::where('cohort_id', $cohort->id)->where('active', true)->where('expires_on', '>=', now()->toDateString())->whereHas('organization', fn ($q) => $q->where('status', 'active'))->when($exceptLicense, fn ($q) => $q->whereKeyNot($exceptLicense))->withCount('seats')->get()->sum(fn ($l) => max(0, $l->seat_limit - $l->seats_count));
    }

    /** Mutations must hold the cohort lock before using this capacity. */
    public function available(Cohort $cohort, ?int $exceptEnrollment = null, ?int $exceptLicense = null): int
    {
        return max(0, $cohort->capacity - $this->occupied($cohort, $exceptEnrollment) - $this->reserved($cohort, $exceptLicense));
    }
}
