<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Models\CertificatePolicy;
use App\Models\CertificateRequest;
use App\Models\CertificateVisibilityEvent;
use App\Models\Cohort;
use App\Models\CourseCertificate;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LiveSession;
use App\Services\Certification;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Inertia\Inertia;
use Inertia\Response;

class CertificateController extends Controller
{
    private function certificateData(?CourseCertificate $certificate, Certification $service, bool $admin = false): ?array
    {
        return $certificate ? [...$service->publicData($certificate), 'id' => $certificate->id, 'revision' => $certificate->revision, 'public_enabled' => $certificate->public_enabled, 'public_consent_at' => $certificate->public_consent_at, 'public_withdrawn_at' => $certificate->public_withdrawn_at, 'verification_url' => route('certificates.verify', $certificate->verification_token), ...($admin ? ['revocation_reason' => $certificate->revocation_reason] : [])] : null;
    }

    public function index(Request $request, Certification $service): Response
    {
        $enrollments = Enrollment::where('user_id', $request->user()->id)->where(fn ($query) => $query->where('status', 'active')->orWhereHas('certificate'))->with('course:id,title', 'cohort:id,title')->latest()->paginate(12)->through(fn ($enrollment) => ['id' => $enrollment->id, 'course_title' => $enrollment->course->title, 'cohort_title' => $enrollment->cohort->title, 'certificate' => $this->certificateData(CourseCertificate::where('enrollment_id', $enrollment->id)->first(), $service), 'enabled' => CertificatePolicy::where('cohort_id', $enrollment->cohort_id)->where('enabled', true)->exists()]);

        return Inertia::render('Certificates', ['enrollments' => $enrollments]);
    }

    public function enrollment(Request $request, Enrollment $enrollment, Certification $service): Response
    {
        $admin = $request->user()->hasRole('admin');
        abort_unless($admin || $enrollment->user_id === $request->user()->id, 403);
        $policy = CertificatePolicy::where('cohort_id', $enrollment->cohort_id)->first();
        $requests = CertificateRequest::where('enrollment_id', $enrollment->id)->latest('id')->get();
        $issued = CourseCertificate::where('enrollment_id', $enrollment->id)->first();
        $definition = $requests->firstWhere('status', 'pending')?->definition ?? ($policy?->enabled ? $policy->definition : null);

        return Inertia::render('EnrollmentCertificate', ['admin' => $admin, 'enrollment' => $enrollment->only('id', 'status'), 'course_title' => $enrollment->course->title, 'cohort_title' => $enrollment->cohort->title, 'recipient_name' => $enrollment->user->name,
            'can_manage_visibility' => $issued && $enrollment->user_id === $request->user()->id, 'visibility_events' => $issued ? CertificateVisibilityEvent::where('course_certificate_id', $issued->id)->latest('id')->limit(20)->get(['enabled', 'revision', 'consented_at', 'created_at']) : [], 'can_request' => ! $admin && $request->user()->hasRole('student') && $enrollment->status === 'active', 'policy' => $policy?->enabled ? ['definition' => $policy->definition, 'revision' => $policy->revision] : null, 'definition' => $definition, 'evidence' => $definition ? $service->evidence($enrollment, $definition) : null,
            'requests' => $requests->map(fn ($row) => [...$row->only('id', 'revision', 'status', 'recipient_name', 'created_at', 'review_note', 'reviewed_at', 'withdrawn_at'), 'can_withdraw' => $row->status === 'pending' && $enrollment->user_id === $request->user()->id]), 'certificate' => $this->certificateData($issued, $service, $admin)]);
    }

    public function withdraw(Request $request, CertificateRequest $application, Certification $service): RedirectResponse
    {
        $data = $request->validate(['revision' => 'required|integer|min:1']);
        $service->withdraw($request->user(), $application, $data['revision']);

        return back()->with('success', 'انصراف از درخواست ثبت شد؛ درخواست تازه به تأیید دوباره نام و نمایش عمومی نیاز دارد.');
    }

    public function configure(Request $request, Cohort $cohort, Certification $service): RedirectResponse
    {
        $data = $request->validate(['revision' => 'required|integer|min:0', 'enabled' => 'required|boolean',
            'title' => 'exclude_unless:enabled,true|required|string|max:160', 'issuer' => 'exclude_unless:enabled,true|required|string|max:160',
            'lesson_ids' => 'exclude_unless:enabled,true|present|array|max:100', 'lesson_ids.*' => 'required|integer|distinct', 'session_ids' => 'exclude_unless:enabled,true|present|array|max:100', 'session_ids.*' => 'required|integer|distinct', 'assignment_ids' => 'exclude_unless:enabled,true|present|array|max:100', 'assignment_ids.*' => 'required|integer|distinct',
            'attendance_percent' => 'exclude_unless:enabled,true|required|integer|between:1,100', 'score_percent' => 'exclude_unless:enabled,true|required|integer|between:1,100', 'require_settled' => 'exclude_unless:enabled,true|required|boolean', 'reviewed' => 'exclude_unless:enabled,true|required|accepted']);
        $service->configure($request->user(), $cohort, $data);

        return back()->with('success', 'شرایط درخواست‌های بعدی ذخیره شد؛ درخواست‌های قبلی معیارهای ثبت‌شده خود را دارند.');
    }

