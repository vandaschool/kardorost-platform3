<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SmsDelivery extends Model
{
    use HasFactory;

    protected $guarded = [];

    protected $hidden = ['provider_account', 'lease_token'];

    protected $attributes = ['status' => 'pending', 'attempts' => 0, 'revision' => 1];

    protected function casts(): array
    {
        return ['last_code' => 'integer', 'attempts' => 'integer', 'revision' => 'integer', 'poll_started_at' => 'datetime', 'dispatched_at' => 'datetime', 'last_checked_at' => 'datetime', 'next_poll_at' => 'datetime', 'expires_at' => 'datetime'];
    }

    public function attemptsLog(): HasMany
    {
        return $this->hasMany(SmsDeliveryAttempt::class);
    }
}
