<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Models\Attendance;
use App\Models\AuditEvent;
use App\Models\Cohort;
use App\Models\Course;
use App\Models\CourseSection;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LiveSession;
use App\Models\Submission;
use App\Services\LearningAccess;
use App\Services\LearningProgress;
use App\Services\MediaUrl;
use Carbon\CarbonImmutable;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Inertia\Inertia;
use Inertia\Response;

class TeachingController extends Controller
{
    public function show(Request $request, Course $course, LearningAccess $access, LearningProgress $progress): Response
    {
        $access->manage($request->user(), $course);
        $sections = CourseSection::where('course_id', $course->id)->with('lessons')->orderBy('position')->orderBy('id')->get();
        $cohorts = $course->cohorts()->orderBy('id')->get();
        $data = $request->validate(['cohort' => 'nullable|integer|min:1']);
        $cohort = isset($data['cohort']) ? $cohorts->firstWhere('id', (int) $data['cohort']) : $cohorts->first();
        abort_if(isset($data['cohort']) && ! $cohort, 404);
        $enrollments = $cohort ? Enrollment::where('cohort_id', $cohort->id)->where('status', 'active')->with('user')->get() : collect();
        $assignments = $cohort ? Assignment::where('cohort_id', $cohort->id)->withCount('submissions')->orderBy('id')->get() : collect();
        $allSubmissions = $cohort ? Submission::with('reviews')->whereIn('assignment_id', $assignments->pluck('id'))->whereIn('enrollment_id', $enrollments->pluck('id'))->orderByDesc('version')->get() : collect();
        $latest = $allSubmissions->unique(fn ($s) => $s->assignment_id.':'.$s->enrollment_id)->values();
        $sessions = $cohort ? LiveSession::where('cohort_id', $cohort->id)->orderBy('starts_at')->get() : collect();

        return Inertia::render('Teaching', [
            'course' => $course->only('id', 'title'), 'role' => $request->user()->hasRole('admin') ? 'admin' : 'coach', 'sections' => $sections, 'cohorts' => $cohorts, 'cohort' => $cohort,
            'sessions' => $sessions->map(fn ($s) => [...$s->toArray(), 'meeting_url' => $s->meeting_url, 'recording_url' => $s->recording_url]), 'assignments' => $assignments,
            'members' => $enrollments->map(fn ($e) => ['enrollment_id' => $e->id, 'name' => $e->user->name, 'progress' => $progress->summary($e)]),
            'submissions' => $latest->map(fn ($s) => [...app(LearningController::class)->submissionData($s), 'assignment_id' => $s->assignment_id, 'enrollment_id' => $s->enrollment_id, 'attempt_history' => $allSubmissions->filter(fn ($old) => $old->assignment_id === $s->assignment_id && $old->enrollment_id === $s->enrollment_id && $old->version < $s->version)->map(fn ($old) => app(LearningController::class)->submissionData($old))->values()]),
            'attendances' => $sessions->isEmpty() ? [] : Attendance::whereIn('live_session_id', $sessions->pluck('id'))->get(['live_session_id', 'enrollment_id', 'status']),
        ]);
    }

    public function section(Request $request, Course $course, LearningAccess $access): RedirectResponse
    {
        $access->manage($request->user(), $course);
        $data = $request->validate(['title' => 'required|string|max:150', 'position' => 'required|integer|min:0|max:10000']);
        $section = $course->sections()->create($data);
        $this->audit($request, 'section.created', 'course_section', $section->id);

        return back()->with('success', 'سرفصل اضافه شد.');
    }

    public function lesson(Request $request, Course $course, LearningAccess $access, MediaUrl $media, ?Lesson $lesson = null): RedirectResponse
    {
        $access->manage($request->user(), $course);
        if ($lesson) {
            abort_unless($lesson->section->course_id === $course->id, 404);
        }
        $data = $request->validate(['course_section_id' => 'required|integer', 'title' => 'required|string|max:150', 'body' => 'nullable|string|max:50000', 'media_kind' => 'required|in:none,mp4,hls,embed', 'media_url' => 'nullable|string|max:2000', 'duration_seconds' => 'required|integer|min:0|max:86400', 'position' => 'required|integer|min:0|max:10000', 'is_published' => 'required|boolean', 'available_at' => 'nullable|date']);
        abort_unless($course->sections()->whereKey($data['course_section_id'])->exists(), 422);
        $data = $this->normalizeDates($data, ['available_at']);
        $data['media_url'] = $media->media($data['media_kind'], $data['media_url'] ?? null);
        if ($data['is_published'] && $data['media_kind'] === 'none' && trim($data['body'] ?? '') === '') {
            throw ValidationException::withMessages(['body' => 'درس منتشرشده باید متن یا ویدیو داشته باشد.']);
        }
        DB::transaction(function () use ($lesson, $data, $request) {
            if ($lesson) {
                $lesson->update($data);
            } else {
                $lesson = Lesson::create($data);
            }
            $this->audit($request, 'lesson.saved', 'lesson', $lesson->id);
        });

        return back()->with('success', 'درس ذخیره شد.');
    }

