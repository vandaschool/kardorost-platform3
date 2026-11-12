<?php

namespace Database\Factories;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ConsultationRequestFactory extends Factory
{
    public function definition(): array
    {
        return ['student_id' => User::factory()->afterCreating(fn (User $u) => $u->roles()->attach(Role::where('key', 'student')->value('id'))), 'topic' => fake()->sentence(), 'availability' => 'عصرها پس از ساعت شش', 'status' => 'pending', 'version' => 1];
    }
}
