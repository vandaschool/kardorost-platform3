<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Models\AuditEvent;
use App\Models\Enrollment;
use App\Models\Submission;
use App\Notifications\AssignmentReviewed;
use App\Services\LearningAccess;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\StreamedResponse;

class SubmissionController extends Controller
{
    public function store(Request $request, Enrollment $enrollment, Assignment $assignment, LearningAccess $access): RedirectResponse
    {
        $access->learn($request->user(), $enrollment);
        abort_unless($assignment->cohort_id === $enrollment->cohort_id && $assignment->available(), 403);
        $data = $request->validate(['body' => 'nullable|required_without:file|string|max:12000', 'file' => 'nullable|required_without:body|file|mimes:pdf,jpg,jpeg,png,docx|max:10240', 'expected_version' => 'required|integer|min:0']);
        $path = null;
        try {
            DB::transaction(function () use ($request, $data, $enrollment, $assignment, $access, &$path) {
                $enrollment = Enrollment::whereKey($enrollment->id)->lockForUpdate()->firstOrFail();
                $access->learn($request->user(), $enrollment);
                $assignment = Assignment::whereKey($assignment->id)->lockForUpdate()->firstOrFail();
                if (! $assignment->open()) {
                    throw ValidationException::withMessages(['body' => 'مهلت ارسال این تمرین بسته شده است.']);
                }
                $latest = Submission::where('assignment_id', $assignment->id)->where('enrollment_id', $enrollment->id)->orderByDesc('version')->first();
                if (($latest?->version ?? 0) !== (int) $data['expected_version']) {
                    throw ValidationException::withMessages(['body' => 'پاسخ جدیدتری ثبت شده است. صفحه را به‌روز کنید.']);
                }
                if ($latest?->status === 'reviewed') {
                    throw ValidationException::withMessages(['body' => 'پاسخ بررسی شده است؛ ارسال مجدد نیازمند درخواست اصلاح مربی است.']);
                }
                if ($request->hasFile('file')) {
                    $path = $request->file('file')->store('submissions', 'learning');
                }
                $name = $request->hasFile('file') ? mb_substr(preg_replace('/[\x00-\x1F\x7F]/u', '_', str_replace(['\\', '/'], '_', $request->file('file')->getClientOriginalName())), 0, 200) : null;
                $submission = Submission::create(['assignment_id' => $assignment->id, 'enrollment_id' => $enrollment->id, 'version' => ($latest?->version ?? 0) + 1, 'body' => $data['body'] ?? null, 'file_path' => $path, 'file_name' => $name]);
                AuditEvent::create(['actor_id' => $request->user()->id, 'action' => 'assignment.submitted', 'subject_type' => 'submission', 'subject_id' => $submission->id, 'metadata' => ['version' => $submission->version]]);
            });
        } catch (\Throwable $exception) {
            if ($path) {
                Storage::disk('learning')->delete($path);
            }throw $exception;
        }

        return back()->with('success', 'پاسخ تمرین ذخیره شد و منتظر بررسی مربی است.');
    }

    public function review(Request $request, Submission $submission, LearningAccess $access): RedirectResponse
    {
        $access->manage($request->user(), $submission->assignment->cohort->course);
        $data = $request->validate(['status' => 'required|in:reviewed,revision_requested', 'score' => 'nullable|required_if:status,reviewed|integer|min:0|max:'.$submission->assignment->max_score, 'feedback' => 'nullable|required_if:status,revision_requested|string|max:12000', 'expected_review_version' => 'required|integer|min:0']);
        DB::transaction(function () use ($request, $submission, $data, $access) {
            $enrollment = Enrollment::whereKey($submission->enrollment_id)->lockForUpdate()->firstOrFail();
            $assignment = Assignment::whereKey($submission->assignment_id)->lockForUpdate()->firstOrFail();
            $submission = Submission::whereKey($submission->id)->lockForUpdate()->firstOrFail();
            $access->manage($request->user(), $enrollment->course);
            abort_unless($enrollment->status === 'active', 403);
            $latest = Submission::where('assignment_id', $submission->assignment_id)->where('enrollment_id', $enrollment->id)->max('version');
            if ((int) $latest !== $submission->version || $submission->review_version !== (int) $data['expected_review_version']) {
                throw ValidationException::withMessages(['score' => 'پاسخ یا بازخورد جدیدتری ثبت شده است. صفحه را به‌روز کنید.']);
            }
            if ($data['status'] === 'reviewed' && (int) $data['score'] > $assignment->max_score) {
                throw ValidationException::withMessages(['score' => 'نمره از سقف فعلی تمرین بیشتر است.']);
            }
            $submission->update(['status' => $data['status'], 'score' => $data['status'] === 'reviewed' ? $data['score'] : null, 'feedback' => $data['feedback'] ?? null, 'reviewed_by' => $request->user()->id, 'reviewed_at' => now(), 'review_version' => $submission->review_version + 1]);
            $submission->reviews()->create(['reviewer_id' => $request->user()->id, 'revision' => $submission->review_version, 'status' => $submission->status, 'score' => $submission->score, 'feedback' => $submission->feedback]);
            $enrollment->user->notify(new AssignmentReviewed($submission));
            AuditEvent::create(['actor_id' => $request->user()->id, 'action' => 'assignment.reviewed', 'subject_type' => 'submission', 'subject_id' => $submission->id, 'metadata' => ['status' => $data['status'], 'version' => $submission->version]]);
        });

        return back()->with('success', 'بازخورد ذخیره شد و دانشجو اعلان دریافت کرد.');
    }

    public function file(Request $request, Submission $submission, LearningAccess $access): StreamedResponse
    {
        $organization = $request->user()->hasRole('org_admin') ? app(OrganizationController::class)->selected($request) : null;
        $access->download($request->user(), $submission, $organization);
        abort_unless($submission->file_path && Storage::disk('learning')->exists($submission->file_path), 404);

        return Storage::disk('learning')->download($submission->file_path, $submission->file_name, ['Cache-Control' => 'private, no-store', 'X-Content-Type-Options' => 'nosniff']);
    }
}
