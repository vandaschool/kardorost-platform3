<?php

namespace App\Services;

use App\Models\AuditEvent;
use App\Models\Cohort;
use App\Models\CohortConsultationBenefit;
use App\Models\ConsultationGrant;
use App\Models\Enrollment;
use App\Models\EnrollmentConsultationBenefit;
use App\Models\User;
use App\Notifications\ConsultationChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class CourseConsultationBenefits
{
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['benefit' => $message]);
    }

    public function configure(User $admin, Cohort $cohort, array $data): void
    {
        abort_unless($admin->is_active && $admin->hasRole('admin'), 403);
        DB::transaction(function () use ($admin, $cohort, $data) {
            $cohort = Cohort::whereKey($cohort->id)->lockForUpdate()->firstOrFail();
            $benefit = CohortConsultationBenefit::where('cohort_id', $cohort->id)->lockForUpdate()->first();
            if (($benefit?->revision ?? 0) !== (int) $data['revision']) {
                $this->fail('تنظیمات تغییر کرده است؛ صفحه را تازه کنید.');
            }
            if ($data['enabled']) {
                $coach = User::findOrFail($data['coach_id']);
                if (! $coach->is_active || ! $coach->hasRole('coach') || $cohort->course->price <= 0) {
                    $this->fail('مربی فعال و دوره دارای قیمت مثبت لازم است.');
                }
                $fields = [...array_intersect_key($data, array_flip(['coach_id', 'title', 'sessions', 'session_minutes', 'valid_days', 'activation'])), 'enabled' => true, 'reviewed_by' => $admin->id, 'revision' => ($benefit?->revision ?? 0) + 1];
                if ($benefit) {
                    $benefit->update($fields);
                } else {
                    $benefit = CohortConsultationBenefit::create([...$fields, 'cohort_id' => $cohort->id]);
                }
            } elseif ($benefit) {
                $benefit->update(['enabled' => false, 'revision' => $benefit->revision + 1, 'reviewed_by' => $admin->id]);
            }
            AuditEvent::create(['actor_id' => $admin->id, 'action' => 'course_benefit.configured', 'subject_type' => 'cohort', 'subject_id' => $cohort->id, 'metadata' => ['revision' => $benefit?->revision ?? 0, 'enabled' => (bool) $data['enabled']]]);
        }, 3);
    }

    public function publicDefinition(CohortConsultationBenefit $benefit): array
    {
        return [...$benefit->only('title', 'sessions', 'session_minutes', 'valid_days', 'activation', 'revision', 'coach_id'), 'coach_name' => $benefit->coach->name];
    }

    public function forEnrollment(Enrollment $enrollment): ?array
    {
        $contract = EnrollmentConsultationBenefit::where('enrollment_id', $enrollment->id)->where('billing_version', $enrollment->billing_version)->first();

        return $contract ? $this->contractData($contract) : null;
    }

    public function contractData(EnrollmentConsultationBenefit $contract): array
    {
        $labels = ['waiting' => 'در انتظار پرداخت واجد شرایط', 'issued' => 'سهمیه صادر شده', 'blocked' => 'نیازمند رسیدگی مدیریت', 'superseded' => 'قرارداد جایگزین شده'];
        $reasons = ['coach_unavailable' => 'مربی فعال نیست', 'student_unavailable' => 'حساب دانشجو واجد شرایط نیست', 'same_participant' => 'دانشجو و مربی یکسان‌اند', 'source_conflict' => 'تعارض با منبع سهمیه قبلی'];

        return [...$contract->only('id', 'status', 'eligible_at', 'issued_at', 'consultation_grant_id'), 'status_label' => $labels[$contract->status] ?? $contract->status,
            'definition' => array_intersect_key($contract->definition, array_flip(['title', 'sessions', 'session_minutes', 'valid_days', 'activation', 'coach_name', 'course_title', 'cohort_title'])),
            'reason_label' => $contract->issue_reason ? ($reasons[$contract->issue_reason] ?? 'نیازمند بررسی') : null];
    }

    /** Called inside the cohort and enrollment transaction, for a newly issued quote only. */
    public function freeze(Enrollment $enrollment, Cohort $cohort): void
    {
        $this->supersede($enrollment);
        $benefit = CohortConsultationBenefit::where('cohort_id', $cohort->id)->where('enabled', true)->first();
        if (! $benefit || $enrollment->amount <= 0 || $enrollment->organization_license_id || $enrollment->payment_plan === 'organization') {
            return;
        }
        if (! $benefit->coach->is_active || ! $benefit->coach->hasRole('coach')) {
            $this->fail('مشاوره همراه این نوبت فعلاً قابل ارائه نیست؛ پیش از ثبت‌نام با مدیریت پیگیری کنید.');
        }
        $contract = EnrollmentConsultationBenefit::create(['enrollment_id' => $enrollment->id, 'billing_version' => $enrollment->billing_version, 'cohort_consultation_benefit_id' => $benefit->id, 'coach_id' => $benefit->coach_id, 'reviewed_by' => $benefit->reviewed_by, 'definition' => [...$this->publicDefinition($benefit), 'course_title' => $cohort->course->title, 'cohort_title' => $cohort->title, 'policy' => 'paid_personal_enrollment_v1', 'validity_basis' => 'grant_issue_date']]);
        AuditEvent::create(['actor_id' => $enrollment->user_id, 'action' => 'course_benefit.frozen', 'subject_type' => 'enrollment_consultation_benefit', 'subject_id' => $contract->id]);
    }

    public function supersede(Enrollment $enrollment): void
    {
        EnrollmentConsultationBenefit::where('enrollment_id', $enrollment->id)->where('billing_version', '!=', $enrollment->billing_version)->whereIn('status', ['waiting', 'blocked'])->update(['status' => 'superseded']);
    }

    private function qualifyingTime(Enrollment $enrollment, EnrollmentConsultationBenefit $contract): ?string
    {
        if ($enrollment->status !== 'active' || $enrollment->amount <= 0 || $enrollment->organization_license_id || $enrollment->payment_plan === 'organization') {
            return null;
        }
        $invoices = app(BillingService::class)->current($enrollment)->get();
        if ($invoices->isEmpty() || (int) $invoices->sum('amount') !== $enrollment->amount) {
            return null;
        }
        $paid = DB::table('installments as invoices')->join('payment_allocations as allocations', 'allocations.installment_id', '=', 'invoices.id')->join('payments', 'payments.id', '=', 'allocations.payment_id')
            ->where('invoices.enrollment_id', $enrollment->id)->where('invoices.billing_version', $enrollment->billing_version)->whereNull('invoices.voided_at')->whereNotNull('invoices.paid_at')
            ->where('payments.enrollment_id', $enrollment->id)->where('payments.user_id', $enrollment->user_id)->where('payments.status', 'paid')->whereNotNull('payments.captured_at')->whereNotNull('payments.reference')->whereNull('payments.consultation_id')->whereNull('payments.organization_invoice_id')
            ->whereColumn('payments.installment_id', 'invoices.id')->whereColumn('allocations.amount', 'invoices.amount')->whereColumn('payments.amount', 'invoices.amount')->get(['invoices.sequence', 'invoices.paid_at']);
        if ($contract->definition['activation'] === 'first_payment') {
            return $paid->firstWhere('sequence', 1)?->paid_at;
        }

        return $paid->count() === $invoices->count() ? $paid->max('paid_at') : null;
    }

    /** Caller holds cohort and enrollment locks; payment allocation is already recorded. */
    public function issueLocked(Enrollment $enrollment): ?EnrollmentConsultationBenefit
    {
        $contract = EnrollmentConsultationBenefit::where('enrollment_id', $enrollment->id)->where('billing_version', $enrollment->billing_version)->lockForUpdate()->first();
        if (! $contract || $contract->status === 'issued') {
            return $contract;
        }
        $eligibleAt = $this->qualifyingTime($enrollment, $contract);
        if (! $eligibleAt) {
            return $contract;
        }
        User::whereIn('id', [$enrollment->user_id, $contract->coach_id])->orderBy('id')->lockForUpdate()->get();
        $student = User::findOrFail($enrollment->user_id);
        $coach = User::findOrFail($contract->coach_id);
        $source = 'COURSE-BENEFIT-'.$contract->id;
        $reason = match (true) {
            ! $student->is_active || ! $student->phone_verified_at || ! $student->hasRole('student') => 'student_unavailable',
            ! $coach->is_active || ! $coach->hasRole('coach') => 'coach_unavailable',
            $student->id === $coach->id => 'same_participant',
            ConsultationGrant::where('student_id', $student->id)->where('source_reference', $source)->exists() => 'source_conflict',
            default => null,
        };
        $contract->eligible_at ??= $eligibleAt;
        if ($reason) {
            $changed = $contract->status !== 'blocked' || $contract->issue_reason !== $reason;
            $contract->fill(['status' => 'blocked', 'issue_reason' => $reason])->save();
            if ($changed) {
                AuditEvent::create(['action' => 'course_benefit.blocked', 'subject_type' => 'enrollment_consultation_benefit', 'subject_id' => $contract->id, 'metadata' => ['reason' => $reason]]);
                $student->notify(new ConsultationChanged('مشاوره همراه دوره نیازمند رسیدگی مدیریت است'));
            }

            return $contract;
        }
        $definition = $contract->definition;
        $grant = ConsultationGrant::create(['student_id' => $student->id, 'coach_id' => $coach->id, 'created_by' => $contract->reviewed_by, 'source_reference' => $source, 'title' => $definition['title'], 'total_sessions' => $definition['sessions'], 'session_minutes' => $definition['session_minutes'], 'valid_until' => now()->addDays($definition['valid_days'])->toDateString()]);
        $contract->fill(['status' => 'issued', 'issue_reason' => null, 'consultation_grant_id' => $grant->id, 'issued_at' => now()])->save();
        AuditEvent::create(['action' => 'course_benefit.issued', 'subject_type' => 'enrollment_consultation_benefit', 'subject_id' => $contract->id, 'metadata' => ['grant_id' => $grant->id]]);
        $student->notify(new ConsultationChanged('سهمیه مشاوره همراه دوره فعال شد'));

        return $contract;
    }

    public function retry(User $admin, EnrollmentConsultationBenefit $contract): EnrollmentConsultationBenefit
    {
        abort_unless($admin->is_active && $admin->hasRole('admin'), 403);

        return DB::transaction(function () use ($contract, $admin) {
            $enrollment = Enrollment::findOrFail($contract->enrollment_id);
            Cohort::whereKey($enrollment->cohort_id)->lockForUpdate()->firstOrFail();
            $enrollment = Enrollment::whereKey($enrollment->id)->lockForUpdate()->firstOrFail();
            if ($enrollment->billing_version !== $contract->billing_version) {
                $this->fail('قرارداد متعلق به نسخه قبلی ثبت‌نام است.');
            }

            $result = $this->issueLocked($enrollment);
            AuditEvent::create(['actor_id' => $admin->id, 'action' => 'course_benefit.retried', 'subject_type' => 'enrollment_consultation_benefit', 'subject_id' => $contract->id, 'metadata' => ['status' => $result->status]]);

            return $result;
        }, 3);
    }
}
