<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Cohort;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Installment;
use App\Models\Payment;
use App\Models\PaymentAllocation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class BillingService
{
    public function issue(Enrollment $enrollment, Course $course, Cohort $cohort, string $plan): void
    {
        if ($enrollment->amount === 0) {
            return;
        }
        if ($plan === 'installments') {
            $parts = $cohort->installmentPlan()->get();
            if (! $cohort->installment_enabled || $parts->count() < 2 || (int) $parts->sum('amount') !== $enrollment->amount) {
                throw ValidationException::withMessages(['payment_plan' => 'برنامه اقساط این نوبت معتبر یا فعال نیست.']);
            }
            foreach ($parts as $part) {
                Installment::create(['enrollment_id' => $enrollment->id, 'billing_version' => $enrollment->billing_version, 'sequence' => $part->sequence, 'amount' => $part->amount, 'due_date' => $part->sequence === 1 ? now()->toDateString() : $part->due_date]);
            }
        } else {
            Installment::create(['enrollment_id' => $enrollment->id, 'billing_version' => $enrollment->billing_version, 'sequence' => 1, 'amount' => $enrollment->amount, 'due_date' => now()->toDateString()]);
        }
    }

    public function current(Enrollment $enrollment): Builder
    {
        return Installment::where('enrollment_id', $enrollment->id)->where('billing_version', $enrollment->billing_version)->whereNull('voided_at');
    }

    public function locked(Installment $installment): array
    {
        $enrollment = Enrollment::findOrFail($installment->enrollment_id);
        $cohort = Cohort::whereKey($enrollment->cohort_id)->lockForUpdate()->firstOrFail();
        $enrollment = Enrollment::whereKey($enrollment->id)->lockForUpdate()->firstOrFail();
        $installment = Installment::whereKey($installment->id)->lockForUpdate()->firstOrFail();

        return [$cohort, $enrollment, $installment];
    }

    public function payable(User $user, Enrollment $enrollment, Installment $installment): void
    {
        abort_unless($user->is_active && $installment->enrollment_id === $enrollment->id && $enrollment->user_id === $user->id, 403);
        if ($installment->voided_at || $installment->billing_version !== $enrollment->billing_version) {
            throw ValidationException::withMessages(['payment' => 'این صورتحساب دیگر معتبر نیست. وضعیت جدید ثبت‌نام را ببینید.']);
        }
        if ($installment->paid_at || $installment->allocation()->exists()) {
            throw ValidationException::withMessages(['payment' => 'این مبلغ قبلاً پرداخت شده است.']);
        }
        if (! in_array($enrollment->status, ['pending_payment', 'active'], true)) {
            throw ValidationException::withMessages(['payment' => 'وضعیت ثبت‌نام اجازه پرداخت نمی‌دهد.']);
        }
        if ($enrollment->status !== 'active' && (! $enrollment->expires_at?->isFuture() || $installment->sequence !== 1)) {
            throw ValidationException::withMessages(['payment' => 'ابتدا ثبت‌نام و پرداخت اول را در مهلت رزرو تکمیل کنید.']);
        }
        if (Payment::where('installment_id', $installment->id)->where('status', 'review_required')->exists()) {
            throw ValidationException::withMessages(['payment' => 'یک پرداخت این صورتحساب نیازمند بررسی مدیریت است؛ دوباره پرداخت نکنید.']);
        }
    }

    public function attempt(User $user, Installment $installment): Payment
    {
        return DB::transaction(function () use ($user, $installment) {
            [$cohort,$enrollment,$installment] = $this->locked($installment);
            $this->payable($user, $enrollment, $installment);
            if (Payment::where('installment_id', $installment->id)->where('status', 'pending_review')->exists()) {
                throw ValidationException::withMessages(['payment' => 'رسید این صورتحساب در حال بررسی است.']);
            }
            $pending = Payment::where('installment_id', $installment->id)->where('method', 'gateway')->where('status', 'pending')->orderByDesc('id')->first();
            if ($pending && $pending->attempt_expires_at?->isFuture()) {
                return $pending;
            }
            Payment::where('installment_id', $installment->id)->where('status', 'pending')->update(['status' => 'expired']);
            $expires = now()->addMinutes(15);
            if ($enrollment->status !== 'active' && $enrollment->expires_at->lt($expires)) {
                $expires = $enrollment->expires_at;
            }

            return Payment::create(['user_id' => $user->id, 'enrollment_id' => $enrollment->id, 'installment_id' => $installment->id, 'method' => 'gateway', 'amount' => $installment->amount, 'status' => 'pending', 'attempt_expires_at' => $expires]);
        });
    }

    public function capture(Payment $payment, string $reference): bool
    {
        abort_if($payment->package_purchase_id || $payment->consultation_id || $payment->organization_invoice_id, 422);
        try {
            return DB::transaction(function () use ($payment, $reference) {
                $e = Enrollment::findOrFail($payment->enrollment_id);
                $cohort = Cohort::whereKey($e->cohort_id)->lockForUpdate()->firstOrFail();
                $e = Enrollment::whereKey($e->id)->lockForUpdate()->firstOrFail();
                $invoice = $payment->installment_id ? Installment::whereKey($payment->installment_id)->lockForUpdate()->first() : null;
                $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
                if ($payment->status === 'paid') {
                    return true;
                }
                if (in_array($payment->status, ['review_required', 'refunded'], true)) {
                    return false;
                }
                if ($payment->method === 'manual' && $payment->receipt?->status !== 'approved') {
                    throw ValidationException::withMessages(['payment' => 'رسید باید ابتدا توسط مدیریت تأیید شود.']);
                }
                if (! $invoice && $e->payment_plan === 'full') {
                    $invoice = $this->current($e)->where('sequence', 1)->lockForUpdate()->first();
                    if ($invoice) {
                        $payment->installment_id = $invoice->id;
                    }
                }
                if (Payment::where('reference', $reference)->where('id', '!=', $payment->id)->exists()) {
                    $payment->update(['status' => 'review_required', 'review_reason' => 'reference_conflict']);
                    $this->audit($payment, 'payment.reference_conflict');

                    return false;
                }
                $payment->forceFill(['reference' => $reference, 'captured_at' => now(), 'paid_at' => now()])->save();
                $reason = $this->allocationReason($e, $cohort, $invoice, $payment);
                if ($reason) {
                    $payment->update(['status' => 'review_required', 'review_reason' => $reason]);
                } else {
                    $this->allocate($e, $invoice, $payment);
                }
                $this->audit($payment, 'payment.'.$payment->status);

                return $payment->status === 'paid';
            });
        } catch (UniqueConstraintViolationException $exception) {
            if (! Payment::where('reference', $reference)->where('id', '!=', $payment->id)->exists()) {
                throw $exception;
            }

            return $this->capture($payment, $reference);
        }
    }

    public function resolve(Payment $payment, User $admin): bool
    {
        abort_unless($admin->is_active && $admin->hasRole('admin'), 403);
        if ($payment->package_purchase_id || $payment->consultation_id || $payment->organization_invoice_id) {
            throw ValidationException::withMessages(['payment' => 'پرداخت مشاوره باید با وضعیت جلسه و بازپرداخت آن بررسی شود؛ تخصیص به دوره مجاز نیست.']);
        }

        return DB::transaction(function () use ($payment, $admin) {
            $e = Enrollment::findOrFail($payment->enrollment_id);
            $cohort = Cohort::whereKey($e->cohort_id)->lockForUpdate()->firstOrFail();
            $e = Enrollment::whereKey($e->id)->lockForUpdate()->firstOrFail();
            $invoice = $payment->installment_id ? Installment::whereKey($payment->installment_id)->lockForUpdate()->first() : null;
            $payment = Payment::whereKey($payment->id)->lockForUpdate()->firstOrFail();
            if ($payment->refund()->where('status', '!=', 'rejected')->exists()) {
                throw ValidationException::withMessages(['payment' => 'این پرداخت در فرایند بازپرداخت است و قابل تخصیص نیست.']);
            }
            if ($payment->status === 'paid') {
                return true;
            }
            if ($payment->status !== 'review_required' || ! $payment->captured_at || ! $payment->reference) {
                throw ValidationException::withMessages(['payment' => 'دریافت این پرداخت تأیید نشده است و قابل تخصیص نیست.']);
            }
            $reason = $this->allocationReason($e, $cohort, $invoice, $payment);
            if ($reason) {
                throw ValidationException::withMessages(['payment' => 'این پرداخت هنوز قابل تخصیص نیست: '.$this->reasonLabel($reason)]);
            }
            $this->allocate($e, $invoice, $payment);
            $this->audit($payment, 'payment.allocated_after_review', $admin->id);

            return true;
        });
    }

    private function allocationReason(Enrollment $enrollment, Cohort $cohort, ?Installment $invoice, Payment $payment): ?string
    {
        if (! $invoice || $invoice->enrollment_id !== $enrollment->id || $invoice->voided_at || $invoice->billing_version !== $enrollment->billing_version) {
            return 'obsolete_quote';
        }
        if ($invoice->paid_at || $invoice->allocation()->exists()) {
            return 'duplicate_charge';
        }
        if ($payment->amount !== $invoice->amount) {
            return 'amount_mismatch';
        }
        if (! in_array($enrollment->status, ['active', 'pending_payment'], true)) {
            return 'inactive_enrollment';
        }
        if ($enrollment->status !== 'active') {
            if ($invoice->sequence !== 1) {
                return 'first_installment_unpaid';
            }
            if (app(CohortCapacity::class)->available($cohort, $enrollment->id) < 1) {
                return 'capacity_unavailable';
            }
        }

        return null;
    }

    private function allocate(Enrollment $enrollment, Installment $installment, Payment $payment): void
    {
        PaymentAllocation::create(['installment_id' => $installment->id, 'payment_id' => $payment->id, 'amount' => $payment->amount]);
        $installment->update(['paid_at' => $payment->captured_at]);
        $payment->update(['status' => 'paid', 'review_reason' => null]);
        if ($enrollment->status !== 'active') {
            $enrollment->update(['status' => 'active', 'expires_at' => null]);
        }
        app(CourseConsultationBenefits::class)->issueLocked($enrollment);
    }

    private function audit(Payment $payment, string $action, ?int $actor = null): void
    {
        AuditEvent::create(['actor_id' => $actor ?? $payment->user_id, 'action' => $action, 'subject_type' => 'payment', 'subject_id' => $payment->id, 'metadata' => ['installment_id' => $payment->installment_id, 'amount' => $payment->amount, 'reason' => $payment->review_reason]]);
    }

    public function reasonLabel(?string $reason): string
    {
        return match ($reason) {
            'package_amount_mismatch' => 'مبلغ یا صاحب پرداخت با سفارش پکیج تطبیق ندارد',
            'package_duplicate' => 'وجه اضافه برای سفارش پکیج پرداخت‌شده؛ بررسی بازپرداخت لازم است',
            'package_expired' => 'وجه پس از انقضای سفارش پکیج؛ بررسی انجام سفارش یا بازپرداخت لازم است',
            'package_account_unavailable' => 'حساب دانشجو یا مربی پکیج واجد شرایط نیست',
            'package_source_conflict' => 'تعارض منبع سهمیه پکیج؛ رسیدگی مدیریت لازم است',
            'organization_invoice_mismatch' => 'مبلغ پرداخت با صورتحساب سازمان تطبیق ندارد',
            'organization_invoice_excess' => 'وجه دریافت‌شده از مانده صورتحساب سازمان بیشتر است؛ بررسی مدیریت لازم است',
            'organization_invoice_duplicate' => 'دریافتی اضافه برای صورتحساب تسویه‌شده سازمان',
            'organization_invoice_void' => 'وجه صورتحساب ابطال‌شده؛ رسیدگی مدیریت لازم است',
            'consultation_amount_mismatch' => 'مبلغ یا صاحب پرداخت با رزرو مشاوره تطبیق ندارد',
            'consultation_package_payment' => 'این جلسه با سهمیه پکیج پوشش داده شده؛ وجه جداگانه نیازمند بررسی است',
            'consultation_duplicate' => 'پرداخت اضافه برای مشاوره؛ بررسی بازپرداخت لازم است',
            'consultation_expired' => 'پرداخت پس از پایان یا لغو رزرو مشاوره؛ بررسی بازپرداخت لازم است',
            'reference_conflict' => 'شناسه تراکنش تکراری یا متناقض','obsolete_quote' => 'صورتحساب قدیمی یا باطل‌شده','duplicate_charge' => 'صورتحساب قبلاً تسویه شده','amount_mismatch' => 'مغایرت مبلغ','inactive_enrollment' => 'ثبت‌نام غیرفعال','first_installment_unpaid' => 'قسط اول پرداخت نشده','capacity_unavailable' => 'ظرفیت نوبت تکمیل شده',default => 'نیازمند بررسی'
        };
    }

    public function summary(Enrollment $enrollment): array
    {
        $invoices = $this->current($enrollment)->get();

        return ['total' => $enrollment->amount, 'paid' => (int) $invoices->whereNotNull('paid_at')->sum('amount'), 'remaining' => (int) $invoices->whereNull('paid_at')->sum('amount'), 'overdue' => $enrollment->status === 'active' ? (int) $invoices->filter(fn ($i) => $i->overdue())->sum('amount') : 0];
    }
}
