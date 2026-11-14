<?php

namespace Database\Factories;

use App\Models\ConsultationProduct;
use App\Models\Role;
use App\Models\User;
use App\Services\PackageBilling;
use Illuminate\Database\Eloquent\Factories\Factory;

class PackagePurchaseFactory extends Factory
{
    public function definition(): array
    {
        return ['consultation_product_id' => ConsultationProduct::factory(), 'student_id' => User::factory()->afterCreating(fn ($user) => $user->roles()->attach(Role::where('key', 'student')->value('id'))), 'coach_id' => fn (array $attributes) => ConsultationProduct::findOrFail($attributes['consultation_product_id'])->coach_id, 'reviewed_by' => fn (array $attributes) => ConsultationProduct::findOrFail($attributes['consultation_product_id'])->reviewed_by, 'definition' => fn (array $attributes) => app(PackageBilling::class)->definition(ConsultationProduct::findOrFail($attributes['consultation_product_id'])), 'amount' => fn (array $attributes) => ConsultationProduct::findOrFail($attributes['consultation_product_id'])->price, 'expires_at' => now()->addMinutes(15)];
    }
}
