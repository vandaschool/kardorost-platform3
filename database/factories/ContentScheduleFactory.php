<?php

namespace Database\Factories;

use App\Models\ContentPage;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ContentScheduleFactory extends Factory
{
    public function definition(): array
    {
        return ['content_page_id' => ContentPage::factory()->state(['revision' => 2]), 'created_by' => User::factory()->afterCreating(fn ($user) => $user->roles()->attach(Role::where('key', 'admin')->value('id'))), 'source_revision' => 1, 'page_revision' => 2, 'definition' => fn ($attributes) => ContentPage::findOrFail($attributes['content_page_id'])->draft, 'definition_hash' => fn ($attributes) => hash('sha256', json_encode($attributes['definition'], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)), 'scheduled_for' => now()->addHour()];
    }
}
