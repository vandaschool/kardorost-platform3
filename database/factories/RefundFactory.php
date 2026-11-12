<?php

namespace Database\Factories;

use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

class RefundFactory extends Factory
{
    public function definition(): array
    {
        return ['payment_id' => Payment::factory()->state(['status' => 'review_required', 'captured_at' => now(), 'reference' => fake()->unique()->uuid()]), 'amount' => 1000000, 'destination' => 'IR820540102680020817909002', 'reason' => 'درخواست بازپرداخت', 'status' => 'requested', 'version' => 1];
    }
}
