<?php

namespace Database\Factories;

use App\Models\Enrollment;
use App\Models\Role;
use App\Models\SurveyPublication;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class SurveyResponseFactory extends Factory
{
    public function definition(): array
    {
        return ['survey_publication_id' => SurveyPublication::factory(), 'enrollment_id' => fn (array $attributes) => Enrollment::factory()->create(['cohort_id' => SurveyPublication::findOrFail($attributes['survey_publication_id'])->survey->cohort_id, 'user_id' => User::factory()->afterCreating(fn ($user) => $user->roles()->attach(Role::where('key', 'student')->value('id')))])->id, 'status' => 'in_progress', 'answers' => [], 'respondent_name' => fn (array $attributes) => ($attributes['status'] === 'submitted' && SurveyPublication::findOrFail($attributes['survey_publication_id'])->definition['mode'] === 'identified') ? Enrollment::findOrFail($attributes['enrollment_id'])->user->name : null];
    }
}
