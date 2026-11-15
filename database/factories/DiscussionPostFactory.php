<?php

namespace Database\Factories;

use App\Models\CourseDiscussion;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class DiscussionPostFactory extends Factory
{
    public function definition(): array
    {
        return ['course_discussion_id' => CourseDiscussion::factory(), 'author_id' => fn (array $attributes) => CourseDiscussion::findOrFail($attributes['course_discussion_id'])->author_id, 'author_role' => 'student', 'body' => 'پیام آزمایشی گفت‌وگوی دوره', 'request_key' => (string) Str::uuid()];
    }
}
