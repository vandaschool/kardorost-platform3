<?php

namespace Database\Factories;

use App\Models\AssessmentVersion;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AssessmentAttemptFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'assessment_version_id' => AssessmentVersion::factory(), 'status' => 'in_progress', 'revision' => 1, 'answers' => []];
    }
}
