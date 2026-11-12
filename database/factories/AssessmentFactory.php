<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class AssessmentFactory extends Factory
{
    public function definition(): array
    {
        return ['title' => 'پرسشنامه آزمایشی برای آزمون نرم‌افزار', 'revision' => 0, 'current_version' => 0, 'available' => false];
    }
}
