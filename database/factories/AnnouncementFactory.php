<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AnnouncementFactory extends Factory
{
    public function definition(): array
    {
        return ['created_by' => User::factory(), 'title' => 'اطلاعیه آزمایشی', 'message' => 'پیام صرفاً برای آزمون سامانه', 'roles' => ['student']];
    }
}
