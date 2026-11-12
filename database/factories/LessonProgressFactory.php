<?php

namespace Database\Factories;

use App\Models\CourseSection;
use App\Models\Enrollment;
use App\Models\Lesson;
use Illuminate\Database\Eloquent\Factories\Factory;

class LessonProgressFactory extends Factory
{
    public function definition(): array
    {
        return ['enrollment_id' => Enrollment::factory(), 'lesson_id' => fn (array $a) => Lesson::factory()->create(['course_section_id' => CourseSection::factory()->create(['course_id' => Enrollment::findOrFail($a['enrollment_id'])->course_id])->id])->id];
    }
}
