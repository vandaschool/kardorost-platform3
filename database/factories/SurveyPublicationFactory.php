<?php

namespace Database\Factories;

use App\Models\CourseSurvey;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class SurveyPublicationFactory extends Factory
{
    public function definition(): array
    {
        return ['course_survey_id' => CourseSurvey::factory(), 'number' => 1, 'definition' => fn (array $attributes) => CourseSurvey::findOrFail($attributes['course_survey_id'])->draft, 'content_hash' => fn (array $attributes) => hash('sha256', json_encode($attributes['definition'], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR)), 'published_by' => User::factory()];
    }

    public function configure(): static
    {
        return $this->afterCreating(fn ($publication) => $publication->survey->update(['current_version' => $publication->number, 'available' => true]));
    }
}
