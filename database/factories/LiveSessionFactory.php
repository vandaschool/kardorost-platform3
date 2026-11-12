<?php

namespace Database\Factories;

use App\Models\Cohort;
use Illuminate\Database\Eloquent\Factories\Factory;

class LiveSessionFactory extends Factory
{
    public function definition(): array
    {
        return ['cohort_id' => Cohort::factory(), 'title' => 'جلسه اول', 'provider' => 'meet', 'meeting_url' => 'https://meet.google.com/abc-defg-hij', 'starts_at' => now()->addHour(), 'ends_at' => now()->addHours(2), 'is_published' => true];
    }
}
