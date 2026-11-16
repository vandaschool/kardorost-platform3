<?php

namespace Database\Factories;

use App\Models\Announcement;
use App\Models\Role;
use App\Models\User;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Factories\Factory;

class AnnouncementScheduleFactory extends Factory
{
    public function definition(): array
    {
        return ['announcement_id' => Announcement::factory()->state(['revision' => 2]), 'created_by' => User::factory()->afterCreating(fn ($user) => $user->roles()->attach(Role::where('key', 'admin')->value('id'))), 'source_revision' => 1, 'announcement_revision' => 2, 'scheduled_for' => now()->addHour(), 'definition' => function ($attributes) {
            $announcement = Announcement::findOrFail($attributes['announcement_id']);
            $definition = $announcement->only('title', 'message', 'roles');
            sort($definition['roles']);

            return [...$definition, ...($announcement->audience === null ? [] : ['audience' => $announcement->audience])];
        }, 'definition_hash' => function ($attributes) {
            $approval = ['engine' => 'internal_announcement_schedule_v1', 'announcement_id' => (int) $attributes['announcement_id'], 'created_by' => (int) $attributes['created_by'], 'source_revision' => (int) $attributes['source_revision'], 'announcement_revision' => (int) $attributes['announcement_revision'], 'scheduled_for_utc' => CarbonImmutable::parse($attributes['scheduled_for'])->setTimezone('UTC')->format('Y-m-d\\TH:i:s\\Z'), 'definition' => $attributes['definition']];

            return hash_hmac('sha256', json_encode($approval, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR), config('app.key'));
        }];
    }
}
