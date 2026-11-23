<?php

namespace Database\Factories;

use App\Models\ContentPage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ContentRedirectFactory extends Factory
{
    public function definition(): array
    {
        return ['content_page_id' => ContentPage::factory(), 'kind' => 'article', 'from_slug' => 'legacy-'.Str::lower(Str::random(10)), 'created_by' => User::factory(), 'updated_by' => fn (array $attributes) => $attributes['created_by']];
    }
}
