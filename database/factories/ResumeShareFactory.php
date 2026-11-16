<?php

namespace Database\Factories;

use App\Models\ResumeShare;
use App\Models\ResumeVersion;
use App\Services\ResumeSharing;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ResumeShareFactory extends Factory
{
    public function definition(): array
    {
        return ['resume_version_id' => ResumeVersion::factory(), 'career_resume_id' => fn (array $attributes) => ResumeVersion::findOrFail($attributes['resume_version_id'])->career_resume_id, 'user_id' => fn (array $attributes) => ResumeVersion::findOrFail($attributes['resume_version_id'])->resume->user_id, 'request_key' => (string) Str::uuid(), 'token_hash' => hash('sha256', Str::uuid()), 'definition' => [], 'include_contacts' => false, 'duration_days' => 7, 'consented_at' => now(), 'expires_at' => now()->addDays(7)];
    }

    public function configure(): static
    {
        return $this->afterCreating(function (ResumeShare $share): void {
            $token = bin2hex(random_bytes(32));
            $version = $share->version;
            $snapshot = $version->definition;
            if (! $share->include_contacts) {
                $snapshot = [...$snapshot, 'email' => '', 'phone' => '', 'links' => []];
            }
            $share->update(['token_hash' => hash('sha256', $token), 'definition' => ['share_id' => $share->id, 'user_id' => $share->user_id, 'resume_id' => $share->career_resume_id, 'version_id' => $share->resume_version_id, 'version_number' => $version->number, 'source_hash' => $version->content_hash, 'include_contacts' => $share->include_contacts, 'duration_days' => $share->duration_days, 'consented_timestamp' => $share->consented_at->timestamp, 'expires_timestamp' => $share->expires_at->timestamp, 'consent_version' => 'public_resume_link_v1', 'consent_text' => ResumeSharing::CONSENT, 'token' => $token, 'snapshot' => $snapshot]]);
        });
    }
}
