<?php

namespace App\Http\Controllers;

use App\Models\Assignment;
use App\Models\Attendance;
use App\Models\CourseSection;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonProgress as Progress;
use App\Models\LiveSession;
use App\Models\Submission;
use App\Services\LearningAccess;
use App\Services\LearningProgress;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Inertia\Inertia;
use Inertia\Response;

class LearningController extends Controller
{
    public function show(Request $request, Enrollment $enrollment, LearningAccess $access, LearningProgress $progress): Response
    {
        $access->learn($request->user(), $enrollment);
        $sections = CourseSection::where('course_id', $enrollment->course_id)->with(['lessons' => fn ($q) => $q->where('is_published', true)])->orderBy('position')->orderBy('id')->get();
        $completed = Progress::where('enrollment_id', $enrollment->id)->get()->keyBy('lesson_id');
        $lessons = $sections->flatMap->lessons;
        $data = $request->validate(['lesson' => 'nullable|integer|min:1', 'recording' => 'nullable|integer|min:1']);
        $lesson = null;
        $recording = null;
        if (! empty($data['recording'])) {
            $session = LiveSession::whereKey($data['recording'])->where('cohort_id', $enrollment->cohort_id)->where('is_published', true)->firstOrFail();
            abort_unless($session->recording_url && $session->ends_at->isPast(), 403);
            $recording = ['id' => $session->id, 'title' => $session->title, 'media_kind' => $session->recording_kind, 'media_url' => $session->recording_url];
        } elseif (! empty($data['lesson'])) {
            $lesson = $lessons->firstWhere('id', (int) $data['lesson']);
            abort_unless($lesson, 404);
            abort_unless($lesson->available(), 403);
        } else {
            $lesson = $lessons->first(fn ($l) => $l->available() && ! $completed->get($l->id)?->completed_at) ?? $lessons->first(fn ($l) => $l->available());
        }
        $selected = $lesson ? ['id' => $lesson->id, 'title' => $lesson->title, 'body' => $lesson->body, 'media_kind' => $lesson->media_kind, 'media_url' => $lesson->media_url, 'duration_seconds' => $lesson->duration_seconds, 'completed' => (bool) $completed->get($lesson->id)?->completed_at, 'position_seconds' => $completed->get($lesson->id)?->position_seconds ?? 0] : null;
        $sectionData = $sections->filter(fn ($s) => $s->lessons->isNotEmpty())->map(fn ($s) => ['id' => $s->id, 'title' => $s->title, 'lessons' => $s->lessons->map(fn ($l) => ['id' => $l->id, 'title' => $l->title, 'duration_seconds' => $l->duration_seconds, 'locked' => ! $l->available(), 'available_at' => $l->available_at?->toIso8601String(), 'completed' => (bool) $completed->get($l->id)?->completed_at])->values()])->values();
        $attendance = Attendance::where('enrollment_id', $enrollment->id)->pluck('status', 'live_session_id');
        $sessions = LiveSession::where('cohort_id', $enrollment->cohort_id)->where('is_published', true)->orderBy('starts_at')->get()->map(fn ($s) => ['id' => $s->id, 'title' => $s->title, 'provider' => $s->provider, 'starts_at' => $s->starts_at->toIso8601String(), 'ends_at' => $s->ends_at->toIso8601String(), 'joinable' => $s->joinable(), 'has_recording' => (bool) $s->recording_url && $s->ends_at->isPast(), 'attendance' => $attendance->get($s->id)]);
        $assignments = Assignment::where('cohort_id', $enrollment->cohort_id)->where('is_published', true)->where(fn ($q) => $q->whereNull('available_at')->orWhere('available_at', '<=', now()))->orderBy('due_at')->orderBy('id')->get();
        $submissions = Submission::where('enrollment_id', $enrollment->id)->with('reviews')->whereIn('assignment_id', $assignments->pluck('id'))->orderByDesc('version')->get()->groupBy('assignment_id');

        return Inertia::render('CourseRoom', [
            'enrollment' => $enrollment->only('id', 'status'), 'course' => $enrollment->course->only('id', 'title'), 'cohort' => $enrollment->cohort->only('id', 'title', 'format'),
            'sections' => $sectionData, 'selected' => $selected, 'recording' => $recording, 'progress' => $progress->summary($enrollment), 'sessions' => $sessions,
            'assignments' => $assignments->map(fn ($a) => [...$a->only('id', 'title', 'instructions', 'max_score', 'due_at', 'closes_at'), 'open' => $a->open(), 'attempts' => $submissions->get($a->id, collect())->map(fn ($s) => $this->submissionData($s))->values()]),
        ]);
    }

    public function submissionData(Submission $submission): array
    {
        return [...$submission->only('id', 'version', 'body', 'status', 'score', 'feedback', 'reviewed_at', 'created_at', 'file_name', 'review_version'), 'download_url' => $submission->file_path ? route('submissions.file', $submission) : null, 'reviews' => $submission->reviews->map(fn ($r) => $r->only('id', 'revision', 'status', 'score', 'feedback', 'created_at'))];
    }

    public function progress(Request $request, Enrollment $enrollment, Lesson $lesson, LearningAccess $access): RedirectResponse|\Illuminate\Http\Response
    {
        $access->learn($request->user(), $enrollment);
        abort_unless($lesson->section->course_id === $enrollment->course_id && $lesson->available(), 403);
        $data = $request->validate(['position_seconds' => 'sometimes|integer|min:0|max:864000', 'complete' => 'sometimes|boolean']);
        DB::transaction(function () use ($enrollment, $lesson, $data, $request, $access) {
            $enrollment = Enrollment::whereKey($enrollment->id)->lockForUpdate()->firstOrFail();
            $access->learn($request->user(), $enrollment);
            $progress = Progress::firstOrCreate(['enrollment_id' => $enrollment->id, 'lesson_id' => $lesson->id]);
            if (array_key_exists('position_seconds', $data)) {
                $progress->position_seconds = $lesson->duration_seconds ? min($data['position_seconds'], $lesson->duration_seconds) : $data['position_seconds'];
            }
            if (! empty($data['complete']) && ! $progress->completed_at) {
                $progress->completed_at = now();
            }
            $progress->save();
        });

        return $request->expectsJson() ? response()->noContent() : back();
    }

    public function join(Request $request, Enrollment $enrollment, LiveSession $session, LearningAccess $access): RedirectResponse
    {
        $access->learn($request->user(), $enrollment);
        abort_unless($session->cohort_id === $enrollment->cohort_id && $session->is_published, 403);
        if (! $session->joinable()) {
            return back()->with('error', 'ورود به کلاس از ۱۵ دقیقه پیش از شروع تا پایان جلسه فعال است.');
        }

        return redirect()->away($session->meeting_url);
    }
}
