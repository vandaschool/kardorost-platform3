<?php

namespace Database\Factories;

use App\Models\CertificateRequest;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class CourseCertificateFactory extends Factory
{
    public function definition(): array
    {
        return ['certificate_request_id' => CertificateRequest::factory()->state(['status' => 'issued']), 'enrollment_id' => fn (array $attributes) => CertificateRequest::findOrFail($attributes['certificate_request_id'])->enrollment_id, 'serial' => 'KD-TEST-'.strtoupper(bin2hex(random_bytes(8))), 'verification_token' => Str::random(48), 'issued_by' => User::factory(), 'issued_at' => now(), 'evidence' => ['eligible' => true, 'checks' => [], 'basis' => 'synthetic_factory_fixture'], 'definition' => function (array $attributes) {
            $request = CertificateRequest::with('enrollment.course', 'enrollment.cohort')->findOrFail($attributes['certificate_request_id']);

            return ['recipient_name' => $request->recipient_name, 'course_title' => $request->enrollment->course->title, 'cohort_title' => $request->enrollment->cohort->title, 'title' => 'گواهی تکمیل دوره', 'issuer' => 'کاردرست', 'policy' => $request->definition];
        }];
    }
}
