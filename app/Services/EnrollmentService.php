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
    public function reserve(User $user, Cohort $cohort, string $plan = 'full'): Enrollment
    {
        return DB::transaction(function () use ($user, $cohort, $plan) {
            $cohort = Cohort::whereKey($cohort->id)->lockForUpdate()->firstOrFail();
            $course = Course::whereKey($cohort->course_id)->firstOrFail();
            if (! $cohort->registration_open || ! $course->is_published) {
                throw ValidationException::withMessages(['cohort' => 'ثبت‌نام این دوره باز نیست.']);
            }
            if (! in_array($plan, ['full', 'installments'], true)) {
                throw ValidationException::withMessages(['payment_plan' => 'روش پرداخت معتبر نیست.']);
            }
            $existing = Enrollment::where('user_id', $user->id)->where('cohort_id', $cohort->id)->lockForUpdate()->first();
            if ($existing && $existing->status === 'active') {
                return $existing;
            }
            if ($existing && $existing->status === 'pending_payment' && $existing->expires_at?->isFuture()) {
                if ($existing->payment_plan !== $plan) {
                    throw ValidationException::withMessages(['payment_plan' => 'روش پرداخت این رزرو ثبت شده است. پس از پایان رزرو می‌توانید روش تازه انتخاب کنید.']);
                }

                return $existing;
            }
            if ($existing && Payment::where('enrollment_id', $existing->id)->where('status', '!=', 'refunded')->where(fn ($q) => $q->whereNotNull('captured_at')->orWhere('status', 'pending_review'))->exists()) {
                throw ValidationException::withMessages(['payment' => 'برای این ثبت‌نام پرداخت یا رسید در انتظار تعیین تکلیف وجود دارد. با مدیریت پیگیری کنید.']);
            }
            $occupied = Enrollment::where('cohort_id', $cohort->id)->where(fn ($q) => $q->where('status', 'active')->orWhere(fn ($q) => $q->where('status', 'pending_payment')->where('expires_at', '>', now())))->count();
            if ($occupied >= $cohort->capacity) {
                throw ValidationException::withMessages(['cohort' => 'ظرفیت این نوبت تکمیل شده است.']);
            }
            $attributes = ['amount' => $course->price, 'user_id' => $user->id, 'course_id' => $course->id, 'cohort_id' => $cohort->id, 'payment_plan' => $course->price === 0 ? 'full' : $plan, 'billing_version' => $existing ? $existing->billing_version + 1 : 1, 'status' => $course->price === 0 ? 'active' : 'pending_payment', 'expires_at' => $course->price === 0 ? null : now()->addMinutes(15)];
            if ($existing) {
                Payment::where('enrollment_id', $existing->id)->where('status', 'pending')->update(['status' => 'expired']);
                $existing->installments()->whereNull('paid_at')->whereNull('voided_at')->update(['voided_at' => now()]);
                $existing->update($attributes);
                $enrollment = $existing;
            } else {
                $enrollment = Enrollment::create($attributes);
            }
            app(BillingService::class)->issue($enrollment, $course, $cohort, $attributes['payment_plan']);
            AuditEvent::create(['actor_id' => $user->id, 'action' => 'enrollment.created', 'subject_type' => 'enrollment', 'subject_id' => $enrollment->id]);

            return $enrollment;
        });
    }

    public function settle(Payment $payment, string $reference): bool
    {
        return app(BillingService::class)->capture($payment, $reference);
    }
}
