<?php

namespace Database\Factories;

use App\Models\ContentPage;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class ContentPublicationFactory extends Factory
{
    public function definition(): array
    {
        $definition = ['title' => 'مقاله منتشرشده', 'excerpt' => 'توضیح کوتاه', 'body' => 'متن عمومی مقاله', 'meta_description' => 'توضیح مقاله'];

        return ['content_page_id' => ContentPage::factory(), 'published_by' => User::factory(), 'number' => 1, 'definition' => $definition, 'content_hash' => hash('sha256', json_encode($definition)), 'published_at' => now()];
    }
}
