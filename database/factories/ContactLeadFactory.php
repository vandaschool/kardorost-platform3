<?php

namespace Database\Factories;

use App\Services\ContactLeads;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class ContactLeadFactory extends Factory
{
    public function definition(): array
    {
        return ['request_key' => (string) Str::uuid(), 'name' => 'مخاطب صرفاً آزمایشی', 'phone' => '989121112222', 'phone_fingerprint' => app(ContactLeads::class)->fingerprint('989121112222'), 'audience' => 'company', 'organization_name' => 'مجموعه آزمایشی', 'subject' => 'training', 'message' => 'این متن فقط برای بررسی درخواست ارتباط و پیگیری مدیریت است.', 'consented_at' => now(), 'consent_version' => 'contact_follow_up_v1'];
    }
}
