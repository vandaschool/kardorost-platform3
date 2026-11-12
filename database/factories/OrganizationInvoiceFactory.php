<?php

namespace Database\Factories;

use App\Models\Organization;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrganizationInvoiceFactory extends Factory
{
    public function definition(): array
    {
        return ['organization_id' => Organization::factory()->state(['name' => 'سازمان آزمایشی', 'type' => 'company', 'status' => 'active']), 'created_by' => User::factory(), 'organization_name' => 'سازمان آزمایشی', 'title' => 'صورتحساب آزمایشی داخلی', 'contract_reference' => 'TEST-CONTRACT', 'lines' => [['title' => 'خدمات آزمایشی', 'quantity' => 1, 'unit_price' => 1000000]], 'amount' => 1000000, 'due_date' => now()->addWeek()->toDateString(), 'status' => 'draft', 'revision' => 1];
    }
}
