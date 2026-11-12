<?php

namespace Database\Factories;

use App\Models\CourseSection;
use Illuminate\Database\Eloquent\Factories\Factory;

class LessonFactory extends Factory
{
    public function definition(): array
    {
        return ['course_section_id' => CourseSection::factory(), 'title' => 'درس اول', 'body' => 'متن آموزشی', 'media_kind' => 'none', 'is_published' => true, 'position' => 1];
    }
}
