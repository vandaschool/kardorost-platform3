<?php

namespace Database\Factories;

use App\Models\Assignment;
use App\Models\Enrollment;
use Illuminate\Database\Eloquent\Factories\Factory;

class SubmissionFactory extends Factory
{
    public function definition(): array
    {
        return ['assignment_id' => Assignment::factory(), 'enrollment_id' => fn (array $a) => Enrollment::factory()->create(['cohort_id' => Assignment::findOrFail($a['assignment_id'])->cohort_id])->id, 'version' => 1, 'body' => 'پاسخ دانشجو', 'status' => 'submitted'];
    }
}
