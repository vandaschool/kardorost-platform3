<?php

namespace Database\Factories;

use App\Models\Cohort;
use Illuminate\Database\Eloquent\Factories\Factory;

class AssignmentFactory extends Factory
{
    public function definition(): array
    {
        return ['cohort_id' => Cohort::factory(), 'title' => 'تمرین اول', 'instructions' => 'پاسخ خود را بنویسید.', 'max_score' => 100, 'is_published' => true];
    }
}
