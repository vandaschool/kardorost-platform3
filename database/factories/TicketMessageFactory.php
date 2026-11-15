<?php

namespace Database\Factories;

use App\Models\SupportTicket;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class TicketMessageFactory extends Factory
{
    public function definition(): array
    {
        return ['support_ticket_id' => SupportTicket::factory(), 'author_id' => fn (array $data) => SupportTicket::findOrFail($data['support_ticket_id'])->user_id, 'author_role' => 'requester', 'body' => 'پیام خصوصی آزمایشی', 'internal_note' => false, 'request_key' => (string) Str::uuid()];
    }
}
