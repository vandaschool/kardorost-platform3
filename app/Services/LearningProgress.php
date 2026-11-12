<?php

namespace App\Services;

use App\Models\Enrollment;
use App\Models\Lesson;
use App\Models\LessonProgress;

class LearningProgress
{
    public function summary(Enrollment $enrollment): array
    {
        $lessons = Lesson::whereHas('section', fn ($q) => $q->where('course_id', $enrollment->course_id))->where('is_published', true)->pluck('id');
        $done = LessonProgress::where('enrollment_id', $enrollment->id)->whereIn('lesson_id', $lessons)->whereNotNull('completed_at')->count();

        return ['total' => $lessons->count(), 'completed' => $done, 'percent' => $lessons->count() ? (int) floor($done / $lessons->count() * 100) : 0];
    }
}
