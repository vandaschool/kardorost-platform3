<?php

namespace Database\Factories;

use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

class PaymentReceiptFactory extends Factory
{
    public function definition(): array
    {
        return ['payment_id' => Payment::factory()->state(['method' => 'manual', 'status' => 'pending_review']), 'file_path' => 'receipts/test.pdf', 'file_name' => 'test.pdf', 'file_hash' => fake()->sha256(), 'destination' => '6037991234567890', 'transfer_reference' => fake()->numerify('##########'), 'transfer_date' => now()->toDateString(), 'status' => 'pending'];
    }
}
