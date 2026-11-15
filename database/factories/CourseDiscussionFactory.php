<?php

namespace Database\Factories;

use App\Models\Cohort;
use App\Models\Enrollment;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class CourseDiscussionFactory extends Factory
{
    public function definition(): array
    {
        return ['cohort_id' => Cohort::factory(), 'author_id' => User::factory()->afterCreating(fn ($user) => $user->roles()->attach(Role::where('key', 'student')->value('id'))), 'title' => 'پرسش آزمایشی دوره', 'kind' => 'question', 'request_key' => (string) Str::uuid(), 'last_post_at' => now()];
    }

    public function configure(): static
    {
        return $this->afterCreating(fn ($discussion) => Enrollment::factory()->create(['cohort_id' => $discussion->cohort_id, 'user_id' => $discussion->author_id]));
    }
}
