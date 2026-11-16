<?php

namespace Database\Factories;

use App\Models\CoachCredential;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class CoachCredentialFactory extends Factory
{
    public function configure(): static
    {
        return $this->afterCreating(fn (CoachCredential $credential) => $credential->update(['definition' => [...$credential->definition, 'credential_id' => $credential->id, 'user_id' => $credential->user_id]]));
    }

    public function definition(): array
    {
        return ['user_id' => User::factory()->afterCreating(fn ($user) => $user->roles()->attach(Role::where('key', 'coach')->value('id'))), 'request_key' => (string) Str::uuid(), 'status' => 'submitted', 'definition' => ['title' => 'مدرک صرفاً آزمایشی نرم‌افزار', 'issuer' => 'Software fixture, no official credential', 'note' => '', 'file_name' => 'software-fixture.pdf', 'mime' => 'application/pdf', 'size' => 0, 'sha256' => hash('sha256', '')], 'consented_at' => now()];
    }
}
