<?php

namespace Database\Factories;

use App\Models\Submission;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class SubmissionReviewFactory extends Factory
{
    public function definition(): array
    {
        return ['submission_id' => Submission::factory(), 'reviewer_id' => User::factory(), 'revision' => 1, 'status' => 'reviewed', 'score' => 10, 'feedback' => 'بازخورد'];
    }
}
