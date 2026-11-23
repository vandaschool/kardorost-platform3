<?php

namespace Database\Factories;

use App\Models\CoachProfilePhoto;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<CoachProfilePhoto>
 */
class CoachProfilePhotoFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'user_id' => User::factory(),
            'public_key' => (string) Str::uuid(),
            'request_key' => (string) Str::uuid(),
            'consented_at' => now()->startOfSecond(),
        ];
    }
}
