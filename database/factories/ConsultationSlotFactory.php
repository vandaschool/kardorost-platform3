<?php

namespace Database\Factories;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ConsultationSlotFactory extends Factory
{
    public function definition(): array
    {
        return ['coach_id' => User::factory()->afterCreating(fn (User $u) => $u->roles()->attach(Role::where('key', 'coach')->value('id'))), 'starts_at' => now()->addDay(), 'ends_at' => now()->addDay()->addHour(), 'provider' => 'meet', 'meeting_url' => 'https://meet.google.com/abc-defg-hij', 'published' => true, 'booked' => false];
    }
}
