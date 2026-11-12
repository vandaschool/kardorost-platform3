<?php

namespace Database\Factories;

use App\Models\Payment;
use Illuminate\Database\Eloquent\Factories\Factory;

class PaymentAllocationFactory extends Factory
{
    public function definition(): array
    {
        return ['payment_id' => Payment::factory(), 'installment_id' => fn (array $a) => Payment::findOrFail($a['payment_id'])->installment_id, 'amount' => fn (array $a) => Payment::findOrFail($a['payment_id'])->amount];
    }
}
