<?php

namespace Database\Factories;

use App\Models\Cohort;
use App\Models\Course;
use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class CourseSurveyFactory extends Factory
{
    public function definition(): array
    {
        return ['cohort_id' => Cohort::factory()->state(['course_id' => Course::factory()->state(['coach_id' => User::factory()->afterCreating(fn ($user) => $user->roles()->attach(Role::where('key', 'coach')->value('id')))])]), 'title' => 'نظرسنجی آزمایشی دوره', 'draft' => ['title' => 'نظرسنجی آزمایشی دوره', 'description' => 'ارزیابی تجربه آموزشی', 'mode' => 'aggregate', 'minimum_responses' => 3, 'options' => ['خیلی کم', 'کم', 'متوسط', 'زیاد', 'خیلی زیاد'], 'questions' => [['key' => 'quality', 'text' => 'کیفیت آموزش چگونه بود؟', 'type' => 'rating', 'required' => true], ['key' => 'comment', 'text' => 'پیشنهاد شما چیست؟', 'type' => 'text', 'required' => false]], 'engine' => 'course_feedback_v1']];
    }
}
