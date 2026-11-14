<?php

namespace Database\Factories;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ConsultationGrantFactory extends Factory
{
    public function definition(): array
    {
        return ['student_id' => User::factory()->afterCreating(fn ($user) => $user->roles()->attach(Role::where('key', 'student')->value('id'))), 'coach_id' => User::factory()->afterCreating(fn ($user) => $user->roles()->attach(Role::where('key', 'coach')->value('id'))), 'created_by' => User::factory(), 'title' => 'پکیج آزمایشی مشاوره', 'source_reference' => fake()->unique()->uuid(), 'total_sessions' => 2, 'session_minutes' => 60, 'valid_until' => now()->addMonth()->toDateString()];
    }
}
