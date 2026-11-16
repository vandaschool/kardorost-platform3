<?php

namespace Database\Factories;

use App\Models\CoachCredential;
use Illuminate\Database\Eloquent\Factories\Factory;

class CoachCredentialEventFactory extends Factory
{
    public function definition(): array
    {
        return ['coach_credential_id' => CoachCredential::factory(), 'actor_id' => fn (array $attributes) => CoachCredential::findOrFail($attributes['coach_credential_id'])->user_id, 'action' => 'submitted', 'from_status' => null, 'to_status' => 'submitted', 'revision' => 1];
    }
}
