<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Cohort;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Payment;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class EnrollmentService
{
    public function reserve(User $user, Cohort $cohort): Enrollment
    {
        return DB::transaction(function () use ($user, $cohort) {
            $cohort = Cohort::whereKey($cohort->id)->lockForUpdate()->firstOrFail();
            $course = Course::whereKey($cohort->course_id)->firstOrFail();
            if (! $cohort->registration_open || ! $course->is_published) {
                throw ValidationException::withMessages(['cohort' => 'ثبت‌نام این دوره باز نیست.']);
            }
            $existing = Enrollment::where('user_id', $user->id)->where('cohort_id', $cohort->id)->first();
            if ($existing && ($existing->status === 'active' || ($existing->status === 'pending_payment' && $existing->expires_at?->isFuture()))) {
                return $existing;
            }
            $occupied = Enrollment::where('cohort_id', $cohort->id)->where(function ($q) {
                $q->where('status', 'active')->orWhere(fn ($q) => $q->where('status', 'pending_payment')->where('expires_at', '>', now()));
            })->count();
            if ($occupied >= $cohort->capacity) {
                throw ValidationException::withMessages(['cohort' => 'ظرفیت این نوبت تکمیل شده است.']);
            }
            $attributes = ['amount' => $course->price, 'user_id' => $user->id, 'course_id' => $course->id, 'cohort_id' => $cohort->id, 'status' => $course->price === 0 ? 'active' : 'pending_payment', 'expires_at' => $course->price === 0 ? null : now()->addMinutes(15)];
            if ($existing) {
                Payment::where('enrollment_id', $existing->id)->where('status', 'pending')->update(['status' => 'expired']);
                $existing->update($attributes);
                $enrollment = $existing;
            } else {
                $enrollment = Enrollment::create($attributes);
            }
            AuditEvent::create(['actor_id' => $user->id, 'action' => 'enrollment.created', 'subject_type' => 'enrollment', 'subject_id' => $enrollment->id]);

            return $enrollment;
        });
    }

    public function settle(Payment $payment, string $reference): bool
    {
        return DB::transaction(function () use ($payment, $reference) {
            $enrollmentId = $payment->enrollment_id;
            $enrollment = Enrollment::whereKey($enrollmentId)->firstOrFail();
            $cohort = Cohort::whereKey($enrollment->cohort_id)->lockForUpdate()->firstOrFail();
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            $enrollment = Enrollment::whereKey($enrollmentId)->lockForUpdate()->firstOrFail();
            if (in_array($payment->status, ['paid', 'review_required'], true)) {
                return $payment->status === 'paid';
            }
            $alreadyPaid = Payment::where('enrollment_id', $enrollmentId)->where('status', 'paid')->exists();
            $active = Enrollment::where('cohort_id', $cohort->id)->where('id', '!=', $enrollment->id)->where(function ($q) {
                $q->where('status', 'active')->orWhere(fn ($q) => $q->where('status', 'pending_payment')->where('expires_at', '>', now()));
            })->count();
            $state = ($alreadyPaid || (int) $payment->amount !== (int) $enrollment->amount || ($enrollment->status !== 'active' && $active >= $cohort->capacity)) ? 'review_required' : 'paid';
            $payment->update(['status' => $state, 'reference' => $reference, 'paid_at' => now()]);
            if ($state === 'paid') {
                $enrollment->update(['status' => 'active', 'expires_at' => null]);
            }
            AuditEvent::create(['actor_id' => $payment->user_id, 'action' => 'payment.'.$state, 'subject_type' => 'payment', 'subject_id' => $payment->id]);

            return $state === 'paid';
        });
    }
}
