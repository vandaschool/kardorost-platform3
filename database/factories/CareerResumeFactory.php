<?php

namespace Database\Factories;

use App\Models\User;
use App\Services\ResumeBuilder;
use Illuminate\Database\Eloquent\Factories\Factory;

class CareerResumeFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'title' => 'رزومه کاری', 'draft' => app(ResumeBuilder::class)->emptyDefinition('نام آزمایشی')];
    }
}
