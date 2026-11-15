<?php

namespace App\Services;

use App\Models\Cohort;
use App\Models\Course;
use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class LessonProgressRecorder
{
    public function record(User $actor, Enrollment $enrollment, Lesson $lesson, array $input): LessonProgress
    {
        $data = Validator::make($input, ['position_seconds' => 'sometimes|integer|min:0|max:864000', 'complete' => 'sometimes|boolean'])->validate();

        return DB::transaction(function () use ($actor, $enrollment, $lesson, $data) {
            $course = Course::whereKey($enrollment->course_id)->sharedLock()->firstOrFail();
            $cohort = Cohort::whereKey($enrollment->cohort_id)->sharedLock()->firstOrFail();
            $enrollment = Enrollment::whereKey($enrollment->id)->lockForUpdate()->firstOrFail();
            abort_unless($enrollment->course_id === $course->id && $enrollment->cohort_id === $cohort->id && $cohort->course_id === $course->id, 403);
            $actor = User::whereKey($actor->id)->lockForUpdate()->firstOrFail();
            app(LearningAccess::class)->learn($actor, $enrollment);
            $lesson = Lesson::with('section')->whereKey($lesson->id)->sharedLock()->firstOrFail();
            abort_unless($lesson->section->course_id === $course->id && $lesson->available(), 403);
            $progress = LessonProgress::firstOrCreate(['enrollment_id' => $enrollment->id, 'lesson_id' => $lesson->id]);
            if (array_key_exists('position_seconds', $data)) {
                $progress->position_seconds = $lesson->duration_seconds ? min($data['position_seconds'], $lesson->duration_seconds) : $data['position_seconds'];
            }
            if (! empty($data['complete']) && ! $progress->completed_at) {
                $progress->completed_at = now();
            }
            $progress->save();

            return $progress;
        }, 3);
    }
}
