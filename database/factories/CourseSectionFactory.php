<?php

namespace Database\Factories;

use App\Models\Course;
use Illuminate\Database\Eloquent\Factories\Factory;

class CourseSectionFactory extends Factory
{
    public function definition(): array
    {
        return ['course_id' => Course::factory(), 'title' => 'سرفصل اول', 'position' => 1];
    }
}
