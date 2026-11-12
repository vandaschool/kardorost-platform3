<?php

namespace Database\Factories;

use App\Models\Enrollment;
use App\Models\OrganizationLicense;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class OrganizationSeatFactory extends Factory
{
    public function definition(): array
    {
        return ['organization_license_id' => OrganizationLicense::factory(), 'enrollment_id' => Enrollment::factory(), 'user_id' => fn (array $a) => Enrollment::findOrFail($a['enrollment_id'])->user_id, 'created_by' => User::factory(), 'member_name' => 'عضو آزمایشی', 'member_phone' => '989121234567'];
    }
}
