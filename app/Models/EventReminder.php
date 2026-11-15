<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventReminder extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $hidden = ['sender_account'];

    protected $attributes = ['sms_status' => 'not_requested'];

    protected function casts(): array
    {
        return ['occurs_at' => 'datetime', 'in_app_sent_at' => 'datetime', 'sms_dispatched_at' => 'datetime', 'sms_attempted_at' => 'datetime', 'sms_finished_at' => 'datetime'];
    }

    public function recipient(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function delivery(): BelongsTo
    {
        return $this->belongsTo(SmsDelivery::class, 'sms_delivery_id');
    }
}
