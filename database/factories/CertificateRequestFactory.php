<?php

namespace Database\Factories;

use App\Models\CertificatePolicy;
use App\Models\Enrollment;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class CertificateRequestFactory extends Factory
{
    public function definition(): array
    {
        return ['enrollment_id' => Enrollment::factory()->state(['user_id' => User::factory()->afterCreating(fn ($user) => $user->roles()->attach(Role::where('key', 'student')->value('id')))]), 'recipient_name' => fn (array $attributes) => Enrollment::findOrFail($attributes['enrollment_id'])->user->name, 'public_consent_at' => now(), 'definition' => fn (array $attributes) => CertificatePolicy::factory()->create(['cohort_id' => Enrollment::findOrFail($attributes['enrollment_id'])->cohort_id])->definition];
    }
}
