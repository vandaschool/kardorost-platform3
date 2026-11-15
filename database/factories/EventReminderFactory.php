<?php

namespace Database\Factories;

use App\Models\Consultation;
use App\Models\ConsultationSlot;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class EventReminderFactory extends Factory
{
    public function definition(): array
    {
        return ['source_type' => 'consultation', 'source_id' => Consultation::factory()->state(['consultation_slot_id' => ConsultationSlot::factory()->state(['starts_at' => now()->addMinutes(30), 'ends_at' => now()->addMinutes(90)])]), 'user_id' => fn (array $data) => Consultation::findOrFail($data['source_id'])->student_id, 'occurs_at' => fn (array $data) => Consultation::findOrFail($data['source_id'])->slot->starts_at, 'notification_id' => (string) Str::uuid(), 'in_app_sent_at' => now(), 'sms_status' => 'queued'];
    }
}
