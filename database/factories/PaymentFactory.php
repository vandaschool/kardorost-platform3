<?php

namespace Database\Factories;

use App\Models\Enrollment;
use App\Models\Installment;
use Illuminate\Database\Eloquent\Factories\Factory;

class PaymentFactory extends Factory
{
    public function definition(): array
    {
        return ['installment_id' => Installment::factory(), 'enrollment_id' => fn (array $a) => Installment::findOrFail($a['installment_id'])->enrollment_id, 'user_id' => fn (array $a) => Enrollment::findOrFail($a['enrollment_id'])->user_id, 'amount' => fn (array $a) => Installment::findOrFail($a['installment_id'])->amount, 'method' => 'gateway', 'status' => 'pending'];
    }
}