    public function policies(): Response
    {
        $cohorts = Cohort::with('course:id,title')->latest()->paginate(10)->through(fn ($cohort) => ['id' => $cohort->id, 'title' => $cohort->title, 'course_title' => $cohort->course->title, 'policy' => CertificatePolicy::where('cohort_id', $cohort->id)->first(),
            'lessons' => Lesson::whereHas('section', fn ($query) => $query->where('course_id', $cohort->course_id))->where('is_published', true)->get(['id', 'title']), 'sessions' => LiveSession::where('cohort_id', $cohort->id)->where('is_published', true)->get(['id', 'title']), 'assignments' => Assignment::where('cohort_id', $cohort->id)->where('is_published', true)->get(['id', 'title'])]);

        return Inertia::render('CertificatePolicies', ['cohorts' => $cohorts]);
    }

    public function admin(Request $request, Certification $service): Response
    {
        $data = $request->validate(['status' => 'nullable|in:all,pending,rejected,issued,withdrawn']);
        $status = $data['status'] ?? 'pending';
        $requests = CertificateRequest::with('enrollment.user', 'enrollment.course', 'enrollment.cohort')->when($status !== 'all', fn ($query) => $query->where('status', $status))->latest()->paginate(12)->withQueryString()->through(fn ($row) => [...$row->only('id', 'revision', 'recipient_name', 'definition', 'status', 'created_at', 'review_note', 'withdrawn_at'), 'student' => $row->enrollment->user->only('name', 'phone'), 'course_title' => $row->enrollment->course->title, 'cohort_title' => $row->enrollment->cohort->title, 'evidence' => $row->status === 'pending' ? $service->evidence($row->enrollment, $row->definition) : null, 'certificate' => $this->certificateData(CourseCertificate::where('certificate_request_id', $row->id)->first(), $service, true)]);

        return Inertia::render('AdminCertificates', ['requests' => $requests, 'status' => $status]);
    }

    public function apply(Request $request, Enrollment $enrollment, Certification $service): RedirectResponse
    {
        $data = $request->validate(['revision' => 'required|integer|min:1', 'expected_name' => 'required|string|max:200', 'public_consent' => 'required|accepted']);
        $service->request($request->user(), $enrollment, (int) $data['revision'], $data['expected_name']);

        return back()->with('success', 'درخواست ثبت شد؛ گواهی پس از بررسی نهایی مدیریت صادر می‌شود.');
    }

    public function review(Request $request, CertificateRequest $application, Certification $service): RedirectResponse
    {
        $data = $request->validate(['revision' => 'required|integer|min:1', 'action' => 'required|in:issue,reject', 'note' => 'required|string|min:3|max:2000', 'reviewed' => 'exclude_unless:action,issue|required|accepted']);
        $service->review($request->user(), $application, (int) $data['revision'], $data['action'], $data['note']);

        return back()->with('success', 'نتیجه بررسی درخواست ثبت شد.');
    }

    public function revoke(Request $request, CourseCertificate $certificate, Certification $service): RedirectResponse
    {
        $data = $request->validate(['revision' => 'required|integer|min:1', 'reason' => 'required|string|min:3|max:2000']);
        $service->revoke($request->user(), $certificate, (int) $data['revision'], $data['reason']);

        return back()->with('success', 'گواهی باطل شد و نتیجه استعلام به‌روز شد.');
    }

    public function visibility(Request $request, CourseCertificate $certificate, Certification $service): RedirectResponse
    {
        $data = $request->validate(['revision' => 'required|integer|min:1', 'enabled' => 'required|boolean', 'public_consent' => 'exclude_unless:enabled,true|required|accepted']);
        $service->visibility($request->user(), $certificate, $data['revision'], (bool) $data['enabled'], (bool) ($data['public_consent'] ?? false));

        return back()->with('success', $data['enabled'] ? 'نمایش عمومی با رضایت تازه فعال شد.' : 'نمایش عمومی بسته شد؛ نسخه خصوصی در حساب شما باقی است.');
    }

    public function verify(Request $request, string $token, Certification $service): \Symfony\Component\HttpFoundation\Response
    {
        abort_unless(preg_match('/^[A-Za-z0-9]{48}$/D', $token), 404);
        $certificate = CourseCertificate::where('verification_token', $token)->firstOrFail();

        $viewer = $request->user()?->fresh();
        $public = $certificate->public_enabled && $certificate->public_consent_at !== null;
        abort_unless($public || ($viewer?->is_active && ($viewer->id === $certificate->enrollment->user_id || $viewer->hasRole('admin'))), 404);

        return Inertia::render('CertificateVerification', ['public_enabled' => $public, 'certificate' => $service->publicData($certificate), 'verification_url' => route('certificates.verify', $token)])->toResponse($request)->withHeaders(['Cache-Control' => 'private, no-store', 'X-Robots-Tag' => 'noindex, noarchive', 'Referrer-Policy' => 'no-referrer']);
    }
}
