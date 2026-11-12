<?php

namespace Database\Factories;

use App\Models\PaymentReceipt;
use Illuminate\Database\Eloquent\Factories\Factory;

class ReceiptClaimFactory extends Factory
{
    public function definition(): array
    {
        return ['payment_receipt_id' => PaymentReceipt::factory(), 'file_hash' => fn (array $a) => PaymentReceipt::findOrFail($a['payment_receipt_id'])->file_hash, 'transfer_key' => fake()->unique()->sha256()];
    }
}
