<?php

namespace Database\Factories;

use App\Models\ResumeVersion;
use App\Models\User;
use App\Services\ResumeBuilder;
use App\Services\ResumeTextReview;
use Illuminate\Database\Eloquent\Factories\Factory;

class ResumeCheckFactory extends Factory
{
    public function definition(): array
    {
        return ['resume_version_id' => ResumeVersion::factory(), 'created_by' => User::factory(), 'job_text' => '', 'job_hash' => hash('sha256', ''), 'method' => ResumeTextReview::METHOD, 'report' => app(ResumeTextReview::class)->report(app(ResumeBuilder::class)->emptyDefinition('نام آزمایشی'), '')];
    }
}
