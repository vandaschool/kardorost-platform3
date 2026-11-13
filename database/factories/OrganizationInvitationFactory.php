<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrganizationInvitationFactory extends Factory
{
    public function definition(): array
    {
        return ['organization_id' => Organization::factory()->state(['name' => 'سازمان آزمایشی دعوت', 'type' => 'company', 'status' => 'active']), 'phone' => '989'.fake()->unique()->numerify('#########'), 'created_by' => User::factory(), 'expires_at' => now()->addDays(7)];
    }
}
