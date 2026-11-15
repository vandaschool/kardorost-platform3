<?php

namespace Database\Factories;

use App\Models\CareerResume;
use App\Models\User;
use App\Services\ResumeBuilder;
use Illuminate\Database\Eloquent\Factories\Factory;

class ResumeVersionFactory extends Factory
{
    public function definition(): array
    {
        $definition = app(ResumeBuilder::class)->emptyDefinition('نام آزمایشی');

        return ['career_resume_id' => CareerResume::factory(), 'created_by' => User::factory(), 'number' => 1, 'definition' => $definition, 'content_hash' => hash('sha256', json_encode($definition))];
    }
}
