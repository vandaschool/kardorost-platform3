<?php

namespace Database\Factories;

use App\Models\Cohort;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class EnrollmentFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'cohort_id' => Cohort::factory(), 'course_id' => fn (array $a) => Cohort::findOrFail($a['cohort_id'])->course_id, 'status' => 'active', 'amount' => 0];
    }
}
