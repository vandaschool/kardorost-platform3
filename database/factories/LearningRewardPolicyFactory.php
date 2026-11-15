<?php

namespace Database\Factories;

use App\Models\Cohort;
use Illuminate\Database\Eloquent\Factories\Factory;

class LearningRewardPolicyFactory extends Factory
{
    public function definition(): array
    {
        return ['cohort_id' => Cohort::factory(), 'revision' => 0, 'enabled' => false];
    }
}
