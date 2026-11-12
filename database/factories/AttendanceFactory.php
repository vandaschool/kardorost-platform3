<?php

namespace Database\Factories;

use App\Models\Enrollment;
use App\Models\LiveSession;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AttendanceFactory extends Factory
{
    public function definition(): array
    {
        return ['live_session_id' => LiveSession::factory(), 'enrollment_id' => fn (array $a) => Enrollment::factory()->create(['cohort_id' => LiveSession::findOrFail($a['live_session_id'])->cohort_id])->id, 'status' => 'present', 'recorded_by' => User::factory()];
    }
}
