<?php

namespace App\Services;

use App\Models\Assignment;
use App\Models\Attendance;
use App\Models\AuditEvent;
use App\Models\CertificatePolicy;
use App\Models\CertificateRequest;
use App\Models\Cohort;
use App\Models\Course;
use App\Models\CourseCertificate;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\LiveSession;
use App\Models\Submission;
use App\Models\User;
use App\Notifications\CertificateChanged;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class Certification
{
    private function fail(string $message): never
    {
        throw ValidationException::withMessages(['certificate' => $message]);
    }

    private function audit(string $action, string $type, int $id, int $actor): void
    {
        AuditEvent::create(['action' => $action, 'subject_type' => $type, 'subject_id' => $id, 'actor_id' => $actor]);
    }

    /** Lock order matches billing parents, enrollment edits and submission review. */
    public function locked(Enrollment $enrollment): Enrollment
    {
        Course::whereKey($enrollment->course_id)->lockForUpdate()->firstOrFail();
        Cohort::whereKey($enrollment->cohort_id)->lockForUpdate()->firstOrFail();

        return Enrollment::whereKey($enrollment->id)->lockForUpdate()->firstOrFail();
    }

    public function configure(User $admin, Cohort $cohort, array $data): void
    {
        abort_unless($admin->is_active && $admin->hasRole('admin'), 403);
        DB::transaction(function () use ($admin, $cohort, $data) {
            Course::whereKey($cohort->course_id)->lockForUpdate()->firstOrFail();
            $cohort = Cohort::whereKey($cohort->id)->lockForUpdate()->firstOrFail();
            $policy = CertificatePolicy::where('cohort_id', $cohort->id)->lockForUpdate()->first();
            if (($policy?->revision ?? 0) !== (int) $data['revision']) {
                $this->fail('شرایط صدور تغییر کرده است؛ صفحه را تازه کنید.');
            }
            if (! $data['enabled']) {
                $policy?->update(['enabled' => false, 'revision' => $policy->revision + 1, 'reviewed_by' => $admin->id]);
            } else {
                $lessons = Lesson::whereIn('id', $data['lesson_ids'])->whereHas('section', fn ($q) => $q->where('course_id', $cohort->course_id))->where('is_published', true)->orderBy('id')->get(['id', 'title']);
                $sessions = LiveSession::whereIn('id', $data['session_ids'])->where('cohort_id', $cohort->id)->where('is_published', true)->orderBy('id')->get(['id', 'title']);
                $assignments = Assignment::whereIn('id', $data['assignment_ids'])->where('cohort_id', $cohort->id)->where('is_published', true)->orderBy('id')->get(['id', 'title', 'max_score']);
                if ($lessons->count() !== count($data['lesson_ids']) || $sessions->count() !== count($data['session_ids']) || $assignments->count() !== count($data['assignment_ids']) || $lessons->count() + $sessions->count() + $assignments->count() === 0) {
                    $this->fail('حداقل یک معیار آموزشی منتشرشده متعلق به همین دوره و نوبت انتخاب کنید.');
                }
                $definition = ['title' => $data['title'], 'issuer' => $data['issuer'], 'lessons' => $lessons->toArray(), 'sessions' => $sessions->toArray(), 'assignments' => $assignments->toArray(), 'attendance_percent' => (int) $data['attendance_percent'], 'score_percent' => (int) $data['score_percent'], 'require_settled' => (bool) $data['require_settled'], 'policy' => 'selected_evidence_manual_review_v1', 'policy_revision' => ($policy?->revision ?? 0) + 1];
                $fields = ['cohort_id' => $cohort->id, 'reviewed_by' => $admin->id, 'definition' => $definition, 'enabled' => true, 'revision' => $definition['policy_revision']];
                if ($policy) {
                    $policy->update($fields);
                } else {
                    $policy = CertificatePolicy::create($fields);
                }
            }
            $this->audit('certificate_policy.configured', 'cohort', $cohort->id, $admin->id);
        }, 3);
    }

    public function evidence(Enrollment $enrollment, array $definition): array
    {
        $checks = [];
        $student = User::findOrFail($enrollment->user_id);
        $checks[] = ['label' => 'ثبت‌نام فعال و حساب تأییدشده', 'passed' => $enrollment->status === 'active' && $student->is_active && (bool) $student->phone_verified_at && $student->hasRole('student')];
        foreach ($definition['lessons'] as $item) {
            $lesson = Lesson::whereKey($item['id'])->whereHas('section', fn ($q) => $q->where('course_id', $enrollment->course_id))->first();
            $checks[] = ['label' => 'تکمیل درس: '.$item['title'], 'passed' => (bool) ($lesson?->available() && LessonProgress::where('enrollment_id', $enrollment->id)->where('lesson_id', $item['id'])->whereNotNull('completed_at')->exists())];
        }
        $present = 0;
        $sessionsReady = true;
        foreach ($definition['sessions'] as $item) {
            $session = LiveSession::whereKey($item['id'])->where('cohort_id', $enrollment->cohort_id)->first();
            $sessionsReady = $sessionsReady && $session && $session->is_published && $session->ends_at->lte(now());
            $present += Attendance::where('enrollment_id', $enrollment->id)->where('live_session_id', $item['id'])->where('status', 'present')->exists() ? 1 : 0;
        }
        if (count($definition['sessions'])) {
            $checks[] = ['label' => 'پایان جلسات منتخب و حداقل '.$definition['attendance_percent'].'٪ حضور ثبت‌شده ('.$present.' از '.count($definition['sessions']).')', 'passed' => (bool) $sessionsReady && $present * 100 >= count($definition['sessions']) * $definition['attendance_percent']];
        }
        foreach ($definition['assignments'] as $item) {
            $assignment = Assignment::whereKey($item['id'])->where('cohort_id', $enrollment->cohort_id)->first();
            $submission = Submission::where('enrollment_id', $enrollment->id)->where('assignment_id', $item['id'])->orderByDesc('version')->first();
            $checks[] = ['label' => 'آخرین پاسخ تمرین: '.$item['title'].'، حداقل '.$definition['score_percent'].'٪', 'passed' => (bool) ($assignment?->available() && $assignment->max_score === $item['max_score'] && $submission?->status === 'reviewed' && $submission->reviewed_at && $submission->score !== null && $submission->score * 100 >= $item['max_score'] * $definition['score_percent']), 'submission_id' => $submission?->id, 'review_version' => $submission?->review_version];
        }
        if ($definition['require_settled']) {
            $invoices = app(BillingService::class)->current($enrollment)->get();
            $paid = DB::table('installments as invoices')->join('payment_allocations as allocations', 'allocations.installment_id', '=', 'invoices.id')->join('payments', 'payments.id', '=', 'allocations.payment_id')
                ->where('invoices.enrollment_id', $enrollment->id)->where('invoices.billing_version', $enrollment->billing_version)->whereNull('invoices.voided_at')->whereNotNull('invoices.paid_at')->where('payments.status', 'paid')->where('payments.enrollment_id', $enrollment->id)->where('payments.user_id', $enrollment->user_id)
                ->whereNotNull('payments.captured_at')->whereNotNull('payments.reference')->whereNull('payments.consultation_id')->whereNull('payments.organization_invoice_id')->whereNull('payments.package_purchase_id')->whereColumn('payments.installment_id', 'invoices.id')->whereColumn('payments.amount', 'invoices.amount')->whereColumn('allocations.amount', 'invoices.amount')->sum('allocations.amount');
            $checks[] = ['label' => 'تسویه شهریه شخصی ثبت‌نام', 'passed' => $enrollment->amount === 0 || ($invoices->isNotEmpty() && (int) $invoices->sum('amount') === $enrollment->amount && (int) $paid === $enrollment->amount)];
        }

        return ['eligible' => ! collect($checks)->contains(fn ($check) => ! $check['passed']), 'checks' => $checks, 'evaluated_at' => now()->toIso8601String(), 'basis' => 'selected_evidence_manual_review_v1'];
    }

    public function request(User $student, Enrollment $enrollment, int $revision, string $expectedName): CertificateRequest
    {
        abort_unless($student->id === $enrollment->user_id && $student->is_active && $student->hasRole('student'), 403);

        return DB::transaction(function () use ($student, $enrollment, $revision, $expectedName) {
            $enrollment = $this->locked($enrollment);
            if (CourseCertificate::where('enrollment_id', $enrollment->id)->exists()) {
                $this->fail('برای این ثبت‌نام گواهی ثبت شده است؛ وضعیت آن را در سوابق ببینید.');
            }
            $pending = CertificateRequest::where('enrollment_id', $enrollment->id)->where('status', 'pending')->first();
            if ($pending) {
                return $pending;
            }
            $policy = CertificatePolicy::where('cohort_id', $enrollment->cohort_id)->where('enabled', true)->first();
            if (! $policy || $policy->revision !== $revision) {
                $this->fail('شرایط درخواست تغییر کرده یا فعال نیست؛ صفحه را تازه کنید.');
            }
            $student = User::whereKey($student->id)->lockForUpdate()->firstOrFail();
            if (trim($student->name) !== trim($expectedName) || trim($student->name) === '') {
                $this->fail('نام حساب تغییر کرده است؛ صفحه را تازه کنید و نام گواهی را بررسی کنید.');
            }
            if (! $this->evidence($enrollment, $policy->definition)['eligible']) {
                $this->fail('همه معیارهای درخواست هنوز تکمیل نشده‌اند.');
            }
            $request = CertificateRequest::create(['enrollment_id' => $enrollment->id, 'recipient_name' => trim($student->name), 'definition' => $policy->definition, 'public_consent_at' => now()]);
            $this->audit('certificate.requested', 'certificate_request', $request->id, $student->id);

            return $request;
        }, 3);
    }

    public function review(User $admin, CertificateRequest $request, int $revision, string $action, string $note): ?CourseCertificate
    {
        abort_unless($admin->is_active && $admin->hasRole('admin'), 403);

        return DB::transaction(function () use ($admin, $request, $revision, $action, $note) {
            $enrollment = $this->locked($request->enrollment);
            $request = CertificateRequest::whereKey($request->id)->lockForUpdate()->firstOrFail();
            if ($action === 'issue' && $request->status === 'issued') {
                return CourseCertificate::where('certificate_request_id', $request->id)->firstOrFail();
            }
            if ($request->status !== 'pending' || $request->revision !== $revision) {
                $this->fail('درخواست قبلاً بررسی شده یا نسخه فرم قدیمی است.');
            }
            if ($action === 'reject') {
                $request->update(['status' => 'rejected', 'reviewed_by' => $admin->id, 'reviewed_at' => now(), 'review_note' => $note, 'revision' => $request->revision + 1]);
                $this->audit('certificate.rejected', 'certificate_request', $request->id, $admin->id);
                $enrollment->user->notify(new CertificateChanged('درخواست گواهی نیازمند اصلاح است', $enrollment->id));

                return null;
            }
            if ($action !== 'issue') {
                $this->fail('تصمیم معتبر نیست.');
            }
            User::whereKey($enrollment->user_id)->lockForUpdate()->firstOrFail();
            $evidence = $this->evidence($enrollment, $request->definition);
            if (! $evidence['eligible'] || CourseCertificate::where('enrollment_id', $enrollment->id)->exists()) {
                $this->fail('معیارهای همین درخواست برقرار نیست یا گواهی از قبل ثبت شده است.');
            }
            $certificate = CourseCertificate::create(['enrollment_id' => $enrollment->id, 'certificate_request_id' => $request->id, 'serial' => 'KD-'.now()->format('Y').'-'.strtoupper(bin2hex(random_bytes(8))), 'verification_token' => Str::random(48),
                'definition' => ['recipient_name' => $request->recipient_name, 'course_title' => $enrollment->course->title, 'cohort_title' => $enrollment->cohort->title, 'title' => $request->definition['title'], 'issuer' => $request->definition['issuer'], 'policy' => $request->definition], 'evidence' => $evidence, 'issued_by' => $admin->id, 'issued_at' => now()]);
            $request->update(['status' => 'issued', 'reviewed_by' => $admin->id, 'reviewed_at' => now(), 'review_note' => $note, 'revision' => $request->revision + 1]);
            $this->audit('certificate.issued', 'course_certificate', $certificate->id, $admin->id);
            $enrollment->user->notify(new CertificateChanged('گواهی پایان دوره صادر شد', $enrollment->id));

            return $certificate;
        }, 3);
    }

    public function revoke(User $admin, CourseCertificate $certificate, int $revision, string $reason): void
    {
        abort_unless($admin->is_active && $admin->hasRole('admin'), 403);
        DB::transaction(function () use ($admin, $certificate, $revision, $reason) {
            $this->locked($certificate->enrollment);
            $certificate = CourseCertificate::whereKey($certificate->id)->lockForUpdate()->firstOrFail();
            if ($certificate->status !== 'issued' || $certificate->revision !== $revision) {
                $this->fail('گواهی قبلاً باطل شده یا نسخه فرم قدیمی است.');
            }
            $certificate->update(['status' => 'revoked', 'revision' => $certificate->revision + 1, 'revoked_by' => $admin->id, 'revoked_at' => now(), 'revocation_reason' => $reason]);
            $this->audit('certificate.revoked', 'course_certificate', $certificate->id, $admin->id);
            $certificate->enrollment->user->notify(new CertificateChanged('وضعیت گواهی پایان دوره تغییر کرد', $certificate->enrollment_id));
        }, 3);
    }

    public function status(Enrollment $enrollment): string
    {
        return CourseCertificate::where('enrollment_id', $enrollment->id)->value('status')
            ?? CertificateRequest::where('enrollment_id', $enrollment->id)->latest('id')->value('status')
            ?? 'none';
    }

    public function publicData(CourseCertificate $certificate): array
    {
        return [...$certificate->only('serial', 'status', 'issued_at', 'revoked_at'), ...array_intersect_key($certificate->definition, array_flip(['recipient_name', 'title', 'issuer', 'course_title', 'cohort_title']))];
    }
}
