<?php

namespace Database\Factories;

use App\Models\Cohort;
use Illuminate\Database\Eloquent\Factories\Factory;

class CohortInstallmentFactory extends Factory
{
    public function definition(): array
    {
        return ['cohort_id' => Cohort::factory(), 'sequence' => 1, 'amount' => 500000, 'due_date' => now()->toDateString()];
    }
}
