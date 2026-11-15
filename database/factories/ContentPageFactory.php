<?php

namespace Database\Factories;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ContentPageFactory extends Factory
{
    public function definition(): array
    {
        return ['created_by' => User::factory(), 'kind' => 'article', 'slug' => 'article-'.Str::lower(Str::random(10)), 'draft' => ['title' => 'یادداشت مسیر شغلی', 'excerpt' => 'توضیح کوتاه مقاله', 'body' => 'متن مقاله آموزشی برای آزمون', 'meta_description' => 'توضیح مقاله']];
    }
}
