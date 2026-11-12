<?php

namespace Database\Factories;

use App\Models\Assessment;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;

class AssessmentVersionFactory extends Factory
{
    public function definition(): array
    {
        $d = ['title' => 'مقیاس آزمایشی نرم‌افزار', 'description' => 'فقط داده آزمایشی، بدون اعتبار روان‌سنجی', 'instructions' => 'برای آزمون نرم‌افزار یک گزینه انتخاب کنید.', 'source' => 'Software test fixture, not a psychometric instrument', 'engine' => 'likert_sum_v1', 'options' => ['گزینه ۱', 'گزینه ۲', 'گزینه ۳', 'گزینه ۴', 'گزینه ۵'], 'dimensions' => [['key' => 'interest', 'title' => 'بعد آزمایشی', 'description' => 'صرفاً داده آزمون نرم‌افزار']], 'questions' => [['key' => 'q1', 'text' => 'پرسش نمونه نرم‌افزار', 'dimension' => 'interest', 'reverse' => false], ['key' => 'q2', 'text' => 'پرسش معکوس آزمایشی', 'dimension' => 'interest', 'reverse' => true]]];

        return ['assessment_id' => Assessment::factory(), 'number' => 1, 'definition' => $d, 'content_hash' => hash('sha256', json_encode($d)), 'published_by' => User::factory()];
    }
}
