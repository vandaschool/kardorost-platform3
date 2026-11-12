<?php

namespace Database\Factories;

use App\Models\ConsultationSlot;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ConsultationFactory extends Factory
{
    public function definition(): array
    {
        return ['student_id' => User::factory()->afterCreating(fn (User $u) => $u->roles()->attach(Role::where('key', 'student')->value('id'))), 'consultation_slot_id' => ConsultationSlot::factory(), 'topic' => fake()->sentence(), 'status' => 'confirmed', 'version' => 1];
    }
}
