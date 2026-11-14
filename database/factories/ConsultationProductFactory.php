<?php

namespace Database\Factories;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ConsultationProductFactory extends Factory
{
    public function definition(): array
    {
        return ['coach_id' => User::factory()->afterCreating(fn ($user) => $user->roles()->attach(Role::where('key', 'coach')->value('id'))), 'reviewed_by' => User::factory()->afterCreating(fn ($user) => $user->roles()->attach(Role::where('key', 'admin')->value('id'))), 'title' => 'پکیج آزمایشی مشاوره', 'description' => 'دو جلسه برای بررسی مسیر حرفه‌ای', 'price' => 1000000, 'sessions' => 2, 'session_minutes' => 60, 'valid_days' => 90, 'published' => true, 'manual_payment_enabled' => true];
    }
}
