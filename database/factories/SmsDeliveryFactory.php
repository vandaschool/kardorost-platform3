<?php

namespace Database\Factories;

use App\Services\MelipayamakClient;
use Illuminate\Database\Eloquent\Factories\Factory;

class SmsDeliveryFactory extends Factory
{
    public function definition(): array
    {
        return ['source_type' => 'invitation', 'source_id' => fake()->unique()->numberBetween(1, 2000000000), 'provider_mode' => 'rest', 'provider_account' => app(MelipayamakClient::class)->account()['fingerprint'] ?? hash('sha256', 'isolated-test-sender'), 'provider_reference' => (string) fake()->unique()->numberBetween(1000000000, 2000000000), 'next_poll_at' => now(), 'expires_at' => now()->addHours(72)];
    }
}
