<?php

namespace Database\Factories;

use App\Models\ContentMedia;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/**
 * @extends Factory<ContentMedia>
 */
class ContentMediaFactory extends Factory
{
    /**
     * Define the model's default state.
     *
     * @return array<string, mixed>
     */
    public function definition(): array
    {
        return [
            'owner_id' => User::factory(),
            'public_key' => (string) Str::uuid(),
            'request_key' => (string) Str::uuid(),
        ];
    }
}
