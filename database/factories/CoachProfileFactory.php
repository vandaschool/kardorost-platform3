<?php

namespace Database\Factories;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class CoachProfileFactory extends Factory
{
    public function definition(): array
    {
        return ['user_id' => User::factory()->afterCreating(fn ($user) => $user->roles()->attach(Role::where('key', 'coach')->value('id'))), 'slug' => (string) Str::uuid(), 'draft' => ['display_name' => 'مربی صرفاً آزمایشی', 'headline' => 'عنوان حرفه‌ای آزمایشی', 'bio' => 'این متن فقط برای آزمون فرایند پروفایل است و شرح حال فرد واقعی نیست.', 'city' => '', 'focus_areas' => ['موضوع آزمایشی'], 'languages' => ['فارسی']]];
    }
}
