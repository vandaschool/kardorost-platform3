<?php

namespace Database\Factories;

use App\Models\DiscussionPost;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class DiscussionAttachmentFactory extends Factory
{
    public function definition(): array
    {
        return ['discussion_post_id' => DiscussionPost::factory(), 'owner_id' => fn (array $attributes) => DiscussionPost::findOrFail($attributes['discussion_post_id'])->author_id, 'request_key' => (string) Str::uuid()];
    }
}
