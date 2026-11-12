<?php

namespace Database\Factories;

use App\Models\Course;
use Illuminate\Database\Eloquent\Factories\Factory;

class CohortFactory extends Factory
{
    public function definition(): array
    {
        return ['course_id' => Course::factory(), 'title' => 'نوبت اول', 'format' => 'hybrid', 'capacity' => 20, 'registration_open' => true];
    }
}