    public function session(Request $request, Course $course, Cohort $cohort, LearningAccess $access, MediaUrl $media, ?LiveSession $session = null): RedirectResponse
    {
        $access->manage($request->user(), $course);
        abort_unless($cohort->course_id === $course->id, 404);
        if ($session) {
            abort_unless($session->cohort_id === $cohort->id, 404);
        }
        $data = $request->validate(['title' => 'required|string|max:150', 'provider' => 'required|in:meet,skyroom,zoom', 'meeting_url' => 'required|string|max:2000', 'starts_at' => 'required|date', 'ends_at' => 'required|date|after:starts_at', 'is_published' => 'required|boolean', 'recording_kind' => 'required|in:none,mp4,hls,embed', 'recording_url' => 'nullable|string|max:2000']);
        $data = $this->normalizeDates($data, ['starts_at', 'ends_at']);
        $data['meeting_url'] = $media->meeting($data['provider'], $data['meeting_url']);
        $data['recording_url'] = $media->media($data['recording_kind'], $data['recording_url'] ?? null, 'recording_url');
        $data['cohort_id'] = $cohort->id;
        DB::transaction(function () use ($session, $data, $request) {
            if ($session) {
                $session->update($data);
            } else {
                $session = LiveSession::create($data);
            }$this->audit($request, 'live_session.saved', 'live_session', $session->id);
        });

        return back()->with('success', 'جلسه و لینک‌های آن ذخیره شدند.');
    }

    public function assignment(Request $request, Course $course, Cohort $cohort, LearningAccess $access, ?Assignment $assignment = null): RedirectResponse
    {
        $access->manage($request->user(), $course);
        abort_unless($cohort->course_id === $course->id, 404);
        if ($assignment) {
            abort_unless($assignment->cohort_id === $cohort->id, 404);
        }
        $rules = ['title' => 'required|string|max:150', 'instructions' => 'required|string|max:20000', 'max_score' => 'required|integer|between:1,1000', 'is_published' => 'required|boolean', 'available_at' => 'nullable|date', 'due_at' => 'nullable|date', 'closes_at' => 'nullable|date'];
        if ($request->filled('available_at')) {
            $rules['due_at'] .= '|after_or_equal:available_at';
            $rules['closes_at'] .= '|after_or_equal:available_at';
        }
        if ($request->filled('due_at')) {
            $rules['closes_at'] .= '|after_or_equal:due_at';
        }
        $data = $this->normalizeDates($request->validate($rules), ['available_at', 'due_at', 'closes_at']);
        $data['cohort_id'] = $cohort->id;
        DB::transaction(function () use ($assignment, $data, $request) {
            if ($assignment) {
                $assignment = Assignment::whereKey($assignment->id)->lockForUpdate()->firstOrFail();
                if ($assignment->max_score !== (int) $data['max_score'] && Submission::where('assignment_id', $assignment->id)->exists()) {
                    throw ValidationException::withMessages(['max_score' => 'بعد از دریافت اولین پاسخ، سقف نمره قابل تغییر نیست؛ برای مقیاس جدید یک تمرین تازه بسازید.']);
                }
                $assignment->update($data);
            } else {
                $assignment = Assignment::create($data);
            }
            $this->audit($request, 'assignment.saved', 'assignment', $assignment->id);
        });

        return back()->with('success', 'تمرین ذخیره شد.');
    }

    public function attendance(Request $request, Course $course, LiveSession $session, LearningAccess $access): RedirectResponse
    {
        $access->manage($request->user(), $course);
        abort_unless($session->cohort->course_id === $course->id, 404);
        $data = $request->validate(['enrollment_id' => 'required|integer', 'status' => 'required|in:present,absent,excused']);
        $enrollment = Enrollment::findOrFail($data['enrollment_id']);
        abort_unless($enrollment->cohort_id === $session->cohort_id && $enrollment->status === 'active', 422);
        DB::transaction(function () use ($data, $request, $session) {
            Attendance::updateOrCreate(['live_session_id' => $session->id, 'enrollment_id' => $data['enrollment_id']], ['status' => $data['status'], 'recorded_by' => $request->user()->id]);
            $this->audit($request, 'attendance.recorded', 'live_session', $session->id, ['enrollment_id' => $data['enrollment_id'], 'status' => $data['status']]);
        });

        return back()->with('success', 'وضعیت حضور ذخیره شد.');
    }

    private function normalizeDates(array $data, array $fields): array
    {
        foreach ($fields as $field) {
            if (! empty($data[$field])) {
                $data[$field] = CarbonImmutable::parse($data[$field])->setTimezone(config('app.timezone'))->toDateTimeString();
            }
        }

        return $data;
    }

    private function audit(Request $request, string $action, string $type, int $id, array $metadata = []): void
    {
        AuditEvent::create(['actor_id' => $request->user()->id, 'action' => $action, 'subject_type' => $type, 'subject_id' => $id, 'metadata' => $metadata]);
    }
}
