<?php

namespace Database\Factories;

use App\Models\Cohort;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrganizationLicenseFactory extends Factory
{
    public function definition(): array
    {
        return ['organization_id' => Organization::factory()->state(['name' => 'سازمان آزمایشی', 'type' => 'company', 'status' => 'active']), 'cohort_id' => Cohort::factory(), 'seat_limit' => 2, 'expires_on' => now()->addMonth()->toDateString(), 'active' => true, 'revision' => 1, 'contract_reference' => 'TEST-CONTRACT', 'created_by' => User::factory()];
    }
}
