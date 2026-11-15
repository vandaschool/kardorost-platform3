<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class SupportTicketFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory(), 'subject' => 'درخواست پشتیبانی', 'category' => 'technical', 'priority' => 'normal', 'status' => 'waiting_staff', 'revision' => 1, 'request_key' => (string) Str::uuid(), 'last_message_at' => now()];
    }
}
