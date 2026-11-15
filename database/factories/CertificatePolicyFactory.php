<?php

namespace Database\Factories;

use App\Models\Cohort;
use App\Models\CourseSection;
use App\Models\Lesson;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class CertificatePolicyFactory extends Factory
{
    public function definition(): array
    {
        return ['cohort_id' => Cohort::factory(), 'reviewed_by' => User::factory(), 'enabled' => true, 'definition' => function (array $attributes) {
            $lesson = Lesson::factory()->create(['course_section_id' => CourseSection::factory()->create(['course_id' => Cohort::findOrFail($attributes['cohort_id'])->course_id])->id]);

            return ['title' => 'گواهی تکمیل دوره', 'issuer' => 'کاردرست', 'lessons' => [$lesson->only('id', 'title')], 'sessions' => [], 'assignments' => [], 'attendance_percent' => 100, 'score_percent' => 70, 'require_settled' => false, 'policy' => 'selected_evidence_manual_review_v1', 'policy_revision' => 1];
        }];
    }
}
