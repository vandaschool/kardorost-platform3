<?php

namespace Database\Factories;

use App\Models\Enrollment;
use Illuminate\Database\Eloquent\Factories\Factory;

class InstallmentFactory extends Factory
{
    public function definition(): array
    {
        return ['enrollment_id' => Enrollment::factory()->state(['amount' => 1000000]), 'billing_version' => 1, 'sequence' => 1, 'amount' => 1000000, 'due_date' => now()->toDateString()];
    }
}
