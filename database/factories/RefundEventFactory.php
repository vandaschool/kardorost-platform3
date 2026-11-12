<?php

namespace Database\Factories;

use App\Models\Refund;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class RefundEventFactory extends Factory
{
    public function definition(): array
    {
        return ['refund_id' => Refund::factory(), 'actor_id' => User::factory(), 'status' => 'requested', 'note' => 'درخواست بازپرداخت', 'version' => 1];
    }
}
